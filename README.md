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

## Installation auf dem Server

Auf einem Linux-Server mit Docker genügt ein Befehl:

```bash
curl -fsSL https://raw.githubusercontent.com/TheUnknown-0/klausurwerk/main/install.sh | sudo bash
```

Das Skript fragt nach dem Zielordner, klont Klausurwerk dorthin und startet den Installations-Assistenten.
Der Zielordner darf so angegeben werden:

| Eingabe | Ziel |
|---|---|
| *(leer)* | `/opt/klausurwerk` |
| `/srv/klausurwerk` | absoluter Pfad |
| `klausurwerk`, `./apps/klausurwerk`, `../klausurwerk` | relativ zum Ordner, in dem der Befehl läuft |
| `.` | `./klausurwerk` im aktuellen Ordner |
| `~/docker` | im eigenen Home-Verzeichnis, auch unter `sudo` |

Ist der Ordner schon mit anderen Dingen belegt (etwa `~/docker` mit weiteren Projekten), landet
Klausurwerk im Unterordner `klausurwerk`.

Wer das Skript vorher lesen möchte, klont selbst und startet es im Ordner:

```bash
git clone https://github.com/TheUnknown-0/klausurwerk.git /opt/klausurwerk
cd /opt/klausurwerk
sudo ./install.sh
```

Der Assistent prüft Docker, fragt öffentliche Adresse, Reverse-Proxy, Port, Images, Mailserver und
das erste Admin-Konto ab, schreibt `.env` mit zufälligen Passwörtern, setzt `TRUSTED_PROXIES`,
startet die Container und legt den Admin an. Damit ist die Einrichtung im Browser gesperrt, bevor
die Domain öffentlich erreichbar ist. Zum Schluss zeigt er, was am Reverse-Proxy noch fehlt.

Passen die Passwörter in `.env` nicht zu einer Datenbank aus einer früheren Installation, erkennt der
Assistent das und bietet an, die alten Daten zu löschen (nur nach Eintippen von „löschen“).

### Update

```bash
cd /opt/klausurwerk && sudo git pull && sudo docker compose pull && sudo docker compose up -d
```

Ohne fertige Images (lokal gebaut) statt der letzten beiden Befehle `sudo docker compose up -d --build`.
Vorher die Datenbank sichern und nicht während einer Klausur aktualisieren.

## Schnellstart von Hand (Docker)

```bash
cp .env.example .env      # Passwörter und RUNNER_TOKEN setzen!
docker network create proxy_network   # falls der Reverse-Proxy es noch nicht anlegt
docker compose up -d --build
docker compose exec app php bin/setup.php   # erstes Admin-Konto
```

Anwendung: `http://localhost:9030`. Ohne `bin/setup.php` führt beim ersten Aufruf ein
**Setup-Assistent** im Browser durch Admin-Konto und Schulname.

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
| `TRUSTED_PROXIES` | *(leer)* | Adressen oder CIDR-Bereiche der eigenen Reverse-Proxys. Nur von dort gelten `X-Forwarded-For` (Client-IP) und `X-Forwarded-Proto` (HTTPS für Cookies, QR-Codes und SEB-Prüfung). **Hinter einem Proxy Pflicht.** |
| `SECURE_COOKIES` | *(leer)* | `1` erzwingt das Secure-Flag der Cookies; nötig nur, wenn HTTPS am Proxy endet und `TRUSTED_PROXIES` nicht passt |
| `LIVE_DIR_SIZE` | `256m` | tmpfs der Live-Wand (je Raum mit Bildschirmfreigabe etwa 25 MB) |
| `RUNNER_MAX_PARALLEL` | `4` | gleichzeitige Java-Läufe, weitere warten |
| `RUNNER_MAX_SECONDS` | `10` | CPU-Zeit je Lauf |
| `RUNNER_MAX_MEMORY_MB` | `256` | Heap je Java-Lauf |
| `RUNNER_MEM_LIMIT` / `RUNNER_CPUS` | `2g` / `2` | Grenzen des Runner-Containers |
| `RUNNER_TIMEOUT` | `20` | Wartezeit der App auf Runner-Antworten in Sekunden |
| `DB_WAIT_TIMEOUT` | `120` | Wartezeit auf die Datenbank beim Start in Sekunden |
| `MAIL_*` | *(leer)* | SMTP für Anmeldecodes; leer = Codes abgeschaltet |

**Mindestanforderungen:** 2 CPU-Kerne und 4 GB RAM für einen Raum mit etwa 35 Schüler:innen.
Für mehrere Räume gleichzeitig `RUNNER_CPUS`, `RUNNER_MEM_LIMIT` und `RUNNER_MAX_PARALLEL` erhöhen;
bei Bildschirmfreigabe in vielen Räumen auch `LIVE_DIR_SIZE`.

**Hinter einem Reverse-Proxy mit HTTPS:** `TRUSTED_PROXIES` auf die Adresse oder das Netz des Proxys
setzen (bei Docker z. B. das Subnetz von `proxy_network`). Ohne diesen Eintrag sieht die App nur
HTTP: Cookies gehen ohne Secure-Flag raus, und QR-Codes sowie SEB-Dateien bekommen falsche Adressen,
solange in den Einstellungen keine öffentliche Adresse hinterlegt ist.

## Betrieb

| Endpunkt | Bedeutung |
|---|---|
| `/healthz` | Die Anwendung antwortet. |
| `/readyz` | Vollständig bereit inkl. Datenbank (`200` / `503`). |

Migrationen laufen beim Container-Start, abgesichert über eine Datenbanksperre.
Alle Container haben Healthchecks und `restart: unless-stopped`. Das Dashboard zeigt Admins,
wenn der Runner nicht erreichbar ist, der Mailserver fehlt oder Aufbewahrungsfristen überschritten sind.

Verwaiste Dateien unter `uploads/` (ohne Datenbankzeile, z. B. nach Datenbankimporten) findet
`docker compose exec app php bin/cleanup.php`; mit `--delete` werden sie entfernt.

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
