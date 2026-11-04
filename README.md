# Gartenarbeitstag

Webanwendung zur Organisation eines schulischen Gartenarbeitstags: Stände mit Zeitblöcken und
Kapazitäten, Einschreibung der Schüler:innen (Sofortbuchung mit Warteliste **oder**
Wunschliste mit automatischer Zuteilung), konfigurierbare Limits je Stand (Klasse, Stufe,
Kapazität), Ausschlusskriterien, Anwesenheitslisten, PDF-/CSV-Berichte, granulares
Rechtesystem und Audit-Log.

## Features

| Bereich | Funktion |
|---|---|
| **Aktionstage** | Mehrere Tage (Entwurf / aktiv / archiviert), Datum, Anmeldefenster, Modus, Mindest-/Höchstzahl Blöcke je Schüler:in, Klonen des Vorjahres |
| **Zeitblöcke** | Beliebig viele Blöcke je Tag; ein Stand kann in mehreren Blöcken angeboten werden; eine Person kann pro Block nur an einem Stand sein (datenbankseitig erzwungen) |
| **Stände** | Beschreibung, Ort, Material, Kapazität je Block, max. X aus derselben Klasse / Stufe, erlaubte Stufen/Klassen, Mindestbesetzung (Warnung), Standleitungen |
| **Ausschlusskriterien** | Von Admins anlegbar, von Orga/Lehrkräften Schüler:innen zugewiesen (Einzeln oder CSV-Import); Stände mit passendem Kriterium sind für die Person **hart gesperrt** — nie übersteuerbar |
| **Einschreibung** | Modus *Sofortbuchung* (+ Warteliste mit automatischem Nachrücken) oder *Wunschliste* (Prio 1–n, automatische Zuteilung mit Probelauf, fair und reproduzierbar) |
| **Orga-Eingriffe** | Einschreibungen anlegen, umbuchen, löschen; weiche Limits (Kapazität, Klassen-/Stufenlimit) nur mit Recht, Bestätigung und Begründung übersteuerbar — jede Übersteuerung im Audit-Log |
| **Lehrkräfte / Standleitung** | Klassenübersicht, eigene Stände mit Abhak-Liste für Anwesenheit, eigene Teilnehmerlisten pflegen (per Berechtigung) |
| **Berichte** | PDF: Standlisten mit Abhak-Kästchen, Klassenlisten, Belegungsübersicht, persönlicher Plan, Zugangsdaten; CSV/XLSX-Export |
| **Verwaltung** | Benutzer (CSV-Import mit Klasse, Stufe, Kriterien), Rechte & Gruppen, Einstellungen (Schule, Logo, Seitenpasswort), Darstellung (Theme, Navigation, Seitenblöcke), Audit-Log |

## Schnellstart (Docker)

```bash
cp .env.example .env      # Passwörter setzen!
docker compose up -d --build
```

Anwendung: `http://localhost:9020` — beim ersten Aufruf führt ein **Setup-Assistent** durch
Admin-Konto, Schulname und ersten Aktionstag.

phpMyAdmin (nur bei Bedarf): `docker compose --profile tools up -d` → Port 8091.

> Die Compose-Datei bindet die App zusätzlich an das externe Netz `proxy_network`
> (Reverse-Proxy). Existiert es nicht: `docker network create proxy_network`.

## Umgebungsvariablen

| Variable | Standard | Beschreibung |
|---|---|---|
| `DB_HOST` / `DB_USER` / `DB_NAME` | `db` / `gartenarbeitstag` / `gartenarbeitstag` | Datenbankverbindung |
| `DB_PASS` / `DB_ROOT_PASS` | *(leer)* | **Pflicht** — sichere Passwörter setzen |
| `APP_PORT` | `9020` | Host-Port |
| `APP_ENV` | `production` | `development` zeigt Fehlerdetails |
| `BASE_URL` | `/` | Basis-Pfad bei Betrieb im Unterverzeichnis |
| `TRUSTED_PROXIES` | *(leer)* | Adressen der eigenen Reverse-Proxys für `X-Forwarded-For` — nie ganze Netze |
| `DB_WAIT_TIMEOUT` | `120` | Sekunden, die der Start auf die Datenbank wartet |
| `PMA_PORT` | `8091` | Port für phpMyAdmin (Profil `tools`) |

## Rollen & Rechte

| Rolle | Beschreibung |
|---|---|
| `admin` | Alle Rechte; legt Ausschlusskriterien an, verwaltet Darstellung |
| `orga` | Granulare Rechte (z. B. Stände, Einschreibungen, Übersteuern, Zuteilung, Benutzer) |
| `teacher` | Granulare Rechte; Standard: Anwesenheit sehen/bearbeiten, Berichte sehen/drucken; als Standleitung eigene Stände |
| `student` | Eigene Einschreibung / Wünsche, Stände ansehen, eigener Plan |

Der komplette Rechtekatalog steht in `src/Core/Permissions.php` und wird unter
*Verwaltung → Berechtigungen* mit Abhängigkeitslogik und Gruppen vergeben.

## Fachliche Regeln (Kurzfassung)

- **Hart, nie übersteuerbar:** Ausschlusskriterium, zwei feste Einschreibungen im selben
  Zeitblock, Duplikat, deaktivierter Stand.
- **Weich, nur mit Recht + Bestätigung + Begründung:** Kapazität, max. X aus Klasse/Stufe,
  erlaubte Stufen/Klassen, Höchstzahl Blöcke.
- **Warnung:** Mindestbesetzung eines Standes.
- Details: [ARCHITECTURE.md](ARCHITECTURE.md), Abschnitt „Fachregeln“.

## Tests

Die Integrationstests brauchen eine eigene Datenbank `gartenarbeitstag_test` (wird bei
ausreichenden Rechten automatisch angelegt). Mit laufendem Compose-Stack:

```bash
docker run --rm --network gartenarbeitstag_default \
  -v "$PWD:/app" -w /app \
  -e TEST_DB_HOST=db -e TEST_DB_USER=root -e TEST_DB_PASS="$DB_ROOT_PASS" \
  gartenarbeitstag-test php tools/phpunit.phar
```

(Das Image `gartenarbeitstag-test` ist `php:8.3-cli` mit `pdo_mysql`; siehe `tests/README.md`.)

## Betrieb

Beide Container starten mit `restart: unless-stopped` und haben Healthchecks.

| Endpunkt | Bedeutung |
|---|---|
| `/healthz` | Die Anwendung antwortet (auch bei Datenbankausfall `ok`). |
| `/readyz` | Vollständig bedienbereit inkl. Datenbank (`200` / `503`). Für Monitoring. |

Migrationen laufen beim Container-Start, abgesichert über eine Datenbanksperre.

## Architektur

Siehe [ARCHITECTURE.md](ARCHITECTURE.md).
