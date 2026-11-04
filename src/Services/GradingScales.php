<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Notenschlüssel: Auflösung für Kurse, Umrechnung Prozent zu Note/Punkten, Prüfung.
 *
 * thresholds ist eine Liste absteigend nach min_percent:
 *   [{"label":"1+","value":0.7,"min_percent":95}, ...]
 * Diese Klasse ist die Schnittstelle der Korrektur (scaleForCourse, gradeFor).
 */
final class GradingScales
{
    public const TYPES = ['grades' => 'Noten (1 bis 6)', 'points' => 'Punkte (0 bis 15)'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Notenschlüssel eines Kurses: Override des Kurses, sonst Standard der Klassenstufe,
     * sonst null. thresholds ist bereits ein Array.
     *
     * @return array<string, mixed>|null
     */
    public function scaleForCourse(?int $courseId): ?array
    {
        if ($courseId === null) {
            return null;
        }
        $course = $this->db->fetchOne('SELECT grade_level, grading_scale_id FROM courses WHERE id = ?', [$courseId]);
        if ($course === null) {
            return null;
        }
        if ($course['grading_scale_id'] !== null) {
            $scale = $this->find((int) $course['grading_scale_id']);
            if ($scale !== null) {
                return $scale;
            }
        }

        return $this->scaleForGradeLevel((int) $course['grade_level']);
    }

    /** @return array<string, mixed>|null */
    public function scaleForGradeLevel(int $level): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM grading_scales WHERE grade_level = ?', [$level]);

        return $row === null ? null : self::decode($row);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM grading_scales WHERE id = ?', [$id]);

        return $row === null ? null : self::decode($row);
    }

    /**
     * Alle Schlüssel: zuerst Standards je Klassenstufe, dann Vorlagen.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM grading_scales ORDER BY grade_level IS NULL, grade_level, name',
        );

        return array_map(self::decode(...), $rows);
    }

    /**
     * Rechnet Prozent in Note/Punkte um: die erste Stufe (absteigend), deren Grenze erreicht ist.
     * Liegt der Wert unter allen Grenzen (z. B. fehlerhafter Schlüssel), gilt die niedrigste Stufe.
     *
     * @param list<array{label: mixed, value: mixed, min_percent: mixed}> $thresholds
     * @return array{label: string, value: float}
     */
    public static function gradeFor(array $thresholds, float $percent): array
    {
        if ($thresholds === []) {
            return ['label' => '', 'value' => 0.0];
        }
        $sorted = array_values($thresholds);
        usort($sorted, static fn (array $a, array $b): int => (float) $b['min_percent'] <=> (float) $a['min_percent']);

        // Kleine Toleranz gegen Gleitkomma-Rauschen (z. B. 89,99999999 bei Rechnung 45/50).
        foreach ($sorted as $step) {
            if ($percent + 1e-9 >= (float) $step['min_percent']) {
                return ['label' => (string) $step['label'], 'value' => (float) $step['value']];
            }
        }
        $last = $sorted[count($sorted) - 1];

        return ['label' => (string) $last['label'], 'value' => (float) $last['value']];
    }

    /**
     * Prüft einen Schlüssel: nicht leer, Beschriftung und Wert je Stufe, Grenzen zwischen 0 und 100,
     * streng absteigend, lückenlos bis 0 (die letzte Stufe beginnt bei 0).
     *
     * @param list<array<string, mixed>> $thresholds
     * @return list<string> Fehlermeldungen, leer wenn gültig
     */
    public static function validate(array $thresholds, string $type): array
    {
        $errors = [];
        if (!isset(self::TYPES[$type])) {
            $errors[] = 'Der Typ muss Noten oder Punkte sein.';
        }
        if ($thresholds === []) {
            return array_merge($errors, ['Der Schlüssel braucht mindestens eine Stufe.']);
        }
        if (count($thresholds) > 40) {
            $errors[] = 'Ein Schlüssel darf höchstens 40 Stufen haben.';
        }

        $previous = null;
        $labels = [];
        foreach (array_values($thresholds) as $i => $step) {
            $n = $i + 1;
            $label = trim((string) ($step['label'] ?? ''));
            if ($label === '' || mb_strlen($label) > 10) {
                $errors[] = "Stufe {$n}: Die Beschriftung ist Pflicht (höchstens 10 Zeichen).";
            }
            if (isset($labels[mb_strtolower($label)]) && $label !== '') {
                $errors[] = "Stufe {$n}: Die Beschriftung „{$label}“ kommt doppelt vor.";
            }
            $labels[mb_strtolower($label)] = true;

            if (!is_numeric($step['value'] ?? null)) {
                $errors[] = "Stufe {$n}: Der Wert muss eine Zahl sein.";
            }
            $min = $step['min_percent'] ?? null;
            if (!is_numeric($min)) {
                $errors[] = "Stufe {$n}: „ab Prozent“ muss eine Zahl sein.";
                $previous = null;
                continue;
            }
            $min = (float) $min;
            if ($min < 0 || $min > 100) {
                $errors[] = "Stufe {$n}: „ab Prozent“ muss zwischen 0 und 100 liegen.";
            }
            if ($previous !== null && $min >= $previous) {
                $errors[] = "Stufe {$n}: Die Grenzen müssen absteigend sein (erst {$previous}, dann kleiner).";
            }
            $previous = $min;
        }

        $last = end($thresholds);
        if (is_array($last) && is_numeric($last['min_percent'] ?? null) && abs((float) $last['min_percent']) > 1e-9) {
            $errors[] = 'Die letzte Stufe muss bei 0 Prozent beginnen, sonst fehlt die Note für schwache Ergebnisse.';
        }

        return $errors;
    }

    /**
     * Normalisiert Eingaben (Zahlen mit Komma, Leerzeichen) zu sauberen Stufen.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{label: string, value: float|int, min_percent: float|int}>
     */
    public static function normalize(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $valueRaw = str_replace(',', '.', trim((string) ($row['value'] ?? '')));
            $minRaw = str_replace(',', '.', trim((string) ($row['min_percent'] ?? '')));
            if ($label === '' && $valueRaw === '' && $minRaw === '') {
                continue; // leere Zeile
            }
            $out[] = [
                'label' => $label,
                'value' => is_numeric($valueRaw) ? self::number((float) $valueRaw) : $valueRaw,
                'min_percent' => is_numeric($minRaw) ? self::number((float) $minRaw) : $minRaw,
            ];
        }

        return $out;
    }

    // ---------- intern ----------

    private static function number(float $value): float|int
    {
        $rounded = round($value, 2);

        return floor($rounded) == $rounded ? (int) $rounded : $rounded;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $decoded = json_decode((string) $row['thresholds'], true);
        $row['thresholds'] = is_array($decoded) ? $decoded : [];

        return $row;
    }
}
