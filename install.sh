#!/usr/bin/env bash
# Klausurwerk: Installations-Assistent für den Server (Linux mit Docker).
#
#   Im geklonten Repo:   ./install.sh
#   Ohne Klon:           curl -fsSL https://raw.githubusercontent.com/TheUnknown-0/klausurwerk/main/install.sh | bash
#                        Zielordner wird abgefragt: absolut (/opt/klausurwerk), relativ zum aktuellen
#                        Ordner (klausurwerk, ./apps/klausurwerk, "." = ./klausurwerk) oder im
#                        Home-Verzeichnis (~/docker). Vorgabe auch über KLAUSURWERK_DIR.
#
# Der Assistent prüft Docker, fragt die nötigen Angaben ab, schreibt .env mit zufälligen
# Geheimnissen, startet die Container und legt das erste Admin-Konto an. Damit ist die
# Einrichtung im Browser (/setup) gesperrt, bevor die Domain öffentlich erreichbar ist.
# Ein zweiter Aufruf behält auf Wunsch die vorhandene .env und startet nur neu.
#
# Aktualisieren:         ./install.sh --update   (oder ./update.sh)
#                        curl -fsSL https://raw.githubusercontent.com/TheUnknown-0/klausurwerk/main/install.sh | sudo bash -s -- --update
#                        Holt den neuen Code, warnt bei laufenden Klausuren, sichert Datenbank und
#                        Uploads nach backups/, lädt oder baut die Images und startet neu.
#                        Ein Aufruf ohne --update in einer bestehenden Installation fragt nach.

set -euo pipefail

REPO_URL="${KLAUSURWERK_REPO:-https://github.com/TheUnknown-0/klausurwerk.git}"
DEFAULT_DIR="${KLAUSURWERK_DIR:-/opt/klausurwerk}"
GHCR_APP="ghcr.io/theunknown-0/klausurwerk-app:latest"
GHCR_RUNNER="ghcr.io/theunknown-0/klausurwerk-runner:latest"
PROXY_NET="proxy_network"
# Ordner, in dem das Skript gestartet wurde: Bezug für relative Zielordner.
START_DIR="$(pwd)"
# install = einrichten, update = aktualisieren (--update)
MODE="install"

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

    # Läuft schon eine Installation, steht ihr Ordner im Label des App-Containers.
    local default="$DEFAULT_DIR" found
    found="$(installed_dir)"
    [ -n "$found" ] && [ -z "${KLAUSURWERK_DIR:-}" ] && default="$found"

    local input dir
    if [ "$MODE" = update ]; then
        while :; do
            ask input "In welchem Ordner liegt Klausurwerk?" "$default"
            dir="$(resolve_dir "$input")"
            [ -n "$dir" ] && break
        done
        if [ ! -f "$dir/compose.yaml" ] && [ -f "$dir/klausurwerk/compose.yaml" ]; then
            dir="$dir/klausurwerk"
        fi
        [ -f "$dir/compose.yaml" ] || die "In $dir liegt keine Klausurwerk-Installation. Zum Installieren ohne --update starten."
        cd "$dir"
        ok "Installation in $dir gefunden"
        return
    fi

    say "Zielordner: absolut (/opt/klausurwerk), relativ zum aktuellen Ordner $START_DIR"
    say "(klausurwerk oder ./apps/klausurwerk; ein einzelner Punkt heißt ./klausurwerk) oder mit ~ im Home-Verzeichnis."
    say "Ist der Ordner schon belegt, landet Klausurwerk im Unterordner klausurwerk."
    while :; do
        ask input "Wohin soll Klausurwerk installiert werden?" "$default"
        dir="$(resolve_dir "$input")"
        [ -n "$dir" ] && break
        warn "Bitte einen Ordner angeben."
    done
    if [ -f "$dir/compose.yaml" ]; then
        cd "$dir"
        ok "Vorhandenen Ordner $dir gefunden"
        return
    fi
    # Belegter Ordner ohne Klausurwerk (z. B. ~/docker mit anderen Projekten): Unterordner nehmen.
    if [ -d "$dir" ] && [ -n "$(ls -A "$dir" 2>/dev/null)" ]; then
        dir="${dir%/}/klausurwerk"
        if [ -f "$dir/compose.yaml" ]; then
            cd "$dir"
            ok "Vorhandenen Ordner $dir gefunden"
            return
        fi
    fi
    ask_yn "Klausurwerk nach $dir installieren?" j || die "Abgebrochen."
    command -v git >/dev/null 2>&1 || die "git fehlt (Paket git)."
    mkdir -p "$(dirname "$dir")" 2>/dev/null || die "Kann $(dirname "$dir") nicht anlegen. Als root starten (sudo) oder einen anderen Ordner wählen."
    say "Lade den Code von $REPO_URL ..."
    if ! GIT_TERMINAL_PROMPT=0 git clone --depth 1 "$REPO_URL" "$dir" </dev/null; then
        die "Klonen fehlgeschlagen. Ist das Repo privat, zuerst mit Zugang klonen (Deploy-Key oder Token) und dann ./install.sh im Ordner starten."
    fi
    cd "$dir"
    ok "Code liegt in $dir"
}

# Home-Verzeichnis der aufrufenden Person, auch unter sudo (dort wäre $HOME sonst /root).
user_home() {
    local h=""
    if [ -n "${SUDO_USER:-}" ] && [ "$SUDO_USER" != root ]; then
        h="$(getent passwd "$SUDO_USER" 2>/dev/null | cut -d: -f6 || true)"
    fi
    printf '%s' "${h:-$HOME}"
}

# Macht aus der Eingabe einen absoluten Pfad: ~ = Home, "." = ./klausurwerk, sonst relativ zu START_DIR.
resolve_dir() {
    local p="$1"
    # shellcheck disable=SC2088 # Die Tilde wird hier bewusst wörtlich verglichen und selbst ersetzt.
    case "$p" in
        "") return 0 ;;
        "~") p="$(user_home)" ;;
        "~/"*) p="$(user_home)/${p#\~/}" ;;
        ".") p="$START_DIR/klausurwerk" ;;
        /*) ;;
        *) p="$START_DIR/${p#./}" ;;
    esac
    # Doppelte und abschließende Schrägstriche sowie ".." glätten (realpath -m: Ordner muss nicht existieren).
    p="$(printf '%s' "$p" | tr -s '/')"
    p="$(realpath -m -- "$p" 2>/dev/null || printf '%s' "$p")"
    [ "$p" = / ] || p="${p%/}"
    printf '%s' "$p"
}

# Ordner einer laufenden oder gestoppten Installation (aus dem Label des App-Containers), sonst leer.
installed_dir() {
    local d
    d="$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project.working_dir"}}' klausurwerk-app 2>/dev/null || true)"
    [ -n "$d" ] && [ -f "$d/compose.yaml" ] && printf '%s' "$d"
    return 0
}

# Compose-Projektname: wie Docker Compose ihn aus dem Ordnernamen bildet.
project_name() {
    basename "$PWD" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-'
}

project_volume_exists() {
    docker volume inspect "$(project_name)_db_data" >/dev/null 2>&1
}

# Destruktive Schritte nur nach eingetipptem Wort, nicht mit einem schnellen "j".
confirm_delete() { # Frage; Rückgabe 0 = bestätigt
    local ans
    say "$1"
    ask ans "Zum Bestätigen das Wort löschen eintippen (leer: abbrechen)"
    [ "$ans" = "löschen" ] || [ "$ans" = "LÖSCHEN" ] || [ "$ans" = "Löschen" ]
}

# Löscht Container und Volumes dieses Projekts: Datenbank und hochgeladene Dateien.
reset_data() {
    docker compose down -v --remove-orphans </dev/null >/dev/null 2>&1 || true
    docker volume rm "$(project_name)_db_data" "$(project_name)_uploads" >/dev/null 2>&1 || true
    if project_volume_exists; then
        die "Das Volume $(project_name)_db_data ließ sich nicht löschen. Läuft noch ein Container darauf? (docker ps -a)"
    fi
    ok "Alte Daten gelöscht. Die Datenbank wird beim Start neu angelegt."
}

# Container mit den festen Namen klausurwerk-* aus einer Installation in einem anderen Ordner
# blockieren den Start ("name already in use").
check_name_conflicts() {
    local c project dir own
    own="$(project_name)"
    for c in klausurwerk-db klausurwerk-runner klausurwerk-app; do
        docker inspect "$c" >/dev/null 2>&1 || continue
        project="$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project"}}' "$c" 2>/dev/null || true)"
        [ "$project" = "$own" ] && continue
        dir="$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project.working_dir"}}' "$c" 2>/dev/null || true)"
        warn "Der Container $c gehört zu einer anderen Installation${dir:+ (Ordner $dir)}."
        if ask_yn "Diesen alten Container entfernen? (Seine Daten-Volumes bleiben erhalten.)" j; then
            docker rm -f "$c" >/dev/null
            ok "$c entfernt"
        else
            die "Abgebrochen. Zwei Installationen mit denselben Containernamen können nicht gleichzeitig laufen."
        fi
    done
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
    NEED_TP=1
    if [ -f .env ]; then
        if ask_yn "Es gibt schon eine .env. Behalten und nur (neu) starten?" j; then
            FRESH_ENV=0
            NEED_TP=0
            PROXY_MODE=docker
            ok ".env bleibt unverändert"
            if [ -z "$(get_env TRUSTED_PROXIES)" ]; then
                warn "TRUSTED_PROXIES ist leer. Hinter einem Reverse-Proxy sieht die App sonst nur HTTP (Cookies, QR-Codes, SEB)."
                NEED_TP=1
                if ask_yn "Läuft der Reverse-Proxy als Docker-Container im Netz $PROXY_NET? (Nein: auf dem Server selbst)" j; then
                    PROXY_MODE=docker
                else
                    PROXY_MODE=host
                fi
            fi
            return
        fi
        local backup
        backup=".env.bak-$(date +%Y%m%d-%H%M%S)"
        cp .env "$backup"
        chmod 600 "$backup"
        ok "Alte .env gesichert als $backup"
    fi

    if project_volume_exists; then
        warn "Hier liegt noch eine Datenbank aus einer früheren Installation (Volume $(project_name)_db_data)."
        say "Neue Passwörter passen nicht zu ihr: MariaDB übernimmt Passwörter nur beim allerersten Start."
        say "Entweder die alte .env zurücklegen und den Assistenten mit \"behalten\" starten, oder die alten Daten löschen."
        if confirm_delete "Alte Klausurwerk-Daten (Datenbank und hochgeladene Dateien) endgültig löschen?"; then
            reset_data
        else
            die "Abgebrochen. Nichts verändert."
        fi
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

app_ready() {
    local t=()
    command -v timeout >/dev/null 2>&1 && t=(timeout 20)
    # shellcheck disable=SC2016 # PHP-Code: $c gehört PHP, nicht der Shell.
    ${t[@]+"${t[@]}"} docker compose exec -T app php -r '
        $c = stream_context_create(["http" => ["timeout" => 5]]);
        exit(@file_get_contents("http://127.0.0.1/readyz", false, $c) !== false ? 0 : 1);' </dev/null >/dev/null 2>&1
}

wait_ready() {
    say "Warte, bis die Anwendung bereit ist (Migrationen laufen beim ersten Start) ..."
    local start=$SECONDS note=15 cid info status restarts base
    cid="$(docker compose ps -q app 2>/dev/null)"
    base="$(docker inspect -f '{{.RestartCount}}' "$cid" 2>/dev/null || echo 0)"
    while [ $((SECONDS - start)) -lt 300 ]; do
        if app_ready; then
            ok "Anwendung bereit"
            return 0
        fi
        info="$(docker inspect -f '{{.State.Status}} {{.RestartCount}}' "$cid" 2>/dev/null || echo "fehlt 0")"
        status="${info% *}"
        restarts="${info##* }"
        if [ "$restarts" -gt "$base" ] || [ "$status" = exited ] || [ "$status" = dead ] || [ "$status" = fehlt ]; then
            docker compose logs --tail 30 app >&2 || true
            die "Der App-Container ist abgebrochen (Status: $status). Die Protokolle stehen oben, mehr mit: sudo docker compose logs app"
        fi
        if [ $((SECONDS - start)) -ge "$note" ]; then
            say "  ... noch nicht bereit nach $((SECONDS - start)) s (Container: $status)"
            note=$((note + 15))
        fi
        sleep 2
    done
    docker compose logs --tail 40 app db runner >&2 || true
    die "Die Anwendung wurde nach 5 Minuten nicht bereit. Die Protokolle stehen oben, mehr mit: sudo docker compose logs app"
}

# Prüft mit den Zugangsdaten aus .env, ob die Datenbank die App hereinlässt. Die Werte kommen
# aus der Umgebung des db-Containers, damit kein Passwort auf einer Befehlszeile steht.
# Rückgabe 0 = Anmeldung abgelehnt (falsches Passwort), 1 = in Ordnung oder unklar.
db_login_rejected() {
    local out _
    for _ in 1 2 3 4 5; do
        if out="$(docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mariadb -h 127.0.0.1 -u"$MYSQL_USER" "$MYSQL_DATABASE" -e "SELECT 1"' </dev/null 2>&1)"; then
            return 1
        fi
        case "$out" in
            *"Access denied"*|*"1045"*|*"1044"*|*"Unknown database"*|*"1049"*) return 0 ;;
        esac
        sleep 2
    done
    return 1
}

check_db_login() {
    db_login_rejected || return 0
    warn "Die Datenbank lehnt die Anmeldung der App ab: Das Passwort in .env passt nicht zur vorhandenen Datenbank."
    say "Die Datenbank stammt aus einer früheren Installation, MariaDB übernimmt Passwörter nur beim allerersten Start."
    say "Lösung A: die .env der damaligen Installation zurücklegen und den Assistenten erneut starten (Daten bleiben)."
    say "Lösung B: die alten Daten löschen; die Datenbank wird dann mit den Passwörtern aus .env neu angelegt."
    if confirm_delete "Lösung B: alte Klausurwerk-Daten (Datenbank und hochgeladene Dateien) endgültig löschen?"; then
        reset_data
        docker compose up -d </dev/null
        if db_login_rejected; then
            die "Die Anmeldung an der Datenbank klappt weiterhin nicht. Bitte DB_USER, DB_PASS und DB_NAME in .env prüfen."
        fi
        ok "Datenbank neu angelegt"
    else
        die "Abgebrochen. Nichts gelöscht."
    fi
}

start_containers() {
    step "Container starten"
    ensure_network
    check_name_conflicts
    if [ "${NEED_TP:-0}" = 1 ] && [ "$PROXY_MODE" = docker ]; then
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
    check_db_login
    wait_ready

    if [ "${NEED_TP:-0}" = 1 ] && [ "$PROXY_MODE" = host ]; then
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
        [ "$tries" -ge 3 ] && die "Admin-Konto konnte nicht angelegt werden. Später von Hand: sudo docker compose exec app php bin/setup.php"
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

# ---------- Aktualisieren ----------

# git verweigert als root die Arbeit in einem Ordner, der jemand anderem gehört ("dubious ownership").
git_() { git --no-pager -c safe.directory="$(pwd -P)" "$@"; }

version_at() { # Versionsnummer aus CHANGELOG.md eines Commits (oberste "## X.Y.Z"), leer wenn unbekannt
    { git_ show "$1:CHANGELOG.md" 2>/dev/null || true; } \
        | sed -nE '/^## v?[0-9]+\.[0-9]+\.[0-9]+/{s/^## v?([0-9]+\.[0-9]+\.[0-9]+).*/\1/p;q;}' || true
}

describe_rev() { # Commit; Ausgabe "Version 1.3.0 (abc1234 vom 05.11.2026 14:03)" bzw. ohne Version
    local rev version
    rev="$(git_ log -1 --format='%h vom %cd' --date=format:'%d.%m.%Y %H:%M' "$1" 2>/dev/null || printf '%.7s' "$1")"
    version="$(version_at "$1")"
    if [ -n "$version" ]; then
        printf 'Version %s (%s)' "$version" "$rev"
    else
        printf '%s' "$rev"
    fi
}

uses_ghcr() { [ -n "$(get_env APP_IMAGE)" ]; }

UPDATE_TARGET=""   # Commit, auf den der Code wechselt; leer = Code bleibt
STASH_NEEDED=0     # 1 = von Hand geänderte Dateien vor dem Wechsel beiseitelegen
BACKUP_DIR=""

fetch_code() {
    step "Neuer Stand"
    if ! command -v git >/dev/null 2>&1 || ! git_ rev-parse --git-dir >/dev/null 2>&1; then
        warn "$PWD ist kein Git-Klon (oder git fehlt). Der Code bleibt, wie er ist; aktualisiert werden nur die Images."
        return 0
    fi
    local branch head target count dirty
    branch="$(git_ symbolic-ref --quiet --short HEAD 2>/dev/null || true)"
    if [ -z "$branch" ]; then
        warn "Der Code steht auf keinem Branch, sondern fest auf $(describe_rev HEAD). Er bleibt so; aktualisiert werden nur die Images."
        return 0
    fi
    say "Frage das Repo nach dem neuesten Stand von $branch ..."
    if ! GIT_TERMINAL_PROMPT=0 git_ fetch --quiet origin "$branch" </dev/null; then
        die "Der neue Stand ließ sich nicht holen. Netzverbindung prüfen. Es wurde nichts verändert."
    fi
    head="$(git_ rev-parse HEAD)"
    target="$(git_ rev-parse FETCH_HEAD)"
    if [ "$head" = "$target" ] || git_ merge-base --is-ancestor "$target" HEAD 2>/dev/null; then
        ok "Der Code ist aktuell ($(describe_rev HEAD))"
        return 0
    fi
    count="$(git_ rev-list --count "HEAD..$target" 2>/dev/null || echo '?')"
    say "Installiert: $(describe_rev HEAD)"
    say "Neu:         $(describe_rev "$target")"
    say "Änderungen ($count):"
    git_ log --no-decorate --format='  - %s' -n 15 "HEAD..$target" 2>/dev/null || true
    if [ "$count" != '?' ] && [ "$count" -gt 15 ]; then
        say "  ... und $((count - 15)) weitere"
    fi

    if ! git_ merge-base --is-ancestor HEAD "$target" 2>/dev/null; then
        warn "Der neue Stand baut nicht auf dem installierten auf (eigene Commits im Ordner oder umgeschriebener Verlauf)."
        ask_yn "Trotzdem auf den neuen Stand wechseln? Eigene Commits sind danach nur noch über git reflog erreichbar." n \
            || die "Abgebrochen. Nichts verändert."
    fi
    dirty="$(git_ status --porcelain --untracked-files=no)"
    if [ -n "$dirty" ]; then
        warn "Diese Dateien wurden im Ordner von Hand geändert:"
        printf '%s\n' "$dirty" | sed 's/^/    /' >&2
        ask_yn "Beim Update beiseitelegen (git stash, zurückholen mit git stash pop)?" n \
            || die "Abgebrochen. Nichts verändert."
        STASH_NEEDED=1
    fi
    UPDATE_TARGET="$target"
}

# 0 = ein Container fehlt oder läuft mit einem anderen Image als dem, das lokal unter seinem Namen liegt.
containers_outdated() {
    local svc cid ref used cur
    for svc in db runner app; do
        cid="$(docker compose ps -q "$svc" 2>/dev/null || true)"
        [ -n "$cid" ] || return 0
        ref="$(docker inspect -f '{{.Config.Image}}' "$cid" 2>/dev/null || true)"
        used="$(docker inspect -f '{{.Image}}' "$cid" 2>/dev/null || true)"
        cur="$(docker image inspect -f '{{.Id}}' "$ref" 2>/dev/null || true)"
        if [ -n "$cur" ] && [ "$cur" != "$used" ]; then
            return 0
        fi
    done
    return 1
}

check_running_exams() {
    step "Laufende Klausuren"
    if [ -z "$(docker compose ps -q db 2>/dev/null || true)" ]; then
        ok "Die Datenbank läuft nicht, also auch keine Klausur"
        return 0
    fi
    local list
    # shellcheck disable=SC2016 # $MYSQL_* gehört der Shell im Container.
    if ! list="$(printf '%s\n' "SELECT CONCAT('  - ', title, ' (', CASE status WHEN 'lobby' THEN 'Warteraum' WHEN 'paused' THEN 'pausiert' ELSE 'läuft' END, ')') FROM exams WHERE status IN ('lobby','running','paused') ORDER BY id;" \
        | docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" exec mariadb -N -B -u"$MYSQL_USER" "$MYSQL_DATABASE"' 2>/dev/null)"; then
        warn "Konnte nicht prüfen, ob gerade Klausuren laufen. Bitte selbst im Pult nachsehen."
        return 0
    fi
    if [ -z "$list" ]; then
        ok "Gerade läuft keine Klausur"
        return 0
    fi
    warn "Gerade sind Klausuren geöffnet:"
    printf '%s\n' "$list" >&2
    say "Beim Neustart sind Pult und Schüleransicht etwa eine Minute nicht erreichbar. Die Schüler:innen"
    say "schreiben dank lokaler Sicherung weiter, aber besser erst nach der Klausur aktualisieren."
    ask_yn "Trotzdem jetzt aktualisieren?" n || die "Abgebrochen. Nichts verändert."
}

db_wait() {
    local _
    for _ in $(seq 1 30); do
        docker compose exec -T db healthcheck.sh --connect --innodb_initialized </dev/null >/dev/null 2>&1 && return 0
        sleep 2
    done
    return 1
}

backup_failed() { # Meldung, Sicherungsordner
    warn "$1"
    rm -rf -- "$2"
    ask_yn "Ohne Sicherung weitermachen?" n || die "Abgebrochen. Am Code und an den Containern wurde nichts verändert."
}

backup_data() {
    step "Datensicherung"
    if ! ask_yn "Vorher Datenbank und hochgeladene Dateien sichern? (empfohlen)" j; then
        warn "Weiter ohne Sicherung."
        return 0
    fi
    local dir
    dir="$PWD/backups/$(date +%Y%m%d-%H%M%S)"
    mkdir -p "$PWD/backups"
    chmod 700 "$PWD/backups"
    mkdir -p "$dir"
    chmod 700 "$dir"

    # Nur starten, nicht neu anlegen: ein gerade geladenes neues DB-Image kommt erst nach der Sicherung dran.
    if [ -z "$(docker compose ps -q db 2>/dev/null || true)" ]; then
        docker compose start db </dev/null >/dev/null 2>&1 || docker compose up -d db </dev/null >/dev/null 2>&1 || true
    fi
    if ! db_wait; then
        backup_failed "Die Datenbank antwortet nicht, die Sicherung geht nicht." "$dir"
        return 0
    fi
    say "Sichere die Datenbank ..."
    # shellcheck disable=SC2016 # $MYSQL_* gehört der Shell im Container.
    if ! docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mariadb-dump -uroot --single-transaction --quick --routines --triggers "$MYSQL_DATABASE"' </dev/null \
            | gzip > "$dir/datenbank.sql.gz" \
        || ! gzip -dc "$dir/datenbank.sql.gz" | tail -n 1 | grep -q 'Dump completed'; then
        backup_failed "Der Datenbank-Dump ist fehlgeschlagen." "$dir"
        return 0
    fi

    say "Sichere die hochgeladenen Dateien ..."
    local tar_ok=0
    if [ -n "$(docker compose ps -q app 2>/dev/null || true)" ]; then
        docker compose exec -T app tar czf - -C /var/www/html/uploads . </dev/null > "$dir/uploads.tar.gz" && tar_ok=1
    else
        docker compose run --rm --no-deps -T --entrypoint tar app czf - -C /var/www/html/uploads . </dev/null > "$dir/uploads.tar.gz" && tar_ok=1
    fi
    if [ "$tar_ok" != 1 ]; then
        backup_failed "Die hochgeladenen Dateien ließen sich nicht sichern." "$dir"
        return 0
    fi

    # Die Passwörter in .env gehören zu genau dieser Datenbank.
    cp .env "$dir/env"
    chmod 600 "$dir/env" "$dir/datenbank.sql.gz" "$dir/uploads.tar.gz"
    BACKUP_DIR="$dir"
    ok "Gesichert nach $dir ($(du -sh "$dir" 2>/dev/null | cut -f1))"

    local n
    n="$(find "$PWD/backups" -mindepth 1 -maxdepth 1 -type d | wc -l)"
    if [ "$n" -gt 5 ]; then
        say "In $PWD/backups liegen $n Sicherungen ($(du -sh "$PWD/backups" 2>/dev/null | cut -f1)). Alte bei Bedarf von Hand löschen."
    fi
}

# Wechselt den Code auf UPDATE_TARGET. Rückgabe 1 = nichts zu tun.
apply_code() {
    [ -n "$UPDATE_TARGET" ] || return 1
    step "Code aktualisieren"
    if [ "$STASH_NEEDED" = 1 ]; then
        git_ stash push --quiet -m "vor install.sh --update $(date +%Y-%m-%d_%H%M)"
        ok "Eigene Änderungen beiseitegelegt (git stash list)"
    fi
    if git_ merge-base --is-ancestor HEAD "$UPDATE_TARGET" 2>/dev/null; then
        git_ merge --ff-only --quiet "$UPDATE_TARGET" </dev/null
    else
        git_ reset --hard --quiet "$UPDATE_TARGET"
    fi
    ok "Code auf $(describe_rev HEAD)"
}

# Stand im Label des App-Images mit dem Code vergleichen: GitHub baut Images erst einige
# Minuten nach einer Änderung.
check_image_revision() {
    local have want
    have="$(docker image inspect -f '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$(get_env APP_IMAGE)" 2>/dev/null || true)"
    [ "$have" = "<no value>" ] && have=""
    want="$(git_ rev-parse HEAD 2>/dev/null || true)"
    if [ -z "$have" ] || [ -z "$want" ] || [ "$have" = "$want" ]; then
        return 0
    fi
    # Code älter als das Image (fester Stand): passt.
    if git_ merge-base --is-ancestor "$want" "$have" 2>/dev/null; then
        return 0
    fi
    warn "Das App-Image stammt vom Stand ${have:0:7}, der Code ist schon auf ${want:0:7}."
    say "GitHub baut das Image nach jeder Änderung neu, das dauert etwa zehn Minuten. Danach das Update noch einmal starten."
}

update_containers() {
    step "Container aktualisieren"
    ensure_network
    check_name_conflicts
    if uses_ghcr; then
        say "Lade die Images ..."
        docker compose pull </dev/null \
            || die "Die Images ließen sich nicht laden. Die Container laufen mit der bisherigen Version weiter; später erneut versuchen."
        docker compose up -d </dev/null
    else
        say "Baue die Images neu. Das dauert einige Minuten ..."
        docker compose pull db </dev/null \
            || warn "Das Datenbank-Image ließ sich nicht aktualisieren; es bleibt das vorhandene."
        docker compose up -d --build </dev/null
    fi
    wait_ready
    if uses_ghcr; then
        check_image_revision
    fi
}

# Einstellungen aus .env.example, die in .env fehlen (es gilt dann der Standardwert aus compose.yaml).
report_new_settings() {
    [ -f .env.example ] || return 0
    local key missing=()
    while IFS= read -r key; do
        grep -Eq "^(# ?)?$key=" .env || missing+=("$key")
    done < <(sed -nE 's/^([A-Z][A-Z0-9_]*)=.*/\1/p' .env.example)
    if [ "${#missing[@]}" -gt 0 ]; then
        say ""
        say "In .env.example gibt es Einstellungen, die in deiner .env fehlen: ${missing[*]}"
        say "Bis du sie einträgst, gilt der Standardwert. Erklärung: README, Abschnitt Umgebungsvariablen."
    fi
}

cleanup_images() {
    local n
    n="$(docker images -q -f dangling=true 2>/dev/null | wc -l)"
    [ "$n" -gt 0 ] || return 0
    say ""
    if ask_yn "$n alte Image-Version(en) ohne Namen liegen noch herum und werden von keinem Container benutzt. Löschen, um Platz zu sparen?" j; then
        docker image prune -f 2>/dev/null | tail -n 1
    fi
}

update_summary() {
    local old="${KW_OLD_REV:-}" now
    now="$(git_ rev-parse HEAD 2>/dev/null || true)"
    step "Fertig"
    if [ -n "$old" ] && [ "$old" != "$now" ]; then
        say "Klausurwerk ist aktualisiert: $(describe_rev "$old") → $(describe_rev HEAD)"
    else
        say "Klausurwerk läuft mit frischen Images."
    fi
    if [ -n "$BACKUP_DIR" ]; then
        say "Sicherung vom Stand davor: $BACKUP_DIR"
    fi
    say "Neue Datenbank-Migrationen laufen beim Start der App von selbst. Bitte einmal anmelden und kurz prüfen."
    say "Falls etwas nicht stimmt: Protokolle mit sudo docker compose logs app, zurück auf den alten Stand"
    say "siehe Handbuch für Admins, Kapitel 2.8."
}

# Rest des Updates nach dem Codewechsel. Läuft im neuen install.sh, damit neue Schritte gleich greifen.
finish_update() {
    update_containers
    report_new_settings
    cleanup_images
    update_summary
}

run_update() {
    [ -f .env ] || die "In $PWD fehlt die .env: Klausurwerk ist hier noch nicht eingerichtet. Zum Einrichten ./install.sh ohne --update starten."
    fetch_code

    local changed=0
    if uses_ghcr; then
        step "Images"
        say "Frage die GitHub Container Registry nach neuen Images ..."
        docker compose pull </dev/null \
            || die "Die Images ließen sich nicht laden. Netzverbindung prüfen. Es wurde nichts verändert."
    fi
    if containers_outdated; then
        changed=1
        if uses_ghcr; then ok "Neue Images geladen"; fi
    elif uses_ghcr; then
        ok "Die Images sind aktuell"
    fi

    if [ -z "$UPDATE_TARGET" ] && [ "$changed" = 0 ]; then
        if uses_ghcr; then
            step "Fertig"
            ok "Klausurwerk ist auf dem neuesten Stand. Nichts zu tun."
            return 0
        fi
        say ""
        say "Ein Neubau holt nur Sicherheits-Updates der Basis-Images (PHP, Java, Python, Node)."
        if ! ask_yn "Trotzdem neu bauen? Das dauert einige Minuten." n; then
            ok "Nichts zu tun."
            return 0
        fi
    fi

    check_running_exams
    say ""
    ask_yn "Jetzt aktualisieren? Die Anwendung ist dabei etwa eine Minute nicht erreichbar." j \
        || die "Abgebrochen. Nichts verändert."
    backup_data

    local old
    old="$(git_ rev-parse HEAD 2>/dev/null || true)"
    if apply_code; then
        # Den Rest erledigt das neue install.sh.
        export KW_UPDATE_STAGE=2 KW_OLD_REV="$old" KW_BACKUP_DIR="$BACKUP_DIR"
        exec bash "$PWD/install.sh" --update
    fi
    KW_OLD_REV="$old"
    finish_update
}

# Bestehende Installation ohne --update gestartet: fragen, was passieren soll.
choose_action() {
    [ -f .env ] || return 0
    step "Vorhandene Installation"
    say "In $PWD ist Klausurwerk schon eingerichtet."
    say "  1) Aktualisieren: neuen Stand holen, Daten und Einstellungen bleiben"
    say "  2) Einrichtung erneut durchlaufen (.env behalten oder neu schreiben)"
    say "  3) Abbrechen"
    local c
    while :; do
        ask c "Auswahl" "1"
        case "$c" in
            1) MODE=update; return 0 ;;
            2) return 0 ;;
            3) say "Abgebrochen. Nichts verändert."; exit 0 ;;
        esac
    done
}

usage() {
    cat <<'EOF'
Klausurwerk: Installation und Update

  ./install.sh            einrichten; in einer bestehenden Installation wird gefragt
  ./install.sh --update   aktualisieren (Kurzform: ./update.sh)
  ./install.sh --help     diese Hilfe

Ohne Klon:
  curl -fsSL https://raw.githubusercontent.com/TheUnknown-0/klausurwerk/main/install.sh | sudo bash
  curl -fsSL https://raw.githubusercontent.com/TheUnknown-0/klausurwerk/main/install.sh | sudo bash -s -- --update
EOF
}

main() {
    while [ $# -gt 0 ]; do
        case "$1" in
            -u|--update|update) MODE=update ;;
            -h|--help|hilfe) usage; exit 0 ;;
            *) die "Unbekannte Option: $1 (Hilfe: ./install.sh --help)" ;;
        esac
        shift
    done

    # Zweiter Teil eines Updates, gestartet vom alten install.sh nach dem Codewechsel.
    if [ "${KW_UPDATE_STAGE:-}" = 2 ]; then
        MODE=update
        BACKUP_DIR="${KW_BACKUP_DIR:-}"
        finish_update
        return 0
    fi

    if [ "$MODE" = update ]; then
        say "${B}Klausurwerk: Update${N}"
    else
        say "${B}Klausurwerk: Installation${N}"
    fi
    check_requirements
    locate_project
    if [ "$MODE" = install ]; then
        choose_action
    fi
    if [ "$MODE" = update ]; then
        run_update
        return 0
    fi
    configure_env
    start_containers
    create_admin
    summary
}

main "$@"
