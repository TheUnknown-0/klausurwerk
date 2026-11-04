# Klausurwerk-Runner: führt Java (21 und 25), Python 3 und SQLite abgeschottet aus.
# Die Einschränkungen (read_only, tmpfs, cap_drop, Limits) setzt compose.yaml.
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
    && useradd --system --uid 10001 --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin runner \
    && mkdir -p /work

COPY --from=jdk21 /opt/java/openjdk /opt/jdk21
COPY --from=jdk25 /opt/java/openjdk /opt/jdk25

COPY kwrunner /opt/runner/kwrunner
COPY libs /opt/runner/libs

ENV RUNNER_LIBS_DIR=/opt/runner/libs \
    RUNNER_WORK_DIR=/work \
    RUNNER_PORT=8080

USER 10001:10001
WORKDIR /work
EXPOSE 8080

# Läuft als unprivilegierter Benutzer, schreibt nur nach /tmp und /work (tmpfs).
CMD ["python3", "-I", "-B", "/opt/runner/kwrunner/main.py"]
