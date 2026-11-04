<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Zentraler Datenbankzugriff über PDO.
 *
 * Die Verbindung wird lazy beim ersten Zugriff aufgebaut, damit Seiten ohne
 * Datenbankbedarf (z. B. statische Fehlerseiten) keine Verbindung öffnen.
 */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $host,
        private readonly string $name,
        private readonly string $user,
        private readonly string $password,
    ) {
    }

    /**
     * Instanz um eine bereits geöffnete Verbindung — für Tests, die ihre
     * eigene Transaktion auf derselben Verbindung führen müssen.
     */
    public static function fromPdo(PDO $pdo): self
    {
        $db = new self('', '', '', '');
        $db->pdo = $pdo;

        return $db;
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $this->host, $this->name);
            $this->pdo = new PDO($dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return $this->pdo;
    }

    /** Führt ein Statement mit Parametern aus und gibt das Statement zurück. */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /** @return array<string, mixed>|null */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Führt $fn innerhalb einer Transaktion aus. Verschachtelte Aufrufe
     * laufen in der äußeren Transaktion mit.
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();

        if ($pdo->inTransaction()) {
            return $fn($this);
        }

        $pdo->beginTransaction();
        try {
            $result = $fn($this);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
