# Stufe 4: Robo, Java-GUI und Replay

Anleitung und technische Übersicht aus Sicht von Schüler:innen, Lehrkräften und Admins.
Grundlage für die Gesamtanleitung des Programms.

## Schüler:innen

**Robo-Aufgaben.** Links oder oben siehst du die Welt (Robo = blauer Kreis mit Pfeil, Wände dunkel,
Kisten braun, Schrauben grau mit Zahl, Ziele als Flagge), darunter den Editor. Mit „Ausführen“
(Strg+Enter) läuft dein Programm; Robo bewegt sich danach in der Welt.

* Python: `from robo import *`, Java: `Robo.vor()` usw. Befehle: `vor`, `links`, `rechts`, `aufheben`,
  `ablegen`, `schieben`. Fragen: `vorneFrei`, `vorneWand`, `vorneKiste`, `aufSchraube`, `aufZiel`,
  `schraubenImKorb`, `schraubenHier`, `richtung` (`'N'`, `'O'`, `'S'`, `'W'`).
* Gegen eine Wand oder den Rand laufen, ohne Schraube aufheben oder bei leerem Korb ablegen beendet das
  Programm mit einer Meldung (Robo ist kaputt). Nach 10000 Befehlen stoppt Robo (Endlosschleife).
* Mit `schieben()` schiebt Robo die Kiste vor sich ein Feld weiter, wenn dahinter alles frei ist, und rückt nach.
* Unter der Welt: Tempo-Regler (bis „sofort“), Pause/Weiter, ein Schritt, zurück zum Start, „Nochmal“.

**Java-Fenster.** Bei Aufgaben mit grafischer Oberfläche erscheint der Reiter „Fenster“. Klicks und
Eingaben im Fenster gehen an dein Programm. Anleitung: `runner/libs/java/kw/gui/README.md`.

## Lehrkräfte

* **Robo-Aufgabe anlegen:** Antworttyp „Robo (Gitterwelt)“. Sprache, Welt per Klick (Werkzeuge Robo, Wand, Kiste,
  Schraube mit Anzahl, Ziel, Radierer; Größe 2 bis 30), Zieltext, Startcode.
* **Testwelten:** Jede Testwelt hat eigene Welt, Punkte und Bedingungen (alle oder mindestens n Schrauben im Korb,
  Robo auf Feld x|y oder auf einem Ziel, Blickrichtung, Kisten auf bestimmten Feldern oder auf Zielen). Sie laufen
  in der Korrektur im Runner mit denselben Bibliotheken (`POST /robo`), Schüler:innen sehen sie nie.
* **Java-GUI-Aufgabe:** Bei einer Java-Code-Aufgabe den Haken „grafische Oberfläche“ setzen.
* **Replay:** Im Pult bei einer Person und Aufgabe „Replay“. Zeitleiste, Abspielen 1x/4x/16x, rote Marken für
  Einfügungen, gelbe für Überziehung, Pausen über einer Minute werden verkürzt. Fehlen Schritte, weist das Replay
  darauf hin und setzt beim nächsten Schnappschuss wieder auf.

## Admins

* Neuer Runner-Stand nötig (Image neu bauen): Route `POST /robo`, Bibliotheken `Robo.java`, `RoboFehler.java`,
  `kw/gui/*.java`, `robo.py`. Die Java-Bibliotheken liegen als Quelltext im Klassenpfad und werden bei jedem
  Übersetzen mitkompiliert (kostet etwa eine Sekunde); das Vorübersetzen im Dockerfile wäre schneller.
* Keine neuen Datenbanktabellen. Frontend: `npm run build` (Bundles exam, pult, task-editor, worker-python).

## Technik

| Thema | Ort |
|---|---|
| Regeln der Welt | `frontend/src/robo/world.js`, `runner/libs/python/robo.py`, `runner/libs/java/Robo.java`, `src/Services/Robo/RoboWorld.php` (vier Umsetzungen, gemeinsame Testfälle in `runner/tests/robo_vectors.json`) |
| Python-Modul im Browser | `frontend/src/robo/python-source.js` ist generiert: `node scripts/sync-robo.mjs` |
| Zugprotokoll | Zeichen `v l r a b s`, Fehler `!Text`. Java/CPython: stdout-Zeilen mit Präfix U+0001 `KWR:`; Pyodide: `_kw.turtle([{robo: code}])`. Welt: Datei `robo_world.json` (Python, Runner) bzw. erste Eingabezeile `@KWROBO {json}` (Java im Browser) |
| Nachrechnen | Der Runner vertraut dem Protokoll nicht, er spielt es in `robo.py` nach (`kwrunner/modules/robo.py`) |
| `POST /robo` | Anfrage wie `docs/API.md` 5, zusätzlich `goal.robo_on_target`, `goal.boxes_on_targets`, `limits`; Antwort zusätzlich `steps`, `goal_results`, `stdout`, `stderr` |
| GUI | `runner/kwrunner/modules/_guifilter.py` (gui_frames), `frontend/src/gui/*` |
| Replay | `frontend/src/replay/{player,reconstruct,timeline}.js` |
