<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

use App\Services\Diagram\DiagramTaskType;

/**
 * Antworttyp "truth_table" (Wahrheitstabelle ausfüllen). Format: docs/DATENFORMATE.md, 2.12.
 * Die Eingangsspalten ergeben sich aus config.inputs (alle Kombinationen, erster Eingang
 * höchstwertig). Zellen aus config.prefilled sind vorgegeben und nicht änderbar.
 */
final class TruthTableType extends DiagramTaskType
{
    public const MAX_IN = 6;
    public const MAX_OUT = 8;
    private const NAME = '/^[A-Za-z][A-Za-z0-9_]{0,9}$/';

    public function name(): string
    {
        return 'truth_table';
    }

    public function label(): string
    {
        return 'Wahrheitstabelle';
    }

    public function defaultConfig(): array
    {
        return ['inputs' => ['A', 'B'], 'outputs' => ['X'], 'prefilled' => new \stdClass()];
    }

    public function defaultSolution(): array
    {
        return ['rows' => ['00' => ['X' => '0'], '01' => ['X' => '0'], '10' => ['X' => '0'], '11' => ['X' => '0']]];
    }

    /** @return list<string> Zeilenschlüssel in Anzeigereihenfolge */
    public static function rowKeys(int $inputs): array
    {
        $keys = [];
        for ($row = 0; $row < (1 << $inputs); $row++) {
            $keys[] = str_pad(decbin($row), $inputs, '0', STR_PAD_LEFT);
        }

        return $keys;
    }

    public function normalizeConfig(array $config): array
    {
        $inputs = self::names($config['inputs'] ?? [], self::MAX_IN, 'Eingang');
        $outputs = self::names($config['outputs'] ?? [], self::MAX_OUT, 'Ausgang');
        if ($inputs === [] || $outputs === []) {
            throw new \InvalidArgumentException('Es braucht mindestens einen Eingang und einen Ausgang.');
        }
        if (array_intersect($inputs, $outputs) !== []) {
            throw new \InvalidArgumentException('Ein Name darf nicht gleichzeitig Eingang und Ausgang sein.');
        }
        $prefilled = self::cells((array) ($config['prefilled'] ?? []), $inputs, $outputs);

        return ['inputs' => $inputs, 'outputs' => $outputs, 'prefilled' => $prefilled === [] ? new \stdClass() : $prefilled];
    }

    /** @return list<string> */
    private static function names(mixed $list, int $max, string $what): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $name) {
            $name = is_scalar($name) ? trim((string) $name) : '';
            if ($name === '') {
                continue;
            }
            if (preg_match(self::NAME, $name) !== 1) {
                throw new \InvalidArgumentException("Ungültiger Name für {$what}: \"{$name}\" (Buchstabe, dann Buchstaben, Ziffern oder _, höchstens 10 Zeichen).");
            }
            if (in_array($name, $out, true)) {
                throw new \InvalidArgumentException("Der Name \"{$name}\" kommt doppelt vor.");
            }
            $out[] = $name;
        }
        if (count($out) > $max) {
            throw new \InvalidArgumentException("Höchstens {$max} Einträge erlaubt ({$what}).");
        }

        return $out;
    }

    /**
     * Zellen {Zeilenschlüssel: {Ausgang: "0"|"1"}} bereinigen (unbekannte Zeilen, Ausgänge
     * und Werte werden verworfen).
     *
     * @param list<string> $inputs
     * @param list<string> $outputs
     * @return array<string, array<string, string>>
     */
    private static function cells(mixed $rows, array $inputs, array $outputs): array
    {
        $valid = array_flip(self::rowKeys(count($inputs)));
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $key => $row) {
            $key = (string) $key;
            if (!isset($valid[$key]) || !is_array($row)) {
                continue;
            }
            foreach ($outputs as $name) {
                $v = $row[$name] ?? null;
                if (in_array($v, [0, 1, '0', '1'], true)) {
                    $out[$key][$name] = (string) $v;
                }
            }
        }

        return $out;
    }

    public function normalizeSolution(array $solution, array $config): array
    {
        $rows = self::cells($solution['rows'] ?? [], $config['inputs'] ?? [], $config['outputs'] ?? []);
        if ($rows === []) {
            return ['rows' => []]; // keine Musterlösung: nur manuelle Bewertung
        }
        foreach (self::rowKeys(count($config['inputs'] ?? [])) as $key) {
            foreach ($config['outputs'] ?? [] as $out) {
                if (!isset($rows[$key][$out])) {
                    throw new \InvalidArgumentException("Die Musterlösung ist unvollständig: Zeile {$key}, Ausgang {$out}.");
                }
            }
        }

        return ['rows' => $rows];
    }

    public function normalizeContent(array $content, array $config): array
    {
        $rows = self::cells($content['rows'] ?? [], $config['inputs'] ?? [], $config['outputs'] ?? []);
        // Vorgegebene Zellen sind fest.
        $prefilled = (array) ($config['prefilled'] ?? []);
        foreach ($prefilled as $key => $row) {
            foreach (is_array($row) ? $row : [] as $out => $v) {
                $rows[(string) $key][(string) $out] = (string) $v;
            }
        }

        return ['rows' => $rows];
    }

    public function isAnswered(array $content): bool
    {
        return ($content['rows'] ?? []) !== [];
    }

    public function autoCheck(array $config, array $solution, array $content): ?array
    {
        $expected = $solution['rows'] ?? null;
        if (!is_array($expected) || $expected === []) {
            return null;
        }
        $prefilled = (array) ($config['prefilled'] ?? []);
        $items = [];
        $right = 0;
        foreach ($expected as $key => $row) {
            foreach ($row as $out => $value) {
                if (isset($prefilled[$key][$out])) {
                    continue; // vorgegebene Zellen zählen nicht
                }
                $given = $content['rows'][(string) $key][$out] ?? null;
                $ok = $given !== null && (string) $given === (string) $value;
                $items[] = ['id' => $key . '.' . $out, 'ok' => $ok];
                $right += $ok ? 1 : 0;
            }
        }
        if ($items === []) {
            return null;
        }
        $ratio = round($right / count($items), 4);

        return ['ratio' => $ratio, 'details' => ['source' => 'truth_table', 'ratio' => $ratio, 'items' => $items]];
    }

    public function toText(array $content, array $config): string
    {
        $inputs = $config['inputs'] ?? [];
        $outputs = $config['outputs'] ?? [];
        $lines = [implode(' | ', array_merge($inputs, $outputs))];
        foreach (self::rowKeys(count($inputs)) as $key) {
            $cells = str_split($key);
            foreach ($outputs as $out) {
                $cells[] = $content['rows'][$key][$out] ?? '-';
            }
            $lines[] = implode(' | ', $cells);
        }

        return implode("\n", $lines);
    }
}
