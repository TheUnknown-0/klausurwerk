#!/usr/bin/env bash
# Klausurwerk aktualisieren: Kurzform für ./install.sh --update
#
#   cd /opt/klausurwerk && sudo ./update.sh
#
# Holt den neuen Code, warnt bei laufenden Klausuren, sichert Datenbank und Uploads nach
# backups/, lädt oder baut die Images und startet die Container neu.
exec bash "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/install.sh" --update "$@"
