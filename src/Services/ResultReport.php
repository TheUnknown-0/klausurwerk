<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\TaskTypes\TaskTypeRegistry;

/**
 * Ergebnisbericht einer Teilnahme: Aufgaben, eigene Antworten, Punkte, Kommentare, Note.
 *
 * Grundlage der Schüler-Rückgabe und der PDFs. Der Bericht enthält ausschließlich Aufgabendaten
 * für Schüler:innen (wie ExamContent), die eigenen Antworten und die Bewertung. Musterlösung,
 * Tests und richtige Antworten kommen hier nie vor.
 *
 * Kommentare: Sichtbar sind die der Lehrkraft, deren Punkte als Endwert zählen. Gibt es für eine
 * Aufgabe noch keinen Endwert, sehen Schüler:innen keine Kommentare (Lehrkräfte im PDF alle).
 */
final class ResultReport
{
    public function __construct(
        private readonly Database $db,
        private readonly GradingService $grading,
        private readonly string $baseUrl = '',
    ) {
    }

    /**
     * @param array<string, mixed> $exam
     * @param array<string, mixed> $participation
     * @param bool $forStudent true = ohne Kommentare ohne Endwert
     * @param bool $withPreview Zeichenvorschauen (PNG) mitliefern (PDF)
     * @return array<string, mixed>
     */
    public function build(array $exam, array $participation, bool $forStudent, bool $withPreview = false): array
    {
        $examId = (int) $exam['id'];
        $pid = (int) $participation['id'];
        $ownerId = $exam['owner_id'] !== null ? (int) $exam['owner_id'] : null;
        $settings = ExamAccess::settingsOf($exam);

        $content = new ExamContent($this->db, $this->baseUrl);
        $tree = $content->tasks($exam, (int) $participation['shuffle_seed'], !empty($settings['shuffle_choices']));

        $answers = [];
        foreach ($this->db->fetchAll(
            'SELECT task_id, content, has_overtime' . ($withPreview ? ', preview_png' : '') . ' FROM answers WHERE participation_id = ?',
            [$pid],
        ) as $row) {
            $decoded = json_decode((string) $row['content'], true);
            $answers[(int) $row['task_id']] = [
                'content' => is_array($decoded) ? $decoded : [],
                'overtime' => (int) $row['has_overtime'] === 1,
                'preview' => $withPreview ? ($row['preview_png'] ?? null) : null,
            ];
        }
        $entries = $this->grading->entriesByCell($examId, null, $pid)[$pid] ?? [];
        $comments = [];
        foreach ($this->db->fetchAll(
            'SELECT c.id, c.task_id, c.grader_id, c.anchor, c.body, u.firstname, u.lastname, u.username
             FROM grading_comments c JOIN users u ON u.id = c.grader_id
             WHERE c.participation_id = ? ORDER BY c.id',
            [$pid],
        ) as $row) {
            $comments[(int) $row['task_id']][] = [
                'id' => (int) $row['id'],
                'grader_id' => (int) $row['grader_id'],
                'grader_name' => display_name($row),
                'anchor' => json_decode((string) $row['anchor'], true) ?: ['kind' => 'task'],
                'body' => (string) $row['body'],
            ];
        }

        $tasks = [];
        $this->walk($tree, 0, $tasks, $entries, $answers, $comments, $ownerId, $forStudent);

        $totals = $this->grading->computeTotals($exam, [$pid])[$pid];
        $saved = $this->db->fetchOne('SELECT general_comment, finalized_at FROM participation_results WHERE participation_id = ?', [$pid]) ?? [];
        $course = $exam['course_id'] !== null
            ? $this->db->fetchValue('SELECT name FROM courses WHERE id = ?', [(int) $exam['course_id']])
            : null;
        $user = $this->db->fetchOne('SELECT firstname, lastname, username FROM users WHERE id = ?', [(int) $participation['user_id']]) ?? [];
        $scale = $this->grading->scaleFor($exam);

        return [
            'exam' => [
                'id' => $examId,
                'title' => (string) $exam['title'],
                'course' => $course !== null ? (string) $course : '',
                'date' => (string) ($exam['started_at'] ?? $exam['scheduled_at'] ?? ''),
            ],
            'person' => ['name' => display_name($user), 'username' => (string) ($user['username'] ?? '')],
            'summary' => [
                'total' => $totals['total'],
                'max' => $totals['max'],
                'percent' => $totals['percent'],
                'grade_label' => $totals['grade_label'],
                'scale_type' => $scale['type'] ?? null,
                'general_comment' => (string) ($saved['general_comment'] ?? ''),
                'finalized_at' => $saved['finalized_at'] ?? null,
            ],
            'tasks' => $tasks,
        ];
    }

    /**
     * PDF für eine oder mehrere Teilnahmen (je Person neue Seite).
     *
     * @param array<string, mixed> $exam
     * @param list<array<string, mixed>> $participations
     */
    public function renderPdf(array $exam, array $participations, string $schoolName, bool $forStudent = false): string
    {
        $reports = [];
        foreach ($participations as $p) {
            $reports[] = $this->build($exam, $p, $forStudent, true);
        }

        return (new ResultPdf($schoolName))->render($reports);
    }

    /**
     * Läuft durch den Aufgabenbaum und hängt Antwort, Punkte und Kommentare an.
     *
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $out
     * @param array<int, list<array<string, mixed>>> $entries
     * @param array<int, array<string, mixed>> $answers
     * @param array<int, list<array<string, mixed>>> $comments
     */
    private function walk(array $nodes, int $depth, array &$out, array $entries, array $answers, array $comments, ?int $ownerId, bool $forStudent): void
    {
        foreach ($nodes as $node) {
            $id = (int) $node['id'];
            $children = $node['children'] ?? [];
            unset($node['children']);
            $node['depth'] = $depth;
            $node['earned'] = null;
            $node['answer'] = null;
            $node['comments'] = [];
            $node['overtime'] = false;
            $node['preview_png'] = null;
            $node['answer_text'] = '';
            if ($node['answer_type'] !== null) {
                $final = GradingService::finalEntry($entries[$id] ?? [], $ownerId);
                $node['earned'] = $final['points'];
                $answer = $answers[$id] ?? null;
                if ($answer !== null) {
                    $node['answer'] = $answer['content'];
                    $node['overtime'] = $answer['overtime'];
                    $node['preview_png'] = $answer['preview'];
                    $type = TaskTypeRegistry::get((string) $node['answer_type']);
                    if ($type !== null && $answer['content'] !== []) {
                        try {
                            $config = is_array($node['config']) ? $node['config'] : (array) $node['config'];
                            $node['answer_text'] = $type->toText($answer['content'], $config);
                        } catch (\Throwable) {
                            $node['answer_text'] = '';
                        }
                    }
                }
                foreach ($comments[$id] ?? [] as $c) {
                    if ($final['grader_id'] !== null) {
                        if ($c['grader_id'] === $final['grader_id']) {
                            $node['comments'][] = $c;
                        }
                    } elseif (!$forStudent) {
                        $node['comments'][] = $c;
                    }
                }
            }
            $out[] = $node;
            $this->walk($children, $depth + 1, $out, $entries, $answers, $comments, $ownerId, $forStudent);
        }
    }
}
