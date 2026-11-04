#!/bin/sh
# Entwickler-Helfer (Git Bash / Linux). Braucht nur Docker.
#
#   tools/dev.sh lint                 PHP-Syntaxprüfung aller Dateien
#   tools/dev.sh unit                 PHPUnit, nur Unit-Tests
#   tools/dev.sh test [Filter]        PHPUnit komplett (Unit + Integration)
#   tools/dev.sh php <args>           beliebiger PHP-Aufruf im Testimage
#   tools/dev.sh testdb               Test-Datenbank starten (Container klausurwerk-testdb)
#
# Integrationstests nutzen TEST_DB_NAME (Standard klausurwerk_test). Wer parallel
# arbeitet, setzt einen eigenen Namen, z. B. TEST_DB_NAME=klausurwerk_test_live.
set -e
cd "$(dirname "$0")/.."
ROOT="$(pwd -W 2>/dev/null || pwd)"
IMAGE=klausurwerk-test
NET=klausurwerk-dev

ensure_image() {
    docker image inspect "$IMAGE" >/dev/null 2>&1 || docker build -q -t "$IMAGE" tests >/dev/null
}
ensure_net() {
    docker network inspect "$NET" >/dev/null 2>&1 || docker network create "$NET" >/dev/null
}
ensure_db() {
    ensure_net
    if ! docker ps --format '{{.Names}}' | grep -q '^klausurwerk-testdb$'; then
        docker rm -f klausurwerk-testdb >/dev/null 2>&1 || true
        docker run -d --name klausurwerk-testdb --network "$NET" \
            -e MARIADB_ROOT_PASSWORD=testroot -e TZ=Europe/Berlin \
            mariadb:11.4 >/dev/null
        i=0
        until docker exec klausurwerk-testdb healthcheck.sh --connect >/dev/null 2>&1; do
            i=$((i+1)); [ "$i" -gt 60 ] && { echo "Test-DB startet nicht" >&2; exit 1; }
            sleep 1
        done
    fi
}
run_php() {
    ensure_image
    ensure_net
    MSYS_NO_PATHCONV=1 docker run --rm --network "$NET" -v "$ROOT:/app" -w /app \
        -e TEST_DB_HOST=klausurwerk-testdb -e TEST_DB_USER=root -e TEST_DB_PASS=testroot \
        -e TEST_DB_NAME="${TEST_DB_NAME:-klausurwerk_test}" \
        "$IMAGE" php "$@"
}

case "${1:-}" in
    lint)
        ensure_image
        MSYS_NO_PATHCONV=1 docker run --rm -v "$ROOT:/app" -w /app "$IMAGE" sh -c \
            'find src templates public bin config tests lib -name "*.php" -not -path "*/node_modules/*" -print0 | xargs -0 -n1 -P4 php -l 2>&1 | grep -v "^No syntax errors" || true'
        ;;
    unit)
        run_php tools/phpunit.phar --testsuite Unit "${@:2}"
        ;;
    test)
        ensure_db
        shift
        if [ -n "${1:-}" ]; then run_php tools/phpunit.phar --filter "$1"; else run_php tools/phpunit.phar; fi
        ;;
    php)
        shift
        run_php "$@"
        ;;
    testdb)
        ensure_db
        echo "Test-DB läuft (Container klausurwerk-testdb, Netz $NET, root/testroot)."
        ;;
    *)
        sed -n '2,13p' "$0"
        ;;
esac
