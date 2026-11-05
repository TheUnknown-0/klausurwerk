<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Was die Anfrage über sich selbst verrät: Client-IP, Schema, Host.
 *
 * Proxy-Header (X-Forwarded-For, X-Forwarded-Proto) zählen nur, wenn REMOTE_ADDR ein
 * vertrauenswürdiger Proxy ist (TRUSTED_PROXIES, Adressen oder CIDR-Bereiche, kommagetrennt).
 * Der Standard umfasst NUR die Loopback-Adresse. Würden die privaten Bereiche (10/8,
 * 172.16/12, 192.168/16) pauschal gelten, wäre in einem Schulnetz jeder Rechner ein „Proxy“:
 * Er könnte eine beliebige Absenderadresse behaupten (Login-Drossel umgehen, Audit-Log fälschen)
 * oder per X-Forwarded-Proto das Schema verfälschen.
 *
 * Wer hinter einem echten Reverse-Proxy betreibt, trägt dessen Adresse in TRUSTED_PROXIES ein,
 * und nur diese. Diese Datei kommt ohne Autoloader aus (config/config.php lädt sie direkt).
 */
final class Request
{
    public static function remoteAddr(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /** Ist die Adresse (Standard: REMOTE_ADDR) ein vertrauenswürdiger Proxy? */
    public static function isTrustedProxy(?string $ip = null): bool
    {
        $ip ??= self::remoteAddr();
        if ($ip === '') {
            return false;
        }
        $raw = getenv('TRUSTED_PROXIES');
        $cidrs = $raw !== false && $raw !== ''
            ? array_map('trim', explode(',', $raw))
            : ['127.0.0.1', '::1'];

        foreach ($cidrs as $cidr) {
            if ($cidr !== '' && self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ermittelt die Client-IP. X-Forwarded-For wird nur berücksichtigt, wenn
     * REMOTE_ADDR ein vertrauenswürdiger Proxy ist.
     */
    public static function clientIp(): string
    {
        $remote = self::remoteAddr();
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');

        if ($forwarded === '' || !self::isTrustedProxy($remote)) {
            return $remote;
        }

        // Erste IP der Kette = ursprünglicher Client
        $first = trim(explode(',', $forwarded)[0]);

        return filter_var($first, FILTER_VALIDATE_IP) !== false ? $first : $remote;
    }

    /**
     * Kam die Anfrage über HTTPS an? Direkt (HTTPS gesetzt) oder über einen vertrauenswürdigen
     * Proxy, der X-Forwarded-Proto: https mitschickt (TLS endet dann am Proxy).
     */
    public static function isHttps(): bool
    {
        $https = (string) ($_SERVER['HTTPS'] ?? '');
        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        $forwarded = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));

        return $forwarded === 'https' && self::isTrustedProxy();
    }

    public static function scheme(): string
    {
        return self::isHttps() ? 'https' : 'http';
    }

    /** Host samt Port, wie der Browser ihn angesprochen hat. */
    public static function host(): string
    {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

        return $host !== '' ? $host : 'localhost';
    }

    /** Herkunft der Anfrage: Schema plus Host. */
    public static function origin(): string
    {
        return self::scheme() . '://' . self::host();
    }

    public static function ipInCidr(string $ip, string $cidr): bool
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
        if ($remainder > 0 && $bytes < strlen($ipBin)) {
            $mask = 0xFF << (8 - $remainder) & 0xFF;
            if ((ord($ipBin[$bytes]) & $mask) !== (ord($subnetBin[$bytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
