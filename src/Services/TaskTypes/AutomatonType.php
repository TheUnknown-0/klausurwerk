<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

use App\Services\Diagram\AutomatonSimulator;
use App\Services\Diagram\DiagramTaskType;

/**
 * Antworttyp "automaton" (DEA/NEA zeichnen). Format: docs/DATENFORMATE.md, 2.13.
 *
 * Zusätzliche, optionale Felder:
 *  - config.allow_simulation: Schüler:innen dürfen Wörter schrittweise ausprobieren (Standard true)
 *  - config.allow_epsilon: ε-Übergänge erlaubt (nur NEA, Standard false)
 *  - solution.automaton: Musterautomat (nur für die Korrektur)
 */
final class AutomatonType extends DiagramTaskType
{
    public const MAX_STATES = 40;
    public const MAX_TRANSITIONS = 200;
    public const MAX_WORDS = 50;
    public const MAX_WORD_LENGTH = 30;

    public function name(): string
    {
        return 'automaton';
    }

    public function label(): string
    {
        return 'Endlicher Automat';
    }

    public function defaultConfig(): array
    {
        return ['kind' => 'dfa', 'alphabet' => ['a', 'b'], 'allow_simulation' => true, 'allow_epsilon' => false];
    }

    public function defaultSolution(): array
    {
        return ['accept' => [], 'reject' => []];
    }

    public function normalizeConfig(array $config): array
    {
        $kind = self::oneOf($config['kind'] ?? 'dfa', ['dfa', 'nfa'], 'dfa');
        $alphabet = [];
        foreach (is_array($config['alphabet'] ?? null) ? $config['alphabet'] : [] as $symbol) {
            $symbol = is_scalar($symbol) ? trim((string) $symbol) : '';
            if ($symbol === '') {
                continue;
            }
            if (mb_strlen($symbol) !== 1 || $symbol === AutomatonSimulator::EPSILON || preg_match('/[\x00-\x1F]/', $symbol) === 1) {
                throw new \InvalidArgumentException("Jedes Zeichen des Alphabets muss ein einzelnes Zeichen sein (\"{$symbol}\" ist es nicht).");
            }
            if (!in_array($symbol, $alphabet, true)) {
                $alphabet[] = $symbol;
            }
        }
        if ($alphabet === []) {
            throw new \InvalidArgumentException('Das Alphabet braucht mindestens ein Zeichen.');
        }
        if (count($alphabet) > 10) {
            throw new \InvalidArgumentException('Höchstens 10 Zeichen im Alphabet.');
        }

        return [
            'kind' => $kind,
            'alphabet' => $alphabet,
            'allow_simulation' => self::bool($config['allow_simulation'] ?? true),
            'allow_epsilon' => $kind === 'nfa' && self::bool($config['allow_epsilon'] ?? false),
        ];
    }

    public function normalizeSolution(array $solution, array $config): array
    {
        $alphabet = $config['alphabet'] ?? [];
        $result = [];
        foreach (['accept', 'reject'] as $key) {
            $words = [];
            foreach (is_array($solution[$key] ?? null) ? $solution[$key] : [] as $word) {
                if (!is_scalar($word)) {
                    continue;
                }
                $word = (string) $word;
                if (mb_strlen($word) > self::MAX_WORD_LENGTH) {
                    throw new \InvalidArgumentException('Testwörter dürfen höchstens ' . self::MAX_WORD_LENGTH . ' Zeichen lang sein.');
                }
                foreach (mb_str_split($word) as $char) {
                    if (!in_array($char, $alphabet, true)) {
                        throw new \InvalidArgumentException("Das Testwort \"{$word}\" enthält \"{$char}\", das nicht im Alphabet steht.");
                    }
                }
                if (!in_array($word, $words, true)) {
                    $words[] = $word;
                }
            }
            if (count($words) > self::MAX_WORDS) {
                throw new \InvalidArgumentException('Höchstens ' . self::MAX_WORDS . ' Testwörter je Liste.');
            }
            $result[$key] = $words;
        }
        $both = array_intersect($result['accept'], $result['reject']);
        if ($both !== []) {
            throw new \InvalidArgumentException('Das Wort "' . reset($both) . '" steht bei akzeptiert und bei verworfen.');
        }
        if (isset($solution['automaton']) && is_array($solution['automaton']) && ($solution['automaton']['states'] ?? []) !== []) {
            $result['automaton'] = self::normalizeAutomaton($solution['automaton'], $config);
        }

        return $result;
    }

    public function normalizeContent(array $content, array $config): array
    {
        $automaton = self::normalizeAutomaton($content, $config);
        $this->assertSize($automaton);

        return $automaton;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $config
     * @return array{states: list<array<string, mixed>>, transitions: list<array<string, mixed>>}
     */
    public static function normalizeAutomaton(array $data, array $config): array
    {
        $alphabet = is_array($config['alphabet'] ?? null) ? $config['alphabet'] : null;
        $states = [];
        $ids = [];
        $hasInitial = false;
        foreach (self::rows($data['states'] ?? [], self::MAX_STATES) as $s) {
            $id = self::safeId($s['id'] ?? null);
            if ($id === null || isset($ids[$id])) {
                continue;
            }
            $ids[$id] = true;
            $initial = self::bool($s['initial'] ?? false) && !$hasInitial;
            $hasInitial = $hasInitial || $initial;
            $states[] = [
                'id' => $id,
                'name' => self::line($s['name'] ?? '', 12),
                'x' => self::coord($s['x'] ?? 0),
                'y' => self::coord($s['y'] ?? 0),
                'initial' => $initial,
                'accepting' => self::bool($s['accepting'] ?? false),
            ];
        }

        $allowEpsilon = !empty($config['allow_epsilon']);
        $transitions = [];
        $tIds = [];
        foreach (self::rows($data['transitions'] ?? [], self::MAX_TRANSITIONS) as $t) {
            $id = self::safeId($t['id'] ?? null);
            $from = self::safeId($t['from'] ?? null);
            $to = self::safeId($t['to'] ?? null);
            if ($id === null || isset($tIds[$id]) || $from === null || $to === null || !isset($ids[$from], $ids[$to])) {
                continue;
            }
            $symbols = [];
            foreach (is_array($t['symbols'] ?? null) ? $t['symbols'] : [] as $symbol) {
                $symbol = is_scalar($symbol) ? (string) $symbol : '';
                $valid = $symbol === AutomatonSimulator::EPSILON
                    ? ($alphabet === null || $allowEpsilon)
                    : (mb_strlen($symbol) === 1 && ($alphabet === null || in_array($symbol, $alphabet, true)));
                if ($valid && !in_array($symbol, $symbols, true)) {
                    $symbols[] = $symbol;
                }
            }
            if ($symbols === []) {
                continue;
            }
            $tIds[$id] = true;
            $transitions[] = ['id' => $id, 'from' => $from, 'to' => $to, 'symbols' => $symbols];
        }

        return ['states' => $states, 'transitions' => $transitions];
    }

    public function isAnswered(array $content): bool
    {
        return ($content['states'] ?? []) !== [];
    }

    public function autoCheck(array $config, array $solution, array $content): ?array
    {
        $accept = is_array($solution['accept'] ?? null) ? $solution['accept'] : [];
        $reject = is_array($solution['reject'] ?? null) ? $solution['reject'] : [];
        if ($accept === [] && $reject === []) {
            return null;
        }

        $hints = [];
        if (AutomatonSimulator::initial($content) === null) {
            $hints[] = 'Es gibt keinen Startzustand.';
        }
        if (($config['kind'] ?? 'dfa') === 'dfa') {
            $hints = array_values(array_unique(array_merge($hints, AutomatonSimulator::dfaIssues($content, $config['alphabet'] ?? []))));
        }

        $items = [];
        $right = 0;
        foreach (['accept' => $accept, 'reject' => $reject] as $kind => $words) {
            foreach ($words as $word) {
                $word = (string) $word;
                $accepted = AutomatonSimulator::accepts($content, $word);
                $ok = $kind === 'accept' ? $accepted : !$accepted;
                $items[] = ['id' => $kind . ':' . $word, 'ok' => $ok, 'word' => $word, 'expected' => $kind === 'accept' ? 'akzeptiert' : 'verworfen'];
                $right += $ok ? 1 : 0;
            }
        }
        $ratio = round($right / count($items), 4);

        return ['ratio' => $ratio, 'details' => ['source' => 'automaton', 'ratio' => $ratio, 'items' => $items, 'hints' => $hints]];
    }

    public function toText(array $content, array $config): string
    {
        $alphabet = $config['alphabet'] ?? [];
        $symbols = $alphabet;
        $table = AutomatonSimulator::table($content);
        foreach ($table as $row) {
            if (isset($row[AutomatonSimulator::EPSILON])) {
                $symbols[] = AutomatonSimulator::EPSILON;
                break;
            }
        }
        $names = [];
        foreach ($content['states'] ?? [] as $s) {
            $names[(string) $s['id']] = $s['name'] !== '' ? (string) $s['name'] : (string) $s['id'];
        }
        $lines = ['Zustand | ' . implode(' | ', $symbols)];
        foreach ($content['states'] ?? [] as $s) {
            $label = (!empty($s['initial']) ? '→' : '') . (!empty($s['accepting']) ? '*' : '') . $names[$s['id']];
            $cells = [];
            foreach ($symbols as $symbol) {
                $targets = array_map(static fn (string $id): string => $names[$id] ?? '?', $table[$s['id']][$symbol] ?? []);
                $cells[] = $targets === [] ? '-' : (count($targets) === 1 ? $targets[0] : '{' . implode(', ', $targets) . '}');
            }
            $lines[] = $label . ' | ' . implode(' | ', $cells);
        }
        $lines[] = '(→ Startzustand, * Endzustand)';

        return implode("\n", $lines);
    }
}
