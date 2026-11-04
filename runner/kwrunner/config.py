"""Konfiguration aus Umgebungsvariablen (einmal beim Start gelesen)."""
from __future__ import annotations

import os
from dataclasses import dataclass, field


def _int(name: str, default: int, lo: int, hi: int) -> int:
    try:
        value = int(os.environ.get(name, "") or default)
    except ValueError:
        value = default
    return max(lo, min(hi, value))


@dataclass
class Config:
    token: str = ""
    port: int = 8080
    work_dir: str = "/work"
    libs_dir: str = "/opt/runner/libs"
    max_parallel: int = 4
    max_seconds: int = 10
    max_memory_mb: int = 256
    # Harte Wandzeit für interaktive Läufe (Programm wartet auf Eingaben).
    interactive_seconds: int = 120
    max_files: int = 20
    max_total_bytes: int = 1024 * 1024
    max_output_bytes: int = 1024 * 1024
    max_queued: int = 64
    abandon_seconds: int = 60
    max_threads: int = 100
    max_disk_bytes: int = 64 * 1024 * 1024
    fsize_bytes: int = 10 * 1024 * 1024
    jdks: dict = field(default_factory=lambda: {"21": "/opt/jdk21", "25": "/opt/jdk25"})
    default_java: str = "21"
    python_bin: str = "python3"
    boot_py: str = ""

    @classmethod
    def from_env(cls) -> "Config":
        here = os.path.dirname(os.path.abspath(__file__))
        return cls(
            token=os.environ.get("RUNNER_TOKEN", ""),
            port=_int("RUNNER_PORT", 8080, 1, 65535),
            work_dir=os.environ.get("RUNNER_WORK_DIR", "/work"),
            libs_dir=os.environ.get("RUNNER_LIBS_DIR", "/opt/runner/libs"),
            max_parallel=_int("RUNNER_MAX_PARALLEL", 4, 1, 32),
            max_seconds=_int("RUNNER_MAX_SECONDS", 10, 1, 120),
            max_memory_mb=_int("RUNNER_MAX_MEMORY_MB", 256, 64, 1024),
            interactive_seconds=_int("RUNNER_INTERACTIVE_SECONDS", 120, 5, 600),
            boot_py=os.path.join(here, "boot_py.py"),
        )

    # Bibliotheken (Erweiterungspunkt für spätere Module wie die Robo-Welt).
    def java_libs(self) -> list[str]:
        base = os.path.join(self.libs_dir, "java")
        found: list[str] = []
        if os.path.isdir(base):
            found.append(base)  # lose .class-Dateien bzw. Paketordner
            for name in sorted(os.listdir(base)):
                if name.endswith(".jar"):
                    found.append(os.path.join(base, name))
        return found

    def python_libs(self) -> str:
        return os.path.join(self.libs_dir, "python")
