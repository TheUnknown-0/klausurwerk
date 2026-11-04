<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

/**
 * Korrektur: Punkte je Aufgabe, Endwert bei Erst-/Zweitkorrektur, Kommentare, Summen, Noten
 * und der Statusfluss der Klausur (finished, grading, returned, archived).
 *
 * Endwert einer Aufgabe: Eintrag der Hauptlehrkraft (exams.owner_id), sonst der einzige vorhandene
 * Eintrag, sonst der zuletzt geänderte. Hat eine zweite Lehrkraft abweichend bewertet, ist das ein
 * Hinweis für die Hauptlehrkraft ("differs"), die entscheidet.
 *
 * Dieser Dienst liest task_solutions nie; Lösungsdaten holt nur der Korrektur-Controller über
 * TaskService::fullList (nach CAP_GRADE).
 */
final class GradingService
{
    public const POINT_STEP = 0.5;
    public const MAX_COMMENT_CHARS = 2000;
    public const MAX_GENERAL_COMMENT_CHARS = 5000;

    public function __construct(
        private readonly Database $db,
        private readonly GradingScales $scales,
    ) {
    }

    // ---------- reine Rechenfunktionen (ohne Datenbank) ----------

    /** Rundet auf das nächste 0,5 (x,25 wird zu x,5; x,75 wird zu x+1). */
    public static function roundHalf(float $value): float
    {
        return round($value * 2) / 2;
    }

    /**
     * Prüft eine Punkteingabe (Zahl oder Text mit Komma). null oder leer = keine Bewertung.
     *
     * @throws \InvalidArgumentException bei ungültigem Wert
     */
    public static function normalizePoints(mixed $raw, float $max): ?float
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        if (is_string($raw)) {
            $raw = str_replace(',', '.', trim($raw));
        }
        if (!is_numeric($raw)) {
            throw new \InvalidArgumentException('Bitte gib die Punkte als Zahl ein.');
        }
        $value = (float) $raw;
        if ($value < 0) {
            throw new \InvalidArgumentException('Punkte dürfen nicht negativ sein.');
        }
        if ($value > $max + 1e-9) {
            throw new \InvalidArgumentException('Mehr als ' . format_points($max) . ' Punkte sind für diese Aufgabe nicht möglich.');
        }
        if (abs($value * 2 - round($value * 2)) > 1e-6) {
            throw new \InvalidArgumentException('Punkte werden in Schritten von 0,5 vergeben.');
        }

        return round($value, 2);
    }

    /**
     * Endwert aus den Einträgen mehrerer Lehrkräfte.
     *
     * @param list<array{grader_id: int|string, points: mixed, updated_at?: mixed}> $entries
     * @return array{points: ?float, grader_id: ?int, source: ?string, differs: bool, count: int}
     */
    public static function finalEntry(array $entries, ?int $ownerId): array
    {
        $valid = [];
        foreach ($entries as $entry) {
            if ($entry['points'] !== null && $entry['points'] !== '') {
                $valid[] = ['grader_id' => (int) $entry['grader_id'], 'points' => (float) $entry['points'], 'at' => (string) ($entry['updated_at'] ?? '')];
            }
        }
        if ($valid === []) {
            return ['points' => null, 'grader_id' => null, 'source' => null, 'differs' => false, 'count' => 0];
        }

        $chosen = null;
        $source = null;
        if ($ownerId !== null) {
            foreach ($valid as $entry) {
                if ($entry['grader_id'] === $ownerId) {
                    $chosen = $entry;
                    $source = 'owner';
                    break;
                }
            }
        }
        if ($chosen === null) {
            if (count($valid) === 1) {
                $chosen = $valid[0];
                $source = 'single';
            } else {
                usort($valid, static fn (array $a, array $b): int => [$b['at'], $b['grader_id']] <=> [$a['at'], $a['grader_id']]);
                $chosen = $valid[0];
                $source = 'latest';
            }
        }
        $differs = false;
        foreach ($valid as $entry) {
            if (abs($entry['points'] - $chosen['points']) > 1e-9) {
                $differs = true;
            }
        }

        return [
            'points' => $chosen['points'],
            'grader_id' => $chosen['grader_id'],
            'source' => $source,
            'differs' => $differs,
            'count' => count($valid),
        ];
    }

    public static function percent(float $total, float $max): ?float
    {
        if ($max <= 0) {
            return null;
        }

        return round($total / $max * 100, 2);
    }

    /**
     * Note zu einem Prozentwert.
     *
     * @param array<string, mixed>|null $scale
     * @return array{label: string, value: float}|null
     */
    public static function gradeOf(?array $scale, ?float $percent): ?array
    {
        if ($scale === null || $percent === null || ($scale['thresholds'] ?? []) === []) {
            return null;
        }

        return GradingScales::gradeFor($scale['thresholds'], $percent);
    }

    /**
     * Prüft und normalisiert den Anker eines Kommentars (docs/DATENFORMATE.md Abschnitt 5).
     *
     * @param array<string, mixed> $anchor
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    public static function normalizeAnchor(array $anchor): array
    {
        $kind = (string) ($anchor['kind'] ?? '');
        if ($kind === 'task') {
            return ['kind' => 'task'];
        }
        if ($kind === 'code') {
            $file = (string) ($anchor['file'] ?? '');
            if (preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $file) !== 1) {
                throw new \InvalidArgumentException('Ungültiger Dateiname im Kommentar.');
            }
            $from = (int) ($anchor['from_line'] ?? 0);
            $to = (int) ($anchor['to_line'] ?? $from);
            if ($from < 1 || $to < $from || $to > 100000) {
                throw new \InvalidArgumentException('Ungültige Zeilenangabe im Kommentar.');
            }

            return ['kind' => 'code', 'file' => $file, 'from_line' => $from, 'to_line' => $to];
        }
        if ($kind === 'text') {
            $from = (int) ($anchor['from'] ?? -1);
            $to = (int) ($anchor['to'] ?? -1);
            if ($from < 0 || $to < $from || $to > 1000000) {
                throw new \InvalidArgumentException('Ungültige Textstelle im Kommentar.');
            }
            $quote = mb_substr((string) ($anchor['quote'] ?? ''), 0, 300);

            return ['kind' => 'text', 'from' => $from, 'to' => $to, 'quote' => $quote];
        }

        throw new \InvalidArgumentException('Unbekannte Art von Kommentar.');
    }

    // ---------- Lesen ----------

    /**
     * Aufgaben mit Antwort (Blätter des Aufgabenbaums) in Anzeigereihenfolge mit Nummer.
     *
     * @return list<array<string, mixed>>
     */
    public function leafTasks(int $examId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, parent_id, position, title, points, afb, answer_type, config FROM tasks WHERE exam_id = ?',
            [$examId],
        );
        $out = [];
        foreach (TaskTree::flatten(TaskTree::build($rows)) as $task) {
            if ($task['answer_type'] === null) {
                continue;
            }
            $out[] = [
                'id' => (int) $task['id'],
                'parent_id' => $task['parent_id'] !== null ? (int) $task['parent_id'] : null,
                'number' => (string) $task['number'],
                'title' => (string) $task['title'],
                'points' => (float) $task['points'],
                'afb' => $task['afb'] !== null ? (string) $task['afb'] : null,
                'answer_type' => (string) $task['answer_type'],
                'config' => json_decode((string) ($task['config'] ?? '{}'), true) ?: [],
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> Teilnahmen mit Namen, nach Nachname sortiert. */
    public function participations(int $examId): array
    {
        return $this->db->fetchAll(
            'SELECT p.id, p.user_id, p.state, p.submitted_at, p.shuffle_seed, u.firstname, u.lastname, u.username
             FROM participations p JOIN users u ON u.id = p.user_id
             WHERE p.exam_id = ?
             ORDER BY u.lastname, u.firstname, p.id',
            [$examId],
        );
    }

    /**
     * Pseudonyme "Nr. k" für anonymes Korrigieren: stabile, aber von Namen und Reihenfolge unabhängige Zuordnung.
     *
     * @param list<int> $participationIds
     * @return array<int, int> participation_id => Nummer (ab 1)
     */
    public static function pseudonyms(int $examId, array $participationIds): array
    {
        $keyed = [];
        foreach ($participationIds as $id) {
            $keyed[$id] = md5($examId . '-' . $id . '-anon');
        }
        asort($keyed);
        $out = [];
        $n = 0;
        foreach (array_keys($keyed) as $id) {
            $out[$id] = ++$n;
        }

        return $out;
    }

    /**
     * Einträge aller Lehrkräfte einer Klausur.
     *
     * @return array<int, array<int, list<array<string, mixed>>>> participation_id => task_id => Einträge
     */
    public function entriesByCell(int $examId, ?int $onlyGrader = null, ?int $onlyParticipation = null): array
    {
        $sql = 'SELECT ge.participation_id, ge.task_id, ge.grader_id, ge.points, ge.updated_at
                FROM grading_entries ge JOIN participations p ON p.id = ge.participation_id
                WHERE p.exam_id = ?';
        $params = [$examId];
        if ($onlyGrader !== null) {
            $sql .= ' AND ge.grader_id = ?';
            $params[] = $onlyGrader;
        }
        if ($onlyParticipation !== null) {
            $sql .= ' AND ge.participation_id = ?';
            $params[] = $onlyParticipation;
        }
        $out = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $out[(int) $row['participation_id']][(int) $row['task_id']][] = $row;
        }

        return $out;
    }

    /**
     * Automatische Vorbewertungen: participation_id => task_id => Zeile.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function autosByCell(int $examId): array
    {
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT a.participation_id, a.task_id, a.suggested_points, a.details
             FROM auto_gradings a JOIN participations p ON p.id = a.participation_id WHERE p.exam_id = ?',
            [$examId],
        ) as $row) {
            $out[(int) $row['participation_id']][(int) $row['task_id']] = $row;
        }

        return $out;
    }

    /**
     * Notenschlüssel der Klausur: gewählter Schlüssel, sonst der des Kurses (bzw. Standard der Klassenstufe).
     *
     * @param array<string, mixed> $exam
     * @return array<string, mixed>|null
     */
    public function scaleFor(array $exam): ?array
    {
        if (!empty($exam['grading_scale_id'])) {
            $scale = $this->scales->find((int) $exam['grading_scale_id']);
            if ($scale !== null) {
                return $scale;
            }
        }

        return $this->scales->scaleForCourse($exam['course_id'] !== null ? (int) $exam['course_id'] : null);
    }

    /** @return list<array<string, mixed>> alle Schlüssel zur Auswahl. */
    public function allScales(): array
    {
        return $this->scales->all();
    }

    /**
     * Matrix für die Korrektur-Übersicht.
     *
     * @param array<string, mixed> $exam
     * @return array{tasks: list<array<string, mixed>>, rows: list<array<string, mixed>>, progress: array<string, int>, auto: array<string, int>}
     */
    public function overview(array $exam, int $viewerId, bool $seeOthers, bool $anonymous): array
    {
        $examId = (int) $exam['id'];
        $ownerId = $exam['owner_id'] !== null ? (int) $exam['owner_id'] : null;
        $tasks = $this->leafTasks($examId);
        $people = $this->participations($examId);
        $entries = $this->entriesByCell($examId, $seeOthers ? null : $viewerId);
        $autos = $this->autosByCell($examId);

        $answered = [];
        $overtime = [];
        foreach ($this->db->fetchAll(
            'SELECT a.participation_id, a.task_id, a.has_overtime FROM answers a JOIN participations p ON p.id = a.participation_id WHERE p.exam_id = ?',
            [$examId],
        ) as $a) {
            $answered[(int) $a['participation_id']][(int) $a['task_id']] = true;
            $overtime[(int) $a['participation_id']][(int) $a['task_id']] = (int) $a['has_overtime'] === 1;
        }
        $pastes = [];
        foreach ($this->db->fetchAll(
            'SELECT o.participation_id, o.task_id, COUNT(*) AS n FROM answer_ops o JOIN participations p ON p.id = o.participation_id
             WHERE p.exam_id = ? AND o.is_paste = 1 GROUP BY o.participation_id, o.task_id',
            [$examId],
        ) as $r) {
            $pastes[(int) $r['participation_id']][(int) $r['task_id']] = (int) $r['n'];
        }

        $pseudo = $anonymous ? self::pseudonyms($examId, array_map(static fn (array $p): int => (int) $p['id'], $people)) : [];
        $rows = [];
        $graded = 0;
        $cellsTotal = 0;
        $suggested = 0;
        $errors = 0;
        $diff = 0;
        foreach ($people as $p) {
            $pid = (int) $p['id'];
            $cells = [];
            $sum = 0.0;
            $sumSuggested = 0.0;
            $done = 0;
            foreach ($tasks as $t) {
                $tid = $t['id'];
                $final = self::finalEntry($entries[$pid][$tid] ?? [], $ownerId);
                $auto = $autos[$pid][$tid] ?? null;
                $sug = $auto !== null && $auto['suggested_points'] !== null ? (float) $auto['suggested_points'] : null;
                $autoError = $auto !== null && $auto['suggested_points'] === null;
                $own = null;
                foreach ($entries[$pid][$tid] ?? [] as $e) {
                    if ((int) $e['grader_id'] === $viewerId && $e['points'] !== null) {
                        $own = (float) $e['points'];
                    }
                }
                $status = 'open';
                $shown = null;
                if ($final['points'] !== null) {
                    $status = ($seeOthers && $final['differs']) ? 'diff' : 'graded';
                    $shown = $own ?? $final['points'];
                    if ($status === 'diff') {
                        $diff++;
                    }
                } elseif ($sug !== null) {
                    $status = 'suggested';
                    $shown = $sug;
                } elseif ($autoError) {
                    $status = 'error';
                    $errors++;
                }
                $cellsTotal++;
                if ($final['points'] !== null) {
                    $graded++;
                    $done++;
                    $sum += $final['points'];
                    $sumSuggested += $final['points'];
                } elseif ($sug !== null) {
                    $suggested++;
                    $sumSuggested += $sug;
                }
                $cells[$tid] = [
                    'task_id' => $tid,
                    'status' => $status,
                    'points' => $shown,
                    'answered' => isset($answered[$pid][$tid]),
                    'overtime' => $overtime[$pid][$tid] ?? false,
                    'pastes' => $pastes[$pid][$tid] ?? 0,
                ];
            }
            $rows[] = [
                'participation_id' => $pid,
                'user_id' => (int) $p['user_id'],
                'state' => (string) $p['state'],
                'submitted' => (string) $p['state'] === 'submitted',
                'label' => $anonymous ? 'Nr. ' . $pseudo[$pid] : display_name($p),
                'sort' => $anonymous ? $pseudo[$pid] : 0,
                'cells' => $cells,
                'sum' => $sum,
                'sum_suggested' => $sumSuggested,
                'done' => $done,
            ];
        }
        if ($anonymous) {
            usort($rows, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
        }

        return [
            'tasks' => $tasks,
            'rows' => $rows,
            'progress' => ['graded' => $graded, 'cells' => $cellsTotal, 'suggested' => $suggested, 'errors' => $errors, 'diff' => $diff],
            'auto' => ['suggested' => $suggested, 'errors' => $errors],
        ];
    }

    // ---------- Schreiben: Bewertung ----------

    /**
     * Speichert die Punkte einer Lehrkraft für eine Zelle.
     *
     * @param array<string, mixed> $exam
     * @return array{points: ?float, final: array<string, mixed>, totals: array<string, mixed>, unfinalized: bool}
     */
    public function saveEntry(array $exam, int $participationId, int $taskId, int $graderId, mixed $rawPoints): array
    {
        $this->assertGrading($exam);
        $task = $this->leafTask((int) $exam['id'], $taskId);
        $this->requireParticipation((int) $exam['id'], $participationId);
        try {
            $points = self::normalizePoints($rawPoints, (float) $task['points']);
        } catch (\InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        $unfinalized = false;
        $this->db->transaction(function () use ($participationId, $taskId, $graderId, $points, &$unfinalized): void {
            $this->db->run(
                'INSERT INTO grading_entries (participation_id, task_id, grader_id, points) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE points = VALUES(points)',
                [$participationId, $taskId, $graderId, $points],
            );
            $unfinalized = $this->db->run(
                'UPDATE participation_results SET finalized_at = NULL, finalized_by = NULL
                 WHERE participation_id = ? AND finalized_at IS NOT NULL',
                [$participationId],
            )->rowCount() > 0;
        });
        $totals = $this->computeTotals($exam, [$participationId])[$participationId];
        $this->storeTotals($participationId, $totals);
        $entries = $this->entriesByCell((int) $exam['id'], null, $participationId)[$participationId][$taskId] ?? [];

        return [
            'points' => $points,
            'final' => self::finalEntry($entries, $exam['owner_id'] !== null ? (int) $exam['owner_id'] : null),
            'totals' => $totals,
            'unfinalized' => $unfinalized,
        ];
    }

    /**
     * Übernimmt Vorschläge als eigene Bewertung (nur Zellen ohne eigene Bewertung).
     *
     * @param array<string, mixed> $exam
     * @param bool $includeTests auch Vorschläge aus Code-Tests übernehmen
     * @return int Anzahl übernommener Zellen
     */
    public function applySuggestions(array $exam, int $graderId, bool $includeTests, ?int $participationId = null, ?int $taskId = null): int
    {
        $this->assertGrading($exam);
        $examId = (int) $exam['id'];
        $own = $this->entriesByCell($examId, $graderId);
        $count = 0;
        $touched = [];
        foreach ($this->autosByCell($examId) as $pid => $byTask) {
            if ($participationId !== null && $pid !== $participationId) {
                continue;
            }
            foreach ($byTask as $tid => $auto) {
                if ($taskId !== null && $tid !== $taskId) {
                    continue;
                }
                if ($auto['suggested_points'] === null) {
                    continue;
                }
                $details = json_decode((string) $auto['details'], true);
                if (!$includeTests && is_array($details) && ($details['source'] ?? '') === 'tests') {
                    continue;
                }
                $existing = $own[$pid][$tid][0]['points'] ?? null;
                if ($existing !== null) {
                    continue;
                }
                $this->db->run(
                    'INSERT INTO grading_entries (participation_id, task_id, grader_id, points) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE points = VALUES(points)',
                    [$pid, $tid, $graderId, $auto['suggested_points']],
                );
                $touched[$pid] = true;
                $count++;
            }
        }
        if ($touched !== []) {
            $ids = array_keys($touched);
            $this->db->run(
                'UPDATE participation_results SET finalized_at = NULL, finalized_by = NULL WHERE finalized_at IS NOT NULL AND participation_id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')',
                $ids,
            );
            foreach ($this->computeTotals($exam, $ids) as $pid => $totals) {
                $this->storeTotals($pid, $totals);
            }
        }

        return $count;
    }

    // ---------- Kommentare ----------

    /**
     * Kommentare einer Zelle. Ohne $seeOthers nur die eigenen.
     *
     * @return list<array<string, mixed>>
     */
    public function comments(int $participationId, int $taskId, int $viewerId, bool $seeOthers): array
    {
        $rows = $this->db->fetchAll(
            'SELECT c.id, c.grader_id, c.anchor, c.body, c.created_at, u.firstname, u.lastname, u.username
             FROM grading_comments c JOIN users u ON u.id = c.grader_id
             WHERE c.participation_id = ? AND c.task_id = ? ORDER BY c.id',
            [$participationId, $taskId],
        );
        $out = [];
        foreach ($rows as $r) {
            $mine = (int) $r['grader_id'] === $viewerId;
            if (!$mine && !$seeOthers) {
                continue;
            }
            $out[] = [
                'id' => (int) $r['id'],
                'grader_id' => (int) $r['grader_id'],
                'grader_name' => display_name($r),
                'mine' => $mine,
                'anchor' => json_decode((string) $r['anchor'], true) ?: ['kind' => 'task'],
                'body' => (string) $r['body'],
                'created_at' => (string) $r['created_at'],
            ];
        }

        return $out;
    }

    /**
     * Legt einen Kommentar an oder ändert den eigenen.
     *
     * @param array<string, mixed> $exam
     * @param array<string, mixed> $anchor
     * @return array<string, mixed>
     */
    public function saveComment(array $exam, int $participationId, int $taskId, int $graderId, ?int $commentId, array $anchor, string $body): array
    {
        $this->assertGrading($exam);
        $this->leafTask((int) $exam['id'], $taskId);
        $this->requireParticipation((int) $exam['id'], $participationId);
        $body = trim($body);
        if ($body === '') {
            throw new HttpException(422, 'Der Kommentar ist leer.');
        }
        if (mb_strlen($body) > self::MAX_COMMENT_CHARS) {
            throw new HttpException(422, 'Der Kommentar ist zu lang (höchstens ' . self::MAX_COMMENT_CHARS . ' Zeichen).');
        }
        try {
            $anchor = self::normalizeAnchor($anchor);
        } catch (\InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        $json = json_encode($anchor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if ($commentId === null) {
            $this->db->run(
                'INSERT INTO grading_comments (participation_id, task_id, grader_id, anchor, body) VALUES (?, ?, ?, ?, ?)',
                [$participationId, $taskId, $graderId, $json, $body],
            );
            $commentId = $this->db->lastInsertId();
        } else {
            $changed = $this->db->run(
                'UPDATE grading_comments SET body = ?, anchor = ? WHERE id = ? AND participation_id = ? AND task_id = ? AND grader_id = ?',
                [$body, $json, $commentId, $participationId, $taskId, $graderId],
            )->rowCount();
            $exists = $this->db->fetchValue(
                'SELECT 1 FROM grading_comments WHERE id = ? AND participation_id = ? AND task_id = ? AND grader_id = ?',
                [$commentId, $participationId, $taskId, $graderId],
            );
            if ($changed === 0 && $exists === null) {
                throw new HttpException(404, 'Kommentar nicht gefunden.');
            }
        }

        foreach ($this->comments($participationId, $taskId, $graderId, false) as $c) {
            if ($c['id'] === $commentId) {
                return $c;
            }
        }

        return [];
    }

    /** Löscht einen Kommentar (eigene; die Hauptlehrkraft darf alle löschen). */
    public function deleteComment(array $exam, int $commentId, int $graderId, bool $mayDeleteAny): void
    {
        $this->assertGrading($exam);
        $sql = 'DELETE c FROM grading_comments c JOIN participations p ON p.id = c.participation_id WHERE c.id = ? AND p.exam_id = ?';
        $params = [$commentId, (int) $exam['id']];
        if (!$mayDeleteAny) {
            $sql .= ' AND c.grader_id = ?';
            $params[] = $graderId;
        }
        if ($this->db->run($sql, $params)->rowCount() === 0) {
            throw new HttpException(404, 'Kommentar nicht gefunden.');
        }
    }

    // ---------- Summen, Noten, Ergebnisse ----------

    /**
     * Summen, Prozent und Note je Teilnahme.
     *
     * @param array<string, mixed> $exam
     * @param list<int>|null $participationIds null = alle der Klausur
     * @return array<int, array{total: float, max: float, percent: ?float, grade_label: ?string, grade_value: ?float, graded: int, cells: int}>
     */
    public function computeTotals(array $exam, ?array $participationIds = null): array
    {
        $examId = (int) $exam['id'];
        $ownerId = $exam['owner_id'] !== null ? (int) $exam['owner_id'] : null;
        $tasks = $this->leafTasks($examId);
        $max = array_sum(array_column($tasks, 'points'));
        $scale = $this->scaleFor($exam);
        if ($participationIds === null) {
            $participationIds = array_map(static fn (array $p): int => (int) $p['id'], $this->participations($examId));
        }
        $entries = $this->entriesByCell($examId, null, count($participationIds) === 1 ? $participationIds[0] : null);

        $out = [];
        foreach ($participationIds as $pid) {
            $total = 0.0;
            $graded = 0;
            foreach ($tasks as $t) {
                $final = self::finalEntry($entries[$pid][$t['id']] ?? [], $ownerId);
                if ($final['points'] !== null) {
                    $total += min($final['points'], $t['points']);
                    $graded++;
                }
            }
            $percent = self::percent($total, (float) $max);
            $grade = self::gradeOf($scale, $percent);
            $out[$pid] = [
                'total' => round($total, 2),
                'max' => round((float) $max, 2),
                'percent' => $percent,
                'grade_label' => $grade['label'] ?? null,
                'grade_value' => $grade['value'] ?? null,
                'graded' => $graded,
                'cells' => count($tasks),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $totals Ergebnis von computeTotals für eine Teilnahme */
    public function storeTotals(int $participationId, array $totals): void
    {
        $this->db->run(
            'INSERT INTO participation_results (participation_id, total_points, max_points, percent, grade_label)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE total_points = VALUES(total_points), max_points = VALUES(max_points),
                percent = VALUES(percent), grade_label = VALUES(grade_label)',
            [$participationId, $totals['total'], $totals['max'], $totals['percent'], $totals['grade_label']],
        );
    }

    /**
     * Berechnet und speichert die Ergebnisse aller Teilnahmen neu (nach Wechsel des Notenschlüssels, vor der Freigabe).
     *
     * @param array<string, mixed> $exam
     * @return array<int, array<string, mixed>>
     */
    public function recomputeAll(array $exam): array
    {
        $totals = $this->computeTotals($exam);
        foreach ($totals as $pid => $t) {
            $this->storeTotals($pid, $t);
        }

        return $totals;
    }

    /**
     * Ergebniszeilen je Person (Summe, Note, Kommentar, Abschluss), nach Namen sortiert.
     *
     * @param array<string, mixed> $exam
     * @return list<array<string, mixed>>
     */
    public function results(array $exam): array
    {
        $examId = (int) $exam['id'];
        $totals = $this->computeTotals($exam);
        $saved = [];
        foreach ($this->db->fetchAll(
            'SELECT r.* FROM participation_results r JOIN participations p ON p.id = r.participation_id WHERE p.exam_id = ?',
            [$examId],
        ) as $r) {
            $saved[(int) $r['participation_id']] = $r;
        }
        $rows = [];
        foreach ($this->participations($examId) as $p) {
            $pid = (int) $p['id'];
            $t = $totals[$pid];
            $rows[] = $p + [
                'name' => display_name($p),
                'total' => $t['total'],
                'max' => $t['max'],
                'percent' => $t['percent'],
                'grade_label' => $t['grade_label'],
                'grade_value' => $t['grade_value'],
                'graded' => $t['graded'],
                'cells' => $t['cells'],
                'general_comment' => (string) ($saved[$pid]['general_comment'] ?? ''),
                'finalized_at' => $saved[$pid]['finalized_at'] ?? null,
            ];
        }

        return $rows;
    }

    public function saveGeneralComment(array $exam, int $participationId, string $text): void
    {
        $this->assertGrading($exam);
        $this->requireParticipation((int) $exam['id'], $participationId);
        if (mb_strlen($text) > self::MAX_GENERAL_COMMENT_CHARS) {
            throw new HttpException(422, 'Der Kommentar ist zu lang (höchstens ' . self::MAX_GENERAL_COMMENT_CHARS . ' Zeichen).');
        }
        $totals = $this->computeTotals($exam, [$participationId])[$participationId];
        $this->storeTotals($participationId, $totals);
        $this->db->run(
            'UPDATE participation_results SET general_comment = ? WHERE participation_id = ?',
            [trim($text) === '' ? null : trim($text), $participationId],
        );
    }

    /** Schließt eine Person ab oder macht den Abschluss rückgängig. */
    public function setFinalized(array $exam, int $participationId, bool $finalized, int $userId): void
    {
        $this->assertGrading($exam);
        $this->requireParticipation((int) $exam['id'], $participationId);
        $totals = $this->computeTotals($exam, [$participationId])[$participationId];
        $this->storeTotals($participationId, $totals);
        if ($finalized) {
            $this->db->run(
                'UPDATE participation_results SET finalized_at = NOW(), finalized_by = ? WHERE participation_id = ?',
                [$userId, $participationId],
            );
        } else {
            $this->db->run(
                'UPDATE participation_results SET finalized_at = NULL, finalized_by = NULL WHERE participation_id = ?',
                [$participationId],
            );
        }
    }

    /**
     * Schließt alle Personen ab, deren Aufgaben vollständig bewertet sind.
     *
     * @return array{finalized: int, skipped: int}
     */
    public function finalizeComplete(array $exam, int $userId): array
    {
        $this->assertGrading($exam);
        $done = 0;
        $skipped = 0;
        foreach ($this->recomputeAll($exam) as $pid => $t) {
            if ($t['cells'] > 0 && $t['graded'] >= $t['cells']) {
                $this->db->run(
                    'UPDATE participation_results SET finalized_at = COALESCE(finalized_at, NOW()), finalized_by = COALESCE(finalized_by, ?) WHERE participation_id = ?',
                    [$userId, $pid],
                );
                $done++;
            } else {
                $skipped++;
            }
        }

        return ['finalized' => $done, 'skipped' => $skipped];
    }

    /** Ändert den Notenschlüssel der Klausur und rechnet alle Noten neu. */
    public function changeScale(array $exam, ?int $scaleId): array
    {
        if ($scaleId !== null && $this->scales->find($scaleId) === null) {
            throw new HttpException(422, 'Der gewählte Notenschlüssel existiert nicht.');
        }
        $this->db->run('UPDATE exams SET grading_scale_id = ? WHERE id = ?', [$scaleId, (int) $exam['id']]);
        $exam['grading_scale_id'] = $scaleId;
        $this->recomputeAll($exam);

        return $exam;
    }

    // ---------- Statusfluss ----------

    /** finished, grading. */
    public function begin(array $exam): void
    {
        $this->transition($exam, 'finished', 'grading', 'Die Korrektur kann nur für eine beendete Klausur begonnen werden.');
    }

    /**
     * grading, returned. Rechnet vorher alle Ergebnisse neu.
     *
     * @return array{finalized: int, open: int}
     */
    public function release(array $exam): array
    {
        if ((string) $exam['status'] !== 'grading') {
            throw new HttpException(409, 'Ergebnisse lassen sich nur aus der Korrektur heraus freigeben.');
        }
        $this->recomputeAll($exam);
        $this->db->run(
            'UPDATE exams SET status = ?, returned_at = NOW() WHERE id = ? AND status = ?',
            ['returned', (int) $exam['id'], 'grading'],
        );
        $finalized = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM participation_results r JOIN participations p ON p.id = r.participation_id
             WHERE p.exam_id = ? AND r.finalized_at IS NOT NULL',
            [(int) $exam['id']],
        );
        $all = (int) $this->db->fetchValue('SELECT COUNT(*) FROM participations WHERE exam_id = ?', [(int) $exam['id']]);

        return ['finalized' => $finalized, 'open' => max(0, $all - $finalized)];
    }

    /** returned, grading (Freigabe zurücknehmen). */
    public function withdraw(array $exam): void
    {
        $this->transition($exam, 'returned', 'grading', 'Es ist nichts freigegeben, das sich zurücknehmen ließe.', true);
    }

    /** returned, archived. */
    public function archive(array $exam): void
    {
        $this->transition($exam, 'returned', 'archived', 'Archiviert werden kann nur eine zurückgegebene Klausur.');
    }

    // ---------- intern ----------

    private function transition(array $exam, string $from, string $to, string $message, bool $clearReturned = false): void
    {
        $n = $this->db->run(
            'UPDATE exams SET status = ?' . ($clearReturned ? ', returned_at = NULL' : '') . ' WHERE id = ? AND status = ?',
            [$to, (int) $exam['id'], $from],
        )->rowCount();
        if ($n === 0) {
            throw new HttpException(409, $message);
        }
    }

    /** Bewerten ist nur in der Korrekturphase möglich. */
    public function assertGrading(array $exam): void
    {
        $status = (string) ($exam['status'] ?? '');
        if ($status === 'grading') {
            return;
        }
        throw new HttpException(409, match ($status) {
            'finished' => 'Die Korrektur ist noch nicht begonnen. Beginne zuerst die Korrektur.',
            'returned', 'archived' => 'Die Ergebnisse sind bereits freigegeben. Nimm die Freigabe zurück, um etwas zu ändern.',
            default => 'Diese Klausur ist noch nicht beendet.',
        });
    }

    /** @return array<string, mixed> */
    private function leafTask(int $examId, int $taskId): array
    {
        $task = $this->db->fetchOne(
            'SELECT id, points, answer_type FROM tasks WHERE id = ? AND exam_id = ? AND answer_type IS NOT NULL',
            [$taskId, $examId],
        );
        if ($task === null) {
            throw new HttpException(404, 'Aufgabe nicht gefunden.');
        }

        return $task;
    }

    /** @return array<string, mixed> */
    private function requireParticipation(int $examId, int $participationId): array
    {
        $p = $this->db->fetchOne('SELECT * FROM participations WHERE id = ? AND exam_id = ?', [$participationId, $examId]);
        if ($p === null) {
            throw new HttpException(404, 'Teilnahme nicht gefunden.');
        }

        return $p;
    }
}
