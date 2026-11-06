-- Eingebauter Browser (Nummernbereich 190 bis 199). Nur additiv.
-- Freigegebene Webseiten je Aufgabe (Default deny): Liste aus {url, label, exact}, siehe
-- Services\Web\WebSites. Teilaufgaben erben die Freigaben ihrer Überschrift.

SET NAMES utf8mb4;

ALTER TABLE tasks ADD COLUMN IF NOT EXISTS web_sites JSON NULL;
