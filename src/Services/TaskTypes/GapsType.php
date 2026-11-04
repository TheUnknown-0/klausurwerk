<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

/** Antworttyp "gaps" (Lückentext/Lückencode). Format: docs/DATENFORMATE.md, 2.6. */
final class GapsType extends BaseTaskType
{
    public const MAX_GAPS = 40;

    public function name(): string
    {
        return 'gaps';
    }

    public function label(): string
    {
        return 'Lückentext';
    }

    public function group(): string
    {
        return 'Text & Auswahl';
    }

    public function defaultConfig(): array
    {
        return ['mode' => 'text', 'language' => null, 'template' => '', 'gaps' => []];
    }

    public function defaultSolution(): array
    {
        return ['gaps' => []];
    }

    /**
     * Kennungen der Lücken in Reihenfolge des Auftretens.
     *
     * @return list<string>
     */
    public static function gapIdsIn(string $template): array
    {
        preg_match_all('/\{\{([A-Za-z0-9_-]{1,40})\}\}/', $template, $m);

        return array_values(array_unique($m[1]));
    }

    public function normalizeConfig(array $config): array
    {
        $mode = (string) ($config['mode'] ?? 'text');
        if (!in_array($mode, ['text', 'code'], true)) {
            throw new \InvalidArgumentException('Der Modus muss "text" oder "code" sein.');
        }
        $language = $config['language'] ?? null;
        $language = ($mode === 'code' && is_string($language) && in_array($language, CodeType::LANGUAGES, true)) ? $language : null;

        $template = self::str($config['template'] ?? '', 20000);
        $inTemplate = self::gapIdsIn($template);
        if ($inTemplate === []) {
            throw new \InvalidArgumentException('Der Text braucht mindestens eine Lücke, markiert mit {{kennung}}.');
        }
        if (count($inTemplate) > self::MAX_GAPS) {
            throw new \InvalidArgumentException('Höchstens ' . self::MAX_GAPS . ' Lücken.');
        }

        $given = [];
        foreach (is_array($config['gaps'] ?? null) ? $config['gaps'] : [] as $gap) {
            if (is_array($gap)) {
                $given[self::id($gap['id'] ?? '')] = $gap;
            }
        }

        $gaps = [];
        foreach ($inTemplate as $id) {
            $gap = $given[$id] ?? [];
            $options = $gap['options'] ?? null;
            if (is_array($options)) {
                $clean = [];
                foreach ($options as $opt) {
                    $opt = self::str($opt, 60);
                    if ($opt !== '' && !in_array($opt, $clean, true)) {
                        $clean[] = $opt;
                    }
                }
                if (count($clean) > 12) {
                    throw new \InvalidArgumentException("Lücke {$id}: höchstens 12 Auswahlmöglichkeiten.");
                }
                $options = count($clean) >= 2 ? $clean : null;
            } else {
                $options = null;
            }
            $gaps[] = [
                'id' => $id,
                'width' => max(1, min(60, (int) ($gap['width'] ?? 6))),
                'options' => $options,
            ];
        }

        return ['mode' => $mode, 'language' => $language, 'template' => $template, 'gaps' => $gaps];
    }

    public function normalizeSolution(array $solution, array $config): array
    {
        $given = is_array($solution['gaps'] ?? null) ? $solution['gaps'] : [];
        $out = [];
        foreach ($config['gaps'] ?? [] as $gap) {
            $id = $gap['id'];
            $entry = is_array($given[$id] ?? null) ? $given[$id] : [];
            $accepted = [];
            foreach (is_array($entry['accepted'] ?? null) ? $entry['accepted'] : [] as $value) {
                $value = self::str($value, 500);
                if (trim($value) !== '' && !in_array($value, $accepted, true)) {
                    $accepted[] = $value;
                }
            }
            $regex = $entry['regex'] ?? null;
            $regex = (is_string($regex) && $regex !== '') ? mb_substr($regex, 0, 300) : null;
            if ($regex !== null && @preg_match(self::delimit($regex), '') === false) {
                throw new \InvalidArgumentException("Lücke {$id}: Der reguläre Ausdruck ist ungültig.");
            }
            if ($accepted === [] && $regex === null) {
                throw new \InvalidArgumentException("Lücke {$id}: Gib mindestens eine richtige Antwort an.");
            }
            $out[$id] = [
                'accepted' => array_slice($accepted, 0, 20),
                'case_sensitive' => self::bool($entry['case_sensitive'] ?? true),
                'trim' => self::bool($entry['trim'] ?? true),
                'regex' => $regex,
            ];
        }

        return ['gaps' => $out];
    }

    private static function delimit(string $regex): string
    {
        return '~' . str_replace('~', '\~', $regex) . '~u';
    }

    public function normalizeContent(array $content, array $config): array
    {
        $values = [];
        $given = is_array($content['values'] ?? null) ? $content['values'] : [];
        foreach ($config['gaps'] ?? [] as $gap) {
            $id = $gap['id'];
            if (array_key_exists($id, $given)) {
                $values[$id] = self::str($given[$id], 500);
            }
        }

        return ['values' => $values];
    }

    public function isAnswered(array $content): bool
    {
        foreach ($content['values'] ?? [] as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    public function autoCheck(array $config, array $solution, array $content): ?array
    {
        $expected = is_array($solution['gaps'] ?? null) ? $solution['gaps'] : [];
        if ($expected === []) {
            return null;
        }
        $values = is_array($content['values'] ?? null) ? $content['values'] : [];
        $items = [];
        $ok = 0;
        foreach ($expected as $id => $rule) {
            $good = self::matches((string) ($values[$id] ?? ''), is_array($rule) ? $rule : []);
            $ok += $good ? 1 : 0;
            $items[] = ['id' => (string) $id, 'ok' => $good];
        }
        $ratio = (float) ($ok / count($expected));

        return ['ratio' => $ratio, 'details' => ['source' => 'gaps', 'ratio' => $ratio, 'items' => $items]];
    }

    /** @param array<string, mixed> $rule */
    private static function matches(string $value, array $rule): bool
    {
        $trim = ($rule['trim'] ?? true) !== false;
        $caseSensitive = ($rule['case_sensitive'] ?? true) !== false;
        if ($trim) {
            $value = trim($value);
        }
        if ($value === '') {
            return false;
        }
        foreach ($rule['accepted'] ?? [] as $accepted) {
            $accepted = (string) $accepted;
            if ($trim) {
                $accepted = trim($accepted);
            }
            if ($caseSensitive ? $value === $accepted : mb_strtolower($value) === mb_strtolower($accepted)) {
                return true;
            }
        }
        $regex = $rule['regex'] ?? null;
        if (is_string($regex) && $regex !== '') {
            return @preg_match(self::delimit($regex) . ($caseSensitive ? '' : 'i'), $value) === 1;
        }

        return false;
    }

    public function toText(array $content, array $config): string
    {
        $values = $content['values'] ?? [];

        return (string) preg_replace_callback(
            '/\{\{([A-Za-z0-9_-]{1,40})\}\}/',
            static fn (array $m): string => '[' . (string) ($values[$m[1]] ?? '') . ']',
            (string) ($config['template'] ?? ''),
        );
    }
}
