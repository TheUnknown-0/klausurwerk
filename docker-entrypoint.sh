#!/bin/sh
set -e

DB_WAIT_TIMEOUT="${DB_WAIT_TIMEOUT:-120}"

# Das Passwort NICHT als Argument übergeben — es stünde sonst für jeden
# sichtbar in der Prozessliste des Containers.
export MYSQL_PWD="${DB_PASS}"

echo "Warte auf Datenbank ${DB_HOST} (max. ${DB_WAIT_TIMEOUT}s)..."
waited=0
until mysql -h "${DB_HOST}" -u"${DB_USER}" "${DB_NAME}" -e "SELECT 1" >/dev/null 2>&1; do
    if [ "${waited}" -ge "${DB_WAIT_TIMEOUT}" ]; then
        echo "Datenbank ${DB_HOST} nach ${DB_WAIT_TIMEOUT}s nicht erreichbar — Abbruch." >&2
        echo "Ohne Abbruch bliebe der Container endlos in der Warteschleife und" >&2
        echo "gälte dabei als lauffähig; so greift die Restart-Policy." >&2
        exit 1
    fi
    waited=$((waited + 2))
    sleep 2
done

echo "Führe Migrationen aus..."
php /var/www/html/bin/migrate.php

exec "$@"
