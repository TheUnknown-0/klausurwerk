<?php

declare(strict_types=1);

namespace App\Services\Diagram;

/**
 * Vergleich zweier Strukturformeln (Antworttyp "structure") als Vorschlag für die Bewertung.
 *
 * Je zusammenhängendem Molekül werden Summenformel und Gesamtladung verglichen, danach das
 * Bindungsgerüst als Graph-Isomorphie (Elemente = Knotenfarben, Bindungsordnung = Kantenfarbe,
 * Backtracking mit Farbverfeinerung). Freie Elektronenpaare, Radikale und Atomladungen zählen nur,
 * wenn "score_pairs" eingeschaltet ist. Reaktionspfeile werden nach Art gezählt.
 */
final class StructureComparer
{
    /** Obergrenze der Rückverfolgungs-Schritte je Vergleich (Schutz vor Rechenzeit-Explosion). */
    private const MAX_STEPS = 400000;

    private const SUB = ['0' => '₀', '1' => '₁', '2' => '₂', '3' => '₃', '4' => '₄', '5' => '₅', '6' => '₆', '7' => '₇', '8' => '₈', '9' => '₉'];

    /**
     * @param array<string, mixed> $solution
     * @param array<string, mixed> $content
     * @param array<string, mixed> $config
     * @return array{ratio: float, details: array<string, mixed>}
     */
    public static function compare(array $solution, array $content, array $config = []): array
    {
        $pairs = !empty($config['score_pairs']);
        $sol = self::molecules($solution);
        $stu = self::molecules($content);

        // Zuordnung: beste Paare zuerst (Gerüst > Summenformel > Ladung > Elementüberlappung).
        $cand = [];
        foreach ($sol as $i => $s) {
            foreach ($stu as $j => $t) {
                $same = self::sameFormula($s, $t);
                $iso = $same && self::isomorphic($s, $t, $pairs);
                $score = ($iso ? 4.0 : 0.0) + ($same ? 2.0 : 0.0) + ($s['charge'] === $t['charge'] ? 1.0 : 0.0) + self::overlap($s['counts'], $t['counts']);
                if ($score > 0.5) {
                    $cand[] = [$score, $i, $j];
                }
            }
        }
        usort($cand, static fn (array $a, array $b): int => [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]]);
        $match = [];
        $used = [];
        foreach ($cand as [, $i, $j]) {
            if (!isset($match[$i]) && !isset($used[$j])) {
                $match[$i] = $j;
                $used[$j] = true;
            }
        }

        $items = [];
        $extra = [];
        foreach ($sol as $i => $s) {
            $t = isset($match[$i]) ? $stu[$match[$i]] : null;
            $name = self::formulaText($s['counts']);
            $id = 'mol:' . $i;
            if ($t === null) {
                $items[] = ['id' => $id . ':formula', 'label' => "Summenformel {$name} (Molekül fehlt)", 'ok' => false];
                $items[] = ['id' => $id . ':skeleton', 'label' => "Bindungsgerüst von {$name} (Molekül fehlt)", 'ok' => false];
                if ($pairs) {
                    $items[] = ['id' => $id . ':pairs', 'label' => "Elektronenpaare und Ladungen von {$name} (Molekül fehlt)", 'ok' => false];
                }
                if ($s['charge'] !== 0) {
                    $items[] = ['id' => $id . ':charge', 'label' => 'Gesamtladung ' . self::chargeText($s['charge']) . " von {$name}", 'ok' => false];
                }
                continue;
            }
            $same = self::sameFormula($s, $t);
            $iso = $same && self::isomorphic($s, $t, false);
            $items[] = [
                'id' => $id . ':formula',
                'label' => $same ? "Summenformel {$name}" : "Summenformel {$name}: " . self::formulaHint($s['counts'], $t['counts']),
                'ok' => $same,
            ];
            if ($s['charge'] !== 0) {
                $ok = $s['charge'] === $t['charge'];
                $items[] = [
                    'id' => $id . ':charge',
                    'label' => 'Gesamtladung ' . self::chargeText($s['charge']) . " von {$name}" . ($ok ? '' : ' (gezeichnet: ' . self::chargeText($t['charge']) . ')'),
                    'ok' => $ok,
                ];
            } elseif ($t['charge'] !== 0) {
                $extra[] = "Ladung {$name} " . self::chargeText($t['charge']);
            }
            $items[] = [
                'id' => $id . ':skeleton',
                'label' => $iso ? "Bindungsgerüst von {$name}" : "Bindungsgerüst von {$name}: " . self::skeletonHint($s, $t, $same),
                'ok' => $iso,
            ];
            if ($pairs) {
                $full = $iso && self::isomorphic($s, $t, true);
                $items[] = [
                    'id' => $id . ':pairs',
                    'label' => $full ? "Elektronenpaare und Ladungen von {$name}" : "Elektronenpaare, Radikale oder Atomladungen von {$name} weichen ab",
                    'ok' => $full,
                ];
            }
        }
        foreach ($stu as $j => $t) {
            if (!isset($used[$j])) {
                $extra[] = 'Molekül ' . self::formulaText($t['counts']);
            }
        }

        // Reaktionspfeile nach Art
        $stuArrows = array_map(static fn (array $a): string => (string) $a['kind'], $content['arrows'] ?? []);
        foreach ($solution['arrows'] ?? [] as $a) {
            $kind = (string) $a['kind'];
            $k = array_search($kind, $stuArrows, true);
            if ($k !== false) {
                unset($stuArrows[$k]);
            }
            $items[] = ['id' => 'arrow:' . $a['id'], 'label' => 'Reaktionspfeil ' . ($kind === 'equilibrium' ? '⇌' : '→'), 'ok' => $k !== false];
        }
        foreach ($stuArrows as $kind) {
            $extra[] = 'Reaktionspfeil ' . ($kind === 'equilibrium' ? '⇌' : '→');
        }

        return DiagramTaskType::comparisonResult('structure', $items, $extra);
    }

    // ---------- Moleküle ----------

    /**
     * Zusammenhängende Moleküle (nach Position von links nach rechts, oben nach unten).
     *
     * @param array<string, mixed> $diagram
     * @return list<array{atoms: list<array<string, mixed>>, bonds: list<array<string, mixed>>, counts: array<string, int>, charge: int}>
     */
    public static function molecules(array $diagram): array
    {
        $atoms = [];
        foreach ($diagram['atoms'] ?? [] as $a) {
            $atoms[(string) $a['id']] = $a;
        }
        $parent = [];
        foreach ($atoms as $id => $_) {
            $parent[$id] = $id;
        }
        $find = static function (string $x) use (&$parent): string {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };
        $bonds = [];
        foreach ($diagram['bonds'] ?? [] as $b) {
            if (isset($atoms[$b['a']], $atoms[$b['b']])) {
                $bonds[] = $b;
                $parent[$find((string) $b['a'])] = $find((string) $b['b']);
            }
        }
        $groups = [];
        foreach ($atoms as $id => $a) {
            $groups[$find((string) $id)]['atoms'][] = $a;
        }
        $out = [];
        foreach ($groups as $root => $g) {
            $counts = [];
            $charge = 0;
            $minX = PHP_INT_MAX;
            $minY = PHP_INT_MAX;
            foreach ($g['atoms'] as $a) {
                $counts[(string) $a['el']] = ($counts[(string) $a['el']] ?? 0) + 1;
                $charge += (int) $a['charge'];
                $minX = min($minX, (int) $a['x']);
                $minY = min($minY, (int) $a['y']);
            }
            $mine = array_values(array_filter($bonds, static fn (array $b): bool => $find((string) $b['a']) === $root));
            $out[] = ['atoms' => $g['atoms'], 'bonds' => $mine, 'counts' => $counts, 'charge' => $charge, 'x' => $minX, 'y' => $minY];
        }
        usort($out, static fn (array $p, array $q): int => [intdiv($p['x'], 40), $p['y']] <=> [intdiv($q['x'], 40), $q['y']]);

        return array_map(static function (array $m): array {
            unset($m['x'], $m['y']);

            return $m;
        }, $out);
    }

    /** Summenformel in Hill-Reihenfolge als Text mit Tiefzahlen, z. B. C₂H₆O. */
    public static function formulaText(array $counts): string
    {
        $symbols = array_keys($counts);
        sort($symbols, SORT_STRING);
        if (isset($counts['C'])) {
            $symbols = array_merge(['C'], isset($counts['H']) ? ['H'] : [], array_values(array_filter($symbols, static fn (string $e): bool => $e !== 'C' && $e !== 'H')));
        }
        $text = '';
        foreach ($symbols as $e) {
            $text .= $e . ($counts[$e] > 1 ? strtr((string) $counts[$e], self::SUB) : '');
        }

        return $text;
    }

    /** Ladung als Text: ungeladen, +, −, 2−, 3+ ... */
    public static function chargeText(int $charge): string
    {
        if ($charge === 0) {
            return 'ungeladen';
        }
        $sign = $charge > 0 ? '+' : '−';

        return (abs($charge) === 1 ? '' : (string) abs($charge)) . $sign;
    }

    private static function sameFormula(array $a, array $b): bool
    {
        $x = $a['counts'];
        $y = $b['counts'];
        ksort($x);
        ksort($y);

        return $x === $y;
    }

    /** Anteil übereinstimmender Atome (0 bis 1). */
    private static function overlap(array $a, array $b): float
    {
        $common = 0;
        foreach ($a as $e => $n) {
            $common += min($n, $b[$e] ?? 0);
        }
        $total = max(array_sum($a), array_sum($b));

        return $total > 0 ? $common / $total : 0.0;
    }

    // ---------- Hinweise ----------

    private static function formulaHint(array $want, array $have): string
    {
        $parts = [];
        foreach (array_unique(array_merge(array_keys($want), array_keys($have))) as $e) {
            $d = ($have[$e] ?? 0) - ($want[$e] ?? 0);
            if ($d < 0) {
                $parts[] = (-$d === 1 ? "ein {$e}-Atom fehlt" : (-$d) . " {$e}-Atome fehlen");
            } elseif ($d > 0) {
                $parts[] = ($d === 1 ? "ein {$e}-Atom ist zu viel" : "{$d} {$e}-Atome sind zu viel");
            }
        }

        return 'gezeichnet ist ' . self::formulaText($have) . ($parts !== [] ? ' (' . implode(', ', $parts) . ')' : '');
    }

    private static function skeletonHint(array $sol, array $stu, bool $sameFormula): string
    {
        $want = self::bondTypes($sol);
        $have = self::bondTypes($stu);
        $parts = [];
        foreach (array_unique(array_merge(array_keys($want), array_keys($have))) as $type) {
            $d = ($have[$type] ?? 0) - ($want[$type] ?? 0);
            if ($d < 0) {
                $parts[] = -$d === 1 ? "Bindung {$type} fehlt" : (-$d) . " Bindungen {$type} fehlen";
            } elseif ($d > 0) {
                $parts[] = $d === 1 ? "Bindung {$type} ist zu viel" : "{$d} Bindungen {$type} sind zu viel";
            }
        }
        $lead = $sameFormula ? 'Summenformel stimmt, aber ' : '';
        if ($parts === []) {
            return $lead . ($sameFormula ? 'die Atome sind anders verbunden' : 'die Atome sind anders verbunden');
        }

        return $lead . implode(' und ', $parts);
    }

    /** @return array<string, int> Bindungsart (z. B. "C=O") => Anzahl */
    private static function bondTypes(array $mol): array
    {
        $el = [];
        foreach ($mol['atoms'] as $a) {
            $el[(string) $a['id']] = (string) $a['el'];
        }
        $out = [];
        foreach ($mol['bonds'] as $b) {
            $pair = [$el[$b['a']], $el[$b['b']]];
            sort($pair, SORT_STRING);
            if ($pair[1] === 'C') {
                $pair = array_reverse($pair);
            }
            $key = $pair[0] . [1 => '-', 2 => '=', 3 => '≡'][(int) $b['order']] . $pair[1];
            $out[$key] = ($out[$key] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }

    // ---------- Graph-Isomorphie ----------

    /**
     * Sind die Bindungsgerüste gleich? Mit $pairs zählen auch Atomladung, Radikale und
     * Anzahl freier Elektronenpaare (nicht deren Seite) zur Knotenfarbe.
     */
    public static function isomorphic(array $a, array $b, bool $pairs): bool
    {
        $n = count($a['atoms']);
        if ($n !== count($b['atoms']) || count($a['bonds']) !== count($b['bonds'])) {
            return false;
        }
        $ga = self::graph($a, $pairs);
        $gb = self::graph($b, $pairs);
        [$ca, $cb] = self::refine($ga, $gb);
        $ha = array_count_values($ca);
        $hb = array_count_values($cb);
        ksort($ha);
        ksort($hb);
        if ($ha !== $hb) {
            return false;
        }

        // Reihenfolge: Start bei der seltensten Farbe, danach breitenweise (bleibt zusammenhängend).
        $order = [];
        $seen = [];
        $rare = array_keys($ca);
        usort($rare, static fn (int $p, int $q): int => [$ha[$ca[$p]], $p] <=> [$ha[$ca[$q]], $q]);
        foreach ($rare as $start) {
            if (isset($seen[$start])) {
                continue;
            }
            $queue = [$start];
            $seen[$start] = true;
            while ($queue !== []) {
                $v = array_shift($queue);
                $order[] = $v;
                foreach (array_keys($ga['adj'][$v]) as $w) {
                    if (!isset($seen[$w])) {
                        $seen[$w] = true;
                        $queue[] = $w;
                    }
                }
            }
        }

        $steps = 0;
        $map = [];
        $taken = [];
        $solve = static function (int $k) use (&$solve, &$steps, &$map, &$taken, $order, $ga, $gb, $ca, $cb): ?bool {
            if ($k === count($order)) {
                return true;
            }
            if (++$steps > self::MAX_STEPS) {
                return null;
            }
            $v = $order[$k];
            foreach ($cb as $w => $color) {
                if ($color !== $ca[$v] || isset($taken[$w])) {
                    continue;
                }
                $ok = true;
                foreach ($ga['adj'][$v] as $u => $order1) {
                    if (isset($map[$u]) && (($gb['adj'][$w][$map[$u]] ?? 0) !== $order1)) {
                        $ok = false;
                        break;
                    }
                }
                if (!$ok) {
                    continue;
                }
                $map[$v] = $w;
                $taken[$w] = true;
                $r = $solve($k + 1);
                if ($r !== false) {
                    return $r;
                }
                unset($map[$v], $taken[$w]);
            }

            return false;
        };
        $result = $solve(0);

        // Rechenbudget erschöpft: Farbverfeinerung stimmt überein, im Zweifel zugunsten der Schüler:innen.
        return $result !== false;
    }

    /**
     * @return array{color: array<int, string>, adj: array<int, array<int, int>>}
     */
    private static function graph(array $mol, bool $pairs): array
    {
        $index = [];
        $color = [];
        foreach (array_values($mol['atoms']) as $i => $a) {
            $index[(string) $a['id']] = $i;
            $color[$i] = (string) $a['el'] . ($pairs ? '|' . (int) $a['charge'] . '|' . count($a['pairs']) . '|' . (int) $a['radicals'] : '');
        }
        $adj = array_fill(0, count($color), []);
        foreach ($mol['bonds'] as $b) {
            $x = $index[$b['a']] ?? null;
            $y = $index[$b['b']] ?? null;
            if ($x !== null && $y !== null) {
                $adj[$x][$y] = (int) $b['order'];
                $adj[$y][$x] = (int) $b['order'];
            }
        }

        return ['color' => $color, 'adj' => $adj];
    }

    /**
     * Gemeinsame Farbverfeinerung (Weisfeiler-Lehman) beider Graphen.
     *
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private static function refine(array $ga, array $gb): array
    {
        $dict = [];
        $intern = static function (string $key) use (&$dict): int {
            return $dict[$key] ??= count($dict);
        };
        $ca = array_map($intern, $ga['color']);
        $cb = array_map($intern, $gb['color']);
        $classes = count(array_unique(array_merge($ca, $cb)));
        $step = static function (array $g, array $c) use ($intern): array {
            $out = [];
            foreach ($g['adj'] as $v => $nbrs) {
                $sig = [];
                foreach ($nbrs as $w => $order) {
                    $sig[] = $order . ':' . $c[$w];
                }
                sort($sig, SORT_STRING);
                $out[$v] = $intern($c[$v] . '|' . implode(',', $sig));
            }

            return $out;
        };
        for ($r = 0, $max = count($ca) + 1; $r < $max; $r++) {
            $dict = [];
            $na = $step($ga, $ca);
            $nb = $step($gb, $cb);
            $next = count(array_unique(array_merge($na, $nb)));
            $ca = $na;
            $cb = $nb;
            if ($next <= $classes) {
                break;
            }
            $classes = $next;
        }

        return [$ca, $cb];
    }
}
