<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

use App\Services\Diagram\DiagramTaskType;

/**
 * Antworttyp "flowchart" (Programmablaufplan nach DIN 66001).
 * Format: docs/DATENFORMATE.md, 2.10. Keine automatische Bewertung; die Musterlösung
 * (gleiche Struktur wie der Inhalt) ist optional und dient der Korrektur.
 */
final class FlowchartType extends DiagramTaskType
{
    public const NODE_TYPES = ['start', 'end', 'process', 'decision', 'io', 'subroutine', 'connector'];
    public const MAX_NODES = 120;
    public const MAX_EDGES = 300;

    private const TYPE_LABEL = [
        'start' => 'Start', 'end' => 'Ende', 'process' => 'Operation', 'decision' => 'Verzweigung',
        'io' => 'Ein-/Ausgabe', 'subroutine' => 'Unterprogramm', 'connector' => 'Verbinder',
    ];

    public function name(): string
    {
        return 'flowchart';
    }

    public function label(): string
    {
        return 'Programmablaufplan';
    }

    public function defaultSolution(): array
    {
        return ['nodes' => [], 'edges' => []];
    }

    public function normalizeConfig(array $config): array
    {
        return [];
    }

    public function normalizeSolution(array $solution, array $config): array
    {
        return $solution === [] ? $this->defaultSolution() : $this->normalizeContent($solution, $config);
    }

    public function normalizeContent(array $content, array $config): array
    {
        $nodes = [];
        $ids = [];
        foreach (self::rows($content['nodes'] ?? [], self::MAX_NODES) as $n) {
            $id = self::safeId($n['id'] ?? null);
            if ($id === null || isset($ids[$id])) {
                continue;
            }
            $ids[$id] = true;
            $nodes[] = [
                'id' => $id,
                'type' => self::oneOf($n['type'] ?? 'process', self::NODE_TYPES, 'process'),
                'text' => self::clean($n['text'] ?? '', 200),
                'x' => self::coord($n['x'] ?? 0),
                'y' => self::coord($n['y'] ?? 0),
            ];
        }
        $edges = [];
        $edgeIds = [];
        foreach (self::rows($content['edges'] ?? [], self::MAX_EDGES) as $e) {
            $id = self::safeId($e['id'] ?? null);
            $from = self::safeId($e['from'] ?? null);
            $to = self::safeId($e['to'] ?? null);
            if ($id === null || isset($edgeIds[$id]) || $from === null || $to === null || !isset($ids[$from], $ids[$to])) {
                continue;
            }
            $edgeIds[$id] = true;
            $edges[] = ['id' => $id, 'from' => $from, 'to' => $to, 'label' => self::line($e['label'] ?? '', 40)];
        }
        $result = ['nodes' => $nodes, 'edges' => $edges];
        $this->assertSize($result);

        return $result;
    }

    public function isAnswered(array $content): bool
    {
        return ($content['nodes'] ?? []) !== [];
    }

    public function toText(array $content, array $config): string
    {
        $label = [];
        $lines = [];
        $i = 0;
        foreach ($content['nodes'] ?? [] as $n) {
            $i++;
            $label[(string) $n['id']] = $i;
            $text = str_replace("\n", ' ', (string) $n['text']);
            $lines[] = "[{$i}] " . self::TYPE_LABEL[$n['type']] . ($text !== '' ? ': ' . $text : '');
        }
        foreach ($content['edges'] ?? [] as $e) {
            $lines[] = '[' . ($label[$e['from']] ?? '?') . '] -> [' . ($label[$e['to']] ?? '?') . ']' . ($e['label'] !== '' ? ' (' . $e['label'] . ')' : '');
        }

        return implode("\n", $lines);
    }
}
