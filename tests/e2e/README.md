# Ende-zu-Ende-Tests (Playwright, Python)

Laufen im echten Browser (Chromium) gegen den Compose-Stack.

```bash
pip install pytest playwright && python -m playwright install chromium   # einmalig
docker compose -f compose.yaml -f compose.dev.yaml up -d               # Stack mit eingeblendetem Code
(cd frontend && node build.mjs)                                        # Bundles für den eingeblendeten Code
tests/e2e/reset.sh                                                     # ACHTUNG: löscht die Datenbank, spielt Demo-Daten ein
cd tests/e2e && python -m pytest -q
```

| Datei | Inhalt |
|---|---|
| `test_klausur_ablauf.py` | Warteraum, Beitritt, Start, Autosave, Verstoß mit Sperre, Freigabe, Zusatzzeit, Durchsage, Abgabe-Anfrage und Bestätigung, Klausur beenden |
| `test_code.py` | Python im Browser mit interaktivem `input()`, Java über den Runner, SQL mit Ergebnistabelle |
| `test_seiten.py` | alle wichtigen Seiten je Rolle ohne Serverfehler und ohne JS-Fehler; Sperren für Schüler:innen |
| `test_sicherheit.py` | keine Lösungen in Schüler-APIs, gesperrte Lehrkräfte-APIs, Aufgaben erst ab Start, CSRF, QR-Login |
| `test_live.py` | Live-Wand: Einstellungen, Beitritt mit Bildschirmfreigabe (Fake-Bildschirmquelle), Code-Ausschnitt und Bild im Pult, Fokus, Alarm ohne Sperre, Rechte, Aufräumen am Klausurende; Link zur Korrektur im Pult; Enter in der letzten Korrekturzelle speichert |
| `test_loeschen.py` | Löschen per Entf/Rücktaste: Aufgabenliste mit Rückfrage, Formularlisten, Diagramm-Elemente, Rücktaste im Textfeld |

Umgebungsvariablen: `KW_BASE` (Standard `http://localhost:9030`), `KW_HEADED=1` (sichtbarer Browser),
`KW_SHOTS` (Ordner für Bildschirmfotos, Standard `tests/e2e/shots`).
