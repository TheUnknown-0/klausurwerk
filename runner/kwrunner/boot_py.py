"""Startskript für Python- und SQL-Läufe (läuft als Kindprozess, ``python3 -I -B``).

Aufrufe (alle von ``kwrunner`` gebaut, nie direkt von außen):

    boot_py.py run    <main_file> <libs> <mem_mb>
    boot_py.py assert <main_file> <libs> <mem_mb> <assert_file> <result_fd>
    boot_py.py sql    <setup_file> <student_file> <expected_file> <mem_mb> <result_fd> <order 0|1> <reveal 0|1> <seconds>

``assert`` und ``sql`` melden ihr Ergebnis als JSON auf einem eigenen Dateideskriptor.
Hinweis: Der Schülercode läuft im selben Prozess wie die Prüfung. Wer absichtlich
auf den Deskriptor schreibt, kann ein Ergebnis fälschen; das ist bei Tests in
Python nicht vollständig zu verhindern (siehe runner/README.md).
"""
import json
import math
import os
import re
import sys
import traceback


def limit_memory(mem_mb: int) -> None:
    try:
        import resource
        limit = int(mem_mb) * 1024 * 1024
        resource.setrlimit(resource.RLIMIT_AS, (limit, limit))
    except (ImportError, ValueError, OSError):
        pass


def setup_paths(libs: str) -> None:
    sys.path.insert(0, os.getcwd())
    if libs and os.path.isdir(libs):
        sys.path.insert(1, libs)


def print_exception(exc: BaseException, skip_names: tuple = ()) -> None:
    """Traceback ohne die Rahmen dieses Startskripts ausgeben."""
    tb = exc.__traceback__
    here = os.path.abspath(__file__)
    while tb is not None and (os.path.abspath(tb.tb_frame.f_code.co_filename) == here
                              or tb.tb_frame.f_code.co_filename.endswith("runpy.py")
                              or tb.tb_frame.f_code.co_filename.startswith("<frozen runpy")):
        tb = tb.tb_next
    sys.stderr.write("".join(traceback.format_exception(type(exc), exc, tb)))
    sys.stderr.flush()


def emit(fd: int, data: dict) -> None:
    try:
        sys.stdout.flush()
        sys.stderr.flush()
    except (OSError, ValueError):
        pass
    os.write(fd, json.dumps(data, ensure_ascii=False).encode("utf-8"))


def mode_run(args: list) -> int:
    main_file, libs, mem = args[0], args[1], args[2]
    limit_memory(int(mem))
    setup_paths(libs)
    import runpy
    try:
        runpy.run_path(main_file, run_name="__main__")
    except SystemExit as exc:
        code = exc.code
        if code is None:
            return 0
        if isinstance(code, int):
            return code
        sys.stderr.write(str(code) + "\n")
        return 1
    except KeyboardInterrupt:
        return 130
    except BaseException as exc:  # noqa: BLE001 - Schülerfehler sollen als Traceback erscheinen
        print_exception(exc)
        return 1
    return 0


def _remove_hidden(path: str) -> None:
    """Versteckte Testdatei löschen, sobald sie gelesen ist (Schülercode kann sie nicht mehr öffnen)."""
    try:
        os.unlink(path)
    except OSError:
        pass


def mode_assert(args: list) -> int:
    main_file, libs, mem, assert_file, fd = args[0], args[1], int(args[2]), args[3], int(args[4])
    limit_memory(mem)
    setup_paths(libs)
    with open(main_file, encoding="utf-8", errors="replace") as handle:
        student = handle.read()
    with open(assert_file, encoding="utf-8", errors="replace") as handle:
        test_code = handle.read()
    _remove_hidden(assert_file)  # nicht mehr als Datei lesbar, bevor Schülercode läuft
    namespace = {"__name__": "schueler_modul", "__file__": main_file, "__builtins__": __builtins__}
    note = ""
    try:
        exec(compile(student, main_file, "exec"), namespace)
    except EOFError:
        # Typisch in Klasse 10: input() steht ohne "if __name__ == '__main__':" im Hauptteil.
        # Alles, was davor definiert wurde, ist im Namensraum; damit wird weiter geprüft.
        note = (" Hinweis: Das Hauptprogramm wartet beim Laden auf eine Eingabe (input())."
                " Geprüft wurde mit allem, was davor definiert ist. Besser steht das Hauptprogramm"
                " unter if __name__ == \"__main__\":")
    except SystemExit:
        emit(fd, {"ok": False, "phase": "load",
                  "message": "Das Programm beendet sich beim Laden selbst (exit/quit). Die Prüfung kann nicht laufen."})
        return 2
    except BaseException as exc:  # noqa: BLE001
        print_exception(exc)
        emit(fd, {"ok": False, "phase": "load",
                  "message": "Das Programm lässt sich nicht laden: " + type(exc).__name__ + ": " + str(exc)[:300]})
        return 2
    try:
        exec(compile(test_code, "<test>", "exec"), namespace)
    except AssertionError as exc:
        message = str(exc).strip()
        if not message:
            tb = exc.__traceback__
            line = None
            while tb is not None:
                if tb.tb_frame.f_code.co_filename == "<test>":
                    line = tb.tb_lineno
                tb = tb.tb_next
            lines = test_code.splitlines()
            if line and 0 < line <= len(lines):
                message = "Die Prüfung ist fehlgeschlagen: " + lines[line - 1].strip()[:200]
            else:
                message = "Eine Prüfung (assert) ist fehlgeschlagen."
        emit(fd, {"ok": False, "phase": "assert", "message": (message[:500] + note).strip()})
        return 1
    except SystemExit:
        emit(fd, {"ok": False, "phase": "assert", "message": "Das Programm hat die Prüfung vorzeitig beendet." + note})
        return 1
    except BaseException as exc:  # noqa: BLE001
        print_exception(exc)
        emit(fd, {"ok": False, "phase": "assert",
                  "message": "Beim Prüfen ist ein Fehler aufgetreten: " + type(exc).__name__ + ": " + str(exc)[:300] + note})
        return 1
    emit(fd, {"ok": True, "phase": "assert", "message": "Alle Prüfungen bestanden." + note})
    return 0


# --- SQL -----------------------------------------------------------------------

COMMENT_RE = re.compile(r"--[^\n]*|/\*.*?\*/", re.S)


def split_statements(sql: str) -> list:
    """Zerlegt ein Skript in einzelne Anweisungen (Kommentar-Reste fallen weg)."""
    import sqlite3
    statements = []
    buffer = ""
    for line in sql.splitlines(keepends=True):
        buffer += line
        if sqlite3.complete_statement(buffer):
            statements.append(buffer.strip())
            buffer = ""
    if COMMENT_RE.sub("", buffer).strip():
        statements.append(buffer.strip())
    return [s for s in statements if COMMENT_RE.sub("", s).strip().strip(";").strip()]


def cell_key(value):
    if isinstance(value, bool):
        value = int(value)
    if isinstance(value, (int, float)):
        return (0, float(value), "")
    if isinstance(value, str):
        return (1, 0.0, value)
    if value is None:
        return (2, 0.0, "")
    return (3, 0.0, repr(value))


def cells_equal(a, b) -> bool:
    if isinstance(a, bool):
        a = int(a)
    if isinstance(b, bool):
        b = int(b)
    if isinstance(a, (int, float)) and isinstance(b, (int, float)):
        if a == b:
            return True
        try:
            return math.isclose(float(a), float(b), rel_tol=1e-9, abs_tol=1e-9)
        except (OverflowError, ValueError):
            return False
    return type(a) is type(b) and a == b


def rows_equal(a, b) -> bool:
    return len(a) == len(b) and all(cells_equal(x, y) for x, y in zip(a, b))


def short(row) -> str:
    return "(" + ", ".join("NULL" if v is None else repr(v) for v in row)[:200] + ")"


def mode_sql(args: list) -> int:
    import sqlite3
    import time
    setup_file, student_file, expected_file = args[0], args[1], args[2]
    limit_memory(int(args[3]))
    fd, ordered, reveal, seconds = int(args[4]), args[5] == "1", args[6] == "1", int(args[7])
    read = lambda p: open(p, encoding="utf-8", errors="replace").read()  # noqa: E731
    setup, student, expected = read(setup_file), read(student_file), read(expected_file)
    _remove_hidden(setup_file)
    _remove_hidden(expected_file)
    deadline = time.monotonic() + seconds

    def guard(db):
        def authorizer(action, *_):
            if action in (sqlite3.SQLITE_ATTACH, sqlite3.SQLITE_DETACH):
                return sqlite3.SQLITE_DENY
            return sqlite3.SQLITE_OK
        db.set_authorizer(authorizer)
        db.set_progress_handler(lambda: 1 if time.monotonic() > deadline else 0, 10000)

    def fail(message: str, phase: str = "compare") -> int:
        emit(fd, {"ok": False, "phase": phase, "message": message})
        return 1

    # Erwartung zuerst: ein Fehler hier liegt an der Aufgabe, nicht an der Schülerlösung.
    try:
        ref = sqlite3.connect(":memory:")
        ref.executescript(setup)
        guard(ref)
        ref_cur = ref.execute(expected.strip() or "SELECT 1 WHERE 0")
        if ref_cur.description is None:
            return fail("Die erwartete Abfrage der Aufgabe liefert keine Tabelle.", "task")
        ref_rows = ref_cur.fetchmany(10001)
        ref_cols = len(ref_cur.description)
    except sqlite3.Error as exc:
        return fail("Die Aufgabe ist fehlerhaft eingerichtet (" + str(exc)[:200] + "). Bitte die Lehrkraft informieren.", "task")

    try:
        db = sqlite3.connect(":memory:")
        db.executescript(setup)
        guard(db)
    except sqlite3.Error as exc:
        return fail("Das Einrichtungsskript der Aufgabe ist fehlerhaft (" + str(exc)[:200] + ").", "task")

    statements = split_statements(student)
    if not statements:
        return fail("Die Datei enthält keine SQL-Anweisung.", "student")
    for number, statement in enumerate(statements[:-1], 1):
        try:
            db.execute(statement).fetchall()
        except sqlite3.Error as exc:
            return fail("Anweisung " + str(number) + " führt zu einem Fehler: " + str(exc)[:300], "student")
    try:
        cur = db.execute(statements[-1])
        if cur.description is None:
            return fail("Die letzte Anweisung liefert keine Ergebnistabelle. Am Ende der Datei muss eine Abfrage (SELECT) stehen.", "student")
        rows = cur.fetchmany(10001)
        cols = len(cur.description)
    except sqlite3.Error as exc:
        return fail("Die Abfrage führt zu einem Fehler: " + str(exc)[:300], "student")

    if cols != ref_cols:
        return fail(("Die Ergebnistabelle hat " + str(cols) + " Spalten, erwartet sind " + str(ref_cols) + ".")
                    if reveal else "Die Anzahl der Spalten stimmt nicht.")
    if len(rows) != len(ref_rows):
        return fail(("Die Ergebnistabelle hat " + str(len(rows)) + " Zeilen, erwartet sind " + str(len(ref_rows)) + ".")
                    if reveal else "Die Anzahl der Zeilen stimmt nicht.")
    left, right = list(rows), list(ref_rows)
    if not ordered:
        keyer = lambda r: tuple(cell_key(c) for c in r)  # noqa: E731
        left.sort(key=keyer)
        right.sort(key=keyer)
    for index, (a, b) in enumerate(zip(left, right), 1):
        if not rows_equal(a, b):
            if reveal:
                return fail("Zeile " + str(index) + " weicht ab: erhalten " + short(a) + ", erwartet " + short(b)
                            + ("" if ordered else " (Reihenfolge egal)") + ".")
            return fail("Das Ergebnis stimmt nicht mit der Erwartung überein." + (" Achte auch auf die Reihenfolge." if ordered else ""))
    emit(fd, {"ok": True, "phase": "compare", "message": "Das Ergebnis stimmt."})
    return 0


def main() -> int:
    mode, args = sys.argv[1], sys.argv[2:]
    if mode == "run":
        return mode_run(args)
    if mode == "assert":
        return mode_assert(args)
    if mode == "sql":
        return mode_sql(args)
    sys.stderr.write("Unbekannter Modus\n")
    return 64


if __name__ == "__main__":
    code = main()
    try:
        sys.stdout.flush()
        sys.stderr.flush()
    except (OSError, ValueError):
        pass
    os._exit(code)
