<?php

declare(strict_types=1);

namespace App\Services\Diagram;

/**
 * Grober Vergleich eines ER-Diagramms (Chen) mit der Musterlösung (nur als Vorschlag):
 * Entitäten, Attribute (mit Schlüsselmarkierung) und Beziehungen mit Kardinalitäten.
 * Beziehungen werden über die Menge der beteiligten Entitätsnamen zugeordnet, der
 * Beziehungsname selbst ist egal.
 */
final class ErComparer
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

        $stuEntities = [];
        foreach ($content['entities'] ?? [] as $e) {
            $stuEntities[DiagramTaskType::norm((string) $e['name'])] ??= $e;
        }
        $usedEntities = [];
        foreach ($solution['entities'] ?? [] as $sol) {
            $name = (string) $sol['name'];
            $key = DiagramTaskType::norm($name);
            $stu = $stuEntities[$key] ?? null;
            $items[] = ['id' => 'entity:' . $sol['id'], 'label' => "Entität {$name}", 'ok' => $stu !== null];
            if ($stu !== null) {
                $usedEntities[$key] = true;
            }
            if (!empty($sol['weak'])) {
                $items[] = ['id' => 'weak:' . $sol['id'], 'label' => "{$name} ist schwach", 'ok' => $stu !== null && !empty($stu['weak'])];
            }
            $extra = array_merge($extra, self::attributeItems($solution, $content, $sol, $stu, $items));
        }
        foreach ($stuEntities as $key => $e) {
            if (!isset($usedEntities[$key])) {
                $extra[] = 'Entität ' . $e['name'];
            }
        }

        // Beziehungen
        $solEntityNames = self::entityNames($solution);
        $stuEntityNames = self::entityNames($content);
        $stuRels = [];
        foreach ($content['relationships'] ?? [] as $r) {
            $ends = [];
            foreach ($r['ends'] ?? [] as $end) {
                $ends[] = ['name' => $stuEntityNames[$end['entity']] ?? '', 'card' => $end['cardinality']];
            }
            $stuRels[] = ['key' => self::relKey($ends), 'ends' => $ends, 'used' => false, 'rel' => $r];
        }
        foreach ($solution['relationships'] ?? [] as $rel) {
            $ends = [];
            foreach ($rel['ends'] ?? [] as $end) {
                $ends[] = ['name' => $solEntityNames[$end['entity']] ?? '', 'card' => $end['cardinality']];
            }
            $key = self::relKey($ends);
            $label = 'Beziehung ' . ($rel['name'] !== '' ? $rel['name'] . ' ' : '') . '(' . implode(' – ', array_map(
                static fn (array $e): string => self::display($solution, $e['name']),
                $ends
            )) . ')';
            $match = null;
            foreach ($stuRels as $i => $cand) {
                if (!$cand['used'] && $cand['key'] === $key) {
                    $match = $i;
                    break;
                }
            }
            $items[] = ['id' => 'rel:' . $rel['id'], 'label' => $label, 'ok' => $match !== null];
            if ($match !== null) {
                $stuRels[$match]['used'] = true;
            }
            foreach ($ends as $j => $end) {
                $ok = false;
                if ($match !== null) {
                    foreach ($stuRels[$match]['ends'] as $cand) {
                        if ($cand['name'] === $end['name'] && $cand['card'] === $end['card']) {
                            $ok = true;
                            break;
                        }
                    }
                }
                $items[] = [
                    'id' => 'card:' . $rel['id'] . ':' . $j,
                    'label' => 'Kardinalität ' . $end['card'] . ' bei ' . self::display($solution, $end['name']) . ' in ' . ($rel['name'] !== '' ? $rel['name'] : 'der Beziehung'),
                    'ok' => $ok,
                ];
            }
        }
        foreach ($stuRels as $cand) {
            if (!$cand['used']) {
                $extra[] = 'Beziehung ' . ($cand['rel']['name'] !== '' ? $cand['rel']['name'] : '(ohne Namen)');
            }
        }

        return DiagramTaskType::comparisonResult('er', $items, $extra);
    }

    /**
     * Fügt die Attribut-Positionen einer Entität hinzu, liefert zusätzliche Attribute.
     *
     * @param array<string, mixed> $solution
     * @param array<string, mixed> $content
     * @param array<string, mixed> $sol
     * @param array<string, mixed>|null $stu
     * @param list<array{id: string, label: string, ok: bool}> $items
     * @return list<string>
     */
    private static function attributeItems(array $solution, array $content, array $sol, ?array $stu, array &$items): array
    {
        $extra = [];
        $solAttrs = array_values(array_filter($solution['attributes'] ?? [], static fn (array $a): bool => $a['owner'] === $sol['id']));
        $stuAttrs = [];
        if ($stu !== null) {
            foreach ($content['attributes'] ?? [] as $a) {
                if ($a['owner'] === $stu['id']) {
                    $stuAttrs[DiagramTaskType::norm((string) $a['name'])] ??= $a;
                }
            }
        }
        $used = [];
        foreach ($solAttrs as $attr) {
            $key = DiagramTaskType::norm((string) $attr['name']);
            $found = $stuAttrs[$key] ?? null;
            $items[] = ['id' => 'attr:' . $attr['id'], 'label' => "Attribut {$sol['name']}.{$attr['name']}", 'ok' => $found !== null];
            if ($found !== null) {
                $used[$key] = true;
            }
            if (!empty($attr['key'])) {
                $items[] = [
                    'id' => 'key:' . $attr['id'],
                    'label' => "Schlüssel {$sol['name']}.{$attr['name']}",
                    'ok' => $found !== null && !empty($found['key']),
                ];
            }
        }
        foreach ($stuAttrs as $key => $a) {
            if (!isset($used[$key])) {
                $extra[] = "Attribut {$stu['name']}.{$a['name']}";
            }
        }

        return $extra;
    }

    /** @return array<string, string> Entitäts-ID => normalisierter Name */
    private static function entityNames(array $diagram): array
    {
        $map = [];
        foreach ($diagram['entities'] ?? [] as $e) {
            $map[(string) $e['id']] = DiagramTaskType::norm((string) $e['name']);
        }

        return $map;
    }

    /** @param list<array{name: string, card: string}> $ends */
    private static function relKey(array $ends): string
    {
        $names = array_map(static fn (array $e): string => $e['name'], $ends);
        sort($names);

        return implode('|', $names);
    }

    /** Anzeigename zu einem normalisierten Namen (aus den Entitäten). */
    private static function display(array $diagram, string $norm): string
    {
        foreach ($diagram['entities'] ?? [] as $e) {
            if (DiagramTaskType::norm((string) $e['name']) === $norm) {
                return (string) $e['name'];
            }
        }

        return '?';
    }
}
