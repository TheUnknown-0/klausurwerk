<?php

declare(strict_types=1);

namespace App\Services\Robo;

/**
 * Prüfung und Normalisierung der Robo-Welt und der Ziele (docs/DATENFORMATE.md, 2.16).
 * Dieselben Regeln wie frontend/src/robo/world.js (Browser) und runner/libs/python/robo.py (Runner).
 * Koordinaten: x nach rechts, y nach unten, (0|0) oben links. Fehler: \InvalidArgumentException auf Deutsch.
 */
final class RoboWorld
{
    public const MIN_SIZE = 2;
    public const MAX_SIZE = 30;
    public const MAX_SCREWS_PER_CELL = 99;
    public const DIRS = ['N', 'E', 'S', 'W'];

    private function __construct()
    {
    }

    /**
     * @return array{cols: int, rows: int, robo: array{x: int, y: int, dir: string}, walls: list<array{0: int, 1: int}>,
     *               boxes: list<array{0: int, 1: int}>, screws: list<array{x: int, y: int, count: int}>, targets: list<array{0: int, 1: int}>}
     */
    public static function normalize(mixed $raw): array
    {
        if (!is_array($raw) || array_is_list($raw) && $raw !== []) {
            throw new \InvalidArgumentException('Die Welt fehlt.');
        }
        $cols = $raw['cols'] ?? null;
        $rows = $raw['rows'] ?? null;
        if (!is_int($cols) || !is_int($rows) || $cols < self::MIN_SIZE || $rows < self::MIN_SIZE
            || $cols > self::MAX_SIZE || $rows > self::MAX_SIZE) {
            throw new \InvalidArgumentException('Die Welt muss zwischen ' . self::MIN_SIZE . ' und ' . self::MAX_SIZE . ' Spalten und Zeilen haben.');
        }

        $robo = is_array($raw['robo'] ?? null) ? $raw['robo'] : [];
        $rx = $robo['x'] ?? null;
        $ry = $robo['y'] ?? null;
        if (!is_int($rx) || !is_int($ry) || $rx < 0 || $ry < 0 || $rx >= $cols || $ry >= $rows) {
            throw new \InvalidArgumentException('Robo steht außerhalb der Welt.');
        }
        $dir = strtoupper(is_scalar($robo['dir'] ?? null) ? (string) $robo['dir'] : 'E');
        $dir = $dir === 'O' ? 'E' : $dir;
        if (!in_array($dir, self::DIRS, true)) {
            throw new \InvalidArgumentException('Ungültige Blickrichtung (erlaubt: N, E oder O, S, W).');
        }

        $walls = self::cells($raw['walls'] ?? [], 'Wände', $cols, $rows);
        $boxes = self::cells($raw['boxes'] ?? [], 'Kisten', $cols, $rows);
        $targets = self::cells($raw['targets'] ?? [], 'Ziele', $cols, $rows);
        $wallKeys = self::keys($walls, $cols);
        $boxKeys = self::keys($boxes, $cols);
        $roboKey = $ry * $cols + $rx;
        if (isset($wallKeys[$roboKey])) {
            throw new \InvalidArgumentException("Robo steht auf einer Wand ($rx|$ry).");
        }
        if (isset($boxKeys[$roboKey])) {
            throw new \InvalidArgumentException("Robo steht auf einer Kiste ($rx|$ry).");
        }
        foreach ($boxes as [$x, $y]) {
            if (isset($wallKeys[$y * $cols + $x])) {
                throw new \InvalidArgumentException("Eine Kiste steht auf einer Wand ($x|$y).");
            }
        }
        foreach ($targets as [$x, $y]) {
            if (isset($wallKeys[$y * $cols + $x])) {
                throw new \InvalidArgumentException("Ein Ziel liegt auf einer Wand ($x|$y).");
            }
        }

        $screws = [];
        $seen = [];
        if (!is_array($raw['screws'] ?? [])) {
            throw new \InvalidArgumentException('Schrauben: Erwartet wird eine Liste.');
        }
        foreach ($raw['screws'] ?? [] as $item) {
            if (!is_array($item) || !is_int($item['x'] ?? null) || !is_int($item['y'] ?? null) || !is_int($item['count'] ?? null)) {
                throw new \InvalidArgumentException('Schrauben: Jeder Eintrag braucht x, y und count (ganze Zahlen).');
            }
            [$x, $y, $n] = [$item['x'], $item['y'], $item['count']];
            if ($x < 0 || $y < 0 || $x >= $cols || $y >= $rows) {
                throw new \InvalidArgumentException("Schrauben: Das Feld ($x|$y) liegt außerhalb der Welt.");
            }
            if ($n < 1 || $n > self::MAX_SCREWS_PER_CELL) {
                throw new \InvalidArgumentException('Schrauben: Die Anzahl muss zwischen 1 und ' . self::MAX_SCREWS_PER_CELL . ' liegen.');
            }
            $key = $y * $cols + $x;
            if (isset($wallKeys[$key]) || isset($boxKeys[$key])) {
                throw new \InvalidArgumentException("Schrauben: Auf dem Feld ($x|$y) kann keine Schraube liegen (Wand oder Kiste).");
            }
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException("Schrauben: Das Feld ($x|$y) kommt doppelt vor.");
            }
            $seen[$key] = true;
            $screws[] = ['x' => $x, 'y' => $y, 'count' => $n];
        }

        return [
            'cols' => $cols,
            'rows' => $rows,
            'robo' => ['x' => $rx, 'y' => $ry, 'dir' => $dir],
            'walls' => $walls,
            'boxes' => $boxes,
            'screws' => $screws,
            'targets' => $targets,
        ];
    }

    /**
     * Ziel einer Testwelt: screws_collected ("all" oder Zahl), robo_at [x, y], robo_dir, boxes_at [[x, y], ...],
     * robo_on_target, boxes_on_targets. Unbekannte Schlüssel fallen weg.
     *
     * @param array<string, mixed> $world bereits normalisierte Welt
     * @return array<string, mixed>
     */
    public static function normalizeGoal(mixed $raw, array $world): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (!is_array($raw) || array_is_list($raw)) {
            throw new \InvalidArgumentException('Das Ziel muss ein Objekt sein.');
        }
        $goal = [];
        if (array_key_exists('screws_collected', $raw) && $raw['screws_collected'] !== null) {
            $sc = $raw['screws_collected'];
            if ($sc !== 'all' && !(is_int($sc) && $sc >= 0 && $sc <= 100000)) {
                throw new \InvalidArgumentException('screws_collected muss "all" oder eine Zahl sein.');
            }
            $goal['screws_collected'] = $sc;
        }
        if (array_key_exists('robo_at', $raw) && $raw['robo_at'] !== null) {
            $at = $raw['robo_at'];
            if (!is_array($at) || count($at) !== 2 || !is_int($at[0] ?? null) || !is_int($at[1] ?? null)) {
                throw new \InvalidArgumentException('robo_at muss die Form [x, y] haben.');
            }
            if ($at[0] < 0 || $at[1] < 0 || $at[0] >= $world['cols'] || $at[1] >= $world['rows']) {
                throw new \InvalidArgumentException('robo_at liegt außerhalb der Welt.');
            }
            $goal['robo_at'] = [$at[0], $at[1]];
        }
        if (array_key_exists('robo_dir', $raw) && $raw['robo_dir'] !== null && $raw['robo_dir'] !== '') {
            $dir = strtoupper(is_scalar($raw['robo_dir']) ? (string) $raw['robo_dir'] : '');
            $dir = $dir === 'O' ? 'E' : $dir;
            if (!in_array($dir, self::DIRS, true)) {
                throw new \InvalidArgumentException('robo_dir muss N, E (O), S oder W sein.');
            }
            $goal['robo_dir'] = $dir;
        }
        if (array_key_exists('boxes_at', $raw) && $raw['boxes_at'] !== null) {
            $boxes = self::cells($raw['boxes_at'], 'boxes_at', $world['cols'], $world['rows']);
            if ($boxes !== []) {
                $goal['boxes_at'] = $boxes;
            }
        }
        if (!empty($raw['robo_on_target'])) {
            $goal['robo_on_target'] = true;
        }
        if (!empty($raw['boxes_on_targets'])) {
            $goal['boxes_on_targets'] = true;
        }

        return $goal;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private static function cells(mixed $raw, string $label, int $cols, int $rows): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException("$label: Erwartet wird eine Liste von Positionen [x, y].");
        }
        if (count($raw) > $cols * $rows) {
            throw new \InvalidArgumentException("$label: Es sind mehr Einträge als Felder.");
        }
        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            if (!is_array($item) || count($item) !== 2 || !is_int($item[0] ?? null) || !is_int($item[1] ?? null)) {
                throw new \InvalidArgumentException("$label: Jede Position muss die Form [x, y] haben.");
            }
            [$x, $y] = [$item[0], $item[1]];
            if ($x < 0 || $y < 0 || $x >= $cols || $y >= $rows) {
                throw new \InvalidArgumentException("$label: Das Feld ($x|$y) liegt außerhalb der Welt.");
            }
            $key = $y * $cols + $x;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = [$x, $y];
            }
        }

        return $out;
    }

    /**
     * @param list<array{0: int, 1: int}> $cells
     * @return array<int, true>
     */
    private static function keys(array $cells, int $cols): array
    {
        $keys = [];
        foreach ($cells as [$x, $y]) {
            $keys[$y * $cols + $x] = true;
        }

        return $keys;
    }
}
