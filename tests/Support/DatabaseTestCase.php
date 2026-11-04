<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Basis für Tests, die echtes SQL brauchen.
 *
 * Läuft gegen eine EIGENE Datenbank (Standard: klausurwerk_test), niemals
 * gegen die Entwicklungsdaten. Das Schema wird einmal pro Testlauf aus
 * migrations/ aufgebaut; jeder einzelne Test läuft in einer Transaktion, die
 * am Ende zurückgerollt wird — Tests sehen sich also gegenseitig nicht.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected static ?PDO $pdo = null;
    protected Database $db;

    public static function setUpBeforeClass(): void
    {
        if (self::$pdo !== null) {
            return;
        }

        $host = getenv('TEST_DB_HOST') ?: (getenv('DB_HOST') ?: '127.0.0.1');
        $name = getenv('TEST_DB_NAME') ?: 'klausurwerk_test';
        $user = getenv('TEST_DB_USER') ?: (getenv('DB_USER') ?: 'gartenarbeitstag');
        $pass = getenv('TEST_DB_PASS') ?: (getenv('DB_PASS') ?: '');

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name),
                $user,
                $pass,
                $options,
            );
        } catch (\PDOException $e) {
            // Datenbank fehlt noch: anlegen, falls das Konto es darf.
            try {
                $server = new PDO(sprintf('mysql:host=%s;charset=utf8mb4', $host), $user, $pass, $options);
                $server->exec(sprintf(
                    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    $name,
                ));
                $pdo = new PDO(
                    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name),
                    $user,
                    $pass,
                    $options,
                );
            } catch (\PDOException) {
                self::markTestSkipped(sprintf(
                    "Testdatenbank '%s' ist nicht erreichbar und ließ sich nicht anlegen (%s).\n"
                    . "Einmalig als Administrator anlegen:\n"
                    . "  CREATE DATABASE %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
                    . "  GRANT ALL PRIVILEGES ON %s.* TO '%s'@'%%';",
                    $name,
                    $e->getMessage(),
                    $name,
                    $name,
                    $user,
                ));
            }
        }

        self::loadSchema($pdo);
        self::$pdo = $pdo;
    }

    /** Spielt alle Migrationen in eine leere Testdatenbank ein. */
    private static function loadSchema(PDO $pdo): void
    {
        $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if ($existing !== []) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($existing as $table) {
                $pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', $table));
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $files = glob(dirname(__DIR__, 2) . '/migrations/*.sql');
        sort($files);
        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if ($sql !== false && trim($sql) !== '') {
                $pdo->exec($sql);
            }
        }
    }

    protected function setUp(): void
    {
        $this->db = Database::fromPdo(self::$pdo);
        self::$pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
    }
}
