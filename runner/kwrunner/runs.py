"""Interaktive Läufe: Warteschlange, Übersetzen, Starten, Abruf, Aufräumen."""
from __future__ import annotations

import os
import secrets
import threading
import time

from . import javatools
from .config import Config
from .modules._guifilter import GuiView
from .sandbox import Limits, Proc, RunnerError, clean_env, make_private_dir, remove_tree
from .workspace import clamp_limits, validate_files, write_files

LANGUAGES = ("java", "python")
MODES = ("run", "syntax")


class Run:
    def __init__(self, run_id: str):
        self.id = run_id
        self.state = "queued"
        self.proc: Proc | None = None
        self.own_chunks: list[dict] = []
        self.gui_view: GuiView | None = None  # nur bei gui: true (kw.gui, siehe modules/_guifilter.py)
        self.compile_errors: list[dict] = []
        self.compile_ok: bool | None = None
        self.exit_code: int | None = None
        self.timed_out = False
        self.message: str | None = None
        self.reason: str | None = None
        self.finished = False
        self.cancelled = False
        self.abandoned = False
        self.last_access = time.monotonic()
        self.lock = threading.Lock()

    def touch(self) -> None:
        self.last_access = time.monotonic()


class RunManager:
    def __init__(self, cfg: Config):
        self.cfg = cfg
        self.runs: dict[str, Run] = {}
        self.lock = threading.Lock()
        self.slots = threading.BoundedSemaphore(cfg.max_parallel)
        threading.Thread(target=self._reaper, daemon=True).start()

    # --- Slots (auch für /tests) ------------------------------------------
    def acquire_slot(self, timeout: float, run: Run | None = None) -> bool:
        deadline = time.monotonic() + timeout
        while True:
            if self.slots.acquire(timeout=0.25):
                return True
            if run is not None and run.cancelled:
                return False
            if time.monotonic() > deadline:
                return False
            if run is not None:
                run.touch()

    def release_slot(self) -> None:
        try:
            self.slots.release()
        except ValueError:
            pass

    # --- Start -------------------------------------------------------------
    def start(self, body: dict) -> str:
        cfg = self.cfg
        language = body.get("language", "java")
        if language not in LANGUAGES:
            raise RunnerError(400, "Die Sprache wird für interaktive Läufe nicht unterstützt (java oder python).")
        mode = body.get("mode", "run")
        if mode not in MODES:
            raise RunnerError(400, "Ungültiger Modus (run oder syntax).")
        if language == "python" and mode == "syntax":
            raise RunnerError(400, "Der Modus syntax gibt es nur für Java.")
        files = validate_files(body.get("files"), cfg)
        main_file = body.get("main_file")
        if main_file is not None and not isinstance(main_file, str):
            raise RunnerError(400, "Ungültige Startdatei.")
        home = None
        cls = None
        if language == "java":
            _, home = javatools.java_home(cfg, body.get("java_version"))
            if mode == "run":
                cls = javatools.main_class(files, main_file)
        else:
            if main_file not in dict(files):
                raise RunnerError(400, "Die Startdatei (main_file) fehlt.")
        stdin = body.get("stdin")
        if stdin is not None and not isinstance(stdin, str):
            raise RunnerError(400, "Ungültige Eingabe (stdin).")
        if isinstance(stdin, str) and len(stdin.encode("utf-8", "replace")) > 256 * 1024:
            raise RunnerError(413, "Die vorgegebene Eingabe ist zu lang.")
        seconds, memory = clamp_limits(body.get("limits"), cfg)

        with self.lock:
            active = sum(1 for r in self.runs.values() if not r.finished)
            if active >= cfg.max_queued:
                raise RunnerError(429, "Die Ausführungsumgebung ist gerade ausgelastet. Bitte versuche es gleich noch einmal.")
            run_id = "r_" + secrets.token_hex(12)
            run = Run(run_id)
            self.runs[run_id] = run
        job = dict(language=language, mode=mode, files=files, main_file=main_file, home=home, cls=cls,
                   stdin=stdin if stdin else None, seconds=seconds, memory=memory,
                   gui=bool(body.get("gui")) and language == "java")
        threading.Thread(target=self._execute, args=(run, job), daemon=True).start()
        return run_id

    # --- Ausführung ----------------------------------------------------------
    def _execute(self, run: Run, job: dict) -> None:
        cfg = self.cfg
        work = os.path.join(cfg.work_dir, run.id)
        home_dir = os.path.join("/tmp", run.id)
        have_slot = False
        try:
            have_slot = self.acquire_slot(10 * 3600, run)
            if not have_slot:
                return
            run.state = "compiling" if job["language"] == "java" else "running"
            make_private_dir(work)
            make_private_dir(home_dir)
            tmp = make_private_dir(os.path.join(work, ".tmp"))
            write_files(work, job["files"])
            if job["language"] == "java":
                self._run_java(run, job, work, home_dir, tmp)
            else:
                self._run_python(run, job, work, home_dir, tmp)
        except RunnerError as exc:
            run.message = exc.message
            run.own_chunks.append({"stream": "system", "text": exc.message + "\n"})
            run.exit_code = 1
        except Exception as exc:  # unerwartet: Lauf sauber beenden statt hängen zu lassen
            run.message = "Interner Fehler in der Ausführungsumgebung."
            run.own_chunks.append({"stream": "system", "text": run.message + " (" + type(exc).__name__ + ")\n"})
            run.exit_code = 1
        finally:
            if have_slot:
                self.release_slot()
            remove_tree(work)
            remove_tree(home_dir)
            if run.proc is not None:
                run.exit_code = run.proc.exit_code if run.exit_code is None else run.exit_code
                run.timed_out = run.proc.timed_out
                run.reason = run.proc.reason
            run.state = "finished"
            run.finished = True
            run.touch()

    def _limits(self, job: dict, interactive: bool) -> Limits:
        cfg = self.cfg
        seconds = job["seconds"]
        # Mit vorgegebener Eingabe wartet nichts auf Menschen: kurze Wandzeit.
        wall = cfg.interactive_seconds if interactive else seconds + 5
        return Limits(cpu_seconds=seconds, wall_seconds=wall, rss_mb=javatools.rss_limit_mb(job["memory"]),
                      max_output=cfg.max_output_bytes * (4 if job.get("gui") else 1), max_threads=cfg.max_threads,
                      max_disk=cfg.max_disk_bytes, fsize=cfg.fsize_bytes)

    def _run_java(self, run: Run, job: dict, work: str, home_dir: str, tmp: str) -> None:
        cfg = self.cfg
        home = job["home"]
        out_dir = os.path.join(work, ".out")
        sources = [n for n, _ in job["files"] if n.endswith(".java")]
        if not sources:
            raise RunnerError(400, "Es gibt keine Java-Datei (.java) zu übersetzen.")
        ok, raw, errors = javatools.run_compile(cfg, home, work, home_dir, sources, out_dir)
        run.compile_ok = ok
        if not ok:
            run.compile_errors = errors
            run.exit_code = 1
            if not any(e["line"] for e in errors):
                run.own_chunks.append({"stream": "stderr", "text": errors[0]["message"] + "\n"})
            return
        if job["mode"] == "syntax":
            run.exit_code = 0
            return
        if run.cancelled:
            return
        run.state = "running"
        argv = javatools.java_run_argv(cfg, home, out_dir, job["cls"], job["memory"])
        env = clean_env(home_dir, tmp, {"JAVA_HOME": home})
        self._supervise(run, job, argv, work, env, home_dir)

    def _run_python(self, run: Run, job: dict, work: str, home_dir: str, tmp: str) -> None:
        cfg = self.cfg
        argv = [cfg.python_bin, "-I", "-B", cfg.boot_py, "run", job["main_file"], cfg.python_libs(),
                str(job["memory"] + 200)]
        env = clean_env(home_dir, tmp)
        self._supervise(run, job, argv, work, env, home_dir)

    def _supervise(self, run: Run, job: dict, argv: list[str], work: str, env: dict, home_dir: str) -> None:
        stdin = job["stdin"]
        interactive = stdin is None
        proc = Proc(argv, work, env, self._limits(job, interactive),
                    stdin_data=None if interactive else stdin.encode("utf-8", "replace"),
                    interactive=interactive, watch_dirs=(work, home_dir))
        run.proc = proc
        if job.get("gui"):
            run.gui_view = GuiView()
        proc.start()
        while not proc.wait(0.25):
            if run.cancelled:
                proc.kill("stopped")

    # --- Abruf ---------------------------------------------------------------
    def get(self, run_id: str) -> Run:
        run = self.runs.get(run_id)
        if run is None:
            raise RunnerError(404, "Dieser Lauf existiert nicht (mehr).")
        run.touch()
        return run

    def snapshot(self, run_id: str, offset: int) -> dict:
        run = self.get(run_id)
        offset = max(0, offset)
        finished = run.finished  # zuerst lesen: ist es True, ist die Ausgabe vollständig
        proc = run.proc
        view = run.gui_view
        if view is not None and proc is not None:
            # GUI-Lauf: Frames aus der Ausgabe ziehen (modules/_guifilter.py), ein gemeinsamer Offset.
            with run.lock:
                with proc.lock:
                    raw = list(proc.chunks)
                view.update(raw)
                if finished:
                    view.close(run.own_chunks)
                output, frames, total = view.slice(offset)
            return {
                "output": output,
                "offset": total,
                "finished": finished,
                "status": run.state,
                "exit_code": run.exit_code if finished else None,
                "timed_out": run.timed_out if finished else False,
                "killed_reason": run.reason if finished else None,
                "compile_ok": run.compile_ok,
                "compile_errors": run.compile_errors,
                "message": run.message,
                "gui_frames": frames,
            }
        total = 0
        chunks: list[dict] = []
        if proc is not None:
            with proc.lock:
                chunks = proc.chunks[offset:]
                total = len(proc.chunks)
        if finished and run.own_chunks:
            # Eigene Hinweise (z. B. Compilerausgabe ohne Zeilenbezug) hinter die Prozessausgabe.
            chunks = chunks + run.own_chunks[max(0, offset - total):]
            total += len(run.own_chunks)
        if proc is None and not finished:
            total = offset
        return {
            "output": chunks,
            "offset": total,
            "finished": finished,
            "status": run.state,
            "exit_code": run.exit_code if finished else None,
            "timed_out": run.timed_out if finished else False,
            "killed_reason": run.reason if finished else None,
            "compile_ok": run.compile_ok,
            "compile_errors": run.compile_errors,
            "message": run.message,
            "gui_frames": [],
        }

    def stdin(self, run_id: str, text: str) -> None:
        run = self.get(run_id)
        if run.finished or run.proc is None:
            raise RunnerError(409, "Das Programm läuft nicht (mehr).")
        run.proc.write_stdin(text)

    def stop(self, run_id: str) -> None:
        run = self.get(run_id)
        run.cancelled = True
        if run.proc is not None:
            run.proc.kill("stopped")

    # --- Aufräumen -------------------------------------------------------------
    def _reaper(self) -> None:
        while True:
            time.sleep(2.0)
            now = time.monotonic()
            with self.lock:
                items = list(self.runs.items())
            for run_id, run in items:
                if run.finished and run.abandoned:
                    with self.lock:
                        self.runs.pop(run_id, None)
                    continue
                idle = now - run.last_access
                if idle <= self.cfg.abandon_seconds:
                    continue
                if not run.finished:
                    run.abandoned = True
                    run.cancelled = True
                    if run.proc is not None:
                        run.proc.kill("abandoned")
                    continue  # nach dem Ende im nächsten Durchgang entfernen
                with self.lock:
                    self.runs.pop(run_id, None)
            self._sweep_orphans()

    def _sweep_orphans(self) -> None:
        """Arbeitsverzeichnisse ohne zugehörigen Lauf (z. B. nach Absturz) entfernen."""
        try:
            names = os.listdir(self.cfg.work_dir)
        except OSError:
            return
        for name in names:
            if name.startswith("r_") and name not in self.runs:
                path = os.path.join(self.cfg.work_dir, name)
                try:
                    if time.time() - os.stat(path).st_mtime > 300:
                        remove_tree(path)
                        remove_tree(os.path.join("/tmp", name))
                except OSError:
                    pass
