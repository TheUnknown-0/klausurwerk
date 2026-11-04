<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Audit-Log: protokolliert Admin- und sicherheitsrelevante Aktionen.
 */
final class Audit
{
    public function __construct(
        private readonly Database $db,
        private readonly Auth $auth,
    ) {
    }

    public function log(
        string $action,
        string $severity = 'info',
        ?string $details = null,
        ?int $userId = null,
        ?string $username = null,
    ): void {
        $user = $this->auth->user();

        $this->db->run(
            'INSERT INTO audit_logs (user_id, username, action, severity, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $userId ?? ($user !== null ? (int) $user['id'] : null),
                $username ?? ($user['username'] ?? null),
                $action,
                in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'info',
                $details,
                self::clientIp(),
                mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ],
        );
    }

    /**
     * Ermittelt die Client-IP. X-Forwarded-For wird nur berücksichtigt, wenn
     * REMOTE_ADDR ein vertrauenswürdiger Proxy ist (TRUSTED_PROXIES, CIDR).
     */
    public static function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

        if ($forwarded === '' || !self::isTrustedProxy($remote)) {
            return $remote;
        }

        // Erste IP der Kette = ursprünglicher Client
        $first = trim(explode(',', $forwarded)[0]);

        return filter_var($first, FILTER_VALIDATE_IP) !== false ? $first : $remote;
    }

    /**
     * Vertrauenswürdige Proxys.
     *
     * Der Standard umfasst NUR die Loopback-Adresse. Früher galten die ganzen
     * privaten Bereiche (10/8, 172.16/12, 192.168/16) als vertrauenswürdig —
     * in einem Schulnetz ist damit jeder Rechner ein „Proxy“ und kann per
     * X-Forwarded-For eine beliebige Absenderadresse behaupten. Damit ließen
     * sich sämtliche IP-Grenzen (Login) umgehen und
     * die Einträge im Audit-Log fälschen.
     *
     * Wer hinter einem echten Reverse-Proxy betreibt, trägt dessen Adresse
     * in TRUSTED_PROXIES ein — und nur diese.
     */
    private static function isTrustedProxy(string $ip): bool
    {
        $raw = getenv('TRUSTED_PROXIES');
        $cidrs = $raw !== false && $raw !== ''
            ? array_map('trim', explode(',', $raw))
            : ['127.0.0.1', '::1'];

        foreach ($cidrs as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        if ($remainder > 0) {
            $mask = 0xFF << (8 - $remainder) & 0xFF;
            if ((ord($ipBin[$bytes]) & $mask) !== (ord($subnetBin[$bytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
