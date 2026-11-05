#!/usr/bin/env bash
# Klausurwerk: Installations-Assistent für den Server (Linux mit Docker).
#
#   Im geklonten Repo:   ./install.sh
#   Ohne Klon:           curl -fsSL https://raw.githubusercontent.com/TheUnknown-0/klausurwerk/main/install.sh | bash
#                        (klappt nur, solange das Repo öffentlich ist; Zielordner: KLAUSURWERK_DIR, Standard /opt/klausurwerk)
#
# Der Assistent prüft Docker, fragt die nötigen Angaben ab, schreibt .env mit zufälligen
# Geheimnissen, startet die Container und legt das erste Admin-Konto an. Damit ist die
# Einrichtung im Browser (/setup) gesperrt, bevor die Domain öffentlich erreichbar ist.
# Ein zweiter Aufruf behält auf Wunsch die vorhandene .env und startet nur neu.

set -euo pipefail

REPO_URL="${KLAUSURWERK_REPO:-https://github.com/TheUnknown-0/klausurwerk.git}"
DEFAULT_DIR="${KLAUSURWERK_DIR:-/opt/klausurwerk}"
GHCR_APP="ghcr.io/theunknown-0/klausurwerk-app:latest"
GHCR_RUNNER="ghcr.io/theunknown-0/klausurwerk-runner:latest"
PROXY_NET="proxy_network"

# ---------- Ausgabe und Eingabe ----------

if [ -t 1 ]; then
    B=$'\e[1m'; G=$'\e[32m'; Y=$'\e[33m'; R=$'\e[31m'; N=$'\e[0m'
else
    B=""; G=""; Y=""; R=""; N=""
fi

say()  { printf '%s\n' "$*"; }
step() { printf '\n%s== %s ==%s\n' "$B" "$*" "$N"; }
ok()   { printf '%s✓%s %s\n' "$G" "$N" "$*"; }
warn() { printf '%s!%s %s\n' "$Y" "$N" "$*" >&2; }
die()  { printf '%sFehler:%s %s\n' "$R" "$N" "$*" >&2; exit 1; }

# Eingaben kommen immer vom Terminal, auch wenn das Skript per "curl | bash" läuft.
ask() { # Variable, Frage, Vorgabe
    local __var="$1" __q="$2" __def="${3:-}" __ans
    if [ -n "$__def" ]; then
        printf '%s [%s]: ' "$__q" "$__def" >/dev/tty
    else
        printf '%s: ' "$__q" >/dev/tty
    fi
    IFS= read -r __ans </dev/tty || __ans=""
    [ -z "$__ans" ] && __ans="$__def"
    printf -v "$__var" '%s' "$__ans"
}

ask_secret() { # Variable, Frage
    local __var="$1" __q="$2" __ans
    printf '%s: ' "$__q" >/dev/tty
    IFS= read -rs __ans </dev/tty || __ans=""
    printf '\n' >/dev/tty
    printf -v "$__var" '%s' "$__ans"
}

ask_yn() { # Frage, Vorgabe j|n; Rückgabe 0 = ja
    local __q="$1" __def="$2" __ans
    while :; do
        if [ "$__def" = "j" ]; then printf '%s [J/n]: ' "$__q" >/dev/tty; else printf '%s [j/N]: ' "$__q" >/dev/tty; fi
        IFS= read -r __ans </dev/tty || __ans=""
        __ans="${__ans:-$__def}"
        case "$__ans" in
            j|J|ja|Ja|y|Y) return 0 ;;
            n|N|nein|Nein) return 1 ;;
        esac
    done
}

# ---------- .env schreiben ----------

# Wert für die .env so schreiben, dass Docker Compose ihn wörtlich nimmt (keine $-Ersetzung).
env_quote() {
    local v="$1"
    if [[ "$v" =~ ^[A-Za-z0-9._:/@+,=-]*$ ]]; then
        printf '%s' "$v"
    elif [[ "$v" != *"'"* ]]; then
        printf "'%s'" "$v"
    else
        return 1
    fi
}

# Setzt KEY=VALUE in .env: ersetzt die erste (auch auskommentierte) Zeile oder hängt an.
set_env() {
    local key="$1" quoted
    quoted="$(env_quote "$2")" || die "Der Wert für $key enthält ein einfaches Anführungszeichen; das kann die .env nicht sicher speichern."
    KEY="$key" VAL="$quoted" awk '
        BEGIN { k = ENVIRON["KEY"]; v = ENVIRON["VAL"]; done = 0 }
        !done && $0 ~ ("^# ?" k "=|^" k "=") { print k "=" v; done = 1; next }
        { print }
        END { if (!done) print k "=" v }
    ' .env > .env.tmp
    mv .env.tmp .env
    chmod 600 .env
}

get_env() { # liest einen Wert aus .env (ohne Anführungszeichen), leer wenn nicht gesetzt
    local line
    line="$(grep -E "^$1=" .env 2>/dev/null | tail -n1 || true)"
    line="${line#*=}"
    line="${line#\'}"; line="${line%\'}"
    line="${line#\"}"; line="${line%\"}"
    printf '%s' "$line"
}

unset_env() { # kommentiert KEY in .env aus
    KEY="$1" awk 'BEGIN { k = ENVIRON["KEY"] } $0 ~ ("^" k "=") { print "# " $0; next } { print }' .env > .env.tmp
    mv .env.tmp .env
    chmod 600 .env
}

gen_secret() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 32
    else
        od -An -tx1 -N32 /dev/urandom | tr -d ' \n'
    fi
}

# ---------- Schritte ----------

check_requirements() {
    step "Voraussetzungen"
    [ -r /dev/tty ] || die "Der Assistent braucht ein Terminal für Rückfragen."
    command -v docker >/dev/null 2>&1 \
        || die "Docker fehlt. Installation zum Beispiel mit: curl -fsSL https://get.docker.com | sh"
    docker compose version >/dev/null 2>&1 \
        || die "Das Docker-Compose-Plugin fehlt (Paket docker-compose-plugin)."
    docker info >/dev/null 2>&1 \
        || die "Kein Zugriff auf Docker. Als root starten oder den Benutzer in die Gruppe docker aufnehmen."
    ok "Docker $(docker version --format '{{.Server.Version}}' 2>/dev/null) mit $(docker compose version --short 2>/dev/null | sed 's/^/Compose /')"

    local cores mem_kb
    cores="$(nproc 2>/dev/null || echo 0)"
    mem_kb="$(awk '/^MemTotal:/ { print $2 }' /proc/meminfo 2>/dev/null || echo 0)"
    if [ "$cores" -lt 2 ] || [ "$mem_kb" -lt 3500000 ]; then
        warn "Der Server hat $cores CPU-Kern(e) und $((mem_kb / 1024)) MB RAM. Empfohlen sind mindestens 2 Kerne und 4 GB für einen Raum mit etwa 35 Schüler:innen."
    else
        ok "$cores CPU-Kerne, $((mem_kb / 1024)) MB RAM"
    fi
}

locate_project() {
    step "Projektordner"
    local self_dir=""
    if [ -n "${BASH_SOURCE[0]:-}" ] && [ -f "${BASH_SOURCE[0]}" ]; then
        self_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    fi
    if [ -n "$self_dir" ] && [ -f "$self_dir/compose.yaml" ]; then
        cd "$self_dir"
        ok "Verwende $self_dir"
        return
    fi

    local dir
    ask dir "Wohin soll Klausurwerk installiert werden?" "$DEFAULT_DIR"
    if [ -f "$dir/compose.yaml" ]; then
        cd "$dir"
        ok "Vorhandenen Ordner $dir gefunden"
        return
    fi
    command -v git >/dev/null 2>&1 || die "git fehlt (Paket git)."
    mkdir -p "$(dirname "$dir")" 2>/dev/null || die "Kann $(dirname "$dir") nicht anlegen. Als root starten oder KLAUSURWERK_DIR setzen."
    say "Lade den Code von $REPO_URL ..."
    if ! GIT_TERMINAL_PROMPT=0 git clone --depth 1 "$REPO_URL" "$dir" </dev/null; then
        die "Klonen fehlgeschlagen. Ist das Repo privat, zuerst mit Zugang klonen (Deploy-Key oder Token) und dann ./install.sh im Ordner starten."
    fi
    cd "$dir"
    ok "Code liegt in $dir"
}

project_volume_exists() {
    local project
    project="$(basename "$PWD" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-')"
    docker volume inspect "${project}_db_data" >/dev/null 2>&1
}

ADMIN_READY=0
ask_admin() {
    step "Erstes Admin-Konto"
    say "Mit diesem Konto meldest du dich nach der Installation an."
    ask A_SCHOOL "Name der Schule" "${A_SCHOOL:-}"
    while [ -z "$A_SCHOOL" ]; do ask A_SCHOOL "Name der Schule (Pflicht)"; done
    ask A_FIRST "Vorname" "${A_FIRST:-}"
    ask A_LAST "Nachname" "${A_LAST:-}"
    while :; do
        ask A_USER "Benutzername" "${A_USER:-admin}"
        [[ "$A_USER" =~ ^[a-zA-Z0-9._-]{3,50}$ ]] && break
        warn "3 bis 50 Zeichen: Buchstaben, Zahlen, Punkt, Minus, Unterstrich."
    done
    while :; do
        ask A_MAIL "E-Mail für Anmeldecodes (leer: keine)" "${A_MAIL:-}"
        [ -z "$A_MAIL" ] || [[ "$A_MAIL" == *@*.* ]] && break
        warn "Das sieht nicht nach einer E-Mail-Adresse aus."
    done
    while :; do
        ask_secret A_PASS "Passwort (mindestens 8 Zeichen)"
        if [ "${#A_PASS}" -lt 8 ]; then warn "Zu kurz."; continue; fi
        ask_secret A_PASS2 "Passwort wiederholen"
        [ "$A_PASS" = "$A_PASS2" ] && break
        warn "Die Eingaben stimmen nicht überein."
    done
    ADMIN_READY=1
}

configure_env() {
    step "Konfiguration"
    FRESH_ENV=1
    if [ -f .env ]; then
        if ask_yn "Es gibt schon eine .env. Behalten und nur (neu) starten?" j; then
            FRESH_ENV=0
            ok ".env bleibt unverändert"
            return
        fi
        local backup
        backup=".env.bak-$(date +%Y%m%d-%H%M%S)"
        cp .env "$backup"
        chmod 600 "$backup"
        ok "Alte .env gesichert als $backup"
    fi

    if project_volume_exists; then
        warn "Es gibt schon eine Datenbank aus einer früheren Installation. Neue Passwörter passen nicht zu ihr, die App käme dann nicht an die Datenbank."
        ask_yn "Trotzdem fortfahren? (Sonst die alte .env zurückholen oder das Volume bewusst löschen.)" n || die "Abgebrochen."
    fi

    cp .env.example .env
    chmod 600 .env

    # Öffentliche Adresse
    say ""
    say "Die öffentliche Adresse steht in den QR-Codes der Zugangskarten und in den SEB-Dateien."
    while :; do
        ask PUBLIC_URL "Öffentliche Adresse, z. B. https://klausur.meine-schule.de (leer: später in den Einstellungen)"
        PUBLIC_URL="${PUBLIC_URL%/}"
        [ -z "$PUBLIC_URL" ] && break
        [[ "$PUBLIC_URL" =~ ^https?:// ]] || PUBLIC_URL="https://$PUBLIC_URL"
        [[ "$PUBLIC_URL" =~ ^https?://[A-Za-z0-9.-]+(:[0-9]+)?(/.*)?$ ]] && break
        warn "Die Adresse ist ungültig."
    done

    # Reverse-Proxy
    say ""
    say "Klausurwerk braucht einen Reverse-Proxy mit HTTPS davor (zum Beispiel Nginx Proxy Manager, Traefik oder Caddy)."
    if ask_yn "Läuft der Reverse-Proxy als Docker-Container im Netz $PROXY_NET?" j; then
        PROXY_MODE=docker
    else
        PROXY_MODE=host
        say "Dann leitet der Proxy auf dem Server an 127.0.0.1 und den Port von Klausurwerk weiter."
    fi

    # Port, immer nur lokal gebunden: Docker umgeht Firewall-Regeln wie ufw.
    local port
    while :; do
        ask port "Lokaler Port (nur auf dem Server erreichbar, für Proxy, SSH-Tunnel und Tests)" "9030"
        [[ "$port" =~ ^[0-9]+$ ]] && [ "$port" -ge 1 ] && [ "$port" -le 65535 ] || { warn "Bitte eine Portnummer."; continue; }
        local listening=""
        command -v ss >/dev/null 2>&1 && listening="$(ss -ltnH 2>/dev/null | awk '{ print $4 }' || true)"
        if grep -Eq "[:.]$port\$" <<<"$listening"; then
            warn "Port $port ist schon belegt."
            continue
        fi
        break
    done
    APP_PORT_NUM="$port"

    # Images
    say ""
    if ask_yn "Fertige Images aus der GitHub Container Registry nutzen? (Nein: auf dem Server bauen, dauert einige Minuten)" j; then
        USE_GHCR=1
    else
        USE_GHCR=0
    fi

    # Mail
    say ""
    say "Per E-Mail bekommen Lehrkräfte einen Anmeldecode als zweiten Faktor. Ohne Mailserver melden sie sich nur mit Passwort an."
    MAIL_ON=0
    if ask_yn "SMTP-Zugang jetzt eintragen?" n; then
        MAIL_ON=1
        ask M_HOST "SMTP-Server"
        ask M_PORT "Port" "587"
        ask M_ENC "Verschlüsselung (tls oder ssl)" "tls"
        ask M_USER "Benutzer"
        ask_secret M_PASS "Passwort"
        ask M_FROM "Absenderadresse" "$M_USER"
        ask M_NAME "Absendername" "Klausurwerk"
    fi

    ask_admin

    # Schreiben
    set_env DB_PASS "$(gen_secret)"
    set_env DB_ROOT_PASS "$(gen_secret)"
    set_env RUNNER_TOKEN "$(gen_secret)"
    set_env APP_PORT "127.0.0.1:$APP_PORT_NUM"
    set_env APP_ENV "production"
    if [ "$USE_GHCR" = 1 ]; then
        set_env APP_IMAGE "$GHCR_APP"
        set_env RUNNER_IMAGE "$GHCR_RUNNER"
    fi
    if [ "$MAIL_ON" = 1 ]; then
        set_env MAIL_HOST "$M_HOST"
        set_env MAIL_PORT "$M_PORT"
        set_env MAIL_ENCRYPTION "$M_ENC"
        set_env MAIL_USER "$M_USER"
        set_env MAIL_PASS "$M_PASS"
        set_env MAIL_FROM_ADDRESS "$M_FROM"
        set_env MAIL_FROM_NAME "$M_NAME"
    fi
    ok ".env geschrieben (Passwörter und Runner-Token zufällig erzeugt, nur für root lesbar)"
}

ensure_network() {
    if docker network inspect "$PROXY_NET" >/dev/null 2>&1; then
        ok "Docker-Netz $PROXY_NET vorhanden"
    else
        docker network create "$PROXY_NET" >/dev/null
        ok "Docker-Netz $PROXY_NET angelegt. Der Reverse-Proxy muss sich mit diesem Netz verbinden."
    fi
}

# Nur Anfragen von diesen Adressen dürfen X-Forwarded-For/-Proto setzen.
set_trusted_proxies() {
    local value=""
    if [ "$PROXY_MODE" = docker ]; then
        value="$(docker network inspect "$PROXY_NET" --format '{{range .IPAM.Config}}{{.Subnet}},{{end}}' 2>/dev/null)"
    else
        # Proxy auf dem Host: Anfragen kommen über das Gateway der Docker-Netze der App.
        local cid
        cid="$(docker compose ps -q app 2>/dev/null)"
        [ -n "$cid" ] && value="$(docker inspect "$cid" --format '{{range .NetworkSettings.Networks}}{{if .Gateway}}{{.Gateway}},{{end}}{{end}}' 2>/dev/null)"
    fi
    value="${value%,}"
    if [ -z "$value" ]; then
        warn "Konnte die Adressen des Reverse-Proxys nicht bestimmen. TRUSTED_PROXIES in .env bitte von Hand setzen."
        return 1
    fi
    if [ "$(get_env TRUSTED_PROXIES)" = "$value" ]; then
        return 1
    fi
    set_env TRUSTED_PROXIES "$value"
    ok "TRUSTED_PROXIES=$value"
    return 0
}

wait_ready() {
    say "Warte, bis die Anwendung bereit ist (Migrationen laufen beim ersten Start) ..."
    for _ in $(seq 1 90); do
        if docker compose exec -T app php -r 'exit(@file_get_contents("http://127.0.0.1/readyz") !== false ? 0 : 1);' </dev/null >/dev/null 2>&1; then
            ok "Anwendung bereit"
            return 0
        fi
        sleep 2
    done
    docker compose logs --tail 40 app db runner >&2 || true
    die "Die Anwendung wurde nicht bereit. Protokolle oben, mehr mit: docker compose logs app"
}

start_containers() {
    step "Container starten"
    ensure_network
    if [ "${FRESH_ENV:-0}" = 1 ] && [ "$PROXY_MODE" = docker ]; then
        set_trusted_proxies || true
    fi

    if [ -n "$(get_env APP_IMAGE)" ]; then
        say "Lade Images ..."
        if ! docker compose pull </dev/null; then
            warn "Die Images ließen sich nicht laden. Sind sie privat, vorher anmelden: docker login ghcr.io (Token mit read:packages)."
            if ask_yn "Stattdessen auf dem Server bauen?" j; then
                unset_env APP_IMAGE
                unset_env RUNNER_IMAGE
            else
                die "Abgebrochen."
            fi
        fi
    fi

    if [ -n "$(get_env APP_IMAGE)" ]; then
        docker compose up -d </dev/null
    else
        say "Baue die Images. Das erste Mal dauert das einige Minuten ..."
        docker compose up -d --build </dev/null
    fi
    wait_ready

    if [ "${FRESH_ENV:-0}" = 1 ] && [ "$PROXY_MODE" = host ]; then
        if set_trusted_proxies; then
            docker compose up -d </dev/null
            wait_ready
        fi
    fi
}

create_admin() {
    step "Einrichtung"
    if ! docker compose exec -T app php bin/setup.php --check </dev/null >/dev/null 2>&1; then
        ok "Es gibt schon Konten. Die Einrichtung ist abgeschlossen."
        return
    fi
    [ "$ADMIN_READY" = 1 ] || ask_admin
    local tries=0
    while :; do
        if printf '%s\n' \
            "school_name=$A_SCHOOL" \
            "firstname=$A_FIRST" \
            "lastname=$A_LAST" \
            "username=$A_USER" \
            "email=$A_MAIL" \
            "public_base_url=${PUBLIC_URL:-}" \
            "password=$A_PASS" \
            "password_confirm=$A_PASS2" \
            | docker compose exec -T app php bin/setup.php --stdin; then
            break
        fi
        tries=$((tries + 1))
        [ "$tries" -ge 3 ] && die "Admin-Konto konnte nicht angelegt werden. Später von Hand: docker compose exec app php bin/setup.php"
        warn "Bitte die Angaben korrigieren."
        ask_admin
    done
    A_PASS=""; A_PASS2=""
}

summary() {
    local port url
    port="$(get_env APP_PORT)"
    port="${port##*:}"
    url="${PUBLIC_URL:-}"
    step "Fertig"
    say "Klausurwerk läuft. Auf dem Server erreichbar unter http://127.0.0.1:${port:-9030}"
    say "Von deinem Rechner aus per SSH-Tunnel: ssh -L ${port:-9030}:127.0.0.1:${port:-9030} benutzer@server, dann http://localhost:${port:-9030}"
    say ""
    say "${B}Am Reverse-Proxy noch einrichten:${N}"
    if [ "${PROXY_MODE:-docker}" = host ]; then
        say "  - Ziel: http://127.0.0.1:${port:-9030}"
    else
        say "  - Ziel: klausurwerk-app, Port 80 (der Proxy hängt im Docker-Netz $PROXY_NET)"
    fi
    say "  - Domain ${url:-deiner Wahl} mit HTTPS-Zertifikat"
    say "  - Die Sicherheits-Header der App nicht entfernen oder überschreiben (sonst wartet Python-input() nicht auf Eingaben)"
    say "  - Uploads bis 200 MB erlauben (Nginx: client_max_body_size 200m;)"
    say ""
    say "${B}Danach in der Anwendung:${N}"
    say "  - Anmelden und oben in der Übersicht auf gelbe Hinweise achten"
    if [ "${FRESH_ENV:-0}" = 1 ] && [ -z "$url" ]; then
        say "  - Unter Verwaltung › Einstellungen die öffentliche Adresse eintragen"
    fi
    say "  - Unter Verwaltung › Einstellungen eine Test-Mail senden"
    say "  - Eine Probe-Klausur mit Python-input() und einem Java-Lauf über die echte Domain machen"
    say "  - Datensicherung einrichten (Handbuch für Admins, Kapitel 2.9)"
    say ""
    say "Die Zugangsdaten der Datenbank stehen in $PWD/.env. Diese Datei gut sichern."
}

main() {
    say "${B}Klausurwerk: Installation${N}"
    check_requirements
    locate_project
    configure_env
    if [ "${FRESH_ENV:-0}" = 0 ]; then
        PROXY_MODE=docker
        [ -n "$(get_env TRUSTED_PROXIES)" ] || warn "TRUSTED_PROXIES ist leer. Hinter einem Reverse-Proxy bitte in .env setzen."
    fi
    start_containers
    create_admin
    summary
}

main "$@"
