<?php

declare(strict_types=1);

namespace App\Services\Diagram;

/**
 * Vergleich eines Relationenschemas mit der Musterlösung (nur als Vorschlag):
 * Tabellen, Spalten, Primärschlüssel und Fremdschlüssel (Ziel Tabelle.spalte).
 */
final class RelationalComparer
{
    /**
     * @param array<string, mixed> $solution
     * @param array<string, mixed> $content
     * @return array{ratio: float, details: array<string, mixed>}
     */
    public static function compare(array $solution, array $content): array
    {
        $items = [];
        $extra = [];
        $stuTables = [];
        foreach ($content['tables'] ?? [] as $t) {
            $stuTables[DiagramTaskType::norm((string) $t['name'])] ??= $t;
        }
        $usedTables = [];

        foreach ($solution['tables'] ?? [] as $sol) {
            $name = (string) $sol['name'];
            $key = DiagramTaskType::norm($name);
            $stu = $stuTables[$key] ?? null;
            $items[] = ['id' => 'table:' . $sol['id'], 'label' => "Tabelle {$name}", 'ok' => $stu !== null];
            if ($stu !== null) {
                $usedTables[$key] = true;
            }
            $stuCols = [];
            foreach ($stu['columns'] ?? [] as $c) {
                $stuCols[DiagramTaskType::norm((string) $c['name'])] ??= $c;
            }
            $usedCols = [];
            foreach ($sol['columns'] ?? [] as $i => $col) {
                $ckey = DiagramTaskType::norm((string) $col['name']);
                $found = $stuCols[$ckey] ?? null;
                $items[] = ['id' => 'col:' . $sol['id'] . ':' . $i, 'label' => "Spalte {$name}.{$col['name']}", 'ok' => $found !== null];
                if ($found !== null) {
                    $usedCols[$ckey] = true;
                }
                if (!empty($col['pk'])) {
                    $items[] = [
                        'id' => 'pk:' . $sol['id'] . ':' . $i,
                        'label' => "Primärschlüssel {$name}.{$col['name']}",
                        'ok' => $found !== null && !empty($found['pk']),
                    ];
                } elseif ($found !== null && !empty($found['pk'])) {
                    $extra[] = "{$name}.{$col['name']} ist kein Primärschlüssel";
                }
                if (($col['fk'] ?? null) !== null) {
                    $items[] = [
                        'id' => 'fk:' . $sol['id'] . ':' . $i,
                        'label' => "Fremdschlüssel {$name}.{$col['name']} → {$col['fk']}",
                        'ok' => $found !== null && self::fkKey($found['fk'] ?? null) === self::fkKey($col['fk']),
                    ];
                } elseif ($found !== null && ($found['fk'] ?? null) !== null) {
                    $extra[] = "{$name}.{$col['name']} ist kein Fremdschlüssel";
                }
            }
            foreach ($stuCols as $ckey => $c) {
                if (!isset($usedCols[$ckey])) {
                    $extra[] = "Spalte {$stu['name']}.{$c['name']}";
                }
            }
        }
        foreach ($stuTables as $key => $t) {
            if (!isset($usedTables[$key])) {
                $extra[] = 'Tabelle ' . $t['name'];
            }
        }

        return DiagramTaskType::comparisonResult('relational', $items, $extra);
    }

    private static function fkKey(mixed $fk): string
    {
        if (!is_string($fk) || $fk === '') {
            return '';
        }
        [$table, $column] = array_pad(explode('.', $fk, 2), 2, '');

        return DiagramTaskType::norm($table) . '.' . DiagramTaskType::norm($column);
    }
}
