<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

use App\Services\Markdown;

/** Antworttyp "matching" (Zuordnung). Format: docs/DATENFORMATE.md, 2.4. */
final class MatchingType extends BaseTaskType
{
    public const MAX_ITEMS = 20;

    public function name(): string
    {
        return 'matching';
    }

    public function label(): string
    {
        return 'Zuordnung';
    }

    public function group(): string
    {
        return 'Text & Auswahl';
    }

    public function defaultConfig(): array
    {
        return [
            'left' => [['id' => 'l1', 'text_md' => ''], ['id' => 'l2', 'text_md' => '']],
            'right' => [['id' => 'r1', 'text_md' => ''], ['id' => 'r2', 'text_md' => '']],
            'shuffle' => true,
        ];
    }

    public function defaultSolution(): array
    {
        return ['pairs' => []];
    }

    public function normalizeConfig(array $config): array
    {
        $left = ChoiceType::textItems($config['left'] ?? [], 1, self::MAX_ITEMS, 'linke Seite');
        $right = ChoiceType::textItems($config['right'] ?? [], 1, self::MAX_ITEMS, 'rechte Seite');
        if (array_intersect(array_column($left, 'id'), array_column($right, 'id')) !== []) {
            throw new \InvalidArgumentException('Linke und rechte Seite dürfen keine gleichen Kennungen haben.');
        }

        return ['left' => $left, 'right' => $right, 'shuffle' => self::bool($config['shuffle'] ?? true)];
    }

    public function normalizeSolution(array $solution, array $config): array
    {
        $pairs = $this->cleanPairs($solution['pairs'] ?? [], $config);
        if ($pairs === []) {
            throw new \InvalidArgumentException('Lege mindestens eine richtige Zuordnung fest.');
        }

        return ['pairs' => $pairs];
    }

    /** @return array<string, string> */
    private function cleanPairs(mixed $pairs, array $config): array
    {
        $left = array_column($config['left'] ?? [], 'id');
        $right = array_column($config['right'] ?? [], 'id');
        $out = [];
        foreach (is_array($pairs) ? $pairs : [] as $l => $r) {
            $l = (string) $l;
            if (is_string($r) && in_array($l, $left, true) && in_array($r, $right, true)) {
                $out[$l] = $r;
            }
        }

        return $out;
    }

    public function studentConfig(array $config, int $seed): array
    {
        $md = new Markdown();
        $render = static fn (array $items): array => array_map(
            static fn (array $i): array => ['id' => $i['id'], 'text_html' => $md->inline((string) $i['text_md'])],
            $items,
        );
        $left = $render($config['left'] ?? []);
        $right = $render($config['right'] ?? []);
        if (!empty($config['shuffle'])) {
            $left = self::seededShuffle($left, $seed);
            $right = self::seededShuffle($right, $seed + 1);
        }

        return ['left' => $left, 'right' => $right];
    }

    public function normalizeContent(array $content, array $config): array
    {
        return ['pairs' => $this->cleanPairs($content['pairs'] ?? [], $config)];
    }

    public function isAnswered(array $content): bool
    {
        return ($content['pairs'] ?? []) !== [];
    }

    public function autoCheck(array $config, array $solution, array $content): ?array
    {
        $expected = is_array($solution['pairs'] ?? null) ? $solution['pairs'] : [];
        if ($expected === []) {
            return null;
        }
        $given = is_array($content['pairs'] ?? null) ? $content['pairs'] : [];
        $items = [];
        $ok = 0;
        foreach ($expected as $l => $r) {
            $good = ($given[$l] ?? null) === $r;
            $ok += $good ? 1 : 0;
            $items[] = ['id' => (string) $l, 'ok' => $good];
        }
        $ratio = (float) ($ok / count($expected));

        return ['ratio' => $ratio, 'details' => ['source' => 'matching', 'ratio' => $ratio, 'items' => $items]];
    }

    public function toText(array $content, array $config): string
    {
        $map = static function (array $items): array {
            $m = [];
            foreach ($items as $i) {
                $m[$i['id']] = $i['text_md'];
            }

            return $m;
        };
        $left = $map($config['left'] ?? []);
        $right = $map($config['right'] ?? []);
        $lines = [];
        foreach ($content['pairs'] ?? [] as $l => $r) {
            $lines[] = ($left[$l] ?? $l) . ' -> ' . ($right[$r] ?? $r);
        }

        return implode("\n", $lines);
    }
}
