<?php

declare(strict_types=1);

/**
 * Migrations-Runner: führt alle noch nicht angewendeten SQL-Dateien aus
 * migrations/ in alphabetischer Reihenfolge aus und protokolliert sie in
 * der Tabelle schema_migrations. Aufruf: php bin/migrate.php
 */

$config = require dirname(__DIR__) . '/config/config.php';

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db']['host'], $config['db']['name']),
    $config['db']['user'],
    $config['db']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

// Nur ein Prozess migriert. Ohne Sperre könnten zwei gleichzeitig startende
// Container dieselbe Datei anwenden — die zweite Ausführung scheitert dann
// mitten im Schema. Die Sperre gilt für die Verbindung und fällt beim Beenden
// automatisch weg.
$lock = $pdo->prepare('SELECT GET_LOCK(?, ?)');
$lock->execute(['klausurwerk_migrate', 60]);
if ((int) $lock->fetchColumn() !== 1) {
    fwrite(STDERR, "Migrationen laufen bereits in einem anderen Prozess — Abbruch.\n");
    exit(1);
}

$pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    SQL);

$applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

$files = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
sort($files);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }

    echo "Wende Migration an: {$name}\n";
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Kann {$name} nicht lesen.\n");
        exit(1);
    }

    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
        $stmt->execute([$name]);
        $ran++;
    } catch (Throwable $e) {
        fwrite(STDERR, "Migration {$name} fehlgeschlagen: {$e->getMessage()}\n");
        fwrite(STDERR,
            "ACHTUNG: Enthält die Datei mehrere Anweisungen, sind die davor\n"
            . "liegenden bereits angewendet, ohne dass die Migration als erledigt\n"
            . "vermerkt wurde. Der Zustand der Datenbank muss von Hand geprüft und\n"
            . "die Datei entweder nachgezogen oder in schema_migrations eingetragen\n"
            . "werden, sonst scheitert jeder weitere Start an derselben Stelle.\n");
        exit(1);
    }
}

echo $ran === 0 ? "Keine neuen Migrationen.\n" : "{$ran} Migration(en) angewendet.\n";
