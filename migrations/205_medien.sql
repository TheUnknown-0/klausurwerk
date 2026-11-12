-- Audio und Video als Material (Hörverstehen, Hör-Seh-Verstehen). Nur additiv.
-- play_policy: 'teacher' = nur die Lehrkraft spielt ab (Pult, zentral im Raum),
--              'free' = Schüler:innen beliebig oft, 'limited' = höchstens play_limit-mal.
-- Für Bilder und PDF ohne Bedeutung.

SET NAMES utf8mb4;

ALTER TABLE exam_materials ADD COLUMN IF NOT EXISTS play_policy VARCHAR(10) NOT NULL DEFAULT 'teacher';
ALTER TABLE exam_materials ADD COLUMN IF NOT EXISTS play_limit TINYINT UNSIGNED NOT NULL DEFAULT 2;

-- Abspielzähler je Teilnahme. token_hash: Einmal-Schlüssel für den nächsten Abruf der Datei
-- (bei 'limited'), wird beim Abruf verbraucht.
CREATE TABLE IF NOT EXISTS material_plays (
    participation_id INT UNSIGNED NOT NULL,
    material_id INT UNSIGNED NOT NULL,
    plays SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    token_hash CHAR(64) NULL,
    token_expires_at DATETIME NULL,
    last_played_at DATETIME NULL,
    PRIMARY KEY (participation_id, material_id),
    KEY idx_mp_material (material_id),
    CONSTRAINT fk_mp_part FOREIGN KEY (participation_id) REFERENCES participations(id) ON DELETE CASCADE,
    CONSTRAINT fk_mp_material FOREIGN KEY (material_id) REFERENCES exam_materials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
