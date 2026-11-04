# Tests

- `Unit/` — ohne Datenbank (Rechtekatalog, Routen-Guards über statische Analyse der Controller).
- `Integration/` — gegen eine eigene Datenbank `gartenarbeitstag_test`. Das Schema wird pro
  Testlauf aus `migrations/` aufgebaut; jeder Test läuft in einer Transaktion, die am Ende
  zurückgerollt wird.

## Ausführen (Docker)

```bash
# einmalig: Test-Image bauen
docker build -t gartenarbeitstag-test tests

# Stack (mindestens die Datenbank) muss laufen
docker compose up -d db

docker run --rm --network gartenarbeitstag_default \
  -v "D:/Schule/StuB/BSO/WebApp/gartenarbeitstag:/app" -w /app \
  -e TEST_DB_HOST=db -e TEST_DB_USER=root -e TEST_DB_PASS="<DB_ROOT_PASS aus .env>" \
  gartenarbeitstag-test php tools/phpunit.phar
```

Nur Unit-Tests (ohne DB): `… php tools/phpunit.phar --testsuite Unit`.

`tools/phpunit.phar` ist nicht versioniert — bei Bedarf von https://phar.phpunit.de/ laden.
