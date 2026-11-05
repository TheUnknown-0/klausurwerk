<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;

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

    /** Client-IP der Anfrage (Proxy-Header nur von vertrauenswürdigen Proxys, siehe Core\Request). */
    public static function clientIp(): string
    {
        return Request::clientIp();
    }
}
