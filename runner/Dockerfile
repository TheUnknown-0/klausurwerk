# Klausurwerk-Runner: führt Java (21 und 25), Python 3 und SQLite abgeschottet aus.
# Die Einschränkungen (read_only, tmpfs, cap_drop/cap_add, Limits) setzt compose.yaml.
FROM eclipse-temurin:21-jdk AS jdk21
FROM eclipse-temurin:25-jdk AS jdk25

# Temurin basiert auf Ubuntu 24.04 (glibc), die Binaries passen also zu dieser Basis.
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    LANG=C.UTF-8 \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

# python3 inkl. sqlite3-Modul; util-linux liefert prlimit (Ressourcengrenzen je Lauf);
# tzdata nur für TZ=Europe/Berlin aus compose.yaml.
RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 util-linux tzdata \
    && python3 -c "import sqlite3" \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /work

COPY --from=jdk21 /opt/java/openjdk /opt/jdk21
COPY --from=jdk25 /opt/java/openjdk /opt/jdk25

COPY kwrunner /opt/runner/kwrunner
COPY libs /opt/runner/libs
# Bibliotheken (Robo, kw.gui) vorübersetzen: spart bei jedem Lauf rund eine
# Sekunde. --release 21 läuft auf beiden JDKs; javac nimmt bei Quelle und
# Klasse im Klassenpfad die neuere Datei, also die vorübersetzte Klasse.
RUN find /opt/runner/libs/java -name '*.java' > /tmp/libs.txt     && if [ -s /tmp/libs.txt ]; then /opt/jdk21/bin/javac --release 21 -encoding UTF-8 -nowarn -d /opt/runner/libs/java @/tmp/libs.txt; fi     && rm -f /tmp/libs.txt     && find /opt/runner/libs -name '__pycache__' -prune -exec rm -rf {} +

ENV RUNNER_LIBS_DIR=/opt/runner/libs \
    RUNNER_WORK_DIR=/work \
    RUNNER_PORT=8080

# Der Hauptprozess bleibt root, aber nur mit SETUID, SETGID, CHOWN, FOWNER, DAC_OVERRIDE und KILL
# (compose.yaml: cap_drop ALL, no-new-privileges, read_only). Jeder Lauf wechselt vor exec auf
# eine eigene UID aus 20000 bis 20063 (setgroups([]), setgid, setuid), siehe kwrunner/sandbox.py.
WORKDIR /work
EXPOSE 8080

# Schreibt nur nach /tmp und /work (tmpfs); Schülercode läuft nie als root.
CMD ["python3", "-I", "-B", "/opt/runner/kwrunner/main.py"]
