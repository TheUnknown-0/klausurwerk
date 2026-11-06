<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Core\Database;

/**
 * Kleiner OAuth-2.1-Autorisierungsserver für den KI-Zugang (MCP).
 *
 *  - Clients melden sich selbst an (Dynamic Client Registration, RFC 7591). Es gibt nur öffentliche
 *    Clients ohne Geheimnis; geschützt wird der Ablauf durch PKCE (S256) und die Zustimmung der
 *    Lehrkraft im Browser.
 *  - Autorisierungscodes gelten 10 Minuten und nur einmal.
 *  - Ein Zugang (Grant) je Zustimmung: Zugriffsschlüssel 1 Stunde, Erneuerungsschlüssel 30 Tage,
 *    bei jeder Erneuerung neu (Rotation). Widerruf beendet den Zugang sofort.
 *  - Gespeichert werden nur SHA-256-Hashes von Codes und Schlüsseln.
 *  - Zielgebunden (RFC 8707): Schlüssel gelten nur für die MCP-Adresse dieser Installation.
 */
final class OAuthServer
{
    public const SCOPE = 'klausurwerk';
    public const ACCESS_TTL = 3600;
    public const REFRESH_TTL = 30 * 86400;
    public const CODE_TTL = 600;
    /** Obergrenze angemeldeter Clients (offene Registrierung, Schutz gegen Fluten). */
    public const MAX_CLIENTS = 500;

    public function __construct(private readonly Database $db)
    {
    }

    // ---------- Hilfen ----------

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    private static function random(string $prefix): string
    {
        return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** base64url(SHA-256(verifier)) nach RFC 7636. */
    public static function challengeOf(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function now(int $plusSeconds = 0): string
    {
        return date('Y-m-d H:i:s', time() + $plusSeconds);
    }

    /** Loopback-Adresse (RFC 8252: dort darf der Port frei gewählt sein). */
    private static function isLoopback(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * Zulässige Redirect-Adresse: https überall, http nur auf dem eigenen Rechner (localhost),
     * eigene App-Schemata (cursor://, vscode://), aber nie javascript:, data:, file: und Co.
     */
    public static function validRedirectUri(string $uri): bool
    {
        if ($uri === '' || strlen($uri) > 2000 || preg_match('/[\x00-\x20\x7F]/', $uri) === 1 || str_contains($uri, '#')) {
            return false;
        }
        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            return isset($parts['host']) && $parts['host'] !== '';
        }
        if ($scheme === 'http') {
            return isset($parts['host']) && self::isLoopback($parts['host']);
        }
        if (in_array($scheme, ['javascript', 'data', 'file', 'vbscript', 'blob', 'about', 'ftp', 'ws', 'wss'], true)) {
            return false;
        }

        return preg_match('/^[a-z][a-z0-9+.-]{1,60}$/', $scheme) === 1;
    }

    /** Passt die angefragte Redirect-Adresse zu einer registrierten? Loopback: Port egal. */
    public static function redirectMatches(array $registered, string $uri): bool
    {
        if (in_array($uri, $registered, true)) {
            return true;
        }
        $want = parse_url($uri);
        if ($want === false || strtolower($want['scheme'] ?? '') !== 'http' || !self::isLoopback((string) ($want['host'] ?? ''))) {
            return false;
        }
        foreach ($registered as $r) {
            $have = parse_url((string) $r);
            if ($have !== false && strtolower($have['scheme'] ?? '') === 'http'
                && self::isLoopback((string) ($have['host'] ?? ''))
                && strtolower((string) $have['host']) === strtolower((string) $want['host'])
                && ($have['path'] ?? '/') === ($want['path'] ?? '/')
                && ($have['query'] ?? '') === ($want['query'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /** Herkunft für CSP form-action: https://host[:port] oder "schema:" bei App-Schemata. */
    public static function originOf(string $uri): string
    {
        $p = parse_url($uri);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        if (in_array($scheme, ['http', 'https'], true)) {
            return $scheme . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        }

        return $scheme . ':';
    }

    // ---------- Clients ----------

    /**
     * Dynamic Client Registration (RFC 7591).
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed> Antwort nach RFC 7591 3.2.1
     */
    public function registerClient(array $in): array
    {
        $uris = $in['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || count($uris) > 10) {
            throw new OAuthError('invalid_redirect_uri', 'redirect_uris fehlt oder enthält mehr als 10 Adressen.');
        }
        $clean = [];
        foreach ($uris as $uri) {
            if (!is_string($uri) || !self::validRedirectUri($uri)) {
                throw new OAuthError('invalid_redirect_uri', 'Unzulässige Redirect-Adresse: https, http://localhost oder ein App-Schema.');
            }
            $clean[] = $uri;
        }
        $method = (string) ($in['token_endpoint_auth_method'] ?? 'none');
        if ($method !== 'none') {
            throw new OAuthError('invalid_client_metadata', 'Nur öffentliche Clients (token_endpoint_auth_method "none") mit PKCE.');
        }
        foreach ((array) ($in['grant_types'] ?? ['authorization_code']) as $g) {
            if (!in_array($g, ['authorization_code', 'refresh_token'], true)) {
                throw new OAuthError('invalid_client_metadata', 'Nicht unterstützter grant_type: ' . mb_substr((string) $g, 0, 40));
            }
        }
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($in['client_name'] ?? '')) ?? '');
        $name = mb_substr($name !== '' ? $name : 'KI-Programm', 0, 200);

        if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM oauth_clients') >= self::MAX_CLIENTS) {
            $this->cleanup();
            if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM oauth_clients') >= self::MAX_CLIENTS) {
                throw new OAuthError('temporarily_unavailable', 'Zu viele angemeldete Programme. Bitte später erneut versuchen.', 503);
            }
        }

        $clientId = 'kwc_' . bin2hex(random_bytes(16));
        $this->db->run(
            'INSERT INTO oauth_clients (client_id, client_name, redirect_uris) VALUES (?, ?, ?)',
            [$clientId, $name, json_encode(array_values(array_unique($clean)), JSON_UNESCAPED_SLASHES)],
        );

        return [
            'client_id' => $clientId,
            'client_id_issued_at' => time(),
            'client_name' => $name,
            'redirect_uris' => array_values(array_unique($clean)),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => self::SCOPE,
        ];
    }

    /** @return array{client_id: string, client_name: string, redirect_uris: list<string>}|null */
    public function findClient(string $clientId): ?array
    {
        if (preg_match('/^kwc_[0-9a-f]{32}$/', $clientId) !== 1) {
            return null;
        }
        $row = $this->db->fetchOne('SELECT client_id, client_name, redirect_uris FROM oauth_clients WHERE client_id = ?', [$clientId]);
        if ($row === null) {
            return null;
        }
        $uris = json_decode((string) $row['redirect_uris'], true);

        return [
            'client_id' => (string) $row['client_id'],
            'client_name' => (string) $row['client_name'],
            'redirect_uris' => is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [],
        ];
    }

    // ---------- Autorisierung ----------

    /**
     * Prüft die Parameter der Autorisierungsanfrage, die nicht den Client selbst betreffen.
     * Client und Redirect prüft der Aufrufer vorher (dort wird nicht zurückgeleitet).
     *
     * @param array<string, mixed> $q
     * @return array{code_challenge: string, scope: string, resource: string}
     */
    public function checkAuthorizeParams(array $q, string $canonicalResource): array
    {
        if (($q['response_type'] ?? '') !== 'code') {
            throw new OAuthError('unsupported_response_type', 'Nur response_type=code wird unterstützt.');
        }
        $challenge = (string) ($q['code_challenge'] ?? '');
        if (($q['code_challenge_method'] ?? '') !== 'S256' || preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge) !== 1) {
            throw new OAuthError('invalid_request', 'PKCE mit code_challenge_method=S256 ist Pflicht.');
        }
        $scope = trim((string) ($q['scope'] ?? ''));
        foreach ($scope === '' ? [] : preg_split('/\s+/', $scope) as $s) {
            if (!in_array($s, [self::SCOPE, 'offline_access'], true)) {
                throw new OAuthError('invalid_scope', 'Unbekannter Scope: ' . mb_substr((string) $s, 0, 40));
            }
        }
        $resource = $this->checkResource($q['resource'] ?? null, $canonicalResource);

        return ['code_challenge' => $challenge, 'scope' => self::SCOPE, 'resource' => $resource];
    }

    /** RFC 8707: Ziel muss die eigene MCP-Adresse sein (leer = sie). */
    private function checkResource(mixed $resource, string $canonical): string
    {
        if ($resource === null || $resource === '') {
            return $canonical;
        }
        if (!is_string($resource) || rtrim($resource, '/') !== rtrim($canonical, '/')) {
            throw new OAuthError('invalid_target', 'Der Zugang gilt nur für ' . $canonical . '.');
        }

        return $canonical;
    }

    public function createCode(string $clientId, int $userId, string $redirectUri, string $challenge, string $scope, string $resource): string
    {
        $code = self::random('kwo_');
        $this->db->run(
            'INSERT INTO oauth_codes (code_hash, client_id, user_id, redirect_uri, code_challenge, scope, resource, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [self::hash($code), $clientId, $userId, $redirectUri, $challenge, $scope, $resource, self::now(self::CODE_TTL)],
        );

        return $code;
    }

    // ---------- Token-Endpunkt ----------

    /**
     * grant_type=authorization_code: Code gegen Schlüssel tauschen (PKCE prüfen, Code verbrauchen).
     *
     * @return array<string, mixed> Token-Antwort
     */
    public function exchangeCode(string $clientId, string $code, string $redirectUri, string $verifier, mixed $resource, string $canonicalResource): array
    {
        if ($this->findClient($clientId) === null) {
            throw new OAuthError('invalid_client', 'Unbekannter Client.', 401);
        }
        if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) !== 1) {
            throw new OAuthError('invalid_request', 'code_verifier fehlt oder ist ungültig.');
        }

        // Einmalig: Der Code wird beim ersten Einlösen gelöscht, auch wenn die Prüfung danach scheitert.
        $row = $this->db->transaction(function () use ($code): ?array {
            $row = $this->db->fetchOne('SELECT * FROM oauth_codes WHERE code_hash = ? FOR UPDATE', [self::hash($code)]);
            if ($row !== null) {
                $this->db->run('DELETE FROM oauth_codes WHERE code_hash = ?', [$row['code_hash']]);
            }

            return $row;
        });
        if ($row === null || (string) $row['client_id'] !== $clientId || strtotime((string) $row['expires_at']) < time()) {
            throw new OAuthError('invalid_grant', 'Der Code ist ungültig, abgelaufen oder schon benutzt.');
        }
        if ((string) $row['redirect_uri'] !== $redirectUri) {
            throw new OAuthError('invalid_grant', 'redirect_uri passt nicht zur Anfrage.');
        }
        if (!hash_equals((string) $row['code_challenge'], self::challengeOf($verifier))) {
            throw new OAuthError('invalid_grant', 'PKCE-Prüfung fehlgeschlagen.');
        }

        $target = $this->checkResource($resource, $canonicalResource);

        $access = self::random('kwa_');
        $refresh = self::random('kwr_');
        $this->db->run(
            'INSERT INTO oauth_grants (user_id, client_id, scope, resource, access_hash, access_expires_at, refresh_hash, refresh_expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [(int) $row['user_id'], $clientId, (string) $row['scope'], $target, self::hash($access), self::now(self::ACCESS_TTL), self::hash($refresh), self::now(self::REFRESH_TTL)],
        );
        $this->db->run('UPDATE oauth_clients SET last_used_at = NOW() WHERE client_id = ?', [$clientId]);

        return self::tokenResponse($access, $refresh, (string) $row['scope']);
    }

    /**
     * grant_type=refresh_token: neuen Zugriffsschlüssel ausgeben, Erneuerungsschlüssel rotieren.
     *
     * @return array<string, mixed>
     */
    public function refresh(string $clientId, string $refreshToken, mixed $resource, string $canonicalResource): array
    {
        return $this->db->transaction(function () use ($clientId, $refreshToken, $resource, $canonicalResource): array {
            $grant = $this->db->fetchOne('SELECT * FROM oauth_grants WHERE refresh_hash = ? FOR UPDATE', [self::hash($refreshToken)]);
            if ($grant === null || (string) $grant['client_id'] !== $clientId || $grant['revoked_at'] !== null
                || strtotime((string) $grant['refresh_expires_at']) < time()) {
                throw new OAuthError('invalid_grant', 'Der Erneuerungsschlüssel ist ungültig oder abgelaufen. Bitte neu verbinden.');
            }
            $this->checkResource($resource, $canonicalResource);
            $access = self::random('kwa_');
            $refresh = self::random('kwr_');
            $this->db->run(
                'UPDATE oauth_grants SET access_hash = ?, access_expires_at = ?, refresh_hash = ?, refresh_expires_at = ?, last_used_at = NOW() WHERE id = ?',
                [self::hash($access), self::now(self::ACCESS_TTL), self::hash($refresh), self::now(self::REFRESH_TTL), (int) $grant['id']],
            );

            return self::tokenResponse($access, $refresh, (string) $grant['scope']);
        });
    }

    /** @return array<string, mixed> */
    private static function tokenResponse(string $access, string $refresh, string $scope): array
    {
        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope' => $scope,
        ];
    }

    /**
     * Zugriffsschlüssel prüfen. Rückgabe: Zugang (id, user_id, client_id, resource) oder null.
     *
     * @return array<string, mixed>|null
     */
    public function authenticate(string $accessToken, string $canonicalResource): ?array
    {
        if (preg_match('/^kwa_[A-Za-z0-9_-]{43}$/', $accessToken) !== 1) {
            return null;
        }
        $grant = $this->db->fetchOne(
            'SELECT id, user_id, client_id, resource, access_expires_at, revoked_at, last_used_at FROM oauth_grants WHERE access_hash = ?',
            [self::hash($accessToken)],
        );
        if ($grant === null || $grant['revoked_at'] !== null || strtotime((string) $grant['access_expires_at']) < time()) {
            return null;
        }
        if ((string) $grant['resource'] !== '' && rtrim((string) $grant['resource'], '/') !== rtrim($canonicalResource, '/')) {
            return null;
        }
        // Zuletzt benutzt: höchstens einmal pro Minute schreiben.
        if ($grant['last_used_at'] === null || strtotime((string) $grant['last_used_at']) < time() - 60) {
            $this->db->run('UPDATE oauth_grants SET last_used_at = NOW() WHERE id = ?', [(int) $grant['id']]);
        }

        return $grant;
    }

    // ---------- Widerruf und Übersicht ----------

    /** RFC 7009: Zugriffs- oder Erneuerungsschlüssel widerrufen (beendet den ganzen Zugang). */
    public function revokeToken(string $token): void
    {
        $h = self::hash($token);
        $this->db->run('UPDATE oauth_grants SET revoked_at = NOW() WHERE (access_hash = ? OR refresh_hash = ?) AND revoked_at IS NULL', [$h, $h]);
    }

    /** Zugang einer Lehrkraft widerrufen. Rückgabe: ob es ihn gab. */
    public function revokeGrant(int $grantId, ?int $userId): bool
    {
        $sql = 'UPDATE oauth_grants SET revoked_at = NOW(), access_hash = NULL, refresh_hash = NULL WHERE id = ? AND revoked_at IS NULL';
        $args = [$grantId];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $args[] = $userId;
        }

        return $this->db->run($sql, $args)->rowCount() > 0;
    }

    /** Alle Zugänge sofort beenden (Admin schaltet den KI-Zugang ab). Rückgabe: Anzahl. */
    public function revokeAll(): int
    {
        return $this->db->run('UPDATE oauth_grants SET revoked_at = NOW(), access_hash = NULL, refresh_hash = NULL WHERE revoked_at IS NULL')->rowCount();
    }

    /**
     * Aktive Zugänge (einer Lehrkraft oder aller) für die Übersicht.
     *
     * @return list<array<string, mixed>>
     */
    public function activeGrants(?int $userId = null): array
    {
        $sql = 'SELECT g.id, g.user_id, g.client_id, g.created_at, g.last_used_at, c.client_name, u.username, u.firstname, u.lastname
                FROM oauth_grants g
                LEFT JOIN oauth_clients c ON c.client_id = g.client_id
                JOIN users u ON u.id = g.user_id
                WHERE g.revoked_at IS NULL AND g.refresh_expires_at > NOW()';
        $args = [];
        if ($userId !== null) {
            $sql .= ' AND g.user_id = ?';
            $args[] = $userId;
        }

        return $this->db->fetchAll($sql . ' ORDER BY COALESCE(g.last_used_at, g.created_at) DESC', $args);
    }

    /** Abgelaufenes aufräumen: Codes, beendete Zugänge (nach 30 Tagen), ungenutzte Clients (nach 7 Tagen). */
    public function cleanup(): void
    {
        $this->db->run('DELETE FROM oauth_codes WHERE expires_at < NOW()');
        $this->db->run('DELETE FROM oauth_grants WHERE (revoked_at IS NOT NULL AND revoked_at < NOW() - INTERVAL 30 DAY) OR refresh_expires_at < NOW() - INTERVAL 30 DAY');
        $this->db->run(
            'DELETE FROM oauth_clients
             WHERE created_at < NOW() - INTERVAL 7 DAY
               AND client_id NOT IN (SELECT client_id FROM oauth_grants WHERE revoked_at IS NULL)
               AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL 90 DAY)',
        );
    }
}
