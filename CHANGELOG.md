# Änderungen

Jede Version beginnt mit einer Zeile `## X.Y.Z (JJJJ-MM-TT)`, die oberste ist die aktuelle.
Klausurwerk liest diese Datei für die Versionsnummer, den Änderungsverlauf im Admin-Dialog und
die Update-Prüfung (siehe ARCHITECTURE.md, Abschnitt Versionen). Einträge kurz und für Admins
verständlich halten.

## 1.3.0 (2026-11-06)

- Versionsnummer unten in der Seitenleiste, für alle sichtbar. Admins öffnen per Klick diesen Änderungsverlauf.
- Update-Hinweis für Admins: Klausurwerk prüft bei jeder Anmeldung eines Admins und einmal täglich, ob es eine neue Version gibt, höchstens einmal pro Minute. Im Änderungsverlauf sucht ein Knopf sofort. Abschaltbar unter Einstellungen.
- Der Updater (`./update.sh`) nennt alte und neue Versionsnummer.

## 1.2.0 (2026-11-06)

- Updater im Installations-Assistenten: `sudo ./update.sh` sichert Datenbank und Uploads, holt den neuen Stand und startet neu.
- Vorschau je Frage direkt aus dem Aufgaben-Editor, Knopf „Vorschau beenden“.
- KI-Assistent: Lehrkräfte verbinden Claude, ChatGPT und andere KI-Programme über MCP mit Klausurwerk (Freischaltung durch Admins).
- Eingebauter Browser: freigegebene Webseiten je Aufgabe, alles andere bleibt gesperrt.

## 1.1.0 (2026-11-05)

- Installations-Assistent für den Server (`install.sh`), auch als Einzeiler.
- Klausuren einfacher erstellen: automatisches Speichern, Typ-Kacheln, Profile und Vorlagen.
- Fragenliste im Aufgaben-Editor im Stil von Google Forms.

## 1.0.0 (2026-11-04)

- Erste vollständige Fassung: Klausuren mit Java, Python und SQL, Lehrer-Pult mit Live-Wand, Überwachung, Safe Exam Browser, Korrektur und Rückgabe, Diagramme, Handbuch.
