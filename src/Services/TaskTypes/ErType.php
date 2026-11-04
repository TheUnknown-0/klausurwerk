<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

use App\Services\Diagram\DiagramTaskType;
use App\Services\Diagram\ErComparer;

/**
 * Antworttyp "er" (Entity-Relationship-Diagramm in Chen-Notation).
 * Format: docs/DATENFORMATE.md, 2.14. Das Attribut-Feld "owner" verweist auf eine
 * Entität oder eine Beziehung.
 */
final class ErType extends DiagramTaskType
{
    public const CARDINALITIES = ['1', 'n', 'm', '0..1', '0..n', '1..n'];
    public const MAX_ENTITIES = 30;
    public const MAX_ATTRIBUTES = 150;
    public const MAX_RELATIONSHIPS = 50;

    public function name(): string
    {
        return 'er';
    }

    public function label(): string
    {
        return 'ER-Diagramm (Chen)';
    }

    public function defaultConfig(): array
    {
        return ['notation' => 'chen'];
    }

    public function defaultSolution(): array
    {
        return ['entities' => [], 'attributes' => [], 'relationships' => []];
    }

    public function normalizeConfig(array $config): array
    {
        return ['notation' => 'chen'];
    }

    public function normalizeSolution(array $solution, array $config): array
    {
        $diagram = self::normalizeDiagram($solution);
        if ($diagram['entities'] === []) {
            return $this->defaultSolution(); // ohne Musterlösung nur manuelle Bewertung
        }
        foreach ($diagram['entities'] as $e) {
            if ($e['name'] === '') {
                throw new \InvalidArgumentException('In der Musterlösung hat eine Entität keinen Namen.');
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
     * @return array{entities: list<array<string, mixed>>, attributes: list<array<string, mixed>>, relationships: list<array<string, mixed>>}
     */
    public static function normalizeDiagram(array $data): array
    {
        $ids = [];
        $entities = [];
        foreach (self::rows($data['entities'] ?? [], self::MAX_ENTITIES) as $e) {
            $id = self::safeId($e['id'] ?? null);
            if ($id === null || isset($ids[$id])) {
                continue;
            }
            $ids[$id] = 'entity';
            $entities[] = [
                'id' => $id,
                'name' => self::line($e['name'] ?? '', 60),
                'x' => self::coord($e['x'] ?? 0),
                'y' => self::coord($e['y'] ?? 0),
                'weak' => self::bool($e['weak'] ?? false),
            ];
        }

        $relationships = [];
        foreach (self::rows($data['relationships'] ?? [], self::MAX_RELATIONSHIPS) as $r) {
            $id = self::safeId($r['id'] ?? null);
            if ($id === null || isset($ids[$id])) {
                continue;
            }
            $ids[$id] = 'relationship';
            $ends = [];
            foreach (self::rows($r['ends'] ?? [], 4) as $end) {
                $entity = self::safeId($end['entity'] ?? null);
                if ($entity === null || ($ids[$entity] ?? '') !== 'entity') {
                    continue;
                }
                $ends[] = ['entity' => $entity, 'cardinality' => self::oneOf($end['cardinality'] ?? '1', self::CARDINALITIES, '1')];
            }
            $relationships[] = [
                'id' => $id,
                'name' => self::line($r['name'] ?? '', 60),
                'x' => self::coord($r['x'] ?? 0),
                'y' => self::coord($r['y'] ?? 0),
                'ends' => $ends,
            ];
        }
        $attributes = [];
        $attrIds = [];
        foreach (self::rows($data['attributes'] ?? [], self::MAX_ATTRIBUTES) as $a) {
            $id = self::safeId($a['id'] ?? null);
            $owner = self::safeId($a['owner'] ?? null);
            if ($id === null || isset($ids[$id]) || isset($attrIds[$id]) || $owner === null || !isset($ids[$owner])) {
                continue;
            }
            $attrIds[$id] = true;
            $attributes[] = [
                'id' => $id,
                'owner' => $owner,
                'name' => self::line($a['name'] ?? '', 60),
                'key' => self::bool($a['key'] ?? false),
                'x' => self::coord($a['x'] ?? 0),
                'y' => self::coord($a['y'] ?? 0),
            ];
        }

        return ['entities' => $entities, 'attributes' => $attributes, 'relationships' => $relationships];
    }

    public function isAnswered(array $content): bool
    {
        return ($content['entities'] ?? []) !== [] || ($content['relationships'] ?? []) !== [];
    }

    public function autoCheck(array $config, array $solution, array $content): ?array
    {
        if (($solution['entities'] ?? []) === []) {
            return null;
        }

        return ErComparer::compare($solution, $content);
    }

    public function toText(array $content, array $config): string
    {
        $names = [];
        foreach (array_merge($content['entities'] ?? [], $content['relationships'] ?? []) as $n) {
            $names[(string) $n['id']] = (string) $n['name'];
        }
        $lines = [];
        foreach ($content['entities'] ?? [] as $e) {
            $attrs = [];
            foreach ($content['attributes'] ?? [] as $a) {
                if ($a['owner'] === $e['id']) {
                    $attrs[] = ($a['key'] ? '_' . $a['name'] . '_' : $a['name']);
                }
            }
            $lines[] = ($e['weak'] ? 'Schwache Entität ' : 'Entität ') . $e['name'] . ($attrs !== [] ? ' (' . implode(', ', $attrs) . ')' : '');
        }
        foreach ($content['relationships'] ?? [] as $r) {
            $ends = array_map(static fn (array $end): string => ($names[$end['entity']] ?? '?') . ' [' . $end['cardinality'] . ']', $r['ends']);
            $attrs = [];
            foreach ($content['attributes'] ?? [] as $a) {
                if ($a['owner'] === $r['id']) {
                    $attrs[] = $a['name'];
                }
            }
            $lines[] = 'Beziehung ' . ($r['name'] !== '' ? $r['name'] : '(ohne Namen)') . ': ' . implode(' – ', $ends)
                . ($attrs !== [] ? ' (' . implode(', ', $attrs) . ')' : '');
        }

        return implode("\n", $lines);
    }
}
