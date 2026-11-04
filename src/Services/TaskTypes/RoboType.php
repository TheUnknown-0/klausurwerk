<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

use App\Services\Robo\RoboWorld;

/**
 * Antworttyp "robo" (Gitterwelt, Stufe 4). Format: docs/DATENFORMATE.md, 2.16.
 *
 * config:   language (java|python), java_version, run_mode, main_file, start_code, goal_md, world
 *           (zusätzlich allow_new_files = true, damit der RunController die Startdatei annimmt, die bei
 *           Robo-Aufgaben nicht in task_files steht)
 * content:  { files: [{ name, content }] } nur die Startdatei, wie bei "code"
 * solution: { tests: [...] }  Rückfallebene für die Testwelten (siehe normalizeSolution)
 * tests:    [{ name, type: "robo_world", world, goal, points }] in task_solutions.tests, laufen im Runner (POST /robo)
 *
 * Die Prüfung der Welt steckt in Services\Robo\RoboWorld (dieselben Regeln wie frontend/src/robo/world.js
 * und runner/libs/python/robo.py).
 */
final class RoboType extends BaseTaskType
{
    public const LANGUAGES = ['java', 'python'];
    public const MAX_TESTS = 20;
    public const MAX_START_CODE = 20000;
    public const MAX_FILE_BYTES = 200000;
    public const FILENAME_PATTERN = '/^[A-Za-z0-9_.-]{1,80}$/';
    /** Namen der Bibliotheken: eine Startdatei so zu nennen würde `import robo` verdecken. */
    public const RESERVED_FILES = ['robo.py', 'Robo.java', 'RoboFehler.java', 'robo_world.json'];

    public function name(): string
    {
        return 'robo';
    }

    public function label(): string
    {
        return 'Robo (Gitterwelt)';
    }

    public function group(): string
    {
        return 'Mikrowelt';
    }

    public static function defaultMainFile(string $language): string
    {
        return $language === 'java' ? 'Main.java' : 'main.py';
    }

    public function defaultConfig(): array
    {
        return [
            'language' => 'python',
            'java_version' => null,
            'run_mode' => null,
            'main_file' => 'main.py',
            'allow_new_files' => true,
            'start_code' => '',
            'goal_md' => '',
            'world' => [
                'cols' => 10,
                'rows' => 8,
                'robo' => ['x' => 0, 'y' => 0, 'dir' => 'E'],
                'walls' => [],
                'boxes' => [],
                'screws' => [],
                'targets' => [],
            ],
        ];
    }

    public function normalizeConfig(array $config): array
    {
        $language = (string) ($config['language'] ?? 'python');
        if (!in_array($language, self::LANGUAGES, true)) {
            throw new \InvalidArgumentException('Robo gibt es nur für Java und Python.');
        }

        $javaVersion = $config['java_version'] ?? null;
        $javaVersion = ($javaVersion === null || $javaVersion === '') ? null : (string) $javaVersion;
        if ($javaVersion !== null && !in_array($javaVersion, ['21', '25'], true)) {
            throw new \InvalidArgumentException('Java-Version muss 21 oder 25 sein.');
        }
        if ($language !== 'java') {
            $javaVersion = null;
        }

        $runMode = $config['run_mode'] ?? null;
        $runMode = ($runMode === null || $runMode === '') ? null : (string) $runMode;
        if ($runMode !== null && !in_array($runMode, ['run', 'syntax', 'none'], true)) {
            throw new \InvalidArgumentException('Ungültiger Ausführungsmodus.');
        }

        $main = trim(self::str($config['main_file'] ?? '', 80));
        if ($main === '') {
            $main = self::defaultMainFile($language);
        }
        if (preg_match(self::FILENAME_PATTERN, $main) !== 1) {
            throw new \InvalidArgumentException('Die Startdatei hat einen ungültigen Namen (erlaubt: Buchstaben, Ziffern, _ . -).');
        }
        // So heißen die Bibliotheken: das Schülerprogramm würde sie verdecken.
        if ($main === 'robo.py') {
            $main = 'main.py';
        } elseif ($main === 'Robo.java' || $main === 'RoboFehler.java') {
            $main = 'Main.java';
        }
        $extension = $language === 'java' ? '.java' : '.py';
        if (!str_ends_with(strtolower($main), $extension)) {
            throw new \InvalidArgumentException('Die Startdatei muss bei ' . ($language === 'java' ? 'Java auf .java' : 'Python auf .py') . ' enden.');
        }

        return [
            'language' => $language,
            'java_version' => $javaVersion,
            'run_mode' => $runMode,
            'main_file' => $main,
            'allow_new_files' => true,
            'start_code' => self::str($config['start_code'] ?? '', self::MAX_START_CODE),
            'goal_md' => self::str($config['goal_md'] ?? '', 2000),
            'world' => RoboWorld::normalize($config['world'] ?? null),
        ];
    }

    /**
     * Die Lösungsseite ist bei Robo die Liste der Testwelten. Der Server speichert sie bevorzugt in
     * task_solutions.tests (TaskService, wie bei "code"); diese Rückfallebene in task_solutions.solution
     * sorgt dafür, dass sie auch dann nicht verloren gehen, wenn das nicht eingerichtet ist.
     */
    public function normalizeSolution(array $solution, array $config): array
    {
        return ['tests' => self::normalizeTests($solution['tests'] ?? [])];
    }

    public function defaultSolution(): array
    {
        return ['tests' => []];
    }

    /**
     * Testwelten prüfen (task_solutions.tests, nie an Schüler:innen).
     *
     * @return list<array<string, mixed>>
     */
    public static function normalizeTests(mixed $tests): array
    {
        if (!is_array($tests)) {
            return [];
        }
        $out = [];
        foreach (array_values($tests) as $i => $test) {
            if (!is_array($test)) {
                continue;
            }
            if (count($out) >= self::MAX_TESTS) {
                throw new \InvalidArgumentException('Höchstens ' . self::MAX_TESTS . ' Testwelten je Aufgabe.');
            }
            $type = (string) ($test['type'] ?? 'robo_world');
            if ($type !== 'robo_world') {
                throw new \InvalidArgumentException('Unbekannter Testtyp: ' . self::str($type, 40));
            }
            $name = self::str($test['name'] ?? '', 100);
            $name = trim($name) !== '' ? trim($name) : 'Welt ' . ($i + 1);
            try {
                $world = RoboWorld::normalize($test['world'] ?? null);
                $goal = RoboWorld::normalizeGoal($test['goal'] ?? [], $world);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException('Testwelt "' . $name . '": ' . $e->getMessage());
            }
            $points = round((float) ($test['points'] ?? 1), 2);
            if ($points < 0 || $points > 999) {
                throw new \InvalidArgumentException('Testpunkte müssen zwischen 0 und 999 liegen.');
            }
            $out[] = ['name' => $name, 'type' => 'robo_world', 'world' => $world, 'goal' => $goal, 'points' => $points];
        }

        return $out;
    }

    public function studentConfig(array $config, int $seed): array
    {
        // Die Welt der Aufgabe ist für Schüler:innen sichtbar, die Testwelten stehen nie in config.
        return $config;
    }

    public function normalizeContent(array $content, array $config): array
    {
        $main = (string) ($config['main_file'] ?? '');
        $files = [];
        foreach (is_array($content['files'] ?? null) ? $content['files'] : [] as $file) {
            if (!is_array($file)) {
                continue;
            }
            $name = (string) ($file['name'] ?? '');
            if (preg_match(self::FILENAME_PATTERN, $name) !== 1) {
                throw new \InvalidArgumentException('Ungültiger Dateiname: ' . self::str($name, 40));
            }
            // Bei Robo gibt es nur die Startdatei; alles andere (auch die Bibliotheksnamen) wird verworfen.
            if ($name !== $main || in_array($name, self::RESERVED_FILES, true) || $files !== []) {
                continue;
            }
            $files[] = ['name' => $name, 'content' => self::str($file['content'] ?? '', self::MAX_FILE_BYTES)];
        }

        $out = ['files' => $files];
        $this->assertSize($out);

        return $out;
    }

    public function isAnswered(array $content): bool
    {
        foreach ($content['files'] ?? [] as $file) {
            if (is_array($file) && trim((string) ($file['content'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    public function autoCheck(array $config, array $solution, array $content): ?array
    {
        // Die Testwelten laufen in der Korrektur im Runner (POST /robo), nicht hier.
        return null;
    }

    public function toText(array $content, array $config): string
    {
        $parts = [];
        foreach ($content['files'] ?? [] as $file) {
            if (is_array($file)) {
                $parts[] = '// ' . ($file['name'] ?? '') . "\n" . ($file['content'] ?? '');
            }
        }

        return implode("\n\n", $parts);
    }
}
