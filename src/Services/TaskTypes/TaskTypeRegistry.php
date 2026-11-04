<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

/**
 * Findet alle Antworttypen automatisch: jede Datei *Type.php in diesem
 * Verzeichnis, deren Klasse TaskType implementiert. Neue Typen brauchen
 * keinen Eintrag an zentraler Stelle.
 */
final class TaskTypeRegistry
{
    /** @var array<string, TaskType>|null */
    private static ?array $types = null;

    /** @return array<string, TaskType> name => Typ, sortiert nach Gruppe und Label */
    public static function all(): array
    {
        if (self::$types !== null) {
            return self::$types;
        }

        $types = [];
        foreach (glob(__DIR__ . '/*Type.php') ?: [] as $file) {
            $class = __NAMESPACE__ . '\\' . basename($file, '.php');
            if ($class === __NAMESPACE__ . '\\TaskType' || $class === __NAMESPACE__ . '\\BaseTaskType') {
                continue;
            }
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->implementsInterface(TaskType::class)) {
                continue;
            }
            /** @var TaskType $type */
            $type = new $class();
            $types[$type->name()] = $type;
        }

        uasort($types, static fn (TaskType $a, TaskType $b): int => [$a->group(), $a->label()] <=> [$b->group(), $b->label()]);

        return self::$types = $types;
    }

    public static function get(string $name): ?TaskType
    {
        return self::all()[$name] ?? null;
    }

    public static function require(string $name): TaskType
    {
        $type = self::get($name);
        if ($type === null) {
            throw new \InvalidArgumentException('Unbekannter Antworttyp: ' . $name);
        }

        return $type;
    }

    /** @return array<string, array<string, string>> Gruppe => (name => label) */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::all() as $name => $type) {
            $groups[$type->group()][$name] = $type->label();
        }

        return $groups;
    }

    /** Nur für Tests: Cache leeren. */
    public static function reset(): void
    {
        self::$types = null;
    }
}
