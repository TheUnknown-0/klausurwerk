<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Einzige Stelle für Zustandsübergänge einer Teilnahme (ARCHITECTURE.md, "Zustände einer Teilnahme").
 *
 * Jeder Übergang
 *  - prüft die Übergangstabelle (ungültig: \DomainException),
 *  - schreibt state_changed_at, resume_state und lock_reason,
 *  - bucht Zeitpausen (participations.paused_at / paused_seconds),
 *  - schreibt das Ereignis state_changed (mit auslösender Person und Grund).
 *
 * Eine Einzelpause läuft in 'paused' sowie in 'absent', wenn die Klausur
 * toilet_pauses_clock gesetzt hat. Während einer Gesamtpause (exams.paused_at)
 * läuft keine Einzelpause, damit sich Pausen nie doppelt zählen.
 */
final class ParticipationState
{
    public const STATES = ['waiting', 'active', 'locked', 'absent', 'paused', 'submit_requested', 'submitted', 'device_pending'];

    /** Erlaubte Übergänge: von => [nach, ...]. */
    public const TRANSITIONS = [
        'waiting' => ['active', 'device_pending', 'submitted'],
        'active' => ['locked', 'absent', 'paused', 'submit_requested', 'submitted', 'device_pending'],
        'locked' => ['active', 'submitted', 'device_pending'],
        'absent' => ['active', 'submitted', 'device_pending'],
        'paused' => ['active', 'submitted', 'device_pending'],
        'submit_requested' => ['active', 'submitted', 'device_pending'],
        'submitted' => ['active'],
        'device_pending' => ['waiting', 'active', 'locked', 'absent', 'paused', 'submit_requested', 'submitted'],
    ];

    /** Sperrgrund, der Teilnahmen kennzeichnet, die durch "Alle pausieren" pausiert wurden. */
    public const PAUSE_ALL_REASON = 'Pause für alle';

    /** Zustände, aus denen heraus ein Gerätewechsel möglich ist (alle außer submitted). */
    public const DEVICE_CHANGE_FROM = ['waiting', 'active', 'locked', 'absent', 'paused', 'submit_requested'];

    public function __construct(
        private readonly Database $db,
        private readonly EventLog $events,
    ) {
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @return list<string> */
    public static function allowedFrom(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    /** Zustände mit laufender Einzelpause (Uhr der Person steht). */
    public static function stopsClock(string $state, bool $toiletPausesClock): bool
    {
        return $state === 'paused' || ($state === 'absent' && $toiletPausesClock);
    }

    /**
     * Führt einen Übergang aus.
     *
     * Optionen: lock_reason (Text für locked), by_exam_pause (Pause für alle: keine Einzelpause buchen).
     *
     * @param array<string, mixed>|int $participation Zeile oder ID (wird gesperrt neu geladen)
     * @param array{lock_reason?: string, by_exam_pause?: bool} $options
     * @return array<string, mixed> die aktualisierte Teilnahme
     * @throws \DomainException bei ungültigem Übergang
     */
    public function transition(array|int $participation, string $to, string $reason = '', ?int $actorId = null, ?int $nowMs = null, array $options = []): array
    {
        $id = is_array($participation) ? (int) $participation['id'] : $participation;
        $nowMs ??= ExamClock::nowMs();

        return $this->db->transaction(function () use ($id, $to, $reason, $actorId, $nowMs, $options): array {
            $row = $this->db->fetchOne('SELECT * FROM participations WHERE id = ? FOR UPDATE', [$id]);
            if ($row === null) {
                throw new \DomainException('Teilnahme nicht gefunden.');
            }
            $from = (string) $row['state'];
            if (!in_array($to, self::STATES, true) || !self::canTransition($from, $to)) {
                throw new \DomainException(sprintf('Übergang von "%s" nach "%s" ist nicht erlaubt.', $from, $to));
            }

            $exam = $this->db->fetchOne('SELECT id, paused_at, settings FROM exams WHERE id = ?', [(int) $row['exam_id']]);
            $settings = ExamAccess::settingsOf($exam ?? []);
            $toilet = !empty($settings['toilet_pauses_clock']);
            $examPaused = $exam !== null && $exam['paused_at'] !== null;
            $nowDb = ExamClock::toDb($nowMs);
            // Eine Pause während der Gesamtpause gehört zur Gesamtpause (Fortsetzen für alle löst sie).
            $byExamPause = !empty($options['by_exam_pause']) || ($to === 'paused' && $examPaused);

            // Einzelpause beenden und/oder starten.
            $pausedAt = ExamClock::toMs($row['paused_at']);
            $pausedSeconds = (int) $row['paused_seconds'];
            // Wartet ein Gerätewechsel, läuft die Einzelpause der Person weiter (Wartezeit zählt nicht
            // von der Restzeit ab), wenn der Rückkehrzustand die Uhr anhält.
            $fromStops = self::stopsClock($from, $toilet)
                || ($from === 'device_pending' && self::stopsClock((string) $row['resume_state'], $toilet));
            $toStops = self::stopsClock($to, $toilet)
                || ($to === 'device_pending' && self::stopsClock($from, $toilet));
            if ($pausedAt !== null && $fromStops && $toStops) {
                // Pause läuft über den Übergang hinweg ununterbrochen weiter.
            } elseif ($pausedAt !== null && $fromStops) {
                $pausedSeconds += (int) round(max(0, $nowMs - $pausedAt) / 1000);
                $pausedAt = null;
            } elseif ($pausedAt !== null && !$toStops) {
                // Altlast (z. B. geänderte Einstellung): offene Pause nie stehen lassen.
                $pausedSeconds += (int) round(max(0, $nowMs - $pausedAt) / 1000);
                $pausedAt = null;
            }
            if ($pausedAt === null && $toStops && !$examPaused && !$byExamPause) {
                $pausedAt = $nowMs;
            }

            // Rückkehrzustand: gemerkt, wenn eine Unterbrechung beginnt; gelöscht, wenn sie endet.
            $resume = $row['resume_state'];
            if (in_array($to, ['locked', 'absent', 'paused', 'device_pending'], true)) {
                $resume = $from === 'device_pending' ? $resume : $from;
            } else {
                $resume = null;
            }

            $lockReason = match ($to) {
                'locked' => mb_substr((string) ($options['lock_reason'] ?? ($reason !== '' ? $reason : 'Gesperrt')), 0, 255),
                'absent' => 'Abwesend',
                'paused' => $byExamPause ? self::PAUSE_ALL_REASON : 'Pause',
                'device_pending' => 'Gerätewechsel angefragt',
                default => null,
            };

            $set = [
                'state = ?' => $to,
                'state_changed_at = ?' => $nowDb,
                'resume_state = ?' => $resume,
                'lock_reason = ?' => $lockReason,
                'paused_at = ?' => $pausedAt !== null ? ExamClock::toDb($pausedAt) : null,
                'paused_seconds = ?' => $pausedSeconds,
            ];
            if ($to === 'submit_requested') {
                $set['submit_requested_at = ?'] = $nowDb;
            } elseif ($from === 'submit_requested' || $to === 'active') {
                $set['submit_requested_at = ?'] = null;
            }
            if ($to === 'submitted') {
                $set['submitted_at = ?'] = $nowDb;
                $set['submitted_by = ?'] = $actorId;
                $set['hand_raised_at = ?'] = null;
            } elseif ($from === 'submitted') {
                $set['submitted_at = ?'] = null;
                $set['submitted_by = ?'] = null;
            }
            if ($from === 'device_pending' && $to !== 'device_pending') {
                $set['pending_device_hash = ?'] = null;
            }

            $this->db->run(
                'UPDATE participations SET ' . implode(', ', array_keys($set)) . ' WHERE id = ?',
                [...array_values($set), $id],
            );

            $details = ['from' => $from, 'to' => $to, 'reason' => $reason];
            if ($lockReason !== null && $to === 'locked') {
                $details['lock_reason'] = $lockReason;
            }
            $this->events->log((int) $row['exam_id'], $id, 'state_changed', 'info', $details, $actorId, null, $nowMs);

            return $this->db->fetchOne('SELECT * FROM participations WHERE id = ?', [$id]) ?? $row;
        });
    }

    /**
     * Rückkehr aus locked/absent/device_pending in den vorherigen Zustand (meist active).
     *
     * @param array<string, mixed>|int $participation
     * @return array<string, mixed>
     */
    public function release(array|int $participation, string $reason = 'Freigabe', ?int $actorId = null, ?int $nowMs = null): array
    {
        $id = is_array($participation) ? (int) $participation['id'] : $participation;
        $row = $this->db->fetchOne('SELECT state, resume_state FROM participations WHERE id = ?', [$id]);
        if ($row === null) {
            throw new \DomainException('Teilnahme nicht gefunden.');
        }
        $target = 'active';
        if ($row['state'] === 'device_pending') {
            $target = (string) ($row['resume_state'] ?: 'active');
            if (!self::canTransition('device_pending', $target)) {
                $target = 'active';
            }
        }

        $options = [];
        if ($row['state'] === 'device_pending' && $target === 'locked') {
            // Der ursprüngliche Sperrgrund bleibt erhalten (nicht "Gerätewechsel freigegeben").
            $original = $this->lastLockReason($id);
            if ($original !== null) {
                $options['lock_reason'] = $original;
            }
        }

        return $this->transition($id, $target, $reason, $actorId, $nowMs, $options);
    }

    /** Sperrgrund aus dem letzten Übergang nach "locked" (aus dem Ereignisprotokoll). */
    private function lastLockReason(int $id): ?string
    {
        $rows = $this->db->fetchAll(
            "SELECT details FROM participation_events WHERE participation_id = ? AND type = 'state_changed' ORDER BY id DESC LIMIT 20",
            [$id],
        );
        foreach ($rows as $r) {
            $d = json_decode((string) $r['details'], true);
            if (is_array($d) && ($d['to'] ?? null) === 'locked' && is_string($d['lock_reason'] ?? null) && $d['lock_reason'] !== '') {
                return $d['lock_reason'];
            }
        }

        return null;
    }

    /**
     * Gesamtpause beginnt: offene Einzelpausen werden abgeschlossen, damit nichts doppelt zählt.
     * Aufruf durch PultActions, nachdem exams.paused_at gesetzt wurde.
     */
    public function closeIndividualPauses(int $examId, int $nowMs): void
    {
        $rows = $this->db->fetchAll(
            'SELECT id, paused_at FROM participations WHERE exam_id = ? AND paused_at IS NOT NULL',
            [$examId],
        );
        foreach ($rows as $row) {
            $start = ExamClock::toMs($row['paused_at']);
            $add = $start !== null ? (int) round(max(0, $nowMs - $start) / 1000) : 0;
            $this->db->run(
                'UPDATE participations SET paused_seconds = paused_seconds + ?, paused_at = NULL WHERE id = ?',
                [$add, (int) $row['id']],
            );
        }
    }

    /** Gesamtpause endet: Einzelpausen laufen für wartende Pausierte/Abwesende wieder an. */
    public function reopenIndividualPauses(int $examId, bool $toiletPausesClock, int $nowMs): void
    {
        $states = $toiletPausesClock ? ['paused', 'absent'] : ['paused'];
        $marks = implode(', ', array_fill(0, count($states), '?'));
        $this->db->run(
            "UPDATE participations SET paused_at = ? WHERE exam_id = ? AND paused_at IS NULL AND state IN ({$marks})
             AND NOT (state = 'paused' AND lock_reason = ?)",
            [ExamClock::toDb($nowMs), $examId, ...$states, self::PAUSE_ALL_REASON],
        );
    }
}
