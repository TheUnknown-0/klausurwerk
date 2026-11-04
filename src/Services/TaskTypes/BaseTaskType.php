<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

/**
 * Gemeinsame Helfer und sinnvolle Standards für Antworttypen.
 * Unterklassen überschreiben nur, was sie brauchen.
 */
abstract class BaseTaskType implements TaskType
{
    /** Höchstgröße einer Antwort (JSON-kodiert), schützt Datenbank und Pult. */
    public const MAX_CONTENT_BYTES = 512 * 1024;

    public function defaultConfig(): array
    {
        return [];
    }

    public function defaultSolution(): array
    {
        return [];
    }

    public function normalizeConfig(array $config): array
    {
        return $config;
    }

    public function normalizeSolution(array $solution, array $config): array
    {
        return $solution;
    }

    public function studentConfig(array $config, int $seed): array
    {
        return $config;
    }

    public function isAnswered(array $content): bool
    {
        return $content !== [];
    }

    public function autoCheck(array $config, array $solution, array $content): ?array
    {
        return null;
    }

    public function toText(array $content, array $config): string
    {
        return json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
    }

    // ---------- Helfer ----------

    /** Wirft, wenn der Inhalt zu groß ist. */
    protected function assertSize(array $content): void
    {
        $json = json_encode($content, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > self::MAX_CONTENT_BYTES) {
            throw new \InvalidArgumentException('Die Antwort ist zu groß.');
        }
    }

    /** Kürzt einen String auf $max Zeichen (UTF-8). */
    protected static function str(mixed $value, int $max = 10000): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return mb_substr($value, 0, $max);
    }

    protected static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** Sichere ID (Buchstaben, Ziffern, -, _), max. 40 Zeichen. */
    protected static function id(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $value) !== 1) {
            throw new \InvalidArgumentException('Ungültige Kennung: ' . mb_substr($value, 0, 40));
        }

        return $value;
    }

    /**
     * Mischt eine Liste deterministisch (gleicher Seed = gleiche Reihenfolge),
     * ohne den globalen Zufallsgenerator zu verstellen.
     *
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    protected static function seededShuffle(array $items, int $seed): array
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));

        return array_values($rng->shuffleArray($items));
    }
}
