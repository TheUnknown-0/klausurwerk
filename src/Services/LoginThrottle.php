<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Rate-Limiting für Logins.
 *
 * Konto und IP werden GETRENNT gezählt, nicht über ODER zusammengeworfen:
 * Eine Schule sitzt hinter einer einzigen öffentlichen IP. Eine gemeinsame
 * Grenze von zehn Fehlversuchen würde am Morgen des Aktionstags — wenn sich hunderte
 * Schüler:innen anmelden und sich einige vertippen — den Login für ALLE
 * sperren. Deshalb:
 *
 *   - je Benutzername eng  (10 Fehlversuche / 5 Minuten) — gegen gezieltes
 *     Durchprobieren eines Kontos;
 *   - je IP-Adresse weit   (60 Fehlversuche / 5 Minuten) — fängt automatisierte
 *     Angriffe ab, liegt aber weit über dem, was eine ganze Schule im
 *     Normalbetrieb erzeugt.
 */
final class LoginThrottle
{
    /** Fehlversuche je Benutzername im Zeitfenster. */
    private const MAX_PER_USER = 10;

    /** Fehlversuche je IP im Zeitfenster — bewusst hoch wegen NAT. */
    private const MAX_PER_IP = 60;

    private const WINDOW_MINUTES = 5;

    public function __construct(private readonly Database $db)
    {
    }

    /** Ist der Login für dieses Konto oder von dieser IP aus gesperrt? */
    public function isBlocked(string $username, string $ip): bool
    {
        return $this->countForUser($username) >= self::MAX_PER_USER
            || $this->countForIp($ip) >= self::MAX_PER_IP;
    }

    public function recordFailure(string $username, string $ip): void
    {
        $this->db->run(
            'INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)',
            [$username, $ip],
        );
        // Gelegentliches Aufräumen alter Einträge
        if (random_int(1, 50) === 1) {
            $this->db->run('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
        }
    }

    /**
     * Räumt nach erfolgreichem Login die Fehlversuche des Kontos ab —
     * bewusst NUR die des Kontos: Würde hier auch die IP geleert, könnte ein
     * beliebiges gültiges Konto den Schutz für alle anderen Konten derselben
     * IP aufheben.
     */
    public function clear(string $username): void
    {
        $this->db->run('DELETE FROM login_attempts WHERE username = ?', [$username]);
    }

    private function countForUser(string $username): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_attempts
             WHERE username = ? AND attempted_at > (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)',
            [$username],
        );
    }

    private function countForIp(string $ip): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = ? AND attempted_at > (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)',
            [$ip],
        );
    }
}
