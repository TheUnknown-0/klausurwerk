<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Content-Security-Policy an einer Stelle.
 *
 * Grundsatz: Skripte nur von der eigenen Herkunft, kein Inline-JS.
 * 'wasm-unsafe-eval' erlaubt WebAssembly (Pyodide, sql.js), aber kein eval().
 * Seiten mit Sonderbedarf rufen Csp::send(['script-src' => ["'unsafe-eval'"]])
 * auf; das ersetzt den Header dieser Antwort.
 */
final class Csp
{
    /** @return array<string, list<string>> */
    public static function defaults(): array
    {
        return [
            'default-src' => ["'self'"],
            'img-src' => ["'self'", 'data:', 'blob:'],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'font-src' => ["'self'", 'data:'],
            'script-src' => ["'self'", "'wasm-unsafe-eval'"],
            'worker-src' => ["'self'", 'blob:'],
            'connect-src' => ["'self'"],
            'media-src' => ["'self'", 'data:', 'blob:'],
            'object-src' => ["'none'"],
            'frame-src' => ["'self'"],
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
        ];
    }

    /** @param array<string, list<string>> $extra zusätzliche Quellen je Direktive */
    public static function build(array $extra = []): string
    {
        $policy = self::defaults();
        foreach ($extra as $directive => $sources) {
            $policy[$directive] = array_values(array_unique(array_merge($policy[$directive] ?? [], $sources)));
        }
        $parts = [];
        foreach ($policy as $directive => $sources) {
            $parts[] = $directive . ' ' . implode(' ', $sources);
        }

        return implode('; ', $parts);
    }

    /** @param array<string, list<string>> $extra */
    public static function send(array $extra = []): void
    {
        if (!headers_sent()) {
            header('Content-Security-Policy: ' . self::build($extra), true);
        }
    }
}
