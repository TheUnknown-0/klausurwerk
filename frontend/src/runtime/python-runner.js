/**
 * Python-Quelltext des Läufers, der im Pyodide-Worker ausgeführt wird:
 * bereitet das virtuelle Dateisystem vor, ersetzt input()/stdin/stdout und
 * führt das Hauptskript aus. Fehler werden als JSON beschrieben (siehe
 * python-errors.js für die verständliche Aufbereitung).
 *
 * Erwartet das JS-Modul '_kw' (registerJsModule) mit:
 *   write(stream, text)   Ausgabe          echo(text)   Eingabe-Echo
 *   read_input()          blockiert, liefert 'T<text>' | 'C' (Abbruch) | 'E' (Ende)
 *   turtle(json)          Turtle-Befehle   image(base64)  matplotlib-Bild
 *
 * Kein Backtick und keine Folge dollar+geschweifte Klammer im Python-Text (String.raw).
 */
export const PY_RUNNER = String.raw`import sys, os, builtins, traceback, json, linecache, shutil, importlib, io, base64
import _kw

WORK = "/work"
KWLIB = "/kwlib"
_state = {"queue": [], "interactive": False, "main": "main.py"}


class _Out:
    encoding = "utf-8"
    errors = "strict"

    def __init__(self, stream):
        self._stream = stream

    def write(self, text):
        if not isinstance(text, str):
            raise TypeError("write() argument must be str, not %s" % type(text).__name__)
        if text:
            _kw.write(self._stream, text)
        return len(text)

    def writelines(self, lines):
        for line in lines:
            self.write(line)

    def flush(self):
        pass

    def isatty(self):
        return False

    def writable(self):
        return True

    def readable(self):
        return False

    def fileno(self):
        raise OSError("Kein Dateideskriptor verfuegbar")


def _next_line():
    """Naechste Eingabezeile oder None (Ende der Eingabe)."""
    queue = _state["queue"]
    if "turtle" in sys.modules:
        sys.modules["turtle"]._flush()
    if queue:
        line = queue.pop(0)
        _kw.echo(line + "\n")
        return line
    if not _state["interactive"]:
        return None
    reply = _kw.read_input()
    kind, text = reply[:1], reply[1:]
    if kind == "C":
        raise KeyboardInterrupt
    if kind == "E":
        return None
    return text


class _In:
    encoding = "utf-8"
    errors = "strict"

    def readline(self, size=-1):
        line = _next_line()
        return "" if line is None else line + "\n"

    def read(self, size=-1):
        queue = _state["queue"]
        if queue:
            parts = []
            while queue:
                parts.append(_next_line() + "\n")
            return "".join(parts)
        return self.readline()

    def readlines(self, hint=-1):
        lines = []
        while True:
            line = self.readline()
            if not line:
                return lines
            lines.append(line)

    def __iter__(self):
        return self

    def __next__(self):
        line = self.readline()
        if not line:
            raise StopIteration
        return line

    def isatty(self):
        return False

    def readable(self):
        return True

    def fileno(self):
        raise OSError("Kein Dateideskriptor verfuegbar")


def _input(prompt=""):
    if prompt is not None and str(prompt) != "":
        _kw.write("stdout", str(prompt))
    line = _next_line()
    if line is None:
        raise EOFError("EOF when reading a line")
    return line


def _rel(filename):
    if filename and filename.startswith(WORK + "/"):
        return filename[len(WORK) + 1:]
    return filename


def _is_user(filename):
    return bool(filename) and (filename == _state["main"] or filename.startswith(WORK + "/"))


def prepare(spec_json):
    """Dateisystem und Ein-/Ausgabe fuer einen neuen Lauf vorbereiten."""
    spec = json.loads(spec_json)
    os.chdir("/")
    if os.path.isdir(WORK):
        shutil.rmtree(WORK)
    os.makedirs(WORK)
    for f in spec["files"]:
        path = os.path.join(WORK, f["name"])
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "w", encoding="utf-8", newline="") as fh:
            fh.write(f["content"])
    os.chdir(WORK)
    for p in (KWLIB, WORK):
        if p in sys.path:
            sys.path.remove(p)
        sys.path.insert(0, p)
    for name, mod in list(sys.modules.items()):
        mfile = getattr(mod, "__file__", None)
        if isinstance(mfile, str) and (mfile.startswith(WORK + "/") or mfile.startswith(KWLIB + "/")):
            del sys.modules[name]
    importlib.invalidate_caches()
    linecache.clearcache()
    _state["main"] = spec.get("main") or "main.py"
    _state["queue"] = [ln for ln in spec.get("stdin_lines", [])]
    _state["interactive"] = bool(spec.get("interactive"))
    sys.stdout = _Out("stdout")
    sys.stderr = _Out("stderr")
    sys.stdin = _In()
    builtins.input = _input
    sys.argv = [_state["main"]]
    sys.setrecursionlimit(1000)
    if "matplotlib.pyplot" in sys.modules:
        sys.modules["matplotlib.pyplot"].close("all")


def install_matplotlib():
    """matplotlib ohne Fenster: plt.show() schickt PNG-Bilder an die Seite."""
    import matplotlib
    matplotlib.use("agg")
    import matplotlib.pyplot as plt

    def show(*args, **kwargs):
        for num in plt.get_fignums():
            buf = io.BytesIO()
            plt.figure(num).savefig(buf, format="png", dpi=100, bbox_inches="tight")
            _kw.image(base64.b64encode(buf.getvalue()).decode("ascii"))
        plt.close("all")

    plt.show = show


def _describe(exc):
    tb = exc.__traceback__
    while tb is not None and not _is_user(tb.tb_frame.f_code.co_filename):
        tb = tb.tb_next
    frames = []
    for fs in traceback.extract_tb(tb) if tb is not None else []:
        if _is_user(fs.filename):
            frames.append({"file": _rel(fs.filename), "line": fs.lineno})
    info = {"status": "error", "type": type(exc).__name__, "message": str(exc),
            "file": None, "line": None, "column": None, "frames": frames}
    if isinstance(exc, SyntaxError):
        info["message"] = exc.msg or str(exc)
        info["file"] = _rel(exc.filename)
        info["line"] = exc.lineno
        info["column"] = exc.offset
        text = "".join(traceback.format_exception_only(type(exc), exc))
    else:
        if frames:
            info["file"], info["line"] = frames[-1]["file"], frames[-1]["line"]
        text = "".join(traceback.format_exception(type(exc), exc, tb))
    info["traceback"] = text.replace(WORK + "/", "")
    return info


def run(main):
    """Hauptskript ausfuehren. Gibt eine JSON-Beschreibung des Ergebnisses zurueck."""
    result = {"status": "ok"}
    try:
        with open(os.path.join(WORK, main), encoding="utf-8") as fh:
            source = fh.read()
        linecache.cache[main] = (len(source), None, source.splitlines(True), main)
        code = compile(source, main, "exec")
        scope = {"__name__": "__main__", "__file__": main, "__builtins__": builtins}
        exec(code, scope)
    except SystemExit as e:
        code_value = e.code
        if code_value is None:
            result = {"status": "exit", "exit_code": 0}
        elif isinstance(code_value, int):
            result = {"status": "exit", "exit_code": code_value}
        else:
            _kw.write("stderr", str(code_value) + "\n")
            result = {"status": "exit", "exit_code": 1}
    except KeyboardInterrupt:
        result = {"status": "stopped"}
    except BaseException as e:
        result = _describe(e)
    finally:
        try:
            if "turtle" in sys.modules:
                sys.modules["turtle"]._flush()
        except Exception:
            pass
    return json.dumps(result)


def check(spec_json):
    """Syntaxpruefung ohne Ausfuehrung: alle .py-Dateien kompilieren."""
    spec = json.loads(spec_json)
    errors = []
    for f in spec["files"]:
        if not f["name"].endswith(".py"):
            continue
        try:
            compile(f["content"], f["name"], "exec")
        except SyntaxError as e:
            errors.append({"file": f["name"], "line": e.lineno, "column": e.offset,
                           "type": type(e).__name__, "message": e.msg or str(e)})
        except ValueError as e:
            errors.append({"file": f["name"], "line": 1, "column": None,
                           "type": "ValueError", "message": str(e)})
    return json.dumps(errors)
`;
