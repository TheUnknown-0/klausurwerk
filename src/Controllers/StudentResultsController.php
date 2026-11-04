<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\AccessCards;
use App\Services\GradingScales;
use App\Services\GradingService;
use App\Services\Markdown;
use App\Services\ResultReport;

/**
 * Digitale Rückgabe für Schüler:innen: Liste und Einzelansicht der eigenen Ergebnisse.
 *
 * Sichtbar ist ein Ergebnis nur, wenn die Klausur zurückgegeben ist (status returned) UND die eigene
 * Teilnahme abgeschlossen wurde (finalized_at). Gelesen wird ausschließlich die eigene Teilnahme.
 *
 * Musterlösung, Tests und richtige Antworten gibt es hier nie. Einzige Ausnahme ist der Erwartungshorizont
 * (expectation_md), und nur, wenn die Lehrkraft ihn für diese Klausur freigegeben hat
 * (exams.show_expectation_on_return). SolutionLeakTest prüft genau das.
 */
final class StudentResultsController extends Controller
{
    private function baseUrl(): string
    {
        return (string) ($this->ctx->config['app']['base_url'] ?? '');
    }

    private function report(): ResultReport
    {
        $db = $this->ctx->db;

        return new ResultReport($db, new GradingService($db, new GradingScales($db)), $this->baseUrl());
    }

    /** GET /meine-ergebnisse */
    public function index(array $params): string
    {
        $user = $this->requireStudent();
        $rows = $this->ctx->db->fetchAll(
            "SELECT e.id, e.title, e.returned_at, c.name AS course_name,
                    r.total_points, r.max_points, r.percent, r.grade_label, r.finalized_at
             FROM participations p
             JOIN exams e ON e.id = p.exam_id
             LEFT JOIN courses c ON c.id = e.course_id
             LEFT JOIN participation_results r ON r.participation_id = p.id
             WHERE p.user_id = ? AND e.status = 'returned'
             ORDER BY e.returned_at DESC, e.id DESC",
            [(int) $user['id']],
        );

        return $this->render('pages/student-results/index', [
            'title' => 'Meine Ergebnisse',
            'rows' => $rows,
        ]);
    }

    /** GET /meine-ergebnisse/{id} */
    public function show(array $params): string
    {
        $user = $this->requireStudent();
        [$exam, $participation] = $this->releasedResult((int) $params['id'], (int) $user['id']);
        $examId = (int) $exam['id'];
        $report = $this->report()->build($exam, $participation, true);

        $expectations = (int) ($exam['show_expectation_on_return'] ?? 0) === 1 ? $this->expectations($examId) : [];

        $tasks = [];
        foreach ($report['tasks'] as $t) {
            $tasks[] = [
                'id' => $t['id'],
                'parent_id' => $t['parent_id'],
                'number' => $t['number'],
                'depth' => $t['depth'],
                'title' => $t['title'],
                'body_html' => $t['body_html'],
                'points' => $t['points'],
                'afb' => $t['afb'],
                'operator' => $t['operator'],
                'answer_type' => $t['answer_type'],
                'config' => $t['config'],
                'files' => $t['files'],
                'earned' => $t['earned'],
                'answer' => $t['answer'] === null ? null : ($t['answer'] === [] ? (object) [] : $t['answer']),
                'comments' => array_map(static fn (array $c): array => ['id' => $c['id'], 'anchor' => $c['anchor'], 'body' => $c['body']], $t['comments']),
                'expectation_html' => $expectations[$t['id']] ?? '',
            ];
        }

        return $this->render('pages/student-results/show', [
            'title' => 'Ergebnis: ' . (string) $exam['title'],
            'exam' => $exam,
            'summary' => $report['summary'],
            'showExpectation' => $expectations !== [],
            'boot' => [
                'exam_id' => $examId,
                'title' => (string) $exam['title'],
                'summary' => $report['summary'],
                'tasks' => $tasks,
                'show_expectation' => $expectations !== [],
            ],
            'bundles' => ['grading.js'],
            'pageStyles' => ['grading.css', 'exam.css', 'code.css'],
        ]);
    }

    /** GET /meine-ergebnisse/{id}/pdf */
    public function pdf(array $params): never
    {
        $user = $this->requireStudent();
        [$exam, $participation] = $this->releasedResult((int) $params['id'], (int) $user['id']);
        $this->ctx->session->release();
        $bytes = $this->report()->renderPdf($exam, [$participation], (string) ($this->ctx->settings->get('school_name') ?? ''), true);
        AccessCards::sendPdf($bytes, 'ergebnis-' . (string) $exam['title'] . '.pdf');
    }

    // ---------- intern ----------

    /**
     * Klausur und eigene Teilnahme, nur wenn zurückgegeben und eigener Abschluss vorliegt.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function releasedResult(int $examId, int $userId): array
    {
        $db = $this->ctx->db;
        $participation = $db->fetchOne('SELECT * FROM participations WHERE exam_id = ? AND user_id = ?', [$examId, $userId]);
        $exam = $participation === null ? null : $db->fetchOne('SELECT * FROM exams WHERE id = ?', [$examId]);
        if ($participation === null || $exam === null) {
            throw new HttpException(404, 'Dieses Ergebnis gibt es nicht.');
        }
        if ((string) $exam['status'] !== 'returned') {
            throw new HttpException(403, 'Die Ergebnisse dieser Klausur sind noch nicht freigegeben.');
        }
        $finalized = $db->fetchValue('SELECT finalized_at FROM participation_results WHERE participation_id = ?', [(int) $participation['id']]);
        if ($finalized === null) {
            throw new HttpException(403, 'Deine Korrektur ist noch nicht abgeschlossen. Schau später noch einmal vorbei.');
        }

        return [$exam, $participation];
    }

    /**
     * Erwartungshorizont je Aufgabe als HTML, nur nach Freigabe durch die Lehrkraft (Aufrufer prüft das Flag).
     *
     * @return array<int, string>
     */
    private function expectations(int $examId): array
    {
        $markdown = new Markdown($this->baseUrl(), $examId);
        $out = [];
        foreach ($this->ctx->db->fetchAll(
            'SELECT s.task_id, s.expectation_md FROM task_solutions s JOIN tasks t ON t.id = s.task_id WHERE t.exam_id = ?',
            [$examId],
        ) as $row) {
            if (trim((string) $row['expectation_md']) !== '') {
                $out[(int) $row['task_id']] = $markdown->render((string) $row['expectation_md']);
            }
        }

        return $out;
    }
}
