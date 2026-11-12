<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

use App\Services\Subjects;

/** Antworttyp "text" (Freitext). Format: docs/DATENFORMATE.md, 2.2. */
final class TextType extends BaseTaskType
{
    public function name(): string
    {
        return 'text';
    }

    public function label(): string
    {
        return 'Freitext';
    }

    public function group(): string
    {
        return 'Text & Auswahl';
    }

    public function defaultConfig(): array
    {
        // lang und chars: '' = wie das Fach der Klausur (Services\Subjects).
        return ['min_words' => null, 'max_chars' => 20000, 'placeholder' => '', 'lang' => '', 'chars' => ''];
    }

    public function normalizeConfig(array $config): array
    {
        $minWords = $config['min_words'] ?? null;
        if ($minWords === '' || $minWords === null) {
            $minWords = null;
        } else {
            $minWords = (int) $minWords;
            if ($minWords < 0 || $minWords > 10000) {
                throw new \InvalidArgumentException('Die Mindestwortzahl muss zwischen 0 und 10000 liegen.');
            }
            $minWords = $minWords === 0 ? null : $minWords;
        }
        $maxChars = (int) ($config['max_chars'] ?? 20000);
        if ($maxChars < 100 || $maxChars > 100000) {
            throw new \InvalidArgumentException('Die Höchstlänge muss zwischen 100 und 100000 Zeichen liegen.');
        }

        return [
            'min_words' => $minWords,
            'max_chars' => $maxChars,
            'placeholder' => self::str($config['placeholder'] ?? '', 300),
            'lang' => Subjects::cleanLanguage($config['lang'] ?? ''),
            'chars' => Subjects::cleanCharset($config['chars'] ?? ''),
        ];
    }

    public function normalizeSolution(array $solution, array $config): array
    {
        return [];
    }

    public function normalizeContent(array $content, array $config): array
    {
        $max = max(100, min(100000, (int) ($config['max_chars'] ?? 20000)));
        $out = ['text' => self::str($content['text'] ?? '', $max)];
        $this->assertSize($out);

        return $out;
    }

    public function isAnswered(array $content): bool
    {
        return trim((string) ($content['text'] ?? '')) !== '';
    }

    public function toText(array $content, array $config): string
    {
        return (string) ($content['text'] ?? '');
    }
}
