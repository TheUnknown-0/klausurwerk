<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions as P;
use App\Services\ExamAccess;
use App\Services\ExamKit;
use App\Services\TaskService;
use App\Services\TaskTypes\TaskTypeRegistry;
use App\Services\TaskValidationException;

/**
 * Archiv: Suche über Aufgaben aller sichtbaren Klausuren und der im Archiv freigegebenen,
 * Kopieren einzelner Aufgaben in eigene Entwürfe.
 */
final class ArchiveController extends Controller
{
    private const PAGE_SIZE = 25;

    /** GET /archiv */
    public function index(array $params): string
    {
        $this->requirePermission(P::ARCHIV_DURCHSUCHEN);
        $this->requireStaff();

        $f = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'sprache' => (string) ($_GET['sprache'] ?? ''),
            'typ' => (string) ($_GET['typ'] ?? ''),
            'stufe' => (int) ($_GET['stufe'] ?? 0),
            'operator' => (int) ($_GET['operator'] ?? 0),
        ];
        $page = max(1, (int) ($_GET['seite'] ?? 1));

        $visible = $this->ctx->exams->visibleExamIds();
        $where = ['(e.shared_in_archive = 1' . ($visible !== [] ? ' OR e.id IN (' . implode(',', array_fill(0, count($visible), '?')) . ')' : '') . ')'];
        $args = $visible;

        $select = 't.id, t.title, t.body_md, t.points, t.answer_type, t.afb, t.parent_id, t.exam_id,
            e.title AS exam_title, e.created_at AS exam_created, e.scheduled_at, e.shared_in_archive, e.owner_id,
            c.name AS course_name, c.grade_level, o.name AS operator_name,
            p.title AS parent_title';
        $from = 'FROM tasks t
            JOIN exams e ON e.id = t.exam_id
            LEFT JOIN courses c ON c.id = e.course_id
            LEFT JOIN operators o ON o.id = t.operator_id
            LEFT JOIN tasks p ON p.id = t.parent_id';

        // Nur Aufgaben mit Inhalt (keine leeren Gerüste).
        $where[] = "(t.body_md <> '' OR t.title <> '')";

        if ($f['q'] !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
            $where[] = '(MATCH(t.title, t.body_md) AGAINST (? IN NATURAL LANGUAGE MODE) OR t.title LIKE ? OR t.body_md LIKE ?)';
            array_push($args, $f['q'], $like, $like);
        }
        if (in_array($f['sprache'], ['java', 'python', 'sql'], true)) {
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(t.config, '$.language')) = ?";
            $args[] = $f['sprache'];
        }
        if ($f['typ'] !== '' && TaskTypeRegistry::get($f['typ']) !== null) {
            $where[] = 't.answer_type = ?';
            $args[] = $f['typ'];
        }
        if ($f['stufe'] > 0) {
            $where[] = 'c.grade_level = ?';
            $args[] = $f['stufe'];
        }
        if ($f['operator'] > 0) {
            $where[] = 't.operator_id = ?';
            $args[] = $f['operator'];
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) $this->ctx->db->fetchValue("SELECT COUNT(*) {$from} WHERE {$whereSql}", $args);
        $rows = $this->ctx->db->fetchAll(
            "SELECT {$select} {$from} WHERE {$whereSql} ORDER BY e.created_at DESC, t.exam_id, t.parent_id, t.position LIMIT " . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE),
            $args,
        );
        foreach ($rows as &$row) {
            $row['snippet'] = self::snippet((string) $row['body_md'], $f['q']);
        }
        unset($row);

        // Zielklausuren für "In Klausur kopieren": CAP_EDIT und Entwurf/Bereit.
        $targets = [];
        foreach ($this->ctx->exams->visibleExamIds() as $examId) {
            if ($this->ctx->exams->can($examId, ExamAccess::CAP_EDIT)) {
                $exam = $this->ctx->exams->require($examId, ExamAccess::CAP_EDIT);
                if (in_array($exam['status'], TaskService::EDITABLE_STATUSES, true)) {
                    $targets[] = ['id' => $examId, 'title' => $exam['title']];
                }
            }
        }

        return $this->render('pages/archive/index', [
            'title' => 'Archiv',
            'rows' => $rows,
            'filters' => $f,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'total' => $total,
            'types' => TaskTypeRegistry::grouped(),
            'operators' => $this->ctx->db->fetchAll('SELECT id, name FROM operators ORDER BY sort_order, name'),
            'grades' => $this->ctx->db->fetchAll('SELECT DISTINCT grade_level FROM courses ORDER BY grade_level'),
            'targets' => $targets,
            'pageStyles' => ['exams.css'],
        ]);
    }

    /** Ausschnitt um die erste Fundstelle, sonst Textanfang (Markdown-Zeichen bleiben Rohtext). */
    private static function snippet(string $text, string $q): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return '';
        }
        $pos = $q !== '' ? mb_stripos($text, $q) : false;
        $start = $pos === false ? 0 : max(0, $pos - 60);
        $out = mb_substr($text, $start, 220);

        return ($start > 0 ? '… ' : '') . $out . (mb_strlen($text) > $start + 220 ? ' …' : '');
    }

    /** POST /archiv/kopieren */
    public function copy(array $params): never
    {
        $this->requirePermission(P::ARCHIV_DURCHSUCHEN);
        $this->requireStaff();
        $this->requireCsrf();
        $in = $this->jsonInput();
        $taskId = (int) ($in['task_id'] ?? 0);
        $targetId = (int) ($in['target_exam_id'] ?? 0);
        $back = $this->ctx->url('/archiv');

        $target = $this->ctx->exams->require($targetId, ExamAccess::CAP_EDIT);
        $source = $this->ctx->db->fetchOne(
            'SELECT t.id, t.exam_id, e.shared_in_archive FROM tasks t JOIN exams e ON e.id = t.exam_id WHERE t.id = ?',
            [$taskId],
        );
        if ($source === null) {
            throw new HttpException(404, 'Aufgabe nicht gefunden.');
        }
        $sourceExam = (int) $source['exam_id'];
        $mayView = $this->ctx->exams->can($sourceExam, ExamAccess::CAP_VIEW);
        if (!$mayView && (int) $source['shared_in_archive'] !== 1) {
            throw new HttpException(403);
        }
        // Lösungen: mit Recht an der Quelle oder bei Freigabe im Archiv.
        $withSolutions = $this->ctx->exams->can($sourceExam, ExamAccess::CAP_SOLUTIONS) || (int) $source['shared_in_archive'] === 1;

        try {
            $newId = ExamKit::tasks($this->ctx)->copyTask($taskId, (int) $target['id'], null, $withSolutions);
            $this->ctx->audit->log('aufgabe_kopiert', 'info', 'Aufgabe #' . $taskId . ' aus Klausur #' . $sourceExam . ' nach Klausur #' . $targetId . ' kopiert (neu #' . $newId . ').');
            $this->flash('success', 'Die Aufgabe wurde in "' . $target['title'] . '" kopiert.');
        } catch (TaskValidationException | HttpException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect($back);
    }
}
