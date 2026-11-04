<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Klausurspiegel: Notenverteilung, Durchschnitt, Median, Auswertung je Aufgabe und je Anforderungsbereich.
 *
 * compute() ist rein (ohne Datenbank) und damit direkt testbar; forExam() baut die Eingabe aus
 * den Ergebnissen der Korrektur.
 */
final class ExamStatistics
{
    /** Anforderungsbereiche in fester Reihenfolge der Anzeige. */
    public const AFB_ORDER = ['I', 'I-II', 'II', 'II-III', 'III'];

    /** @param list<float|int> $values */
    public static function mean(array $values): ?float
    {
        return $values === [] ? null : array_sum($values) / count($values);
    }

    /** @param list<float|int> $values */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $values[$mid] : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
    }

    /**
     * Häufigkeit je Stufe in der Reihenfolge des Schlüssels (auch Stufen mit 0).
     *
     * @param list<string> $labels Noten der Personen
     * @param list<string> $order Stufen des Notenschlüssels (beste zuerst)
     * @return array<string, int>
     */
    public static function distribution(array $labels, array $order): array
    {
        $out = [];
        foreach ($order as $label) {
            $out[$label] = 0;
        }
        foreach ($labels as $label) {
            $out[$label] = ($out[$label] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * @param array{
     *   tasks: list<array{id: int, number: string, title: string, points: float|int, afb: ?string}>,
     *   persons: list<array{total: float|int, max: float|int, percent: ?float, grade_label: ?string, grade_value: ?float, points: array<int, float|int|null>}>,
     *   order?: list<string>
     * } $input
     * @return array<string, mixed>
     */
    public static function compute(array $input): array
    {
        $persons = $input['persons'];
        $n = count($persons);
        $percents = [];
        $totals = [];
        $gradeValues = [];
        $labels = [];
        foreach ($persons as $p) {
            if ($p['percent'] !== null) {
                $percents[] = (float) $p['percent'];
            }
            $totals[] = (float) $p['total'];
            if ($p['grade_value'] !== null) {
                $gradeValues[] = (float) $p['grade_value'];
            }
            if ($p['grade_label'] !== null && $p['grade_label'] !== '') {
                $labels[] = (string) $p['grade_label'];
            }
        }

        $tasks = [];
        foreach ($input['tasks'] as $t) {
            $earned = [];
            foreach ($persons as $p) {
                $v = $p['points'][$t['id']] ?? null;
                if ($v !== null) {
                    $earned[] = (float) $v;
                }
            }
            $max = (float) $t['points'];
            $avg = self::mean($earned);
            $tasks[] = [
                'id' => $t['id'],
                'number' => $t['number'],
                'title' => $t['title'],
                'afb' => $t['afb'],
                'points' => $max,
                'n' => count($earned),
                'average' => $avg,
                // Lösungsquote: erreichte Punkte im Verhältnis zu den möglichen Punkten der bewerteten Personen
                'rate' => ($avg !== null && $max > 0) ? $avg / $max : null,
                'full' => count(array_filter($earned, static fn (float $v): bool => $v >= $max - 1e-9)),
                'zero' => count(array_filter($earned, static fn (float $v): bool => $v <= 1e-9)),
            ];
        }

        $afb = [];
        foreach ($input['tasks'] as $t) {
            $key = $t['afb'] !== null && $t['afb'] !== '' ? (string) $t['afb'] : '';
            foreach ($persons as $p) {
                $v = $p['points'][$t['id']] ?? null;
                if ($v === null) {
                    continue;
                }
                $afb[$key]['earned'] = ($afb[$key]['earned'] ?? 0.0) + (float) $v;
                $afb[$key]['possible'] = ($afb[$key]['possible'] ?? 0.0) + (float) $t['points'];
            }
            $afb[$key]['points'] = ($afb[$key]['points'] ?? 0.0) + (float) $t['points'];
        }
        $afbRows = [];
        $keys = array_merge(array_values(array_intersect(self::AFB_ORDER, array_keys($afb))), array_values(array_diff(array_keys($afb), self::AFB_ORDER)));
        foreach ($keys as $key) {
            $row = $afb[$key];
            $possible = (float) ($row['possible'] ?? 0.0);
            $afbRows[] = [
                'afb' => (string) $key,
                'points' => (float) $row['points'],
                'rate' => $possible > 0 ? (float) ($row['earned'] ?? 0.0) / $possible : null,
            ];
        }

        return [
            'n' => $n,
            'mean_total' => self::mean($totals),
            'mean_percent' => self::mean($percents),
            'median_percent' => self::median($percents),
            'mean_grade' => self::mean($gradeValues),
            'median_grade' => self::median($gradeValues),
            'best_percent' => $percents === [] ? null : max($percents),
            'worst_percent' => $percents === [] ? null : min($percents),
            'distribution' => self::distribution($labels, $input['order'] ?? []),
            'tasks' => $tasks,
            'afb' => $afbRows,
        ];
    }

    /**
     * Statistik einer Klausur aus der Korrektur. Berücksichtigt Personen mit Abgabe oder mit Bewertung.
     *
     * @param array<string, mixed> $exam
     * @return array<string, mixed>
     */
    public static function forExam(GradingService $grading, array $exam): array
    {
        $examId = (int) $exam['id'];
        $ownerId = $exam['owner_id'] !== null ? (int) $exam['owner_id'] : null;
        $tasks = $grading->leafTasks($examId);
        $totals = $grading->computeTotals($exam);
        $entries = $grading->entriesByCell($examId);
        $scale = $grading->scaleFor($exam);

        $persons = [];
        foreach ($grading->participations($examId) as $p) {
            $pid = (int) $p['id'];
            $hasGrades = isset($entries[$pid]);
            if ((string) $p['state'] !== 'submitted' && !$hasGrades) {
                continue;
            }
            $points = [];
            foreach ($tasks as $t) {
                $points[$t['id']] = GradingService::finalEntry($entries[$pid][$t['id']] ?? [], $ownerId)['points'];
            }
            $t = $totals[$pid];
            $persons[] = [
                'total' => $t['total'],
                'max' => $t['max'],
                'percent' => $t['percent'],
                'grade_label' => $t['grade_label'],
                'grade_value' => $t['grade_value'],
                'points' => $points,
            ];
        }
        $order = [];
        foreach ($scale['thresholds'] ?? [] as $step) {
            $order[] = (string) $step['label'];
        }

        $result = self::compute([
            'tasks' => array_map(static fn (array $t): array => [
                'id' => $t['id'], 'number' => $t['number'], 'title' => $t['title'], 'points' => $t['points'], 'afb' => $t['afb'],
            ], $tasks),
            'persons' => $persons,
            'order' => $order,
        ]);
        $result['scale_type'] = $scale['type'] ?? null;
        $result['scale_name'] = $scale['name'] ?? null;

        return $result;
    }
}
