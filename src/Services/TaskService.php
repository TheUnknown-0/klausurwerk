<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Services\TaskTypes\CodeType;
use App\Services\TaskTypes\TaskTypeRegistry;

/**
 * Aufgaben einer Klausur: Anlegen, Speichern (mit Typprüfung), Baum, Verschieben,
 * Kopieren (auch aus dem Archiv). Alle Prüfungen der Antworttypen laufen hier zusammen.
 */
final class TaskService
{
    public const MAX_TASKS_PER_EXAM = 300;
    public const AFB_VALUES = ['I', 'II', 'III', 'I-II', 'II-III'];
    /** Status, in denen Struktur und Aufgabentexte noch bearbeitbar sind. */
    public const EDITABLE_STATUSES = ['draft', 'ready'];

    public function __construct(
        private readonly Database $db,
        private readonly ?Materials $materials = null,
    ) {
    }

    /** Aufgabentexte, config und Struktur sind ab Status lobby gesperrt. @param array<string, mixed> $exam */
    public static function isLocked(array $exam): bool
    {
        return !in_array((string) ($exam['status'] ?? 'draft'), self::EDITABLE_STATUSES, true);
    }

    // ---------- Lesen ----------

    /**
     * Aufgaben mit Lösungsdaten in Anzeigereihenfolge (nur für Berechtigte, z. B. den Editor).
     *
     * @return list<array<string, mixed>>
     */
    public function fullList(int $examId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT t.*, s.solution AS solution_json, s.model_answer, s.expectation_md, s.tests AS tests_json
             FROM tasks t LEFT JOIN task_solutions s ON s.task_id = t.id
             WHERE t.exam_id = ?',
            [$examId],
        );
        $files = [];
        foreach ($this->db->fetchAll(
            'SELECT f.* FROM task_files f JOIN tasks t ON t.id = f.task_id WHERE t.exam_id = ? ORDER BY f.task_id, f.position, f.id',
            [$examId],
        ) as $f) {
            $files[(int) $f['task_id']][] = [
                'filename' => $f['filename'],
                'content' => $f['content'],
                'is_readonly' => (int) $f['is_readonly'] === 1,
            ];
        }

        $prepared = [];
        foreach ($rows as $row) {
            $prepared[] = $this->shape($row, $files[(int) $row['id']] ?? []);
        }

        return TaskTree::flatten(TaskTree::build($prepared));
    }

    /** @return array<string, mixed>|null */
    public function find(int $examId, int $taskId): ?array
    {
        foreach ($this->fullList($examId) as $task) {
            if ((int) $task['id'] === $taskId) {
                return $task;
            }
        }

        return null;
    }

    /**
     * Kurzliste ohne Lösungen (Übersicht, Prüfungen).
     *
     * @return list<array<string, mixed>>
     */
    public function outline(int $examId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, parent_id, position, title, points, answer_type FROM tasks WHERE exam_id = ?',
            [$examId],
        );

        return TaskTree::flatten(TaskTree::build($rows));
    }

    /** @param array<string, mixed> $row @param list<array<string, mixed>> $files @return array<string, mixed> */
    private function shape(array $row, array $files): array
    {
        $decode = static function (mixed $json, mixed $fallback): mixed {
            $v = json_decode((string) $json, true);

            return is_array($v) ? $v : $fallback;
        };

        return [
            'id' => (int) $row['id'],
            'exam_id' => (int) $row['exam_id'],
            'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'position' => (int) $row['position'],
            'title' => (string) $row['title'],
            'body_md' => (string) $row['body_md'],
            'points' => (float) $row['points'],
            'operator_id' => $row['operator_id'] !== null ? (int) $row['operator_id'] : null,
            'afb' => $row['afb'],
            'answer_type' => $row['answer_type'],
            'config' => $decode($row['config'] ?? '{}', []),
            'solution' => $decode($row['solution_json'] ?? '{}', []),
            'model_answer' => (string) ($row['model_answer'] ?? ''),
            'expectation_md' => (string) ($row['expectation_md'] ?? ''),
            'tests' => $decode($row['tests_json'] ?? '[]', []),
            'files' => $files,
        ];
    }

    // ---------- Schreiben ----------

    private function assertEditableStructure(array $exam): void
    {
        if (self::isLocked($exam)) {
            throw new HttpException(409, 'Die Klausur ist gestartet oder abgeschlossen: Aufgaben können nicht mehr hinzugefügt, gelöscht oder verschoben werden.');
        }
    }

    private static function json(mixed $value): string
    {
        if ($value === [] || $value === null) {
            return '{}';
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function jsonList(array $value): string
    {
        return json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Legt eine Aufgabe an (am Ende der Ebene).
     *
     * @param array<string, mixed> $exam
     */
    public function create(array $exam, ?int $parentId = null, ?string $answerType = null, string $title = ''): int
    {
        $this->assertEditableStructure($exam);
        $examId = (int) $exam['id'];

        if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM tasks WHERE exam_id = ?', [$examId]) >= self::MAX_TASKS_PER_EXAM) {
            throw new TaskValidationException('Eine Klausur kann höchstens ' . self::MAX_TASKS_PER_EXAM . ' Aufgaben enthalten.');
        }
        if ($parentId !== null) {
            $parent = $this->db->fetchOne('SELECT id, parent_id, answer_type FROM tasks WHERE id = ? AND exam_id = ?', [$parentId, $examId]);
            if ($parent === null) {
                throw new HttpException(404, 'Übergeordnete Aufgabe nicht gefunden.');
            }
            if ($parent['parent_id'] !== null) {
                throw new TaskValidationException('Teilaufgaben können nicht weiter untergliedert werden.');
            }
            if ($parent['answer_type'] !== null) {
                throw new TaskValidationException('Diese Aufgabe hat einen Antworttyp. Stelle ihn auf "Überschrift ohne Antwort", um Teilaufgaben anzulegen.');
            }
        }

        $type = null;
        if ($answerType !== null && $answerType !== '') {
            $type = TaskTypeRegistry::get($answerType) ?? throw new TaskValidationException('Unbekannter Antworttyp.', 'answer_type');
        }

        $position = (int) $this->db->fetchValue(
            'SELECT COALESCE(MAX(position) + 1, 0) FROM tasks WHERE exam_id = ? AND parent_id <=> ?',
            [$examId, $parentId],
        );

        return (int) $this->db->transaction(function () use ($examId, $parentId, $type, $position, $title): int {
            $this->db->run(
                'INSERT INTO tasks (exam_id, parent_id, position, title, body_md, points, answer_type, config)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $examId, $parentId, $position, mb_substr($title, 0, 200), '', 0,
                    $type?->name(), self::json($type?->defaultConfig() ?? []),
                ],
            );
            $id = $this->db->lastInsertId();
            $this->db->run(
                'INSERT INTO task_solutions (task_id, solution, model_answer, expectation_md, tests) VALUES (?, ?, ?, ?, ?)',
                [$id, self::json($type?->defaultSolution() ?? []), '', '', '[]'],
            );

            return $id;
        });
    }

    /**
     * Speichert eine Aufgabe. Gesperrte Klausuren (ab lobby) erlauben nur Musterlösung,
     * Erwartungshorizont, Lösung und Tests.
     *
     * @param array<string, mixed> $exam
     * @param array<string, mixed> $input
     * @return array<string, mixed> die gespeicherte Aufgabe
     */
    public function save(array $exam, int $taskId, array $input): array
    {
        $examId = (int) $exam['id'];
        $current = $this->find($examId, $taskId) ?? throw new HttpException(404, 'Aufgabe nicht gefunden.');
        $locked = self::isLocked($exam);

        $childCount = (int) $this->db->fetchValue('SELECT COUNT(*) FROM tasks WHERE parent_id = ?', [$taskId]);

        // Endgültige Struktur-/Konfigurationsfelder bestimmen.
        $title = $current['title'];
        $bodyMd = $current['body_md'];
        $points = (float) $current['points'];
        $operatorId = $current['operator_id'];
        $afb = $current['afb'];
        $answerType = $current['answer_type'];
        $config = $current['config'];
        $files = $current['files'];

        if (!$locked) {
            $title = trim(mb_substr((string) ($input['title'] ?? ''), 0, 200));
            $bodyMd = mb_substr((string) ($input['body_md'] ?? ''), 0, 100000);

            $points = round((float) str_replace(',', '.', (string) ($input['points'] ?? 0)), 2);
            if ($points < 0 || $points > 999) {
                throw new TaskValidationException('Die Punktzahl muss zwischen 0 und 999 liegen.', 'points');
            }
            // Die Korrektur vergibt nur 0,5-Schritte (GradingService::normalizePoints), sonst wäre das Maximum nie erreichbar.
            if (abs($points * 2 - round($points * 2)) > 1e-6) {
                throw new TaskValidationException('Die Punktzahl muss ein Vielfaches von 0,5 sein.', 'points');
            }

            $operatorId = ($input['operator_id'] ?? null) === null || $input['operator_id'] === '' ? null : (int) $input['operator_id'];
            if ($operatorId !== null && $this->db->fetchValue('SELECT id FROM operators WHERE id = ?', [$operatorId]) === null) {
                $operatorId = null;
            }
            $afb = $input['afb'] ?? null;
            $afb = ($afb === null || $afb === '') ? null : (string) $afb;
            if ($afb !== null && !in_array($afb, self::AFB_VALUES, true)) {
                throw new TaskValidationException('Ungültiger Anforderungsbereich.', 'afb');
            }

            $answerType = $input['answer_type'] ?? null;
            $answerType = ($answerType === null || $answerType === '') ? null : (string) $answerType;
            if ($answerType !== null && TaskTypeRegistry::get($answerType) === null) {
                throw new TaskValidationException('Unbekannter Antworttyp: ' . mb_substr($answerType, 0, 40), 'answer_type');
            }
            if ($answerType !== null && $childCount > 0) {
                throw new TaskValidationException('Aufgaben mit Teilaufgaben haben keinen eigenen Antworttyp.', 'answer_type');
            }

            if ($answerType === null) {
                $config = [];
                $files = [];
            } else {
                $type = TaskTypeRegistry::require($answerType);
                $rawConfig = is_array($input['config'] ?? null) ? $input['config'] : [];
                if ($answerType === 'code') {
                    $files = $this->normalizeFiles($input['files'] ?? []);
                    $rawConfig['readonly_files'] = array_values(array_map(
                        static fn (array $f): string => $f['filename'],
                        array_filter($files, static fn (array $f): bool => $f['is_readonly']),
                    ));
                    $rawConfig['editable_files'] = array_values(array_map(
                        static fn (array $f): string => $f['filename'],
                        array_filter($files, static fn (array $f): bool => !$f['is_readonly']),
                    ));
                } else {
                    $files = [];
                }
                try {
                    $config = $type->normalizeConfig($rawConfig);
                } catch (\InvalidArgumentException $e) {
                    throw new TaskValidationException($e->getMessage(), 'config');
                }
            }
        }

        // Lösungsseite (immer bearbeitbar)
        $solution = $current['solution'];
        $modelAnswer = $current['model_answer'];
        $expectation = $current['expectation_md'];
        $tests = $current['tests'];

        if (array_key_exists('model_answer', $input)) {
            $modelAnswer = mb_substr((string) $input['model_answer'], 0, 100000);
        }
        if (array_key_exists('expectation_md', $input)) {
            $expectation = mb_substr((string) $input['expectation_md'], 0, 100000);
        }

        if ($answerType === null) {
            $solution = [];
            $tests = [];
        } else {
            $type = TaskTypeRegistry::require($answerType);
            $typeChanged = $answerType !== $current['answer_type'];
            if (array_key_exists('solution', $input) || $typeChanged) {
                $rawSolution = is_array($input['solution'] ?? null) ? $input['solution'] : [];
                try {
                    $solution = $type->normalizeSolution($rawSolution, $config);
                } catch (\InvalidArgumentException $e) {
                    if ($rawSolution !== []) {
                        throw new TaskValidationException($e->getMessage(), 'solution');
                    }
                    // Noch keine Lösung eingegeben: Entwurf mit leerer Standardlösung speichern.
                    $solution = $type->defaultSolution();
                }
            }
            if ($answerType === 'code') {
                if (array_key_exists('tests', $input) || $typeChanged) {
                    try {
                        $tests = CodeType::normalizeTests($input['tests'] ?? [], (string) ($config['language'] ?? 'java'));
                    } catch (\InvalidArgumentException $e) {
                        throw new TaskValidationException($e->getMessage(), 'tests');
                    }
                }
            } elseif ($answerType === 'robo') {
                // Testwelten der Gitterwelt (Korrektur im Runner); Altbestand in solution.tests übernehmen.
                if (array_key_exists('tests', $input) || $typeChanged) {
                    $rawTests = $input['tests'] ?? ($solution['tests'] ?? []);
                    try {
                        $tests = TaskTypes\RoboType::normalizeTests($rawTests);
                    } catch (\InvalidArgumentException $e) {
                        throw new TaskValidationException($e->getMessage(), 'tests');
                    }
                }
            } else {
                $tests = [];
            }
        }

        $this->db->transaction(function () use (
            $taskId, $locked, $title, $bodyMd, $points, $operatorId, $afb, $answerType, $config, $files,
            $solution, $modelAnswer, $expectation, $tests, $examId
        ): void {
            if (!$locked) {
                $this->db->run(
                    'UPDATE tasks SET title = ?, body_md = ?, points = ?, operator_id = ?, afb = ?, answer_type = ?, config = ? WHERE id = ?',
                    [$title, $bodyMd, $points, $operatorId, $afb, $answerType, self::json($config), $taskId],
                );
                $this->db->run('DELETE FROM task_files WHERE task_id = ?', [$taskId]);
                foreach ($files as $i => $file) {
                    $this->db->run(
                        'INSERT INTO task_files (task_id, filename, content, is_readonly, position) VALUES (?, ?, ?, ?, ?)',
                        [$taskId, $file['filename'], $file['content'], $file['is_readonly'] ? 1 : 0, $i],
                    );
                }
            }
            $this->db->run(
                'INSERT INTO task_solutions (task_id, solution, model_answer, expectation_md, tests) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE solution = VALUES(solution), model_answer = VALUES(model_answer),
                    expectation_md = VALUES(expectation_md), tests = VALUES(tests)',
                [$taskId, self::json($solution), $modelAnswer, $expectation, self::jsonList(is_array($tests) ? $tests : [])],
            );
            $this->recomputePoints($examId);
        });

        return $this->find($examId, $taskId) ?? throw new HttpException(500);
    }

    /**
     * Vorgegebene Dateien prüfen.
     *
     * @return list<array{filename: string, content: string, is_readonly: bool}>
     */
    public function normalizeFiles(mixed $files): array
    {
        if (!is_array($files)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $name = trim((string) ($file['filename'] ?? $file['name'] ?? ''));
            if (preg_match(CodeType::FILENAME_PATTERN, $name) !== 1) {
                throw new TaskValidationException('Ungültiger Dateiname "' . mb_substr($name, 0, 40) . '" (erlaubt: Buchstaben, Ziffern, _ . -, höchstens 80 Zeichen).', 'files');
            }
            if (isset($seen[$name])) {
                throw new TaskValidationException('Der Dateiname "' . $name . '" kommt doppelt vor.', 'files');
            }
            if (count($out) >= CodeType::MAX_FILES) {
                throw new TaskValidationException('Höchstens ' . CodeType::MAX_FILES . ' Dateien je Aufgabe.', 'files');
            }
            $content = (string) ($file['content'] ?? '');
            if (strlen($content) > CodeType::MAX_FILE_BYTES) {
                throw new TaskValidationException('Die Datei "' . $name . '" ist zu groß (höchstens 200 KB).', 'files');
            }
            $seen[$name] = true;
            $out[] = [
                'filename' => $name,
                'content' => $content,
                'is_readonly' => filter_var($file['is_readonly'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $out;
    }

    /** Container-Punkte = Summe der Teilaufgaben. */
    public function recomputePoints(int $examId): void
    {
        $sums = $this->db->fetchAll(
            'SELECT parent_id, SUM(points) AS total FROM tasks WHERE exam_id = ? AND parent_id IS NOT NULL GROUP BY parent_id',
            [$examId],
        );
        foreach ($sums as $row) {
            $this->db->run('UPDATE tasks SET points = ? WHERE id = ? AND exam_id = ?', [$row['total'], (int) $row['parent_id'], $examId]);
        }
    }

    /** @param array<string, mixed> $exam */
    public function delete(array $exam, int $taskId): void
    {
        $this->assertEditableStructure($exam);
        $examId = (int) $exam['id'];
        $task = $this->db->fetchOne('SELECT id, parent_id FROM tasks WHERE id = ? AND exam_id = ?', [$taskId, $examId])
            ?? throw new HttpException(404, 'Aufgabe nicht gefunden.');
        $this->db->transaction(function () use ($taskId, $task, $examId): void {
            $this->db->run('DELETE FROM tasks WHERE id = ?', [$taskId]);
            $this->renumber($examId, $task['parent_id'] !== null ? (int) $task['parent_id'] : null);
            $this->recomputePoints($examId);
        });
    }

    /**
     * Setzt eine Aufgabe direkt hinter eine andere derselben Ebene (für "Frage darunter einfügen"
     * und Duplizieren). Liegen beide auf verschiedenen Ebenen, bleibt alles, wie es ist.
     *
     * @param array<string, mixed> $exam
     */
    public function placeAfter(array $exam, int $taskId, int $afterId): void
    {
        $this->assertEditableStructure($exam);
        $examId = (int) $exam['id'];
        $task = $this->db->fetchOne('SELECT id, parent_id FROM tasks WHERE id = ? AND exam_id = ?', [$taskId, $examId])
            ?? throw new HttpException(404, 'Aufgabe nicht gefunden.');
        $after = $this->db->fetchOne('SELECT id, parent_id FROM tasks WHERE id = ? AND exam_id = ?', [$afterId, $examId])
            ?? throw new HttpException(404, 'Aufgabe nicht gefunden.');
        $parentId = $task['parent_id'] !== null ? (int) $task['parent_id'] : null;
        $afterParent = $after['parent_id'] !== null ? (int) $after['parent_id'] : null;
        if ($taskId === $afterId || $parentId !== $afterParent) {
            return;
        }
        $this->db->transaction(function () use ($examId, $parentId, $taskId, $afterId): void {
            $ids = array_map('intval', array_column($this->db->fetchAll(
                'SELECT id FROM tasks WHERE exam_id = ? AND parent_id <=> ? ORDER BY position, id',
                [$examId, $parentId],
            ), 'id'));
            $ids = array_values(array_filter($ids, static fn (int $id): bool => $id !== $taskId));
            $at = array_search($afterId, $ids, true);
            array_splice($ids, $at === false ? count($ids) : $at + 1, 0, [$taskId]);
            $this->renumber($examId, $parentId, $ids);
        });
    }

    /**
     * Dupliziert eine Aufgabe (samt Teilaufgaben, Lösung und Tests) direkt hinter das Original.
     *
     * @param array<string, mixed> $exam
     * @return int ID der Kopie
     */
    public function duplicate(array $exam, int $taskId): int
    {
        $this->assertEditableStructure($exam);
        $examId = (int) $exam['id'];
        $task = $this->db->fetchOne('SELECT id, parent_id, title FROM tasks WHERE id = ? AND exam_id = ?', [$taskId, $examId])
            ?? throw new HttpException(404, 'Aufgabe nicht gefunden.');
        $count = (int) $this->db->fetchValue('SELECT COUNT(*) FROM tasks WHERE exam_id = ?', [$examId]);
        $subtree = 1 + (int) $this->db->fetchValue('SELECT COUNT(*) FROM tasks WHERE parent_id = ?', [$taskId]);
        if ($count + $subtree > self::MAX_TASKS_PER_EXAM) {
            throw new TaskValidationException('Eine Klausur kann höchstens ' . self::MAX_TASKS_PER_EXAM . ' Aufgaben enthalten.');
        }
        $parentId = $task['parent_id'] !== null ? (int) $task['parent_id'] : null;
        $newId = $this->copyTask($taskId, $examId, $parentId);
        $title = trim((string) $task['title']);
        $this->db->run('UPDATE tasks SET title = ? WHERE id = ?', [mb_substr($title === '' ? '' : $title . ' (Kopie)', 0, 200), $newId]);
        $this->placeAfter($exam, $newId, $taskId);

        return $newId;
    }

    /** Positionen einer Ebene lückenlos neu vergeben. */
    private function renumber(int $examId, ?int $parentId, ?array $order = null): void
    {
        $ids = $order ?? array_map(
            'intval',
            array_column($this->db->fetchAll(
                'SELECT id FROM tasks WHERE exam_id = ? AND parent_id <=> ? ORDER BY position, id',
                [$examId, $parentId],
            ), 'id'),
        );
        foreach ($ids as $i => $id) {
            $this->db->run('UPDATE tasks SET position = ?, parent_id = ? WHERE id = ? AND exam_id = ?', [$i, $parentId, $id, $examId]);
        }
    }

    /**
     * Verschiebt eine Aufgabe: up, down (innerhalb der Ebene), indent (wird Teilaufgabe der
     * vorherigen Aufgabe), outdent (wird Aufgabe der obersten Ebene hinter ihrer bisherigen Hauptaufgabe).
     *
     * @param array<string, mixed> $exam
     */
    public function move(array $exam, int $taskId, string $direction): void
    {
        $this->assertEditableStructure($exam);
        $examId = (int) $exam['id'];
        $task = $this->db->fetchOne('SELECT id, parent_id, answer_type FROM tasks WHERE id = ? AND exam_id = ?', [$taskId, $examId])
            ?? throw new HttpException(404, 'Aufgabe nicht gefunden.');
        $parentId = $task['parent_id'] !== null ? (int) $task['parent_id'] : null;

        $siblings = array_map('intval', array_column($this->db->fetchAll(
            'SELECT id FROM tasks WHERE exam_id = ? AND parent_id <=> ? ORDER BY position, id',
            [$examId, $parentId],
        ), 'id'));
        $index = array_search($taskId, $siblings, true);

        $this->db->transaction(function () use ($direction, $examId, $taskId, $task, $parentId, $siblings, $index): void {
            switch ($direction) {
                case 'up':
                case 'down':
                    $swap = $direction === 'up' ? $index - 1 : $index + 1;
                    if ($swap < 0 || $swap >= count($siblings)) {
                        return;
                    }
                    [$siblings[$index], $siblings[$swap]] = [$siblings[$swap], $siblings[$index]];
                    $this->renumber($examId, $parentId, $siblings);
                    break;

                case 'indent':
                    if ($parentId !== null || $index === 0) {
                        throw new TaskValidationException('Diese Aufgabe kann nicht eingerückt werden (es fehlt eine vorherige Aufgabe auf derselben Ebene).');
                    }
                    if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM tasks WHERE parent_id = ?', [$taskId]) > 0) {
                        throw new TaskValidationException('Aufgaben mit Teilaufgaben können nicht eingerückt werden.');
                    }
                    $newParent = $siblings[$index - 1];
                    $newParentType = $this->db->fetchValue('SELECT answer_type FROM tasks WHERE id = ?', [$newParent]);
                    if ($newParentType !== null) {
                        throw new TaskValidationException('Die vorherige Aufgabe hat einen Antworttyp. Stelle sie zuerst auf "Überschrift ohne Antwort".');
                    }
                    $newSiblings = array_map('intval', array_column($this->db->fetchAll(
                        'SELECT id FROM tasks WHERE parent_id = ? ORDER BY position, id',
                        [$newParent],
                    ), 'id'));
                    $newSiblings[] = $taskId;
                    unset($siblings[$index]);
                    $this->renumber($examId, null, array_values($siblings));
                    $this->renumber($examId, $newParent, $newSiblings);
                    break;

                case 'outdent':
                    if ($parentId === null) {
                        throw new TaskValidationException('Diese Aufgabe steht bereits auf der obersten Ebene.');
                    }
                    $parentRow = $this->db->fetchOne('SELECT id, parent_id FROM tasks WHERE id = ?', [$parentId]);
                    $grand = $parentRow !== null && $parentRow['parent_id'] !== null ? (int) $parentRow['parent_id'] : null;
                    $top = array_map('intval', array_column($this->db->fetchAll(
                        'SELECT id FROM tasks WHERE exam_id = ? AND parent_id <=> ? ORDER BY position, id',
                        [$examId, $grand],
                    ), 'id'));
                    $at = array_search($parentId, $top, true);
                    array_splice($top, $at === false ? count($top) : $at + 1, 0, [$taskId]);
                    unset($siblings[$index]);
                    $this->renumber($examId, $parentId, array_values($siblings));
                    $this->renumber($examId, $grand, $top);
                    break;

                default:
                    throw new TaskValidationException('Unbekannte Verschiebung.');
            }
            $this->recomputePoints($examId);
        });
    }

    // ---------- Kopieren ----------

    /**
     * Kopiert eine Aufgabe samt Teilaufgaben, Dateien und (optional) Lösungen in eine andere
     * (oder dieselbe) Klausur. Im Text referenzierte Materialien werden als neue Kopien
     * angelegt und die material:ID-Verweise umgeschrieben. Kopien sind unabhängig.
     *
     * @param array<int, int> $materialMap Quell-Material-ID => neue ID (wird ergänzt, auch für Aufrufer-Wiederverwendung)
     * @param array<int, int> $taskMap Quell-Aufgaben-ID => neue ID (wird ergänzt)
     * @return int ID der neuen obersten kopierten Aufgabe
     */
    public function copyTask(
        int $taskId,
        int $targetExamId,
        ?int $targetParentId = null,
        bool $withSolutions = true,
        array &$materialMap = [],
        array &$taskMap = [],
    ): int {
        $source = $this->db->fetchOne('SELECT * FROM tasks WHERE id = ?', [$taskId]) ?? throw new HttpException(404, 'Aufgabe nicht gefunden.');
        $sourceExamId = (int) $source['exam_id'];

        $children = $this->db->fetchAll('SELECT id FROM tasks WHERE parent_id = ? ORDER BY position, id', [$taskId]);
        if ($targetParentId !== null) {
            if ($children !== []) {
                throw new TaskValidationException('Aufgaben mit Teilaufgaben können nur auf der obersten Ebene eingefügt werden.');
            }
            $parent = $this->db->fetchOne('SELECT parent_id, answer_type FROM tasks WHERE id = ? AND exam_id = ?', [$targetParentId, $targetExamId])
                ?? throw new HttpException(404, 'Zielaufgabe nicht gefunden.');
            if ($parent['parent_id'] !== null || $parent['answer_type'] !== null) {
                throw new TaskValidationException('Die Zielaufgabe kann keine Teilaufgaben aufnehmen.');
            }
        }
        $targetExam = $this->db->fetchOne('SELECT * FROM exams WHERE id = ?', [$targetExamId]) ?? throw new HttpException(404, 'Zielklausur nicht gefunden.');
        $this->assertEditableStructure($targetExam);

        return (int) $this->db->transaction(function () use ($source, $sourceExamId, $targetExamId, $targetParentId, $withSolutions, &$materialMap, &$taskMap): int {
            $newId = $this->copyRow($source, $sourceExamId, $targetExamId, $targetParentId, $withSolutions, $materialMap, $taskMap);
            $this->recomputePoints($targetExamId);

            return $newId;
        });
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, int> $materialMap
     * @param array<int, int> $taskMap
     */
    private function copyRow(array $source, int $sourceExamId, int $targetExamId, ?int $parentId, bool $withSolutions, array &$materialMap, array &$taskMap): int
    {
        $body = $this->rewriteMaterials((string) $source['body_md'], $sourceExamId, $targetExamId, $materialMap);
        $position = (int) $this->db->fetchValue(
            'SELECT COALESCE(MAX(position) + 1, 0) FROM tasks WHERE exam_id = ? AND parent_id <=> ?',
            [$targetExamId, $parentId],
        );
        $this->db->run(
            'INSERT INTO tasks (exam_id, parent_id, position, title, body_md, points, operator_id, afb, answer_type, config)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$targetExamId, $parentId, $position, $source['title'], $body, $source['points'], $source['operator_id'], $source['afb'], $source['answer_type'], $source['config']],
        );
        $newId = $this->db->lastInsertId();
        $taskMap[(int) $source['id']] = $newId;

        $this->db->run(
            'INSERT INTO task_files (task_id, filename, content, is_readonly, position)
             SELECT ?, filename, content, is_readonly, position FROM task_files WHERE task_id = ?',
            [$newId, (int) $source['id']],
        );

        $solution = $this->db->fetchOne('SELECT * FROM task_solutions WHERE task_id = ?', [(int) $source['id']]);
        if ($withSolutions && $solution !== null) {
            $this->db->run(
                'INSERT INTO task_solutions (task_id, solution, model_answer, expectation_md, tests) VALUES (?, ?, ?, ?, ?)',
                [
                    $newId, $solution['solution'],
                    $this->rewriteMaterials((string) $solution['model_answer'], $sourceExamId, $targetExamId, $materialMap),
                    $this->rewriteMaterials((string) $solution['expectation_md'], $sourceExamId, $targetExamId, $materialMap),
                    $solution['tests'],
                ],
            );
        } else {
            $this->db->run(
                'INSERT INTO task_solutions (task_id, solution, model_answer, expectation_md, tests) VALUES (?, ?, ?, ?, ?)',
                [$newId, '{}', '', '', '[]'],
            );
        }

        foreach ($this->db->fetchAll('SELECT * FROM tasks WHERE parent_id = ? ORDER BY position, id', [(int) $source['id']]) as $child) {
            $this->copyRow($child, $sourceExamId, $targetExamId, $newId, $withSolutions, $materialMap, $taskMap);
        }

        return $newId;
    }

    /**
     * Schreibt material:ID-Verweise auf Kopien in der Zielklausur um. Verweise auf
     * Materialien anderer Klausuren oder gelöschte Materialien bleiben unverändert.
     *
     * @param array<int, int> $materialMap
     */
    private function rewriteMaterials(string $text, int $sourceExamId, int $targetExamId, array &$materialMap): string
    {
        if ($this->materials === null || !str_contains($text, 'material:')) {
            return $text;
        }

        return (string) preg_replace_callback('/material:(\d+)/', function (array $m) use ($sourceExamId, $targetExamId, &$materialMap): string {
            $oldId = (int) $m[1];
            if (!isset($materialMap[$oldId])) {
                $row = $this->materials->find($oldId);
                if ($row === null || (int) $row['exam_id'] !== $sourceExamId) {
                    return $m[0];
                }
                if ($sourceExamId === $targetExamId) {
                    return $m[0];
                }
                $materialMap[$oldId] = (int) $this->materials->copyTo($row, $targetExamId, null)['id'];
            }

            return 'material:' . $materialMap[$oldId];
        }, $text);
    }

    // ---------- Prüfung ----------

    /**
     * Fertigstellen-Prüfung: Fehler verhindern den Wechsel auf "ready", Warnungen nicht.
     *
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function check(int $examId): array
    {
        $errors = [];
        $warnings = [];
        $tasks = $this->fullList($examId);
        if ($tasks === []) {
            $errors[] = 'Die Klausur enthält noch keine Aufgabe.';
        }
        foreach ($tasks as $task) {
            $label = 'Aufgabe ' . $task['number'] . ($task['title'] !== '' ? ' (' . $task['title'] . ')' : '');
            if ($task['answer_type'] === null) {
                continue;
            }
            if ($task['points'] <= 0) {
                $errors[] = $label . ': Es sind keine Punkte vergeben.';
            }
            if ($task['answer_type'] === 'code') {
                $main = (string) ($task['config']['main_file'] ?? '');
                $names = array_column($task['files'], 'filename');
                if ($main === '') {
                    $errors[] = $label . ': Es ist keine Startdatei festgelegt.';
                } elseif (!in_array($main, $names, true) && empty($task['config']['allow_new_files']) && $names !== []) {
                    $warnings[] = $label . ': Die Startdatei "' . $main . '" gehört nicht zu den vorgegebenen Dateien.';
                }
                if ($task['tests'] === [] && $task['model_answer'] === '') {
                    $warnings[] = $label . ': Weder Tests noch Musterlösung hinterlegt.';
                }
                $pointsWarning = self::testPointsWarning((float) $task['points'], $task['tests']);
                if ($pointsWarning !== null) {
                    $warnings[] = $label . ': ' . $pointsWarning;
                }
            }
            if ($task['answer_type'] === 'robo' && $task['tests'] === []) {
                $warnings[] = $label . ': Keine Testwelten hinterlegt; die Aufgabe kann nicht automatisch vorbewertet werden.';
            }
            $type = TaskTypeRegistry::get((string) $task['answer_type']);
            if ($type === null) {
                $errors[] = $label . ': Unbekannter Antworttyp.';
                continue;
            }
            if (in_array($task['answer_type'], ['choice', 'matching', 'ordering', 'gaps'], true)) {
                try {
                    $type->normalizeSolution($task['solution'], $task['config']);
                } catch (\InvalidArgumentException $e) {
                    $warnings[] = $label . ': Lösung unvollständig (' . $e->getMessage() . ')';
                }
            }
            if (trim($task['body_md']) === '' && trim($task['title']) === '') {
                $warnings[] = $label . ': Aufgabentext und Titel sind leer.';
            }
            if ($task['model_answer'] === '' && $task['expectation_md'] === '' && $task['answer_type'] === 'text') {
                $warnings[] = $label . ': Es gibt weder Musterlösung noch Erwartungshorizont.';
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Hinweis, wenn die Testpunkte nicht der Aufgabenpunktzahl entsprechen (Vorbewertung schlägt sonst
     * nicht die volle Punktzahl vor). Null, wenn alles passt oder keine Tests vorhanden sind.
     *
     * @param list<array<string, mixed>> $tests
     */
    public static function testPointsWarning(float $taskPoints, array $tests): ?string
    {
        if ($tests === []) {
            return null;
        }
        $sum = 0.0;
        foreach ($tests as $test) {
            $sum += (float) ($test['points'] ?? 1);
        }
        if (abs($sum - $taskPoints) < 0.005) {
            return null;
        }
        $fmt = static fn (float $n): string => rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',');

        return 'Die Tests ergeben zusammen ' . $fmt($sum) . ' Punkte, die Aufgabe ist aber mit '
            . $fmt($taskPoints) . ' Punkten bewertet; die Vorbewertung schlägt nicht die volle Punktzahl vor.';
    }
}
