<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

/**
 * Antworttyp "code" (Java, Python, SQL). Format: docs/DATENFORMATE.md, 2.1.
 *
 * Vorgegebene Dateien stehen in task_files. Damit normalizeContent ohne
 * Datenbank auskommt, führt config zwei Listen mit, die TaskService aus
 * task_files pflegt: readonly_files (nie aus Antworten übernehmen) und
 * editable_files (bekannte bearbeitbare Dateien, relevant ohne allow_new_files).
 */
final class CodeType extends BaseTaskType
{
    public const LANGUAGES = ['java', 'python', 'sql'];
    public const COMPARE_MODES = ['exact', 'trim', 'ignore_whitespace', 'contains', 'regex'];
    public const TEST_TYPES = ['io', 'java_main', 'python_assert', 'sql_result'];
    public const MAX_FILES = 20;
    public const MAX_FILE_BYTES = 200000;
    public const MAX_TESTS = 50;
    public const MAX_SAMPLE_TESTS = 20;
    public const FILENAME_PATTERN = '/^[A-Za-z0-9_.-]{1,80}$/';

    public function name(): string
    {
        return 'code';
    }

    public function label(): string
    {
        return 'Programmcode';
    }

    public function group(): string
    {
        return 'Programmieren';
    }

    public static function defaultMainFile(string $language): string
    {
        return match ($language) {
            'python' => 'main.py',
            'sql' => 'abfrage.sql',
            default => 'Main.java',
        };
    }

    public function defaultConfig(): array
    {
        return [
            'language' => 'java',
            'java_version' => null,
            'run_mode' => null,
            'main_file' => 'Main.java',
            'allow_new_files' => false,
            'stdin' => '',
            'gui' => false,
            'sample_tests' => [],
            'sql_setup' => '',
            'python_packages' => null,
            'readonly_files' => [],
            'editable_files' => [],
        ];
    }

    public function normalizeConfig(array $config): array
    {
        $language = (string) ($config['language'] ?? 'java');
        if (!in_array($language, self::LANGUAGES, true)) {
            throw new \InvalidArgumentException('Unbekannte Programmiersprache.');
        }

        $javaVersion = $config['java_version'] ?? null;
        $javaVersion = ($javaVersion === null || $javaVersion === '') ? null : (string) $javaVersion;
        if ($javaVersion !== null && !in_array($javaVersion, ['21', '25'], true)) {
            throw new \InvalidArgumentException('Java-Version muss 21 oder 25 sein.');
        }

        $runMode = $config['run_mode'] ?? null;
        $runMode = ($runMode === null || $runMode === '') ? null : (string) $runMode;
        if ($runMode !== null && !in_array($runMode, ['run', 'syntax', 'none'], true)) {
            throw new \InvalidArgumentException('Ungültiger Ausführungsmodus.');
        }

        $mainFile = trim(self::str($config['main_file'] ?? '', 80));
        if ($mainFile === '') {
            $mainFile = self::defaultMainFile($language);
        }
        if (preg_match(self::FILENAME_PATTERN, $mainFile) !== 1) {
            throw new \InvalidArgumentException('Die Startdatei hat einen ungültigen Namen (erlaubt: Buchstaben, Ziffern, _ . -).');
        }

        $packages = $config['python_packages'] ?? null;
        if ($packages !== null) {
            $packages = is_array($packages)
                ? array_values(array_intersect(['numpy', 'matplotlib'], $packages))
                : null;
        }

        $sample = [];
        foreach (is_array($config['sample_tests'] ?? null) ? $config['sample_tests'] : [] as $i => $test) {
            if (!is_array($test)) {
                continue;
            }
            if (count($sample) >= self::MAX_SAMPLE_TESTS) {
                throw new \InvalidArgumentException('Höchstens ' . self::MAX_SAMPLE_TESTS . ' sichtbare Beispiele.');
            }
            $compare = (string) ($test['compare'] ?? 'trim');
            $sample[] = [
                'name' => self::str($test['name'] ?? '', 100) ?: 'Beispiel ' . ((int) $i + 1),
                'input' => self::str($test['input'] ?? '', 5000),
                'expected_output' => self::str($test['expected_output'] ?? '', 5000),
                'compare' => in_array($compare, self::COMPARE_MODES, true) ? $compare : 'trim',
            ];
        }

        return [
            'language' => $language,
            'java_version' => $javaVersion,
            'run_mode' => $runMode,
            'main_file' => $mainFile,
            'allow_new_files' => self::bool($config['allow_new_files'] ?? false),
            'stdin' => self::str($config['stdin'] ?? '', 10000),
            'gui' => self::bool($config['gui'] ?? false) && $language === 'java',
            'sample_tests' => $sample,
            'sql_setup' => $language === 'sql' ? self::str($config['sql_setup'] ?? '', 50000) : '',
            'python_packages' => $language === 'python' ? $packages : null,
            'readonly_files' => self::filenames($config['readonly_files'] ?? []),
            'editable_files' => self::filenames($config['editable_files'] ?? []),
        ];
    }

    public function normalizeSolution(array $solution, array $config): array
    {
        // Musterlösung und Tests liegen in model_answer bzw. tests.
        return [];
    }

    /**
     * Versteckte Tests prüfen (task_solutions.tests).
     *
     * @return list<array<string, mixed>>
     */
    public static function normalizeTests(mixed $tests, string $language): array
    {
        if (!is_array($tests)) {
            return [];
        }
        $out = [];
        foreach ($tests as $i => $test) {
            if (!is_array($test)) {
                continue;
            }
            if (count($out) >= self::MAX_TESTS) {
                throw new \InvalidArgumentException('Höchstens ' . self::MAX_TESTS . ' Tests je Aufgabe.');
            }
            $type = (string) ($test['type'] ?? 'io');
            if (!in_array($type, self::TEST_TYPES, true)) {
                throw new \InvalidArgumentException('Unbekannter Testtyp: ' . self::str($type, 40));
            }
            $needs = ['java_main' => 'java', 'python_assert' => 'python', 'sql_result' => 'sql'][$type] ?? null;
            if ($needs !== null && $needs !== $language) {
                throw new \InvalidArgumentException('Der Testtyp "' . $type . '" passt nicht zur Sprache ' . $language . '.');
            }
            $points = round((float) ($test['points'] ?? 1), 2);
            if ($points < 0 || $points > 999) {
                throw new \InvalidArgumentException('Testpunkte müssen zwischen 0 und 999 liegen.');
            }
            $entry = [
                'name' => self::str($test['name'] ?? '', 100) ?: 'Test ' . ((int) $i + 1),
                'type' => $type,
            ];
            if ($type === 'io') {
                $compare = (string) ($test['compare'] ?? 'trim');
                $entry['input'] = self::str($test['input'] ?? '', 20000);
                $entry['expected_output'] = self::str($test['expected_output'] ?? '', 20000);
                $entry['compare'] = in_array($compare, self::COMPARE_MODES, true) ? $compare : 'trim';
            } elseif ($type === 'sql_result') {
                $query = trim(self::str($test['expected_query'] ?? '', 20000));
                if ($query === '') {
                    throw new \InvalidArgumentException('Test "' . $entry['name'] . '": Die erwartete Abfrage fehlt.');
                }
                $entry['expected_query'] = $query;
                $entry['order_sensitive'] = self::bool($test['order_sensitive'] ?? false);
            } else {
                $code = self::str($test['code'] ?? '', 50000);
                if (trim($code) === '') {
                    throw new \InvalidArgumentException('Test "' . $entry['name'] . '": Der Testcode fehlt.');
                }
                $entry['code'] = $code;
            }
            $entry['points'] = $points;
            $out[] = $entry;
        }

        return $out;
    }

    /** @return list<string> */
    private static function filenames(mixed $names): array
    {
        if (!is_array($names)) {
            return [];
        }
        $out = [];
        foreach ($names as $name) {
            if (is_string($name) && preg_match(self::FILENAME_PATTERN, $name) === 1 && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return array_slice($out, 0, self::MAX_FILES);
    }

    public function normalizeContent(array $content, array $config): array
    {
        $readonly = is_array($config['readonly_files'] ?? null) ? $config['readonly_files'] : [];
        $editable = is_array($config['editable_files'] ?? null) ? $config['editable_files'] : null;
        $allowNew = (bool) ($config['allow_new_files'] ?? false);
        $mainFile = (string) ($config['main_file'] ?? '');
        $known = $editable === null ? null : array_merge($editable, $mainFile !== '' ? [$mainFile] : []);

        $files = [];
        $seen = [];
        foreach (is_array($content['files'] ?? null) ? $content['files'] : [] as $file) {
            if (!is_array($file)) {
                continue;
            }
            $name = (string) ($file['name'] ?? '');
            if (preg_match(self::FILENAME_PATTERN, $name) !== 1) {
                throw new \InvalidArgumentException('Ungültiger Dateiname: ' . self::str($name, 40));
            }
            if (in_array($name, $readonly, true) || isset($seen[$name])) {
                continue;
            }
            if (!$allowNew && $known !== null && !in_array($name, $known, true)) {
                continue;
            }
            if (count($files) >= self::MAX_FILES) {
                throw new \InvalidArgumentException('Höchstens ' . self::MAX_FILES . ' Dateien.');
            }
            $seen[$name] = true;
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
