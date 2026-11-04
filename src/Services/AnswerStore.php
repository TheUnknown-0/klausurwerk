<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Services\TaskTypes\TaskType;
use App\Services\TaskTypes\TaskTypeRegistry;

/**
 * Autosave der Schüleransicht (docs/API.md 1.5).
 *
 * Regeln:
 *  - höchstens 200 Schritte je Anfrage,
 *  - Schritte mit seq <= last_seq sind Duplikate und werden ignoriert (Lücken sind erlaubt),
 *  - Speichern nur im Zustand active; in anderen Zuständen nur Schritte, deren client_ts (mit Offset
 *    korrigiert) vor state_changed_at + 2 s liegt (offline vor der Sperre entstanden),
 *  - nach submitted immer 409,
 *  - nach Zeitende wird gespeichert, aber is_overtime / has_overtime gesetzt,
 *  - Inhalte laufen durch TaskType::normalizeContent(); ungültig ergibt 422 für das ganze Paket,
 *  - alles in einer Transaktion, die Teilnahme ist dabei gesperrt (parallele Pakete werden serialisiert).
 */
final class AnswerStore
{
    public const MAX_ITEMS = 200;
    /** Toleranz für Schritte, die kurz vor einem Zustandswechsel entstanden sind. */
    public const OFFLINE_WINDOW_MS = 2000;
    public const KINDS = ['ops', 'snapshot', 'value'];
    private const MAX_PAYLOAD_BYTES = 524288;

    /** @var callable(string): ?TaskType */
    private $typeResolver;

    /** @param (callable(string): ?TaskType)|null $typeResolver Auflösung des Antworttyps (Standard: TaskTypeRegistry) */
    public function __construct(private readonly Database $db, ?callable $typeResolver = null)
    {
        $this->typeResolver = $typeResolver ?? TaskTypeRegistry::get(...);
    }

    /**
     * @param array<string, mixed> $exam Zeile aus exams
     * @param array<string, mixed> $participation Zeile aus participations
     * @param list<mixed> $items Replay-Schritte
     * @param array<string|int, mixed> $contents Aufgaben-ID => {content, flagged}
     * @param int $clientOffsetMs server_time - client_time (0, wenn unbekannt)
     * @return array{ack_seq: int, versions: array<int, int>, rejected: list<int>, saved_items: int, server_time_ms: int}
     * @throws HttpException 400/409/422
     */
    public function save(array $exam, array $participation, array $items, array $contents, int $nowMs, int $clientOffsetMs = 0): array
    {
        if (count($items) > self::MAX_ITEMS) {
            throw new HttpException(400, 'Zu viele Schritte in einer Anfrage (höchstens ' . self::MAX_ITEMS . ').');
        }
        if (!in_array((string) $exam['status'], ['running', 'paused'], true)) {
            throw new HttpException(409, 'Die Klausur nimmt keine Antworten mehr an.');
        }

        $cleanItems = $this->cleanItems($items);
        $cleanContents = $this->cleanContents($contents);

        return $this->db->transaction(function () use ($exam, $participation, $cleanItems, $cleanContents, $nowMs, $clientOffsetMs): array {
            $pid = (int) $participation['id'];
            $row = $this->db->fetchOne('SELECT * FROM participations WHERE id = ? AND exam_id = ? FOR UPDATE', [$pid, (int) $exam['id']]);
            if ($row === null) {
                throw new HttpException(404, 'Teilnahme nicht gefunden.');
            }
            $state = (string) $row['state'];
            if ($state === 'submitted') {
                throw new HttpException(409, 'Du hast bereits abgegeben. Änderungen werden nicht mehr gespeichert.');
            }
            if ($state === 'waiting') {
                throw new HttpException(409, 'Die Klausur hat für dich noch nicht begonnen.');
            }

            $taskIds = array_values(array_unique(array_merge(
                array_column($cleanItems, 'task_id'),
                array_keys($cleanContents),
            )));
            $tasks = $this->loadTasks((int) $exam['id'], $taskIds);

            $restricted = $state !== 'active';
            $cutoff = (ExamClock::toMs($row['state_changed_at']) ?? 0) + self::OFFLINE_WINDOW_MS;
            $clock = ExamClock::forParticipation($exam, $row, $nowMs);
            $overtime = $clock['started'] && $clock['time_up'];
            $nowDb = ExamClock::toDb($nowMs);

            $lastSeq = (int) $row['last_seq'];
            $maxSeq = $lastSeq;
            $accepted = [];   // task_id => Anzahl neu gespeicherter Schritte
            $seen = [];       // task_id => Anzahl Schritte insgesamt (auch Duplikate)
            $rejected = [];
            $duplicates = 0;
            $saved = 0;

            foreach ($cleanItems as $item) {
                $taskId = $item['task_id'];
                if (!isset($tasks[$taskId])) {
                    throw new HttpException(422, 'Unbekannte Aufgabe in den Schritten.');
                }
                $seen[$taskId] = ($seen[$taskId] ?? 0) + 1;
                if ($item['seq'] <= $lastSeq) {
                    $duplicates++;
                    continue;
                }
                if ($restricted && $item['client_ts'] + $clientOffsetMs >= $cutoff) {
                    $rejected[] = $item['seq'];
                    continue;
                }
                $this->db->run(
                    'INSERT INTO answer_ops (participation_id, task_id, seq, client_ts, server_ts, kind, payload, is_paste, is_overtime)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE seq = seq',
                    [$pid, $taskId, $item['seq'], $item['client_ts'], $nowDb, $item['kind'], $item['payload'], $item['is_paste'] ? 1 : 0, $overtime ? 1 : 0],
                );
                $accepted[$taskId] = ($accepted[$taskId] ?? 0) + 1;
                $maxSeq = max($maxSeq, $item['seq']);
                $saved++;
            }

            $versions = [];
            $answeredDelta = 0;
            $flaggedDelta = 0;
            $contentSaved = false;

            foreach ($cleanContents as $taskId => $entry) {
                if (!isset($tasks[$taskId])) {
                    throw new HttpException(422, 'Unbekannte Aufgabe in den Antworten.');
                }
                $task = $tasks[$taskId];
                $existing = $this->db->fetchOne(
                    'SELECT id, content, version, flagged, has_overtime FROM answers WHERE participation_id = ? AND task_id = ?',
                    [$pid, $taskId],
                );
                $hasItems = isset($seen[$taskId]);
                $newItems = $accepted[$taskId] ?? 0;
                // Gesperrt: nur Inhalte, zu denen Schritte aus dem Offline-Fenster gehören.
                // Aktiv: Inhalte ohne neuen Schritt nur, wenn überhaupt keine Schritte dabei waren (sonst veraltet).
                $allowed = $restricted ? $newItems > 0 : (!$hasItems || $newItems > 0);
                if (!$allowed) {
                    if ($existing !== null) {
                        $versions[$taskId] = (int) $existing['version'];
                    }
                    continue;
                }

                $type = $task['type'];
                try {
                    $normalized = $type->normalizeContent($entry['content'], $task['config']);
                } catch (\InvalidArgumentException $e) {
                    throw new HttpException(422, 'Antwort zu Aufgabe ' . $taskId . ' ist ungültig: ' . $e->getMessage());
                }
                $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false || strlen($json) > TaskTypes\BaseTaskType::MAX_CONTENT_BYTES) {
                    throw new HttpException(422, 'Die Antwort zu Aufgabe ' . $taskId . ' ist zu groß.');
                }
                $flagged = $entry['flagged'] ? 1 : 0;
                $nowAnswered = $type->isAnswered($normalized);

                if ($existing === null) {
                    $this->db->run(
                        'INSERT INTO answers (participation_id, task_id, content, version, flagged, has_overtime, updated_at)
                         VALUES (?, ?, ?, 1, ?, ?, ?)',
                        [$pid, $taskId, $json, $flagged, $overtime ? 1 : 0, $nowDb],
                    );
                    $versions[$taskId] = 1;
                    $answeredDelta += $nowAnswered ? 1 : 0;
                    $flaggedDelta += $flagged;
                    $contentSaved = true;
                    continue;
                }

                $oldContent = json_decode((string) $existing['content'], true);
                $wasAnswered = is_array($oldContent) && $type->isAnswered($oldContent);
                $changed = (string) $existing['content'] !== $json || (int) $existing['flagged'] !== $flagged;
                $version = (int) $existing['version'];
                if ($changed) {
                    $version++;
                    $this->db->run(
                        'UPDATE answers SET content = ?, version = ?, flagged = ?, has_overtime = ?, updated_at = ? WHERE id = ?',
                        [$json, $version, $flagged, ((int) $existing['has_overtime'] === 1 || $overtime) ? 1 : 0, $nowDb, (int) $existing['id']],
                    );
                    $answeredDelta += ($nowAnswered ? 1 : 0) - ($wasAnswered ? 1 : 0);
                    $flaggedDelta += $flagged - (int) $existing['flagged'];
                    $contentSaved = true;
                }
                $versions[$taskId] = $version;
            }

            if ($restricted && $saved === 0 && $duplicates === 0 && !$contentSaved) {
                throw new HttpException(409, self::restrictedMessage($state));
            }

            $this->db->run(
                'UPDATE participations SET last_seq = ?, last_seen_at = ?,
                        last_save_at = IF(?, ?, last_save_at),
                        answered_count = GREATEST(0, CAST(answered_count AS SIGNED) + ?),
                        flagged_count = GREATEST(0, CAST(flagged_count AS SIGNED) + ?)
                 WHERE id = ?',
                [$maxSeq, $nowDb, ($saved > 0 || $contentSaved) ? 1 : 0, $nowDb, $answeredDelta, $flaggedDelta, $pid],
            );

            return [
                'ack_seq' => $maxSeq,
                'versions' => $versions,
                'rejected' => $rejected,
                'saved_items' => $saved,
                'server_time_ms' => $nowMs,
            ];
        });
    }

    /** Zähler (answered_count, flagged_count) einer Teilnahme aus den gespeicherten Antworten neu berechnen. */
    public function recount(int $participationId): void
    {
        $rows = $this->db->fetchAll(
            'SELECT a.content, a.flagged, t.answer_type FROM answers a JOIN tasks t ON t.id = a.task_id WHERE a.participation_id = ?',
            [$participationId],
        );
        $answered = 0;
        $flagged = 0;
        foreach ($rows as $row) {
            $type = $row['answer_type'] !== null ? ($this->typeResolver)((string) $row['answer_type']) : null;
            $content = json_decode((string) $row['content'], true);
            if ($type !== null && is_array($content) && $type->isAnswered($content)) {
                $answered++;
            }
            $flagged += (int) $row['flagged'];
        }
        $this->db->run('UPDATE participations SET answered_count = ?, flagged_count = ? WHERE id = ?', [$answered, $flagged, $participationId]);
    }

    public static function restrictedMessage(string $state): string
    {
        return match ($state) {
            'locked' => 'Dein Bildschirm ist gesperrt. Neue Eingaben werden nicht gespeichert.',
            'absent' => 'Du bist als abwesend eingetragen. Neue Eingaben werden nicht gespeichert.',
            'paused' => 'Die Klausur ist pausiert. Neue Eingaben werden nicht gespeichert.',
            'submit_requested' => 'Deine Abgabe wird gerade bestätigt. Neue Eingaben werden nicht gespeichert.',
            'device_pending' => 'Der Gerätewechsel muss erst freigegeben werden.',
            'submitted' => 'Du hast bereits abgegeben.',
            default => 'Neue Eingaben werden gerade nicht gespeichert.',
        };
    }

    // ---------- intern ----------

    /**
     * @param list<mixed> $items
     * @return list<array{seq: int, task_id: int, client_ts: int, kind: string, payload: string, is_paste: bool}>
     */
    private function cleanItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new HttpException(422, 'Ungültiger Schritt.');
            }
            $seq = $item['seq'] ?? null;
            $taskId = $item['task_id'] ?? null;
            $kind = $item['kind'] ?? 'value';
            if (!is_numeric($seq) || (int) $seq < 1 || !is_numeric($taskId) || (int) $taskId < 1 || !in_array($kind, self::KINDS, true)) {
                throw new HttpException(422, 'Ungültiger Schritt (seq, task_id oder kind).');
            }
            $payload = json_encode($item['payload'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false || strlen($payload) > self::MAX_PAYLOAD_BYTES) {
                throw new HttpException(422, 'Ein Schritt ist zu groß.');
            }
            $out[] = [
                'seq' => (int) $seq,
                'task_id' => (int) $taskId,
                'client_ts' => max(0, (int) ($item['client_ts'] ?? 0)),
                'kind' => (string) $kind,
                'payload' => $payload,
                'is_paste' => filter_var($item['is_paste'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }
        usort($out, static fn (array $a, array $b): int => $a['seq'] <=> $b['seq']);

        return $out;
    }

    /**
     * @param array<string|int, mixed> $contents
     * @return array<int, array{content: array<string, mixed>, flagged: bool}>
     */
    private function cleanContents(array $contents): array
    {
        $out = [];
        foreach ($contents as $taskId => $entry) {
            if (!is_numeric($taskId) || (int) $taskId < 1 || !is_array($entry) || !is_array($entry['content'] ?? null)) {
                throw new HttpException(422, 'Ungültige Antwort in contents.');
            }
            $out[(int) $taskId] = [
                'content' => $entry['content'],
                'flagged' => filter_var($entry['flagged'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $out;
    }

    /**
     * Aufgaben der Klausur mit Typ und Konfiguration. Aufgaben ohne Antworttyp (Container) sind keine Ziele.
     *
     * @param list<int> $taskIds
     * @return array<int, array{type: TaskType, config: array<string, mixed>}>
     */
    private function loadTasks(int $examId, array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($taskIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT id, answer_type, config FROM tasks WHERE exam_id = ? AND answer_type IS NOT NULL AND id IN ({$marks})",
            [$examId, ...$taskIds],
        );
        $out = [];
        foreach ($rows as $row) {
            $type = ($this->typeResolver)((string) $row['answer_type']);
            if ($type === null) {
                throw new HttpException(422, 'Unbekannter Antworttyp: ' . $row['answer_type']);
            }
            $config = json_decode((string) $row['config'], true);
            $out[(int) $row['id']] = ['type' => $type, 'config' => is_array($config) ? $config : []];
        }

        return $out;
    }
}
