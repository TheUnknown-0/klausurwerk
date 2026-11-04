<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Einmalcodes für die passwortlose Anmeldung per E-Mail (deckt auch
 * "Passwort vergessen" ab — wer den Code kennt, kommt ohne altes Passwort
 * wieder rein).
 *
 * Rate-Limiting läuft NICHT hier, sondern über den bestehenden
 * LoginThrottle (Aufrufer zählt sowohl Anfragen als auch Fehlversuche als
 * "Fehlschlag" — das ist bewusst dieselbe Bremse wie beim Passwort-Login).
 * Diese Klasse kümmert sich nur um Erzeugen/Prüfen des Codes selbst:
 * 6-stellig, 10 Minuten gültig, einmal verwendbar, max. 5 Rateversuche.
 */
final class LoginCodeService
{
    private const LENGTH = 6;
    private const TTL_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;

    /** Mindestabstand zwischen zwei Codes desselben Kontos — verhindert Mail-Spam per Mehrfachklick. */
    private const RESEND_COOLDOWN_SECONDS = 60;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * True, wenn seit dem letzten für dieses Konto erzeugten Code noch keine
     * 60 Sekunden vergangen sind — der Aufrufer soll dann KEINEN neuen Code
     * verschicken (der vorherige bleibt gültig), aber nach außen dieselbe
     * Meldung zeigen wie bei einem echten Versand, um nichts über die
     * Existenz des Kontos zu verraten.
     */
    public function withinCooldown(int $userId): bool
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_codes WHERE user_id = ? AND created_at > (NOW() - INTERVAL ' . self::RESEND_COOLDOWN_SECONDS . ' SECOND)',
            [$userId],
        ) > 0;
    }

    /**
     * Erzeugt einen neuen Code und macht vorherige, noch nicht verwendete
     * Codes desselben Kontos ungültig — es soll immer nur einer gelten.
     *
     * @return string Der Klartext-Code (nur zum sofortigen Versand, wird nicht gespeichert).
     */
    public function issue(int $userId, string $ip): string
    {
        $code = str_pad((string) random_int(0, 999999), self::LENGTH, '0', STR_PAD_LEFT);

        $this->db->run('DELETE FROM login_codes WHERE user_id = ? AND used_at IS NULL', [$userId]);
        $this->db->run(
            'INSERT INTO login_codes (user_id, code_hash, expires_at, ip_address)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . self::TTL_MINUTES . ' MINUTE), ?)',
            [$userId, password_hash($code, PASSWORD_DEFAULT), $ip],
        );

        // Gelegentliches Aufräumen abgelaufener Codes.
        if (random_int(1, 50) === 1) {
            $this->db->run('DELETE FROM login_codes WHERE expires_at < (NOW() - INTERVAL 1 DAY)');
        }

        return $code;
    }

    /**
     * Prüft den eingegebenen Code gegen den aktuell gültigen des Kontos.
     * Bei falscher Eingabe wird der Rateversuchs-Zähler der Code-Zeile erhöht
     * (unabhängig vom äußeren LoginThrottle) — nach MAX_ATTEMPTS gilt der
     * Code als verbraucht, ganz ohne dass der Code selbst erraten wurde.
     */
    public function verify(int $userId, string $code): bool
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM login_codes
             WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW() AND attempts < ?
             ORDER BY created_at DESC LIMIT 1',
            [$userId, self::MAX_ATTEMPTS],
        );
        if ($row === null) {
            return false;
        }

        if (!password_verify($code, (string) $row['code_hash'])) {
            $this->db->run('UPDATE login_codes SET attempts = attempts + 1 WHERE id = ?', [(int) $row['id']]);

            return false;
        }

        $this->db->run('UPDATE login_codes SET used_at = NOW() WHERE id = ?', [(int) $row['id']]);

        return true;
    }
}
