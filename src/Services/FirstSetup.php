<?php

declare(strict_types=1);

namespace App\Services;

use App\Controllers\AuthController;
use App\Core\Database;

/**
 * Erst-Einrichtung: legt das erste Admin-Konto und den Schulnamen an.
 *
 * Gemeinsam genutzt vom Web-Assistenten (/setup) und vom Kommandozeilen-Skript
 * bin/setup.php, das install.sh aufruft. Läuft nur, solange es noch kein Konto gibt.
 */
final class FirstSetup
{
    /** Sperre gegen zwei gleichzeitige Einrichtungen (Web und Kommandozeile). */
    private const LOCK = 'klausurwerk_first_setup';

    public function __construct(
        private readonly Database $db,
        private readonly Settings $settings,
    ) {
    }

    public function isNeeded(): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM users LIMIT 1') === null;
    }

    /**
     * Prüft die Eingaben und bringt sie in Form (getrimmt, Adresse ohne Schrägstrich am Ende).
     *
     * @param array<string, mixed> $input username, password, password_confirm, school_name,
     *                                    firstname, lastname, email, public_base_url (optional)
     * @return array{0: array<string, string>|null, 1: string|null} [Daten, Fehlermeldung]
     */
    public static function validate(array $input): array
    {
        $data = [
            'username' => trim((string) ($input['username'] ?? '')),
            'password' => (string) ($input['password'] ?? ''),
            'school_name' => trim((string) ($input['school_name'] ?? '')),
            'firstname' => trim((string) ($input['firstname'] ?? '')),
            'lastname' => trim((string) ($input['lastname'] ?? '')),
            'email' => trim((string) ($input['email'] ?? '')),
            'public_base_url' => rtrim(trim((string) ($input['public_base_url'] ?? '')), '/'),
        ];
        $confirm = (string) ($input['password_confirm'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $data['username'])) {
            return [null, 'Der Admin-Benutzername darf 3 bis 50 Zeichen lang sein (Buchstaben, Zahlen, Punkt, Minus, Unterstrich).'];
        }
        if (mb_strlen($data['password']) < AuthController::MIN_PASSWORD_LENGTH || $data['password'] !== $confirm) {
            return [null, 'Das Passwort muss mindestens ' . AuthController::MIN_PASSWORD_LENGTH
                . ' Zeichen lang sein und beide Eingaben müssen übereinstimmen.'];
        }
        if ($data['school_name'] === '') {
            return [null, 'Bitte den Namen der Schule angeben.'];
        }
        if (mb_strlen($data['school_name']) > 200) {
            return [null, 'Der Name der Schule ist zu lang (höchstens 200 Zeichen).'];
        }
        if ($data['email'] !== '' && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            return [null, 'Bitte eine gültige E-Mail-Adresse angeben (oder das Feld leer lassen).'];
        }
        if ($data['public_base_url'] !== '') {
            $valid = filter_var($data['public_base_url'], FILTER_VALIDATE_URL) !== false
                && (str_starts_with($data['public_base_url'], 'http://') || str_starts_with($data['public_base_url'], 'https://'));
            if (!$valid) {
                return [null, 'Die öffentliche Adresse muss mit http:// oder https:// beginnen und gültig sein.'];
            }
        }

        return [$data, null];
    }

    /**
     * Legt Admin und Einstellungen an. Gibt die ID des neuen Kontos zurück.
     *
     * @param array<string, string> $data Ergebnis von validate()
     * @throws \RuntimeException wenn inzwischen schon ein Konto existiert
     */
    public function run(array $data): int
    {
        if ((int) $this->db->fetchValue('SELECT GET_LOCK(?, 10)', [self::LOCK]) !== 1) {
            throw new \RuntimeException('Die Einrichtung läuft gerade an anderer Stelle.');
        }

        try {
            return $this->db->transaction(function () use ($data): int {
                if (!$this->isNeeded()) {
                    throw new \RuntimeException('Die Einrichtung ist bereits abgeschlossen: Es gibt schon ein Konto.');
                }

                $this->settings->set('school_name', $data['school_name']);
                if (($data['public_base_url'] ?? '') !== '') {
                    $this->settings->set('public_base_url', $data['public_base_url']);
                }
                $this->db->run(
                    "INSERT INTO users (username, email, password, role, firstname, lastname)
                     VALUES (?, ?, ?, 'admin', ?, ?)",
                    [
                        $data['username'],
                        $data['email'] !== '' ? $data['email'] : null,
                        password_hash($data['password'], PASSWORD_DEFAULT),
                        $data['firstname'] !== '' ? $data['firstname'] : 'Admin',
                        $data['lastname'],
                    ],
                );

                return $this->db->lastInsertId();
            });
        } finally {
            $this->db->fetchValue('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }
}
