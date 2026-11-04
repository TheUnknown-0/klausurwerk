<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Aufgabenbaum: Aufbau aus flachen Zeilen und Nummerierung.
 * Nummerierung wird angezeigt, nicht gespeichert: 1, 2, ... und Teilaufgaben 1a, 1b, ...
 */
final class TaskTree
{
    /**
     * Baut aus flachen Aufgabenzeilen (id, parent_id, position, ...) einen Baum.
     * Jeder Knoten bekommt 'number', 'depth' und 'children'.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function build(array $rows): array
    {
        $byParent = [];
        foreach ($rows as $row) {
            $byParent[(int) ($row['parent_id'] ?? 0)][] = $row;
        }
        foreach ($byParent as &$list) {
            usort($list, static fn (array $a, array $b): int => [(int) $a['position'], (int) $a['id']] <=> [(int) $b['position'], (int) $b['id']]);
        }
        unset($list);

        $make = static function (int $parentId, int $depth, string $prefix) use (&$make, $byParent): array {
            $out = [];
            foreach ($byParent[$parentId] ?? [] as $i => $row) {
                $number = $depth === 0 ? (string) ($i + 1) : $prefix . self::letters($i);
                $row['number'] = $number;
                $row['depth'] = $depth;
                $row['children'] = $make((int) $row['id'], $depth + 1, $number);
                $out[] = $row;
            }

            return $out;
        };

        return $make(0, 0, '');
    }

    /**
     * Baum in Anzeigereihenfolge (Tiefensuche) als flache Liste, ohne children.
     *
     * @param list<array<string, mixed>> $tree
     * @return list<array<string, mixed>>
     */
    public static function flatten(array $tree): array
    {
        $out = [];
        foreach ($tree as $node) {
            $children = $node['children'] ?? [];
            unset($node['children']);
            $out[] = $node;
            foreach (self::flatten($children) as $child) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /** 0 => a, 25 => z, 26 => aa. */
    public static function letters(int $index): string
    {
        $s = '';
        $n = $index;
        do {
            $s = chr(97 + $n % 26) . $s;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);

        return $s;
    }
}
