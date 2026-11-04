-- Korrektur, Bewertung, Ergebnisse (Stufe 2). Nur additiv (Nummernbereich 140 bis 149).
-- Die Tabellen grading_entries, grading_comments, auto_gradings und participation_results
-- stehen bereits im Grundschema (001_init.sql); hier kommen nur zwei Spalten an exams dazu.

SET NAMES utf8mb4;

-- Erwartungshorizont bei der Rückgabe an Schüler:innen zeigen (Standard: aus).
ALTER TABLE exams ADD COLUMN IF NOT EXISTS show_expectation_on_return TINYINT(1) NOT NULL DEFAULT 0;

-- Gewählter Notenschlüssel dieser Klausur (NULL = Schlüssel des Kurses bzw. Standard der Klassenstufe).
-- Bewusst ohne Fremdschlüssel: Ein gelöschter Schlüssel fällt in der Anwendung auf den Kursschlüssel zurück.
ALTER TABLE exams ADD COLUMN IF NOT EXISTS grading_scale_id INT UNSIGNED NULL;
