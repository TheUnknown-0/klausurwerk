<?php

declare(strict_types=1);

namespace App\Services\Diagram;

/** Simulation endlicher Automaten (DEA und NEA, optional mit ε-Übergängen). */
final class AutomatonSimulator
{
    public const EPSILON = 'ε';

    /**
     * Übergangstabelle: Zustand => Symbol => Liste der Zielzustände.
     *
     * @return array<string, array<string, list<string>>>
     */
    public static function table(array $automaton): array
    {
        $table = [];
        foreach ($automaton['transitions'] ?? [] as $t) {
            $from = (string) ($t['from'] ?? '');
            $to = (string) ($t['to'] ?? '');
            foreach ($t['symbols'] ?? [] as $symbol) {
                $symbol = (string) $symbol;
                if (!isset($table[$from][$symbol]) || !in_array($to, $table[$from][$symbol], true)) {
                    $table[$from][$symbol][] = $to;
                }
            }
        }

        return $table;
    }

    public static function initial(array $automaton): ?string
    {
        foreach ($automaton['states'] ?? [] as $s) {
            if (!empty($s['initial'])) {
                return (string) $s['id'];
            }
        }

        return null;
    }

    /** @return array<string, true> */
    public static function accepting(array $automaton): array
    {
        $set = [];
        foreach ($automaton['states'] ?? [] as $s) {
            if (!empty($s['accepting'])) {
                $set[(string) $s['id']] = true;
            }
        }

        return $set;
    }

    /**
     * ε-Hülle einer Zustandsmenge.
     *
     * @param list<string> $states
     * @param array<string, array<string, list<string>>> $table
     * @return list<string>
     */
    public static function closure(array $states, array $table): array
    {
        $seen = array_fill_keys($states, true);
        $queue = $states;
        while ($queue !== []) {
            $s = array_pop($queue);
            foreach ($table[$s][self::EPSILON] ?? [] as $next) {
                if (!isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }
        $out = array_map('strval', array_keys($seen));
        sort($out);

        return $out;
    }

    /**
     * Zustandsmengen nach jedem gelesenen Zeichen (Index 0 = Startmenge).
     * null, wenn es keinen Startzustand gibt.
     *
     * @return list<list<string>>|null
     */
    public static function trace(array $automaton, string $word): ?array
    {
        $start = self::initial($automaton);
        if ($start === null) {
            return null;
        }
        $table = self::table($automaton);
        $current = self::closure([$start], $table);
        $steps = [$current];
        foreach (mb_str_split($word) as $char) {
            $next = [];
            foreach ($current as $s) {
                foreach ($table[$s][$char] ?? [] as $target) {
                    $next[$target] = true;
                }
            }
            $current = self::closure(array_map('strval', array_keys($next)), $table);
            $steps[] = $current;
        }

        return $steps;
    }

    public static function accepts(array $automaton, string $word): bool
    {
        $steps = self::trace($automaton, $word);
        if ($steps === null) {
            return false;
        }
        $accepting = self::accepting($automaton);
        foreach (end($steps) ?: [] as $s) {
            if (isset($accepting[$s])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hinweise, wenn ein DEA nicht deterministisch oder nicht vollständig ist.
     *
     * @param list<string> $alphabet
     * @return list<string>
     */
    public static function dfaIssues(array $automaton, array $alphabet): array
    {
        $issues = [];
        if (self::initial($automaton) === null) {
            $issues[] = 'Es gibt keinen Startzustand.';
        }
        $table = self::table($automaton);
        $nondeterministic = [];
        $incomplete = [];
        foreach ($automaton['states'] ?? [] as $s) {
            $id = (string) $s['id'];
            $name = (string) (($s['name'] ?? '') !== '' ? $s['name'] : $id);
            if (isset($table[$id][self::EPSILON])) {
                $nondeterministic[] = "{$name} (ε-Übergang)";
            }
            foreach ($alphabet as $symbol) {
                $targets = $table[$id][$symbol] ?? [];
                if (count($targets) > 1) {
                    $nondeterministic[] = "{$name} bei \"{$symbol}\"";
                } elseif ($targets === []) {
                    $incomplete[] = "{$name} bei \"{$symbol}\"";
                }
            }
        }
        if ($nondeterministic !== []) {
            $issues[] = 'Der Automat ist nicht deterministisch: ' . implode(', ', $nondeterministic) . '.';
        }
        if ($incomplete !== []) {
            $issues[] = 'Der Automat ist nicht vollständig, es fehlen Übergänge: '
                . implode(', ', array_slice($incomplete, 0, 8)) . (count($incomplete) > 8 ? ' ...' : '') . '.';
        }

        return $issues;
    }
}
