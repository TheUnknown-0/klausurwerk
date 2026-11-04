<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

use App\Services\Diagram\DiagramTaskType;
use App\Services\Diagram\RelationalComparer;

/**
 * Antworttyp "relational" (Relationenschema). Format: docs/DATENFORMATE.md, 2.15.
 * Darstellung: Tabelle(_id_, name, ↑kurs_id), Primärschlüssel unterstrichen,
 * Fremdschlüssel mit Pfeil.
 */
final class RelationalType extends DiagramTaskType
{
    public const MAX_TABLES = 30;
    public const MAX_COLUMNS = 40;

    public function name(): string
    {
        return 'relational';
    }

    public function label(): string
    {
        return 'Relationenschema';
    }

    public function defaultConfig(): array
    {
        return ['notation' => 'schema'];
    }

    public function defaultSolution(): array
    {
        return ['tables' => []];
    }

    public function normalizeConfig(array $config): array
    {
        return ['notation' => 'schema'];
    }

    public function normalizeSolution(array $solution, array $config): array
    {
        $schema = self::normalizeSchema($solution);
        if ($schema['tables'] === []) {
            throw new \InvalidArgumentException('Das Musterschema braucht mindestens eine Tabelle.');
        }
        foreach ($schema['tables'] as $t) {
            if ($t['name'] === '') {
                throw new \InvalidArgumentException('Im Musterschema hat eine Tabelle keinen Namen.');
            }
            foreach ($t['columns'] as $c) {
                if ($c['name'] === '') {
                    throw new \InvalidArgumentException("Im Musterschema hat eine Spalte der Tabelle {$t['name']} keinen Namen.");
                }
            }
        }

        return $schema;
    }

    public function normalizeContent(array $content, array $config): array
    {
        $schema = self::normalizeSchema($content);
        $this->assertSize($schema);

        return $schema;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{tables: list<array<string, mixed>>}
     */
    public static function normalizeSchema(array $data): array
    {
        $tables = [];
        $ids = [];
        foreach (self::rows($data['tables'] ?? [], self::MAX_TABLES) as $t) {
            $id = self::safeId($t['id'] ?? null);
            if ($id === null || isset($ids[$id])) {
                continue;
            }
            $ids[$id] = true;
            $columns = [];
            foreach (self::rows($t['columns'] ?? [], self::MAX_COLUMNS) as $c) {
                $fk = null;
                if (is_string($c['fk'] ?? null)) {
                    $candidate = self::line($c['fk'], 120);
                    $parts = explode('.', $candidate, 2);
                    if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
                        $fk = trim($parts[0]) . '.' . trim($parts[1]);
                    }
                }
                $columns[] = ['name' => self::line($c['name'] ?? '', 60), 'pk' => self::bool($c['pk'] ?? false), 'fk' => $fk];
            }
            $tables[] = ['id' => $id, 'name' => self::line($t['name'] ?? '', 60), 'columns' => $columns];
        }

        return ['tables' => $tables];
    }

    public function isAnswered(array $content): bool
    {
        return ($content['tables'] ?? []) !== [];
    }

    public function autoCheck(array $config, array $solution, array $content): ?array
    {
        if (($solution['tables'] ?? []) === []) {
            return null;
        }

        return RelationalComparer::compare($solution, $content);
    }

    public function toText(array $content, array $config): string
    {
        $lines = [];
        foreach ($content['tables'] ?? [] as $t) {
            $cols = [];
            $refs = [];
            foreach ($t['columns'] as $c) {
                $label = $c['name'];
                if ($c['pk']) {
                    $label = '_' . $label . '_';
                }
                if ($c['fk'] !== null) {
                    $label = '↑' . $label;
                    $refs[] = '  ↑' . $c['name'] . ' verweist auf ' . $c['fk'];
                }
                $cols[] = $label;
            }
            $lines[] = ($t['name'] !== '' ? $t['name'] : '(ohne Namen)') . '(' . implode(', ', $cols) . ')';
            array_push($lines, ...$refs);
        }

        return implode("\n", $lines);
    }
}
