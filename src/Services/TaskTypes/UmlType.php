<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

use App\Services\Diagram\DiagramTaskType;
use App\Services\Diagram\UmlComparer;

/**
 * Antworttyp "uml" (Klassendiagramm). Format: docs/DATENFORMATE.md, 2.8.
 *
 * Beziehungen: from = Ausgangsklasse (bei Vererbung/Realisierung die Unterklasse,
 * bei Aggregation/Komposition das Ganze), to = Zielklasse (Pfeilspitze, Dreieck).
 * Optional je Beziehung: route = straight | orthogonal (Darstellung).
 */
final class UmlType extends DiagramTaskType
{
    public const KINDS = ['class', 'abstract', 'interface', 'enum'];
    public const RELATIONS = ['association', 'directed', 'aggregation', 'composition', 'inheritance', 'realization', 'dependency'];
    public const VISIBILITY = ['-', '+', '#', '~'];
    public const MAX_CLASSES = 60;
    public const MAX_MEMBERS = 60;
    public const MAX_RELATIONS = 200;

    public function name(): string
    {
        return 'uml';
    }

    public function label(): string
    {
        return 'UML-Klassendiagramm';
    }

    public function defaultConfig(): array
    {
        return ['diagram' => 'class', 'allow_interfaces' => true];
    }

    public function defaultSolution(): array
    {
        return ['classes' => [], 'relations' => []];
    }

    public function normalizeConfig(array $config): array
    {
        return [
            'diagram' => 'class',
            'allow_interfaces' => self::bool($config['allow_interfaces'] ?? true),
        ];
    }

    public function normalizeSolution(array $solution, array $config): array
    {
        $diagram = self::normalizeDiagram($solution);
        if ($diagram['classes'] === []) {
            return $this->defaultSolution(); // ohne Musterdiagramm gibt es keinen Vorschlag, nur manuelle Bewertung
        }
        foreach ($diagram['classes'] as $c) {
            if ($c['name'] === '') {
                throw new \InvalidArgumentException('Im Musterdiagramm hat eine Klasse keinen Namen.');
            }
        }

        return $diagram;
    }

    public function normalizeContent(array $content, array $config): array
    {
        $diagram = self::normalizeDiagram($content);
        $this->assertSize($diagram);

        return $diagram;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{classes: list<array<string, mixed>>, relations: list<array<string, mixed>>}
     */
    public static function normalizeDiagram(array $data): array
    {
        $classes = [];
        $ids = [];
        foreach (self::rows($data['classes'] ?? [], self::MAX_CLASSES) as $c) {
            $id = self::safeId($c['id'] ?? null);
            if ($id === null || isset($ids[$id])) {
                continue;
            }
            $ids[$id] = true;

            $attributes = [];
            foreach (self::rows($c['attributes'] ?? [], self::MAX_MEMBERS) as $a) {
                $attributes[] = [
                    'visibility' => self::oneOf($a['visibility'] ?? '-', self::VISIBILITY, '-'),
                    'name' => self::line($a['name'] ?? '', 80),
                    'type' => self::line($a['type'] ?? '', 80),
                    'static' => self::bool($a['static'] ?? false),
                ];
            }
            $methods = [];
            foreach (self::rows($c['methods'] ?? [], self::MAX_MEMBERS) as $m) {
                $methods[] = [
                    'visibility' => self::oneOf($m['visibility'] ?? '+', self::VISIBILITY, '+'),
                    'name' => self::line($m['name'] ?? '', 80),
                    'params' => self::line($m['params'] ?? '', 200),
                    'return_type' => self::line($m['return_type'] ?? '', 80),
                    'static' => self::bool($m['static'] ?? false),
                    'abstract' => self::bool($m['abstract'] ?? false),
                ];
            }
            $classes[] = [
                'id' => $id,
                'name' => self::line($c['name'] ?? '', 80),
                'kind' => self::oneOf($c['kind'] ?? 'class', self::KINDS, 'class'),
                'x' => self::coord($c['x'] ?? 0),
                'y' => self::coord($c['y'] ?? 0),
                'attributes' => $attributes,
                'methods' => $methods,
            ];
        }

        $relations = [];
        $relIds = [];
        foreach (self::rows($data['relations'] ?? [], self::MAX_RELATIONS) as $r) {
            $id = self::safeId($r['id'] ?? null);
            $from = self::safeId($r['from'] ?? null);
            $to = self::safeId($r['to'] ?? null);
            if ($id === null || isset($relIds[$id]) || $from === null || $to === null || !isset($ids[$from], $ids[$to])) {
                continue;
            }
            $relIds[$id] = true;
            $relations[] = [
                'id' => $id,
                'from' => $from,
                'to' => $to,
                'kind' => self::oneOf($r['kind'] ?? 'association', self::RELATIONS, 'association'),
                'label' => self::line($r['label'] ?? '', 80),
                'mult_from' => self::line($r['mult_from'] ?? '', 12),
                'mult_to' => self::line($r['mult_to'] ?? '', 12),
                'route' => self::oneOf($r['route'] ?? 'orthogonal', ['orthogonal', 'straight'], 'orthogonal'),
            ];
        }

        return ['classes' => $classes, 'relations' => $relations];
    }

    public function isAnswered(array $content): bool
    {
        return ($content['classes'] ?? []) !== [];
    }

    public function autoCheck(array $config, array $solution, array $content): ?array
    {
        if (($solution['classes'] ?? []) === []) {
            return null;
        }

        return UmlComparer::compare($solution, $content);
    }

    public function toText(array $content, array $config): string
    {
        $names = [];
        foreach ($content['classes'] ?? [] as $c) {
            $names[(string) $c['id']] = (string) $c['name'];
        }
        $lines = [];
        $prefix = ['abstract' => 'abstract ', 'interface' => '«interface» ', 'enum' => '«enumeration» ', 'class' => ''];
        foreach ($content['classes'] ?? [] as $c) {
            $lines[] = ($prefix[$c['kind']] ?? '') . 'class ' . $c['name'];
            foreach ($c['attributes'] as $a) {
                $lines[] = '  ' . $a['visibility'] . ' ' . ($a['static'] ? 'static ' : '') . $a['name'] . ($a['type'] !== '' ? ': ' . $a['type'] : '');
            }
            foreach ($c['methods'] as $m) {
                $lines[] = '  ' . $m['visibility'] . ' ' . ($m['static'] ? 'static ' : '') . ($m['abstract'] ? 'abstract ' : '')
                    . $m['name'] . '(' . $m['params'] . ')' . ($m['return_type'] !== '' ? ': ' . $m['return_type'] : '');
            }
        }
        $arrows = [
            'association' => '---', 'directed' => '-->', 'aggregation' => 'o--', 'composition' => '*--',
            'inheritance' => '--|>', 'realization' => '..|>', 'dependency' => '..>',
        ];
        foreach ($content['relations'] ?? [] as $r) {
            $line = ($names[$r['from']] ?? '?') . ($r['mult_from'] !== '' ? ' "' . $r['mult_from'] . '"' : '')
                . ' ' . ($arrows[$r['kind']] ?? '---') . ' '
                . ($r['mult_to'] !== '' ? '"' . $r['mult_to'] . '" ' : '') . ($names[$r['to']] ?? '?');
            if ($r['label'] !== '') {
                $line .= ' : ' . $r['label'];
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
