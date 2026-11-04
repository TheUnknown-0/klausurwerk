<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Ereignisse und Zustandswechsel (participation_events), siehe docs/DATENFORMATE.md Abschnitt 4.
 * Schreibt immer mit Serverzeit; der Pult-Cursor ist die Ereignis-ID.
 */
final class EventLog
{
    /** Ereignisse, die der Browser melden darf (Typ => Standard-Schweregrad). */
    public const CLIENT_TYPES = [
        'fullscreen_exit' => 'alarm',
        'visibility_hidden' => 'alarm',
        'window_blur' => 'alarm',
        'second_tab' => 'alarm',
        'paste_blocked' => 'warning',
        'paste_external' => 'warning',
        'drop_blocked' => 'warning',
        'devtools_suspected' => 'warning',
        'resize' => 'info',
        'reload' => 'warning',
    ];

    /** Verstöße, die bei lock_on_violation sperren (Typ => Grund für die Sperrmeldung). */
    public const VIOLATIONS = [
        'fullscreen_exit' => 'Vollbild verlassen',
        'visibility_hidden' => 'Fenster oder Tab gewechselt',
        'window_blur' => 'Fokus verloren',
        'second_tab' => 'Klausur in zweitem Tab geöffnet',
    ];

    public const SEVERITIES = ['info', 'warning', 'alarm'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Schweregrad eines Client-Ereignisses. window_blur zählt erst ab der
     * Schwelle (violation_grace_ms) als Alarm; im Modus practice gibt es keine Alarme.
     *
     * @param array<string, mixed> $details
     * @param array<string, mixed> $settings
     */
    public static function severityFor(string $type, array $details, array $settings, string $mode): string
    {
        $severity = self::CLIENT_TYPES[$type] ?? 'info';
        if ($type === 'window_blur') {
            $severity = self::belowGrace($details, $settings) ? 'info' : 'alarm';
        }
        if ($mode === 'practice' && $severity === 'alarm') {
            return 'info';
        }

        return $severity;
    }

    /**
     * Ist das Ereignis ein Verstoß, der bei lock_on_violation sperrt?
     *
     * @param array<string, mixed> $details
     * @param array<string, mixed> $settings
     */
    public static function isLockingViolation(string $type, array $details, array $settings): bool
    {
        if (!isset(self::VIOLATIONS[$type])) {
            return false;
        }
        if ($type === 'window_blur') {
            return !self::belowGrace($details, $settings);
        }

        return true;
    }

    /** Fokusverlust kürzer als die Schwelle? Ohne Dauerangabe zählt er als Verstoß. */
    private static function belowGrace(array $details, array $settings): bool
    {
        $grace = max(0, (int) ($settings['violation_grace_ms'] ?? 300));
        $duration = $details['duration_ms'] ?? null;

        return is_numeric($duration) && (int) $duration < $grace;
    }

    /**
     * Schreibt ein Ereignis und gibt dessen ID zurück.
     *
     * @param array<string, mixed> $details
     */
    public function log(
        int $examId,
        ?int $participationId,
        string $type,
        string $severity = 'info',
        array $details = [],
        ?int $actorId = null,
        ?int $clientTs = null,
        ?int $nowMs = null,
    ): int {
        if (!in_array($severity, self::SEVERITIES, true)) {
            $severity = 'info';
        }
        $this->db->run(
            'INSERT INTO participation_events (exam_id, participation_id, type, severity, details, actor_id, client_ts, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $examId,
                $participationId,
                mb_substr($type, 0, 40),
                $severity,
                $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $actorId,
                $clientTs !== null && $clientTs >= 0 ? $clientTs : null,
                ExamClock::toDb($nowMs ?? ExamClock::nowMs()),
            ],
        );

        return $this->db->lastInsertId();
    }

    /** Höchste Ereignis-ID der Klausur (0 ohne Ereignisse). */
    public function lastId(int $examId): int
    {
        return (int) $this->db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM participation_events WHERE exam_id = ?', [$examId]);
    }

    /**
     * Ereignisse für das Pult seit dem Cursor. Beim ersten Abruf (Cursor 0) nur
     * die letzten $initial Ereignisse, danach alle neuen (höchstens $limit).
     *
     * @return list<array<string, mixed>>
     */
    public function since(int $examId, int $cursor, int $limit = 300, int $initial = 100): array
    {
        if ($cursor <= 0) {
            $rows = $this->db->fetchAll(
                self::select() . ' WHERE e.exam_id = ? ORDER BY e.id DESC LIMIT ' . max(1, $initial),
                [$examId],
            );
            $rows = array_reverse($rows);
        } else {
            $rows = $this->db->fetchAll(
                self::select() . ' WHERE e.exam_id = ? AND e.id > ? ORDER BY e.id ASC LIMIT ' . max(1, $limit),
                [$examId, $cursor],
            );
        }

        return array_map(self::format(...), $rows);
    }

    /**
     * Vollständiges Protokoll einer Person (älteste zuerst).
     *
     * @return list<array<string, mixed>>
     */
    public function forParticipation(int $examId, int $participationId, int $limit = 2000): array
    {
        $rows = $this->db->fetchAll(
            self::select() . ' WHERE e.exam_id = ? AND e.participation_id = ? ORDER BY e.id ASC LIMIT ' . max(1, $limit),
            [$examId, $participationId],
        );

        return array_map(self::format(...), $rows);
    }

    private static function select(): string
    {
        return 'SELECT e.id, e.participation_id, e.type, e.severity, e.details, e.actor_id, e.client_ts, e.created_at,
                       u.firstname AS p_first, u.lastname AS p_last, u.username AS p_user,
                       a.firstname AS a_first, a.lastname AS a_last, a.username AS a_user
                FROM participation_events e
                LEFT JOIN participations p ON p.id = e.participation_id
                LEFT JOIN users u ON u.id = p.user_id
                LEFT JOIN users a ON a.id = e.actor_id';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function format(array $row): array
    {
        $details = $row['details'] !== null ? json_decode((string) $row['details'], true) : [];
        $name = $row['participation_id'] !== null
            ? display_name(['firstname' => $row['p_first'], 'lastname' => $row['p_last'], 'username' => $row['p_user']])
            : null;
        $actor = $row['actor_id'] !== null
            ? display_name(['firstname' => $row['a_first'], 'lastname' => $row['a_last'], 'username' => $row['a_user']])
            : null;

        return [
            'id' => (int) $row['id'],
            'participation_id' => $row['participation_id'] !== null ? (int) $row['participation_id'] : null,
            'name' => $name,
            'type' => (string) $row['type'],
            'severity' => (string) $row['severity'],
            'details' => is_array($details) ? $details : [],
            'created_at_ms' => ExamClock::toMs($row['created_at']),
            'actor' => $actor,
        ];
    }
}
