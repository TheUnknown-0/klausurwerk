<?php

declare(strict_types=1);

namespace App\Services\Diagram;

use App\Services\TaskTypes\BaseTaskType;

/**
 * Gemeinsame Grundlage der Diagramm-Antworttypen (Stufe 3).
 *
 * Inhalte von Schüler:innen werden großzügig bereinigt: ungültige Elemente werden
 * verworfen statt abzulehnen, damit ein halbfertiges Diagramm nie das Speichern
 * blockiert. Konfiguration und Lösung der Lehrkraft werden streng geprüft.
 */
abstract class DiagramTaskType extends BaseTaskType
{
    public function group(): string
    {
        return 'Diagramme';
    }

    /** Begrenzte Koordinate (ganze Pixel). */
    protected static function coord(mixed $value, int $min = -3000, int $max = 8000): int
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return 0;
        }

        return (int) max($min, min($max, round((float) $value)));
    }

    /** Text ohne Steuerzeichen (Zeilenumbruch und Tab bleiben), getrimmt, gekürzt. */
    protected static function clean(mixed $value, int $max = 200): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        return mb_substr(trim($text), 0, $max);
    }

    /** Einzeiliger Text. */
    protected static function line(mixed $value, int $max = 100): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', self::clean($value, $max * 2)) ?? ''), 0, $max);
    }

    /** Gültige Kennung oder null (für tolerante Normalisierung). */
    protected static function safeId(mixed $value): ?string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return preg_match('/^[A-Za-z0-9_-]{1,40}$/', $value) === 1 ? $value : null;
    }

    /**
     * Nur die Einträge, die selbst Arrays sind, höchstens $max.
     *
     * @return list<array<string, mixed>>
     */
    protected static function rows(mixed $value, int $max): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $out[] = $row;
                if (count($out) >= $max) {
                    break;
                }
            }
        }

        return $out;
    }

    /** @param list<string> $allowed */
    protected static function oneOf(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    /** Wertebereich einer Zahl (ganzzahlig). */
    protected static function intRange(mixed $value, int $min, int $max, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return (int) max($min, min($max, round((float) $value)));
    }

    /** Vergleichsform eines Namens: klein, nur Buchstaben und Ziffern. */
    public static function norm(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value)) ?? '';
    }

    /** Vergleichsform eines Typs: klein, ohne Leerraum. */
    public static function normType(string $value): string
    {
        return preg_replace('/\s+/u', '', mb_strtolower($value)) ?? '';
    }

    /**
     * Ergebnis eines Elementvergleichs (Vorschlag für die Bewertung).
     *
     * @param list<array{id: string, label: string, ok: bool}> $items
     * @param list<string> $extra
     * @return array{ratio: float, details: array<string, mixed>}
     */
    public static function comparisonResult(string $source, array $items, array $extra): array
    {
        $total = count($items);
        $found = 0;
        $foundLabels = [];
        $missing = [];
        foreach ($items as $item) {
            if ($item['ok']) {
                $found++;
                $foundLabels[] = $item['label'];
            } else {
                $missing[] = $item['label'];
            }
        }
        $ratio = $total > 0 ? $found / ($total + count($extra)) : 0.0;
        $ratio = round(max(0.0, min(1.0, $ratio)), 4);

        return [
            'ratio' => $ratio,
            'details' => [
                'source' => $source,
                'ratio' => $ratio,
                'items' => array_map(static fn (array $i): array => ['id' => $i['id'], 'ok' => $i['ok']], $items),
                'found' => $foundLabels,
                'missing' => $missing,
                'extra' => $extra,
            ],
        ];
    }
}
