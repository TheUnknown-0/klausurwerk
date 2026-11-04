<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\TaskTypes\TaskType;
use App\Services\TaskTypes\TaskTypeRegistry;

/**
 * Automatische Vorbewertung (auto_gradings): Auswahl, Zuordnung, Reihenfolge und Lücken über
 * TaskType::autoCheck, Code-Aufgaben mit versteckten Tests über den Runner.
 *
 * Der Lauf geschieht in kleinen Stapeln (step), damit ein Request kurz bleibt: Die Oberfläche ruft
 * step() wiederholt auf und zeigt den Rest an. Fehler (z. B. Runner nicht erreichbar) werden je
 * Aufgabe und Teilnahme vermerkt (suggested_points NULL, details.source = 'error') und lassen sich
 * später gezielt wiederholen.
 *
 * Dieser Dienst liest task_solutions (nur Lehrkräfte-Controller nach CAP_GRADE rufen ihn auf).
 */
final class AutoGrader
{
    /** Zeitbudget eines Stapels in Sekunden (ein Runner-Lauf kann einige Sekunden dauern). */
    public const DEFAULT_BUDGET_SECONDS = 20.0;
    public const DEFAULT_BATCH = 10;
    private const OUTPUT_CLIP = 2000;
    private const RUNNER_LANGUAGES = ['java', 'python', 'sql'];

    /** @var callable(string): ?TaskType */
    private $typeResolver;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $taskCache = null;

    /**
     * @param (callable(string): ?TaskType)|null $typeResolver Auflösung des Antworttyps (Standard: TaskTypeRegistry)
     */
    public function __construct(
        private readonly Database $db,
        private readonly ?RunnerClient $runner = null,
        ?callable $typeResolver = null,
    ) {
        $this->typeResolver = $typeResolver ?? TaskTypeRegistry::get(...);
    }

    // ---------- reine Rechnung ----------

    /**
     * Punktzahl und Details aus den Ergebnissen versteckter Tests.
     *
     * Hat mindestens ein Test Punkte, zählt die Summe der erreichten Testpunkte (gedeckelt auf die
     * Aufgabenpunkte). Haben alle Tests 0 Punkte oder keine Angabe, zählt der Anteil bestandener Tests.
     * Das Ergebnis wird auf 0,5 gerundet.
     *
     * @param list<array<string, mixed>> $tests Testbeschreibungen (name, points, ...)
     * @param list<array<string, mixed>> $results Antwort des Runners in Reihenfolge der Tests
     * @return array{suggested: float, details: array<string, mixed>}
     */
    public static function codeScore(array $tests, array $results, float $taskPoints): array
    {
        $total = count($tests);
        $passed = 0;
        $sumPoints = 0.0;
        $earned = 0.0;
        $items = [];
        foreach (array_values($tests) as $i => $test) {
            $result = is_array($results[$i] ?? null) ? $results[$i] : null;
            $ok = $result !== null && !empty($result['ok']);
            $points = max(0.0, (float) ($test['points'] ?? 0));
            $sumPoints += $points;
            if ($ok) {
                $passed++;
                $earned += $points;
            }
            $items[] = [
                'name' => (string) ($result['name'] ?? $test['name'] ?? 'Test ' . ($i + 1)),
                'ok' => $ok,
                'points' => $points,
                'earned' => $ok ? $points : 0.0,
                'message' => self::clip((string) ($result['message'] ?? ($result === null ? 'Kein Ergebnis vom Runner.' : ''))),
                'stdout' => self::clip((string) ($result['stdout'] ?? '')),
                'stderr' => self::clip((string) ($result['stderr'] ?? '')),
            ];
        }

        if ($sumPoints > 1e-9) {
            $raw = min($earned, $taskPoints);
        } else {
            $raw = $total > 0 ? $taskPoints * $passed / $total : 0.0;
        }
        $suggested = max(0.0, min($taskPoints, GradingService::roundHalf($raw)));

        return [
            'suggested' => $suggested,
            'details' => [
                'source' => 'tests',
                'passed' => $passed,
                'total' => $total,
                'earned' => round($earned, 2),
                'max_points' => round($sumPoints, 2),
                'results' => $items,
            ],
        ];
    }

    /** Punkte = Aufgabenpunkte mal Anteil, auf 0,5 gerundet und auf 0 bis Aufgabenpunkte begrenzt. */
    public static function suggestedFromRatio(float $taskPoints, float $ratio): float
    {
        $ratio = max(0.0, min(1.0, $ratio));

        return max(0.0, min($taskPoints, GradingService::roundHalf($taskPoints * $ratio)));
    }

    private static function clip(string $text): string
    {
        return mb_strlen($text) > self::OUTPUT_CLIP ? mb_substr($text, 0, self::OUTPUT_CLIP) . "\n[... gekürzt]" : $text;
    }

    // ---------- Stapel ----------

    /**
     * Aufgaben, die automatisch bewertet werden können, samt Lösungsdaten.
     *
     * @return array<int, array<string, mixed>> task_id => Aufgabe (points, answer_type, config, solution, tests, files)
     */
    public function autoTasks(int $examId): array
    {
        if ($this->taskCache !== null && ($this->taskCache['exam'] ?? null) === $examId) {
            return $this->taskCache['tasks'];
        }
        $rows = $this->db->fetchAll(
            'SELECT t.id, t.points, t.answer_type, t.config, s.solution, s.tests
             FROM tasks t LEFT JOIN task_solutions s ON s.task_id = t.id
             WHERE t.exam_id = ? AND t.answer_type IS NOT NULL',
            [$examId],
        );
        $tasks = [];
        foreach ($rows as $row) {
            $task = [
                'id' => (int) $row['id'],
                'points' => (float) $row['points'],
                'answer_type' => (string) $row['answer_type'],
                'config' => json_decode((string) $row['config'], true) ?: [],
                'solution' => json_decode((string) ($row['solution'] ?? '{}'), true) ?: [],
                'tests' => json_decode((string) ($row['tests'] ?? '[]'), true) ?: [],
            ];
            if ($this->isAuto($task)) {
                $tasks[$task['id']] = $task;
            }
        }
        $this->taskCache = ['exam' => $examId, 'tasks' => $tasks];

        return $tasks;
    }

    /** @param array<string, mixed> $task */
    public function isAuto(array $task): bool
    {
        if ($task['answer_type'] === 'code') {
            $language = (string) ($task['config']['language'] ?? 'java');

            return in_array($language, self::RUNNER_LANGUAGES, true) && $task['tests'] !== [];
        }
        $type = ($this->typeResolver)((string) $task['answer_type']);
        if ($type === null || $task['answer_type'] === 'robo') {
            return false;
        }
        try {
            return $type->autoCheck($task['config'], $task['solution'], []) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Zählt Paare (Teilnahme x automatisch prüfbare Aufgabe) nach Stand.
     *
     * @return array{total: int, done: int, pending: int, errors: int}
     */
    public function status(int $examId): array
    {
        $tasks = $this->autoTasks($examId);
        $people = (int) $this->db->fetchValue('SELECT COUNT(*) FROM participations WHERE exam_id = ?', [$examId]);
        $total = $people * count($tasks);
        if ($total === 0) {
            return ['total' => 0, 'done' => 0, 'pending' => 0, 'errors' => 0];
        }
        $ids = array_keys($tasks);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $row = $this->db->fetchOne(
            "SELECT SUM(a.suggested_points IS NOT NULL) AS done, SUM(a.suggested_points IS NULL) AS errors
             FROM auto_gradings a JOIN participations p ON p.id = a.participation_id
             WHERE p.exam_id = ? AND a.task_id IN ($in)",
            array_merge([$examId], $ids),
        );
        $done = (int) ($row['done'] ?? 0);
        $errors = (int) ($row['errors'] ?? 0);

        return ['total' => $total, 'done' => $done, 'pending' => max(0, $total - $done - $errors), 'errors' => $errors];
    }

    /**
     * Löscht Vorbewertungen, damit sie neu berechnet werden.
     *
     * @param 'all'|'errors' $which
     */
    public function reset(int $examId, string $which = 'errors'): void
    {
        $sql = 'DELETE a FROM auto_gradings a JOIN participations p ON p.id = a.participation_id WHERE p.exam_id = ?';
        if ($which === 'errors') {
            $sql .= ' AND a.suggested_points IS NULL';
        }
        $this->db->run($sql, [$examId]);
    }

    /**
     * Verarbeitet bis zu $limit offene Paare innerhalb des Zeitbudgets.
     *
     * @param array<string, mixed> $exam
     * @return array{processed: int, errors: int, remaining: int, total: int, aborted: bool, message: ?string}
     */
    public function step(array $exam, int $limit = self::DEFAULT_BATCH, float $budgetSeconds = self::DEFAULT_BUDGET_SECONDS): array
    {
        $examId = (int) $exam['id'];
        $tasks = $this->autoTasks($examId);
        $started = microtime(true);
        $processed = 0;
        $errors = 0;
        $aborted = false;
        $message = null;

        if ($tasks !== []) {
            $existing = [];
            foreach ($this->db->fetchAll(
                'SELECT a.participation_id, a.task_id FROM auto_gradings a JOIN participations p ON p.id = a.participation_id WHERE p.exam_id = ?',
                [$examId],
            ) as $r) {
                $existing[(int) $r['participation_id'] . ':' . (int) $r['task_id']] = true;
            }
            $answers = $this->answersByPair($examId);

            foreach ($this->db->fetchAll('SELECT * FROM participations WHERE exam_id = ? ORDER BY id', [$examId]) as $p) {
                foreach ($tasks as $task) {
                    if (isset($existing[(int) $p['id'] . ':' . $task['id']])) {
                        continue;
                    }
                    if ($processed >= $limit || (microtime(true) - $started) >= $budgetSeconds) {
                        break 2;
                    }
                    $result = $this->gradePair($exam, $task, $p, $answers[(int) $p['id']][$task['id']] ?? null);
                    $this->store((int) $p['id'], $task['id'], $result);
                    $processed++;
                    if ($result['suggested'] === null) {
                        $errors++;
                        if (!empty($result['details']['runner_down'])) {
                            $aborted = true;
                            $message = (string) $result['details']['error'];
                            break 2;
                        }
                    }
                }
            }
        }
        $status = $this->status($examId);

        return [
            'processed' => $processed,
            'errors' => $errors,
            'remaining' => $status['pending'],
            'total' => $status['total'],
            'aborted' => $aborted,
            'message' => $message,
        ];
    }

    /**
     * Eine Aufgabe einer Teilnahme neu bewerten (z. B. nach geänderten Tests).
     *
     * @param array<string, mixed> $exam
     * @return array{suggested: ?float, details: array<string, mixed>}|null null, wenn die Aufgabe nicht automatisch prüfbar ist
     */
    public function retest(array $exam, int $participationId, int $taskId): ?array
    {
        $examId = (int) $exam['id'];
        $task = $this->autoTasks($examId)[$taskId] ?? null;
        $p = $this->db->fetchOne('SELECT * FROM participations WHERE id = ? AND exam_id = ?', [$participationId, $examId]);
        if ($task === null || $p === null) {
            return null;
        }
        $answers = $this->answersByPair($examId, $participationId);
        $result = $this->gradePair($exam, $task, $p, $answers[$participationId][$taskId] ?? null);
        $this->store($participationId, $taskId, $result);

        return $result;
    }

    // ---------- Einzelbewertung ----------

    /**
     * @param array<string, mixed> $exam
     * @param array<string, mixed> $task
     * @param array<string, mixed> $participation
     * @param array<string, mixed>|null $content gespeicherte Antwort (null = keine Antwort)
     * @return array{suggested: ?float, details: array<string, mixed>}
     */
    public function gradePair(array $exam, array $task, array $participation, ?array $content): array
    {
        $type = ($this->typeResolver)($task['answer_type']);
        if ($type === null) {
            return ['suggested' => null, 'details' => ['source' => 'error', 'error' => 'Unbekannter Antworttyp.']];
        }
        if ($content === null || !$type->isAnswered($content)) {
            return ['suggested' => 0.0, 'details' => ['source' => 'empty', 'ratio' => 0.0]];
        }

        if ($task['answer_type'] === 'code') {
            return $this->gradeCode($exam, $task, $content);
        }

        try {
            $check = $type->autoCheck($task['config'], $task['solution'], $content);
        } catch (\Throwable $e) {
            return ['suggested' => null, 'details' => ['source' => 'error', 'error' => 'Die Antwort ließ sich nicht prüfen.']];
        }
        if ($check === null) {
            return ['suggested' => null, 'details' => ['source' => 'error', 'error' => 'Für diese Aufgabe gibt es keine automatische Prüfung.']];
        }

        return [
            'suggested' => self::suggestedFromRatio($task['points'], (float) $check['ratio']),
            'details' => $check['details'],
        ];
    }

    /**
     * @param array<string, mixed> $exam
     * @param array<string, mixed> $task
     * @param array<string, mixed> $content
     * @return array{suggested: ?float, details: array<string, mixed>}
     */
    private function gradeCode(array $exam, array $task, array $content): array
    {
        if ($this->runner === null) {
            return ['suggested' => null, 'details' => ['source' => 'error', 'error' => 'Die Ausführungsumgebung ist nicht eingerichtet.', 'runner_down' => true]];
        }
        $config = $task['config'];
        $language = (string) ($config['language'] ?? 'java');
        $files = $this->codeFiles($task['id'], $content);
        $main = (string) ($config['main_file'] ?? '');
        $names = array_column($files, 'name');
        if ($main === '' || !in_array($main, $names, true)) {
            $main = self::guessMain($language, $names);
        }
        if ($files === []) {
            return ['suggested' => 0.0, 'details' => ['source' => 'empty', 'ratio' => 0.0]];
        }
        if ($language !== 'java' && ($main === null || !in_array($main, $names, true))) {
            return ['suggested' => null, 'details' => ['source' => 'error', 'error' => 'Die Startdatei fehlt in der Abgabe.']];
        }

        $settings = ExamAccess::settingsOf($exam);
        $version = (string) ($config['java_version'] ?? $settings['java_version'] ?? '21');
        $tests = array_values($task['tests']);
        $payload = [
            'language' => $language,
            'java_version' => $version,
            'files' => $files,
            'main_file' => $main,
            'tests' => $tests,
            'sql_setup' => (string) ($config['sql_setup'] ?? ''),
            'limits' => ['seconds' => 10, 'memory_mb' => 256, 'total_seconds' => min(300, 20 + count($tests) * 13)],
            'reveal' => true,
        ];

        try {
            $response = $this->runner->tests($payload);
        } catch (\RuntimeException $e) {
            // Status 0 = Runner nicht erreichbar oder antwortet nicht: weitere Versuche im selben Stapel sind sinnlos.
            return ['suggested' => null, 'details' => [
                'source' => 'error',
                'error' => $e->getMessage(),
                'runner_down' => $e->getCode() === 0 || $e->getCode() >= 500,
            ]];
        }

        $score = self::codeScore($tests, array_values($response['results']), $task['points']);

        return ['suggested' => $score['suggested'], 'details' => $score['details']];
    }

    /**
     * Dateien für den Runner: schreibgeschützte Aufgabendateien zuerst, dann die Dateien der Abgabe.
     * Eine Abgabe kann schreibgeschützte Dateien nie ersetzen.
     *
     * @param array<string, mixed> $content
     * @return list<array{name: string, content: string}>
     */
    public function codeFiles(int $taskId, array $content): array
    {
        $files = [];
        foreach ($this->db->fetchAll(
            'SELECT filename, content FROM task_files WHERE task_id = ? AND is_readonly = 1 ORDER BY position, id',
            [$taskId],
        ) as $row) {
            $files[(string) $row['filename']] = (string) $row['content'];
        }
        $readonly = array_keys($files);
        foreach (is_array($content['files'] ?? null) ? $content['files'] : [] as $f) {
            if (!is_array($f) || !isset($f['name']) || in_array((string) $f['name'], $readonly, true)) {
                continue;
            }
            $files[(string) $f['name']] = (string) ($f['content'] ?? '');
        }
        $out = [];
        foreach ($files as $name => $text) {
            $out[] = ['name' => (string) $name, 'content' => $text];
        }

        return $out;
    }

    /** @param list<string> $names */
    private static function guessMain(string $language, array $names): ?string
    {
        $ext = ['java' => '.java', 'python' => '.py', 'sql' => '.sql'][$language] ?? '';
        foreach ($names as $name) {
            if ($ext !== '' && str_ends_with($name, $ext)) {
                return $name;
            }
        }

        return $names[0] ?? null;
    }

    // ---------- Daten ----------

    /**
     * @return array<int, array<int, array<string, mixed>>> participation_id => task_id => Inhalt
     */
    private function answersByPair(int $examId, ?int $participationId = null): array
    {
        $sql = 'SELECT a.participation_id, a.task_id, a.content FROM answers a JOIN participations p ON p.id = a.participation_id WHERE p.exam_id = ?';
        $params = [$examId];
        if ($participationId !== null) {
            $sql .= ' AND a.participation_id = ?';
            $params[] = $participationId;
        }
        $out = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $content = json_decode((string) $row['content'], true);
            $out[(int) $row['participation_id']][(int) $row['task_id']] = is_array($content) ? $content : [];
        }

        return $out;
    }

    /** @param array{suggested: ?float, details: array<string, mixed>} $result */
    private function store(int $participationId, int $taskId, array $result): void
    {
        $this->db->run(
            'INSERT INTO auto_gradings (participation_id, task_id, suggested_points, details) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE suggested_points = VALUES(suggested_points), details = VALUES(details)',
            [
                $participationId,
                $taskId,
                $result['suggested'],
                json_encode($result['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            ],
        );
    }
}
