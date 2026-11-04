"""HTTP-Server (nur Standardbibliothek): Routing, Token-Prüfung, JSON."""
from __future__ import annotations

import hmac
import importlib
import json
import pkgutil
import re
import sys
import traceback
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit

from .config import Config
from .runs import RunManager
from .sandbox import RunnerError
from .testexec import TestRunner

MAX_BODY = 4 * 1024 * 1024
RUN_ID = r"(r_[0-9a-f]{24})"


class Request:
    def __init__(self, method: str, path: str, query: dict, headers, body: bytes, match):
        self.method = method
        self.path = path
        self.query = query
        self.headers = headers
        self.body = body
        self.match = match

    def json(self) -> dict:
        try:
            data = json.loads(self.body.decode("utf-8")) if self.body else {}
        except (ValueError, UnicodeDecodeError):
            raise RunnerError(400, "Die Anfrage ist kein gültiges JSON.")
        if data == []:  # PHP kodiert ein leeres Array als []
            data = {}
        if not isinstance(data, dict):
            raise RunnerError(400, "Die Anfrage muss ein JSON-Objekt sein.")
        return data

    def int_query(self, name: str, default: int = 0) -> int:
        try:
            return int(self.query.get(name, [str(default)])[0])
        except (TypeError, ValueError):
            return default


class Registry:
    """Routentabelle. Erweiterungsmodule hängen sich hier mit ``add`` ein."""

    def __init__(self):
        self._routes: list[tuple[str, re.Pattern, object]] = []

    def add(self, method: str, pattern: str, handler) -> None:
        self._routes.append((method.upper(), re.compile(pattern), handler))

    def find(self, method: str, path: str):
        path_known = False
        for route_method, pattern, handler in self._routes:
            match = pattern.fullmatch(path)
            if match:
                path_known = True
                if route_method == method:
                    return handler, match, True
        return None, None, path_known


class Context:
    def __init__(self, cfg: Config):
        self.cfg = cfg
        self.runs = RunManager(cfg)
        self.tests = TestRunner(cfg, self.runs)
        self.registry = Registry()


# --- Kern-Routen -------------------------------------------------------------

def h_start(ctx: Context, req: Request):
    return 200, {"run_id": ctx.runs.start(req.json())}


def h_poll(ctx: Context, req: Request):
    return 200, ctx.runs.snapshot(req.match.group(1), req.int_query("offset", 0))


def h_stdin(ctx: Context, req: Request):
    text = req.json().get("text", "")
    if not isinstance(text, str):
        raise RunnerError(400, "Ungültige Eingabe.")
    ctx.runs.stdin(req.match.group(1), text)
    return 200, {"ok": True}


def h_stop(ctx: Context, req: Request):
    ctx.runs.stop(req.match.group(1))
    return 200, {"ok": True}


def h_tests(ctx: Context, req: Request):
    return 200, ctx.tests.run(req.json())


def build_context(cfg: Config) -> Context:
    ctx = Context(cfg)
    reg = ctx.registry
    reg.add("POST", r"/runs", h_start)
    reg.add("GET", r"/runs/" + RUN_ID, h_poll)
    reg.add("POST", r"/runs/" + RUN_ID + r"/stdin", h_stdin)
    reg.add("POST", r"/runs/" + RUN_ID + r"/stop", h_stop)
    reg.add("POST", r"/tests", h_tests)
    load_modules(ctx)
    return ctx


def load_modules(ctx: Context) -> None:
    """Erweiterungsmodule aus kwrunner/modules laden (z. B. /robo)."""
    from . import modules
    for info in pkgutil.iter_modules(modules.__path__):
        if info.name.startswith("_"):
            continue
        try:
            module = importlib.import_module(f"{__package__}.modules.{info.name}")
            register = getattr(module, "register", None)
            if callable(register):
                register(ctx.registry, ctx)
        except Exception:  # ein defektes Modul darf den Runner nicht lahmlegen
            sys.stderr.write(f"Modul {info.name} konnte nicht geladen werden:\n{traceback.format_exc()}\n")


# --- Server ------------------------------------------------------------------

def make_handler(ctx: Context):
    cfg = ctx.cfg

    class Handler(BaseHTTPRequestHandler):
        server_version = "KlausurwerkRunner"
        sys_version = ""
        timeout = 30

        def log_message(self, fmt, *args):  # Zugriffsprotokoll: nur Fehler
            pass

        def _send(self, status: int, payload, content_type: str = "application/json; charset=utf-8") -> None:
            raw = payload if isinstance(payload, bytes) else json.dumps(payload, ensure_ascii=False).encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", content_type)
            self.send_header("Content-Length", str(len(raw)))
            self.send_header("Cache-Control", "no-store")
            self.send_header("Connection", "close")
            self.end_headers()
            if self.command != "HEAD":
                self.wfile.write(raw)

        def _authorized(self) -> bool:
            sent = self.headers.get("X-Runner-Token", "")
            return hmac.compare_digest(sent.encode("utf-8", "replace"), cfg.token.encode("utf-8"))

        def _handle(self) -> None:
            try:
                parts = urlsplit(self.path)
                path = parts.path
                if self.command == "GET" and path == "/healthz":
                    self._send(200, b"ok", "text/plain; charset=utf-8")
                    return
                if not cfg.token:
                    self._send(503, {"error": "Die Ausführungsumgebung ist nicht konfiguriert (RUNNER_TOKEN fehlt)."})
                    return
                if not self._authorized():
                    self._send(401, {"error": "Nicht autorisiert."})
                    return
                handler, match, known = ctx.registry.find(self.command, path)
                if handler is None:
                    self._send(405 if known else 404, {"error": "Unbekannte Anfrage."})
                    return
                body = b""
                if self.command == "POST":
                    try:
                        length = int(self.headers.get("Content-Length", "0") or 0)
                    except ValueError:
                        length = -1
                    if length < 0 or length > MAX_BODY:
                        self._send(413, {"error": "Die Anfrage ist zu groß."})
                        return
                    body = self.rfile.read(length) if length else b""
                req = Request(self.command, path, parse_qs(parts.query), self.headers, body, match)
                status, payload = handler(ctx, req)
                self._send(status, payload)
            except RunnerError as exc:
                self._send(exc.status, {"error": exc.message})
            except (BrokenPipeError, ConnectionResetError, TimeoutError):
                pass
            except Exception:
                sys.stderr.write(traceback.format_exc() + "\n")
                try:
                    self._send(500, {"error": "Interner Fehler in der Ausführungsumgebung."})
                except OSError:
                    pass

        do_GET = _handle
        do_POST = _handle

    return Handler


class RunnerServer(ThreadingHTTPServer):
    daemon_threads = True
    request_queue_size = 128
    allow_reuse_address = True


def serve(cfg: Config) -> RunnerServer:
    ctx = build_context(cfg)
    return RunnerServer(("0.0.0.0", cfg.port), make_handler(ctx))
