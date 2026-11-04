<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Laufzeit-Einstellungen aus der settings-Tabelle (Key/Value).
 *
 * Zeiträume und Limits eines Aktionstags liegen bewusst NICHT hier,
 * sondern in garden_days.
 */
final class Settings
{
    /** @var array<string, string|null>|null Cache: key => value */
    private ?array $cache = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return $value === null ? $default : $value === '1';
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value === null || !is_numeric($value) ? $default : (int) $value;
    }

    public function set(string $key, ?string $value): void
    {
        $this->db->run(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $value],
        );
        $this->cache = null;
    }

    public function delete(string $key): void
    {
        $this->db->run('DELETE FROM settings WHERE setting_key = ?', [$key]);
        $this->cache = null;
    }

    /** @return array<string, string|null> */
    public function all(): array
    {
        if ($this->cache === null) {
            $rows = $this->db->fetchAll('SELECT setting_key, setting_value FROM settings');
            $this->cache = array_column($rows, 'setting_value', 'setting_key');
        }

        return $this->cache;
    }
}
