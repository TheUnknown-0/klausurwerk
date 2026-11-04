"""POST /robo: Robo-Programm (Java oder Python) in einer Testwelt ausführen (docs/API.md, Abschnitt 5).

Anfrage::

    { "language": "java|python", "java_version": "21", "files": [...], "main_file": "Main.java",
      "world": { cols, rows, robo, walls, boxes, screws, targets },
      "goal": { "screws_collected": "all" | Zahl, "robo_at": [x, y], "robo_dir": "N|E|S|W|O",
                "boxes_at": [[x, y], ...], "robo_on_target": true, "boxes_on_targets": true },
      "limits": { "seconds": 10, "memory_mb": 256 } }

Antwort::

    { "ok": bool, "trace": ["v", "r", ..., "!Meldung"], "final_world": {...}, "message": "...",
      "steps": 12, "goal_results": [ {"key", "ok", "message"} ], "stdout": "...", "stderr": "..." }

Das Programm läuft mit denselben Bibliotheken wie im Browser (libs/python/robo.py, libs/java/Robo.java).
Es schreibt seine Züge als Protokollzeilen auf die Standardausgabe. Dem Protokoll wird nicht
vertraut: Der Runner spielt jeden Zug in einer eigenen Welt nach (dieselben Regeln wie
robo.py), das Ergebnis und die Zielprüfung beruhen nur auf dieser Nachrechnung.
"""
from __future__ import annotations

import importlib.util
import json
import os
import secrets
import threading

from .. import javatools
from ..sandbox import NOTES, Limits, Proc, RunnerError, clean_env, make_private_dir, remove_tree
from ..workspace import clamp_limits, validate_files, write_files

TRACE_PREFIX = "\x01KWR:"
RESERVED_FILES = {"robo.py", "Robo.java", "RoboFehler.java", "robo_world.json"}
STREAM_CAP = 20000
MAX_TRACE = 10050

_engine_lock = threading.Lock()
_engine_module = None


def engine(cfg):
    """Die Regeln der Welt: dieselbe Datei wie die Schülerbibliothek, unter eigenem Namen geladen."""
    global _engine_module
    with _engine_lock:
        if _engine_module is None:
            path = os.path.join(cfg.python_libs(), "robo.py")
            spec = importlib.util.spec_from_file_location("kw_robo_engine", path)
            module = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(module)
            _engine_module = module
        return _engine_module


def register(registry, ctx):
    registry.add("POST", r"/robo", handle_robo)


# --- Ziel ------------------------------------------------------------------------

def _is_int(v) -> bool:
    return isinstance(v, int) and not isinstance(v, bool)


def normalize_goal(raw) -> dict:
    if raw is None or raw == [] or raw == {}:
        return {}
    if not isinstance(raw, dict):
        raise RunnerError(400, "Das Ziel muss ein Objekt sein.")
    goal: dict = {}
    sc = raw.get("screws_collected")
    if sc is not None:
        if sc != "all" and not (_is_int(sc) and 0 <= sc <= 100000):
            raise RunnerError(400, "screws_collected muss \"all\" oder eine Zahl sein.")
        goal["screws_collected"] = sc
    at = raw.get("robo_at")
    if at is not None:
        if not (isinstance(at, list) and len(at) == 2 and _is_int(at[0]) and _is_int(at[1])):
            raise RunnerError(400, "robo_at muss die Form [x, y] haben.")
        goal["robo_at"] = [at[0], at[1]]
    d = raw.get("robo_dir")
    if d is not None:
        d = str(d).upper()
        d = "E" if d == "O" else d
        if d not in ("N", "E", "S", "W"):
            raise RunnerError(400, "robo_dir muss N, E (O), S oder W sein.")
        goal["robo_dir"] = d
    boxes = raw.get("boxes_at")
    if boxes is not None:
        if not isinstance(boxes, list) or len(boxes) > 900:
            raise RunnerError(400, "boxes_at muss eine Liste von Positionen sein.")
        cells = []
        for item in boxes:
            if not (isinstance(item, list) and len(item) == 2 and _is_int(item[0]) and _is_int(item[1])):
                raise RunnerError(400, "boxes_at: Jede Position muss die Form [x, y] haben.")
            cells.append([item[0], item[1]])
        goal["boxes_at"] = cells
    if raw.get("robo_on_target"):
        goal["robo_on_target"] = True
    if raw.get("boxes_on_targets"):
        goal["boxes_on_targets"] = True
    return goal


def check_goal(world, goal: dict) -> list[dict]:
    """Prüft das Ziel gegen die Endwelt. Rückgabe: [{key, ok, message}]."""
    results: list[dict] = []

    def add(key, ok, ok_text, bad_text):
        results.append({"key": key, "ok": bool(ok), "message": ok_text if ok else bad_text})

    sc = goal.get("screws_collected")
    if sc == "all":
        left = world.total_screws_on_floor()
        add("screws_collected", left == 0, "Alle Schrauben sind im Korb.",
            "Es liegen noch %d Schrauben auf der Welt." % left)
    elif sc is not None:
        add("screws_collected", world.basket >= sc, "Im Korb sind genug Schrauben (%d)." % world.basket,
            "Im Korb sind %d Schrauben, verlangt sind mindestens %d." % (world.basket, sc))
    at = goal.get("robo_at")
    if at is not None:
        ok = (world.x, world.y) == (at[0], at[1])
        add("robo_at", ok, "Robo steht auf dem richtigen Feld.",
            "Robo steht auf Feld %d|%d, sollte aber auf %d|%d stehen." % (world.x, world.y, at[0], at[1]))
    if goal.get("robo_on_target"):
        add("robo_on_target", world.on_target(), "Robo steht auf einem Zielfeld.",
            "Robo steht nicht auf einem Zielfeld.")
    d = goal.get("robo_dir")
    if d is not None:
        names = {"N": "Norden", "E": "Osten", "S": "Süden", "W": "Westen"}
        add("robo_dir", world.dir == d, "Robo schaut in die richtige Richtung.",
            "Robo schaut nach %s, sollte aber nach %s schauen." % (names[world.dir], names[d]))
    boxes = goal.get("boxes_at")
    if boxes is not None:
        missing = [c for c in boxes if (c[0], c[1]) not in world.boxes]
        add("boxes_at", not missing, "Die Kisten stehen an den richtigen Stellen.",
            "Auf Feld %d|%d fehlt eine Kiste." % (missing[0][0], missing[0][1]) if missing else "")
    if goal.get("boxes_on_targets"):
        off = [c for c in world.boxes if c not in world.targets]
        add("boxes_on_targets", not off, "Alle Kisten stehen auf Zielfeldern.",
            "Die Kiste auf Feld %d|%d steht nicht auf einem Zielfeld." % (off[0][0], off[0][1]) if off else "")
    return results


# --- Nachrechnen -----------------------------------------------------------------

def parse_stdout(text: str) -> tuple[list[str], str]:
    """Trennt die Protokollzeilen vom übrigen Programmtext (das Präfix darf mitten in einer Zeile stehen)."""
    trace: list[str] = []
    rest: list[str] = []
    i = 0
    while True:
        j = text.find(TRACE_PREFIX, i)
        if j < 0:
            rest.append(text[i:])
            break
        rest.append(text[i:j])
        k = text.find("\n", j)
        line = text[j + len(TRACE_PREFIX):(k if k >= 0 else len(text))]
        if len(trace) < MAX_TRACE:
            trace.append(line.rstrip("\r"))
        if k < 0:
            break
        i = k + 1
    return trace, "".join(rest)


def replay(eng, world_raw: dict, trace: list[str]):
    """Spielt das Protokoll nach. Rückgabe: (Welt, Fehlermeldung|None, bereinigtes Protokoll)."""
    world = eng.World(world_raw)
    clean: list[str] = []
    for code in trace:
        if code.startswith("!"):
            return world, code[1:][:300] or "Robo ist kaputt.", clean + [code[:300]]
        if code not in ("v", "l", "r", "a", "b", "s"):
            continue  # unbekannte Zeilen sind kein Zug
        try:
            world.step(code)
        except eng.RoboFehler as exc:
            message = str(exc)
            return world, message, clean + ["!" + message]
        clean.append(code)
    return world, None, clean


def _clip(text: str) -> str:
    return text if len(text) <= STREAM_CAP else text[:STREAM_CAP] + "\n[... gekürzt]"


def _first_line(text: str, limit: int = 220) -> str:
    for line in text.splitlines():
        if line.strip():
            return line.strip()[:limit]
    return ""


# --- Handler -----------------------------------------------------------------------

def handle_robo(ctx, req):
    cfg = ctx.cfg
    body = req.json()
    language = body.get("language")
    if language not in ("java", "python"):
        raise RunnerError(400, "Die Sprache muss java oder python sein.")
    files = validate_files(body.get("files"), cfg)
    for name, _ in files:
        if name in RESERVED_FILES:
            raise RunnerError(400, "Der Dateiname " + name + " ist für die Robo-Bibliothek reserviert.")
    main_file = body.get("main_file")
    if main_file is not None and not isinstance(main_file, str):
        raise RunnerError(400, "Ungültige Startdatei.")
    eng = engine(cfg)
    world_raw = body.get("world")
    try:
        eng.World(world_raw)
    except ValueError as exc:
        raise RunnerError(400, "Die Welt ist ungültig: " + str(exc))
    goal = normalize_goal(body.get("goal"))
    java_home = None
    cls = None
    if language == "java":
        _, java_home = javatools.java_home(cfg, body.get("java_version"))
        if not any(n.endswith(".java") for n, _ in files):
            raise RunnerError(400, "Es gibt keine Java-Datei (.java) zu übersetzen.")
        cls = javatools.main_class(files, main_file)
    elif main_file not in dict(files):
        raise RunnerError(400, "Die Startdatei (main_file) fehlt.")
    seconds, memory = clamp_limits(body.get("limits"), cfg)

    if not ctx.runs.acquire_slot(60):
        raise RunnerError(429, "Die Ausführungsumgebung ist gerade ausgelastet. Bitte versuche es gleich noch einmal.")
    run_id = "t_" + secrets.token_hex(10)
    work = os.path.join(cfg.work_dir, run_id)
    home_dir = os.path.join("/tmp", run_id)
    try:
        make_private_dir(work)
        make_private_dir(home_dir)
        directory = make_private_dir(os.path.join(work, "run"))
        write_files(directory, files)
        write_files(directory, [("robo_world.json", json.dumps(world_raw, ensure_ascii=False))])
        result = _execute(ctx, eng, language, files, main_file, java_home, cls, directory, home_dir,
                          seconds, memory, world_raw)
    finally:
        ctx.runs.release_slot()
        remove_tree(work)
        remove_tree(home_dir)

    trace, stdout_text, stderr_text, error_message, proc_message = result
    world, sim_error, clean = replay(eng, world_raw, trace)
    message = None
    ok = False
    goal_results: list[dict] = []
    if error_message:
        message = error_message
    elif sim_error:
        message = sim_error
    elif proc_message:
        message = proc_message
    else:
        goal_results = check_goal(world, goal)
        failed = [g for g in goal_results if not g["ok"]]
        ok = not failed
        message = "Das Ziel ist erreicht." if ok else failed[0]["message"]
        if ok and not goal_results:
            message = "Das Programm ist ohne Fehler durchgelaufen."
    return 200, {
        "ok": ok,
        "trace": clean,
        "final_world": world.to_dict(),
        "message": message,
        "steps": world.moves,
        "goal_results": goal_results,
        "stdout": _clip(stdout_text),
        "stderr": _clip(stderr_text),
    }


def _execute(ctx, eng, language, files, main_file, java_home, cls, directory, home_dir,
             seconds, memory, world_raw):
    """Übersetzt (Java) und startet das Programm. Rückgabe:
    (Protokoll, Text, Fehlertext, Meldung bei Übersetzungs-/Laufzeitproblem, Meldung bei Abbruch)."""
    cfg = ctx.cfg
    env_extra: dict = {}
    if language == "java":
        sources = [n for n, _ in files if n.endswith(".java")]
        out_dir = os.path.join(directory, ".out")
        ok, raw, errors = javatools.run_compile(cfg, java_home, directory, home_dir, sources, out_dir)
        if not ok:
            first = errors[0]
            where = f"{first['file']}, Zeile {first['line']}: " if first.get("line") else ""
            return [], "", raw[:STREAM_CAP], "Das Programm lässt sich nicht übersetzen: " + where + first["message"], None
        argv = javatools.java_run_argv(cfg, java_home, out_dir, cls, memory)
        env_extra = {"JAVA_HOME": java_home}
        rss = javatools.rss_limit_mb(memory)
    else:
        argv = [cfg.python_bin, "-I", "-B", cfg.boot_py, "run", main_file, cfg.python_libs(), str(memory + 200)]
        rss = memory * 2 + 128
    limits = Limits(cpu_seconds=seconds, wall_seconds=seconds + 3, rss_mb=rss, max_output=768 * 1024,
                    max_threads=cfg.max_threads, max_disk=cfg.max_disk_bytes, fsize=cfg.fsize_bytes)
    env = clean_env(home_dir, directory, env_extra)
    proc = Proc(argv, directory, env, limits, stdin_data=b"", watch_dirs=(directory, home_dir))
    proc.start()
    proc.wait(limits.wall_seconds + 10)
    if not proc.done.is_set():
        proc.kill("wall")
        proc.wait(5)
    trace, text = parse_stdout(proc.text("stdout"))
    stderr_text = proc.text("stderr")
    abort = None
    if proc.reason in NOTES:
        abort = NOTES[proc.reason].format(wall=limits.wall_seconds, cpu=limits.cpu_seconds,
                                          threads=limits.max_threads, output_kb=limits.max_output // 1024)
    elif proc.exit_code not in (0, None):
        # Ein Robo-Fehler beendet das Programm ebenfalls mit Fehlercode; seine Meldung steht im Protokoll.
        if not any(t.startswith("!") for t in trace):
            err = _first_line(stderr_text)
            abort = "Das Programm ist mit einem Fehler abgebrochen" + (": " + err if err else " (Exit-Code %s)." % proc.exit_code)
    return trace, text, stderr_text, None, abort
