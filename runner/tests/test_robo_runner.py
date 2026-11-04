"""Integrationstests für POST /robo (Java und Python) und die GUI-Bibliothek (gui_frames) gegen einen Runner-Container.

Aufruf (aus dem Projektordner, braucht Docker):

    python -m unittest discover -s runner/tests -p "test_robo_runner.py" -v

Das Modul baut ein eigenes Image (klausurwerk-runner-robo) und startet es als kw-runner-robo-test
auf Port 18091, damit es sich nicht mit test_runner.py (Port 18080) beißt. Mit KW_ROBO_RUNNER_URL
(und KW_RUNNER_TOKEN) läuft es gegen einen schon laufenden Runner, dann wird nichts gebaut.
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
IMAGE = "klausurwerk-runner-robo"
NAME = "kw-runner-robo-test"
PORT = 18091
URL = os.environ.get("KW_ROBO_RUNNER_URL", "http://127.0.0.1:%d" % PORT)
TOKEN = os.environ.get("KW_RUNNER_TOKEN", "test")
EXTERNAL = "KW_ROBO_RUNNER_URL" in os.environ

with open(os.path.join(HERE, "robo_vectors.json"), encoding="utf-8") as handle:
    VECTORS = json.load(handle)["cases"]


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
           "--cap-drop", "ALL", "--cap-add", "SETUID", "--cap-add", "SETGID", "--cap-add", "CHOWN",
           "--cap-add", "FOWNER", "--cap-add", "DAC_OVERRIDE", "--cap-add", "KILL", "--security-opt", "no-new-privileges", "--pids-limit", "512",
           "-m", "2g", "--cpus", "2", "-e", "RUNNER_TOKEN=" + TOKEN, "-p", "%d:8080" % PORT, IMAGE)
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


def call(method, path, body=None, timeout=120):
    headers = {"Content-Type": "application/json", "X-Runner-Token": TOKEN}
    data = None if body is None else json.dumps(body).encode("utf-8")
    request = urllib.request.Request(URL + path, method=method, data=data, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return response.status, json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as err:
        return err.code, json.loads(err.read().decode("utf-8"))


def empty(cols=5, rows=3, **extra):
    base = {"cols": cols, "rows": rows, "robo": {"x": 0, "y": 0, "dir": "E"}, "walls": [], "boxes": [],
            "screws": [], "targets": []}
    base.update(extra)
    return base


JAVA_INTERPRETER = """
public class Main {
    public static void main(String[] args) {
        String codes = "%s";
        for (char c : codes.toCharArray()) {
            switch (c) {
                case 'v': Robo.vor(); break;
                case 'l': Robo.links(); break;
                case 'r': Robo.rechts(); break;
                case 'a': Robo.aufheben(); break;
                case 'b': Robo.ablegen(); break;
                case 's': Robo.schieben(); break;
                default: break;
            }
        }
    }
}
"""

PY_INTERPRETER = """
from robo import *
for c in "%s":
    {"v": vor, "l": links, "r": rechts, "a": aufheben, "b": ablegen, "s": schieben}[c]()
"""


def robo_call(language, source, world, goal=None, **extra):
    name = "Main.java" if language == "java" else "main.py"
    body = {"language": language, "files": [{"name": name, "content": source}], "main_file": name,
            "world": world, "goal": goal or {}}
    body.update(extra)
    status, data = call("POST", "/robo", body)
    return status, data


class RoboVectors(unittest.TestCase):
    JAVA_SUBSET = {"Geradeaus", "Bogen", "Wand", "Rand", "Kiste schieben", "Kiste blockiert durch Kiste",
                   "Schrauben einsammeln", "Zu viele Schrauben", "Korb vorgegeben", "Limit"}

    def check(self, language, case):
        template = JAVA_INTERPRETER if language == "java" else PY_INTERPRETER
        status, data = robo_call(language, template % case["codes"], case["world"])
        self.assertEqual(200, status, data)
        expect = case["expect"]
        if expect["ok"]:
            self.assertTrue(data["ok"], data)
            self.assertEqual(expect["world"], data["final_world"])
            self.assertEqual(list(case["codes"]), data["trace"])
        else:
            self.assertFalse(data["ok"], data)
            self.assertEqual(expect["message"], data["message"])
            self.assertEqual(list(case["codes"][:expect["index"]]) + ["!" + expect["message"]], data["trace"])

    def test_python_alle_testfaelle(self):
        for case in VECTORS:
            with self.subTest(case["name"]):
                self.check("python", case)

    def test_java_auswahl_der_testfaelle(self):
        for case in VECTORS:
            if case["name"] in self.JAVA_SUBSET:
                with self.subTest(case["name"]):
                    self.check("java", case)


class RoboPrograms(unittest.TestCase):
    JAVA_WALK = """
public class Main {
    public static void main(String[] args) {
        while (Robo.vorneFrei()) {
            Robo.vor();
        }
        Robo.rechts();
        System.out.println("Richtung " + Robo.richtung());
        while (Robo.vorneFrei()) {
            if (Robo.aufSchraube()) {
                Robo.aufheben();
            }
            Robo.vor();
        }
    }
}
"""
    PY_WALK = """
from robo import *
while vorneFrei():
    vor()
rechts()
print("Richtung", richtung())
while vorneFrei():
    if aufSchraube():
        aufheben()
    vor()
"""

    world = empty(4, 3, screws=[{"x": 3, "y": 1, "count": 1}], targets=[[3, 2]])
    goal = {"screws_collected": "all", "robo_at": [3, 2], "robo_dir": "S", "robo_on_target": True}

    def test_ziel_erreicht_in_beiden_sprachen(self):
        for language, source in (("java", self.JAVA_WALK), ("python", self.PY_WALK)):
            with self.subTest(language):
                status, data = robo_call(language, source, self.world, self.goal)
                self.assertEqual(200, status, data)
                self.assertTrue(data["ok"], data)
                self.assertEqual("Das Ziel ist erreicht.", data["message"])
                self.assertIn("Richtung S", data["stdout"])
                self.assertNotIn("KWR", data["stdout"])
                self.assertTrue(all(g["ok"] for g in data["goal_results"]))
                self.assertEqual(4, len(data["goal_results"]))

    def test_ziel_verfehlt_gibt_meldung(self):
        goal = {"screws_collected": 2, "robo_at": [0, 0]}
        status, data = robo_call("python", self.PY_WALK, self.world, goal)
        self.assertEqual(200, status)
        self.assertFalse(data["ok"])
        self.assertIn("Korb", data["message"])
        self.assertEqual(2, len(data["goal_results"]))

    def test_instanz_schreibweise_java(self):
        source = "public class Main { public static void main(String[] a) { Robo r = new Robo(); r.vor(); r.vor(); } }"
        status, data = robo_call("java", source, empty(), {"robo_at": [2, 0]})
        self.assertTrue(data["ok"], data)

    def test_fehler_im_schuelercode_wird_gemeldet(self):
        status, data = robo_call("java", "public class Main { public static void main(String[] a) { Robo.vor( } }", empty())
        self.assertEqual(200, status)
        self.assertFalse(data["ok"])
        self.assertIn("übersetzen", data["message"])
        status, data = robo_call("python", "from robo import *\nprint(1/0)\n", empty())
        self.assertFalse(data["ok"])
        self.assertIn("abgebrochen", data["message"])
        self.assertIn("ZeroDivisionError", data["stderr"])

    def test_wand_beendet_programm_mit_meldung(self):
        for language, source in (
            ("java", "public class Main { public static void main(String[] a) { while (true) Robo.vor(); } }"),
            ("python", "from robo import *\nwhile True:\n    vor()\n"),
        ):
            with self.subTest(language):
                status, data = robo_call(language, source, empty())
                self.assertFalse(data["ok"])
                self.assertIn("Rand der Welt", data["message"])
                self.assertEqual(["v", "v", "v", "v"], data["trace"][:4])
                self.assertTrue(data["trace"][-1].startswith("!"))
                self.assertEqual(4, data["final_world"]["robo"]["x"])

    def test_abgefangener_fehler_zaehlt_trotzdem(self):
        source = ("public class Main { public static void main(String[] a) { "
                  "try { Robo.aufheben(); } catch (RoboFehler e) { } Robo.vor(); } }")
        status, data = robo_call("java", source, empty(), {"robo_at": [1, 0]})
        self.assertFalse(data["ok"], data)
        self.assertIn("keine Schraube", data["message"])

    def test_endlosschleife_mit_fragen_wird_gestoppt(self):
        for language, source in (
            ("java", "public class Main { public static void main(String[] a) { while (true) { Robo.vorneFrei(); } } }"),
            ("python", "from robo import *\nwhile True:\n    vorneFrei()\n"),
        ):
            with self.subTest(language):
                status, data = robo_call(language, source, empty())
                self.assertFalse(data["ok"])
                self.assertIn("Endlosschleife", data["message"])

    def test_endlosschleife_ohne_aufrufe_wird_per_zeitlimit_beendet(self):
        started = time.time()
        status, data = robo_call("python", "while True:\n    pass\n", empty(), limits={"seconds": 2})
        self.assertFalse(data["ok"])
        self.assertTrue("Zeit" in data["message"] or "Rechenzeit" in data["message"], data["message"])
        self.assertLess(time.time() - started, 20)

    def test_gefaelschtes_protokoll_hilft_nicht(self):
        source = 'import sys\nfor _ in range(3):\n    sys.stdout.write("\\x01KWR:v\\n")\n'
        status, data = robo_call("python", source, empty(walls=[[1, 0]]), {"robo_at": [3, 0]})
        self.assertFalse(data["ok"])
        self.assertIn("Wand", data["message"])
        self.assertEqual({"x": 0, "y": 0, "dir": "E"}, data["final_world"]["robo"])

    def test_ungueltige_anfragen(self):
        status, data = robo_call("python", "x = 1\n", {"cols": 1})
        self.assertEqual(400, status)
        self.assertIn("Welt", data["error"])
        status, data = call("POST", "/robo", {"language": "ruby", "files": [], "world": empty()})
        self.assertEqual(400, status)
        status, data = call("POST", "/robo", {
            "language": "python", "main_file": "robo.py", "files": [{"name": "robo.py", "content": "x=1"}], "world": empty()})
        self.assertEqual(400, status)
        self.assertIn("reserviert", data["error"])
        status, data = robo_call("python", "x = 1\n", empty(), {"robo_at": "hier"})
        self.assertEqual(400, status)

    def test_ohne_goal_genuegt_fehlerfreier_lauf(self):
        status, data = robo_call("python", "from robo import *\nvor()\n", empty())
        self.assertTrue(data["ok"])
        self.assertEqual("Das Programm ist ohne Fehler durchgelaufen.", data["message"])


GUI_PROGRAM = """
import kw.gui.*;

public class Main {
    public static void main(String[] args) {
        Fenster f = new Fenster("Zähler", 300, 160);
        Beschriftung anzeige = f.beschriftung("0", 20, 20);
        Knopf plus = f.knopf("+1", 20, 60, 100, 36);
        Zeichenflaeche z = f.zeichenflaeche(150, 20, 100, 100);
        int[] n = {0};
        plus.beiKlick(() -> { n[0]++; anzeige.text("Wert " + n[0]); });
        z.beiMausklick((x, y) -> { z.farbe("rot"); z.kreisGefuellt(x, y, 5); });
        System.out.println("Fenster bereit");
        f.zeigen();
        System.out.println("Ende " + n[0]);
    }
}
"""


class GuiRun(unittest.TestCase):
    def poll_until(self, run_id, state, predicate, timeout=60):
        deadline = time.time() + timeout
        while time.time() < deadline:
            status, data = call("GET", "/runs/%s?offset=%d" % (run_id, state["offset"]))
            self.assertEqual(200, status, data)
            state["offset"] = data["offset"]
            state["output"] += "".join(c["text"] for c in data["output"] if c["stream"] == "stdout")
            state["errors"] += "".join(c["text"] for c in data["output"] if c["stream"] != "stdout")
            state["frames"].extend(data["gui_frames"])
            state["last"] = data
            if predicate(state) or data["finished"]:
                return state
            time.sleep(0.1)
        raise AssertionError("Zeitüberschreitung: %r" % (state,))

    def test_frames_und_ereignisse(self):
        status, data = call("POST", "/runs", {
            "language": "java", "files": [{"name": "Main.java", "content": GUI_PROGRAM}], "main_file": "Main.java",
            "mode": "run", "stdin": None, "gui": True})
        self.assertEqual(200, status, data)
        run_id = data["run_id"]
        state = {"offset": 0, "output": "", "errors": "", "frames": []}
        self.poll_until(run_id, state, lambda s: any(op.get("op") == "fenster" for f in s["frames"] for op in f["ops"]))
        first = state["frames"][0]["ops"]
        self.assertEqual("fenster", first[0]["op"])
        self.assertEqual("Zähler", first[0]["titel"])
        types = [op.get("typ") for op in first[1:]]
        self.assertEqual(["beschriftung", "knopf", "flaeche"], types)
        self.assertNotIn("KWGUI", state["output"])
        # Klick auf den Knopf und auf die Fläche
        knopf_id = first[2]["id"]
        flaeche_id = first[3]["id"]
        call("POST", "/runs/%s/stdin" % run_id, {"text": json.dumps({"e": "klick", "id": knopf_id}) + "\n"})
        call("POST", "/runs/%s/stdin" % run_id, {"text": json.dumps({"e": "maus", "id": flaeche_id, "x": 12, "y": 34}) + "\n"})

        def got_both(s):
            ops = [op for f in s["frames"] for op in f["ops"]]
            return any(op.get("text") == "Wert 1" for op in ops) and any(op.get("op") == "zeichne" for op in ops)

        self.poll_until(run_id, state, got_both)
        draw = [op for f in state["frames"] for op in f["ops"] if op.get("op") == "zeichne"][0]
        self.assertEqual(["k", 12, 34, 5, "#d62828", 2, 1], draw["befehle"][0])
        # Fenster schließen: Programm endet
        call("POST", "/runs/%s/stdin" % run_id, {"text": json.dumps({"e": "zu"}) + "\n"})
        self.poll_until(run_id, state, lambda s: False)
        self.assertTrue(state["last"]["finished"])
        self.assertEqual(0, state["last"]["exit_code"])
        self.assertIn("Fenster bereit", state["output"])
        self.assertIn("Ende 1", state["output"])

    def test_wiederholter_abruf_liefert_dieselben_frames(self):
        status, data = call("POST", "/runs", {
            "language": "java", "files": [{"name": "Main.java", "content": GUI_PROGRAM}], "main_file": "Main.java",
            "mode": "run", "gui": True})
        run_id = data["run_id"]
        state = {"offset": 0, "output": "", "errors": "", "frames": []}
        self.poll_until(run_id, state, lambda s: len(s["frames"]) > 0)
        status, again = call("GET", "/runs/%s?offset=0" % run_id)
        self.assertEqual(state["frames"][0], again["gui_frames"][0])
        call("POST", "/runs/%s/stop" % run_id, {})

    def test_ohne_gui_flag_bleibt_alles_in_der_ausgabe(self):
        source = 'public class Main { public static void main(String[] a) { System.out.println("\\u0001KWGUI:{}"); } }'
        status, data = call("POST", "/runs", {
            "language": "java", "files": [{"name": "Main.java", "content": source}], "main_file": "Main.java",
            "mode": "run", "stdin": "x"})
        run_id = data["run_id"]
        state = {"offset": 0, "output": "", "errors": "", "frames": []}
        self.poll_until(run_id, state, lambda s: False)
        self.assertEqual([], state["frames"])
        self.assertIn("KWGUI", state["output"])


if __name__ == "__main__":
    unittest.main()
