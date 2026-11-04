"""Unit-Tests ohne Container für UID-Pool, Popen-Argumente und die Warteschlange.

Laufen nur unter Linux (kwrunner nutzt fcntl), z. B. im Runner-Image:

    docker run --rm -v "$PWD/runner:/r" --entrypoint python3 klausurwerk-runner-dev -m unittest discover -s /r/tests -p "test_sandbox_unit.py"
"""
import os
import sys
import threading
import time
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

try:
    from kwrunner import sandbox
    from kwrunner.config import Config
    from kwrunner.runs import Run, RunManager
except ImportError:  # Windows: kein fcntl
    sandbox = None


@unittest.skipIf(sandbox is None, "kwrunner braucht Linux")
class SandboxUnitTests(unittest.TestCase):
    def test_uid_pool_gibt_jede_uid_nur_einmal_aus(self):
        pool = sandbox.UidPool(30000, 3)
        got = [pool.acquire(0.1) for _ in range(3)]
        self.assertEqual(sorted(got), [30000, 30001, 30002])
        self.assertIsNone(pool.acquire(0.05))
        pool.release(30001)
        self.assertEqual(pool.acquire(0.1), 30001)

    def test_user_kwargs_setzt_uid_gid_und_leert_gruppen(self):
        self.assertEqual(sandbox.user_kwargs(None), {})
        self.assertEqual(sandbox.user_kwargs(20007), {"user": 20007, "group": 20007, "extra_groups": []})

    def test_wartender_lauf_wird_nicht_am_leben_gehalten(self):
        manager = RunManager(Config(max_parallel=1))
        self.assertTrue(manager.acquire_slot(0.1))  # einziger Slot belegt
        run = Run("r_test")
        run.last_access = time.monotonic() - 100
        before = run.last_access
        self.assertFalse(manager.acquire_slot(0.6, run))
        self.assertEqual(run.last_access, before, "acquire_slot darf den Lauf nicht touchen")

    def test_abgebrochener_wartender_lauf_gibt_auf(self):
        manager = RunManager(Config(max_parallel=1))
        manager.acquire_slot(0.1)
        run = Run("r_test2")
        threading.Timer(0.3, lambda: setattr(run, "cancelled", True)).start()
        started = time.monotonic()
        self.assertFalse(manager.acquire_slot(10, run))
        self.assertLess(time.monotonic() - started, 3)


if __name__ == "__main__":
    unittest.main()
