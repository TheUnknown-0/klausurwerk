"""Tests der Robo-Regeln (runner/libs/python/robo.py) und der Zielprüfung, ohne Container.

Aufruf (aus dem Projektordner):

    python -m unittest discover -s runner/tests -p "test_robo_engine.py" -v

Die gemeinsamen Testfälle stehen in robo_vectors.json und werden auch von
frontend/test/robo-world.test.js (JavaScript) und test_robo_runner.py (Java und Python
im Runner) benutzt. Die Tests des Runner-Moduls (modules/robo.py) brauchen Linux (fcntl)
und werden sonst übersprungen.
"""
import importlib.util
import json
import os
import sys
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
RUNNER = os.path.dirname(HERE)
LIB = os.path.join(RUNNER, "libs", "python", "robo.py")

spec = importlib.util.spec_from_file_location("kw_robo_lib_test", LIB)
robo = importlib.util.module_from_spec(spec)
spec.loader.exec_module(robo)

with open(os.path.join(HERE, "robo_vectors.json"), encoding="utf-8") as handle:
    VECTORS = json.load(handle)["cases"]


def empty(cols=5, rows=3, **extra):
    base = {"cols": cols, "rows": rows, "robo": {"x": 0, "y": 0, "dir": "E"}, "walls": [], "boxes": [],
            "screws": [], "targets": []}
    base.update(extra)
    return base


class VectorTests(unittest.TestCase):
    def test_gemeinsame_testfaelle(self):
        for case in VECTORS:
            with self.subTest(case["name"]):
                world = robo.World(case["world"])
                failed = None
                for index, code in enumerate(case["codes"]):
                    try:
                        world.step(code)
                    except robo.RoboFehler as exc:
                        failed = (index, str(exc))
                        break
                expect = case["expect"]
                if expect["ok"]:
                    self.assertIsNone(failed, failed)
                    self.assertEqual(expect["world"], world.to_dict())
                else:
                    self.assertEqual((expect["index"], expect["message"]), failed)

    def test_kaputt_bleibt_kaputt(self):
        world = robo.World(empty(walls=[[1, 0]]))
        with self.assertRaises(robo.RoboFehler):
            world.step("v")
        with self.assertRaises(robo.RoboFehler) as ctx:
            world.step("l")
        self.assertIn("kaputt", str(ctx.exception))


class WorldValidation(unittest.TestCase):
    def test_gueltige_welt(self):
        world = robo.World(empty(robo={"x": 1, "y": 1, "dir": "O"}))
        self.assertEqual("E", world.dir)

    def test_ungueltige_welten(self):
        bad = [
            None,
            empty(cols=1),
            empty(cols=31),
            empty(robo={"x": 9, "y": 0, "dir": "E"}),
            empty(robo={"x": 0, "y": 0, "dir": "X"}),
            empty(walls=[[0, 0]]),
            empty(boxes=[[0, 0]]),
            empty(walls=[[1, 1]], boxes=[[1, 1]]),
            empty(walls=[[9, 9]]),
            empty(screws=[{"x": 1, "y": 1, "count": 0}]),
            empty(screws=[{"x": 1, "y": 1, "count": 1}, {"x": 1, "y": 1, "count": 1}]),
            empty(walls=[[2, 2]], screws=[{"x": 2, "y": 2, "count": 1}]),
            empty(targets=[[7, 7]]),
        ]
        for raw in bad:
            with self.subTest(raw=raw):
                with self.assertRaises(ValueError):
                    robo.World(raw)


class LibraryApi(unittest.TestCase):
    """Die Schülerschnittstelle (vor(), vorneFrei(), ...) mit Welt aus robo_world.json."""

    def setUp(self):
        self.cwd = os.getcwd()
        self.tmp = tempfile.TemporaryDirectory()
        os.chdir(self.tmp.name)
        robo._state.update({"world": None, "trace": False, "calls": 0, "reported": False})

    def tearDown(self):
        os.chdir(self.cwd)
        self.tmp.cleanup()

    def write_world(self, world):
        with open("robo_world.json", "w", encoding="utf-8") as handle:
            json.dump(world, handle)

    def test_ohne_welt_leise_standardwelt(self):
        self.assertTrue(robo.vorneFrei())
        robo.vor()
        self.assertEqual("O", robo.richtung())
        self.assertFalse(robo._state["trace"])

    def test_sensoren_und_protokoll(self):
        self.write_world(empty(3, 2, walls=[[2, 0]], screws=[{"x": 1, "y": 0, "count": 1}], targets=[[1, 0]]))
        lines = []
        robo._emit = lines.append
        self.assertTrue(robo.vorneFrei())
        robo.vor()
        self.assertTrue(robo.aufSchraube())
        self.assertTrue(robo.aufZiel())
        self.assertTrue(robo.vorneWand())
        robo.aufheben()
        self.assertEqual(1, robo.schraubenImKorb())
        self.assertEqual(0, robo.schraubenHier())
        self.assertEqual(["v", "a"], lines)

    def test_fehler_wird_einmal_gemeldet(self):
        self.write_world(empty(3, 2, walls=[[1, 0]]))
        lines = []
        robo._emit = lines.append
        with self.assertRaises(robo.RoboFehler):
            robo.vor()
        with self.assertRaises(robo.RoboFehler):
            robo.vor()
        self.assertEqual(1, len(lines))
        self.assertTrue(lines[0].startswith("!Robo ist gegen eine Wand"))

    def test_klasse_robo(self):
        self.write_world(empty())
        robo._emit = lambda text: None
        r = robo.Robo()
        r.vor()
        r.rechts()
        self.assertEqual("S", r.richtung())

    def test_aufrufgrenze(self):
        self.write_world(empty())
        robo._emit = lambda text: None
        with self.assertRaises(robo.RoboFehler) as ctx:
            for _ in range(robo.MAX_CALLS + 5):
                robo.vorneFrei()
        self.assertIn("Endlosschleife", str(ctx.exception))


@unittest.skipUnless(sys.platform.startswith("linux"), "Runner-Modul braucht Linux (fcntl)")
class RunnerModule(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        sys.path.insert(0, RUNNER)
        from kwrunner.modules import robo as module  # noqa: E402
        cls.module = module

    def test_parse_und_nachrechnen(self):
        prefix = self.module.TRACE_PREFIX
        text = "Hallo\n" + prefix + "v\n" + prefix + "v\nzwischendurch\n" + prefix + "r\n"
        trace, rest = self.module.parse_stdout(text)
        self.assertEqual(["v", "v", "r"], trace)
        self.assertIn("zwischendurch", rest)
        eng = robo
        world, error, clean = self.module.replay(eng, empty(), trace)
        self.assertIsNone(error)
        self.assertEqual((2, 0, "S"), (world.x, world.y, world.dir))
        self.assertEqual(["v", "v", "r"], clean)

    def test_gefaelschtes_protokoll_wird_nachgerechnet(self):
        eng = robo
        world, error, clean = self.module.replay(eng, empty(walls=[[1, 0]]), ["v"])
        self.assertIn("Wand", error)
        self.assertEqual(["!" + error], clean)

    def test_zielpruefung(self):
        eng = robo
        w = eng.World(empty(screws=[{"x": 1, "y": 0, "count": 1}], boxes=[[3, 1]], targets=[[1, 0]]))
        w.step("v")
        w.step("a")
        goal = self.module.normalize_goal({
            "screws_collected": "all", "robo_at": [1, 0], "robo_dir": "O",
            "boxes_at": [[3, 1]], "robo_on_target": True})
        results = self.module.check_goal(w, goal)
        self.assertTrue(all(r["ok"] for r in results), results)
        self.assertEqual(5, len(results))
        w2 = eng.World(empty(screws=[{"x": 1, "y": 0, "count": 2}]))
        bad = self.module.check_goal(w2, self.module.normalize_goal({"screws_collected": "all", "robo_at": [4, 2]}))
        self.assertEqual([False, False], [r["ok"] for r in bad])
        self.assertIn("2 Schrauben", bad[0]["message"])

    def test_zielvalidierung(self):
        from kwrunner.sandbox import RunnerError
        for bad in ([1], {"robo_at": [1]}, {"screws_collected": "viele"}, {"robo_dir": "Q"}, {"boxes_at": [[1]]}):
            with self.assertRaises(RunnerError):
                self.module.normalize_goal(bad)
        self.assertEqual({}, self.module.normalize_goal(None))
        self.assertEqual({"screws_collected": 3}, self.module.normalize_goal({"screws_collected": 3, "x": 1}))


if __name__ == "__main__":
    unittest.main()
