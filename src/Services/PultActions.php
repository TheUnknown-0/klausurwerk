<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

/**
 * Aktionen des Pults (docs/API.md 3.2). Die Berechtigung (CAP_MONITOR) prüft der Controller.
 *
 * Klausur-Aktionen ändern exams.status; Personen-Aktionen laufen je Teilnahme, Fehler werden je
 * Person gesammelt. Jede Aktion hinterlässt ein Ereignis mit actor_id (Zustandswechsel über
 * ParticipationState, sonst direkt über EventLog); Statuswechsel der Klausur stehen zusätzlich im Audit-Log.
 */
final class PultActions
{
    public const EXAM_ACTIONS = ['open_lobby', 'start', 'pause_all', 'resume_all', 'finish'];
    public const PERSON_ACTIONS = [
        'release', 'lock', 'set_absent', 'pause', 'resume', 'add_time', 'submit', 'approve_submit',
        'reject_submit', 'reject_release', 'approve_device', 'reject_device', 'ack_hand', 'reopen', 'announce',
    ];
    /** Bei "alle" betroffene Zustände je Aktion (nicht gelistet: alle, die noch nicht abgegeben haben). */
    private const STATES_FOR_ALL = [
        'release' => ['locked', 'absent', 'device_pending'],
        'lock' => ['active'],
        'set_absent' => ['active'],
        'pause' => ['active'],
        'resume' => ['paused'],
        'reject_release' => ['locked', 'absent'],
        'approve_submit' => ['submit_requested'],
        'reject_submit' => ['submit_requested'],
        'approve_device' => ['device_pending'],
        'reject_device' => ['device_pending'],
        'reopen' => [],
    ];
    public const MAX_EXTRA_MINUTES = 600;
    public const MAX_MESSAGE_LENGTH = 500;

    public function __construct(
        private readonly Database $db,
        private readonly EventLog $events,
        private readonly ParticipationState $state,
        private readonly DeviceBinding $devices,
        private readonly ?Audit $audit = null,
    ) {
    }

    /**
     * @param array<string, mixed> $exam Zeile aus exams
     * @param array<string, mixed> $input Anfrage (action, participation_ids, minutes, message, request_id)
     * @return array<string, mixed> success, affected, errors (je Person), bei Fehlern status/error
     * @throws HttpException bei unbekannter Aktion oder unpassendem Klausurstatus
     */
    public function run(array $exam, array $input, int $actorId, ?int $nowMs = null): array
    {
        $nowMs ??= ExamClock::nowMs();
        $action = (string) ($input['action'] ?? '');
        if (!in_array($action, self::EXAM_ACTIONS, true) && !in_array($action, self::PERSON_ACTIONS, true)) {
            throw new HttpException(400, 'Unbekannte Aktion.');
        }

        // Frischer Stand der Klausur (der Controller-Cache kann veraltet sein).
        $exam = $this->db->fetchOne('SELECT * FROM exams WHERE id = ?', [(int) $exam['id']]) ?? throw new HttpException(404, 'Klausur nicht gefunden.');

        if (in_array($action, self::EXAM_ACTIONS, true)) {
            return match ($action) {
                'open_lobby' => $this->openLobby($exam, $actorId, $nowMs),
                'start' => $this->start($exam, $actorId, $nowMs),
                'pause_all' => $this->pauseAll($exam, $actorId, $nowMs),
                'resume_all' => $this->resumeAll($exam, $actorId, $nowMs),
                'finish' => $this->finish($exam, $actorId, $nowMs),
            };
        }

        if (!in_array((string) $exam['status'], ['lobby', 'running', 'paused'], true)) {
            throw new HttpException(409, 'Diese Aktion ist nur möglich, solange die Klausur geöffnet ist.');
        }

        return $this->runPersons($exam, $action, $input, $actorId, $nowMs);
    }

    // ---------- Klausur ----------

    /** @param array<string, mixed> $exam */
    private function openLobby(array $exam, int $actorId, int $nowMs): array
    {
        $this->changeStatus($exam, ['draft', 'ready'], 'lobby', [], $actorId, $nowMs, 'Der Warteraum kann nur aus Entwurf oder Bereit geöffnet werden.');

        return ['success' => true, 'affected' => 1, 'errors' => [], 'exam_status' => 'lobby'];
    }

    /** @param array<string, mixed> $exam */
    private function start(array $exam, int $actorId, int $nowMs): array
    {
        $this->changeStatus(
            $exam,
            ['lobby'],
            'running',
            ['started_at' => ExamClock::toDb($nowMs), 'paused_at' => null, 'paused_seconds' => 0, 'time_up_notified_at' => null],
            $actorId,
            $nowMs,
            'Gestartet werden kann nur aus dem offenen Warteraum.',
        );

        $affected = 0;
        $errors = [];
        foreach ($this->participants((int) $exam['id']) as $p) {
            if ($p['state'] !== 'waiting') {
                continue;
            }
            try {
                $this->state->transition((int) $p['id'], 'active', 'Start der Klausur', $actorId, $nowMs);
                $affected++;
            } catch (\DomainException $e) {
                $errors[] = $this->error($p, $e->getMessage());
            }
        }

        return ['success' => true, 'affected' => $affected, 'errors' => $errors, 'exam_status' => 'running'];
    }

    /** @param array<string, mixed> $exam */
    private function pauseAll(array $exam, int $actorId, int $nowMs): array
    {
        $this->changeStatus($exam, ['running'], 'paused', ['paused_at' => ExamClock::toDb($nowMs)], $actorId, $nowMs, 'Pausieren ist nur während der laufenden Klausur möglich.');
        $this->state->closeIndividualPauses((int) $exam['id'], $nowMs);

        $affected = 0;
        $errors = [];
        foreach ($this->participants((int) $exam['id']) as $p) {
            if ($p['state'] !== 'active') {
                continue;
            }
            try {
                $this->state->transition((int) $p['id'], 'paused', 'Pause für alle', $actorId, $nowMs, ['by_exam_pause' => true]);
                $affected++;
            } catch (\DomainException $e) {
                $errors[] = $this->error($p, $e->getMessage());
            }
        }

        return ['success' => true, 'affected' => $affected, 'errors' => $errors, 'exam_status' => 'paused'];
    }

    /** @param array<string, mixed> $exam */
    private function resumeAll(array $exam, int $actorId, int $nowMs): array
    {
        if ((string) $exam['status'] !== 'paused') {
            throw new HttpException(409, 'Die Klausur ist nicht pausiert.');
        }
        $pausedAt = ExamClock::toMs($exam['paused_at']);
        $add = $pausedAt !== null ? (int) round(max(0, $nowMs - $pausedAt) / 1000) : 0;
        $this->changeStatus(
            $exam,
            ['paused'],
            'running',
            ['paused_at' => null, 'paused_seconds' => (int) $exam['paused_seconds'] + $add],
            $actorId,
            $nowMs,
            'Die Klausur ist nicht pausiert.',
        );
        $toilet = !empty(ExamAccess::settingsOf($exam)['toilet_pauses_clock']);
        $this->state->reopenIndividualPauses((int) $exam['id'], $toilet, $nowMs);

        $affected = 0;
        $errors = [];
        foreach ($this->participants((int) $exam['id']) as $p) {
            $resume = ($p['state'] === 'paused' && $p['lock_reason'] === ParticipationState::PAUSE_ALL_REASON)
                || ($p['state'] === 'waiting' && $p['ready_at'] !== null);
            if (!$resume) {
                continue;
            }
            try {
                $this->state->transition((int) $p['id'], 'active', 'Fortsetzung für alle', $actorId, $nowMs);
                $affected++;
            } catch (\DomainException $e) {
                $errors[] = $this->error($p, $e->getMessage());
            }
        }

        return ['success' => true, 'affected' => $affected, 'errors' => $errors, 'exam_status' => 'running'];
    }

    /** @param array<string, mixed> $exam */
    private function finish(array $exam, int $actorId, int $nowMs): array
    {
        if (!in_array((string) $exam['status'], ['running', 'paused'], true)) {
            throw new HttpException(409, 'Beendet werden kann nur eine laufende oder pausierte Klausur.');
        }
        $open = [];
        foreach ($this->participants((int) $exam['id']) as $p) {
            if ($p['state'] !== 'submitted') {
                $open[] = $this->error($p, 'Noch nicht abgegeben (' . $p['state'] . ').');
            }
        }
        if ($open !== []) {
            return [
                'success' => false,
                'status' => 409,
                'error' => 'Die Klausur kann erst beendet werden, wenn alle abgegeben haben (' . count($open) . ' offen).',
                'affected' => 0,
                'errors' => $open,
            ];
        }

        $set = ['finished_at' => ExamClock::toDb($nowMs)];
        if ((string) $exam['status'] === 'paused') {
            $pausedAt = ExamClock::toMs($exam['paused_at']);
            $set['paused_at'] = null;
            $set['paused_seconds'] = (int) $exam['paused_seconds'] + ($pausedAt !== null ? (int) round(max(0, $nowMs - $pausedAt) / 1000) : 0);
        }
        $this->changeStatus($exam, ['running', 'paused'], 'finished', $set, $actorId, $nowMs, 'Die Klausur läuft nicht mehr.');

        return ['success' => true, 'affected' => count($this->participants((int) $exam['id'])), 'errors' => [], 'exam_status' => 'finished'];
    }

    /**
     * Statuswechsel atomar (UPDATE ... WHERE status IN ...), mit Ereignis und Audit-Log.
     *
     * @param array<string, mixed> $exam
     * @param list<string> $from
     * @param array<string, mixed> $columns zusätzliche Spalten
     */
    private function changeStatus(array $exam, array $from, string $to, array $columns, int $actorId, int $nowMs, string $failMessage): void
    {
        $set = ['status = ?'];
        $params = [$to];
        foreach ($columns as $column => $value) {
            $set[] = $column . ' = ?';
            $params[] = $value;
        }
        $marks = implode(', ', array_fill(0, count($from), '?'));
        $stmt = $this->db->run(
            'UPDATE exams SET ' . implode(', ', $set) . " WHERE id = ? AND status IN ({$marks})",
            [...$params, (int) $exam['id'], ...$from],
        );
        if ($stmt->rowCount() !== 1) {
            throw new HttpException(409, $failMessage);
        }
        $this->events->log((int) $exam['id'], null, 'state_changed', 'info', [
            'scope' => 'exam', 'from' => (string) $exam['status'], 'to' => $to,
        ], $actorId, null, $nowMs);
        $this->audit?->log(
            'exam.status.' . $to,
            'info',
            sprintf('Klausur #%d "%s": %s -> %s', (int) $exam['id'], (string) $exam['title'], (string) $exam['status'], $to),
        );
    }

    // ---------- Personen ----------

    /**
     * @param array<string, mixed> $exam
     * @param array<string, mixed> $input
     */
    private function runPersons(array $exam, string $action, array $input, int $actorId, int $nowMs): array
    {
        $examId = (int) $exam['id'];
        $all = $this->participants($examId);
        $byId = [];
        foreach ($all as $p) {
            $byId[(int) $p['id']] = $p;
        }

        $message = trim((string) ($input['message'] ?? ''));
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            throw new HttpException(400, 'Der Text ist zu lang (höchstens ' . self::MAX_MESSAGE_LENGTH . ' Zeichen).');
        }
        $minutes = (int) ($input['minutes'] ?? 0);

        if ($action === 'announce') {
            if ($message === '') {
                throw new HttpException(400, 'Bitte gib einen Text für die Durchsage ein.');
            }

            return $this->announce($exam, $input['participation_ids'] ?? 'all', $byId, $message, $actorId, $nowMs);
        }
        if ($action === 'add_time' && $minutes === 0) {
            throw new HttpException(400, 'Bitte gib eine Minutenzahl ungleich 0 an.');
        }
        if ($action === 'reopen' && $message === '') {
            throw new HttpException(400, 'Bitte gib eine Begründung für das Zurücknehmen der Abgabe an.');
        }

        $targets = $this->resolveTargets($input['participation_ids'] ?? [], $byId, $action);
        $affected = 0;
        $errors = [];
        foreach ($targets['missing'] as $missingId) {
            $errors[] = ['participation_id' => $missingId, 'error' => 'Teilnahme nicht gefunden.'];
        }
        foreach ($targets['rows'] as $p) {
            try {
                $this->applyToPerson($exam, $action, $p, $message, $minutes, $actorId, $nowMs);
                $affected++;
            } catch (\DomainException | HttpException $e) {
                $errors[] = $this->error($p, $e->getMessage());
            }
        }

        return ['success' => true, 'affected' => $affected, 'errors' => $errors, 'exam_status' => (string) $exam['status']];
    }

    /**
     * @param array<string, mixed> $exam
     * @param array<string, mixed> $p
     */
    private function applyToPerson(array $exam, string $action, array $p, string $message, int $minutes, int $actorId, int $nowMs): void
    {
        $id = (int) $p['id'];
        $examId = (int) $exam['id'];
        $state = (string) $p['state'];
        $examPaused = (string) $exam['status'] === 'paused';

        switch ($action) {
            case 'release':
                if ($state === 'device_pending') {
                    $this->devices->approve($id);
                } elseif (!in_array($state, ['locked', 'absent'], true)) {
                    throw new \DomainException('Diese Person ist weder gesperrt noch abwesend.');
                }
                $this->state->release($id, 'Freigabe durch Lehrkraft', $actorId, $nowMs);
                $this->settleForExamPause($id, $examPaused, $actorId, $nowMs);
                $this->resolveRequests($id, ['release', 'device'], 'approved', $actorId, $nowMs);
                break;

            case 'reject_release':
                if (!in_array($state, ['locked', 'absent'], true)) {
                    throw new \DomainException('Es liegt keine Freigabe-Anfrage vor.');
                }
                $this->resolveRequests($id, ['release'], 'rejected', $actorId, $nowMs);
                $this->events->log($examId, $id, 'request_rejected', 'info', ['type' => 'release'], $actorId, null, $nowMs);
                break;

            case 'lock':
                $this->state->transition($id, 'locked', 'Manuell gesperrt', $actorId, $nowMs, [
                    'lock_reason' => $message !== '' ? $message : 'Von der Lehrkraft gesperrt',
                ]);
                break;

            case 'set_absent':
                $this->state->transition($id, 'absent', 'Abwesend gesetzt', $actorId, $nowMs);
                break;

            case 'pause':
                $this->state->transition($id, 'paused', 'Einzelpause', $actorId, $nowMs);
                break;

            case 'resume':
                if ($state !== 'paused') {
                    throw new \DomainException('Diese Person ist nicht pausiert.');
                }
                if ($examPaused || $p['lock_reason'] === ParticipationState::PAUSE_ALL_REASON) {
                    throw new \DomainException('Die Klausur ist für alle pausiert. Setze sie für alle fort.');
                }
                $this->state->transition($id, 'active', 'Einzelpause beendet', $actorId, $nowMs);
                break;

            case 'add_time':
                $this->addTime($exam, $p, $minutes, $actorId, $nowMs);
                break;

            case 'submit':
                $this->state->transition($id, 'submitted', 'Abgabe durch Lehrkraft', $actorId, $nowMs);
                $this->resolveRequests($id, ['submit', 'release', 'hand'], 'approved', $actorId, $nowMs);
                // Eine offene Geräte-Anfrage ist mit der Abgabe gegenstandslos (sonst bliebe sie ewig offen).
                $this->resolveRequests($id, ['device'], 'rejected', $actorId, $nowMs);
                break;

            case 'approve_submit':
                if ($state !== 'submit_requested') {
                    throw new \DomainException('Es liegt keine Abgabe-Anfrage vor.');
                }
                $this->state->transition($id, 'submitted', 'Abgabe bestätigt', $actorId, $nowMs);
                $this->resolveRequests($id, ['submit', 'hand'], 'approved', $actorId, $nowMs);
                break;

            case 'reject_submit':
                if ($state !== 'submit_requested') {
                    throw new \DomainException('Es liegt keine Abgabe-Anfrage vor.');
                }
                $this->state->transition($id, 'active', 'Abgabe abgelehnt', $actorId, $nowMs);
                $this->settleForExamPause($id, $examPaused, $actorId, $nowMs);
                $this->resolveRequests($id, ['submit'], 'rejected', $actorId, $nowMs);
                break;

            case 'approve_device':
            case 'reject_device':
                if ($state !== 'device_pending') {
                    throw new \DomainException('Es liegt keine Anfrage für einen Gerätewechsel vor.');
                }
                $approve = $action === 'approve_device';
                if ($approve) {
                    $this->devices->approve($id);
                } else {
                    $this->devices->reject($id);
                }
                $this->state->release($id, $approve ? 'Gerätewechsel freigegeben' : 'Gerätewechsel abgelehnt', $actorId, $nowMs);
                $this->settleForExamPause($id, $examPaused, $actorId, $nowMs);
                $this->resolveRequests($id, ['device'], $approve ? 'approved' : 'rejected', $actorId, $nowMs);
                break;

            case 'ack_hand':
                $this->db->run('UPDATE participations SET hand_raised_at = NULL WHERE id = ?', [$id]);
                $this->resolveRequests($id, ['hand'], 'approved', $actorId, $nowMs);
                $this->events->log($examId, $id, 'hand_lowered', 'info', ['by' => 'teacher'], $actorId, null, $nowMs);
                break;

            case 'reopen':
                if ($state !== 'submitted') {
                    throw new \DomainException('Diese Person hat nicht abgegeben.');
                }
                if ($examPaused) {
                    throw new \DomainException('Die Klausur ist pausiert. Setze sie zuerst fort.');
                }
                $this->state->transition($id, 'active', 'Abgabe zurückgenommen: ' . $message, $actorId, $nowMs);
                break;

            default:
                throw new \DomainException('Unbekannte Aktion.');
        }
    }

    /** Kehrt jemand während der Gesamtpause in active zurück, gehört er sofort wieder zur Pause. */
    private function settleForExamPause(int $id, bool $examPaused, int $actorId, int $nowMs): void
    {
        if (!$examPaused) {
            return;
        }
        $state = $this->db->fetchValue('SELECT state FROM participations WHERE id = ?', [$id]);
        if ($state === 'active') {
            $this->state->transition($id, 'paused', 'Pause für alle', $actorId, $nowMs, ['by_exam_pause' => true]);
        }
    }
    /**
     * @param array<string, mixed> $exam
     * @param array<string, mixed> $p
     */
    private function addTime(array $exam, array $p, int $minutes, int $actorId, int $nowMs): void
    {
        $id = (int) $p['id'];
        if ((string) $p['state'] === 'submitted') {
            throw new \DomainException('Diese Person hat bereits abgegeben.');
        }
        $minutes = max(-self::MAX_EXTRA_MINUTES, min(self::MAX_EXTRA_MINUTES, $minutes));
        $old = (int) $p['extra_minutes'];
        $new = max(0, min(self::MAX_EXTRA_MINUTES * 2, $old + $minutes));
        if ($new === $old) {
            throw new \DomainException('Es gibt keine Zusatzzeit, die zurückgenommen werden könnte.');
        }
        $this->db->run('UPDATE participations SET extra_minutes = ? WHERE id = ?', [$new, $id]);

        // Neue Restzeit: das Zeitende-Signal darf wieder auslösen.
        $p['extra_minutes'] = $new;
        $clock = ExamClock::forParticipation($exam, $p, $nowMs);
        if (!$clock['time_up']) {
            $this->db->run('UPDATE participations SET time_up_notified_at = NULL WHERE id = ?', [$id]);
            $this->db->run('UPDATE exams SET time_up_notified_at = NULL WHERE id = ?', [(int) $exam['id']]);
        }
        $this->events->log((int) $exam['id'], $id, 'time_added', 'info', ['minutes' => $new - $old, 'extra_minutes' => $new], $actorId, null, $nowMs);
    }

    /**
     * @param array<string, mixed> $exam
     * @param array<int, array<string, mixed>> $byId
     */
    private function announce(array $exam, mixed $ids, array $byId, string $message, int $actorId, int $nowMs): array
    {
        $examId = (int) $exam['id'];
        $now = ExamClock::toDb($nowMs);
        $insert = 'INSERT INTO exam_announcements (exam_id, participation_id, message, created_by, created_at) VALUES (?, ?, ?, ?, ?)';

        if ($ids === 'all') {
            $this->db->run($insert, [$examId, null, $message, $actorId, $now]);
            $this->events->log($examId, null, 'announcement', 'info', ['message' => mb_substr($message, 0, 200), 'target' => 'all'], $actorId, null, $nowMs);

            return ['success' => true, 'affected' => count($byId), 'errors' => [], 'exam_status' => (string) $exam['status']];
        }

        $targets = $this->resolveTargets($ids, $byId, 'announce');
        $errors = [];
        foreach ($targets['missing'] as $missingId) {
            $errors[] = ['participation_id' => $missingId, 'error' => 'Teilnahme nicht gefunden.'];
        }
        foreach ($targets['rows'] as $p) {
            $this->db->run($insert, [$examId, (int) $p['id'], $message, $actorId, $now]);
            $this->events->log($examId, (int) $p['id'], 'announcement', 'info', ['message' => mb_substr($message, 0, 200), 'target' => 'person'], $actorId, null, $nowMs);
        }

        return ['success' => true, 'affected' => count($targets['rows']), 'errors' => $errors, 'exam_status' => (string) $exam['status']];
    }

    /**
     * Zielpersonen: "all" bedeutet alle, die noch nicht abgegeben haben (bei Abgabe-Rücknahme niemand ohne ID).
     *
     * @param array<int, array<string, mixed>> $byId
     * @return array{rows: list<array<string, mixed>>, missing: list<int>}
     */
    private function resolveTargets(mixed $ids, array $byId, string $action): array
    {
        if ($ids === 'all') {
            $states = self::STATES_FOR_ALL[$action] ?? null;
            $rows = array_values(array_filter(
                $byId,
                static fn (array $p): bool => $states === null ? $p['state'] !== 'submitted' : in_array($p['state'], $states, true),
            ));
            if ($action === 'ack_hand') {
                $rows = array_values(array_filter($rows, static fn (array $p): bool => $p['hand_raised_at'] !== null));
            }

            return ['rows' => $rows, 'missing' => []];
        }
        if (!is_array($ids) || $ids === []) {
            throw new HttpException(400, 'Bitte wähle mindestens eine Person aus.');
        }
        $rows = [];
        $missing = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if (isset($byId[$id])) {
                $rows[] = $byId[$id];
            } else {
                $missing[] = $id;
            }
        }

        return ['rows' => $rows, 'missing' => $missing];
    }

    /** Offene Anfragen der Teilnahme abschließen. @param list<string> $types */
    private function resolveRequests(int $participationId, array $types, string $status, int $actorId, int $nowMs): void
    {
        $marks = implode(', ', array_fill(0, count($types), '?'));
        $this->db->run(
            "UPDATE exam_requests SET status = ?, resolved_at = ?, resolved_by = ?
             WHERE participation_id = ? AND status = 'open' AND type IN ({$marks})",
            [$status, ExamClock::toDb($nowMs), $actorId, $participationId, ...$types],
        );
    }

    /** @return list<array<string, mixed>> */
    private function participants(int $examId): array
    {
        return $this->db->fetchAll(
            'SELECT p.*, u.firstname, u.lastname, u.username
             FROM participations p JOIN users u ON u.id = p.user_id
             WHERE p.exam_id = ? ORDER BY u.lastname, u.firstname, p.id',
            [$examId],
        );
    }

    /**
     * @param array<string, mixed> $p
     * @return array{participation_id: int, name: string, error: string}
     */
    private function error(array $p, string $message): array
    {
        return ['participation_id' => (int) $p['id'], 'name' => display_name($p), 'error' => $message];
    }
}
