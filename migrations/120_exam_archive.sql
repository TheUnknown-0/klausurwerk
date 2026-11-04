-- Klausuren und Aufgaben: Freigabe im Archiv fuer Kolleg:innen.
SET NAMES utf8mb4;

ALTER TABLE exams
    ADD COLUMN IF NOT EXISTS shared_in_archive TINYINT(1) NOT NULL DEFAULT 0 AFTER copied_from_exam_id;
