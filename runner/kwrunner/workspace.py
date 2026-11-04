"""Dateien eines Laufs prüfen und ins Arbeitsverzeichnis schreiben."""
from __future__ import annotations

import os
import re

from .config import Config
from .sandbox import RunnerError, chown_tree

NAME_RE = re.compile(r"^[A-Za-z0-9_.-]{1,80}$")


def validate_files(raw, cfg: Config) -> list[tuple[str, str]]:
    """Liefert [(Name, Inhalt)]; wirft RunnerError(400) mit deutscher Meldung."""
    if not isinstance(raw, list) or not raw:
        raise RunnerError(400, "Es wurden keine Dateien übergeben.")
    if len(raw) > cfg.max_files:
        raise RunnerError(400, f"Es sind höchstens {cfg.max_files} Dateien erlaubt.")
    seen: set[str] = set()
    out: list[tuple[str, str]] = []
    total = 0
    for item in raw:
        if not isinstance(item, dict):
            raise RunnerError(400, "Ungültige Dateiangabe.")
        name = item.get("name")
        content = item.get("content", "")
        if not isinstance(name, str) or not NAME_RE.match(name) or name in (".", "..") or name.startswith("."):
            raise RunnerError(400, "Ungültiger Dateiname. Erlaubt sind Buchstaben, Ziffern, Punkt, Unterstrich und Bindestrich.")
        if not isinstance(content, str):
            raise RunnerError(400, "Der Inhalt von " + name + " ist kein Text.")
        if name in seen:
            raise RunnerError(400, "Die Datei " + name + " kommt doppelt vor.")
        seen.add(name)
        total += len(content.encode("utf-8", "replace"))
        if total > cfg.max_total_bytes:
            raise RunnerError(413, "Die Dateien sind zusammen größer als 1 MB.")
        out.append((name, content))
    return out


def write_files(directory: str, files: list[tuple[str, str]], uid: int | None = None) -> None:
    for name, content in files:
        path = os.path.join(directory, name)
        # Namen sind validiert (kein Pfad), trotzdem gegen Ausbruch absichern.
        if os.path.dirname(os.path.abspath(path)) != os.path.abspath(directory):
            raise RunnerError(400, "Ungültiger Dateiname.")
        with open(path, "w", encoding="utf-8", errors="replace", newline="") as handle:
            handle.write(content)
        os.chmod(path, 0o600)
        chown_tree(path, uid)


def clamp_limits(raw, cfg: Config) -> tuple[int, int]:
    """(Sekunden, Speicher MB) aus der Anfrage, nie über den Umgebungsgrenzen."""
    seconds, memory = cfg.max_seconds, cfg.max_memory_mb
    if isinstance(raw, dict):
        try:
            s = int(raw.get("seconds", seconds))
            seconds = max(1, min(cfg.max_seconds, s))
        except (TypeError, ValueError):
            pass
        try:
            m = int(raw.get("memory_mb", memory))
            memory = max(32, min(cfg.max_memory_mb, m))
        except (TypeError, ValueError):
            pass
    return seconds, memory
