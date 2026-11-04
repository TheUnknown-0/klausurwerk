-- Verwaltung: Index für den Import-Rückbau und Standardwerte der Einstellungen.
-- Rein additiv, mehrfach ausführbar.

SET NAMES utf8mb4;

ALTER TABLE users ADD INDEX IF NOT EXISTS idx_users_created_via (created_via, role);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('heartbeat_ms', '2000'),
    ('pult_poll_ms', '1500'),
    ('retention_answers_months', '24'),
    ('retention_logs_months', '6');
