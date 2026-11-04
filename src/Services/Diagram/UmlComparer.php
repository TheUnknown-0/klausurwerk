<?php

declare(strict_types=1);

namespace App\Services\Diagram;

/**
 * Vergleich eines UML-Klassendiagramms mit dem Musterdiagramm (nur als Vorschlag).
 *
 * Klassen werden über den normalisierten Namen zugeordnet (Groß/Kleinschreibung und
 * Sonderzeichen egal), Attribute und Methoden über Name und Typ, Beziehungen über
 * Art und die Namen der Endpunkte. Sichtbarkeit und Parameterlisten bleiben außen vor.
 */
final class UmlComparer
{
    private const UNDIRECTED = ['association'];

    private const KIND_LABEL = [
        'class' => 'Klasse', 'abstract' => 'abstrakte Klasse', 'interface' => 'Interface', 'enum' => 'Enum',
    ];

    private const REL_LABEL = [
        'association' => 'Assoziation', 'directed' => 'gerichtete Assoziation', 'aggregation' => 'Aggregation',
        'composition' => 'Komposition', 'inheritance' => 'Vererbung', 'realization' => 'Realisierung',
        'dependency' => 'Abhängigkeit',
    ];

    /**
     * @param array<string, mixed> $solution normalisiertes Musterdiagramm
     * @param array<string, mixed> $content normalisierte Antwort
     * @return array{ratio: float, details: array<string, mixed>}
     */
    public static function compare(array $solution, array $content): array
    {
        $items = [];
        $extra = [];

        $stuByName = [];
        foreach ($content['classes'] ?? [] as $c) {
            $stuByName[DiagramTaskType::norm((string) $c['name'])] ??= $c;
        }
        $matchedStu = [];

        foreach ($solution['classes'] ?? [] as $sol) {
            $name = (string) $sol['name'];
            $stu = $stuByName[DiagramTaskType::norm($name)] ?? null;
            $items[] = ['id' => 'class:' . $sol['id'], 'label' => "Klasse {$name}", 'ok' => $stu !== null];
            if ($stu !== null) {
                $matchedStu[(string) $stu['id']] = true;
            }
            if ($sol['kind'] !== 'class') {
                $items[] = [
                    'id' => 'kind:' . $sol['id'],
                    'label' => "{$name} ist " . self::KIND_LABEL[$sol['kind']],
                    'ok' => $stu !== null && $stu['kind'] === $sol['kind'],
                ];
            }

            $stuAttrs = [];
            foreach ($stu['attributes'] ?? [] as $a) {
                $stuAttrs[DiagramTaskType::norm((string) $a['name'])] ??= $a;
            }
            $usedAttrs = [];
            foreach ($sol['attributes'] ?? [] as $i => $attr) {
                $key = DiagramTaskType::norm((string) $attr['name']);
                $found = $stuAttrs[$key] ?? null;
                $ok = $found !== null
                    && ((string) $attr['type'] === '' || DiagramTaskType::normType((string) $attr['type']) === DiagramTaskType::normType((string) $found['type']));
                if ($found !== null) {
                    $usedAttrs[$key] = true;
                }
                $label = "Attribut {$name}.{$attr['name']}" . ($attr['type'] !== '' ? ": {$attr['type']}" : '');
                if ($found !== null && !$ok) {
                    $label .= ' (Typ weicht ab)';
                }
                $items[] = ['id' => 'attr:' . $sol['id'] . ':' . $i, 'label' => $label, 'ok' => $ok];
            }
            foreach ($stuAttrs as $key => $a) {
                if (!isset($usedAttrs[$key])) {
                    $extra[] = "Attribut {$name}.{$a['name']}";
                }
            }

            $stuMethods = [];
            foreach ($stu['methods'] ?? [] as $m) {
                $stuMethods[DiagramTaskType::norm((string) $m['name'])] ??= $m;
            }
            $usedMethods = [];
            foreach ($sol['methods'] ?? [] as $i => $method) {
                $key = DiagramTaskType::norm((string) $method['name']);
                $found = $stuMethods[$key] ?? null;
                $ok = $found !== null
                    && ((string) $method['return_type'] === '' || DiagramTaskType::normType((string) $method['return_type']) === DiagramTaskType::normType((string) $found['return_type']));
                if ($found !== null) {
                    $usedMethods[$key] = true;
                }
                $label = "Methode {$name}.{$method['name']}()" . ($method['return_type'] !== '' ? ": {$method['return_type']}" : '');
                if ($found !== null && !$ok) {
                    $label .= ' (Rückgabetyp weicht ab)';
                }
                $items[] = ['id' => 'method:' . $sol['id'] . ':' . $i, 'label' => $label, 'ok' => $ok];
            }
            foreach ($stuMethods as $key => $m) {
                if (!isset($usedMethods[$key])) {
                    $extra[] = "Methode {$name}.{$m['name']}()";
                }
            }
        }

        foreach ($content['classes'] ?? [] as $c) {
            if (!isset($matchedStu[(string) $c['id']])) {
                $extra[] = 'Klasse ' . $c['name'];
            }
        }

        // Beziehungen
        $solNames = self::nameMap($solution);
        $stuNames = self::nameMap($content);
        $stuRels = [];
        foreach ($content['relations'] ?? [] as $r) {
            $stuRels[] = [
                'kind' => $r['kind'],
                'from' => $stuNames[$r['from']] ?? '',
                'to' => $stuNames[$r['to']] ?? '',
                'used' => false,
                'label' => $r,
            ];
        }
        foreach ($solution['relations'] ?? [] as $rel) {
            $from = $solNames[$rel['from']] ?? '';
            $to = $solNames[$rel['to']] ?? '';
            $ok = false;
            foreach ($stuRels as &$cand) {
                if ($cand['used'] || $cand['kind'] !== $rel['kind']) {
                    continue;
                }
                $same = $cand['from'] === $from && $cand['to'] === $to;
                $swapped = in_array($rel['kind'], self::UNDIRECTED, true) && $cand['from'] === $to && $cand['to'] === $from;
                if ($same || $swapped) {
                    $cand['used'] = true;
                    $ok = true;
                    break;
                }
            }
            unset($cand);
            $items[] = [
                'id' => 'rel:' . $rel['id'],
                'label' => self::REL_LABEL[$rel['kind']] . ' ' . self::display($solution, (string) $rel['from']) . ' → ' . self::display($solution, (string) $rel['to']),
                'ok' => $ok,
            ];
        }
        foreach ($stuRels as $cand) {
            if (!$cand['used'] && $cand['from'] !== '' && $cand['to'] !== '') {
                $r = $cand['label'];
                $extra[] = self::REL_LABEL[$r['kind']] . ' ' . self::display($content, (string) $r['from']) . ' → ' . self::display($content, (string) $r['to']);
            }
        }

        return DiagramTaskType::comparisonResult('uml', $items, $extra);
    }

    /** @return array<string, string> Klassen-ID => normalisierter Name */
    private static function nameMap(array $diagram): array
    {
        $map = [];
        foreach ($diagram['classes'] ?? [] as $c) {
            $map[(string) $c['id']] = DiagramTaskType::norm((string) $c['name']);
        }

        return $map;
    }

    private static function display(array $diagram, string $id): string
    {
        foreach ($diagram['classes'] ?? [] as $c) {
            if ((string) $c['id'] === $id) {
                return (string) $c['name'];
            }
        }

        return '?';
    }
}
