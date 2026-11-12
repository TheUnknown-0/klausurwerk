<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

use App\Services\Diagram\DiagramTaskType;
use App\Services\Diagram\StructureComparer;

/**
 * Antworttyp "structure" (Strukturformel, Chemie): Lewis- und Strukturformeln aus Atomen,
 * Bindungen, freien Elektronenpaaren, Beschriftungen und Reaktionspfeilen.
 * Format: docs/DATENFORMATE.md, 2.15a.
 */
final class StructureType extends DiagramTaskType
{
    public const MAX_ATOMS = 150;
    public const MAX_BONDS = 200;
    public const MAX_TEXTS = 30;
    public const MAX_ARROWS = 20;
    public const SIDES = ['top', 'right', 'bottom', 'left'];
    public const STEREO = ['none', 'wedge', 'dash'];
    public const ARROWS = ['forward', 'equilibrium'];

    /** Gültige Elementsymbole (Ordnungszahl 1 bis 118). */
    public const ELEMENTS = [
        'H', 'He', 'Li', 'Be', 'B', 'C', 'N', 'O', 'F', 'Ne', 'Na', 'Mg', 'Al', 'Si', 'P', 'S', 'Cl', 'Ar', 'K', 'Ca',
        'Sc', 'Ti', 'V', 'Cr', 'Mn', 'Fe', 'Co', 'Ni', 'Cu', 'Zn', 'Ga', 'Ge', 'As', 'Se', 'Br', 'Kr', 'Rb', 'Sr', 'Y', 'Zr',
        'Nb', 'Mo', 'Tc', 'Ru', 'Rh', 'Pd', 'Ag', 'Cd', 'In', 'Sn', 'Sb', 'Te', 'I', 'Xe', 'Cs', 'Ba', 'La', 'Ce', 'Pr', 'Nd',
        'Pm', 'Sm', 'Eu', 'Gd', 'Tb', 'Dy', 'Ho', 'Er', 'Tm', 'Yb', 'Lu', 'Hf', 'Ta', 'W', 'Re', 'Os', 'Ir', 'Pt', 'Au', 'Hg',
        'Tl', 'Pb', 'Bi', 'Po', 'At', 'Rn', 'Fr', 'Ra', 'Ac', 'Th', 'Pa', 'U', 'Np', 'Pu', 'Am', 'Cm', 'Bk', 'Cf', 'Es', 'Fm',
        'Md', 'No', 'Lr', 'Rf', 'Db', 'Sg', 'Bh', 'Hs', 'Mt', 'Ds', 'Rg', 'Cn', 'Nh', 'Fl', 'Mc', 'Lv', 'Ts', 'Og',
    ];

    public function name(): string
    {
        return 'structure';
    }

    public function label(): string
    {
        return 'Strukturformel (Chemie)';
    }

    public function defaultConfig(): array
    {
        return ['score_pairs' => false, 'start' => null];
    }

    public function defaultSolution(): array
    {
        return self::emptyDiagram();
    }

    public function normalizeConfig(array $config): array
    {
        $start = isset($config['start']) && is_array($config['start']) ? self::normalizeDiagram($config['start']) : null;
        if ($start !== null && $start['atoms'] === [] && $start['texts'] === [] && $start['arrows'] === []) {
            $start = null;
        }

        return ['score_pairs' => self::bool($config['score_pairs'] ?? false), 'start' => $start];
    }

    public function normalizeSolution(array $solution, array $config): array
    {
        $diagram = self::normalizeDiagram($solution);

        return $diagram['atoms'] === [] ? $this->defaultSolution() : $diagram; // ohne Atome nur manuelle Bewertung
    }

    public function normalizeContent(array $content, array $config): array
    {
        $diagram = self::normalizeDiagram($content);
        $this->assertSize($diagram);

        return $diagram;
    }

    /**
     * @return array{atoms: list<array<string, mixed>>, bonds: list<array<string, mixed>>, texts: list<array<string, mixed>>, arrows: list<array<string, mixed>>}
     */
    public static function emptyDiagram(): array
    {
        return ['atoms' => [], 'bonds' => [], 'texts' => [], 'arrows' => []];
    }

    /** Elementsymbol in Schreibweise "Xy" oder null, wenn es kein Element ist. */
    public static function symbol(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^[A-Za-z]{1,2}$/', $value) !== 1) {
            return null;
        }
        $value = ucfirst(strtolower($value));

        return in_array($value, self::ELEMENTS, true) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{atoms: list<array<string, mixed>>, bonds: list<array<string, mixed>>, texts: list<array<string, mixed>>, arrows: list<array<string, mixed>>}
     */
    public static function normalizeDiagram(array $data): array
    {
        $ids = [];
        $atoms = [];
        foreach (self::rows($data['atoms'] ?? [], self::MAX_ATOMS) as $a) {
            $id = self::safeId($a['id'] ?? null);
            $el = self::symbol($a['el'] ?? null);
            if ($id === null || isset($ids[$id]) || $el === null) {
                continue;
            }
            $ids[$id] = 'atom';
            $pairs = [];
            foreach (is_array($a['pairs'] ?? null) ? $a['pairs'] : [] as $side) {
                if (is_string($side) && in_array($side, self::SIDES, true) && !in_array($side, $pairs, true)) {
                    $pairs[] = $side;
                }
            }
            $atoms[] = [
                'id' => $id,
                'el' => $el,
                'x' => self::coord($a['x'] ?? 0),
                'y' => self::coord($a['y'] ?? 0),
                'charge' => self::intRange($a['charge'] ?? 0, -2, 3, 0),
                'pairs' => $pairs,
                'radicals' => self::intRange($a['radicals'] ?? 0, 0, 4, 0),
            ];
        }

        $bonds = [];
        $seen = [];
        foreach (self::rows($data['bonds'] ?? [], self::MAX_BONDS) as $b) {
            $id = self::safeId($b['id'] ?? null);
            $from = self::safeId($b['a'] ?? null);
            $to = self::safeId($b['b'] ?? null);
            if ($id === null || isset($ids[$id]) || $from === null || $to === null || $from === $to
                || ($ids[$from] ?? '') !== 'atom' || ($ids[$to] ?? '') !== 'atom') {
                continue;
            }
            $pair = $from < $to ? $from . '|' . $to : $to . '|' . $from;
            if (isset($seen[$pair])) {
                continue;
            }
            $seen[$pair] = true;
            $ids[$id] = 'bond';
            $order = self::intRange($b['order'] ?? 1, 1, 3, 1);
            $bonds[] = [
                'id' => $id,
                'a' => $from,
                'b' => $to,
                'order' => $order,
                'stereo' => $order === 1 ? self::oneOf($b['stereo'] ?? 'none', self::STEREO, 'none') : 'none',
            ];
        }

        $texts = [];
        foreach (self::rows($data['texts'] ?? [], self::MAX_TEXTS) as $t) {
            $id = self::safeId($t['id'] ?? null);
            $text = self::line($t['text'] ?? '', 80);
            if ($id === null || isset($ids[$id]) || $text === '') {
                continue;
            }
            $ids[$id] = 'text';
            $texts[] = ['id' => $id, 'x' => self::coord($t['x'] ?? 0), 'y' => self::coord($t['y'] ?? 0), 'text' => $text];
        }

        $arrows = [];
        foreach (self::rows($data['arrows'] ?? [], self::MAX_ARROWS) as $r) {
            $id = self::safeId($r['id'] ?? null);
            if ($id === null || isset($ids[$id])) {
                continue;
            }
            $ids[$id] = 'arrow';
            $arrows[] = [
                'id' => $id,
                'x' => self::coord($r['x'] ?? 0),
                'y' => self::coord($r['y'] ?? 0),
                'len' => self::intRange($r['len'] ?? 100, 40, 400, 100),
                'kind' => self::oneOf($r['kind'] ?? 'forward', self::ARROWS, 'forward'),
            ];
        }

        return ['atoms' => $atoms, 'bonds' => $bonds, 'texts' => $texts, 'arrows' => $arrows];
    }

    public function isAnswered(array $content): bool
    {
        return ($content['atoms'] ?? []) !== [] || ($content['texts'] ?? []) !== [] || ($content['arrows'] ?? []) !== [];
    }

    public function autoCheck(array $config, array $solution, array $content): ?array
    {
        if (($solution['atoms'] ?? []) === []) {
            return null;
        }

        return StructureComparer::compare($solution, $content, $config);
    }

    public function toText(array $content, array $config): string
    {
        $lines = [];
        $n = 0;
        foreach (StructureComparer::molecules($content) as $mol) {
            $n++;
            $label = [];
            foreach ($mol['atoms'] as $a) {
                $label[(string) $a['id']] = (string) $a['el'];
            }
            $bonds = [];
            foreach ($mol['bonds'] as $b) {
                $bonds[] = $label[$b['a']] . [1 => '-', 2 => '=', 3 => '≡'][(int) $b['order']] . $label[$b['b']];
            }
            $lines[] = "Molekül {$n}: " . StructureComparer::formulaText($mol['counts']) . ', Ladung ' . StructureComparer::chargeText($mol['charge'])
                . ($bonds !== [] ? '; Bindungen: ' . implode(', ', $bonds) : '');
        }
        foreach ($content['arrows'] ?? [] as $a) {
            $lines[] = 'Reaktionspfeil ' . ($a['kind'] === 'equilibrium' ? '⇌' : '→');
        }
        foreach ($content['texts'] ?? [] as $t) {
            $lines[] = 'Beschriftung: ' . $t['text'];
        }

        return implode("\n", $lines);
    }
}
