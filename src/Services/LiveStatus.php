<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Pult-Stand (docs/API.md 3.1) und Überwachung des Herzschlags.
 *
 * Beim Abruf werden einmalig geschrieben:
 *  - connection_lost, wenn eine Person länger als LOST_MS keinen Herzschlag gesendet hat
 *    (participations.offline_reported_at merkt sich das), danach connection_restored,
 *  - time_up je Person (participations.time_up_notified_at) und für die Grundzeit der Klausur
 *    (exams.time_up_notified_at).
 * Die Markierungen werden atomar per UPDATE ... WHERE ... IS NULL gesetzt; mehrere Pulte
 * gleichzeitig erzeugen deshalb keine doppelten Ereignisse.
 */
final class LiveStatus
{
    /** Eine Person gilt als online, wenn der letzte Herzschlag jünger ist. */
    public const ONLINE_MS = 6000;
    /** Ab dieser Funkstille meldet der Server connection_lost. */
    public const LOST_MS = 10000;

    public function __construct(
        private readonly Database $db,
        private readonly EventLog $events,
    ) {
    }

    /**
     * Schreibt fällige Verbindungs- und time_up-Ereignisse.
     *
     * @param array<string, mixed> $exam
     */
    public function sweep(array $exam, int $nowMs): void
    {
        if (!in_array((string) $exam['status'], ['lobby', 'running', 'paused'], true)) {
            return;
        }
        $rows = $this->db->fetchAll(
            "SELECT * FROM participations WHERE exam_id = ? AND state <> 'submitted'",
            [(int) $exam['id']],
        );
        $this->sweepRows($exam, $rows, $nowMs);
    }

    /**
     * @param array<string, mixed> $exam
     * @param list<array<string, mixed>> $rows
     */
    private function sweepRows(array $exam, array $rows, int $nowMs): void
    {
        $examId = (int) $exam['id'];
        $nowDb = ExamClock::toDb($nowMs);
        $running = in_array((string) $exam['status'], ['running', 'paused'], true);

        foreach ($rows as $row) {
            if ($row['state'] === 'submitted') {
                continue;
            }
            $id = (int) $row['id'];
            $seen = ExamClock::toMs($row['last_seen_at']);
            $reported = ExamClock::toMs($row['offline_reported_at']);

            if ($seen !== null && $reported === null && $nowMs - $seen > self::LOST_MS) {
                $stmt = $this->db->run(
                    'UPDATE participations SET offline_reported_at = ? WHERE id = ? AND offline_reported_at IS NULL',
                    [$nowDb, $id],
                );
                if ($stmt->rowCount() === 1) {
                    $this->events->log($examId, $id, 'connection_lost', 'warning', ['silent_ms' => $nowMs - $seen], null, null, $nowMs);
                }
            } elseif ($seen !== null && $reported !== null && $seen > $reported) {
                $stmt = $this->db->run(
                    'UPDATE participations SET offline_reported_at = NULL WHERE id = ? AND offline_reported_at IS NOT NULL',
                    [$id],
                );
                if ($stmt->rowCount() === 1) {
                    $this->events->log($examId, $id, 'connection_restored', 'info', [], null, null, $nowMs);
                }
            }

            if ($running && $row['state'] !== 'waiting' && $row['time_up_notified_at'] === null) {
                $clock = ExamClock::forParticipation($exam, $row, $nowMs);
                if ($clock['started'] && $clock['time_up']) {
                    $stmt = $this->db->run(
                        'UPDATE participations SET time_up_notified_at = ? WHERE id = ? AND time_up_notified_at IS NULL',
                        [$nowDb, $id],
                    );
                    if ($stmt->rowCount() === 1) {
                        $this->events->log($examId, $id, 'time_up', 'info', [], null, null, $nowMs);
                    }
                }
            }
        }

        if ($running && $exam['time_up_notified_at'] === null) {
            $baseEnd = ExamClock::baseEndsAtMs($exam, $nowMs);
            if ($baseEnd !== null && $nowMs >= $baseEnd) {
                $stmt = $this->db->run(
                    'UPDATE exams SET time_up_notified_at = ? WHERE id = ? AND time_up_notified_at IS NULL',
                    [$nowDb, $examId],
                );
                if ($stmt->rowCount() === 1) {
                    $this->events->log($examId, null, 'time_up', 'info', ['scope' => 'exam'], null, null, $nowMs);
                }
            }
        }
    }

    /**
     * Vollständiger Pult-Stand.
     *
     * @param array<string, mixed> $exam Zeile aus exams (frisch geladen)
     * @return array<string, mixed>
     */
    public function stand(array $exam, int $cursor, int $nowMs): array
    {
        $this->sweep($exam, $nowMs);
        // sweep kann exams.time_up_notified_at ändern; für die Antwort nicht relevant.

        $examId = (int) $exam['id'];
        $settings = ExamSettings::normalize(ExamAccess::settingsOf($exam), (string) $exam['mode']);
        $roomId = $exam['room_id'] !== null ? (int) $exam['room_id'] : null;

        $rows = $this->db->fetchAll(
            'SELECT p.*, u.firstname, u.lastname, u.username, rp.col AS pos_col, rp.`row` AS pos_row
             FROM participations p
             JOIN users u ON u.id = p.user_id
             LEFT JOIN room_positions rp ON rp.room_id = ? AND rp.user_id = p.user_id
             WHERE p.exam_id = ?
             ORDER BY u.lastname, u.firstname, p.id',
            [$roomId, $examId],
        );

        $requests = [];
        $requestRows = $this->db->fetchAll(
            "SELECT id, participation_id, type, created_at FROM exam_requests WHERE exam_id = ? AND status = 'open' ORDER BY id",
            [$examId],
        );
        foreach ($requestRows as $r) {
            $requests[(int) $r['participation_id']][] = [
                'id' => (int) $r['id'],
                'type' => (string) $r['type'],
                'created_at_ms' => ExamClock::toMs($r['created_at']),
            ];
        }

        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM tasks WHERE exam_id = ? AND answer_type IS NOT NULL', [$examId]);

        $events = $this->events->since($examId, $cursor);
        $alarmIds = [];
        if ($cursor > 0) {
            foreach ($events as $event) {
                if ($event['severity'] === 'alarm' && $event['participation_id'] !== null) {
                    $alarmIds[$event['participation_id']] = true;
                }
            }
        }
        $newCursor = $events !== [] ? (int) end($events)['id'] : ($cursor > 0 ? $cursor : $this->events->lastId($examId));

        $participants = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $clock = ExamClock::forParticipation($exam, $row, $nowMs);
            $seen = ExamClock::toMs($row['last_seen_at']);
            $ago = $seen !== null ? max(0, $nowMs - $seen) : null;
            $saved = ExamClock::toMs($row['last_save_at']);
            $client = $row['client_state'] !== null ? json_decode((string) $row['client_state'], true) : null;
            $client = is_array($client) ? $client : [];

            $participants[] = [
                'id' => $id,
                'user_id' => (int) $row['user_id'],
                'name' => display_name($row),
                'username' => (string) $row['username'],
                'state' => (string) $row['state'],
                'state_since_ms' => ExamClock::toMs($row['state_changed_at']),
                'lock_reason' => $row['lock_reason'],
                'ready' => $row['ready_at'] !== null,
                'online' => $ago !== null && $ago < self::ONLINE_MS,
                'last_seen_ms_ago' => $ago,
                'client_state' => (object) $client,
                'progress' => [
                    'answered' => (int) $row['answered_count'],
                    'flagged' => (int) $row['flagged_count'],
                    'total' => $total,
                ],
                'clock' => [
                    'ends_at_ms' => $clock['ends_at_ms'],
                    'remaining_ms' => $clock['remaining_ms'],
                    'time_up' => $clock['time_up'],
                    'extra_minutes' => $clock['extra_minutes'],
                    'accommodation_percent' => $clock['accommodation_percent'],
                    'paused' => $clock['paused'],
                    'unlimited' => $clock['unlimited'],
                ],
                'hand_raised' => $row['hand_raised_at'] !== null,
                'open_requests' => $requests[$id] ?? [],
                'last_save_ms_ago' => $saved !== null ? max(0, $nowMs - $saved) : null,
                'pending' => ((int) ($client['pending_saves'] ?? 0)) > 0,
                'position' => $row['pos_col'] !== null ? ['col' => (int) $row['pos_col'], 'row' => (int) $row['pos_row']] : null,
                'alarm' => isset($alarmIds[$id]),
            ];
        }

        $notJoined = [];
        if ($exam['course_id'] !== null) {
            $missing = $this->db->fetchAll(
                "SELECT u.id, u.firstname, u.lastname, u.username, rp.col AS pos_col, rp.`row` AS pos_row
                 FROM course_members cm
                 JOIN users u ON u.id = cm.user_id AND u.role = 'student' AND u.is_active = 1
                 LEFT JOIN room_positions rp ON rp.room_id = ? AND rp.user_id = u.id
                 WHERE cm.course_id = ?
                   AND NOT EXISTS (SELECT 1 FROM participations px WHERE px.exam_id = ? AND px.user_id = u.id)
                 ORDER BY u.lastname, u.firstname",
                [$roomId, (int) $exam['course_id'], $examId],
            );
            foreach ($missing as $m) {
                $notJoined[] = [
                    'user_id' => (int) $m['id'],
                    'name' => display_name($m),
                    'position' => $m['pos_col'] !== null ? ['col' => (int) $m['pos_col'], 'row' => (int) $m['pos_row']] : null,
                ];
            }
        }

        $room = null;
        if ($roomId !== null) {
            $r = $this->db->fetchOne('SELECT id, name, grid_cols, grid_rows FROM rooms WHERE id = ?', [$roomId]);
            if ($r !== null) {
                $room = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'cols' => (int) $r['grid_cols'], 'rows' => (int) $r['grid_rows']];
            }
        }

        return [
            'success' => true,
            'server_time_ms' => $nowMs,
            'exam' => [
                'id' => $examId,
                'title' => (string) $exam['title'],
                'status' => (string) $exam['status'],
                'mode' => (string) $exam['mode'],
                'started_at_ms' => ExamClock::toMs($exam['started_at']),
                'duration_minutes' => $exam['duration_minutes'] !== null ? (int) $exam['duration_minutes'] : null,
                'paused' => $exam['paused_at'] !== null,
                // Ende der Grundzeit (ohne Nachteilsausgleich und Zusatzzeit); während einer Pause wandert es mit.
                'base_ends_at_ms' => ExamClock::baseEndsAtMs($exam, $nowMs),
                'base_remaining_ms' => ExamClock::baseEndsAtMs($exam, $nowMs) !== null ? max(0, ExamClock::baseEndsAtMs($exam, $nowMs) - $nowMs) : null,
                'settings' => $settings,
                'room' => $room,
            ],
            'participants' => $participants,
            'not_joined' => $notJoined,
            'events' => $events,
            'cursor' => $newCursor,
        ];
    }
}
