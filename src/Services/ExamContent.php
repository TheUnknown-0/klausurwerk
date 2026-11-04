<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\TaskTypes\TaskTypeRegistry;

/**
 * Aufgabenbaum und Zusatzdaten für Schüleransicht, Vorschau und Pult (docs/API.md 1.3, 3.3).
 *
 * Liest ausschließlich tasks, task_files, exam_materials, operators und die eigenen answers.
 * Musterlösungen, Erwartungshorizont und Tests liegen in einer eigenen Tabelle, die hier nie
 * angefasst wird (SolutionLeakTest prüft das statisch).
 */
final class ExamContent
{
    /** @var callable(string): ?TaskTypes\TaskType */
    private $typeResolver;

    /** @param (callable(string): ?TaskTypes\TaskType)|null $typeResolver Auflösung des Antworttyps (Standard: TaskTypeRegistry) */
    public function __construct(
        private readonly Database $db,
        private readonly string $baseUrl = '',
        ?callable $typeResolver = null,
    ) {
        $this->typeResolver = $typeResolver ?? TaskTypeRegistry::get(...);
    }

    /**
     * Antwort für GET /api/klausur/{id}/inhalt (Schüler:in).
     *
     * @param array<string, mixed> $exam
     * @param array<string, mixed> $participation
     * @return array<string, mixed>
     */
    public function forStudent(array $exam, array $participation): array
    {
        $settings = ExamAccess::settingsOf($exam);
        $seed = (int) $participation['shuffle_seed'];
        $shuffle = !empty($settings['shuffle_choices']);

        return [
            'success' => true,
            'exam' => $this->examHeader($exam, $settings),
            'tasks' => $this->tasks($exam, $seed, $shuffle),
            'answers' => $this->answers((int) $participation['id']),
            'materials' => $this->materials($exam),
            'operators' => $this->operators($settings),
            'last_seq' => (int) $participation['last_seq'],
        ];
    }

    /**
     * Vorschau für Lehrkräfte: ohne Teilnahme, ohne Mischen, ohne gespeicherte Antworten.
     *
     * @param array<string, mixed> $exam
     * @return array<string, mixed>
     */
    public function forPreview(array $exam): array
    {
        $settings = ExamAccess::settingsOf($exam);

        return [
            'success' => true,
            'exam' => $this->examHeader($exam, $settings),
            'tasks' => $this->tasks($exam, 0, false),
            'answers' => (object) [],
            'materials' => $this->materials($exam),
            'operators' => $this->operators($settings),
            'last_seq' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $exam
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function examHeader(array $exam, array $settings): array
    {
        return [
            'id' => (int) $exam['id'],
            'title' => (string) $exam['title'],
            'mode' => (string) $exam['mode'],
            'settings' => ExamSettings::forStudent($settings),
        ];
    }

    /**
     * Aufgabenbaum mit Nummerierung. $shuffle = false mischt nichts (Vorschau, Pult).
     *
     * @param array<string, mixed> $exam
     * @return list<array<string, mixed>>
     */
    public function tasks(array $exam, int $shuffleSeed, bool $shuffle): array
    {
        $examId = (int) $exam['id'];
        $rows = $this->db->fetchAll(
            'SELECT t.id, t.parent_id, t.position, t.title, t.body_md, t.points, t.afb, t.answer_type, t.config, o.name AS operator_name
             FROM tasks t LEFT JOIN operators o ON o.id = t.operator_id
             WHERE t.exam_id = ?',
            [$examId],
        );
        if ($rows === []) {
            return [];
        }

        $filesByTask = [];
        $files = $this->db->fetchAll(
            'SELECT f.task_id, f.filename, f.content, f.is_readonly
             FROM task_files f JOIN tasks t ON t.id = f.task_id
             WHERE t.exam_id = ? ORDER BY f.position, f.id',
            [$examId],
        );
        foreach ($files as $file) {
            $filesByTask[(int) $file['task_id']][] = [
                'name' => (string) $file['filename'],
                'content' => (string) $file['content'],
                'readonly' => (int) $file['is_readonly'] === 1,
            ];
        }

        $markdown = new Markdown($this->baseUrl, $examId);
        $build = function (array $nodes) use (&$build, $markdown, $filesByTask, $shuffleSeed, $shuffle): array {
            $out = [];
            foreach ($nodes as $node) {
                $id = (int) $node['id'];
                $out[] = [
                    'id' => $id,
                    'parent_id' => $node['parent_id'] !== null ? (int) $node['parent_id'] : null,
                    'number' => (string) $node['number'],
                    'title' => (string) $node['title'],
                    'body_html' => $markdown->render((string) $node['body_md']),
                    'points' => (float) $node['points'],
                    'operator' => $node['operator_name'] !== null ? (string) $node['operator_name'] : null,
                    'afb' => $node['afb'] !== null ? (string) $node['afb'] : null,
                    'answer_type' => $node['answer_type'] !== null ? (string) $node['answer_type'] : null,
                    'config' => $this->studentConfig($node, $shuffleSeed, $shuffle),
                    'files' => $filesByTask[$id] ?? [],
                    'children' => $build($node['children']),
                ];
            }

            return $out;
        };

        return $build(TaskTree::build($rows));
    }

    /**
     * Schülersichtige Konfiguration einer Aufgabe (gemischt über Seed = shuffle_seed + task_id).
     *
     * @param array<string, mixed> $task
     * @return array<string, mixed>|\stdClass
     */
    private function studentConfig(array $task, int $shuffleSeed, bool $shuffle): array|\stdClass
    {
        if ($task['answer_type'] === null) {
            return (object) [];
        }
        $config = json_decode((string) $task['config'], true);
        $config = is_array($config) ? $config : [];
        $type = ($this->typeResolver)((string) $task['answer_type']);
        if ($type === null) {
            return $config === [] ? (object) [] : $config;
        }
        if (!$shuffle && array_key_exists('shuffle', $config)) {
            $config['shuffle'] = false;
        }
        $seed = ($shuffleSeed + (int) $task['id']) & 0x7FFFFFFF;
        $result = $type->studentConfig($config, $seed);

        return $result === [] ? (object) [] : $result;
    }

    /**
     * Gespeicherte Antworten einer Teilnahme: task_id => {content, version, flagged}.
     *
     * @return array<int, array<string, mixed>>|\stdClass
     */
    public function answers(int $participationId): array|\stdClass
    {
        $rows = $this->db->fetchAll(
            'SELECT task_id, content, version, flagged FROM answers WHERE participation_id = ?',
            [$participationId],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['task_id']] = [
                'content' => self::decodeContent((string) $row['content']),
                'version' => (int) $row['version'],
                'flagged' => (int) $row['flagged'] === 1,
            ];
        }

        return $out === [] ? (object) [] : $out;
    }

    /** Antwortinhalt als Objekt dekodieren (leer bleibt {} statt []). */
    public static function decodeContent(string $json): array|\stdClass
    {
        $content = json_decode($json, true);
        if (!is_array($content) || $content === []) {
            return (object) [];
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $exam
     * @return list<array<string, mixed>>
     */
    public function materials(array $exam): array
    {
        $examId = (int) $exam['id'];
        $rows = $this->db->fetchAll(
            'SELECT id, original_name, mime, is_aid FROM exam_materials WHERE exam_id = ? ORDER BY id',
            [$examId],
        );

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['original_name'],
            'url' => $this->baseUrl . '/klausur/' . $examId . '/material/' . (int) $row['id'],
            'is_aid' => (int) $row['is_aid'] === 1,
            'mime' => (string) $row['mime'],
        ], $rows);
    }

    /**
     * Operatorenliste, nur wenn die Klausur sie als Hilfsmittel erlaubt.
     *
     * @param array<string, mixed> $settings
     * @return list<array<string, string>>
     */
    public function operators(array $settings): array
    {
        if (empty($settings['aids']['operators'])) {
            return [];
        }
        $rows = $this->db->fetchAll('SELECT name, afb, description FROM operators ORDER BY sort_order, name');

        return array_map(static fn (array $r): array => [
            'name' => (string) $r['name'],
            'afb' => (string) $r['afb'],
            'description' => (string) $r['description'],
        ], $rows);
    }

    /**
     * Bootstrap-Block der Klausurseite (docs/API.md 1, Abschnitt "exam-boot").
     *
     * @param array<string, mixed> $exam
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function boot(array $exam, array $user, string $baseUrl, bool $preview, bool $needsAccessCode, bool $sebDetected): array
    {
        $settings = ExamAccess::settingsOf($exam);

        return [
            'exam_id' => (int) $exam['id'],
            'mode' => (string) $exam['mode'],
            'preview' => $preview,
            'title' => (string) $exam['title'],
            'user' => ['name' => display_name($user)],
            'api_base' => $baseUrl . '/api/klausur/' . (int) $exam['id'],
            'needs_access_code' => $needsAccessCode,
            'seb' => ['required' => !empty($settings['require_seb']), 'detected' => $sebDetected],
            'assets' => [
                'pyodide' => $baseUrl . '/assets/vendor/pyodide/',
                'sqljs' => $baseUrl . '/assets/vendor/sqljs/',
                'aids' => $baseUrl . '/assets/aids/',
            ],
        ];
    }
}
