"""Prozess-Isolation: ein Kindprozess pro Lauf mit Grenzen und Überwachung.

Der Container ist die äußere Mauer (kein Netz, read_only, cap_drop, pids_limit).
Hier kommt die innere dazu: eigene Prozessgruppe je Lauf, rlimits über ``prlimit``,
ein Wächter-Thread für Wandzeit, CPU-Zeit, Threads, Speicher, Plattenplatz und
Ausgabemenge, leere Umgebung (der RUNNER_TOKEN gelangt nie in Kindprozesse).

Läuft der Runner als root (so im Container, mit wenigen Capabilities), bekommt jeder
Lauf eine eigene UID/GID aus einem Pool (20000 bis 20063). Das Laufverzeichnis gehört
dieser UID (0700), ``/work`` selbst ist 0711 (nicht listbar), ``RLIMIT_NPROC`` gilt je Lauf,
und ``/proc/1/environ`` (Token) sowie fremde Läufe sind für Schülercode nicht lesbar.
Ohne root (Entwicklungsrechner, Unit-Tests) entfällt der Wechsel.
"""
from __future__ import annotations

import codecs
import fcntl
import os
import shutil
import signal
import subprocess
import sys
import threading
import time
from dataclasses import dataclass

UID_BASE = int(os.environ.get("RUNNER_UID_BASE", "20000") or 20000)
UID_COUNT = 64
CLK_TCK = os.sysconf("SC_CLK_TCK") if hasattr(os, "sysconf") else 100
PAGE = os.sysconf("SC_PAGE_SIZE") if hasattr(os, "sysconf") else 4096


class RunnerError(Exception):
    """Fehler mit HTTP-Status und deutscher Meldung für den Aufrufer."""

    def __init__(self, status: int, message: str):
        super().__init__(message)
        self.status = status
        self.message = message


@dataclass
class Limits:
    cpu_seconds: int = 10
    wall_seconds: int = 12
    rss_mb: int = 640
    max_output: int = 1024 * 1024
    max_threads: int = 100
    max_procs: int = 40
    max_disk: int = 64 * 1024 * 1024
    fsize: int = 10 * 1024 * 1024
    nproc: int = 300
    nofile: int = 512
    # Obergrenze für die Anzahl Chunks (schützt den Speicher des Runners selbst).
    max_chunks: int = 50000


NOTES = {
    "wall": "Zeitlimit überschritten: Das Programm wurde nach {wall} Sekunden beendet.",
    "cpu": "Rechenzeit überschritten: Das Programm hat mehr als {cpu} Sekunden gerechnet "
           "und wurde beendet (Endlosschleife?).",
    "threads": "Zu viele Threads oder Prozesse gleichzeitig (Grenze {threads}). "
               "Das Programm wurde beendet.",
    "memory": "Zu viel Arbeitsspeicher belegt. Das Programm wurde beendet.",
    "disk": "Zu viele Daten auf die Festplatte geschrieben. Das Programm wurde beendet.",
    "output": "Die Ausgabe ist zu lang (Grenze {output_kb} KB). Das Programm wurde beendet.",
    "stopped": "Das Programm wurde beendet.",
}
TIMEOUT_REASONS = ("wall", "cpu")


def group_stats(pgid: int) -> tuple[int, int, int, int]:
    """(Prozesse, Threads, RSS in Bytes, CPU-Ticks) aller lebenden Prozesse der Gruppe."""
    procs = threads = rss = ticks = 0
    try:
        names = os.listdir("/proc")
    except OSError:
        return 0, 0, 0, 0
    for name in names:
        if not name.isdigit():
            continue
        try:
            with open(f"/proc/{name}/stat", "rb") as handle:
                raw = handle.read().decode("ascii", "replace")
            fields = raw[raw.rindex(")") + 2:].split()
            if int(fields[2]) != pgid or fields[0] == "Z":
                continue
            procs += 1
            threads += int(fields[17])
            rss += int(fields[21]) * PAGE
            ticks += int(fields[11]) + int(fields[12])
        except (OSError, ValueError, IndexError):
            continue
    return procs, threads, rss, ticks


def dir_usage(path: str, entry_cap: int = 4000) -> tuple[int, int]:
    """(Bytes, Einträge) eines Verzeichnisbaums; bricht bei zu vielen Einträgen ab."""
    total = count = 0
    stack = [path]
    while stack:
        current = stack.pop()
        try:
            with os.scandir(current) as it:
                for entry in it:
                    count += 1
                    if count > entry_cap:
                        return total, count
                    try:
                        if entry.is_dir(follow_symlinks=False):
                            stack.append(entry.path)
                        else:
                            total += entry.stat(follow_symlinks=False).st_size
                    except OSError:
                        pass
        except OSError:
            pass
    return total, count


def isolation_active() -> bool:
    """True, wenn jeder Lauf unter eigener UID startet (Runner läuft als root)."""
    return hasattr(os, "geteuid") and os.geteuid() == 0 and os.environ.get("RUNNER_ISOLATE", "1") != "0"


SEAL_PY = os.path.join(os.path.dirname(os.path.abspath(__file__)), "seal.py")


def seal_active() -> bool:
    """True, wenn Läufe mit seccomp-Filter starten (Linux auf x86_64/aarch64, abschaltbar mit RUNNER_SEAL=0)."""
    if not sys.platform.startswith("linux") or os.environ.get("RUNNER_SEAL", "1") == "0":
        return False
    return hasattr(os, "uname") and os.uname().machine in ("x86_64", "aarch64")


class UidPool:
    """Pool laufbezogener UIDs. Jede UID gehört zu höchstens einem Lauf zugleich."""

    def __init__(self, base: int = UID_BASE, count: int = UID_COUNT):
        self._free = list(range(base, base + count))
        self._cond = threading.Condition()

    def acquire(self, timeout: float = 5.0) -> int | None:
        deadline = time.monotonic() + timeout
        with self._cond:
            while not self._free:
                left = deadline - time.monotonic()
                if left <= 0:
                    return None
                self._cond.wait(left)
            return self._free.pop(0)

    def release(self, uid: int) -> None:
        with self._cond:
            if uid not in self._free:
                self._free.append(uid)
                self._cond.notify()


UIDS = UidPool()


def kill_uid(uid: int) -> None:
    """Alle Prozesse einer Lauf-UID beenden (auch solche, die die Sitzung verlassen haben)."""
    for _ in range(20):
        found = False
        try:
            names = os.listdir("/proc")
        except OSError:
            return
        for name in names:
            if not name.isdigit():
                continue
            try:
                if os.stat(f"/proc/{name}").st_uid == uid:
                    found = True
                    os.kill(int(name), signal.SIGKILL)
            except OSError:
                continue
        if not found:
            return
        time.sleep(0.05)


def purge_uid_files(uid: int) -> None:
    """Dateien der Lauf-UID in allgemein beschreibbaren Verzeichnissen entfernen,
    damit der nächste Lauf mit derselben UID nichts davon erbt."""
    for base in ("/tmp", "/dev/shm"):
        try:
            entries = list(os.scandir(base))
        except OSError:
            continue
        for entry in entries:
            try:
                if entry.stat(follow_symlinks=False).st_uid != uid:
                    continue
                if entry.is_dir(follow_symlinks=False):
                    shutil.rmtree(entry.path, ignore_errors=True)
                else:
                    os.unlink(entry.path)
            except OSError:
                pass


class RunIdentity:
    """Kontext: reserviert eine Lauf-UID (oder ``None`` ohne Isolation) und räumt danach auf."""

    def __init__(self):
        self.uid: int | None = None

    def __enter__(self) -> int | None:
        if isolation_active():
            self.uid = UIDS.acquire(5.0)
            if self.uid is None:
                raise RunnerError(429, "Die Ausführungsumgebung ist gerade ausgelastet. Bitte versuche es gleich noch einmal.")
        return self.uid

    def __exit__(self, *exc) -> None:
        if self.uid is not None:
            kill_uid(self.uid)
            purge_uid_files(self.uid)
            UIDS.release(self.uid)


def chown_tree(path: str, uid: int | None) -> None:
    """Datei oder Verzeichnisbaum der Lauf-UID übereignen (Symlinks nicht verfolgen)."""
    if uid is None:
        return
    try:
        os.lchown(path, uid, uid)
    except OSError:
        return
    if os.path.isdir(path) and not os.path.islink(path):
        for root, dirs, files in os.walk(path):
            for name in dirs + files:
                try:
                    os.lchown(os.path.join(root, name), uid, uid)
                except OSError:
                    pass


CHILDREN: set = set()  # PIDs, die ein Proc selbst einsammelt (nicht von reap_orphans anfassen)


def reap_orphans() -> None:
    """Zombies einsammeln, die verwaiste Enkelprozesse hinterlassen (der Runner ist PID 1)."""
    me = os.getpid()
    try:
        names = os.listdir("/proc")
    except OSError:
        return
    for name in names:
        if not name.isdigit() or int(name) in CHILDREN:
            continue
        try:
            with open(f"/proc/{name}/stat", "rb") as handle:
                raw = handle.read().decode("ascii", "replace")
            fields = raw[raw.rindex(")") + 2:].split()
            if fields[0] == "Z" and int(fields[1]) == me:
                os.waitpid(int(name), os.WNOHANG)
        except (OSError, ValueError, IndexError):
            continue


def group_members(pgid: int) -> int:
    """Anzahl aller Prozesse der Gruppe, auch Zombies (die zählen für RLIMIT_NPROC mit)."""
    count = 0
    try:
        names = os.listdir("/proc")
    except OSError:
        return 0
    for name in names:
        if not name.isdigit():
            continue
        try:
            with open(f"/proc/{name}/stat", "rb") as handle:
                raw = handle.read().decode("ascii", "replace")
            if int(raw[raw.rindex(")") + 2:].split()[2]) == pgid:
                count += 1
        except (OSError, ValueError, IndexError):
            continue
    return count


def reap_loop() -> None:
    while True:
        time.sleep(2.0)
        reap_orphans()


class Proc:
    """Ein überwachter Kindprozess mit Ausgabe als Chunk-Liste ``{stream, text}``."""

    def __init__(self, argv: list[str], cwd: str, env: dict, limits: Limits,
                 stdin_data: bytes | None = None, interactive: bool = False,
                 watch_dirs: tuple = (), pass_fds: tuple = (), uid: int | None = None):
        self.uid = uid
        self.argv = argv
        self.cwd = cwd
        self.env = env
        self.limits = limits
        self.stdin_data = stdin_data
        self.interactive = interactive
        self.watch_dirs = tuple(watch_dirs)
        self.pass_fds = pass_fds
        self.chunks: list[dict] = []
        self.lock = threading.Lock()
        self.bytes_out = 0
        self.exit_code: int | None = None
        self.reason: str | None = None
        self.started = 0.0
        self.duration_ms = 0
        self.done = threading.Event()
        self._popen: subprocess.Popen | None = None
        self._stdin_fd: int | None = None
        self._readers: list[threading.Thread] = []

    # --- Start -------------------------------------------------------------
    def start(self) -> None:
        lim = self.limits
        prefix = [
            "prlimit",
            f"--cpu={lim.cpu_seconds + 1}:{lim.cpu_seconds + 2}",
            f"--fsize={lim.fsize}",
            f"--nproc={lim.nproc}",
            f"--nofile={lim.nofile}",
            "--core=0",
        ]
        argv = list(self.argv)
        # Java legt Temp-Dateien sonst im gemeinsamen /tmp an (für Lauf-UIDs gesperrt).
        if argv and argv[0].endswith("/bin/java") and self.env.get("TMPDIR"):
            argv.insert(1, "-Djava.io.tmpdir=" + self.env["TMPDIR"])
        if seal_active():
            prefix += [sys.executable, "-I", "-S", SEAL_PY]
        piped = self.interactive or self.stdin_data is not None
        self.started = time.monotonic()
        try:
            self._popen = subprocess.Popen(
                prefix + argv, cwd=self.cwd, env=self.env, bufsize=0,
                stdin=subprocess.PIPE if piped else subprocess.DEVNULL,
                stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                start_new_session=True, close_fds=True, pass_fds=self.pass_fds,
                **user_kwargs(self.uid),
            )
        except OSError as exc:
            raise RunnerError(503, "Der Prozess konnte nicht gestartet werden (" + str(exc.strerror) + ").") from exc
        popen = self._popen
        CHILDREN.add(popen.pid)
        if piped:
            self._stdin_fd = popen.stdin.fileno()
            if self.interactive:
                flags = fcntl.fcntl(self._stdin_fd, fcntl.F_GETFL)
                fcntl.fcntl(self._stdin_fd, fcntl.F_SETFL, flags | os.O_NONBLOCK)
            if self.stdin_data is not None:
                threading.Thread(target=self._feed_stdin, daemon=True).start()
        for stream, pipe in (("stdout", popen.stdout), ("stderr", popen.stderr)):
            thread = threading.Thread(target=self._read, args=(stream, pipe.fileno()), daemon=True)
            thread.start()
            self._readers.append(thread)
        threading.Thread(target=self._watch, daemon=True).start()

    def _feed_stdin(self) -> None:
        try:
            view = memoryview(self.stdin_data or b"")
            fd = self._stdin_fd
            while len(view) and fd is not None:
                written = os.write(fd, view[:65536])
                view = view[written:]
        except (OSError, ValueError):
            pass
        finally:
            self._close_stdin()

    def _close_stdin(self) -> None:
        try:
            if self._popen and self._popen.stdin:
                self._popen.stdin.close()
        except (OSError, ValueError):
            pass

    # --- Eingabe -----------------------------------------------------------
    def write_stdin(self, text: str) -> None:
        if self.done.is_set() or self._popen is None or self._popen.poll() is not None:
            raise RunnerError(409, "Das Programm läuft nicht mehr.")
        if not self.interactive or self._stdin_fd is None:
            raise RunnerError(409, "Dieser Lauf nimmt keine Eingaben an.")
        data = text.encode("utf-8", "replace")
        if len(data) > 65536:
            raise RunnerError(413, "Die Eingabe ist zu lang.")
        deadline = time.monotonic() + 1.0
        view = memoryview(data)
        while len(view):
            try:
                written = os.write(self._stdin_fd, view)
                view = view[written:]
            except BlockingIOError:
                if time.monotonic() > deadline:
                    raise RunnerError(409, "Das Programm liest die Eingabe gerade nicht.")
                time.sleep(0.01)
            except (OSError, ValueError):
                raise RunnerError(409, "Das Programm läuft nicht mehr.")

    # --- Ausgabe -----------------------------------------------------------
    def _add(self, stream: str, text: str) -> None:
        if not text:
            return
        with self.lock:
            if len(self.chunks) >= self.limits.max_chunks and stream != "system":
                return
            self.chunks.append({"stream": stream, "text": text})

    def _read(self, stream: str, fd: int) -> None:
        decoder = codecs.getincrementaldecoder("utf-8")(errors="replace")
        try:
            while True:
                data = os.read(fd, 8192)
                if not data:
                    break
                with self.lock:
                    self.bytes_out += len(data)
                    over = self.bytes_out > self.limits.max_output
                    if over:
                        allowed = len(data) - (self.bytes_out - self.limits.max_output)
                        data = data[:max(0, allowed)]
                self._add(stream, decoder.decode(data))
                if over:
                    self.kill("output")
                    break
            self._add(stream, decoder.decode(b"", final=True))
        except (OSError, ValueError):
            pass

    # --- Überwachung -------------------------------------------------------
    def kill(self, reason: str) -> None:
        with self.lock:
            if self.reason is None:
                self.reason = reason
        if self._popen is not None:
            try:
                os.killpg(self._popen.pid, signal.SIGKILL)
            except OSError:
                try:
                    self._popen.kill()
                except OSError:
                    pass

    def _watch(self) -> None:
        popen = self._popen
        lim = self.limits
        pgid = popen.pid
        last_disk = 0.0
        try:
            while popen.poll() is None:
                now = time.monotonic()
                if self.reason is None:
                    if now - self.started > lim.wall_seconds:
                        self.kill("wall")
                    else:
                        procs, threads, rss, ticks = group_stats(pgid)
                        if threads > lim.max_threads or procs > lim.max_procs:
                            self.kill("threads")
                        elif rss > lim.rss_mb * 1024 * 1024:
                            self.kill("memory")
                        elif ticks / CLK_TCK > lim.cpu_seconds:
                            self.kill("cpu")
                        elif self.watch_dirs and now - last_disk > 0.5:
                            last_disk = now
                            size = count = 0
                            for directory in self.watch_dirs:
                                part_size, part_count = dir_usage(directory)
                                size += part_size
                                count += part_count
                            if size > lim.max_disk or count > 4000:
                                self.kill("disk")
                time.sleep(0.05)
            code = popen.returncode
            # Reste der Prozessgruppe (Hintergrund-Threads, Kindprozesse) beseitigen.
            try:
                os.killpg(pgid, signal.SIGKILL)
            except OSError:
                pass
            # Zombies verwaister Enkel sofort einsammeln, sie zählen sonst noch einige Sekunden
            # gegen das Prozesslimit und würden den nächsten Lauf ausbremsen.
            for _ in range(60):
                if group_members(pgid) == 0:
                    break
                reap_orphans()
                time.sleep(0.05)
            if code == -signal.SIGXCPU and self.reason is None:
                self.reason = "cpu"
            for thread in self._readers:
                thread.join(3.0)
            self.exit_code = code if code >= 0 else 128 - code
            if self.reason in NOTES:
                self._add("system", NOTES[self.reason].format(
                    wall=lim.wall_seconds, cpu=lim.cpu_seconds, threads=lim.max_threads,
                    output_kb=lim.max_output // 1024) + "\n")
            self._close_stdin()
        finally:
            self.duration_ms = int((time.monotonic() - self.started) * 1000)
            CHILDREN.discard(popen.pid)
            self.done.set()

    # --- Abfrage -----------------------------------------------------------
    @property
    def timed_out(self) -> bool:
        return self.reason in TIMEOUT_REASONS

    def wait(self, timeout: float | None = None) -> bool:
        return self.done.wait(timeout)

    def text(self, stream: str) -> str:
        with self.lock:
            return "".join(c["text"] for c in self.chunks if c["stream"] == stream)


def user_kwargs(uid: int | None) -> dict:
    """Popen-Argumente für den Wechsel auf die Lauf-UID (setgroups([]), setgid, setuid vor exec)."""
    if uid is None:
        return {}
    return {"user": uid, "group": uid, "extra_groups": []}


def clean_env(home: str, tmp: str, extra: dict | None = None) -> dict:
    """Leere Umgebung: nichts vom Runner (Token!) wird weitergegeben."""
    env = {
        "PATH": "/usr/local/bin:/usr/bin:/bin",
        "HOME": home,
        "TMPDIR": tmp,
        "LANG": "C.UTF-8",
        "LC_ALL": "C.UTF-8",
        "TZ": os.environ.get("TZ", "Europe/Berlin"),
        "PYTHONDONTWRITEBYTECODE": "1",
        "PYTHONIOENCODING": "utf-8",
        "PYTHONUNBUFFERED": "1",
    }
    if extra:
        env.update(extra)
    return env


def make_private_dir(path: str, uid: int | None = None) -> str:
    os.makedirs(path, mode=0o700, exist_ok=True)
    os.chmod(path, 0o700)
    if uid is not None:
        os.chown(path, uid, uid)
    return path


def remove_tree(path: str) -> None:
    shutil.rmtree(path, ignore_errors=True)
