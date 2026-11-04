#!/bin/sh
# Setzt die Datenbank des laufenden Compose-Stacks zurück und spielt die Demo-Daten ein.
# NUR für Entwicklung/Tests: löscht alle Daten der Datenbank!
set -e
cd "$(dirname "$0")/../.."
. ./.env
docker compose exec -T -e MYSQL_PWD="$DB_ROOT_PASS" db mariadb -uroot -e \
  "DROP DATABASE IF EXISTS \`${DB_NAME:-klausurwerk}\`; CREATE DATABASE \`${DB_NAME:-klausurwerk}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON \`${DB_NAME:-klausurwerk}\`.* TO '${DB_USER:-klausurwerk}'@'%';"
docker compose exec -T app php bin/migrate.php >/dev/null
docker compose exec -T app php bin/demo.php
