-- KI-Zugang über MCP mit OAuth 2.1 (Nummernbereich 180 bis 189). Nur additiv.
-- Schlüssel und Codes liegen nur als SHA-256-Hash in der Datenbank.

SET NAMES utf8mb4;

-- Per Dynamic Client Registration (RFC 7591) angemeldete KI-Programme (öffentliche Clients mit PKCE).
CREATE TABLE IF NOT EXISTS oauth_clients (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id VARCHAR(64) NOT NULL,
    client_name VARCHAR(200) NOT NULL DEFAULT '',
    redirect_uris JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oauth_clients_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Autorisierungscodes: einmalig, 10 Minuten gültig, an PKCE-Challenge und Redirect gebunden.
CREATE TABLE IF NOT EXISTS oauth_codes (
    code_hash CHAR(64) NOT NULL,
    client_id VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    redirect_uri VARCHAR(2000) NOT NULL,
    code_challenge VARCHAR(128) NOT NULL,
    scope VARCHAR(200) NOT NULL DEFAULT '',
    resource VARCHAR(500) NOT NULL DEFAULT '',
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (code_hash),
    KEY idx_oauth_codes_expires (expires_at),
    CONSTRAINT fk_oauth_codes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zugänge (Grants): ein Eintrag je Verbindung Lehrkraft und KI-Programm. Zugriffsschlüssel kurz
-- gültig, Erneuerungsschlüssel rotiert bei jeder Erneuerung. Widerruf setzt revoked_at.
CREATE TABLE IF NOT EXISTS oauth_grants (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    client_id VARCHAR(64) NOT NULL,
    scope VARCHAR(200) NOT NULL DEFAULT '',
    resource VARCHAR(500) NOT NULL DEFAULT '',
    access_hash CHAR(64) NULL,
    access_expires_at DATETIME NULL,
    refresh_hash CHAR(64) NULL,
    refresh_expires_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL,
    revoked_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oauth_grants_access (access_hash),
    UNIQUE KEY uq_oauth_grants_refresh (refresh_hash),
    KEY idx_oauth_grants_user (user_id),
    CONSTRAINT fk_oauth_grants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
