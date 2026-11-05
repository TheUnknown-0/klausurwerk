<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\AnswerStore;
use App\Services\DeviceBinding;
use App\Services\EventLog;
use App\Services\ExamAccess;
use App\Services\ExamClock;
use App\Services\ExamContent;
use App\Services\ExamSettings;
use App\Services\LiveServices;
use App\Services\LiveStore;
use App\Services\SebGuard;
use App\Services\Uploads;

/**
 * Schüleransicht: Klausurliste, Klausurseite und alle Schüler-APIs (docs/API.md Abschnitt 1).
 *
 * Jede Aktion prüft Rolle (nur Schüler:innen), Kursmitgliedschaft, SEB und, wo nötig, Teilnahme und
 * Gerätebindung. Hochfrequente APIs geben die Sitzungssperre nach den Guards frei.
 */
final class StudentExamController extends Controller
{
    /** Höchstzahl Ereignisse je Anfrage. */
    private const MAX_EVENTS = 50;
    /** Ab so vielen Client-Ereignissen pro Minute werden weitere verworfen (Flutschutz). */
    private const MAX_EVENTS_PER_MINUTE = 300;
    private const MAX_UPLOADS_PER_TASK = 10;
    /** Live-Wand: Grenzen für Code-Ausschnitt, Konsole und Standbild. */
    private const LIVE_CODE_LINES = 60;
    private const LIVE_CODE_CHARS = 4000;
    private const LIVE_CONSOLE_LINES = 20;
    private const LIVE_CONSOLE_CHARS = 2000;
    private const SCREEN_MAX_BYTES = 600 * 1024;
    private const SCREEN_SURFACES = ['monitor', 'window', 'browser', 'unknown'];

    private ?LiveServices $services = null;

    private function svc(): LiveServices
    {
        return $this->services ??= new LiveServices($this->ctx);
    }

    // ---------- Seiten ----------

    /** GET /meine-klausuren */
    public function index(array $params): string
    {
        $user = $this->requireStudent();

        $rows = $this->ctx->db->fetchAll(
            "SELECT e.id, e.title, e.mode, e.status, e.duration_minutes, e.scheduled_at, e.returned_at,
                    c.name AS course_name, p.id AS participation_id, p.state AS participation_state
             FROM exams e
             JOIN course_members cm ON cm.course_id = e.course_id AND cm.user_id = ?
             LEFT JOIN courses c ON c.id = e.course_id
             LEFT JOIN participations p ON p.exam_id = e.id AND p.user_id = ?
             WHERE e.status NOT IN ('draft', 'ready', 'archived')
             ORDER BY e.created_at DESC, e.id DESC",
            [(int) $user['id'], (int) $user['id']],
        );

        $open = [];
        $practice = [];
        $finished = [];
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $isPractice = (string) $row['mode'] === 'practice';
            if ($isPractice && in_array($status, ['lobby', 'running'], true)) {
                $practice[] = $row;
            } elseif (!$isPractice && in_array($status, ['lobby', 'running', 'paused'], true)) {
                $open[] = $row;
            } elseif (in_array($status, ['finished', 'grading', 'returned'], true) && $row['participation_id'] !== null) {
                $finished[] = $row;
            }
        }

        return $this->render('pages/student/my-exams', [
            'title' => 'Meine Klausuren',
            'open' => $open,
            'practice' => $practice,
            'finished' => $finished,
        ]);
    }

    /** GET /klausur/{id} */
    public function show(array $params): string
    {
        $user = $this->requireStudent();
        $examId = (int) $params['id'];
        $exam = $this->loadExam($examId);
        $this->requireMembership($exam, $user);

        $participation = $this->findParticipation($examId, (int) $user['id']);
        $this->svc()->seb()->enforce($exam, $participation);

        $status = (string) $exam['status'];
        if (!in_array($status, ['lobby', 'running', 'paused'], true)) {
            return $this->render('pages/student/closed', [
                'title' => (string) $exam['title'],
                'exam' => $exam,
                'status' => $status,
                'hasParticipation' => $participation !== null,
            ]);
        }

        $settings = ExamAccess::settingsOf($exam);
        $needsCode = !empty($settings['require_access_code']) && (string) ($exam['access_code'] ?? '') !== ''
            && ($participation === null || DeviceBinding::check($participation, DeviceBinding::tokenFromRequest($examId)) !== DeviceBinding::OK);

        $boot = ExamContent::boot(
            $exam,
            $user,
            (string) $this->ctx->config['app']['base_url'],
            false,
            $needsCode,
            SebGuard::headerPresent(),
            $this->ctx->settings->getInt('heartbeat_ms', ExamContent::HEARTBEAT_DEFAULT_MS),
        );

        return $this->render('pages/student/exam', [
            'title' => (string) $exam['title'],
            'boot' => $boot,
            'bundles' => ['exam.js'],
            'pageStyles' => ['exam.css', 'code.css', 'diagrams.css', 'robo.css'],
        ], 'exam');
    }

    // ---------- APIs ----------

    /** POST /api/klausur/{id}/beitreten */
    public function join(array $params): array
    {
        $user = $this->requireStudent();
        $this->requireCsrf();
        $examId = (int) $params['id'];
        $exam = $this->loadExam($examId);
        $this->requireMembership($exam, $user);
        $participation = $this->findParticipation($examId, (int) $user['id']);
        $this->svc()->seb()->enforce($exam, $participation);

        $status = (string) $exam['status'];
        if (!in_array($status, ['lobby', 'running', 'paused'], true)) {
            return $this->jsonError($status === 'draft' || $status === 'ready'
                ? 'Die Klausur ist noch nicht geöffnet.'
                : 'Die Klausur ist beendet.', 409);
        }

        $input = $this->jsonInput();
        $settings = ExamAccess::settingsOf($exam);
        $nowMs = ExamClock::nowMs();
        $userId = (int) $user['id'];
        $token = DeviceBinding::tokenFromRequest($examId);
        $devices = $this->svc()->devices();
        $db = $this->ctx->db;
        $client = $this->cleanClient($input['client'] ?? null);

        $needsCode = !empty($settings['require_access_code']) && (string) ($exam['access_code'] ?? '') !== '';
        $checkCode = function () use ($input, $exam, $userId, $nowMs): void {
            $this->svc()->limiter()->hit('access_code', $userId, 10, 60000, $nowMs);
            $given = strtoupper(trim((string) ($input['access_code'] ?? '')));
            if ($given === '' || !hash_equals(strtoupper((string) $exam['access_code']), $given)) {
                throw new HttpException(403, 'Der Klausurcode stimmt nicht.');
            }
        };

        if ($participation === null) {
            if ($needsCode) {
                $checkCode();
            }
            $token ??= DeviceBinding::newToken();
            $db->transaction(function () use ($db, $examId, $userId, $user, $token, $nowMs, $client): void {
                $nowDb = ExamClock::toDb($nowMs);
                $existing = $db->fetchOne('SELECT id FROM participations WHERE exam_id = ? AND user_id = ? FOR UPDATE', [$examId, $userId]);
                if ($existing !== null) {
                    return;
                }
                $db->run(
                    "INSERT INTO participations (exam_id, user_id, state, state_changed_at, device_hash, joined_at, last_seen_at,
                            accommodation_percent, shuffle_seed, client_state)
                     VALUES (?, ?, 'waiting', ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $examId, $userId, $nowDb, DeviceBinding::hash($token), $nowDb, $nowDb,
                        (int) ($user['accommodation_percent'] ?? 0), random_int(1, 2147483646),
                        json_encode($client, JSON_UNESCAPED_UNICODE),
                    ],
                );
                $this->svc()->events()->log($examId, $db->lastInsertId(), 'joined', 'info', [], null, null, $nowMs);
            });
            $devices->sendCookie($examId, $token);
            $participation = $this->findParticipation($examId, $userId);
        } else {
            $pid = (int) $participation['id'];
            $state = (string) $participation['state'];
            $check = DeviceBinding::check($participation, $token);

            if ($check === DeviceBinding::OK) {
                if ($token === null) {
                    // Noch kein Gerät gebunden: dieses Gerät bindet sich jetzt.
                    $token = DeviceBinding::newToken();
                    $devices->bind($pid, $token);
                    $devices->sendCookie($examId, $token);
                }
            } elseif ($check === DeviceBinding::FOREIGN && $state !== 'submitted') {
                if ($needsCode) {
                    $checkCode();
                }
                // Dieses Gerät bekommt ein eigenes neues Token (das Cookie fehlt oder gehört zu einem anderen Gerät).
                $newToken = DeviceBinding::newToken();
                if ($state === 'waiting') {
                    $devices->bind($pid, $newToken);
                } else {
                    $devices->requestChange($examId, $pid, $newToken, $nowMs);
                    if ($state !== 'device_pending') {
                        $this->svc()->states()->transition($pid, 'device_pending', 'Anmeldung an anderem Gerät', null, $nowMs);
                        $this->svc()->events()->log($examId, $pid, 'device_change_requested', 'warning', ['ua' => $client['ua'] ?? ''], null, null, $nowMs);
                    }
                }
                $devices->sendCookie($examId, $newToken);
            }

            $db->run(
                'UPDATE participations SET last_seen_at = ?, client_state = ? WHERE id = ?',
                [ExamClock::toDb($nowMs), json_encode($client, JSON_UNESCAPED_UNICODE), $pid],
            );
            $participation = $this->findParticipation($examId, $userId);
        }

        $this->ctx->session->release();

        return [
            'success' => true,
            'participation' => ['id' => (int) $participation['id'], 'state' => (string) $participation['state']],
            'server_time_ms' => $nowMs,
        ];
    }

    /** POST /api/klausur/{id}/bereit */
    public function ready(array $params): array
    {
        $user = $this->requireStudent();
        $this->requireCsrf();
        [$exam, $p] = $this->participantContext($params, $user, true);
        $this->ctx->session->release();

        $nowMs = ExamClock::nowMs();
        $pid = (int) $p['id'];
        if ($p['ready_at'] === null) {
            // Ergebnis des Technik-Checks (Probe) bzw. Client-Angaben, bereinigt.
            $details = $this->cleanDetails($this->jsonInput()['details'] ?? null);
            $this->ctx->db->run('UPDATE participations SET ready_at = ? WHERE id = ?', [ExamClock::toDb($nowMs), $pid]);
            $this->svc()->events()->log((int) $exam['id'], $pid, 'ready', 'info', $details, null, null, $nowMs);
        }
        $state = (string) $p['state'];
        // Wer nach dem Start dazukommt, wird mit "Bereit" aktiv (die Uhr läuft seit dem Start).
        if ($state === 'waiting' && (string) $exam['status'] === 'running') {
            try {
                $state = (string) $this->svc()->states()->transition($pid, 'active', 'Bereit nach dem Start', null, $nowMs)['state'];
            } catch (\DomainException) {
                // Zustand hat sich parallel geändert: aktuellen Stand melden.
                $state = (string) $this->ctx->db->fetchValue('SELECT state FROM participations WHERE id = ?', [$pid]);
            }
        }

        return ['success' => true, 'state' => $state];
    }

    /** GET /api/klausur/{id}/inhalt (Schüler:innen ab Start, Lehrkräfte mit CAP_VIEW als Vorschau) */
    public function content(array $params): array
    {
        $user = $this->requireLogin();
        $examId = (int) $params['id'];

        if ($this->ctx->auth->isStaff()) {
            $exam = $this->ctx->exams->require($examId, ExamAccess::CAP_VIEW);
            $this->ctx->session->release();

            return $this->svc()->content()->forPreview($exam);
        }
        if (!$this->ctx->auth->isStudent()) {
            throw new HttpException(403);
        }

        [$exam, $p] = $this->participantContext($params, $user, true);
        $this->ctx->session->release();

        if (!in_array((string) $exam['status'], ['running', 'paused'], true) || in_array((string) $p['state'], ['waiting', 'device_pending'], true)) {
            return $this->jsonError('Die Aufgaben sind erst ab dem Start der Klausur verfügbar.', 409);
        }

        return $this->svc()->content()->forStudent($exam, $p);
    }

    /** POST /api/klausur/{id}/puls */
    public function pulse(array $params): array
    {
        $user = $this->requireStudent();
        $this->requireCsrf();
        [$exam, $p] = $this->participantContext($params, $user, false);
        $this->ctx->session->release();

        $input = $this->jsonInput();
        $nowMs = ExamClock::nowMs();
        $pid = (int) $p['id'];
        $examId = (int) $exam['id'];
        $check = DeviceBinding::check($p, DeviceBinding::tokenFromRequest($examId));

        $state = (string) $p['state'];
        $lockReason = $p['lock_reason'];

        if ($check === DeviceBinding::FOREIGN) {
            // Altes oder fremdes Gerät: kein Herzschlag verbuchen, Sperrbildschirm anzeigen.
            $state = $state === 'submitted' ? 'submitted' : 'locked';
            $lockReason = $state === 'locked' ? 'Dieses Gerät gehört nicht (mehr) zu deiner Teilnahme.' : null;
        } else {
            $previous = $p['client_state'] !== null ? json_decode((string) $p['client_state'], true) : [];
            $clientState = $this->cleanClientState($input['client_state'] ?? null, is_array($previous) ? $previous : []);
            $this->ctx->db->run(
                'UPDATE participations SET last_seen_at = ?, client_state = ? WHERE id = ?',
                [ExamClock::toDb($nowMs), json_encode($clientState, JSON_UNESCAPED_UNICODE), $pid],
            );
        }

        $cursor = max(0, (int) ($input['announcement_cursor'] ?? 0));
        $announcements = [];
        $newCursor = $cursor;
        if ($check !== DeviceBinding::FOREIGN) {
            $rows = $this->ctx->db->fetchAll(
                'SELECT id, participation_id, message, created_at FROM exam_announcements
                 WHERE exam_id = ? AND id > ? AND (participation_id IS NULL OR participation_id = ?) AND created_at >= ?
                 ORDER BY id ASC LIMIT 20',
                [$examId, $cursor, $pid, $p['joined_at'] ?? ExamClock::toDb(0)],
            );
            foreach ($rows as $row) {
                $announcements[] = [
                    'id' => (int) $row['id'],
                    'message' => (string) $row['message'],
                    'created_at_ms' => ExamClock::toMs($row['created_at']),
                    'personal' => $row['participation_id'] !== null,
                ];
                $newCursor = max($newCursor, (int) $row['id']);
            }
        }

        $openTypes = array_map('strval', array_column($this->ctx->db->fetchAll(
            "SELECT DISTINCT type FROM exam_requests WHERE participation_id = ? AND status = 'open'",
            [$pid],
        ), 'type'));

        $clock = ExamClock::forParticipation($exam, $p, $nowMs);

        return [
            'success' => true,
            'server_time_ms' => $nowMs,
            'exam_status' => (string) $exam['status'],
            'state' => $state,
            'lock_reason' => $lockReason,
            'state_since_ms' => $p['state_changed_at'] !== null ? ExamClock::toMs((string) $p['state_changed_at']) : null,
            'submitted_at_ms' => $p['submitted_at'] !== null ? ExamClock::toMs((string) $p['submitted_at']) : null,
            'clock' => [
                'started' => $clock['started'],
                'ends_at_ms' => $clock['ends_at_ms'],
                'remaining_ms' => $clock['remaining_ms'],
                'paused' => $clock['paused'],
                'time_up' => $clock['time_up'],
                'unlimited' => $clock['unlimited'],
            ],
            'announcements' => $announcements,
            'announcement_cursor' => $newCursor,
            'hand_raised' => $p['hand_raised_at'] !== null,
            'open_requests' => $openTypes,
            'last_seq' => (int) $p['last_seq'],
        ];
    }

    /** POST /api/klausur/{id}/speichern */
    public function save(array $params): array
    {
        $user = $this->requireStudent();
        $this->requireCsrf();
        [$exam, $p] = $this->participantContext($params, $user, true);
        $this->ctx->session->release();

        $input = $this->jsonInput();
        $items = $input['items'] ?? [];
        $contents = $input['contents'] ?? [];
        if (!is_array($items) || !is_array($contents)) {
            return $this->jsonError('Ungültige Anfrage.', 400);
        }
        $nowMs = ExamClock::nowMs();
        $offset = 0;
        if (isset($input['client_now_ms']) && is_numeric($input['client_now_ms'])) {
            // Optional vom Client: Versatz zwischen Server- und Clientuhr für Offline-Schritte.
            $offset = max(-3600000, min(3600000, $nowMs - (int) $input['client_now_ms']));
        }

        try {
            $result = $this->svc()->answers()->save($exam, $p, array_values($items), $contents, $nowMs, $offset);
        } catch (HttpException $e) {
            if ($e->status === 409) {
                $state = (string) $this->ctx->db->fetchValue('SELECT state FROM participations WHERE id = ?', [(int) $p['id']]);

                return $this->jsonError($e->getMessage(), 409) + ['state' => $state];
            }
            throw $e;
        }

        return [
            'success' => true,
            'ack_seq' => $result['ack_seq'],
            'versions' => (object) $result['versions'],
            'rejected' => $result['rejected'],
            'server_time_ms' => $nowMs,
        ];
    }

    /** POST /api/klausur/{id}/ereignis */
    public function event(array $params): array
    {
        $user = $this->requireStudent();
        $this->requireCsrf();
        [$exam, $p] = $this->participantContext($params, $user, true);
        $this->ctx->session->release();

        $input = $this->jsonInput();
        $events = $input['events'] ?? null;
        if (!is_array($events)) {
            return $this->jsonError('Ungültige Anfrage.', 400);
        }
        if (count($events) > self::MAX_EVENTS) {
            return $this->jsonError('Zu viele Ereignisse in einer Anfrage (höchstens ' . self::MAX_EVENTS . ').', 400);
        }

        $settings = ExamAccess::settingsOf($exam);
        $mode = (string) $exam['mode'];
        $examId = (int) $exam['id'];
        $pid = (int) $p['id'];
        $nowMs = ExamClock::nowMs();

        // Erst alles prüfen, dann schreiben: ein unbekannter Typ lehnt die ganze Anfrage ab.
        $clean = [];
        foreach ($events as $event) {
            $type = is_array($event) ? ($event['type'] ?? null) : null;
            if (!is_string($type) || !isset(EventLog::CLIENT_TYPES[$type])) {
                return $this->jsonError('Unbekannter Ereignistyp.', 400);
            }
            $clean[] = [
                'type' => $type,
                'details' => $this->cleanDetails($event['details'] ?? null),
                'client_ts' => isset($event['client_ts']) && is_numeric($event['client_ts']) ? max(0, (int) $event['client_ts']) : null,
            ];
        }

        $recent = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM participation_events WHERE participation_id = ? AND created_at > ?',
            [$pid, ExamClock::toDb($nowMs - 60000)],
        );
        $throttled = false;
        $lockReason = null;
        foreach ($clean as $event) {
            if ($recent >= self::MAX_EVENTS_PER_MINUTE) {
                $throttled = true;
                break;
            }
            $recent++;
            $severity = EventLog::severityFor($event['type'], $event['details'], $settings, $mode);
            $this->svc()->events()->log($examId, $pid, $event['type'], $severity, $event['details'], null, $event['client_ts'], $nowMs);
            if ($lockReason === null && EventLog::isLockingViolation($event['type'], $event['details'], $settings)) {
                $lockReason = EventLog::VIOLATIONS[$event['type']];
            }
        }

        // Sperre nur im Zustand active, bei lock_on_violation und nie im Modus practice.
        if ($lockReason !== null && !empty($settings['lock_on_violation']) && $mode !== 'practice'
            && (string) $p['state'] === 'active' && (string) $exam['status'] === 'running') {
            try {
                $this->svc()->states()->transition($pid, 'locked', $lockReason, null, $nowMs, ['lock_reason' => $lockReason]);
            } catch (\DomainException) {
                // Zustand hat sich parallel geändert (z. B. schon gesperrt): nichts weiter zu tun.
            }
        }

        $fresh = $this->ctx->db->fetchOne('SELECT state, lock_reason FROM participations WHERE id = ?', [$pid]);

        return [
            'success' => true,
            'state' => (string) ($fresh['state'] ?? $p['state']),
            'lock_reason' => $fresh['lock_reason'] ?? null,
            'throttled' => $throttled,
        ];
    }

    /** POST /api/klausur/{id}/anfrage */
    public function request(array $params): array
    {
        $user = $this->requireStudent();
        $this->requireCsrf();
        [$exam, $p] = $this->participantContext($params, $user, true);
        $this->ctx->session->release();

        $input = $this->jsonInput();
        $type = (string) ($input['type'] ?? '');
        $cancel = filter_var($input['cancel'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!in_array($type, ['hand', 'release', 'submit'], true)) {
            return $this->jsonError('Unbekannte Anfrage.', 400);
        }

        $db = $this->ctx->db;
        $settings = ExamAccess::settingsOf($exam);
        $nowMs = ExamClock::nowMs();
        $nowDb = ExamClock::toDb($nowMs);
        $pid = (int) $p['id'];
        $examId = (int) $exam['id'];
        $state = (string) $p['state'];
        $userId = (int) $user['id'];

        if (in_array($state, ['submitted', 'device_pending'], true)) {
            return $this->jsonError('Diese Anfrage ist in deinem aktuellen Zustand nicht möglich.', 409) + ['state' => $state];
        }

        $openRequest = static fn (string $t) => $db->fetchValue(
            "SELECT id FROM exam_requests WHERE participation_id = ? AND type = ? AND status = 'open' LIMIT 1",
            [$pid, $t],
        );
        $closeRequests = static function (string $t, string $status) use ($db, $pid, $nowDb, $userId): void {
            $db->run(
                "UPDATE exam_requests SET status = ?, resolved_at = ?, resolved_by = ? WHERE participation_id = ? AND type = ? AND status = 'open'",
                [$status, $nowDb, $userId, $pid, $t],
            );
        };
        $openRequestRow = function (string $t) use ($db, $examId, $pid, $nowDb): void {
            $db->run(
                "INSERT INTO exam_requests (exam_id, participation_id, type, status, created_at) VALUES (?, ?, ?, 'open', ?)",
                [$examId, $pid, $t, $nowDb],
            );
        };

        if ($type === 'hand') {
            if ($cancel) {
                $db->run('UPDATE participations SET hand_raised_at = NULL WHERE id = ?', [$pid]);
                $closeRequests('hand', 'cancelled');
                $this->svc()->events()->log($examId, $pid, 'hand_lowered', 'info', ['by' => 'student'], null, null, $nowMs);
            } else {
                if ($openRequest('hand') === null) {
                    $openRequestRow('hand');
                    $this->svc()->events()->log($examId, $pid, 'hand_raised', 'info', [], null, null, $nowMs);
                }
                $db->run('UPDATE participations SET hand_raised_at = COALESCE(hand_raised_at, ?) WHERE id = ?', [$nowDb, $pid]);
            }

            return ['success' => true, 'state' => $state];
        }

        if ($type === 'release') {
            if ($cancel) {
                $closeRequests('release', 'cancelled');

                return ['success' => true, 'state' => $state];
            }
            if (!in_array($state, ['locked', 'absent'], true)) {
                return $this->jsonError('Eine Freigabe ist nur nach einer Sperre oder bei Abwesenheit nötig.', 409) + ['state' => $state];
            }
            if ($openRequest('release') === null) {
                $openRequestRow('release');
            }

            return ['success' => true, 'state' => $state];
        }

        // Abgabe
        if ($cancel) {
            if ($state !== 'submit_requested') {
                return $this->jsonError('Es liegt keine Abgabe-Anfrage vor.', 409) + ['state' => $state];
            }
            try {
                $new = $this->svc()->states()->transition($pid, 'active', 'Abgabe zurückgezogen', null, $nowMs);
            } catch (\DomainException $e) {
                return $this->staleStateError($pid, $e);
            }
            $closeRequests('submit', 'cancelled');

            return ['success' => true, 'state' => (string) $new['state']];
        }
        if (empty($settings['allow_self_submit'])) {
            return $this->jsonError('In dieser Klausur gibt die Lehrkraft für dich ab.', 403);
        }
        if ($state !== 'active') {
            return $this->jsonError('Abgeben ist nur möglich, solange du arbeiten darfst.', 409) + ['state' => $state];
        }
        if ((string) $exam['mode'] === 'practice') {
            // Übung: keine Aufsicht nötig, die Abgabe gilt sofort.
            try {
                $new = $this->svc()->states()->transition($pid, 'submitted', 'Selbst abgegeben (Übung)', $userId, $nowMs);
            } catch (\DomainException $e) {
                return $this->staleStateError($pid, $e);
            }

            return ['success' => true, 'state' => (string) $new['state']];
        }
        try {
            $new = $this->svc()->states()->transition($pid, 'submit_requested', 'Abgabe angefragt', null, $nowMs);
        } catch (\DomainException $e) {
            return $this->staleStateError($pid, $e);
        }
        if ($openRequest('submit') === null) {
            $openRequestRow('submit');
        }

        return ['success' => true, 'state' => (string) $new['state']];
    }

    /**
     * Der Zustand wurde zwischen Lesen und Übergang geändert (z. B. Sperre durch die
     * Lehrkraft): 409 mit dem aktuellen Zustand statt eines 500-Fehlers.
     *
     * @return array<string, mixed>
     */
    private function staleStateError(int $pid, \DomainException $e): array
    {
        $row = $this->ctx->db->fetchOne('SELECT state FROM participations WHERE id = ?', [$pid]);

        return $this->jsonError($e->getMessage(), 409) + ['state' => (string) ($row['state'] ?? '')];
    }

    /** POST /api/klausur/{id}/datei (Zeichenaufgaben mit Upload) */
    /** Höchstgröße der PNG-Vorschau einer Zeichenaufgabe (Data-URL). */
    private const MAX_PREVIEW_BYTES = 2 * 1024 * 1024;

    /**
     * POST /api/klausur/{id}/live: Live-Zustand der Klausurseite für die Live-Wand des Pults
     * (aktuelle Aufgabe, Code-Ausschnitt, Konsole). Nur im Arbeitsspeicher, nur der letzte Stand.
     */
    public function live(array $params): array
    {
        $user = $this->requireStudent();
        $this->requireCsrf();
        [$exam, $p] = $this->participantContext($params, $user, true);
        $this->ctx->session->release();

        $gate = $this->liveGate($exam, $p, 'live_view', 'Die Live-Ansicht ist für diese Klausur nicht eingeschaltet.');
        if ($gate !== null) {
            return $gate;
        }
        $examId = (int) $exam['id'];
        $pid = (int) $p['id'];
        $store = new LiveStore();
        if (!$store->allow($examId, $pid, 'live')) {
            return $this->jsonError('Zu viele Anfragen.', 429);
        }

        $in = $this->jsonInput();
        $taskId = $in['task_id'] ?? null;
        $view = $in['view'] ?? 'task';
        $file = $in['file'] ?? null;
        $cursor = $in['cursor_line'] ?? null;
        $excerpt = $in['code_excerpt'] ?? '';
        $console = $in['console_tail'] ?? '';
        $isInt = static fn (mixed $v): bool => is_int($v) || (is_string($v) && ctype_digit($v));
        $valid = ($taskId === null || $isInt($taskId))
            && is_string($view) && preg_match('/^[a-z0-9_-]{1,30}$/', $view) === 1
            && ($file === null || is_string($file))
            && ($cursor === null || $isInt($cursor))
            && is_string($excerpt) && is_string($console);
        if (!$valid) {
            return $this->jsonError('Ungültige Live-Daten.', 422);
        }

        $store->putState($examId, $pid, [
            'task_id' => $taskId === null ? null : (int) $taskId,
            'view' => $view,
            'file' => $file === null ? null : mb_substr($file, 0, 200),
            'cursor_line' => $cursor === null ? null : max(0, (int) $cursor),
            'code_excerpt' => self::clipText($excerpt, self::LIVE_CODE_LINES, self::LIVE_CODE_CHARS, false),
            'excerpt_start_line' => max(0, (int) ($in['excerpt_start_line'] ?? 0)),
            'console_tail' => self::clipText($console, self::LIVE_CONSOLE_LINES, self::LIVE_CONSOLE_CHARS, true),
            'client_ms' => max(0, (int) ($in['client_ms'] ?? 0)),
        ]);

        $this->dropLiveIfClosed($store, $examId);

        return $this->liveReply($store, $examId, $pid);
    }

    /**
     * POST /api/klausur/{id}/bildschirm: Standbild des Bildschirms (JPEG als Data-URL) für die
     * Live-Wand. Nur wenn screen_share an ist; nur das letzte Bild liegt im Arbeitsspeicher.
     */
    public function screen(array $params): array
    {
        $user = $this->requireStudent();
        $this->requireCsrf();
        [$exam, $p] = $this->participantContext($params, $user, true);
        $this->ctx->session->release();

        $gate = $this->liveGate($exam, $p, 'screen_share', 'Die Bildschirmfreigabe ist für diese Klausur nicht eingeschaltet.');
        if ($gate !== null) {
            return $gate;
        }
        $examId = (int) $exam['id'];
        $pid = (int) $p['id'];
        $store = new LiveStore();
        if (!$store->allow($examId, $pid, 'screen')) {
            return $this->jsonError('Zu viele Anfragen.', 429);
        }

        $in = $this->jsonInput();
        $data = $in['jpeg'] ?? null;
        if (!is_string($data)) {
            return $this->jsonError('Ungültiges Bild.', 422);
        }
        // Base64 ist etwa ein Drittel größer als die Rohdaten: erst grob, dann genau prüfen.
        if (strlen($data) > intdiv(self::SCREEN_MAX_BYTES * 4, 3) + 64) {
            return $this->jsonError('Das Bild ist zu groß (höchstens 600 KB).', 413);
        }
        $prefix = 'data:image/jpeg;base64,';
        $raw = str_starts_with($data, $prefix) ? base64_decode(substr($data, strlen($prefix)), true) : false;
        if ($raw === false || $raw === '') {
            return $this->jsonError('Ungültiges Bild.', 422);
        }
        if (strlen($raw) > self::SCREEN_MAX_BYTES) {
            return $this->jsonError('Das Bild ist zu groß (höchstens 600 KB).', 413);
        }
        if (!str_starts_with($raw, "\xFF\xD8\xFF")) {
            return $this->jsonError('Das Bild ist kein JPEG.', 422);
        }
        $width = $in['width'] ?? null;
        $height = $in['height'] ?? null;
        $isDim = static fn (mixed $v): bool => (is_int($v) || (is_string($v) && ctype_digit($v))) && (int) $v >= 1 && (int) $v <= 10000;
        if (!$isDim($width) || !$isDim($height)) {
            return $this->jsonError('Ungültige Bildgröße.', 422);
        }
        $surface = is_string($in['surface'] ?? null) && in_array($in['surface'], self::SCREEN_SURFACES, true) ? $in['surface'] : 'unknown';

        $store->putScreen($examId, $pid, $raw, (int) $width, (int) $height, $surface);

        $this->dropLiveIfClosed($store, $examId);

        return $this->liveReply($store, $examId, $pid);
    }

    /**
     * POST /api/klausur/{id}/vorschaubild: PNG-Vorschau einer Zeichenaufgabe
     * (Diagramme) für Korrektur und PDF. Getrennt vom Autosave, damit große
     * Bilder die Speicherpakete nicht aufblähen. Nur zu einer bereits
     * gespeicherten Antwort und nur, solange gearbeitet werden darf.
     */
    public function previewImage(array $params): array
    {
        $user = $this->requireStudent();
        $this->requireCsrf();
        [$exam, $p] = $this->participantContext($params, $user, true);
        $this->ctx->session->release();

        if ((string) $p['state'] !== 'active' || !in_array((string) $exam['status'], ['running', 'paused'], true)) {
            return $this->jsonError(AnswerStore::restrictedMessage((string) $p['state']), 409);
        }
        $input = $this->jsonInput();
        $taskId = (int) ($input['task_id'] ?? 0);
        $png = (string) ($input['png'] ?? '');
        if (!str_starts_with($png, 'data:image/png;base64,') || strlen($png) > self::MAX_PREVIEW_BYTES) {
            return $this->jsonError('Ungültiges oder zu großes Vorschaubild.', 422);
        }
        $raw = base64_decode(substr($png, 22), true);
        if ($raw === false || !str_starts_with($raw, "\x89PNG\r\n\x1a\n")) {
            return $this->jsonError('Ungültiges Vorschaubild.', 422);
        }
        $updated = $this->ctx->db->run(
            'UPDATE answers a JOIN tasks t ON t.id = a.task_id
             SET a.preview_png = ?
             WHERE a.participation_id = ? AND a.task_id = ? AND t.exam_id = ?',
            [$png, (int) $p['id'], $taskId, (int) $exam['id']],
        )->rowCount();

        return ['success' => true, 'stored' => $updated > 0];
    }

    public function upload(array $params): array
    {
        $user = $this->requireStudent();
        $this->requireCsrf();
        [$exam, $p] = $this->participantContext($params, $user, true);
        $this->ctx->session->release();

        if ((string) $p['state'] !== 'active' || (string) $exam['status'] !== 'running') {
            return $this->jsonError(AnswerStore::restrictedMessage((string) $p['state']), 409);
        }
        $db = $this->ctx->db;
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $task = $db->fetchOne(
            "SELECT id FROM tasks WHERE id = ? AND exam_id = ? AND answer_type = 'drawing'",
            [$taskId, (int) $exam['id']],
        );
        if ($task === null) {
            return $this->jsonError('Zu dieser Aufgabe kann keine Datei hochgeladen werden.', 400);
        }
        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            return $this->jsonError('Es wurde keine Datei übermittelt.', 400);
        }
        $count = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM answer_files WHERE participation_id = ? AND task_id = ?',
            [(int) $p['id'], $taskId],
        );
        if ($count >= self::MAX_UPLOADS_PER_TASK) {
            return $this->jsonError('Es sind höchstens ' . self::MAX_UPLOADS_PER_TASK . ' Dateien je Aufgabe möglich.', 409);
        }

        $uploads = new Uploads((string) $this->ctx->config['uploads']['dir']);
        $stored = $uploads->store($file, 'answers', ['png', 'jpg', 'jpeg', 'pdf'], 10 * 1024 * 1024);
        $db->run(
            'INSERT INTO answer_files (participation_id, task_id, original_name, stored_name, mime, size_bytes) VALUES (?, ?, ?, ?, ?, ?)',
            [(int) $p['id'], $taskId, mb_substr($stored['original_name'], 0, 255), $stored['filename'], $stored['mime'], $stored['size']],
        );
        $fileId = $db->lastInsertId();

        return [
            'success' => true,
            'file' => [
                'id' => $fileId,
                'name' => $stored['original_name'],
                'url' => $this->ctx->url('/klausur/' . (int) $exam['id'] . '/antwortdatei/' . $fileId),
            ],
        ];
    }

    /** GET /klausur/{id}/antwortdatei/{fid} (Eigentümer:in und Lehrkräfte mit Pult-Recht) */
    public function answerFile(array $params): string
    {
        $user = $this->requireLogin();
        $examId = (int) $params['id'];
        $row = $this->ctx->db->fetchOne(
            'SELECT f.stored_name, f.original_name, p.user_id
             FROM answer_files f JOIN participations p ON p.id = f.participation_id
             WHERE f.id = ? AND p.exam_id = ?',
            [(int) $params['fid'], $examId],
        );
        if ($row === null) {
            throw new HttpException(404, 'Datei nicht gefunden.');
        }
        $own = (int) $row['user_id'] === (int) $user['id'] && $this->ctx->auth->isStudent();
        if (!$own && !$this->ctx->exams->can($examId, ExamAccess::CAP_MONITOR)) {
            throw new HttpException(403);
        }
        $this->ctx->session->release();

        (new Uploads((string) $this->ctx->config['uploads']['dir']))->stream('answers', (string) $row['stored_name']);
    }

    /**
     * Live-Wand: nur für Teilnahmen in active, locked oder paused und solange die Klausur läuft
     * (auch bei Gesamtpause), und nur, wenn der Schalter der Klausur an ist; sonst 409.
     *
     * @param array<string, mixed> $exam
     * @param array<string, mixed> $p
     * @return array<string, mixed>|null Fehlerantwort oder null
     */
    private function liveGate(array $exam, array $p, string $setting, string $offMessage): ?array
    {
        if (!in_array((string) $p['state'], ['active', 'locked', 'paused'], true)
            || !in_array((string) $exam['status'], ['running', 'paused'], true)) {
            return $this->jsonError('Die Live-Ansicht ist gerade nicht möglich.', 409);
        }
        // normalize: Klausuren aus der Zeit vor der Live-Wand haben die Schlüssel noch nicht (Standardwerte).
        if (empty(ExamSettings::normalize(ExamAccess::settingsOf($exam), (string) $exam['mode'])[$setting])) {
            return $this->jsonError($offMessage, 409);
        }

        return null;
    }

    /**
     * Endet die Klausur zwischen Prüfung und Schreiben, wäre das Verzeichnis nach dem Aufräumen
     * wieder entstanden: dann sofort erneut löschen (nichts bleibt über das Klausurende hinaus liegen).
     */
    private function dropLiveIfClosed(LiveStore $store, int $examId): void
    {
        $status = $this->ctx->db->fetchValue('SELECT status FROM exams WHERE id = ?', [$examId]);
        if (!in_array((string) $status, ['running', 'paused'], true)) {
            $store->clearExam($examId);
        }
    }

    /** @return array<string, mixed> */
    private function liveReply(LiveStore $store, int $examId, int $pid): array
    {
        return [
            'ok' => true,
            'data' => [
                'focus' => $store->isFocused($examId, $pid),
                'screen_interval_ms' => $store->screenIntervalMs($examId, $pid),
            ],
        ];
    }

    /** Text auf Zeilen und Zeichen kürzen: $tail behält das Ende (Konsole), sonst den Anfang. */
    private static function clipText(string $text, int $maxLines, int $maxChars, bool $tail): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $lines = $tail ? array_slice($lines, -$maxLines) : array_slice($lines, 0, $maxLines);
        $text = implode("\n", $lines);

        return $tail ? mb_substr($text, -$maxChars) : mb_substr($text, 0, $maxChars);
    }

    // ---------- intern ----------

    /** @return array<string, mixed> */
    private function loadExam(int $examId): array
    {
        $exam = $this->ctx->db->fetchOne('SELECT * FROM exams WHERE id = ?', [$examId]);
        if ($exam === null) {
            throw new HttpException(404, 'Klausur nicht gefunden.');
        }

        return $exam;
    }

    /**
     * @param array<string, mixed> $exam
     * @param array<string, mixed> $user
     */
    private function requireMembership(array $exam, array $user): void
    {
        $member = $exam['course_id'] !== null && $this->ctx->db->fetchValue(
            'SELECT 1 FROM course_members WHERE course_id = ? AND user_id = ?',
            [(int) $exam['course_id'], (int) $user['id']],
        ) !== null;
        if (!$member) {
            throw new HttpException(403, 'Du gehörst nicht zum Kurs dieser Klausur.');
        }
    }

    /** @return array<string, mixed>|null */
    private function findParticipation(int $examId, int $userId): ?array
    {
        return $this->ctx->db->fetchOne('SELECT * FROM participations WHERE exam_id = ? AND user_id = ?', [$examId, $userId]);
    }

    /**
     * Klausur und eigene Teilnahme mit allen Prüfungen (Kurs, SEB, Teilnahme, optional Gerät).
     *
     * @param array<string, string> $params
     * @param array<string, mixed> $user
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function participantContext(array $params, array $user, bool $checkDevice): array
    {
        $examId = (int) $params['id'];
        $exam = $this->loadExam($examId);
        $this->requireMembership($exam, $user);
        $participation = $this->findParticipation($examId, (int) $user['id']);
        $this->svc()->seb()->enforce($exam, $participation);
        if ($participation === null) {
            throw new HttpException(409, 'Du bist dieser Klausur noch nicht beigetreten.');
        }
        if ($checkDevice && DeviceBinding::check($participation, DeviceBinding::tokenFromRequest($examId)) === DeviceBinding::FOREIGN) {
            throw new HttpException(403, 'Dieses Gerät gehört nicht (mehr) zu deiner Teilnahme.');
        }

        return [$exam, $participation];
    }

    /**
     * Client-Angaben beim Beitritt (Browser, Bildschirm), begrenzt und bereinigt.
     *
     * @return array<string, mixed>
     */
    private function cleanClient(mixed $client): array
    {
        $client = is_array($client) ? $client : [];
        $screen = is_array($client['screen'] ?? null) ? $client['screen'] : [];

        return [
            'ua' => mb_substr((string) ($client['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 200),
            'screen' => ['w' => max(0, (int) ($screen['w'] ?? 0)), 'h' => max(0, (int) ($screen['h'] ?? 0))],
            'seb' => SebGuard::headerPresent(),
        ];
    }

    /**
     * Herzschlag-Zustand des Clients (nur bekannte Felder).
     *
     * @param array<string, mixed> $previous
     * @return array<string, mixed>
     */
    private function cleanClientState(mixed $state, array $previous): array
    {
        $state = is_array($state) ? $state : [];
        $screen = is_array($state['screen'] ?? null) ? $state['screen'] : [];
        $task = $state['current_task_id'] ?? null;

        return [
            'ua' => (string) ($previous['ua'] ?? ''),
            'fullscreen' => filter_var($state['fullscreen'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'visible' => filter_var($state['visible'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'focused' => filter_var($state['focused'] ?? true, FILTER_VALIDATE_BOOLEAN),
            // Maßgeblich ist der Server (Header), nicht die Angabe des Clients.
            'seb' => SebGuard::headerPresent(),
            'screen' => ['w' => max(0, (int) ($screen['w'] ?? 0)), 'h' => max(0, (int) ($screen['h'] ?? 0))],
            'online_since_ms' => max(0, (int) ($state['online_since_ms'] ?? 0)),
            'pending_saves' => max(0, min(99999, (int) ($state['pending_saves'] ?? 0))),
            'current_task_id' => is_numeric($task) ? (int) $task : null,
        ];
    }

    /**
     * Details eines Client-Ereignisses: höchstens 10 Schlüssel, nur Skalare, Texte gekürzt.
     *
     * @return array<string, scalar|null>
     */
    private function cleanDetails(mixed $details): array
    {
        if (!is_array($details)) {
            return [];
        }
        $out = [];
        foreach ($details as $key => $value) {
            if (count($out) >= 10) {
                break;
            }
            if (!is_string($key) || preg_match('/^[A-Za-z0-9_]{1,30}$/', $key) !== 1) {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $out[$key] = $value;
            } elseif (is_string($value)) {
                $out[$key] = mb_substr($value, 0, 200);
            }
        }

        return $out;
    }
}
