<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

use App\Services\Diagram\DiagramTaskType;
use App\Services\Diagram\LogicSimulator;

/**
 * Antworttyp "logic" (Schaltnetz zeichnen). Format: docs/DATENFORMATE.md, 2.11.
 *
 * Zusätzliche, optionale Felder:
 *  - config.symbols: "din" (Standard, DIN EN 60617) | "ansi" (US-Symbole)
 *  - config.show_table: Wahrheitstabelle der eigenen Schaltung anzeigen
 *  - Komponente.n: Zahl der Eingänge (2 bis 4) bei AND/OR/NAND/NOR/XOR/XNOR
 *  - solution.circuit: Musterschaltung (components, wires), nur für die Korrektur
 */
final class LogicType extends DiagramTaskType
{
    public const MAX_IN = 6;
    public const MAX_OUT = 4;
    public const MAX_COMPONENTS = 80;
    public const MAX_WIRES = 240;
    private const NAME = '/^[A-Za-z][A-Za-z0-9_]{0,9}$/';

    public function name(): string
    {
        return 'logic';
    }

    public function label(): string
    {
        return 'Schaltnetz (Logikgatter)';
    }

    public function defaultConfig(): array
    {
        return [
            'inputs' => ['A', 'B'],
            'outputs' => ['X'],
            'allowed_gates' => LogicSimulator::GATES,
            'symbols' => 'din',
            'show_table' => false,
        ];
    }

    public function defaultSolution(): array
    {
        return ['truth_table' => ['00' => ['X' => 0], '01' => ['X' => 0], '10' => ['X' => 0], '11' => ['X' => 0]]];
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
        $gates = [];
        foreach (is_array($config['allowed_gates'] ?? null) ? $config['allowed_gates'] : LogicSimulator::GATES as $g) {
            if (is_string($g) && in_array($g, LogicSimulator::GATES, true) && !in_array($g, $gates, true)) {
                $gates[] = $g;
            }
        }
        if ($gates === []) {
            throw new \InvalidArgumentException('Erlaube mindestens ein Gatter.');
        }

        return [
            'inputs' => $inputs,
            'outputs' => $outputs,
            'allowed_gates' => $gates,
            'symbols' => self::oneOf($config['symbols'] ?? 'din', ['din', 'ansi'], 'din'),
            'show_table' => self::bool($config['show_table'] ?? false),
        ];
    }

    /**
     * @return list<string>
     */
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

    public function normalizeSolution(array $solution, array $config): array
    {
        $inputs = array_values($config['inputs'] ?? []);
        $outputs = array_values($config['outputs'] ?? []);
        $result = [];
        $circuit = null;
        if (isset($solution['circuit']) && is_array($solution['circuit']) && ($solution['circuit']['components'] ?? []) !== []) {
            $circuit = self::normalizeCircuit($solution['circuit'], $config);
            $result['circuit'] = $circuit;
        }

        $table = [];
        $raw = is_array($solution['truth_table'] ?? null) ? $solution['truth_table'] : [];
        if ($raw === [] && $circuit !== null) {
            $calc = LogicSimulator::truthTable($circuit, $inputs, $outputs);
            if ($calc['errors'] !== []) {
                throw new \InvalidArgumentException($calc['errors'][0]);
            }
            $raw = $calc['table'];
        }
        $n = count($inputs);
        for ($row = 0; $row < (1 << $n); $row++) {
            $key = str_pad(decbin($row), $n, '0', STR_PAD_LEFT);
            if (!isset($raw[$key]) || !is_array($raw[$key])) {
                throw new \InvalidArgumentException("In der Wahrheitstabelle fehlt die Zeile {$key}.");
            }
            foreach ($outputs as $out) {
                $v = $raw[$key][$out] ?? null;
                if (!in_array($v, [0, 1, '0', '1', true, false], true)) {
                    throw new \InvalidArgumentException("Wahrheitstabelle: Zeile {$key}, Ausgang {$out} braucht 0 oder 1.");
                }
                $table[$key][$out] = $v ? 1 : 0;
            }
        }
        $result['truth_table'] = $table;

        return $result;
    }

    public function normalizeContent(array $content, array $config): array
    {
        $circuit = self::normalizeCircuit($content, $config);
        $this->assertSize($circuit);

        return $circuit;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $config
     * @return array{components: list<array<string, mixed>>, wires: list<array<string, mixed>>}
     */
    public static function normalizeCircuit(array $data, array $config): array
    {
        $types = array_merge(['INPUT', 'OUTPUT'], LogicSimulator::GATES);
        $inputNames = is_array($config['inputs'] ?? null) ? $config['inputs'] : null;
        $outputNames = is_array($config['outputs'] ?? null) ? $config['outputs'] : null;

        $components = [];
        $byId = [];
        $labels = [];
        foreach (self::rows($data['components'] ?? [], self::MAX_COMPONENTS) as $c) {
            $id = self::safeId($c['id'] ?? null);
            $type = is_string($c['type'] ?? null) ? $c['type'] : '';
            if ($id === null || isset($byId[$id]) || !in_array($type, $types, true)) {
                continue;
            }
            $label = self::line($c['label'] ?? '', 20);
            if ($type === 'INPUT' || $type === 'OUTPUT') {
                $allowed = $type === 'INPUT' ? $inputNames : $outputNames;
                if (($allowed !== null && !in_array($label, $allowed, true)) || isset($labels[$type . ':' . $label])) {
                    continue;
                }
                $labels[$type . ':' . $label] = true;
            }
            $entry = ['id' => $id, 'type' => $type, 'label' => $label, 'x' => self::coord($c['x'] ?? 0), 'y' => self::coord($c['y'] ?? 0)];
            if (!in_array($type, ['INPUT', 'OUTPUT', 'NOT'], true)) {
                $n = self::intRange($c['n'] ?? 2, 2, 4, 2);
                if ($n !== 2) {
                    $entry['n'] = $n;
                }
            }
            $byId[$id] = $entry;
            $components[] = $entry;
        }

        $wires = [];
        $wireIds = [];
        $targets = [];
        foreach (self::rows($data['wires'] ?? [], self::MAX_WIRES) as $w) {
            $id = self::safeId($w['id'] ?? null);
            $from = is_array($w['from'] ?? null) ? self::safeId($w['from']['component'] ?? null) : null;
            $to = is_array($w['to'] ?? null) ? self::safeId($w['to']['component'] ?? null) : null;
            $pin = is_array($w['to'] ?? null) && is_string($w['to']['pin'] ?? null) ? $w['to']['pin'] : '';
            if ($id === null || isset($wireIds[$id]) || $from === null || $to === null || $from === $to
                || !isset($byId[$from], $byId[$to]) || ($w['from']['pin'] ?? 'out') !== 'out') {
                continue;
            }
            if ($byId[$from]['type'] === 'OUTPUT' || $byId[$to]['type'] === 'INPUT') {
                continue;
            }
            if (preg_match('/^in([0-3])$/', $pin, $m) !== 1 || (int) $m[1] >= LogicSimulator::pinCount($byId[$to]) || isset($targets[$to . '.' . $pin])) {
                continue;
            }
            $wireIds[$id] = true;
            $targets[$to . '.' . $pin] = true;
            $wires[] = ['id' => $id, 'from' => ['component' => $from, 'pin' => 'out'], 'to' => ['component' => $to, 'pin' => $pin]];
        }

        return ['components' => $components, 'wires' => $wires];
    }

    public function isAnswered(array $content): bool
    {
        return ($content['wires'] ?? []) !== [];
    }

    public function autoCheck(array $config, array $solution, array $content): ?array
    {
        $table = $solution['truth_table'] ?? null;
        $inputs = array_values($config['inputs'] ?? []);
        $outputs = array_values($config['outputs'] ?? []);
        if (!is_array($table) || $table === [] || $inputs === [] || $outputs === []) {
            return null;
        }
        $calc = LogicSimulator::truthTable($content, $inputs, $outputs);
        if ($calc['errors'] !== [] && $calc['table'] === []) {
            return ['ratio' => 0.0, 'details' => [
                'source' => 'logic', 'ratio' => 0.0, 'items' => [], 'errors' => $calc['errors'], 'warnings' => $calc['warnings'],
            ]];
        }

        $items = [];
        $right = 0;
        foreach ($table as $key => $row) {
            foreach ($row as $out => $expected) {
                $actual = $calc['table'][(string) $key][$out] ?? null;
                $ok = $actual !== null && (int) $actual === (int) $expected;
                $items[] = ['id' => $key . '.' . $out, 'ok' => $ok];
                $right += $ok ? 1 : 0;
            }
        }
        $ratio = $items === [] ? 0.0 : round($right / count($items), 4);

        return ['ratio' => $ratio, 'details' => [
            'source' => 'logic', 'ratio' => $ratio, 'items' => $items, 'errors' => $calc['errors'], 'warnings' => $calc['warnings'],
        ]];
    }

    public function toText(array $content, array $config): string
    {
        $names = [];
        $lines = [];
        foreach ($content['components'] ?? [] as $c) {
            $names[(string) $c['id']] = $c['type'] === 'INPUT' || $c['type'] === 'OUTPUT' ? (string) $c['label'] : $c['type'] . ' ' . $c['id'];
        }
        foreach ($content['wires'] ?? [] as $w) {
            $lines[] = ($names[$w['from']['component']] ?? '?') . ' -> ' . ($names[$w['to']['component']] ?? '?') . '.' . $w['to']['pin'];
        }
        $outputs = [];
        foreach ($content['components'] ?? [] as $c) {
            if ($c['type'] === 'OUTPUT') {
                $outputs[] = $c['label'];
            }
        }
        foreach ($outputs as $out) {
            $lines[] = $out . ' = ' . LogicSimulator::expression($content, (string) $out);
        }

        return implode("\n", $lines);
    }
}
