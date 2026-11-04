<?php

declare(strict_types=1);

namespace App\Services\Diagram;

/**
 * Simulation von Schaltnetzen (Antworttyp "logic").
 *
 * Eine Schaltung besteht aus components (INPUT, OUTPUT, Gatter) und wires
 * (Ausgangspin "out" zu Eingangspin "in0", "in1", ...). Rückkopplungen (Zyklen)
 * werden erkannt und als Fehler gemeldet, nicht simuliert.
 */
final class LogicSimulator
{
    public const GATES = ['AND', 'OR', 'NOT', 'NAND', 'NOR', 'XOR', 'XNOR'];
    public const MAX_INPUTS = 8;

    /** Anzahl der Eingangspins einer Komponente. */
    public static function pinCount(array $component): int
    {
        $type = (string) ($component['type'] ?? '');
        if ($type === 'NOT' || $type === 'OUTPUT') {
            return 1;
        }
        if ($type === 'INPUT') {
            return 0;
        }
        $n = (int) ($component['n'] ?? 2);

        return max(2, min(4, $n));
    }

    /**
     * Verknüpfung der Eingangswerte eines Gatters.
     *
     * @param list<int|bool> $values
     */
    public static function gate(string $type, array $values): int
    {
        $values = array_map(static fn ($v): int => $v ? 1 : 0, array_values($values));
        $any = in_array(1, $values, true);
        $all = $values !== [] && !in_array(0, $values, true);
        $odd = (array_sum($values) % 2) === 1;

        return match ($type) {
            'AND' => $all ? 1 : 0,
            'NAND' => $all ? 0 : 1,
            'OR' => $any ? 1 : 0,
            'NOR' => $any ? 0 : 1,
            'XOR' => $odd ? 1 : 0,
            'XNOR' => $odd ? 0 : 1,
            'NOT' => ($values[0] ?? 0) === 1 ? 0 : 1,
            default => 0,
        };
    }

    /**
     * Wertet die Schaltung für eine Belegung der Eingänge aus.
     *
     * @param array<string, mixed> $circuit components, wires
     * @param array<string, int> $inputs Eingangsname => 0|1
     * @return array{outputs: array<string, int>, errors: list<string>, warnings: list<string>}
     */
    public static function evaluate(array $circuit, array $inputs): array
    {
        $comps = [];
        foreach ($circuit['components'] ?? [] as $c) {
            if (is_array($c) && isset($c['id'])) {
                $comps[(string) $c['id']] = $c;
            }
        }
        // Zielpin => Quellkomponente
        $source = [];
        foreach ($circuit['wires'] ?? [] as $w) {
            $from = (string) ($w['from']['component'] ?? '');
            $to = (string) ($w['to']['component'] ?? '');
            $pin = (string) ($w['to']['pin'] ?? '');
            if (isset($comps[$from], $comps[$to]) && !isset($source[$to . '.' . $pin])) {
                $source[$to . '.' . $pin] = $from;
            }
        }

        $memo = [];
        $visiting = [];
        $warnings = [];

        $value = function (string $id) use (&$value, &$memo, &$visiting, &$warnings, $comps, $source, $inputs): int {
            if (isset($memo[$id])) {
                return $memo[$id];
            }
            if (isset($visiting[$id])) {
                throw new \RuntimeException('cycle');
            }
            $visiting[$id] = true;
            $c = $comps[$id];
            $type = (string) $c['type'];
            if ($type === 'INPUT') {
                $result = ($inputs[(string) ($c['label'] ?? '')] ?? 0) ? 1 : 0;
            } else {
                $pins = [];
                for ($i = 0, $n = self::pinCount($c); $i < $n; $i++) {
                    $from = $source[$id . '.in' . $i] ?? null;
                    if ($from === null) {
                        $label = (string) ($c['label'] ?? '') !== '' ? (string) $c['label'] : $type;
                        $warnings[$id . '.in' . $i] = 'Eingang ' . ($i + 1) . " von {$label} ist nicht verbunden.";
                        $pins[] = 0;
                    } else {
                        $pins[] = $value($from);
                    }
                }
                $result = $type === 'OUTPUT' ? ($pins[0] ?? 0) : self::gate($type, $pins);
            }
            unset($visiting[$id]);

            return $memo[$id] = $result;
        };

        $outputs = [];
        try {
            foreach ($comps as $id => $c) {
                if ($c['type'] === 'OUTPUT') {
                    $outputs[(string) ($c['label'] ?? $id)] = $value((string) $id);
                }
            }
        } catch (\RuntimeException) {
            return [
                'outputs' => [],
                'errors' => ['Die Schaltung enthält eine Rückkopplung (Zyklus). Das ist in einem Schaltnetz nicht erlaubt.'],
                'warnings' => array_values($warnings),
            ];
        }

        return ['outputs' => $outputs, 'errors' => [], 'warnings' => array_values($warnings)];
    }

    /**
     * Wahrheitstabelle einer Schaltung. Schlüssel = Eingangsbits in der Reihenfolge von $inputNames
     * (erster Eingang = höchstwertiges Bit).
     *
     * @param list<string> $inputNames
     * @param list<string> $outputNames
     * @return array{table: array<string, array<string, int>>, errors: list<string>, warnings: list<string>}
     */
    public static function truthTable(array $circuit, array $inputNames, array $outputNames): array
    {
        $n = count($inputNames);
        $table = [];
        $errors = [];
        $warnings = [];
        for ($row = 0; $row < (1 << $n); $row++) {
            $key = str_pad(decbin($row), $n, '0', STR_PAD_LEFT);
            $in = [];
            foreach ($inputNames as $i => $name) {
                $in[$name] = (int) $key[$i];
            }
            $result = self::evaluate($circuit, $in);
            if ($result['errors'] !== []) {
                return ['table' => [], 'errors' => $result['errors'], 'warnings' => $result['warnings']];
            }
            $warnings = array_values(array_unique(array_merge($warnings, $result['warnings'])));
            $line = [];
            foreach ($outputNames as $name) {
                $line[$name] = $result['outputs'][$name] ?? 0;
                if (!array_key_exists($name, $result['outputs'])) {
                    $errors[$name] = "Der Ausgang {$name} fehlt in der Schaltung.";
                }
            }
            $table[$key] = $line;
        }

        return ['table' => $table, 'errors' => array_values($errors), 'warnings' => $warnings];
    }

    /** Boolescher Ausdruck am Ausgang (für die Textdarstellung). */
    public static function expression(array $circuit, string $outputLabel): string
    {
        $comps = [];
        foreach ($circuit['components'] ?? [] as $c) {
            if (is_array($c) && isset($c['id'])) {
                $comps[(string) $c['id']] = $c;
            }
        }
        $source = [];
        foreach ($circuit['wires'] ?? [] as $w) {
            $key = (string) ($w['to']['component'] ?? '') . '.' . (string) ($w['to']['pin'] ?? '');
            $source[$key] ??= (string) ($w['from']['component'] ?? '');
        }
        $out = null;
        foreach ($comps as $id => $c) {
            if ($c['type'] === 'OUTPUT' && (string) ($c['label'] ?? '') === $outputLabel) {
                $out = (string) $id;
            }
        }
        if ($out === null) {
            return '?';
        }
        $ops = ['AND' => ' ∧ ', 'NAND' => ' ∧ ', 'OR' => ' ∨ ', 'NOR' => ' ∨ ', 'XOR' => ' ⊕ ', 'XNOR' => ' ⊕ '];
        $build = function (string $id, array $path) use (&$build, $comps, $source, $ops): string {
            if (in_array($id, $path, true)) {
                return '…';
            }
            $path[] = $id;
            $c = $comps[$id] ?? null;
            if ($c === null) {
                return '?';
            }
            $type = (string) $c['type'];
            if ($type === 'INPUT') {
                return (string) ($c['label'] ?? '?');
            }
            $parts = [];
            for ($i = 0, $n = self::pinCount($c); $i < $n; $i++) {
                $from = $source[$id . '.in' . $i] ?? null;
                $parts[] = $from === null ? '0' : $build($from, $path);
            }
            if ($type === 'OUTPUT') {
                return $parts[0] ?? '0';
            }
            if ($type === 'NOT') {
                return '¬' . ($parts[0] ?? '0');
            }
            $joined = implode($ops[$type] ?? ' ? ', $parts);

            return in_array($type, ['NAND', 'NOR', 'XNOR'], true) ? '¬(' . $joined . ')' : '(' . $joined . ')';
        };

        $text = $build($out, []);
        // Äußere Klammern bei einer einzelnen Verknüpfung weglassen.
        if (str_starts_with($text, '(') && str_ends_with($text, ')') && substr_count($text, '(') === 1) {
            $text = substr($text, 1, -1);
        }

        return $text;
    }
}
