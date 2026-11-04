<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

use App\Services\Markdown;

/** Antworttyp "choice" (Single/Multiple Choice). Format: docs/DATENFORMATE.md, 2.3. */
final class ChoiceType extends BaseTaskType
{
    public const MAX_OPTIONS = 20;

    public function name(): string
    {
        return 'choice';
    }

    public function label(): string
    {
        return 'Multiple Choice';
    }

    public function group(): string
    {
        return 'Text & Auswahl';
    }

    public function defaultConfig(): array
    {
        return [
            'multiple' => false,
            'options' => [['id' => 'a', 'text_md' => ''], ['id' => 'b', 'text_md' => '']],
            'shuffle' => true,
        ];
    }

    public function defaultSolution(): array
    {
        return ['correct' => [], 'scoring' => 'all_or_nothing'];
    }

    public function normalizeConfig(array $config): array
    {
        return [
            'multiple' => self::bool($config['multiple'] ?? false),
            'options' => self::textItems($config['options'] ?? [], 2, self::MAX_OPTIONS, 'Antwortoption'),
            'shuffle' => self::bool($config['shuffle'] ?? true),
        ];
    }

    /**
     * Gemeinsamer Helfer für Listen aus {id, text_md} (auch Zuordnung, Reihenfolge).
     *
     * @return list<array{id: string, text_md: string}>
     */
    public static function textItems(mixed $items, int $min, int $max, string $what): array
    {
        if (!is_array($items)) {
            throw new \InvalidArgumentException("Es fehlen Einträge ({$what}).");
        }
        $out = [];
        $ids = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = self::id($item['id'] ?? '');
            if (isset($ids[$id])) {
                throw new \InvalidArgumentException("Die Kennung \"{$id}\" kommt doppelt vor.");
            }
            $text = trim(self::str($item['text_md'] ?? '', 2000));
            if ($text === '') {
                throw new \InvalidArgumentException("Ein Eintrag ({$what}) hat keinen Text.");
            }
            $ids[$id] = true;
            $out[] = ['id' => $id, 'text_md' => $text];
        }
        if (count($out) < $min) {
            throw new \InvalidArgumentException("Mindestens {$min} Einträge nötig ({$what}).");
        }
        if (count($out) > $max) {
            throw new \InvalidArgumentException("Höchstens {$max} Einträge erlaubt ({$what}).");
        }

        return $out;
    }

    public function normalizeSolution(array $solution, array $config): array
    {
        $valid = array_column($config['options'] ?? [], 'id');
        $correct = [];
        foreach (is_array($solution['correct'] ?? null) ? $solution['correct'] : [] as $id) {
            if (is_string($id) && in_array($id, $valid, true) && !in_array($id, $correct, true)) {
                $correct[] = $id;
            }
        }
        if ($correct === []) {
            throw new \InvalidArgumentException('Markiere mindestens eine richtige Antwort.');
        }
        if (empty($config['multiple']) && count($correct) > 1) {
            throw new \InvalidArgumentException('Bei Einfachauswahl ist genau eine richtige Antwort erlaubt.');
        }
        $scoring = (string) ($solution['scoring'] ?? 'all_or_nothing');

        return [
            'correct' => $correct,
            'scoring' => in_array($scoring, ['all_or_nothing', 'partial'], true) ? $scoring : 'all_or_nothing',
        ];
    }

    public function studentConfig(array $config, int $seed): array
    {
        $md = new Markdown();
        $options = [];
        foreach ($config['options'] ?? [] as $option) {
            $options[] = ['id' => $option['id'], 'text_html' => $md->inline((string) $option['text_md'])];
        }
        if (!empty($config['shuffle'])) {
            $options = self::seededShuffle($options, $seed);
        }

        return ['multiple' => (bool) ($config['multiple'] ?? false), 'options' => $options];
    }

    public function normalizeContent(array $content, array $config): array
    {
        $valid = array_column($config['options'] ?? [], 'id');
        $selected = [];
        foreach (is_array($content['selected'] ?? null) ? $content['selected'] : [] as $id) {
            if (is_string($id) && in_array($id, $valid, true) && !in_array($id, $selected, true)) {
                $selected[] = $id;
            }
        }
        if (empty($config['multiple'])) {
            $selected = array_slice($selected, 0, 1);
        }

        return ['selected' => $selected];
    }

    public function isAnswered(array $content): bool
    {
        return ($content['selected'] ?? []) !== [];
    }

    public function autoCheck(array $config, array $solution, array $content): ?array
    {
        $correct = is_array($solution['correct'] ?? null) ? $solution['correct'] : [];
        if ($correct === []) {
            return null;
        }
        $selected = is_array($content['selected'] ?? null) ? $content['selected'] : [];
        $right = count(array_intersect($selected, $correct));
        $wrong = count(array_diff($selected, $correct));

        if (($solution['scoring'] ?? 'all_or_nothing') === 'partial') {
            $ratio = max(0.0, ($right - $wrong) / count($correct));
        } else {
            $ratio = ($right === count($correct) && $wrong === 0) ? 1.0 : 0.0;
        }
        $ratio = min(1.0, $ratio);

        $items = [];
        foreach ($config['options'] ?? [] as $option) {
            $id = $option['id'];
            $items[] = ['id' => $id, 'ok' => in_array($id, $selected, true) === in_array($id, $correct, true)];
        }

        return ['ratio' => $ratio, 'details' => ['source' => 'choice', 'ratio' => $ratio, 'items' => $items]];
    }

    public function toText(array $content, array $config): string
    {
        $texts = [];
        foreach ($config['options'] ?? [] as $option) {
            if (in_array($option['id'], $content['selected'] ?? [], true)) {
                $texts[] = $option['text_md'];
            }
        }

        return implode("\n", $texts);
    }
}
