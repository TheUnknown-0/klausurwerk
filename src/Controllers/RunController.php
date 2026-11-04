<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\ExamAccess;
use App\Services\ExamSettings;
use App\Services\LiveServices;
use App\Services\RunnerClient;

/**
 * Java-Ausführung im Runner (docs/API.md Abschnitt 2). Python und SQL laufen im Browser.
 *
 * Schüler:innen: nur für die eigene Teilnahme im Zustand active und nur für Aufgaben, deren run_mode
 * die Ausführung erlaubt; schreibgeschützte Dateien der Aufgabe ergänzt der Server selbst.
 * Lehrkräfte dürfen frei ausführen. Je Person höchstens ein laufender Lauf und 30 Starts pro Minute
 * (RunLimiter); eine run_id gehört der Person, fremde run_ids ergeben 404.
 */
final class RunController extends Controller
{
    private const FILENAME_PATTERN = '/^[A-Za-z0-9_.-]{1,80}$/';
    private const MAX_FILES = 20;
    private const MAX_FILE_BYTES = 200000;
    private const MAX_STDIN_CHARS = 20000;
    private const LIMITS = ['seconds' => 10, 'memory_mb' => 256];

    private ?LiveServices $services = null;

    private function svc(): LiveServices
    {
        return $this->services ??= new LiveServices($this->ctx);
    }

    /** POST /api/ausfuehren */
    public function start(array $params): array
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        $input = $this->jsonInput();
        $userId = (int) $user['id'];

        if (($input['language'] ?? 'java') !== 'java') {
            return $this->jsonError('Auf dem Server läuft nur Java. Python und SQL laufen direkt im Browser.', 400);
        }

        if ($this->ctx->auth->isStaff()) {
            [$payload, $examId, $taskId] = $this->teacherPayload($input);
        } elseif ($this->ctx->auth->isStudent()) {
            [$payload, $examId, $taskId] = $this->studentPayload($input, $user);
        } else {
            throw new HttpException(403);
        }

        $this->ctx->session->release();
        $limiter = $this->svc()->limiter();
        $limiter->check($userId);

        $runner = $this->runner();
        try {
            $started = $runner->start($payload);
        } catch (\RuntimeException) {
            return $this->jsonError('Die Code-Ausführung ist gerade nicht erreichbar. Bitte versuche es gleich noch einmal.', 503);
        }
        $runId = (string) ($started['run_id'] ?? '');
        if ($runId === '') {
            return $this->jsonError('Die Code-Ausführung hat keinen Lauf gestartet.', 503);
        }
        $limiter->register($runId, $userId, $examId, $taskId);
        if (random_int(1, 50) === 1) {
            $limiter->cleanup();
        }

        return ['success' => true, 'run_id' => $runId];
    }

    /** GET /api/ausfuehren/{runId}?offset=0 */
    public function poll(array $params): array
    {
        $user = $this->requireLogin();
        $run = $this->ownRun((string) $params['runId'], (int) $user['id']);
        $this->ctx->session->release();

        if ($run['exam_id'] !== null && $this->ctx->auth->isStudent() && !$this->mayKeepRunning((int) $run['exam_id'], (int) $user['id'])) {
            $this->stopQuietly((string) $run['run_id']);

            return $this->jsonError('Dein Programm wurde beendet, weil du gerade nicht arbeiten darfst.', 403);
        }

        $this->svc()->limiter()->touch((string) $run['run_id']);
        try {
            $result = $this->runner()->poll((string) $run['run_id'], max(0, (int) ($_GET['offset'] ?? 0)));
        } catch (\RuntimeException) {
            return $this->jsonError('Die Code-Ausführung ist gerade nicht erreichbar.', 503);
        }
        if (!empty($result['finished'])) {
            $this->svc()->limiter()->finish((string) $run['run_id']);
        }

        return [
            'success' => true,
            'output' => $result['output'] ?? [],
            'offset' => (int) ($result['offset'] ?? 0),
            'finished' => (bool) ($result['finished'] ?? false),
            'exit_code' => $result['exit_code'] ?? null,
            'timed_out' => (bool) ($result['timed_out'] ?? false),
            'compile_errors' => $result['compile_errors'] ?? [],
            'gui_frames' => $result['gui_frames'] ?? [],
        ];
    }

    /** POST /api/ausfuehren/{runId}/eingabe */
    public function input(array $params): array
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        $run = $this->ownRun((string) $params['runId'], (int) $user['id']);
        $this->ctx->session->release();

        $text = (string) ($this->jsonInput()['text'] ?? '');
        if (mb_strlen($text) > 10000) {
            return $this->jsonError('Die Eingabe ist zu lang.', 400);
        }
        if ($run['finished_at'] !== null) {
            return $this->jsonError('Das Programm ist bereits beendet.', 409);
        }
        try {
            $this->runner()->stdin((string) $run['run_id'], $text);
        } catch (\RuntimeException) {
            return $this->jsonError('Die Code-Ausführung ist gerade nicht erreichbar.', 503);
        }

        return ['success' => true];
    }

    /** POST /api/ausfuehren/{runId}/stopp */
    public function stop(array $params): array
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        $run = $this->ownRun((string) $params['runId'], (int) $user['id']);
        $this->ctx->session->release();

        try {
            $this->runner()->stop((string) $run['run_id']);
        } catch (\RuntimeException) {
            // Der Runner beendet unbeachtete Läufe ohnehin nach 60 s.
        }
        $this->svc()->limiter()->finish((string) $run['run_id']);

        return ['success' => true];
    }

    // ---------- intern ----------

    /** @return array<string, mixed> */
    private function ownRun(string $runId, int $userId): array
    {
        $run = $this->svc()->limiter()->find($runId, $userId);
        if ($run === null) {
            throw new HttpException(404, 'Lauf nicht gefunden.');
        }

        return $run;
    }

    private function stopQuietly(string $runId): void
    {
        try {
            $this->runner()->stop($runId);
        } catch (\RuntimeException) {
            // egal
        }
        $this->svc()->limiter()->finish($runId);
    }

    /** Läuft ein Programm weiter, solange die Teilnahme aktiv ist? */
    private function mayKeepRunning(int $examId, int $userId): bool
    {
        $state = $this->ctx->db->fetchValue('SELECT state FROM participations WHERE exam_id = ? AND user_id = ?', [$examId, $userId]);

        return $state === 'active';
    }

    private function runner(): RunnerClient
    {
        if (!class_exists(RunnerClient::class)) {
            throw new HttpException(503, 'Die Code-Ausführung ist nicht eingerichtet.');
        }
        $config = (array) ($this->ctx->config['runner'] ?? []);
        if (method_exists(RunnerClient::class, 'fromConfig')) {
            return RunnerClient::fromConfig($config);
        }

        // Konstruktorargumente nach Namen und Typ füllen (Runner-Modul legt die Signatur fest).
        $ctor = (new \ReflectionClass(RunnerClient::class))->getConstructor();
        $args = [];
        foreach ($ctor?->getParameters() ?? [] as $param) {
            $name = strtolower($param->getName());
            $type = $param->getType() instanceof \ReflectionNamedType ? $param->getType()->getName() : '';
            $args[] = match (true) {
                $type === 'array' => $config,
                str_contains($name, 'url') => $config['url'] ?? 'http://runner:8080',
                str_contains($name, 'token') => $config['token'] ?? '',
                str_contains($name, 'timeout') => $config['timeout_seconds'] ?? 20,
                $param->isDefaultValueAvailable() => $param->getDefaultValue(),
                default => null,
            };
        }

        return new RunnerClient(...$args);
    }

    /**
     * Lehrkräfte: frei, optional mit Klausur- und Aufgabenbezug (dann Recht "view" und Aufgabendateien).
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: ?int, 2: ?int}
     */
    private function teacherPayload(array $input): array
    {
        $examId = isset($input['exam_id']) && is_numeric($input['exam_id']) ? (int) $input['exam_id'] : null;
        $taskId = isset($input['task_id']) && is_numeric($input['task_id']) ? (int) $input['task_id'] : null;
        $settings = ExamSettings::defaults();
        $readonly = [];

        if ($examId !== null) {
            $exam = $this->ctx->exams->require($examId, ExamAccess::CAP_VIEW);
            $settings = ExamSettings::normalize(ExamAccess::settingsOf($exam), (string) $exam['mode']);
            if ($taskId !== null) {
                $task = $this->ctx->db->fetchOne('SELECT id FROM tasks WHERE id = ? AND exam_id = ?', [$taskId, $examId]);
                if ($task === null) {
                    throw new HttpException(404, 'Aufgabe nicht gefunden.');
                }
                $readonly = $this->taskFiles($taskId, true);
            }
        } else {
            $taskId = null;
        }

        $files = $this->mergeFiles($input['files'] ?? null, $readonly, [], true);
        $version = (string) ($input['java_version'] ?? $settings['java_version']);
        if (!in_array($version, ExamSettings::JAVA_VERSIONS, true)) {
            $version = (string) $settings['java_version'];
        }
        $mode = ($input['mode'] ?? 'run') === 'syntax' ? 'syntax' : 'run';
        $main = $this->mainFile($input['main_file'] ?? null, 'Main.java', $files);

        return [[
            'language' => 'java',
            'java_version' => $version,
            'files' => $files,
            'main_file' => $main,
            'mode' => $mode,
            'stdin' => $this->stdin($input['stdin'] ?? null),
            'gui' => !empty($input['gui']),
            'limits' => self::LIMITS,
        ], $examId, $taskId];
    }

    /**
     * Schüler:innen: Teilnahme, Aufgabe, run_mode und Dateien serverseitig prüfen.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $user
     * @return array{0: array<string, mixed>, 1: int, 2: int}
     */
    private function studentPayload(array $input, array $user): array
    {
        $examId = (int) ($input['exam_id'] ?? 0);
        $taskId = (int) ($input['task_id'] ?? 0);
        if ($examId < 1 || $taskId < 1) {
            throw new HttpException(400, 'Klausur und Aufgabe fehlen.');
        }
        $db = $this->ctx->db;
        $exam = $db->fetchOne('SELECT * FROM exams WHERE id = ?', [$examId]);
        if ($exam === null) {
            throw new HttpException(404, 'Klausur nicht gefunden.');
        }
        $member = $exam['course_id'] !== null
            && $db->fetchValue('SELECT 1 FROM course_members WHERE course_id = ? AND user_id = ?', [(int) $exam['course_id'], (int) $user['id']]) !== null;
        if (!$member) {
            throw new HttpException(403, 'Du gehörst nicht zum Kurs dieser Klausur.');
        }
        $participation = $db->fetchOne('SELECT * FROM participations WHERE exam_id = ? AND user_id = ?', [$examId, (int) $user['id']]);
        $this->svc()->seb()->enforce($exam, $participation);
        if ($participation === null || (string) $participation['state'] !== 'active' || (string) $exam['status'] !== 'running') {
            throw new HttpException(403, 'Du kannst Programme nur ausführen, solange du an der Klausur arbeitest.');
        }

        $task = $db->fetchOne(
            "SELECT id, answer_type, config FROM tasks WHERE id = ? AND exam_id = ? AND answer_type IN ('code', 'robo')",
            [$taskId, $examId],
        );
        if ($task === null) {
            throw new HttpException(404, 'Aufgabe nicht gefunden.');
        }
        $config = json_decode((string) $task['config'], true);
        $config = is_array($config) ? $config : [];
        if (($config['language'] ?? 'java') !== 'java') {
            throw new HttpException(400, 'Diese Aufgabe läuft im Browser, nicht auf dem Server.');
        }

        $settings = ExamSettings::normalize(ExamAccess::settingsOf($exam), (string) $exam['mode']);
        $runMode = (string) ($config['run_mode'] ?? $settings['run_mode']);
        $mode = ($input['mode'] ?? 'run') === 'syntax' ? 'syntax' : 'run';
        $allowed = $runMode === 'run' || ($runMode === 'syntax' && $mode === 'syntax');
        if (!$allowed) {
            throw new HttpException(403, $runMode === 'none'
                ? 'In dieser Aufgabe ist das Ausführen nicht erlaubt.'
                : 'In dieser Aufgabe ist nur die Syntaxprüfung erlaubt.');
        }

        $readonlyFiles = $this->taskFiles($taskId, true);
        $editableNames = array_column($this->taskFiles($taskId, false), 'name');
        $files = $this->mergeFiles($input['files'] ?? null, $readonlyFiles, $editableNames, !empty($config['allow_new_files']));
        $main = $this->mainFile($input['main_file'] ?? null, (string) ($config['main_file'] ?? 'Main.java'), $files);

        $stdin = $this->stdin($input['stdin'] ?? null);
        if ($stdin === null && (string) ($config['stdin'] ?? '') !== '') {
            $stdin = mb_substr((string) $config['stdin'], 0, self::MAX_STDIN_CHARS);
        }
        $version = (string) ($config['java_version'] ?? $settings['java_version']);
        if (!in_array($version, ExamSettings::JAVA_VERSIONS, true)) {
            $version = (string) $settings['java_version'];
        }

        return [[
            'language' => 'java',
            'java_version' => $version,
            'files' => $files,
            'main_file' => $main,
            'mode' => $mode,
            'stdin' => $stdin,
            'gui' => !empty($config['gui']) && !empty($input['gui']),
            'limits' => self::LIMITS,
        ], $examId, $taskId];
    }

    /**
     * Vorgegebene Dateien einer Aufgabe.
     *
     * @return list<array{name: string, content: string}>
     */
    private function taskFiles(int $taskId, bool $readonly): array
    {
        $rows = $this->ctx->db->fetchAll(
            'SELECT filename, content FROM task_files WHERE task_id = ? AND is_readonly = ? ORDER BY position, id',
            [$taskId, $readonly ? 1 : 0],
        );

        return array_map(static fn (array $r): array => ['name' => (string) $r['filename'], 'content' => (string) $r['content']], $rows);
    }

    /**
     * Schülerdateien prüfen und mit den schreibgeschützten Dateien des Servers zusammenführen.
     * Der Server hat Vorrang: eine mitgeschickte Datei mit dem Namen einer schreibgeschützten wird ignoriert.
     *
     * @param list<array{name: string, content: string}> $readonly
     * @param list<string> $editableNames bekannte bearbeitbare Dateien der Aufgabe
     * @return list<array{name: string, content: string}>
     */
    private function mergeFiles(mixed $given, array $readonly, array $editableNames, bool $allowNew): array
    {
        if (!is_array($given) || $given === []) {
            throw new HttpException(400, 'Es wurden keine Dateien übergeben.');
        }
        $readonlyNames = array_column($readonly, 'name');
        $files = [];
        foreach ($given as $file) {
            $name = is_array($file) ? ($file['name'] ?? null) : null;
            $content = is_array($file) ? ($file['content'] ?? null) : null;
            if (!is_string($name) || !is_string($content) || preg_match(self::FILENAME_PATTERN, $name) !== 1) {
                throw new HttpException(400, 'Ungültiger Dateiname oder Inhalt.');
            }
            if (strlen($content) > self::MAX_FILE_BYTES) {
                throw new HttpException(400, 'Die Datei ' . $name . ' ist zu groß.');
            }
            if (in_array($name, $readonlyNames, true)) {
                continue;
            }
            if (!$allowNew && !in_array($name, $editableNames, true)) {
                throw new HttpException(400, 'In dieser Aufgabe können keine neuen Dateien angelegt werden (' . $name . ').');
            }
            $files[$name] = $content;
        }
        $out = [];
        foreach ($readonly as $file) {
            $out[$file['name']] = $file['content'];
        }
        foreach ($files as $name => $content) {
            $out[$name] = $content;
        }
        if (count($out) > self::MAX_FILES) {
            throw new HttpException(400, 'Zu viele Dateien (höchstens ' . self::MAX_FILES . ').');
        }

        $list = [];
        foreach ($out as $name => $content) {
            $list[] = ['name' => (string) $name, 'content' => $content];
        }

        return $list;
    }

    /** @param list<array{name: string, content: string}> $files */
    private function mainFile(mixed $given, string $default, array $files): string
    {
        $main = is_string($given) && $given !== '' ? $given : $default;
        if (!in_array($main, array_column($files, 'name'), true)) {
            throw new HttpException(400, 'Die Startdatei ' . $main . ' fehlt.');
        }

        return $main;
    }

    private function stdin(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, self::MAX_STDIN_CHARS);
    }
}
