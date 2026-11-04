# Tests

| Ebene | Ort | Läuft gegen |
|---|---|---|
| Unit (PHP) | `tests/Unit/` | nichts (keine Datenbank) |
| Integration (PHP) | `tests/Integration/` | eigene Test-Datenbank `klausurwerk_test` |
| Frontend-Logik | `frontend/test/*.test.js` | Node (`node --test`) |
| Runner | `runner/tests/` | einzeln gestarteter Runner-Container |
| Ende-zu-Ende | `tests/e2e/` | laufender Compose-Stack (Playwright) |

## PHP

Nur Docker ist nötig; `tools/dev.sh` baut das Testimage (`tests/Dockerfile`, php:8.3-cli mit
pdo_mysql, gd, zip) und startet bei Bedarf die Test-Datenbank (Container `klausurwerk-testdb`).

```bash
tools/dev.sh lint             # Syntaxprüfung aller PHP-Dateien
tools/dev.sh unit             # nur Unit-Tests
tools/dev.sh test             # alles
tools/dev.sh test ExamClock   # nur Tests, deren Name passt
TEST_DB_NAME=klausurwerk_test_x tools/dev.sh test   # eigene Test-DB (parallel arbeiten)
```

Jeder Integrationstest läuft in einer Transaktion, die am Ende zurückgerollt wird. Das Schema
wird einmal je Lauf aus `migrations/` aufgebaut.

PHPUnit liegt als PHAR unter `tools/phpunit.phar` (nicht im Repo):

```bash
curl -L -o tools/phpunit.phar https://phar.phpunit.de/phpunit-11.phar
```

## Frontend

```bash
cd frontend
npm ci
npm test            # Logik-Tests
node build.mjs      # Bundles + Laufzeiten nach public/assets/build und public/assets/vendor
```

## Runner

Siehe `runner/README.md`.

## Ende-zu-Ende

Siehe `tests/e2e/README.md`.
