<?php

declare(strict_types=1);

/**
 * Globale Template-Helfer.
 */

/** HTML-Escaping für Ausgaben in Templates. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * URL zu einer Datei unter public/assets/ inklusive Cache-Buster (?v=mtime),
 * damit Browser nach einem Deploy nicht auf einer alten CSS-/JS-Fassung
 * hängen bleiben.
 */
function asset(string $relativePath, string $baseUrl = ''): string
{
    $relativePath = ltrim($relativePath, '/');
    $file = dirname(__DIR__) . '/public/assets/' . $relativePath;
    $version = is_file($file) ? '?v=' . filemtime($file) : '';

    return rtrim($baseUrl, '/') . '/assets/' . $relativePath . $version;
}

/** Formatiert ein Datum (Y-m-d oder DateTime) als deutsches Datum. */
function format_date(mixed $date, string $format = 'd.m.Y'): string
{
    if ($date === null || $date === '') {
        return '';
    }
    if (!$date instanceof \DateTimeInterface) {
        try {
            $date = new \DateTimeImmutable((string) $date);
        } catch (\Exception) {
            return (string) $date;
        }
    }

    return $date->format($format);
}

/** Formatiert Datum + Uhrzeit als deutsches Datum mit Uhrzeit. */
function format_datetime(mixed $date): string
{
    return format_date($date, 'd.m.Y H:i');
}

/** Anzeigename einer Person (Vorname Nachname, sonst Benutzername). */
function display_name(array $user): string
{
    $name = trim(((string) ($user['firstname'] ?? '')) . ' ' . ((string) ($user['lastname'] ?? '')));

    return $name !== '' ? $name : (string) ($user['username'] ?? '');
}

/** Punktzahl ohne überflüssige Nachkommastellen (3, 2,5). */
function format_points(mixed $points): string
{
    if ($points === null || $points === '') {
        return '';
    }
    $value = round((float) $points, 2);
    $text = number_format($value, 2, ',', '');

    return rtrim(rtrim($text, '0'), ',');
}

/** JSON für data-Attribute oder <script type="application/json">-Blöcke. */
function json_attr(mixed $value): string
{
    return e(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP));
}

/** URL zu einem gebauten Frontend-Bundle (public/assets/build/). */
function bundle(string $name, string $baseUrl = ''): string
{
    return asset('build/' . $name, $baseUrl);
}

/**
 * Inline-Icon aus dem Sprite /assets/img/icons.svg (Lucide, ISC-Lizenz).
 * Statt Emoji verwenden. $name ohne Präfix, z. B. icon('lock'), icon('trash-2').
 * Dekorativ (aria-hidden); bei reinen Icon-Knöpfen braucht der Knopf ein aria-label.
 */
function icon(string $name, string $class = ''): string
{
    static $base = null;
    if ($base === null) {
        $base = asset('img/icons.svg', rtrim((string) (getenv('BASE_URL') ?: ''), '/'));
    }
    $name = preg_replace('/[^a-z0-9-]/', '', $name) ?? '';

    return '<svg class="icon' . ($class !== '' ? ' ' . e($class) : '') . '" aria-hidden="true" focusable="false">'
        . '<use href="' . e($base) . '#i-' . $name . '"></use></svg>';
}
