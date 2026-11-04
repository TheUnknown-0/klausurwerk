"""Java: übersetzen, Fehler parsen, Startklasse ermitteln, Startkommando bauen."""
from __future__ import annotations

import os
import re
import subprocess

from .config import Config
from .sandbox import Limits, RunnerError, chown_tree, clean_env, seal_active, seal_prefix, user_kwargs

ERR_RE = re.compile(r"^(?P<file>[^\s:]+\.java):(?P<line>\d+): (?P<kind>[^:]+): (?P<msg>.*)$")
PACKAGE_RE = re.compile(r"^\s*package\s+([A-Za-z_][\w.]*)\s*;", re.M)
MAIN_RE = re.compile(r"\bvoid\s+main\s*\(")
PUBLIC_CLASS_RE = re.compile(r"\bpublic\s+(?:final\s+|abstract\s+)*(?:class|interface|enum|record)\s+([A-Za-z_]\w*)")
ERROR_KINDS = ("error", "Fehler")


def java_home(cfg: Config, version) -> tuple[str, str]:
    """(Version, JAVA_HOME); unbekannte Versionen sind ein Fehler, None = Standard."""
    key = str(version) if version not in (None, "") else cfg.default_java
    if key not in cfg.jdks:
        raise RunnerError(400, "Die Java-Version " + key[:10] + " wird nicht unterstützt (21 oder 25).")
    return key, cfg.jdks[key]


def strip_comments(source: str) -> str:
    source = re.sub(r"/\*.*?\*/", " ", source, flags=re.S)
    return re.sub(r"//[^\n]*", " ", source)


def main_class(files: list[tuple[str, str]], main_file: str | None) -> str:
    """Voll qualifizierter Klassenname der Startklasse (Paket aus dem Quelltext)."""
    contents = dict(files)
    chosen = main_file if main_file in contents else None
    if chosen is None:
        for name, text in files:
            if name.endswith(".java") and MAIN_RE.search(strip_comments(text)):
                chosen = name
                break
    if chosen is None or not chosen.endswith(".java"):
        raise RunnerError(400, "Die Startdatei (main_file) fehlt oder ist keine Java-Datei.")
    code = strip_comments(contents[chosen])
    stem = chosen[:-5]
    match = PUBLIC_CLASS_RE.search(code)
    cls = match.group(1) if match else stem
    package = PACKAGE_RE.search(code)
    return (package.group(1) + "." if package else "") + cls


def parse_javac(output: str) -> list[dict]:
    """Strukturierte Fehler {file, line, column, message} aus der javac-Ausgabe."""
    errors: list[dict] = []
    lines = output.splitlines()
    i = 0
    while i < len(lines):
        m = ERR_RE.match(lines[i])
        if not m or m.group("kind").strip() not in ERROR_KINDS:
            i += 1
            continue
        entry = {"file": m.group("file"), "line": int(m.group("line")), "column": 1,
                 "message": m.group("msg").strip(), "detail": ""}
        i += 1
        detail: list[str] = []
        # Danach: Quelltextzeile, Zeile mit ^, ggf. weitere Hinweise bis zum nächsten Treffer.
        block: list[str] = []
        while i < len(lines) and not ERR_RE.match(lines[i]) and not re.match(r"^\d+ (error|Fehler)", lines[i]):
            block.append(lines[i])
            i += 1
        for idx, text in enumerate(block):
            if re.match(r"^\s*\^\s*$", text) and idx >= 1:
                entry["column"] = text.index("^") + 1
                detail = [t.strip() for t in block[idx + 1:] if t.strip()]
                break
        entry["detail"] = "\n".join(detail)
        errors.append(entry)
    return errors


def compile_cmd(cfg: Config, home: str, sources: list[str], out_dir: str, extra_cp: list[str] | None = None) -> list[str]:
    cp = [out_dir] + (extra_cp or []) + cfg.java_libs()
    return [
        os.path.join(home, "bin", "javac"),
        "-J-Duser.language=de", "-J-Duser.country=DE", "-J-Dfile.encoding=UTF-8",
        "-J-Xmx384m", "-J-Xss4m", "-J-XX:+UseSerialGC", "-J-XX:TieredStopAtLevel=1",
        "-J-Xshare:auto", "-J-XX:-UsePerfData",
        "-encoding", "UTF-8", "-Xlint:none", "-nowarn", "-proc:none", "-Xmaxerrs", "50",
        "-cp", os.pathsep.join(cp), "-d", out_dir,
    ] + sources


def run_compile(cfg: Config, home: str, work: str, tmp: str, sources: list[str], out_dir: str,
                extra_cp: list[str] | None = None, seconds: int = 30,
                uid: int | None = None) -> tuple[bool, str, list[dict]]:
    """Übersetzt synchron. Rückgabe: (ok, Rohausgabe, Fehlerliste).

    javac läuft wie alle Kindprozesse mit leerer Umgebung und rlimits (prlimit).
    """
    os.makedirs(out_dir, exist_ok=True)
    chown_tree(out_dir, uid)
    lim = Limits(cpu_seconds=seconds, wall_seconds=seconds)
    argv = [
        "prlimit", f"--cpu={lim.cpu_seconds}:{lim.cpu_seconds + 1}", f"--fsize={lim.fsize}",
        f"--nproc={lim.nproc}", "--nofile=1024", "--core=0",
    ] + (seal_prefix() if seal_active() else []) + compile_cmd(cfg, home, sources, out_dir, extra_cp)
    env = clean_env(tmp, tmp, {"JAVA_HOME": home})
    try:
        done = subprocess.run(argv, cwd=work, env=env, stdin=subprocess.DEVNULL,
                              stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                              timeout=seconds + 5, start_new_session=True, **user_kwargs(uid))
    except subprocess.TimeoutExpired:
        return False, "Die Übersetzung hat zu lange gedauert.", [
            {"file": "", "line": 0, "column": 0, "message": "Die Übersetzung hat zu lange gedauert.", "detail": ""}]
    except OSError as exc:
        raise RunnerError(503, "Der Java-Compiler konnte nicht gestartet werden (" + str(exc.strerror) + ").") from exc
    output = done.stdout[:200000].decode("utf-8", "replace")
    if done.returncode == 0:
        return True, output, []
    errors = parse_javac(output)
    if not errors:
        text = output.strip()[:2000] or "Die Übersetzung ist fehlgeschlagen."
        errors = [{"file": "", "line": 0, "column": 0, "message": text, "detail": ""}]
    return False, output, errors


def java_run_argv(cfg: Config, home: str, out_dir: str, cls: str, memory_mb: int,
                  extra_cp: list[str] | None = None, extra_args: list[str] | None = None) -> list[str]:
    cp = [out_dir] + (extra_cp or []) + cfg.java_libs()
    return [
        os.path.join(home, "bin", "java"),
        f"-Xmx{memory_mb}m", "-Xss8m", "-XX:+UseSerialGC", "-XX:TieredStopAtLevel=1",
        "-Xshare:auto", "-Djava.awt.headless=true", "-Dfile.encoding=UTF-8",
        "-Dstdout.encoding=UTF-8", "-Dstderr.encoding=UTF-8", "-Dsun.stdout.encoding=UTF-8",
        # Bewusst kein user.language=de: Scanner.nextDouble und String.format sollen mit
        # Punkt als Dezimaltrenner arbeiten wie in den meisten Lehrbuch-Beispielen.
        "-XX:+ExitOnOutOfMemoryError", "-XX:-UsePerfData", "-XX:MaxMetaspaceSize=128m",
        "-XX:ReservedCodeCacheSize=48m", "-XX:MaxDirectMemorySize=64m",
        "-cp", os.pathsep.join(cp),
    ] + (extra_args or []) + [cls]


def rss_limit_mb(memory_mb: int) -> int:
    """Obergrenze für den echten Speicherverbrauch (Heap plus JVM-Overhead)."""
    return memory_mb + 384
