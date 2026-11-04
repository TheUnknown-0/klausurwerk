-- Live-Betrieb: Verbindungsstatus, Fortschrittszähler, Lauf-Sitzungen, Ratenbegrenzung.
-- Nur additiv (Nummernbereich 130 bis 139).

SET NAMES utf8mb4;

-- Server hat für diese Person "Verbindung verloren" gemeldet (einmalig, bis der Herzschlag zurückkehrt).
ALTER TABLE participations ADD COLUMN IF NOT EXISTS offline_reported_at DATETIME(3) NULL;
-- Zähler für das Pult (werden beim Speichern fortgeschrieben, damit der Pult-Abruf keine Antworten lesen muss).
ALTER TABLE participations ADD COLUMN IF NOT EXISTS answered_count SMALLINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE participations ADD COLUMN IF NOT EXISTS flagged_count SMALLINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE participations ADD COLUMN IF NOT EXISTS last_save_at DATETIME(3) NULL;

-- Java-Läufe: run_id gehört genau einer Person (Abruf fremder run_id ergibt 404).
CREATE TABLE IF NOT EXISTS run_sessions (
    run_id VARCHAR(80) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    exam_id INT UNSIGNED NULL,
    task_id INT UNSIGNED NULL,
    started_at DATETIME(3) NOT NULL,
    last_poll_at DATETIME(3) NULL,
    finished_at DATETIME(3) NULL,
    PRIMARY KEY (run_id),
    KEY idx_run_sessions_user (user_id, started_at),
    CONSTRAINT fk_run_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Einfache Ratenbegrenzung (Treffer je Eimer und Person), z. B. Lauf-Starts, Klausurcode-Versuche.
CREATE TABLE IF NOT EXISTS live_rate_hits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket VARCHAR(60) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    hit_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_live_rate (bucket, user_id, hit_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
