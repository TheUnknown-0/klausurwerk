<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\TaskTypes\CodeType;
use App\Services\TaskTypes\TaskTypeRegistry;

/**
 * Export und Import einer Klausur als ZIP:
 *   klausur.json   format "klausurwerk-export", version 1: Metadaten (ohne Personen und
 *                  Teilnahmen), Einstellungen, Aufgabenbaum mit Dateien und Lösungen,
 *                  Material-Metadaten
 *   materials/<datei>  die Materialdateien
 * Der Import erzeugt immer eine neue Entwurfsklausur und prüft alles über TaskTypes
 * und ExamSettings.
 */
final class ExamExport
{
    public const FORMAT = 'klausurwerk-export';
    public const VERSION = 1;
    private const MAX_JSON_BYTES = 30 * 1024 * 1024;
    private const MAX_ENTRIES = 600;
    private const MAX_UNCOMPRESSED = 400 * 1024 * 1024;

    public function __construct(
        private readonly Database $db,
        private readonly TaskService $tasks,
        private readonly Materials $materials,
    ) {
    }

    // ---------- Export ----------

    /**
     * Baut das Export-Dokument (ohne Dateien).
     *
     * @param array<string, mixed> $exam
     * @return array<string, mixed>
     */
    public function document(array $exam): array
    {
        $examId = (int) $exam['id'];
        $operators = [];
        foreach ($this->db->fetchAll('SELECT id, name FROM operators') as $op) {
            $operators[(int) $op['id']] = (string) $op['name'];
        }

        $list = $this->tasks->fullList($examId);
        $nodes = [];
        foreach ($list as $task) {
            $nodes[(int) $task['id']] = [
                'key' => (string) $task['id'],
                'title' => $task['title'],
                'body_md' => $task['body_md'],
                'points' => $task['points'],
                'operator' => $task['operator_id'] !== null ? ($operators[$task['operator_id']] ?? null) : null,
                'afb' => $task['afb'],
                'answer_type' => $task['answer_type'],
                'config' => (object) $task['config'],
                'files' => $task['files'],
                'solution' => [
                    'solution' => (object) $task['solution'],
                    'model_answer' => $task['model_answer'],
                    'expectation_md' => $task['expectation_md'],
                    'tests' => $task['tests'],
                ],
                'children' => [],
            ];
        }
        $tree = [];
        foreach ($list as $task) {
            $id = (int) $task['id'];
            if ($task['parent_id'] === null) {
                $tree[] = &$nodes[$id];
            } else {
                $nodes[$task['parent_id']]['children'][] = &$nodes[$id];
            }
        }
        unset($node);

        $materials = [];
        foreach ($this->materials->forExam($examId) as $m) {
            $materials[] = [
                'key' => (string) $m['id'],
                'original_name' => $m['original_name'],
                'file' => 'materials/' . $m['stored_name'],
                'mime' => $m['mime'],
                'size_bytes' => (int) $m['size_bytes'],
                'is_aid' => (int) $m['is_aid'] === 1,
                'task_key' => $m['task_id'] !== null ? (string) $m['task_id'] : null,
            ];
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => date('c'),
            'exam' => [
                'title' => $exam['title'],
                'description' => $exam['description'],
                'mode' => $exam['mode'],
                'duration_minutes' => $exam['duration_minutes'] !== null ? (int) $exam['duration_minutes'] : null,
            ],
            'settings' => ExamAccess::settingsOf($exam),
            'tasks' => $tree,
            'materials' => $materials,
        ];
    }

    /**
     * Schreibt das ZIP in eine temporäre Datei und liefert deren Pfad (Aufrufer löscht sie).
     *
     * @param array<string, mixed> $exam
     */
    public function exportToFile(array $exam): string
    {
        $doc = $this->document($exam);
        $path = tempnam(sys_get_temp_dir(), 'kwx');
        if ($path === false) {
            throw new \RuntimeException('Temporäre Datei konnte nicht angelegt werden.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE | \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('ZIP-Datei konnte nicht angelegt werden.');
        }
        $zip->addFromString('klausur.json', json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        foreach ($this->materials->forExam((int) $exam['id']) as $m) {
            $file = $this->materials->pathFor($m);
            if (is_file($file)) {
                $zip->addFile($file, 'materials/' . $m['stored_name']);
            }
        }
        $zip->close();

        return $path;
    }

    // ---------- Import ----------

    /**
     * Importiert ein Export-ZIP als neue Entwurfsklausur.
     *
     * @return int ID der neuen Klausur
     * @throws \InvalidArgumentException bei fremdem oder fehlerhaftem Format
     */
    public function importFromFile(string $zipPath, int $ownerId, ?int $courseId = null): int
    {
        $zip = new \ZipArchive();
        if (!is_file($zipPath) || $zip->open($zipPath) !== true) {
            throw new \InvalidArgumentException('Die Datei ist kein gültiges ZIP-Archiv.');
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new \InvalidArgumentException('Das Archiv enthält zu viele Dateien.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new \InvalidArgumentException('Das Archiv ist beschädigt.');
                }
                $name = (string) $stat['name'];
                if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                    throw new \InvalidArgumentException('Das Archiv enthält ungültige Dateipfade.');
                }
                $total += (int) $stat['size'];
            }
            if ($total > self::MAX_UNCOMPRESSED) {
                throw new \InvalidArgumentException('Das Archiv ist entpackt zu groß.');
            }
            $stat = $zip->statName('klausur.json');
            if ($stat === false) {
                throw new \InvalidArgumentException('Das ist keine Klausurwerk-Exportdatei (klausur.json fehlt).');
            }
            if ((int) $stat['size'] > self::MAX_JSON_BYTES) {
                throw new \InvalidArgumentException('klausur.json ist zu groß.');
            }
            $json = $zip->getFromName('klausur.json');
            $doc = is_string($json) ? json_decode($json, true) : null;
            if (!is_array($doc) || ($doc['format'] ?? null) !== self::FORMAT) {
                throw new \InvalidArgumentException('Das ist keine Klausurwerk-Exportdatei (Format unbekannt).');
            }
            if ((int) ($doc['version'] ?? 0) !== self::VERSION) {
                throw new \InvalidArgumentException('Diese Exportversion wird nicht unterstützt (erwartet: Version ' . self::VERSION . ').');
            }

            return $this->importDocument($doc, $zip, $ownerId, $courseId);
        } finally {
            $zip->close();
        }
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function importDocument(array $doc, \ZipArchive $zip, int $ownerId, ?int $courseId): int
    {
        $examIn = is_array($doc['exam'] ?? null) ? $doc['exam'] : throw new \InvalidArgumentException('Die Klausurdaten fehlen.');
        $mode = (string) ($examIn['mode'] ?? 'exam');
        if (!isset(ExamService::MODES[$mode])) {
            throw new \InvalidArgumentException('Ungültiger Modus in der Exportdatei.');
        }
        $title = trim(mb_substr((string) ($examIn['title'] ?? ''), 0, 200));
        if ($title === '') {
            throw new \InvalidArgumentException('Die Klausur in der Exportdatei hat keinen Titel.');
        }
        $duration = $examIn['duration_minutes'] ?? null;
        $duration = $duration === null ? null : max(1, min(600, (int) $duration));
        $settings = ExamSettings::normalize(is_array($doc['settings'] ?? null) ? $doc['settings'] : [], $mode);
        if ($courseId !== null && $this->db->fetchValue('SELECT id FROM courses WHERE id = ?', [$courseId]) === null) {
            $courseId = null;
        }

        // Alles vorab prüfen und normalisieren, bevor etwas geschrieben wird.
        $operators = [];
        foreach ($this->db->fetchAll('SELECT id, name FROM operators') as $op) {
            $operators[mb_strtolower((string) $op['name'])] = (int) $op['id'];
        }
        $prepared = $this->prepareTasks(is_array($doc['tasks'] ?? null) ? $doc['tasks'] : [], $operators, 0);
        $materialsIn = $this->prepareMaterials(is_array($doc['materials'] ?? null) ? $doc['materials'] : [], $zip);

        return (int) $this->db->transaction(function () use ($title, $examIn, $courseId, $ownerId, $mode, $duration, $settings, $prepared, $materialsIn, $zip): int {
            $this->db->run(
                'INSERT INTO exams (title, description, course_id, owner_id, mode, status, duration_minutes, settings)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $title,
                    ($d = trim(mb_substr((string) ($examIn['description'] ?? ''), 0, 5000))) === '' ? null : $d,
                    $courseId, $ownerId, $mode, 'draft', $duration,
                    json_encode($settings, JSON_UNESCAPED_UNICODE),
                ],
            );
            $examId = $this->db->lastInsertId();

            // Material zuerst (für die Umschreibung der Verweise).
            $materialMap = [];
            $tmpFiles = [];
            try {
                foreach ($materialsIn as $m) {
                    $tmp = tempnam(sys_get_temp_dir(), 'kwm');
                    $tmpFiles[] = $tmp;
                    file_put_contents($tmp, $zip->getFromName($m['file']));
                    $row = $this->materials->storeLocal($examId, $tmp, $m['original_name'], null, $m['is_aid']);
                    $materialMap[$m['key']] = (int) $row['id'];
                }
            } catch (\Throwable $e) {
                $this->cleanupMaterials($examId);
                throw $e;
            } finally {
                foreach ($tmpFiles as $f) {
                    @unlink($f);
                }
            }

            $taskMap = [];
            $this->insertTasks($examId, null, $prepared, $materialMap, $taskMap);

            foreach ($materialsIn as $m) {
                if ($m['task_key'] !== null && isset($taskMap[$m['task_key']])) {
                    $this->db->run('UPDATE exam_materials SET task_id = ? WHERE id = ?', [$taskMap[$m['task_key']], $materialMap[$m['key']]]);
                }
            }
            $this->tasks->recomputePoints($examId);

            return $examId;
        });
    }

    private function cleanupMaterials(int $examId): void
    {
        $this->materials->deleteFilesOfExam($examId);
    }

    /**
     * @param array<int|string, mixed> $list
     * @param array<string, int> $operators
     * @return list<array<string, mixed>>
     */
    private function prepareTasks(array $list, array $operators, int $depth): array
    {
        if ($depth > 1 && $list !== []) {
            throw new \InvalidArgumentException('Aufgaben sind zu tief verschachtelt (erlaubt: Aufgabe und Teilaufgabe).');
        }
        $out = [];
        foreach ($list as $node) {
            if (!is_array($node)) {
                throw new \InvalidArgumentException('Ungültiger Aufgabeneintrag.');
            }
            if (count($out) > TaskService::MAX_TASKS_PER_EXAM) {
                throw new \InvalidArgumentException('Zu viele Aufgaben.');
            }
            $title = mb_substr((string) ($node['title'] ?? ''), 0, 200);
            $where = 'Aufgabe "' . ($title !== '' ? $title : (string) ($node['key'] ?? '?')) . '"';
            $answerType = $node['answer_type'] ?? null;
            $answerType = ($answerType === null || $answerType === '') ? null : (string) $answerType;
            $children = $this->prepareTasks(is_array($node['children'] ?? null) ? $node['children'] : [], $operators, $depth + 1);

            $config = [];
            $solution = [];
            $tests = [];
            $files = [];
            $sol = is_array($node['solution'] ?? null) ? $node['solution'] : [];
            if ($answerType !== null) {
                $type = TaskTypeRegistry::get($answerType);
                if ($type === null) {
                    throw new \InvalidArgumentException("{$where}: Antworttyp \"" . mb_substr($answerType, 0, 40) . '" ist unbekannt.');
                }
                if ($children !== []) {
                    throw new \InvalidArgumentException("{$where}: Aufgaben mit Teilaufgaben dürfen keinen Antworttyp haben.");
                }
                try {
                    $rawConfig = is_array($node['config'] ?? null) ? $node['config'] : [];
                    if ($answerType === 'code') {
                        $files = $this->tasks->normalizeFiles($node['files'] ?? []);
                        $rawConfig['readonly_files'] = array_values(array_map(fn (array $f): string => $f['filename'], array_filter($files, fn (array $f): bool => $f['is_readonly'])));
                        $rawConfig['editable_files'] = array_values(array_map(fn (array $f): string => $f['filename'], array_filter($files, fn (array $f): bool => !$f['is_readonly'])));
                    }
                    $config = $type->normalizeConfig($rawConfig);
                    try {
                        $solution = $type->normalizeSolution(is_array($sol['solution'] ?? null) ? $sol['solution'] : [], $config);
                    } catch (\InvalidArgumentException) {
                        $solution = $type->defaultSolution();
                    }
                    if ($answerType === 'code') {
                        $tests = CodeType::normalizeTests($sol['tests'] ?? [], (string) $config['language']);
                    }
                } catch (\InvalidArgumentException $e) {
                    throw new \InvalidArgumentException("{$where}: " . $e->getMessage());
                }
            }
            $afb = $node['afb'] ?? null;
            $operator = $node['operator'] ?? null;
            $out[] = [
                'key' => (string) ($node['key'] ?? ''),
                'title' => $title,
                'body_md' => mb_substr((string) ($node['body_md'] ?? ''), 0, 100000),
                'points' => max(0, min(999, round((float) ($node['points'] ?? 0), 2))),
                'operator_id' => is_string($operator) ? ($operators[mb_strtolower($operator)] ?? null) : null,
                'afb' => (is_string($afb) && in_array($afb, TaskService::AFB_VALUES, true)) ? $afb : null,
                'answer_type' => $answerType,
                'config' => $config,
                'files' => $files,
                'solution' => $solution,
                'model_answer' => mb_substr((string) ($sol['model_answer'] ?? ''), 0, 100000),
                'expectation_md' => mb_substr((string) ($sol['expectation_md'] ?? ''), 0, 100000),
                'tests' => $tests,
                'children' => $children,
            ];
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $list
     * @return list<array{key: string, original_name: string, file: string, is_aid: bool, task_key: ?string}>
     */
    private function prepareMaterials(array $list, \ZipArchive $zip): array
    {
        $out = [];
        foreach ($list as $m) {
            if (!is_array($m)) {
                continue;
            }
            $file = (string) ($m['file'] ?? '');
            if (preg_match('#^materials/[A-Za-z0-9_.-]{1,120}$#', $file) !== 1 || $zip->locateName($file) === false) {
                throw new \InvalidArgumentException('Material "' . mb_substr((string) ($m['original_name'] ?? '?'), 0, 60) . '" fehlt im Archiv.');
            }
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, Materials::EXTENSIONS, true)) {
                throw new \InvalidArgumentException('Nicht erlaubter Material-Dateityp: ' . $ext);
            }
            $out[] = [
                'key' => (string) ($m['key'] ?? ''),
                'original_name' => mb_substr((string) ($m['original_name'] ?? basename($file)), 0, 255),
                'file' => $file,
                'is_aid' => !empty($m['is_aid']),
                'task_key' => isset($m['task_key']) && $m['task_key'] !== null ? (string) $m['task_key'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param array<string, int> $materialMap
     * @param array<string, int> $taskMap
     */
    private function insertTasks(int $examId, ?int $parentId, array $nodes, array $materialMap, array &$taskMap): void
    {
        $rewrite = static fn (string $text): string => (string) preg_replace_callback(
            '/material:(\d+)/',
            static fn (array $m): string => isset($materialMap[$m[1]]) ? 'material:' . $materialMap[$m[1]] : $m[0],
            $text,
        );
        foreach ($nodes as $position => $n) {
            $this->db->run(
                'INSERT INTO tasks (exam_id, parent_id, position, title, body_md, points, operator_id, afb, answer_type, config)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $examId, $parentId, $position, $n['title'], $rewrite($n['body_md']), $n['points'], $n['operator_id'], $n['afb'],
                    $n['answer_type'], $n['config'] === [] ? '{}' : json_encode($n['config'], JSON_UNESCAPED_UNICODE),
                ],
            );
            $id = $this->db->lastInsertId();
            if ($n['key'] !== '') {
                $taskMap[$n['key']] = $id;
            }
            foreach ($n['files'] as $i => $f) {
                $this->db->run(
                    'INSERT INTO task_files (task_id, filename, content, is_readonly, position) VALUES (?, ?, ?, ?, ?)',
                    [$id, $f['filename'], $f['content'], $f['is_readonly'] ? 1 : 0, $i],
                );
            }
            $this->db->run(
                'INSERT INTO task_solutions (task_id, solution, model_answer, expectation_md, tests) VALUES (?, ?, ?, ?, ?)',
                [
                    $id,
                    $n['solution'] === [] ? '{}' : json_encode($n['solution'], JSON_UNESCAPED_UNICODE),
                    $rewrite($n['model_answer']), $rewrite($n['expectation_md']),
                    json_encode($n['tests'], JSON_UNESCAPED_UNICODE),
                ],
            );
            $this->insertTasks($examId, $id, $n['children'], $materialMap, $taskMap);
        }
    }
}
