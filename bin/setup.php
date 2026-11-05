<?php

declare(strict_types=1);

/**
 * Erst-Einrichtung auf der Kommandozeile: legt das erste Admin-Konto, den Schulnamen
 * und optional die öffentliche Adresse an. Danach ist /setup im Browser gesperrt.
 *
 *   php bin/setup.php            fragt alles ab (Passwort ohne Anzeige)
 *   php bin/setup.php --check    Exit-Code 0, wenn die Einrichtung noch aussteht, sonst 3
 *   php bin/setup.php --stdin    liest Zeilen "schluessel=wert" von der Standardeingabe
 *                                (für install.sh; das Passwort erscheint so nie in der Prozessliste)
 *
 * Schlüssel für --stdin: username, password, password_confirm, school_name, firstname,
 * lastname, email, public_base_url.
 *
 * Im Container: docker compose exec app php bin/setup.php
 */

use App\Core\Context;
use App\Services\FirstSetup;

if (PHP_SAPI !== 'cli') {
    exit("Nur auf der Kommandozeile.\n");
}

/** @var Context $ctx */
$ctx = require dirname(__DIR__) . '/src/bootstrap.php';
$setup = new FirstSetup($ctx->db, $ctx->settings);
$mode = $argv[1] ?? '';

if ($mode === '--check') {
    exit($setup->isNeeded() ? 0 : 3);
}
if (!$setup->isNeeded()) {
    fwrite(STDERR, "Die Einrichtung ist bereits abgeschlossen: Es gibt schon ein Konto.\n");
    exit(3);
}

if ($mode === '--stdin') {
    $input = [];
    while (($line = fgets(STDIN)) !== false) {
        $line = rtrim($line, "\r\n");
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $input[substr($line, 0, $pos)] = substr($line, $pos + 1);
    }
} elseif ($mode === '') {
    $ask = static function (string $label, string $default = ''): string {
        $hint = $default !== '' ? " [{$default}]" : '';
        fwrite(STDOUT, "{$label}{$hint}: ");
        $value = trim((string) fgets(STDIN));

        return $value !== '' ? $value : $default;
    };
    $askSecret = static function (string $label): string {
        fwrite(STDOUT, "{$label}: ");
        $tty = stream_isatty(STDIN);
        if ($tty) {
            shell_exec('stty -echo 2>/dev/null');
        }
        $value = rtrim((string) fgets(STDIN), "\r\n");
        if ($tty) {
            shell_exec('stty echo 2>/dev/null');
        }
        fwrite(STDOUT, "\n");

        return $value;
    };

    echo "Erst-Einrichtung von Klausurwerk\n\n";
    $input = [
        'school_name' => $ask('Name der Schule'),
        'public_base_url' => $ask('Öffentliche Adresse, z. B. https://klausur.example.org (leer: später)'),
        'firstname' => $ask('Vorname des Admins'),
        'lastname' => $ask('Nachname des Admins'),
        'username' => $ask('Benutzername', 'admin'),
        'email' => $ask('E-Mail für Anmeldecodes (leer: keine)'),
        'password' => $askSecret('Passwort (mindestens 8 Zeichen)'),
    ];
    $input['password_confirm'] = $askSecret('Passwort wiederholen');
} else {
    fwrite(STDERR, "Unbekannte Option {$mode}. Erlaubt: --check, --stdin oder keine.\n");
    exit(2);
}

[$data, $error] = FirstSetup::validate($input);
if ($data === null) {
    fwrite(STDERR, $error . "\n");
    exit(1);
}

try {
    $userId = $setup->run($data);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(3);
}

$ctx->audit->log(
    'Erst-Einrichtung abgeschlossen (Kommandozeile)',
    'info',
    "Admin: {$data['username']}, Schule: {$data['school_name']}",
    $userId,
    $data['username'],
);

echo "Admin-Konto \"{$data['username']}\" angelegt. Die Einrichtung im Browser ist jetzt gesperrt.\n";
