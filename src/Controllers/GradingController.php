<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\AutoGrader;
use App\Services\ExamAccess;
use App\Services\ExamContent;
use App\Services\ExamService;
use App\Services\GradingScales;
use App\Services\GradingService;
use App\Services\Markdown;
use App\Services\RunnerClient;
use App\Services\TaskService;

/**
 * Korrektur (Stufe 2): Übersicht, Einzelansicht, Bewertung, Kommentare, automatische Vorbewertung.
 *
 * Alles braucht die Fähigkeit "grade" (Hauptlehrkraft, Admins, Aufsichten mit can_grade).
 * Bewertet werden kann nur in der Phase "In Korrektur". Die Hauptlehrkraft sieht immer alle
 * Bewertungen, hinzugefügte Lehrkräfte sehen fremde nur mit dem Schalter "fremde Bewertungen zeigen".
 */
final class GradingController extends Controller
{
    private ?GradingService $grading = null;

    private function svc(): GradingService
    {
        return $this->grading ??= new GradingService($this->ctx->db, new GradingScales($this->ctx->db));
    }

    private function baseUrl(): string
    {
        return (string) ($this->ctx->config['app']['base_url'] ?? '');
    }

    private function autoGrader(): AutoGrader
    {
        return new AutoGrader($this->ctx->db, new RunnerClient((array) ($this->ctx->config['runner'] ?? [])));
    }

    private function isOwner(int $examId): bool
    {
        return $this->ctx->exams->roleIn($examId) === 'owner';
    }

    /** Fremde Bewertungen sichtbar? Hauptlehrkraft immer, sonst nur mit Schalter (fremde=1). */
    private function seesOthers(int $examId, mixed $flag): bool
    {
        return $this->isOwner($examId) || (string) $flag === '1';
    }

    // ---------- Seiten ----------

    /** GET /klausuren/{id}/korrektur */
    public function overview(array $params): string
    {
        $user = $this->requireStaff();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_GRADE);
        $examId = (int) $exam['id'];
        if (!in_array((string) $exam['status'], ['finished', 'grading', 'returned', 'archived'], true)) {
            $this->flash('warning', 'Die Korrektur ist erst nach dem Ende der Klausur möglich.');
            $this->redirect($this->ctx->url('/klausuren/' . $examId));
        }

        $anonymous = ($_GET['anonym'] ?? '') === '1';
        $seeOthers = $this->seesOthers($examId, $_GET['fremde'] ?? '');
        $grader = $this->autoGrader();

        return $this->render('pages/grading/overview', [
            'title' => 'Korrektur: ' . (string) $exam['title'],
            'exam' => $exam,
            'caps' => $this->ctx->exams->capabilities($examId),
            'isOwner' => $this->isOwner($examId),
            'overview' => $this->svc()->overview($exam, (int) $user['id'], $seeOthers, $anonymous),
            'auto' => $grader->status($examId),
            'anonymous' => $anonymous,
            'seeOthers' => $seeOthers,
            'statusLabel' => ExamService::STATUS_LABELS[(string) $exam['status']] ?? (string) $exam['status'],
            'boot' => [
                'exam_id' => $examId,
                'api_base' => $this->baseUrl() . '/api/korrektur/' . $examId,
                'status' => (string) $exam['status'],
                'auto' => $grader->status($examId),
            ],
            'bundles' => ['grading.js'],
            'pageStyles' => ['grading.css'],
            'wideContent' => true,
        ]);
    }

    /** POST /klausuren/{id}/korrektur/beginnen */
    public function begin(array $params): never
    {
        $this->requireStaff();
        $this->requireCsrf();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_GRADE);
        $examId = (int) $exam['id'];
        try {
            $this->svc()->begin($exam);
            $this->ctx->exams->forget($examId);
            $this->ctx->audit->log('korrektur_begonnen', 'info', 'Korrektur der Klausur #' . $examId . ' (' . $exam['title'] . ') begonnen.');
            $this->flash('success', 'Die Korrektur hat begonnen. Du kannst jetzt Punkte vergeben.');
        } catch (HttpException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect($this->ctx->url('/klausuren/' . $examId . '/korrektur'));
    }

    /** GET /klausuren/{id}/korrektur/{pid}?aufgabe=&modus=&anonym=&fremde= */
    public function show(array $params): string
    {
        $user = $this->requireStaff();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_GRADE);
        $examId = (int) $exam['id'];
        $status = (string) $exam['status'];
        if ($status === 'finished') {
            $this->flash('info', 'Beginne zuerst die Korrektur, dann kannst du Punkte vergeben.');
            $this->redirect($this->ctx->url('/klausuren/' . $examId . '/korrektur'));
        }
        if (!in_array($status, ['grading', 'returned', 'archived'], true)) {
            $this->flash('warning', 'Die Korrektur ist erst nach dem Ende der Klausur möglich.');
            $this->redirect($this->ctx->url('/klausuren/' . $examId));
        }

        $pid = (int) $params['pid'];
        if ($this->ctx->db->fetchValue('SELECT 1 FROM participations WHERE id = ? AND exam_id = ?', [$pid, $examId]) === null) {
            throw new HttpException(404, 'Teilnahme nicht gefunden.');
        }

        $anonymous = ($_GET['anonym'] ?? '') === '1';
        $seeOthers = $this->seesOthers($examId, $_GET['fremde'] ?? '');
        $overview = $this->svc()->overview($exam, (int) $user['id'], $seeOthers, $anonymous);
        $tasks = $this->taskDetails($exam);

        $leafIds = array_column(array_filter($tasks, static fn (array $t): bool => $t['answer_type'] !== null), 'id');
        if ($leafIds === []) {
            $this->flash('warning', 'Diese Klausur hat keine Aufgaben mit Antwort.');
            $this->redirect($this->ctx->url('/klausuren/' . $examId . '/korrektur'));
        }
        $tid = (int) ($_GET['aufgabe'] ?? 0);
        if (!in_array($tid, $leafIds, true)) {
            $tid = (int) $leafIds[0];
        }

        $caps = $this->ctx->exams->capabilities($examId);
        $boot = [
            'exam_id' => $examId,
            'title' => (string) $exam['title'],
            'status' => $status,
            'read_only' => $status !== 'grading',
            'api_base' => $this->baseUrl() . '/api/korrektur/' . $examId,
            'pult_api_base' => $this->baseUrl() . '/api/pult/' . $examId,
            'viewer_id' => (int) $user['id'],
            'is_owner' => $this->isOwner($examId),
            'sees_others' => $seeOthers,
            'anonymous' => $anonymous,
            'mode' => ($_GET['modus'] ?? '') === 'aufgabe' ? 'task' : 'person',
            'current' => ['participation_id' => $pid, 'task_id' => $tid],
            'can' => ['replay' => $caps[ExamAccess::CAP_MONITOR], 'manage' => $caps[ExamAccess::CAP_MANAGE]],
            'urls' => [
                'overview' => $this->baseUrl() . '/klausuren/' . $examId . '/korrektur',
                'results' => $this->baseUrl() . '/klausuren/' . $examId . '/ergebnisse',
                'page' => $this->baseUrl() . '/klausuren/' . $examId . '/korrektur/',
            ],
            'participants' => array_map(static fn (array $r): array => [
                'id' => $r['participation_id'],
                'label' => $r['label'],
                'state' => $r['state'],
                'submitted' => $r['submitted'],
                'cells' => array_map(static fn (array $c): array => ['status' => $c['status'], 'points' => $c['points']], $r['cells']),
            ], $overview['rows']),
            'tasks' => $tasks,
        ];

        return $this->render('pages/grading/show', [
            'title' => 'Korrektur: ' . (string) $exam['title'],
            'exam' => $exam,
            'boot' => $boot,
            'bundles' => ['grading.js'],
            'pageStyles' => ['grading.css', 'exam.css', 'code.css'],
            'wideContent' => true,
        ]);
    }

    // ---------- JSON: Zelle ----------

    /** GET /api/korrektur/{id}/zelle/{pid}/{tid}?fremde=1 */
    public function cell(array $params): array
    {
        $user = $this->requireStaff();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_GRADE);
        $this->ctx->session->release();
        $db = $this->ctx->db;
        $examId = (int) $exam['id'];
        $pid = (int) $params['pid'];
        $tid = (int) $params['tid'];
        $viewerId = (int) $user['id'];
        $seeOthers = $this->seesOthers($examId, $_GET['fremde'] ?? '');

        $p = $db->fetchOne('SELECT id, state, submitted_at FROM participations WHERE id = ? AND exam_id = ?', [$pid, $examId]);
        $task = $db->fetchOne('SELECT id, points FROM tasks WHERE id = ? AND exam_id = ? AND answer_type IS NOT NULL', [$tid, $examId]);
        if ($p === null || $task === null) {
            throw new HttpException(404, 'Zelle nicht gefunden.');
        }

        $answer = $db->fetchOne(
            'SELECT content, version, has_overtime, updated_at FROM answers WHERE participation_id = ? AND task_id = ?',
            [$pid, $tid],
        );
        $pastes = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM answer_ops WHERE participation_id = ? AND task_id = ? AND is_paste = 1',
            [$pid, $tid],
        );
        $autoRow = $db->fetchOne('SELECT suggested_points, details FROM auto_gradings WHERE participation_id = ? AND task_id = ?', [$pid, $tid]);

        $entries = $db->fetchAll(
            'SELECT e.grader_id, e.points, e.updated_at, u.firstname, u.lastname, u.username
             FROM grading_entries e JOIN users u ON u.id = e.grader_id
             WHERE e.participation_id = ? AND e.task_id = ?',
            [$pid, $tid],
        );
        $ownerId = $exam['owner_id'] !== null ? (int) $exam['owner_id'] : null;
        $visible = array_values(array_filter($entries, static fn (array $e): bool => $seeOthers || (int) $e['grader_id'] === $viewerId));
        $final = GradingService::finalEntry($visible, $ownerId);
        $own = null;
        foreach ($entries as $e) {
            if ((int) $e['grader_id'] === $viewerId) {
                $own = $e['points'] !== null ? (float) $e['points'] : null;
            }
        }

        $content = null;
        if ($answer !== null) {
            $decoded = json_decode((string) $answer['content'], true);
            $content = is_array($decoded) && $decoded !== [] ? $decoded : (object) [];
        }

        return [
            'success' => true,
            'participation' => [
                'id' => $pid,
                'state' => (string) $p['state'],
                'submitted' => (string) $p['state'] === 'submitted',
            ],
            'task_id' => $tid,
            'answer' => $answer === null ? null : [
                'content' => $content,
                'version' => (int) $answer['version'],
                'has_overtime' => (int) $answer['has_overtime'] === 1,
                'updated_at' => (string) $answer['updated_at'],
            ],
            'pastes' => $pastes,
            'auto' => $autoRow === null ? null : [
                'suggested_points' => $autoRow['suggested_points'] !== null ? (float) $autoRow['suggested_points'] : null,
                'details' => json_decode((string) $autoRow['details'], true) ?: (object) [],
            ],
            'own_points' => $own,
            'final' => ['points' => $final['points'], 'source' => $final['source'], 'differs' => $seeOthers && $final['differs']],
            'others' => $seeOthers ? array_values(array_map(static fn (array $e): array => [
                'grader_id' => (int) $e['grader_id'],
                'grader_name' => display_name($e),
                'points' => $e['points'] !== null ? (float) $e['points'] : null,
                'is_final' => $final['grader_id'] === (int) $e['grader_id'],
            ], array_filter($entries, static fn (array $e): bool => (int) $e['grader_id'] !== $viewerId))) : [],
            'comments' => $this->svc()->comments($pid, $tid, $viewerId, $seeOthers),
            'max_points' => (float) $task['points'],
        ];
    }

    // ---------- JSON: Bewertung ----------

    /** POST /api/korrektur/{id}/bewertung  {participation_id, task_id, points} */
    public function saveEntry(array $params): array
    {
        $user = $this->requireStaff();
        $this->requireCsrf();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_GRADE);
        $this->ctx->session->release();
        $in = $this->jsonInput();
        $result = $this->svc()->saveEntry(
            $exam,
            (int) ($in['participation_id'] ?? 0),
            (int) ($in['task_id'] ?? 0),
            (int) $user['id'],
            $in['points'] ?? null,
        );

        return [
            'success' => true,
            'points' => $result['points'],
            'final' => $result['final'],
            'totals' => ['total' => $result['totals']['total'], 'max' => $result['totals']['max'], 'percent' => $result['totals']['percent'], 'grade_label' => $result['totals']['grade_label']],
            'unfinalized' => $result['unfinalized'],
        ];
    }

    /** POST /api/korrektur/{id}/kommentar  {participation_id, task_id, id?, anchor, body} */
    public function saveComment(array $params): array
    {
        $user = $this->requireStaff();
        $this->requireCsrf();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_GRADE);
        $this->ctx->session->release();
        $in = $this->jsonInput();
        $comment = $this->svc()->saveComment(
            $exam,
            (int) ($in['participation_id'] ?? 0),
            (int) ($in['task_id'] ?? 0),
            (int) $user['id'],
            isset($in['id']) && (int) $in['id'] > 0 ? (int) $in['id'] : null,
            is_array($in['anchor'] ?? null) ? $in['anchor'] : [],
            (string) ($in['body'] ?? ''),
        );

        return ['success' => true, 'comment' => $comment];
    }

    /** POST /api/korrektur/{id}/kommentar/{cid}/loeschen */
    public function deleteComment(array $params): array
    {
        $user = $this->requireStaff();
        $this->requireCsrf();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_GRADE);
        $this->ctx->session->release();
        $this->svc()->deleteComment($exam, (int) $params['cid'], (int) $user['id'], $this->isOwner((int) $exam['id']));

        return ['success' => true];
    }

    /** POST /api/korrektur/{id}/vorschlaege  {scope: 'auto'|'all', participation_id?, task_id?} */
    public function applySuggestions(array $params): array
    {
        $user = $this->requireStaff();
        $this->requireCsrf();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_GRADE);
        $this->ctx->session->release();
        $in = $this->jsonInput();
        $count = $this->svc()->applySuggestions(
            $exam,
            (int) $user['id'],
            ($in['scope'] ?? 'auto') === 'all',
            isset($in['participation_id']) ? (int) $in['participation_id'] : null,
            isset($in['task_id']) ? (int) $in['task_id'] : null,
        );
        $this->ctx->audit->log('vorschlaege_uebernommen', 'info', $count . ' Vorschläge in Klausur #' . (int) $exam['id'] . ' übernommen.');

        return ['success' => true, 'applied' => $count];
    }

    // ---------- JSON: automatische Vorbewertung ----------

    /**
     * POST /api/korrektur/{id}/vorbewertung  {reset?: 'all'|'errors', limit?: int}
     *
     * Verarbeitet einen Stapel und meldet den Rest. Die Oberfläche ruft wiederholt auf, bis remaining 0 ist
     * oder aborted gesetzt wird (z. B. Runner nicht erreichbar).
     */
    public function autoStep(array $params): array
    {
        $this->requireStaff();
        $this->requireCsrf();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_GRADE);
        $this->ctx->session->release();
        if (!in_array((string) $exam['status'], ['finished', 'grading'], true)) {
            return $this->jsonError('Automatisch vorbewertet wird vor der Rückgabe, nicht danach.', 409);
        }
        $in = $this->jsonInput();
        $grader = $this->autoGrader();
        $reset = (string) ($in['reset'] ?? '');
        if (in_array($reset, ['all', 'errors'], true)) {
            $grader->reset((int) $exam['id'], $reset);
        }
        $limit = max(1, min(25, (int) ($in['limit'] ?? AutoGrader::DEFAULT_BATCH)));

        return ['success' => true] + $grader->step($exam, $limit);
    }

    /** POST /api/korrektur/{id}/neu-testen  {participation_id, task_id} */
    public function retest(array $params): array
    {
        $this->requireStaff();
        $this->requireCsrf();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_GRADE);
        $this->ctx->session->release();
        if (!in_array((string) $exam['status'], ['finished', 'grading'], true)) {
            return $this->jsonError('Neu getestet wird vor der Rückgabe, nicht danach.', 409);
        }
        $in = $this->jsonInput();
        $result = $this->autoGrader()->retest($exam, (int) ($in['participation_id'] ?? 0), (int) ($in['task_id'] ?? 0));
        if ($result === null) {
            return $this->jsonError('Diese Aufgabe lässt sich nicht automatisch prüfen.', 422);
        }

        return [
            'success' => true,
            'auto' => ['suggested_points' => $result['suggested'], 'details' => $result['details']],
        ];
    }

    // ---------- intern ----------

    /**
     * Aufgabenbaum (flach, mit Tiefe) für die Einzelansicht: Texte für Schüler:innen plus Lösungsdaten.
     * Nur nach CAP_GRADE aufrufen.
     *
     * @param array<string, mixed> $exam
     * @return list<array<string, mixed>>
     */
    private function taskDetails(array $exam): array
    {
        $examId = (int) $exam['id'];
        $content = new ExamContent($this->ctx->db, $this->baseUrl());
        $full = [];
        foreach ((new TaskService($this->ctx->db))->fullList($examId) as $t) {
            $full[(int) $t['id']] = $t;
        }
        $markdown = new Markdown($this->baseUrl(), $examId);
        $out = [];
        $walk = function (array $nodes, int $depth) use (&$walk, &$out, $full, $markdown): void {
            foreach ($nodes as $node) {
                $children = $node['children'] ?? [];
                unset($node['children']);
                $id = (int) $node['id'];
                $solution = $full[$id] ?? [];
                $node['depth'] = $depth;
                $node['expectation_html'] = $markdown->render((string) ($solution['expectation_md'] ?? ''));
                $node['model_html'] = $this->modelHtml($markdown, (string) ($solution['model_answer'] ?? ''), (string) $node['answer_type'], (array) ($solution['config'] ?? []));
                $node['solution'] = $node['answer_type'] !== null ? ($solution['solution'] ?? new \stdClass()) : null;
                $node['tests_count'] = count($solution['tests'] ?? []);
                if ($node['solution'] === []) {
                    $node['solution'] = new \stdClass();
                }
                $out[] = $node;
                $walk($children, $depth + 1);
            }
        };
        $walk($content->tasks($exam, 0, false), 0);

        return $out;
    }

    /** Musterlösung als HTML: Code ohne Markdown-Zeichen wird als Codeblock gesetzt. @param array<string, mixed> $config */
    private function modelHtml(Markdown $markdown, string $model, string $type, array $config): string
    {
        if (trim($model) === '') {
            return '';
        }
        if ($type === 'code' && !str_contains($model, '```')) {
            $lang = in_array($config['language'] ?? '', ['java', 'python', 'sql'], true) ? (string) $config['language'] : '';
            $model = "```" . $lang . "\n" . $model . "\n```";
        }

        return $markdown->render($model);
    }
}
