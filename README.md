# Klausurwerk

Webanwendung für Informatik-Klausuren in den Klassen 10 bis 12: Programmieren in **Java**,
**Python** und **SQL**, dazu Freitext-, Auswahl-, Lücken- und Zeichenaufgaben (UML,
Struktogramm, Logikschaltungen, Automaten, ER-Modelle) und eine eigene Gitterwelt mit „Robo“.
Lehrkräfte überwachen die Klausur live im **Pult**, Schüler:innen arbeiten im Vollbild.

## Funktionen

| Bereich | Funktion |
|---|---|
| **Klausuren** | Modi Klausur, Übung und Probe (Technik-Check); Aufgaben mit Teilaufgaben, Punkten, Operatoren (AFB I bis III), Markdown mit Formeln, Bildern und Code; Material und Hilfsmittel; Archivsuche und Kopieren früherer Aufgaben; Export/Import |
| **Umgebung** | pro Klausur und Aufgabe: Sprache und Java-Version, Ausführen / nur Syntaxprüfung / nur Schreiben, Editor-Komfort, Hilfsmittel (Java-API, Python-Doku, SQL-Spickzettel, Operatoren, Taschenrechner), vorgegebene Dateien, numpy/matplotlib zuschaltbar |
| **Ausführung** | Python (Pyodide, interaktives `input()`, Turtle) und SQL (SQLite) im Browser; Java 21/25 abgeschottet im Runner-Container |
| **Überwachung** | Vollbild, Fokus, Tabwechsel, Einfügen von außen, zweiter Tab, Verbindungsabbruch; Alarm mit Ton bei Schüler:in und im Pult, Sperre bis zur Freigabe; Safe Exam Browser optional mit Config-Key-Prüfung und erzeugter `.seb`-Datei |
| **Pult** | Kacheln als Sitzplan, Zustände live, Mitlesen, Replay, Zeit verlängern, Pause, Toilette, Durchsagen, Hand heben, Abgabe bestätigen, Gerätewechsel freigeben |
| **Zeit** | Start durch die Lehrkraft, Nachteilsausgleich je Person, Zeitende nur als Signal: abgegeben wird erst, wenn die Lehrkraft es bestätigt |
| **Ausfallsicherheit** | Autosave mit lokaler Sicherung (IndexedDB), Nachsenden nach Verbindungsabbruch, Anzeige im Pult, ob der letzte Stand vollständig angekommen ist |
| **Korrektur** | Erwartungshorizont daneben, Zeilenkommentare, Punktvorschläge aus Tests und Auswahlaufgaben, Zweitkorrektur, Notenschlüssel je Klassenstufe (Noten 1 bis 6 bzw. Punkte 0 bis 15), Klausurspiegel, PDF, digitale Rückgabe |
| **Verwaltung** | Lehrkräfte, Kurse, Schüler:innen (CSV-Import), Zugangskarten als PDF mit QR-Code, Räume, Operatorenliste, Rechte und Gruppen, Aufbewahrungsfristen, Audit-Log |
| **Anmeldung** | Schüler:innen mit fester Zugangskarte; Lehrkräfte mit Passwort und Code per E-Mail |

## Was technisch geht und was nicht

- **Lösungen sind sicher:** Musterlösung, Erwartungshorizont und versteckte Tests liegen in eigenen
  Tabellen und verlassen den Server nie in Richtung Schüler:innen.
- **Quellcode der Oberfläche:** Im normalen Browser kann man ihn mit F12 lesen. Klausurwerk
  verschleiert die Schüler-Bundles, fängt Tastenkürzel ab und meldet einen DevTools-Verdacht.
  Wirklich gesperrt ist das nur im Safe Exam Browser.
- **Nicht erkennbar:** Handy, zweiter Rechner, Zettel. Dafür ist die Aufsicht im Raum zuständig.

## Schnellstart (Docker)

```bash
cp .env.example .env      # Passwörter und RUNNER_TOKEN setzen!
docker network create proxy_network   # falls der Reverse-Proxy es noch nicht anlegt
docker compose up -d --build
```

Anwendung: `http://localhost:9030`. Beim ersten Aufruf führt ein **Setup-Assistent** durch
Admin-Konto und Schulname.

Fertige Images aus der GitHub Container Registry statt lokal bauen: in `.env`
`APP_IMAGE=ghcr.io/theunknown-0/klausurwerk-app:latest` und
`RUNNER_IMAGE=ghcr.io/theunknown-0/klausurwerk-runner:latest` setzen, dann
`docker compose pull && docker compose up -d`.

## Umgebungsvariablen

| Variable | Standard | Beschreibung |
|---|---|---|
| `DB_PASS` / `DB_ROOT_PASS` | *(leer)* | **Pflicht** |
| `RUNNER_TOKEN` | *(leer)* | **Pflicht**, gemeinsames Geheimnis App/Runner (`openssl rand -hex 32`) |
| `APP_PORT` | `9030` | Host-Port |
| `APP_ENV` | `production` | `development` zeigt Fehlerdetails |
| `BASE_URL` | `/` | Basis-Pfad bei Betrieb im Unterverzeichnis |
| `TRUSTED_PROXIES` | *(leer)* | Adressen der eigenen Reverse-Proxys |
| `RUNNER_MAX_PARALLEL` | `4` | gleichzeitige Java-Läufe, weitere warten |
| `RUNNER_MAX_SECONDS` | `10` | CPU-Zeit je Lauf |
| `RUNNER_MAX_MEMORY_MB` | `256` | Heap je Java-Lauf |
| `RUNNER_MEM_LIMIT` / `RUNNER_CPUS` | `2g` / `2` | Grenzen des Runner-Containers |
| `MAIL_*` | *(leer)* | SMTP für Anmeldecodes; leer = Codes abgeschaltet |

**Mindestanforderungen:** 2 CPU-Kerne und 4 GB RAM für einen Raum mit etwa 35 Schüler:innen.
Für mehrere Räume gleichzeitig `RUNNER_CPUS`, `RUNNER_MEM_LIMIT` und `RUNNER_MAX_PARALLEL` erhöhen.

## Betrieb

| Endpunkt | Bedeutung |
|---|---|
| `/healthz` | Die Anwendung antwortet. |
| `/readyz` | Vollständig bereit inkl. Datenbank (`200` / `503`). |

Migrationen laufen beim Container-Start, abgesichert über eine Datenbanksperre.
Alle Container haben Healthchecks und `restart: unless-stopped`.

## Entwicklung und Tests

Siehe [tests/README.md](tests/README.md). Kurz:

```bash
tools/dev.sh lint      # PHP-Syntax
tools/dev.sh unit      # Unit-Tests
tools/dev.sh test      # Unit + Integration (startet eine eigene Test-Datenbank)
cd frontend && npm ci && npm test && node build.mjs
```

## Architektur

[ARCHITECTURE.md](ARCHITECTURE.md), Schnittstellen in [docs/API.md](docs/API.md), Datenformate in
[docs/DATENFORMATE.md](docs/DATENFORMATE.md), fachliche Entscheidungen in [KONZEPT.md](KONZEPT.md).
