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
}
