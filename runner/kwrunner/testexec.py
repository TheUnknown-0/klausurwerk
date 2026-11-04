"""POST /tests: versteckte Tests synchron ausführen, jeder Test isoliert."""
from __future__ import annotations

import json
import os
import secrets
import shutil
import time

from . import javatools
from .compare import compare_output, show
from .config import Config
from .runs import RunManager
from .sandbox import NOTES, Limits, Proc, RunIdentity, RunnerError, chown_tree, clean_env, make_private_dir, remove_tree
from .workspace import clamp_limits, validate_files, write_files

MAX_TESTS = 100
TYPES_BY_LANGUAGE = {
    "java": ("io", "java_main"),
    "python": ("io", "python_assert"),
    "sql": ("sql_result",),
}
STREAM_CAP = 20000


def _clip(text: str) -> str:
    return text if len(text) <= STREAM_CAP else text[:STREAM_CAP] + "\n[... gekürzt]"


def _first_line(text: str, limit: int = 220) -> str:
    for line in text.splitlines():
        if line.strip():
            return line.strip()[:limit]
    return ""


class TestRunner:
    def __init__(self, cfg: Config, manager: RunManager):
        self.cfg = cfg
        self.manager = manager

    # --- Einstieg -------------------------------------------------------------
    def run(self, body: dict) -> dict:
        cfg = self.cfg
        language = body.get("language")
        if language not in TYPES_BY_LANGUAGE:
            raise RunnerError(400, "Die Sprache muss java, python oder sql sein.")
        files = validate_files(body.get("files"), cfg)
        tests = body.get("tests")
        if not isinstance(tests, list) or not tests:
            raise RunnerError(400, "Es wurden keine Tests übergeben.")
        if len(tests) > MAX_TESTS:
            raise RunnerError(400, f"Es sind höchstens {MAX_TESTS} Tests auf einmal erlaubt.")
        main_file = body.get("main_file")
        if main_file is not None and not isinstance(main_file, str):
            raise RunnerError(400, "Ungültige Startdatei.")
        if language != "java" and main_file not in dict(files):
            raise RunnerError(400, "Die Startdatei (main_file) fehlt.")
        sql_setup = body.get("sql_setup") or ""
        if not isinstance(sql_setup, str) or len(sql_setup.encode("utf-8", "replace")) > 512 * 1024:
            raise RunnerError(400, "Ungültiges oder zu langes SQL-Setup.")
        java_home = None
        if language == "java":
            _, java_home = javatools.java_home(cfg, body.get("java_version"))
        seconds, memory = clamp_limits(body.get("limits"), cfg)
        raw_limits = body.get("limits") if isinstance(body.get("limits"), dict) else {}
        try:
            budget = int(raw_limits.get("total_seconds", 0)) or 0
        except (TypeError, ValueError):
            budget = 0
        budget = max(10, min(300, budget or (20 + len(tests) * (seconds + 3))))
        reveal = bool(body.get("reveal", False))

        if not self.manager.acquire_slot(60):
            raise RunnerError(429, "Die Ausführungsumgebung ist gerade ausgelastet. Bitte versuche es gleich noch einmal.")
        test_id = "t_" + secrets.token_hex(10)
        work = os.path.join(cfg.work_dir, test_id)
        home_dir = os.path.join("/tmp", test_id)
        ident = RunIdentity()
        try:
            uid = ident.__enter__()
            make_private_dir(work, uid)
            make_private_dir(home_dir, uid)
            base = make_private_dir(os.path.join(work, "base"), uid)
            write_files(base, files, uid)
            ctx = dict(uid=uid, language=language, files=files, main_file=main_file, java_home=java_home,
                       seconds=seconds, memory=memory, reveal=reveal, sql_setup=sql_setup,
                       work=work, base=base, home_dir=home_dir, deadline=time.monotonic() + budget,
                       cls=None, compile_error=None)
            results = self._run_all(ctx, tests)
        finally:
            self.manager.release_slot()
            remove_tree(work)
            remove_tree(home_dir)
            ident.__exit__(None, None, None)
        return {"results": results}

    def _run_all(self, ctx: dict, tests: list) -> list[dict]:
        language = ctx["language"]
        needs_student_build = language == "java" and any(
            isinstance(t, dict) and t.get("type") in TYPES_BY_LANGUAGE["java"] for t in tests)
        if needs_student_build:
            self._build_java(ctx)
        results = []
        for index, test in enumerate(tests, 1):
            name = f"Test {index}"
            if isinstance(test, dict) and isinstance(test.get("name"), str) and test["name"].strip():
                name = test["name"].strip()[:120]
            if time.monotonic() > ctx["deadline"]:
                results.append(self._result(name, False, "Das Zeitbudget für alle Tests ist aufgebraucht."))
                continue
            try:
                results.append(self._one(ctx, name, test, index))
            except RunnerError as exc:
                results.append(self._result(name, False, exc.message))
            except Exception as exc:  # ein kaputter Test darf die übrigen nicht mitreißen
                results.append(self._result(name, False,
                                            "Interner Fehler beim Ausführen des Tests (" + type(exc).__name__ + ")."))
        return results

    # --- Java übersetzen (einmal) ---------------------------------------------
    def _build_java(self, ctx: dict) -> None:
        cfg = self.cfg
        sources = [n for n, _ in ctx["files"] if n.endswith(".java")]
        if not sources:
            ctx["compile_error"] = ("Es gibt keine Java-Datei (.java) zu übersetzen.", "")
            return
        out_dir = os.path.join(ctx["base"], ".out")
        ok, raw, errors = javatools.run_compile(cfg, ctx["java_home"], ctx["base"], ctx["home_dir"], sources, out_dir,
                                                uid=ctx["uid"])
        if not ok:
            first = errors[0]
            where = f"{first['file']}, Zeile {first['line']}: " if first.get("line") else ""
            ctx["compile_error"] = ("Das Programm lässt sich nicht übersetzen: " + where + first["message"], raw[:STREAM_CAP])
            return
        try:
            ctx["cls"] = javatools.main_class(ctx["files"], ctx["main_file"])
        except RunnerError as exc:
            ctx["compile_error"] = (exc.message, "")

    # --- Ein Test -----------------------------------------------------------------
    def _one(self, ctx: dict, name: str, test, index: int) -> dict:
        if not isinstance(test, dict):
            return self._result(name, False, "Ungültige Testbeschreibung.")
        kind = test.get("type")
        allowed = TYPES_BY_LANGUAGE[ctx["language"]]
        if kind not in allowed:
            return self._result(name, False, "Der Testtyp " + str(kind)[:30] + " passt nicht zur Sprache " + ctx["language"] + ".")
        if ctx["language"] == "java" and ctx["compile_error"]:
            message, raw = ctx["compile_error"]
            return self._result(name, False, message, stderr=raw)
        # Frisches Verzeichnis je Test: keine Dateien oder Zustände aus Vorläufen.
        directory = os.path.join(ctx["work"], f"t{index}")
        shutil.copytree(ctx["base"], directory, symlinks=False)
        os.chmod(directory, 0o700)
        chown_tree(directory, ctx["uid"])
        remaining = max(1.0, ctx["deadline"] - time.monotonic())
        if kind == "io":
            return self._test_io(ctx, name, test, directory, remaining)
        if kind == "java_main":
            return self._test_java_main(ctx, name, test, directory, remaining)
        if kind == "python_assert":
            return self._test_python_assert(ctx, name, test, directory, remaining)
        return self._test_sql(ctx, name, test, directory, remaining)

    def _limits(self, ctx: dict, remaining: float, java: bool) -> Limits:
        cfg = self.cfg
        seconds = ctx["seconds"]
        wall = int(min(seconds + 3, max(1.0, remaining)))
        rss = javatools.rss_limit_mb(ctx["memory"]) if java else ctx["memory"] * 2 + 128
        return Limits(cpu_seconds=seconds, wall_seconds=wall, rss_mb=rss, max_output=256 * 1024,
                      max_threads=cfg.max_threads, max_disk=cfg.max_disk_bytes, fsize=cfg.fsize_bytes)

    def _spawn(self, ctx: dict, argv: list, directory: str, limits: Limits, stdin: str = "",
               extra_env: dict | None = None, pass_fds: tuple = ()) -> Proc:
        env = clean_env(ctx["home_dir"], directory, extra_env)
        proc = Proc(argv, directory, env, limits, stdin_data=stdin.encode("utf-8", "replace"),
                    watch_dirs=(directory, ctx["home_dir"]), pass_fds=pass_fds, uid=ctx["uid"])
        proc.start()
        proc.wait(limits.wall_seconds + 10)
        if not proc.done.is_set():
            proc.kill("wall")
            proc.wait(5)
        return proc

    def _result(self, name: str, ok: bool, message: str, stdout: str = "", stderr: str = "", ms: int = 0) -> dict:
        return {"name": name, "ok": ok, "message": message, "stdout": _clip(stdout),
                "stderr": _clip(stderr), "duration_ms": ms}

    def _from_proc(self, name: str, ok: bool, message: str, proc: Proc) -> dict:
        return self._result(name, ok, message, proc.text("stdout"), proc.text("stderr"), proc.duration_ms)

    def _abort_message(self, proc: Proc, limits: Limits) -> str | None:
        """Deutsche Meldung, wenn der Lauf von uns beendet wurde (Zeit, Speicher, ...)."""
        if proc.reason in NOTES:
            return NOTES[proc.reason].format(wall=limits.wall_seconds, cpu=limits.cpu_seconds,
                                             threads=limits.max_threads, output_kb=limits.max_output // 1024)
        return None

    def _crash_message(self, proc: Proc) -> str:
        err = _first_line(proc.text("stderr"))
        return "Das Programm ist mit einem Fehler abgebrochen" + (": " + err if err else f" (Exit-Code {proc.exit_code}).")

    # --- Typen ---------------------------------------------------------------------
    def _program_argv(self, ctx: dict, directory: str) -> tuple[list, bool, dict]:
        if ctx["language"] == "java":
            argv = javatools.java_run_argv(self.cfg, ctx["java_home"], os.path.join(directory, ".out"),
                                           ctx["cls"], ctx["memory"])
            return argv, True, {"JAVA_HOME": ctx["java_home"]}
        argv = [self.cfg.python_bin, "-I", "-B", self.cfg.boot_py, "run", ctx["main_file"],
                self.cfg.python_libs(), str(ctx["memory"] + 200)]
        return argv, False, {}

    def _test_io(self, ctx: dict, name: str, test: dict, directory: str, remaining: float) -> dict:
        argv, java, extra = self._program_argv(ctx, directory)
        limits = self._limits(ctx, remaining, java)
        stdin = test.get("input") if isinstance(test.get("input"), str) else ""
        proc = self._spawn(ctx, argv, directory, limits, stdin, extra)
        aborted = self._abort_message(proc, limits)
        if aborted:
            return self._from_proc(name, False, aborted, proc)
        if proc.exit_code != 0:
            return self._from_proc(name, False, self._crash_message(proc), proc)
        expected = test.get("expected_output") if isinstance(test.get("expected_output"), str) else ""
        mode = test.get("compare") if test.get("compare") in ("exact", "trim", "ignore_whitespace", "contains", "regex") else "trim"
        actual = proc.text("stdout")
        ok, problem = compare_output(actual, expected, mode)
        if problem:
            return self._from_proc(name, False, problem, proc)
        if ok:
            return self._from_proc(name, True, "Die Ausgabe stimmt.", proc)
        message = "Die Ausgabe stimmt nicht mit der Erwartung überein."
        if ctx["reveal"]:
            message += " Erwartet: «" + show(expected) + "», erhalten: «" + show(actual) + "»."
        return self._from_proc(name, False, message, proc)

    def _test_java_main(self, ctx: dict, name: str, test: dict, directory: str, remaining: float) -> dict:
        code = test.get("code")
        if not isinstance(code, str) or not code.strip():
            return self._result(name, False, "Der Test enthält keinen Code.")
        if len(code.encode("utf-8", "replace")) > 256 * 1024:
            return self._result(name, False, "Der Testcode ist zu lang.")
        stripped = javatools.strip_comments(code)
        match = javatools.PUBLIC_CLASS_RE.search(stripped)
        class_name = match.group(1) if match else "KwTest"
        package = javatools.PACKAGE_RE.search(stripped)
        full = (package.group(1) + "." if package else "") + class_name
        file_name = class_name + ".java"
        if file_name in dict(ctx["files"]):
            return self._result(name, False, "Der Testcode kollidiert mit einer Datei des Programms (" + file_name + ").")
        with open(os.path.join(directory, "." + file_name), "w", encoding="utf-8") as handle:
            handle.write(code)
        # javac verlangt den Dateinamen passend zur öffentlichen Klasse, daher ohne Punkt umbenennen.
        os.replace(os.path.join(directory, "." + file_name), os.path.join(directory, file_name))
        out_dir = os.path.join(directory, ".out")
        ok, raw, errors = javatools.run_compile(self.cfg, ctx["java_home"], directory, ctx["home_dir"],
                                                [file_name], out_dir, uid=ctx["uid"])
        if not ok:
            first = errors[0]
            return self._result(name, False, "Der Test lässt sich nicht übersetzen: " + first["message"]
                                + " (Das passt meist nicht zu den Namen im Schülerprogramm.)", stderr=raw)
        os.remove(os.path.join(directory, file_name))
        limits = self._limits(ctx, remaining, True)
        argv = javatools.java_run_argv(self.cfg, ctx["java_home"], out_dir, full, ctx["memory"])
        proc = self._spawn(ctx, argv, directory, limits, "", {"JAVA_HOME": ctx["java_home"]})
        aborted = self._abort_message(proc, limits)
        if aborted:
            return self._from_proc(name, False, aborted, proc)
        if proc.exit_code == 0:
            return self._from_proc(name, True, "Der Test wurde bestanden.", proc)
        err = _first_line(proc.text("stderr"))
        message = f"Der Test ist fehlgeschlagen (Exit-Code {proc.exit_code})." + (" " + err if err else "")
        return self._from_proc(name, False, message, proc)

    def _test_python_assert(self, ctx: dict, name: str, test: dict, directory: str, remaining: float) -> dict:
        code = test.get("code")
        if not isinstance(code, str) or not code.strip():
            return self._result(name, False, "Der Test enthält keinen Code.")
        if len(code.encode("utf-8", "replace")) > 256 * 1024:
            return self._result(name, False, "Der Testcode ist zu lang.")
        assert_path = os.path.join(directory, ".kw_assert.py")
        with open(assert_path, "w", encoding="utf-8") as handle:
            handle.write(code)
        limits = self._limits(ctx, remaining, False)
        read_fd, write_fd = os.pipe()
        try:
            argv = [self.cfg.python_bin, "-I", "-B", self.cfg.boot_py, "assert", ctx["main_file"],
                    self.cfg.python_libs(), str(ctx["memory"] + 200), assert_path, str(write_fd)]
            proc = self._spawn(ctx, argv, directory, limits, "", None, pass_fds=(write_fd,))
            os.close(write_fd)
            write_fd = -1
            payload = self._read_result(read_fd)
        finally:
            if write_fd >= 0:
                os.close(write_fd)
            os.close(read_fd)
        aborted = self._abort_message(proc, limits)
        if aborted:
            return self._from_proc(name, False, aborted, proc)
        if payload is None:
            return self._from_proc(name, False, self._crash_message(proc), proc)
        return self._from_proc(name, bool(payload.get("ok")) and proc.exit_code == 0,
                               str(payload.get("message", ""))[:500], proc)

    def _test_sql(self, ctx: dict, name: str, test: dict, directory: str, remaining: float) -> dict:
        expected = test.get("expected_query")
        if not isinstance(expected, str) or not expected.strip():
            return self._result(name, False, "Der Test enthält keine erwartete Abfrage.")
        paths = {}
        for key, text in (("setup", ctx["sql_setup"]), ("expected", expected)):
            paths[key] = os.path.join(directory, ".kw_" + key + ".sql")
            with open(paths[key], "w", encoding="utf-8") as handle:
                handle.write(text)
        limits = self._limits(ctx, remaining, False)
        read_fd, write_fd = os.pipe()
        try:
            argv = [self.cfg.python_bin, "-I", "-B", self.cfg.boot_py, "sql", paths["setup"],
                    os.path.join(directory, ctx["main_file"]), paths["expected"], str(ctx["memory"] + 200),
                    str(write_fd), "1" if test.get("order_sensitive") else "0", "1" if ctx["reveal"] else "0",
                    str(max(1, limits.cpu_seconds))]
            proc = self._spawn(ctx, argv, directory, limits, "", None, pass_fds=(write_fd,))
            os.close(write_fd)
            write_fd = -1
            payload = self._read_result(read_fd)
        finally:
            if write_fd >= 0:
                os.close(write_fd)
            os.close(read_fd)
        aborted = self._abort_message(proc, limits)
        if aborted:
            return self._from_proc(name, False, "Die Abfrage braucht zu lange oder zu viele Ressourcen. " + aborted, proc)
        if payload is None:
            return self._from_proc(name, False, "Die Abfrage konnte nicht ausgewertet werden.", proc)
        ok = bool(payload.get("ok")) and proc.exit_code == 0
        return self._from_proc(name, ok, str(payload.get("message", ""))[:500], proc)

    @staticmethod
    def _read_result(fd: int) -> dict | None:
        import select
        chunks = []
        while select.select([fd], [], [], 0)[0]:
            data = os.read(fd, 65536)
            if not data:
                break
            chunks.append(data)
            if sum(len(c) for c in chunks) > 65536:
                break
        try:
            payload = json.loads(b"".join(chunks).decode("utf-8", "replace"))
        except ValueError:
            return None
        return payload if isinstance(payload, dict) else None
