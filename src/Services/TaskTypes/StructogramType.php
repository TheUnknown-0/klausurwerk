<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

use App\Services\Diagram\DiagramTaskType;

/**
 * Antworttyp "structogram" (Nassi-Shneiderman-Diagramm nach DIN 66261).
 * Format: docs/DATENFORMATE.md, 2.9. Keine automatische Bewertung; die Musterlösung
 * (gleiche Struktur wie der Inhalt) ist optional und dient der Korrektur.
 */
final class StructogramType extends DiagramTaskType
{
    public const MAX_NODES = 300;
    public const MAX_DEPTH = 14;
    public const MAX_CASES = 12;

    public function name(): string
    {
        return 'structogram';
    }

    public function label(): string
    {
        return 'Struktogramm';
    }

    public function defaultSolution(): array
    {
        return ['root' => ['type' => 'sequence', 'children' => []]];
    }

    public function normalizeConfig(array $config): array
    {
        return [];
    }

    public function normalizeSolution(array $solution, array $config): array
    {
        if ($solution === [] || !isset($solution['root'])) {
            return $this->defaultSolution();
        }

        return $this->normalizeContent($solution, $config);
    }

    public function normalizeContent(array $content, array $config): array
    {
        $budget = self::MAX_NODES;
        $ids = [];
        $root = self::sequence($content['root'] ?? null, 0, $budget, $ids);
        $result = ['root' => $root];
        $this->assertSize($result);

        return $result;
    }

    /**
     * @param array<string, true> $ids
     * @return array{type: string, children: list<array<string, mixed>>}
     */
    private static function sequence(mixed $seq, int $depth, int &$budget, array &$ids): array
    {
        $children = [];
        $list = is_array($seq) && is_array($seq['children'] ?? null) ? $seq['children'] : [];
        if ($depth <= self::MAX_DEPTH) {
            foreach ($list as $block) {
                if ($budget <= 0) {
                    break;
                }
                $node = is_array($block) ? self::block($block, $depth, $budget, $ids) : null;
                if ($node !== null) {
                    $children[] = $node;
                }
            }
        }

        return ['type' => 'sequence', 'children' => $children];
    }

    /**
     * @param array<string, mixed> $b
     * @param array<string, true> $ids
     * @return array<string, mixed>|null
     */
    private static function block(array $b, int $depth, int &$budget, array &$ids): ?array
    {
        $id = self::safeId($b['id'] ?? null);
        $type = is_string($b['type'] ?? null) ? $b['type'] : '';
        if ($id === null || isset($ids[$id])) {
            return null;
        }
        if (!in_array($type, ['statement', 'call', 'if', 'switch', 'while', 'do_while', 'for', 'break'], true)) {
            return null;
        }
        $ids[$id] = true;
        $budget--;

        switch ($type) {
            case 'statement':
            case 'call':
            case 'break':
                return ['id' => $id, 'type' => $type, 'text' => self::clean($b['text'] ?? '', 300)];
            case 'if':
                return [
                    'id' => $id,
                    'type' => 'if',
                    'cond' => self::clean($b['cond'] ?? '', 300),
                    'then' => self::sequence($b['then'] ?? null, $depth + 1, $budget, $ids),
                    'else' => self::sequence($b['else'] ?? null, $depth + 1, $budget, $ids),
                ];
            case 'switch':
                $cases = [];
                foreach (self::rows($b['cases'] ?? [], self::MAX_CASES) as $case) {
                    $cases[] = [
                        'label' => self::line($case['label'] ?? '', 80),
                        'body' => self::sequence($case['body'] ?? null, $depth + 1, $budget, $ids),
                    ];
                }

                return ['id' => $id, 'type' => 'switch', 'expr' => self::clean($b['expr'] ?? '', 300), 'cases' => $cases];
            case 'for':
                return ['id' => $id, 'type' => 'for', 'head' => self::clean($b['head'] ?? '', 300), 'body' => self::sequence($b['body'] ?? null, $depth + 1, $budget, $ids)];
            default: // while, do_while
                return ['id' => $id, 'type' => $type, 'cond' => self::clean($b['cond'] ?? '', 300), 'body' => self::sequence($b['body'] ?? null, $depth + 1, $budget, $ids)];
        }
    }

    public function isAnswered(array $content): bool
    {
        return ($content['root']['children'] ?? []) !== [];
    }

    public function toText(array $content, array $config): string
    {
        $lines = [];
        self::emit($content['root']['children'] ?? [], 0, $lines);

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param list<string> $lines
     */
    private static function emit(array $blocks, int $depth, array &$lines): void
    {
        $pad = str_repeat('    ', $depth);
        $one = static fn (string $t): string => str_replace("\n", ' ', $t);
        foreach ($blocks as $b) {
            switch ($b['type']) {
                case 'statement':
                    $lines[] = $pad . $one((string) $b['text']);
                    break;
                case 'call':
                    $lines[] = $pad . 'CALL ' . $one((string) $b['text']);
                    break;
                case 'break':
                    $lines[] = $pad . 'ABBRUCH' . ($b['text'] !== '' ? ' ' . $one((string) $b['text']) : '');
                    break;
                case 'if':
                    $lines[] = $pad . 'WENN ' . $one((string) $b['cond']) . ' DANN';
                    self::emit($b['then']['children'], $depth + 1, $lines);
                    if ($b['else']['children'] !== []) {
                        $lines[] = $pad . 'SONST';
                        self::emit($b['else']['children'], $depth + 1, $lines);
                    }
                    $lines[] = $pad . 'ENDE WENN';
                    break;
                case 'switch':
                    $lines[] = $pad . 'FALLUNTERSCHEIDUNG ' . $one((string) $b['expr']);
                    foreach ($b['cases'] as $case) {
                        $lines[] = $pad . '  FALL ' . $case['label'] . ':';
                        self::emit($case['body']['children'], $depth + 2, $lines);
                    }
                    $lines[] = $pad . 'ENDE FALLUNTERSCHEIDUNG';
                    break;
                case 'while':
                    $lines[] = $pad . 'SOLANGE ' . $one((string) $b['cond']);
                    self::emit($b['body']['children'], $depth + 1, $lines);
                    $lines[] = $pad . 'ENDE SOLANGE';
                    break;
                case 'do_while':
                    $lines[] = $pad . 'WIEDERHOLE';
                    self::emit($b['body']['children'], $depth + 1, $lines);
                    $lines[] = $pad . 'BIS ' . $one((string) $b['cond']);
                    break;
                case 'for':
                    $lines[] = $pad . 'FÜR ' . $one((string) $b['head']);
                    self::emit($b['body']['children'], $depth + 1, $lines);
                    $lines[] = $pad . 'ENDE FÜR';
                    break;
            }
        }
    }
}
