<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\ExamAccess;
use App\Services\ExamClock;
use App\Services\ExamContent;
use App\Services\LiveServices;

/**
 * Lehrer-Pult: Seite und APIs (docs/API.md Abschnitt 3). Alles braucht die Fähigkeit "monitor";
 * auch Aktionen, die den Klausurstatus ändern.
 */
final class PultController extends Controller
{
    private const DEFAULT_POLL_MS = 1500;
    private const REPLAY_MAX_LIMIT = 5000;

    private ?LiveServices $services = null;

    private function svc(): LiveServices
    {
        return $this->services ??= new LiveServices($this->ctx);
    }

    /** GET /klausuren/{id}/pult */
    public function show(array $params): string
    {
        $this->requireStaff();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_MONITOR);
        $base = (string) $this->ctx->config['app']['base_url'];

        $poll = max(500, min(10000, $this->ctx->settings->getInt('pult_poll_ms', self::DEFAULT_POLL_MS)));
        $boot = [
            'exam_id' => (int) $exam['id'],
            'title' => (string) $exam['title'],
            'mode' => (string) $exam['mode'],
            'status' => (string) $exam['status'],
            'api_base' => $base . '/api/pult/' . (int) $exam['id'],
            'student_api_base' => $base . '/api/klausur/' . (int) $exam['id'],
            'poll_ms' => $poll,
            'server_time_ms' => ExamClock::nowMs(),
            'has_room' => $exam['room_id'] !== null,
            'urls' => [
                'exam' => $base . '/klausuren/' . (int) $exam['id'],
                'preview' => $base . '/klausuren/' . (int) $exam['id'] . '/vorschau',
                'answer_file' => $base . '/klausur/' . (int) $exam['id'] . '/antwortdatei/',
            ],
            'can' => $this->ctx->exams->capabilities((int) $exam['id']),
        ];

        return $this->render('pages/pult/index', [
            'title' => 'Pult: ' . (string) $exam['title'],
            'exam' => $exam,
            'boot' => $boot,
            'wideContent' => true,
            'bundles' => ['pult.js'],
            'pageStyles' => ['pult.css', 'exam.css', 'code.css', 'diagrams.css', 'robo.css'],
        ]);
    }

    /** GET /api/pult/{id}/stand?cursor=<event_id> */
    public function state(array $params): array
    {
        $exam = $this->monitorExam($params);
        $this->ctx->session->release();

        $fresh = $this->ctx->db->fetchOne('SELECT * FROM exams WHERE id = ?', [(int) $exam['id']]) ?? $exam;

        return $this->svc()->status()->stand($fresh, max(0, (int) ($_GET['cursor'] ?? 0)), ExamClock::nowMs());
    }

    /** POST /api/pult/{id}/aktion */
    public function action(array $params): array
    {
        $user = $this->requireStaff();
        $this->requireCsrf();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_MONITOR);
        $this->ctx->session->release();

        $result = $this->svc()->actions()->run($exam, $this->jsonInput(), (int) $user['id']);
        if (($result['success'] ?? false) !== true) {
            http_response_code((int) ($result['status'] ?? 400));
            unset($result['status']);
        }

        return $result;
    }

    /** GET /api/pult/{id}/antworten/{participationId} */
    public function answers(array $params): array
    {
        $exam = $this->monitorExam($params);
        $this->ctx->session->release();
        $p = $this->participation($exam, (int) $params['participationId']);

        $rows = $this->ctx->db->fetchAll(
            'SELECT task_id, content, version, flagged, has_overtime, updated_at FROM answers WHERE participation_id = ?',
            [(int) $p['id']],
        );
        $answers = [];
        foreach ($rows as $row) {
            $answers[(int) $row['task_id']] = [
                'content' => ExamContent::decodeContent((string) $row['content']),
                'version' => (int) $row['version'],
                'updated_at_ms' => ExamClock::toMs($row['updated_at']),
                'has_overtime' => (int) $row['has_overtime'] === 1,
                'flagged' => (int) $row['flagged'] === 1,
            ];
        }

        $out = [
            'success' => true,
            'participation' => ['id' => (int) $p['id'], 'name' => display_name($p), 'state' => (string) $p['state']],
            'answers' => $answers === [] ? (object) [] : $answers,
        ];
        // Der Aufgabenbaum ändert sich während der Klausur nicht: Aktualisierungen können ihn weglassen.
        if (($_GET['tasks'] ?? '1') !== '0') {
            $out['tasks'] = $this->svc()->content()->tasks($exam, 0, false);
        }

        return $out;
    }

    /** GET /api/pult/{id}/replay/{participationId}?task_id=&after_seq=&limit= */
    public function replay(array $params): array
    {
        $exam = $this->monitorExam($params);
        $this->ctx->session->release();
        $p = $this->participation($exam, (int) $params['participationId']);

        $limit = max(1, min(self::REPLAY_MAX_LIMIT, (int) ($_GET['limit'] ?? 2000)));
        $after = max(0, (int) ($_GET['after_seq'] ?? 0));
        $taskId = isset($_GET['task_id']) && is_numeric($_GET['task_id']) ? (int) $_GET['task_id'] : null;

        $sql = 'SELECT seq, task_id, client_ts, server_ts, kind, payload, is_paste, is_overtime FROM answer_ops
                WHERE participation_id = ? AND seq > ?';
        $args = [(int) $p['id'], $after];
        if ($taskId !== null) {
            $sql .= ' AND task_id = ?';
            $args[] = $taskId;
        }
        $rows = $this->ctx->db->fetchAll($sql . ' ORDER BY seq ASC LIMIT ' . ($limit + 1), $args);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $ops = array_map(static function (array $r): array {
            $payload = json_decode((string) $r['payload'], true);

            return [
                'seq' => (int) $r['seq'],
                'task_id' => (int) $r['task_id'],
                'client_ts' => (int) $r['client_ts'],
                'server_ts_ms' => ExamClock::toMs($r['server_ts']),
                'kind' => (string) $r['kind'],
                'payload' => $payload ?? new \stdClass(),
                'is_paste' => (int) $r['is_paste'] === 1,
                'is_overtime' => (int) $r['is_overtime'] === 1,
            ];
        }, $rows);

        return ['success' => true, 'ops' => $ops, 'has_more' => $more];
    }

    /** POST /api/pult/{id}/sitzplan */
    public function seatPlan(array $params): array
    {
        $this->requireStaff();
        $this->requireCsrf();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_MONITOR);
        $this->ctx->session->release();

        if ($exam['room_id'] === null) {
            return $this->jsonError('Dieser Klausur ist kein Raum zugeordnet.', 400);
        }
        $roomId = (int) $exam['room_id'];
        $room = $this->ctx->db->fetchOne('SELECT grid_cols, grid_rows FROM rooms WHERE id = ?', [$roomId]);
        if ($room === null) {
            return $this->jsonError('Der Raum existiert nicht mehr.', 400);
        }
        $positions = $this->jsonInput()['positions'] ?? null;
        if (!is_array($positions)) {
            return $this->jsonError('Ungültige Anfrage.', 400);
        }

        // Nur Personen dieser Klausur (Kursmitglieder oder Teilnehmende) dürfen platziert werden.
        $allowed = [];
        $rows = $this->ctx->db->fetchAll(
            'SELECT user_id FROM participations WHERE exam_id = ?
             UNION SELECT user_id FROM course_members WHERE course_id = ?',
            [(int) $exam['id'], (int) ($exam['course_id'] ?? 0)],
        );
        foreach ($rows as $r) {
            $allowed[(int) $r['user_id']] = true;
        }

        $cols = (int) $room['grid_cols'];
        $rowsMax = (int) $room['grid_rows'];
        $assign = [];
        $remove = [];
        $cells = [];
        foreach ($positions as $pos) {
            if (!is_array($pos) || !isset($pos['user_id']) || !is_numeric($pos['user_id']) || !isset($allowed[(int) $pos['user_id']])) {
                return $this->jsonError('Ungültige Person im Sitzplan.', 400);
            }
            $userId = (int) $pos['user_id'];
            if (($pos['col'] ?? null) === null || ($pos['row'] ?? null) === null) {
                $remove[] = $userId;
                continue;
            }
            $col = (int) $pos['col'];
            $row = (int) $pos['row'];
            if ($col < 0 || $col >= $cols || $row < 0 || $row >= $rowsMax) {
                return $this->jsonError('Ein Platz liegt außerhalb des Raumrasters.', 400);
            }
            $key = $col . ':' . $row;
            if (isset($cells[$key]) || isset($assign[$userId])) {
                return $this->jsonError('Ein Platz oder eine Person ist doppelt belegt.', 400);
            }
            $cells[$key] = true;
            $assign[$userId] = [$col, $row];
        }

        $db = $this->ctx->db;
        $db->transaction(function () use ($db, $roomId, $assign, $remove): void {
            $users = array_merge(array_keys($assign), $remove);
            foreach ($users as $userId) {
                $db->run('DELETE FROM room_positions WHERE room_id = ? AND user_id = ?', [$roomId, $userId]);
            }
            foreach ($assign as $userId => [$col, $row]) {
                // Wer sonst auf dem Zielplatz sitzt (z. B. aus einem anderen Kurs), verliert diesen Platz.
                $db->run('DELETE FROM room_positions WHERE room_id = ? AND col = ? AND `row` = ?', [$roomId, $col, $row]);
                $db->run('INSERT INTO room_positions (room_id, user_id, col, `row`) VALUES (?, ?, ?, ?)', [$roomId, $userId, $col, $row]);
            }
        });

        return ['success' => true, 'saved' => count($assign)];
    }

    /** GET /api/pult/{id}/ereignisse/{participationId} */
    public function events(array $params): array
    {
        $exam = $this->monitorExam($params);
        $this->ctx->session->release();
        $p = $this->participation($exam, (int) $params['participationId']);

        return [
            'success' => true,
            'participation' => ['id' => (int) $p['id'], 'name' => display_name($p), 'state' => (string) $p['state']],
            'events' => $this->svc()->events()->forParticipation((int) $exam['id'], (int) $p['id']),
        ];
    }

    // ---------- intern ----------

    /**
     * Lehrkraft mit Pult-Recht an der Klausur.
     *
     * @return array<string, mixed>
     */
    private function monitorExam(array $params): array
    {
        $this->requireStaff();

        return $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_MONITOR);
    }

    /**
     * Teilnahme der Klausur samt Name; 404, wenn sie nicht zu dieser Klausur gehört.
     *
     * @param array<string, mixed> $exam
     * @return array<string, mixed>
     */
    private function participation(array $exam, int $participationId): array
    {
        $p = $this->ctx->db->fetchOne(
            'SELECT p.*, u.firstname, u.lastname, u.username FROM participations p JOIN users u ON u.id = p.user_id
             WHERE p.id = ? AND p.exam_id = ?',
            [$participationId, (int) $exam['id']],
        );
        if ($p === null) {
            throw new HttpException(404, 'Teilnahme nicht gefunden.');
        }

        return $p;
    }
}
