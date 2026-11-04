-- Korrektur, Bewertung, Ergebnisse (Stufe 2). Nur additiv (Nummernbereich 140 bis 149).

SET NAMES utf8mb4;

-- Erwartungshorizont bei der Rückgabe an Schüler:innen zeigen (Standard: aus).
ALTER TABLE exams ADD COLUMN IF NOT EXISTS show_expectation_on_return TINYINT(1) NOT NULL DEFAULT 0;

-- Gewählter Notenschlüssel dieser Klausur (NULL = Schlüssel des Kurses bzw. Standard der Klassenstufe).
ALTER TABLE exams ADD COLUMN IF NOT EXISTS grading_scale_id INT UNSIGNED NULL;

-- Sucht pro Klausur schnell die Kommentare einer Lehrkraft.
ALTER TABLE grading_comments ADD INDEX IF NOT EXISTS idx_gc_grader (grader_id);

-- Auswertungen laufen über Klausur und Teilnahme; Index für das Summieren je Teilnahme.
ALTER TABLE grading_entries ADD INDEX IF NOT EXISTS idx_ge_task (task_id);
