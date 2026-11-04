"""Integrationstests gegen den laufenden Runner-Container.

Aufruf (aus dem Projektordner, braucht Docker):

    python -m unittest discover -s runner/tests -v

Die Tests bauen das Image (klausurwerk-runner-dev) und starten den Container mit
denselben Einschränkungen wie compose.yaml (kw-runner-test, Port 18080). Läuft
bereits ein Runner, den du verwenden willst, setze KW_RUNNER_URL (und
KW_RUNNER_TOKEN); dann wird nichts gebaut oder gestartet.
"""
import json
import os
import subprocess
import time
import unittest
import urllib.error
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
RUNNER_DIR = os.path.dirname(HERE)
IMAGE = "klausurwerk-runner-dev"
NAME = "kw-runner-test"
URL = os.environ.get("KW_RUNNER_URL", "http://127.0.0.1:18080")
TOKEN = os.environ.get("KW_RUNNER_TOKEN", "test")
EXTERNAL = "KW_RUNNER_URL" in os.environ


def docker(*args, check=True, timeout=900):
    env = dict(os.environ, MSYS_NO_PATHCONV="1")
    return subprocess.run(["docker", *args], check=check, timeout=timeout, env=env,
                          stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, encoding="utf-8")


def setUpModule():
    if EXTERNAL:
        return
    docker("build", "-q", "-t", IMAGE, RUNNER_DIR)
    docker("rm", "-f", NAME, check=False)
    docker("run", "-d", "--rm", "--name", NAME, "--read-only",
           "--tmpfs", "/tmp:size=256m,mode=1777", "--tmpfs", "/work:size=512m,mode=1777",
           "--cap-drop", "ALL", "--security-opt", "no-new-privileges", "--pids-limit", "512",
           "-m", "2g", "--cpus", "2", "-e", "RUNNER_TOKEN=" + TOKEN, "-p", "18080:8080", IMAGE)
    deadline = time.time() + 30
    while time.time() < deadline:
        try:
            with urllib.request.urlopen(URL + "/healthz", timeout=2) as response:
                if response.read() == b"ok":
                    return
        except OSError:
            time.sleep(0.5)
    raise RuntimeError("Runner startet nicht:\n" + docker("logs", NAME, check=False).stdout)


def tearDownModule():
    if not EXTERNAL:
        docker("stop", "-t", "2", NAME, check=False)
        docker("rm", "-f", NAME, check=False)


def call(method, path, body=None, token=TOKEN, timeout=120):
    headers = {"Content-Type": "application/json"}
    if token is not None:
        headers["X-Runner-Token"] = token
    data = None if body is None else json.dumps(body).encode("utf-8")
    request = urllib.request.Request(URL + path, method=method, data=data, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return response.status, json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as err:
        return err.code, json.loads(err.read().decode("utf-8"))


def java_files(code, name="Main.java"):
    return [{"name": name, "content": code}]


class Result:
    """Gesammelter Lauf (alle Chunks, Endstatus)."""

    def __init__(self, run_id, status):
        self.run_id = run_id
        self.chunks = []
        self.offset = 0
        self.status = status

    @property
    def stdout(self):
        return "".join(c["text"] for c in self.chunks if c["stream"] == "stdout")

    @property
    def stderr(self):
        return "".join(c["text"] for c in self.chunks if c["stream"] == "stderr")

    @property
    def system(self):
        return "".join(c["text"] for c in self.chunks if c["stream"] == "system")


def start(body):
    status, data = call("POST", "/runs", body)
    assert status == 200, (status, data)
    return Result(data["run_id"], None)


def pump(result, until=None, timeout=90):
    """Pollt bis Ende oder bis ``until(result)`` wahr wird."""
    deadline = time.time() + timeout
    while time.time() < deadline:
        status, data = call("GET", "/runs/%s?offset=%d" % (result.run_id, result.offset))
        assert status == 200, (status, data)
        result.chunks.extend(data["output"])
        result.offset = data["offset"]
        result.status = data
        if data["finished"] or (until and until(result)):
            return result
        time.sleep(0.1)
    raise AssertionError("Zeitüberschreitung beim Abruf, bisher: " + repr(result.chunks[-3:]))


def run_java(code, **extra):
    body = {"language": "java", "java_version": "21", "main_file": "Main.java",
            "files": java_files(code), "mode": "run"}
    body.update(extra)
    return pump(start(body))


HELLO = 'public class Main { public static void main(String[] a) { System.out.println("Hallo äöü " + Runtime.version().feature()); } }'


class TokenTests(unittest.TestCase):
    def test_healthz_ohne_token(self):
        with urllib.request.urlopen(URL + "/healthz", timeout=5) as response:
            self.assertEqual(response.read(), b"ok")

    def test_ohne_token_abgelehnt(self):
        self.assertEqual(call("POST", "/runs", {}, token=None)[0], 401)

    def test_falsches_token_abgelehnt(self):
        self.assertEqual(call("POST", "/runs", {}, token="falsch")[0], 401)
        self.assertEqual(call("GET", "/runs/r_000000000000000000000000", token="falsch")[0], 401)

    def test_unbekannte_route(self):
        self.assertEqual(call("GET", "/gibt-es-nicht")[0], 404)

    def test_unbekannter_lauf(self):
        self.assertEqual(call("GET", "/runs/r_000000000000000000000000")[0], 404)


class ValidationTests(unittest.TestCase):
    def test_ungueltiger_dateiname(self):
        for name in ("../x.java", "a/b.java", ".versteckt", "mit leer.java", ""):
            status, data = call("POST", "/runs", {"language": "java", "main_file": name, "files": java_files("x", name)})
            self.assertEqual(status, 400, name)
            self.assertIn("error", data)

    def test_zu_viele_dateien(self):
        files = [{"name": "F%d.java" % i, "content": ""} for i in range(21)]
        self.assertEqual(call("POST", "/runs", {"language": "java", "files": files, "main_file": "F0.java"})[0], 400)

    def test_zu_gross(self):
        files = [{"name": "Main.java", "content": "x" * (1024 * 1024 + 1)}]
        self.assertEqual(call("POST", "/runs", {"language": "java", "files": files, "main_file": "Main.java"})[0], 413)

    def test_java_version(self):
        status, _ = call("POST", "/runs", {"language": "java", "java_version": "8", "main_file": "Main.java",
                                           "files": java_files(HELLO)})
        self.assertEqual(status, 400)

    def test_kein_json(self):
        request = urllib.request.Request(URL + "/runs", method="POST", data=b"{kaputt",
                                         headers={"X-Runner-Token": TOKEN})
        with self.assertRaises(urllib.error.HTTPError) as ctx:
            urllib.request.urlopen(request, timeout=5)
        self.assertEqual(ctx.exception.code, 400)


class JavaRunTests(unittest.TestCase):
    def test_hello_java21(self):
        result = run_java(HELLO)
        self.assertEqual(result.stdout, "Hallo äöü 21\n")
        self.assertEqual(result.status["exit_code"], 0)
        self.assertTrue(result.status["compile_ok"])

    def test_hello_java25(self):
        result = run_java(HELLO, java_version="25")
        self.assertEqual(result.stdout, "Hallo äöü 25\n")
        self.assertEqual(result.status["exit_code"], 0)

    def test_paket_und_zweite_klasse(self):
        files = [
            {"name": "Main.java", "content": "package app; public class Main { public static void main(String[] a) { System.out.println(new Helfer().gruss()); } }"},
            {"name": "Helfer.java", "content": "package app; class Helfer { String gruss() { return \"ok\"; } }"},
        ]
        result = pump(start({"language": "java", "main_file": "Main.java", "files": files}))
        self.assertEqual(result.stdout, "ok\n")

    def test_kompilierfehler_strukturiert(self):
        code = "public class Main {\n    public static void main(String[] a) {\n        int x = 5\n        System.out.println(x);\n    }\n}\n"
        result = run_java(code)
        errors = result.status["compile_errors"]
        self.assertTrue(result.status["finished"])
        self.assertFalse(result.status["compile_ok"])
        self.assertEqual(result.stdout, "")
        self.assertEqual(len(errors), 1, errors)
        self.assertEqual(errors[0]["file"], "Main.java")
        self.assertEqual(errors[0]["line"], 3)
        self.assertEqual(errors[0]["column"], 18)
        self.assertIn("erwartet", errors[0]["message"])  # deutsche javac-Meldung

    def test_mehrere_kompilierfehler(self):
        code = "public class Main {\n  void f() { int a = \"text\"; undefiniert(); }\n}\n"
        errors = run_java(code, mode="syntax").status["compile_errors"]
        self.assertEqual([e["line"] for e in errors], [2, 2])
        self.assertTrue(all(e["column"] > 1 for e in errors))

    def test_syntax_modus_nur_kompilieren(self):
        result = run_java('public class Main { public static void main(String[] a) { System.out.println("nie"); } }', mode="syntax")
        self.assertEqual(result.stdout, "")
        self.assertEqual(result.status["exit_code"], 0)
        self.assertTrue(result.status["compile_ok"])
        self.assertEqual(result.status["compile_errors"], [])

    def test_ausnahme_exit_code(self):
        result = run_java('public class Main { public static void main(String[] a) { int[] x = new int[1]; x[2] = 1; } }')
        self.assertIn("ArrayIndexOutOfBoundsException", result.stderr)
        self.assertEqual(result.status["exit_code"], 1)

    def test_system_exit(self):
        result = run_java('public class Main { public static void main(String[] a) { System.exit(7); } }')
        self.assertEqual(result.status["exit_code"], 7)

    def test_scanner_interaktiv(self):
        code = ('import java.util.Scanner; public class Main { public static void main(String[] a) {'
                ' Scanner s = new Scanner(System.in); System.out.print("Zahl: "); int n = s.nextInt();'
                ' System.out.println("Doppelt: " + (2 * n)); System.out.print("Name: "); String w = s.next();'
                ' System.out.println("Hallo " + w); } }')
        result = start({"language": "java", "main_file": "Main.java", "files": java_files(code)})
        pump(result, until=lambda r: "Zahl: " in r.stdout)
        self.assertFalse(result.status["finished"], "wartet auf Eingabe")
        self.assertEqual(call("POST", "/runs/%s/stdin" % result.run_id, {"text": "21\n"})[0], 200)
        pump(result, until=lambda r: "Name: " in r.stdout)
        self.assertIn("Doppelt: 42", result.stdout)
        self.assertEqual(call("POST", "/runs/%s/stdin" % result.run_id, {"text": "Äpfel\n"})[0], 200)
        pump(result)
        self.assertEqual(result.stdout, "Zahl: Doppelt: 42\nName: Hallo Äpfel\n")
        self.assertEqual(result.status["exit_code"], 0)

    def test_vorgegebene_eingabe(self):
        code = ('import java.util.Scanner; public class Main { public static void main(String[] a) {'
                ' Scanner s = new Scanner(System.in); System.out.println(s.nextInt() + s.nextInt()); } }')
        result = run_java(code, stdin="3\n4\n")
        self.assertEqual(result.stdout, "7\n")

    def test_stdin_nach_ende_ist_fehler(self):
        result = run_java(HELLO)
        self.assertEqual(call("POST", "/runs/%s/stdin" % result.run_id, {"text": "x\n"})[0], 409)

    def test_stopp(self):
        code = 'public class Main { public static void main(String[] a) throws Exception { System.out.println("los"); Thread.sleep(60000); } }'
        result = start({"language": "java", "main_file": "Main.java", "files": java_files(code)})
        pump(result, until=lambda r: "los" in r.stdout)
        self.assertEqual(call("POST", "/runs/%s/stop" % result.run_id)[0], 200)
        pump(result, timeout=15)
        self.assertTrue(result.status["finished"])

    def test_offset_abruf(self):
        result = run_java(HELLO)
        status, data = call("GET", "/runs/%s?offset=%d" % (result.run_id, result.offset))
        self.assertEqual(status, 200)
        self.assertEqual(data["output"], [])
        self.assertEqual(data["offset"], result.offset)
        status, data = call("GET", "/runs/%s?offset=0" % result.run_id)
        self.assertEqual(data["output"][0]["stream"], "stdout")

    def test_token_nicht_in_umgebung(self):
        code = ('public class Main { public static void main(String[] a) { '
                'System.out.println(System.getenv("RUNNER_TOKEN") + "|" + System.getenv("HOME") + "|" + System.getenv().size()); } }')
        out = run_java(code).stdout
        self.assertTrue(out.startswith("null|/tmp/r_"), out)

    def test_ausgabe_ist_utf8_robust(self):
        code = 'public class Main { public static void main(String[] a) throws Exception { System.out.write(new byte[]{(byte)0xff, 65, (byte)0xc3}); System.out.flush(); } }'
        result = run_java(code)
        self.assertIn("A", result.stdout)
        self.assertIn("�", result.stdout)


class LimitTests(unittest.TestCase):
    def test_endlosschleife(self):
        started = time.time()
        result = run_java('public class Main { public static void main(String[] a) { long x = 0; while (true) { x++; } } }')
        self.assertTrue(result.status["timed_out"], result.status)
        self.assertLess(time.time() - started, 30)
        self.assertIn("Rechenzeit", result.system + result.status["message"] if result.status["message"] else result.system)

    def test_schlaf_endlos_mit_eingabe_hat_wandzeit(self):
        code = 'public class Main { public static void main(String[] a) throws Exception { Thread.sleep(600000); } }'
        started = time.time()
        result = run_java(code, stdin="x")
        self.assertTrue(result.status["timed_out"])
        self.assertLess(time.time() - started, 30)

    def test_speicherfresser(self):
        code = ('import java.util.*; public class Main { public static void main(String[] a) {'
                ' List<long[]> l = new ArrayList<>(); while (true) l.add(new long[1_000_000]); } }')
        result = run_java(code)
        self.assertTrue(result.status["finished"])
        self.assertNotEqual(result.status["exit_code"], 0)
        self.assertIn("OutOfMemoryError", result.stdout + result.stderr)

    def test_thread_flut(self):
        code = ('public class Main { public static void main(String[] a) throws Exception {'
                ' for (int i = 0; i < 100000; i++) { Thread t = new Thread(() -> { try { Thread.sleep(60000); } catch (Exception e) {} });'
                ' t.start(); } } }')
        result = run_java(code)
        self.assertTrue(result.status["finished"])
        self.assertNotEqual(result.status["exit_code"], 0)
        # Der Container lebt weiter und nimmt neue Läufe an.
        self.assertEqual(run_java(HELLO).stdout, "Hallo äöü 21\n")

    def test_rekursion_stackoverflow(self):
        code = 'public class Main { static int f(int n) { return f(n + 1) + 1; } public static void main(String[] a) { f(0); } }'
        result = run_java(code)
        self.assertIn("StackOverflowError", result.stderr)

    def test_ausgabe_flut(self):
        code = 'public class Main { public static void main(String[] a) { while (true) System.out.println("xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"); } }'
        result = run_java(code)
        self.assertLessEqual(len(result.stdout.encode("utf-8")), 1024 * 1024)
        self.assertEqual(result.status["killed_reason"], "output")
        self.assertIn("Ausgabe ist zu lang", result.system)

    def test_dateien_flut(self):
        code = ('import java.io.*; public class Main { public static void main(String[] a) throws Exception {'
                ' byte[] b = new byte[1024 * 1024]; for (int i = 0; ; i++) { try (FileOutputStream f = new FileOutputStream("f" + i)) { f.write(b); } } } }')
        result = run_java(code)
        self.assertTrue(result.status["finished"])
        self.assertNotEqual(result.status["exit_code"], 0)

    def test_fork_bombe_python(self):
        code = "import os\nwhile True:\n    os.fork()\n"
        result = pump(start({"language": "python", "main_file": "m.py", "files": [{"name": "m.py", "content": code}]}))
        self.assertTrue(result.status["finished"])
        self.assertEqual(run_java(HELLO).stdout, "Hallo äöü 21\n")

    def test_parallelitaet_warteschlange(self):
        code = 'public class Main { public static void main(String[] a) throws Exception { Thread.sleep(1500); System.out.println("fertig"); } }'
        runs = [start({"language": "java", "main_file": "Main.java", "files": java_files(code), "stdin": ""}) for _ in range(6)]
        seen_queued = False
        deadline = time.time() + 120
        while time.time() < deadline and not all(r.status and r.status["finished"] for r in runs):
            for r in runs:
                if not (r.status and r.status["finished"]):
                    pump(r, until=lambda x: True, timeout=10)
                    seen_queued = seen_queued or r.status["status"] == "queued"
            time.sleep(0.1)
        self.assertTrue(all(r.stdout == "fertig\n" for r in runs), [r.stdout for r in runs])
        self.assertTrue(seen_queued, "mindestens ein Lauf stand in der Warteschlange")


class PythonRunTests(unittest.TestCase):
    def test_python_lauf_mit_traceback(self):
        code = "print('hallo')\nx = 1 / 0\n"
        result = pump(start({"language": "python", "main_file": "m.py", "files": [{"name": "m.py", "content": code}]}))
        self.assertEqual(result.stdout, "hallo\n")
        self.assertIn("ZeroDivisionError", result.stderr)
        self.assertNotIn("boot_py", result.stderr)
        self.assertEqual(result.status["exit_code"], 1)


class TestsEndpointTests(unittest.TestCase):
    def post_tests(self, body):
        status, data = call("POST", "/tests", body)
        self.assertEqual(status, 200, data)
        return data["results"]

    ADDIEREN = ('import java.util.Scanner; public class Main { public int summe(int a, int b) { return a + b; }'
                ' public static void main(String[] x) { Scanner s = new Scanner(System.in);'
                ' System.out.println(s.nextInt() + s.nextInt()); } }')

    def test_java_io_und_java_main(self):
        results = self.post_tests({
            "language": "java", "java_version": "21", "main_file": "Main.java", "files": java_files(self.ADDIEREN),
            "tests": [
                {"name": "Addition", "type": "io", "input": "2\n5\n", "expected_output": "7", "compare": "trim"},
                {"name": "Falsch", "type": "io", "input": "2\n5\n", "expected_output": "8", "compare": "exact"},
                {"name": "contains", "type": "io", "input": "2\n5\n", "expected_output": "7", "compare": "contains"},
                {"name": "regex", "type": "io", "input": "2\n5\n", "expected_output": "^\\d$", "compare": "regex"},
                {"name": "ws", "type": "io", "input": "2\n5\n", "expected_output": " 7 \n\n", "compare": "ignore_whitespace"},
                {"name": "Methode", "type": "java_main",
                 "code": "public class KwTest { public static void main(String[] a) { if (new Main().summe(2, 3) != 5) System.exit(1); System.out.println(\"gut\"); } }"},
                {"name": "Methode falsch", "type": "java_main",
                 "code": "public class KwTest { public static void main(String[] a) { if (new Main().summe(2, 3) != 6) throw new IllegalStateException(\"summe falsch\"); } }"},
            ]})
        self.assertEqual([r["ok"] for r in results], [True, False, True, True, True, True, False], results)
        self.assertEqual(results[0]["name"], "Addition")
        self.assertEqual(results[0]["stdout"], "7\n")
        self.assertIn("duration_ms", results[0])
        self.assertEqual(results[5]["stdout"], "gut\n")
        self.assertIn("summe falsch", results[6]["stderr"])
        self.assertNotIn("Erwartet", results[1]["message"])

    def test_reveal_zeigt_erwartung(self):
        results = self.post_tests({
            "language": "java", "main_file": "Main.java", "files": java_files(self.ADDIEREN), "reveal": True,
            "tests": [{"name": "x", "type": "io", "input": "2\n5\n", "expected_output": "8", "compare": "exact"}]})
        self.assertIn("Erwartet", results[0]["message"])
        self.assertIn("8", results[0]["message"])

    def test_java_kompilierfehler_in_tests(self):
        results = self.post_tests({
            "language": "java", "main_file": "Main.java", "files": java_files("public class Main { int x = ; }"),
            "tests": [{"name": "a", "type": "io", "input": "", "expected_output": ""}]})
        self.assertFalse(results[0]["ok"])
        self.assertIn("übersetzen", results[0]["message"])

    def test_java_endlosschleife_im_test_isoliert(self):
        code = ('public class Main { public static void main(String[] a) { if (a.length == 0 && System.getenv("X") == null) {'
                ' java.util.Scanner s = new java.util.Scanner(System.in); String l = s.hasNextLine() ? s.nextLine() : "";'
                ' if (l.equals("loop")) while (true) {} System.out.println(l); } } }')
        started = time.time()
        results = self.post_tests({
            "language": "java", "main_file": "Main.java", "files": java_files(code), "limits": {"seconds": 3},
            "tests": [{"name": "Schleife", "type": "io", "input": "loop\n", "expected_output": "loop"},
                      {"name": "normal", "type": "io", "input": "ok\n", "expected_output": "ok"}]})
        self.assertFalse(results[0]["ok"])
        self.assertIn("Rechenzeit", results[0]["message"])
        self.assertTrue(results[1]["ok"], results[1])
        self.assertLess(time.time() - started, 40)

    def test_python_io_und_assert(self):
        code = "def summe(a, b):\n    return a + b\n\nif __name__ == '__main__':\n    print(summe(int(input()), int(input())))\n"
        results = self.post_tests({
            "language": "python", "main_file": "m.py", "files": [{"name": "m.py", "content": code}],
            "tests": [
                {"name": "io", "type": "io", "input": "2\n3\n", "expected_output": "5"},
                {"name": "assert ok", "type": "python_assert", "code": "assert summe(2, 3) == 5"},
                {"name": "assert falsch", "type": "python_assert", "code": "assert summe(2, 3) == 6, 'summe(2, 3) liefert nicht 6'"},
                {"name": "assert ohne text", "type": "python_assert", "code": "x = 1\nassert summe(1, 1) == 3"},
                {"name": "name fehlt", "type": "python_assert", "code": "assert gibt_es_nicht(1)"},
                {"name": "main nicht ausgefuehrt", "type": "python_assert", "code": "assert __name__ != '__main__'"},
            ]})
        self.assertEqual([r["ok"] for r in results], [True, True, False, False, False, True], results)
        self.assertIn("liefert nicht 6", results[2]["message"])
        self.assertIn("summe(1, 1) == 3", results[3]["message"])
        self.assertIn("NameError", results[4]["message"])

    def test_python_syntaxfehler_und_input_ende(self):
        results = self.post_tests({
            "language": "python", "main_file": "m.py", "files": [{"name": "m.py", "content": "def f(:\n"}],
            "tests": [{"name": "a", "type": "python_assert", "code": "assert True"},
                      {"name": "b", "type": "io", "input": "", "expected_output": ""}]})
        self.assertFalse(results[0]["ok"])
        self.assertIn("SyntaxError", results[0]["message"])
        self.assertFalse(results[1]["ok"])

    SETUP = ("CREATE TABLE schueler (id INTEGER PRIMARY KEY, name TEXT, note REAL);"
             "INSERT INTO schueler VALUES (1,'Cem',1.0),(2,'Anna',2),(3,'Bo',3.5);")

    def sql_tests(self, student, tests, **extra):
        body = {"language": "sql", "main_file": "abfrage.sql", "sql_setup": self.SETUP,
                "files": [{"name": "abfrage.sql", "content": student}], "tests": tests}
        body.update(extra)
        return self.post_tests(body)

    def test_sql_result(self):
        expected = "SELECT name FROM schueler ORDER BY name"
        test = {"name": "t", "type": "sql_result", "expected_query": expected, "order_sensitive": True}
        unordered = dict(test, order_sensitive=False)
        results = self.sql_tests("-- Namen\nSELECT name FROM schueler ORDER BY name;\n", [test])
        self.assertTrue(results[0]["ok"], results[0])
        wrong_order = self.sql_tests("SELECT name FROM schueler ORDER BY name DESC", [test, unordered])
        self.assertFalse(wrong_order[0]["ok"])
        self.assertIn("Reihenfolge", wrong_order[0]["message"])
        self.assertTrue(wrong_order[1]["ok"], wrong_order[1])

    def test_sql_letzte_anweisung_und_zahlen_tolerant(self):
        test = {"name": "t", "type": "sql_result", "expected_query": "SELECT note FROM schueler WHERE id = 1"}
        results = self.sql_tests("UPDATE schueler SET note = note;\nSELECT 1;\nSELECT CAST(1 AS INTEGER) AS wert FROM schueler WHERE id = 1;", [test])
        self.assertTrue(results[0]["ok"], results[0])  # 1 == 1.0
        text = self.sql_tests("SELECT '1' FROM schueler WHERE id = 1", [test])
        self.assertFalse(text[0]["ok"])  # Zeichenketten exakt

    def test_sql_fehler_und_spalten(self):
        test = {"name": "t", "type": "sql_result", "expected_query": "SELECT name, note FROM schueler"}
        syntax = self.sql_tests("SELEKT name FROM schueler", [test])
        self.assertFalse(syntax[0]["ok"])
        self.assertIn("Fehler", syntax[0]["message"])
        spalten = self.sql_tests("SELECT name FROM schueler", [test], reveal=True)
        self.assertIn("1 Spalten, erwartet sind 2", spalten[0]["message"])
        keine = self.sql_tests("DELETE FROM schueler", [test])
        self.assertIn("keine Ergebnistabelle", keine[0]["message"])

    def test_sql_endlosabfrage_bricht_ab(self):
        test = {"name": "t", "type": "sql_result", "expected_query": "SELECT 1"}
        started = time.time()
        results = self.sql_tests("WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM r) SELECT count(*) FROM r",
                                 [test], limits={"seconds": 2})
        self.assertFalse(results[0]["ok"])
        self.assertLess(time.time() - started, 20)

    def test_sql_attach_verboten(self):
        test = {"name": "t", "type": "sql_result", "expected_query": "SELECT 1"}
        results = self.sql_tests("ATTACH DATABASE '/tmp/x.db' AS x;\nSELECT 1", [test])
        self.assertFalse(results[0]["ok"])

    def test_falscher_testtyp_und_leere_liste(self):
        results = self.post_tests({"language": "sql", "main_file": "a.sql", "files": [{"name": "a.sql", "content": "SELECT 1"}],
                                   "tests": [{"name": "x", "type": "io"}]})
        self.assertFalse(results[0]["ok"])
        self.assertEqual(call("POST", "/tests", {"language": "sql", "main_file": "a.sql",
                                                 "files": [{"name": "a.sql", "content": "SELECT 1"}], "tests": []})[0], 400)


class HousekeepingTests(unittest.TestCase):
    def test_arbeitsverzeichnis_wird_geloescht(self):
        result = run_java(HELLO)
        out = docker("exec", NAME, "sh", "-c", "ls /work | grep -c '^r_%s' || true" % result.run_id[2:]) if not EXTERNAL else None
        if out is not None:
            self.assertEqual(out.stdout.strip(), "0")

    def test_verlassener_lauf_wird_beendet(self):
        code = 'public class Main { public static void main(String[] a) throws Exception { Thread.sleep(600000); } }'
        status, data = call("POST", "/runs", {"language": "java", "main_file": "Main.java", "files": java_files(code)})
        self.assertEqual(status, 200)
        time.sleep(75)  # 60 s ohne Abruf plus Reaper-Takt
        self.assertEqual(call("GET", "/runs/" + data["run_id"])[0], 404)


if __name__ == "__main__":
    unittest.main()
