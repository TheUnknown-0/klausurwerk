<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Versionsnummer und Änderungsverlauf aus CHANGELOG.md.
 *
 * Format (eine Version je Abschnitt, die oberste ist die aktuelle):
 *
 *   ## 1.3.0 (2026-11-06)
 *
 *   - Ein Punkt, darf über mehrere eingerückte Zeilen gehen.
 *
 * Dieselbe Datei liest die Update-Prüfung (Services\UpdateCheck) aus dem Repo; der Parser
 * ist deshalb auch für fremden Text gedacht und begrenzt Anzahl und Länge.
 */
final class Changelog
{
    public const FILE = __DIR__ . '/../../CHANGELOG.md';

    /** Obergrenzen für fremden Text. */
    private const MAX_ENTRIES = 100;
    private const MAX_ITEMS = 40;
    private const MAX_ITEM_LENGTH = 600;

    /** @var list<array{version: string, date: ?string, items: list<string>}>|null */
    private static ?array $local = null;

    /**
     * @return list<array{version: string, date: ?string, items: list<string>}> neueste zuerst
     */
    public static function parse(string $markdown): array
    {
        $entries = [];
        $current = null;
        $item = null;

        $flushItem = static function () use (&$current, &$item): void {
            if ($current !== null && $item !== null && $item !== '' && count($current['items']) < self::MAX_ITEMS) {
                $current['items'][] = mb_substr($item, 0, self::MAX_ITEM_LENGTH);
            }
            $item = null;
        };

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^##\s+v?(\d+\.\d+\.\d+)\s*(?:\((\d{4}-\d{2}-\d{2})\))?\s*$/', $line, $m)) {
                $flushItem();
                if ($current !== null) {
                    $entries[] = $current;
                }
                if (count($entries) >= self::MAX_ENTRIES) {
                    $current = null;
                    break;
                }
                $current = ['version' => $m[1], 'date' => ($m[2] ?? '') !== '' ? $m[2] : null, 'items' => []];
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (preg_match('/^#{1,2}\s/', $line)) {
                // Andere Überschrift: Abschnitt endet.
                $flushItem();
                $entries[] = $current;
                $current = null;
                continue;
            }
            if (preg_match('/^\s{0,3}[-*]\s+(.*)$/', $line, $m)) {
                $flushItem();
                $item = trim($m[1]);
            } elseif ($item !== null && preg_match('/^\s+(\S.*)$/', $line, $m)) {
                $item .= ' ' . trim($m[1]);
            } elseif (trim($line) === '') {
                $flushItem();
            }
        }
        $flushItem();
        if ($current !== null) {
            $entries[] = $current;
        }

        return $entries;
    }

    /** @return list<array{version: string, date: ?string, items: list<string>}> */
    public static function local(): array
    {
        if (self::$local === null) {
            $text = is_file(self::FILE) ? (string) file_get_contents(self::FILE) : '';
            self::$local = self::parse($text);
        }

        return self::$local;
    }

    /** Installierte Version, z. B. "1.3.0"; "0.0.0", wenn CHANGELOG.md fehlt. */
    public static function currentVersion(): string
    {
        return self::local()[0]['version'] ?? '0.0.0';
    }

    /**
     * Einträge, die neuer sind als $version.
     *
     * @param list<array{version: string, date: ?string, items: list<string>}> $entries
     * @return list<array{version: string, date: ?string, items: list<string>}>
     */
    public static function newerThan(array $entries, string $version): array
    {
        return array_values(array_filter(
            $entries,
            static fn (array $entry): bool => version_compare($entry['version'], $version, '>'),
        ));
    }

    /** Ein Punkt als HTML: alles escaped, nur `Code` und **fett** werden ausgezeichnet. */
    public static function itemHtml(string $item): string
    {
        $html = htmlspecialchars($item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html) ?? $html;

        return preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html) ?? $html;
    }

    /** "2026-11-06" → "6. November 2026"; null bei fehlendem oder ungültigem Datum. */
    public static function formatDate(?string $date): ?string
    {
        if ($date === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return null;
        }
        $months = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August',
            'September', 'Oktober', 'November', 'Dezember'];
        $month = (int) $m[2];
        if ($month < 1 || $month > 12) {
            return null;
        }

        return (int) $m[3] . '. ' . $months[$month - 1] . ' ' . $m[1];
    }
}
