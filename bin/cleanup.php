<?php

declare(strict_types=1);

/**
 * Verwaiste Upload-Dateien finden und auf Wunsch löschen.
 *
 *   php bin/cleanup.php             listet Dateien unter uploads/, zu denen keine Datenbankzeile gehört
 *   php bin/cleanup.php --delete    löscht sie
 *
 * Im Container: docker compose exec app php bin/cleanup.php [--delete]
 *
 * Verwaist heißt: Die Datei liegt in uploads/materials, uploads/answers, uploads/logos oder
 * uploads/branding, aber weder exam_materials, answer_files noch die settings-Tabelle kennen
 * ihren Namen. Dateien, die jünger als eine Stunde sind, bleiben unangetastet (ein laufender
 * Upload hat seine Zeile vielleicht noch nicht). Jeder Löschlauf steht im Audit-Log.
 */

use App\Core\Context;

if (PHP_SAPI !== 'cli') {
    exit("Nur auf der Kommandozeile.\n");
}

/** @var Context $ctx */
$ctx = require dirname(__DIR__) . '/src/bootstrap.php';
$db = $ctx->db;
$delete = in_array('--delete', $argv, true);
$uploadDir = rtrim((string) $ctx->config['uploads']['dir'], '/\\');
$minAgeSeconds = 3600;

$known = [
    'materials' => array_column($db->fetchAll('SELECT stored_name FROM exam_materials'), 'stored_name'),
    'answers' => array_column($db->fetchAll('SELECT stored_name FROM answer_files'), 'stored_name'),
];
// Logo und Login-Hintergrund stehen als Dateiname in den Einstellungen.
$settingValues = array_filter(array_column($db->fetchAll('SELECT setting_value FROM settings'), 'setting_value'), 'is_string');
$known['logos'] = $settingValues;
$known['branding'] = $settingValues;

$orphans = [];
$bytes = 0;
foreach ($known as $subdir => $names) {
    $dir = $uploadDir . '/' . $subdir;
    if (!is_dir($dir)) {
        continue;
    }
    $referenced = array_fill_keys(array_map('strval', $names), true);
    foreach (scandir($dir) ?: [] as $name) {
        $path = $dir . '/' . $name;
        if ($name === '.' || $name === '..' || !is_file($path) || isset($referenced[$name])) {
            continue;
        }
        if (time() - (int) filemtime($path) < $minAgeSeconds) {
            continue;
        }
        $orphans[] = $path;
        $bytes += (int) filesize($path);
    }
}

if ($orphans === []) {
    echo "Keine verwaisten Dateien unter {$uploadDir}.\n";
    exit(0);
}

printf("%d verwaiste Datei(en), %.1f MB:\n", count($orphans), $bytes / 1048576);
foreach ($orphans as $path) {
    echo '  ' . substr($path, strlen($uploadDir) + 1) . "\n";
}

if (!$delete) {
    echo "Nichts gelöscht. Zum Löschen: php bin/cleanup.php --delete\n";
    exit(0);
}

$removed = 0;
foreach ($orphans as $path) {
    if (@unlink($path)) {
        $removed++;
    } else {
        fwrite(STDERR, "Konnte nicht löschen: {$path}\n");
    }
}
$ctx->audit->log('uploads.aufraeumen', 'warning', sprintf('%d verwaiste Datei(en) gelöscht (%.1f MB)', $removed, $bytes / 1048576), null, 'cleanup.php');
echo "{$removed} Datei(en) gelöscht.\n";
