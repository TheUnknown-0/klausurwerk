<?php

declare(strict_types=1);

/**
 * Zentrale Konfiguration, liest Umgebungsvariablen mit sinnvollen Defaults.
 */

$env = static fn (string $key, ?string $default = null): ?string => (
    ($v = getenv($key)) !== false && $v !== '' ? $v : $default
);

return [
    'app' => [
        'env' => $env('APP_ENV', 'production'),
        'base_url' => rtrim($env('BASE_URL', '/'), '/'),
        // Secure-Cookie-Flag automatisch bei HTTPS, per Env erzwingbar.
        'secure_cookies' => $env('SECURE_COOKIES') === '1'
            || (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off'),
    ],
    'db' => [
        'host' => $env('DB_HOST', '127.0.0.1'),
        'name' => $env('DB_NAME', 'klausurwerk'),
        'user' => $env('DB_USER', 'klausurwerk'),
        'pass' => $env('DB_PASS', ''),
    ],
    'mail' => [
        'host' => $env('MAIL_HOST', ''),
        'port' => (int) $env('MAIL_PORT', '587'),
        // 'tls' (STARTTLS, meist Port 587), 'ssl' (implizites TLS, meist Port 465) oder 'none'.
        'encryption' => $env('MAIL_ENCRYPTION', 'tls'),
        'username' => $env('MAIL_USER', ''),
        'password' => $env('MAIL_PASS', ''),
        'from_address' => $env('MAIL_FROM_ADDRESS', ''),
        'from_name' => $env('MAIL_FROM_NAME', 'Klausurwerk'),
    ],
    'uploads' => [
        'dir' => dirname(__DIR__) . '/uploads',
        'max_logo_bytes' => 2 * 1024 * 1024,
        'max_document_bytes' => 20 * 1024 * 1024,
    ],
    // Java-Runner (eigener Container, nur im internen Netz erreichbar).
    'runner' => [
        'url' => rtrim($env('RUNNER_URL', 'http://runner:8080'), '/'),
        // Gemeinsames Geheimnis zwischen App und Runner (Header X-Runner-Token).
        'token' => $env('RUNNER_TOKEN', ''),
        'timeout_seconds' => (int) $env('RUNNER_TIMEOUT', '20'),
    ],
];
