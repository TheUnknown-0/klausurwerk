<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Gerätebindung: Cookie kw_device_<examId> mit zufälligem Token, gespeichert wird nur der SHA-256-Hash
 * (participations.device_hash). Ein anderes Gerät landet in device_pending und das Pult bekommt
 * eine Anfrage (exam_requests, Typ device).
 */
final class DeviceBinding
{
    public const OK = 'ok';
    /** Das Gerät ist das angefragte neue Gerät (Teilnahme steht auf device_pending). */
    public const PENDING_SELF = 'pending_self';
    /** Gerät gehört nicht (mehr) zur Teilnahme. */
    public const FOREIGN = 'foreign';

    public function __construct(
        private readonly Database $db,
        private readonly bool $secureCookie = false,
    ) {
    }

    public static function cookieName(int $examId): string
    {
        return 'kw_device_' . $examId;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    /** Token aus dem Cookie der aktuellen Anfrage (nur gültige Form, sonst null). */
    public static function tokenFromRequest(int $examId): ?string
    {
        $token = $_COOKIE[self::cookieName($examId)] ?? null;

        return is_string($token) && preg_match('/^[a-f0-9]{48}$/', $token) === 1 ? $token : null;
    }

    /** Setzt das Geräte-Cookie (HttpOnly, SameSite=Lax, Secure bei HTTPS). */
    public function sendCookie(int $examId, string $token): void
    {
        $_COOKIE[self::cookieName($examId)] = $token;
        if (headers_sent()) {
            return;
        }
        setcookie(self::cookieName($examId), $token, [
            'expires' => time() + 86400,
            'path' => '/',
            'secure' => $this->secureCookie,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Gehört das Gerät mit diesem Token zur Teilnahme?
     *
     * @param array<string, mixed> $participation
     */
    public static function check(array $participation, ?string $token): string
    {
        $bound = $participation['device_hash'] ?? null;
        if ($bound === null || $bound === '') {
            return self::OK;
        }
        if ($token === null) {
            return self::FOREIGN;
        }
        $hash = self::hash($token);
        if (hash_equals((string) $bound, $hash)) {
            return self::OK;
        }
        $pending = $participation['pending_device_hash'] ?? null;
        if ($pending !== null && $pending !== '' && hash_equals((string) $pending, $hash)) {
            return self::PENDING_SELF;
        }

        return self::FOREIGN;
    }

    /** Bindet das Gerät an die Teilnahme (erster Beitritt, Warteraum oder Freigabe). */
    public function bind(int $participationId, string $token): void
    {
        $this->db->run(
            'UPDATE participations SET device_hash = ?, pending_device_hash = NULL WHERE id = ?',
            [self::hash($token), $participationId],
        );
    }

    /**
     * Fremdes Gerät meldet sich: Hash als "angefragt" merken und eine offene Anfrage sicherstellen.
     * Der Zustandswechsel nach device_pending bleibt bei ParticipationState.
     *
     * @return bool true, wenn eine neue Anfrage angelegt wurde
     */
    public function requestChange(int $examId, int $participationId, string $token, int $nowMs): bool
    {
        $this->db->run(
            'UPDATE participations SET pending_device_hash = ? WHERE id = ?',
            [self::hash($token), $participationId],
        );
        $open = $this->db->fetchValue(
            "SELECT id FROM exam_requests WHERE participation_id = ? AND type = 'device' AND status = 'open' LIMIT 1",
            [$participationId],
        );
        if ($open !== null) {
            return false;
        }
        $this->db->run(
            "INSERT INTO exam_requests (exam_id, participation_id, type, status, created_at) VALUES (?, ?, 'device', 'open', ?)",
            [$examId, $participationId, ExamClock::toDb($nowMs)],
        );

        return true;
    }

    /** Freigabe: das angefragte Gerät wird das gebundene, das alte ist danach fremd. */
    public function approve(int $participationId): void
    {
        $this->db->run(
            'UPDATE participations SET device_hash = COALESCE(pending_device_hash, device_hash), pending_device_hash = NULL WHERE id = ?',
            [$participationId],
        );
    }

    /** Ablehnung: angefragtes Gerät verwerfen, das gebundene bleibt. */
    public function reject(int $participationId): void
    {
        $this->db->run('UPDATE participations SET pending_device_hash = NULL WHERE id = ?', [$participationId]);
    }
}
