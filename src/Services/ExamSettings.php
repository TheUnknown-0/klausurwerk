<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Klausur-Einstellungen (exams.settings): Standardwerte und Normalisierung.
 * Format: docs/DATENFORMATE.md, Abschnitt 1.
 */
final class ExamSettings
{
    public const PASTE_POLICIES = ['internal', 'block', 'allow_log'];
    public const RUN_MODES = ['run', 'syntax', 'none'];
    public const JAVA_VERSIONS = ['21', '25'];
    public const PYTHON_PACKAGES = ['numpy', 'matplotlib'];

    /** @return array<string, mixed> */
    public static function defaults(string $mode = 'exam'): array
    {
        $practice = $mode === 'practice';

        return [
            'lock_on_violation' => !$practice,
            'violation_grace_ms' => 300,
            'paste_policy' => $practice ? 'allow_log' : 'internal',
            'require_seb' => false,
            'require_access_code' => false,
            'toilet_pauses_clock' => false,
            'live_view' => true,
            'screen_share' => false,
            'allow_self_submit' => true,
            'shuffle_choices' => true,
            'show_points' => true,
            'run_mode' => 'run',
            'java_version' => '21',
            'python_packages' => [],
            'editor' => [
                'autocomplete' => $practice,
                'lint' => false,
                'close_brackets' => true,
                'font_size' => 15,
            ],
            'aids' => [
                'java_api' => $practice,
                'python_doc' => $practice,
                'sql_cheatsheet' => $practice,
                'operators' => true,
                'calculator' => false,
            ],
        ];
    }

    /**
     * Füllt fehlende Werte mit Standards, verwirft Unbekanntes, prüft Wertebereiche.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input, string $mode = 'exam'): array
    {
        $d = self::defaults($mode);
        $bool = static fn (mixed $v, bool $default): bool => $v === null ? $default : filter_var($v, FILTER_VALIDATE_BOOLEAN);

        $out = [
            'lock_on_violation' => $bool($input['lock_on_violation'] ?? null, $d['lock_on_violation']),
            'violation_grace_ms' => max(0, min(5000, (int) ($input['violation_grace_ms'] ?? $d['violation_grace_ms']))),
            'paste_policy' => in_array($input['paste_policy'] ?? null, self::PASTE_POLICIES, true) ? $input['paste_policy'] : $d['paste_policy'],
            'require_seb' => $bool($input['require_seb'] ?? null, $d['require_seb']),
            'require_access_code' => $bool($input['require_access_code'] ?? null, $d['require_access_code']),
            'toilet_pauses_clock' => $bool($input['toilet_pauses_clock'] ?? null, $d['toilet_pauses_clock']),
            'live_view' => $bool($input['live_view'] ?? null, $d['live_view']),
            'screen_share' => $bool($input['screen_share'] ?? null, $d['screen_share']),
            'allow_self_submit' => $bool($input['allow_self_submit'] ?? null, $d['allow_self_submit']),
            'shuffle_choices' => $bool($input['shuffle_choices'] ?? null, $d['shuffle_choices']),
            'show_points' => $bool($input['show_points'] ?? null, $d['show_points']),
            'run_mode' => in_array($input['run_mode'] ?? null, self::RUN_MODES, true) ? $input['run_mode'] : $d['run_mode'],
            'java_version' => in_array((string) ($input['java_version'] ?? ''), self::JAVA_VERSIONS, true) ? (string) $input['java_version'] : $d['java_version'],
            'python_packages' => array_values(array_intersect(self::PYTHON_PACKAGES, is_array($input['python_packages'] ?? null) ? $input['python_packages'] : [])),
        ];

        $editor = is_array($input['editor'] ?? null) ? $input['editor'] : [];
        $out['editor'] = [
            'autocomplete' => $bool($editor['autocomplete'] ?? null, $d['editor']['autocomplete']),
            'lint' => $bool($editor['lint'] ?? null, $d['editor']['lint']),
            'close_brackets' => $bool($editor['close_brackets'] ?? null, $d['editor']['close_brackets']),
            'font_size' => max(11, min(24, (int) ($editor['font_size'] ?? $d['editor']['font_size']))),
        ];

        $aids = is_array($input['aids'] ?? null) ? $input['aids'] : [];
        $out['aids'] = [];
        foreach ($d['aids'] as $key => $default) {
            $out['aids'][$key] = $bool($aids[$key] ?? null, $default);
        }

        return $out;
    }

    /**
     * Teilmenge, die die Schüleransicht kennen darf (alles hier ist unkritisch,
     * die Funktion existiert, damit spätere geheime Felder nicht versehentlich
     * mitgehen).
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function forStudent(array $settings): array
    {
        $s = self::normalize($settings);
        unset($s['require_access_code']);

        return $s;
    }

    // ---------- Profile ----------

    /**
     * Fertige Zusammenstellungen für den schnellen Start. Jedes Profil ändert nur die Werte,
     * die es nennt; alles andere kommt aus den Standards des Modus.
     */
    public const PROFILES = [
        'streng' => [
            'label' => 'Klausur, streng',
            'description' => 'Sperre bei Verstoß, Einfügen nur innerhalb der Klausur, Klausurcode, keine Editorhilfen, als Hilfsmittel nur die Operatorenliste.',
        ],
        'seb' => [
            'label' => 'Klausur mit Safe Exam Browser',
            'description' => 'Wie "streng", aber Beitritt nur mit Safe Exam Browser statt mit Klausurcode.',
        ],
        'locker' => [
            'label' => 'Übung, locker',
            'description' => 'Keine Sperre, Einfügen erlaubt (protokolliert), Autovervollständigung, Fehlermarkierung und alle Hilfsmittel.',
        ],
    ];

    /** @return array<string, mixed> */
    private static function profileOverrides(string $key): array
    {
        $strict = [
            'lock_on_violation' => true,
            'paste_policy' => 'internal',
            'require_seb' => false,
            'require_access_code' => true,
            'allow_self_submit' => true,
            'editor' => ['autocomplete' => false, 'lint' => false],
            'aids' => ['java_api' => false, 'python_doc' => false, 'sql_cheatsheet' => false, 'operators' => true, 'calculator' => false],
        ];

        return match ($key) {
            'streng' => $strict,
            'seb' => array_replace_recursive($strict, ['require_seb' => true, 'require_access_code' => false]),
            'locker' => [
                'lock_on_violation' => false,
                'paste_policy' => 'allow_log',
                'require_seb' => false,
                'require_access_code' => false,
                'allow_self_submit' => true,
                'editor' => ['autocomplete' => true, 'lint' => true, 'close_brackets' => true],
                'aids' => ['java_api' => true, 'python_doc' => true, 'sql_cheatsheet' => true, 'operators' => true, 'calculator' => true],
            ],
            default => throw new \InvalidArgumentException('Unbekanntes Profil.'),
        };
    }

    /**
     * Vollständige Einstellungen eines Profils für den Modus.
     *
     * @return array<string, mixed>
     */
    public static function profile(string $key, string $mode = 'exam'): array
    {
        return self::normalize(array_replace_recursive(self::defaults($mode), self::profileOverrides($key)), $mode);
    }

    /**
     * Wendet ein Profil auf bestehende Einstellungen an: nur die Werte des Profils ändern sich,
     * Feineinstellungen wie Java-Version oder Schriftgröße bleiben.
     *
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    public static function applyProfile(array $current, string $key, string $mode = 'exam'): array
    {
        return self::normalize(array_replace_recursive(self::normalize($current, $mode), self::profileOverrides($key)), $mode);
    }

    /**
     * Welches Profil passt zu den Einstellungen? Verglichen werden nur die Werte, die ein Profil
     * festlegt. null, wenn keines passt.
     *
     * @param array<string, mixed> $settings
     */
    public static function matchingProfile(array $settings, string $mode = 'exam'): ?string
    {
        $s = self::normalize($settings, $mode);
        foreach (array_keys(self::PROFILES) as $key) {
            if (self::matches($s, self::profileOverrides($key))) {
                return $key;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $values @param array<string, mixed> $expected */
    private static function matches(array $values, array $expected): bool
    {
        foreach ($expected as $k => $v) {
            if (is_array($v)) {
                if (!is_array($values[$k] ?? null) || !self::matches($values[$k], $v)) {
                    return false;
                }
            } elseif (($values[$k] ?? null) !== $v) {
                return false;
            }
        }

        return true;
    }

    /** Werte unter "Erweitert" auf der Einstellungsseite. */
    public const ADVANCED_KEYS = ['violation_grace_ms', 'toilet_pauses_clock', 'live_view', 'screen_share', 'shuffle_choices', 'run_mode', 'java_version', 'python_packages', 'editor'];

    /**
     * Weicht unter "Erweitert" etwas vom Standard des Modus ab? Dann klappt der Bereich auf,
     * damit nichts Wichtiges versteckt bleibt.
     *
     * @param array<string, mixed> $settings
     */
    public static function advancedChanged(array $settings, string $mode = 'exam'): bool
    {
        $s = self::normalize($settings, $mode);
        $d = self::defaults($mode);
        foreach (self::ADVANCED_KEYS as $key) {
            if ($key === 'editor') {
                // Autovervollständigung und Fehlermarkierung legen die Profile fest; nur der Rest zählt hier.
                if ($s['editor']['close_brackets'] !== $d['editor']['close_brackets'] || $s['editor']['font_size'] !== $d['editor']['font_size']) {
                    return true;
                }
                continue;
            }
            if ($s[$key] !== $d[$key]) {
                return true;
            }
        }

        return false;
    }
}
