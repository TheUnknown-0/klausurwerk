<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

use App\Services\Markdown;

/** Antworttyp "ordering" (Reihenfolge). Format: docs/DATENFORMATE.md, 2.5. */
final class OrderingType extends BaseTaskType
{
    public const MAX_ITEMS = 20;

    public function name(): string
    {
        return 'ordering';
    }

    public function label(): string
    {
        return 'Reihenfolge';
    }

    public function group(): string
    {
        return 'Text & Auswahl';
    }

    public function defaultConfig(): array
    {
        return ['items' => [['id' => 's1', 'text_md' => ''], ['id' => 's2', 'text_md' => '']]];
    }

    public function defaultSolution(): array
    {
        return ['order' => [], 'scoring' => 'all_or_nothing'];
    }

    public function normalizeConfig(array $config): array
    {
        return ['items' => ChoiceType::textItems($config['items'] ?? [], 2, self::MAX_ITEMS, 'Positionen')];
    }

    public function normalizeSolution(array $solution, array $config): array
    {
        $ids = array_column($config['items'] ?? [], 'id');
        $order = [];
        foreach (is_array($solution['order'] ?? null) ? $solution['order'] : [] as $id) {
            if (is_string($id) && in_array($id, $ids, true) && !in_array($id, $order, true)) {
                $order[] = $id;
            }
        }
        if (count($order) !== count($ids)) {
            throw new \InvalidArgumentException('Die richtige Reihenfolge muss alle Positionen genau einmal enthalten.');
        }
        $scoring = (string) ($solution['scoring'] ?? 'all_or_nothing');

        return [
            'order' => $order,
            'scoring' => in_array($scoring, ['all_or_nothing', 'partial'], true) ? $scoring : 'all_or_nothing',
        ];
    }

    public function studentConfig(array $config, int $seed): array
    {
        $md = new Markdown();
        $items = array_map(
            static fn (array $i): array => ['id' => $i['id'], 'text_html' => $md->inline((string) $i['text_md'])],
            $config['items'] ?? [],
        );

        return ['items' => self::seededShuffle($items, $seed)];
    }

    public function normalizeContent(array $content, array $config): array
    {
        $ids = array_column($config['items'] ?? [], 'id');
        $order = [];
        foreach (is_array($content['order'] ?? null) ? $content['order'] : [] as $id) {
            if (is_string($id) && in_array($id, $ids, true) && !in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        return ['order' => $order];
    }

    public function isAnswered(array $content): bool
    {
        return ($content['order'] ?? []) !== [];
    }

    public function autoCheck(array $config, array $solution, array $content): ?array
    {
        $expected = is_array($solution['order'] ?? null) ? array_values($solution['order']) : [];
        if ($expected === []) {
            return null;
        }
        $given = is_array($content['order'] ?? null) ? array_values($content['order']) : [];
        $items = [];
        $ok = 0;
        foreach ($expected as $pos => $id) {
            $good = ($given[$pos] ?? null) === $id;
            $ok += $good ? 1 : 0;
            $items[] = ['id' => $id, 'ok' => $good];
        }
        $ratio = ($solution['scoring'] ?? 'all_or_nothing') === 'partial'
            ? (float) ($ok / count($expected))
            : ($ok === count($expected) ? 1.0 : 0.0);

        return ['ratio' => $ratio, 'details' => ['source' => 'ordering', 'ratio' => $ratio, 'items' => $items]];
    }

    public function toText(array $content, array $config): string
    {
        $map = [];
        foreach ($config['items'] ?? [] as $i) {
            $map[$i['id']] = $i['text_md'];
        }
        $lines = [];
        foreach ($content['order'] ?? [] as $n => $id) {
            $lines[] = ($n + 1) . '. ' . ($map[$id] ?? $id);
        }

        return implode("\n", $lines);
    }
}
