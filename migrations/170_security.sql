-- Sicherheitsnachbesserungen (Nummernbereich 170 bis 179). Nur additiv.

SET NAMES utf8mb4;

-- SEB: Browser Exam Key (BEK, 64 Hex-Zeichen). Anders als der Config Key lässt er sich nicht aus der
-- .seb-Datei berechnen. Leer = nur der Config Key wird geprüft (schützt dann nur gegen Versehen).
ALTER TABLE exams ADD COLUMN IF NOT EXISTS seb_browser_exam_key CHAR(64) NULL;
