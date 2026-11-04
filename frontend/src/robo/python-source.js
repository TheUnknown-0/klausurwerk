/**
 * Python-Quelltext des Moduls 'robo' für Pyodide. GENERIERT aus runner/libs/python/robo.py
 * mit "node scripts/sync-robo.mjs", nicht von Hand ändern (frontend/test/robo-python-source.test.js prüft das).
 */
export const ROBO_PY = String.raw`"""Robo: Gitterwelt fuer den Informatikunterricht (Klausurwerk).

Benutzung im Programm:

    from robo import *

    while vorneFrei():
        vor()
    links()

Befehle:      vor(), links(), rechts(), aufheben(), ablegen(), schieben()
Fragen:       vorneFrei(), vorneWand(), vorneKiste(), aufSchraube(), aufZiel(),
              schraubenImKorb(), schraubenHier(), richtung()  ('N', 'O', 'S' oder 'W')

Regeln:       Robo steht auf einem Feld und schaut in eine Richtung. Waende und der
              Rand der Welt sind Hindernisse. Kisten sind ebenfalls Hindernisse, Robo kann
              sie aber mit schieben() ein Feld weiterschieben, wenn dahinter alles frei ist
              (Robo rueckt dabei auf das Feld der Kiste vor). Ein Fehler (z. B. gegen eine
              Wand laufen) beendet das Programm mit einer Meldung.

Diese Datei enthaelt auch die Regeln der Welt (Klasse World). Sie wird von drei
Stellen genutzt: im Browser (Pyodide), im Runner (Korrektur) und von
frontend/src/robo/python-source.js (Kopie dieses Textes, ein Test prueft die Gleichheit).
Das Protokoll der Zuege: v l r a b s, Fehler als "!Meldung".
"""
import json
import os
import sys

WORLD_FILE = "robo_world.json"
STDOUT_PREFIX = "\x01KWR:"
MAX_MOVES = 10000
MAX_CALLS = 200000
DIRS = "NESW"
DELTA = {"N": (0, -1), "E": (1, 0), "S": (0, 1), "W": (-1, 0)}

MESSAGES = {
    "wall": "Robo ist gegen eine Wand gelaufen.",
    "edge": "Robo ist gegen den Rand der Welt gelaufen.",
    "box": "Robo ist gegen eine Kiste gelaufen. Mit schieben() kann er sie wegschieben.",
    "noScrew": "Hier liegt keine Schraube, die Robo aufheben kann.",
    "emptyBasket": "Der Korb ist leer, Robo hat nichts zum Ablegen.",
    "noBox": "Vor Robo steht keine Kiste, die er schieben könnte.",
    "blocked": "Die Kiste lässt sich nicht schieben, dahinter ist kein freies Feld.",
    "limit": "Robo hat mehr als %d Befehle ausgeführt. Läuft eine Endlosschleife?" % MAX_MOVES,
    "calls": "Robo wurde zu oft befragt oder bewegt. Läuft eine Endlosschleife?",
    "crashed": "Robo ist kaputt und kann keine Befehle mehr ausführen.",
}


class RoboFehler(Exception):
    """Robo kann den Befehl nicht ausfuehren (Wand, leerer Korb, ...)."""


def _int(value):
    return isinstance(value, int) and not isinstance(value, bool)


def _cells(raw, label, cols, rows):
    out = []
    seen = set()
    for item in raw if isinstance(raw, list) else []:
        if not isinstance(item, (list, tuple)) or len(item) != 2 or not _int(item[0]) or not _int(item[1]):
            raise ValueError("%s: Jede Position muss die Form [x, y] haben." % label)
        x, y = item
        if x < 0 or y < 0 or x >= cols or y >= rows:
            raise ValueError("%s: Das Feld (%d|%d) liegt außerhalb der Welt." % (label, x, y))
        if (x, y) not in seen:
            seen.add((x, y))
            out.append((x, y))
    return out


def normalize_world(raw):
    """Prueft Weltdaten wie frontend/src/robo/world.js. Wirft ValueError."""
    if not isinstance(raw, dict):
        raise ValueError("Die Welt fehlt.")
    cols, rows = raw.get("cols"), raw.get("rows")
    if not _int(cols) or not _int(rows) or not (2 <= cols <= 30) or not (2 <= rows <= 30):
        raise ValueError("Die Welt muss zwischen 2 und 30 Spalten und Zeilen haben.")
    robo = raw.get("robo") if isinstance(raw.get("robo"), dict) else {}
    rx, ry = robo.get("x"), robo.get("y")
    if not _int(rx) or not _int(ry) or not (0 <= rx < cols) or not (0 <= ry < rows):
        raise ValueError("Robo steht außerhalb der Welt.")
    d = str(robo.get("dir", "E")).upper()
    d = "E" if d == "O" else d
    if d not in DIRS or len(d) != 1:
        raise ValueError("Ungültige Blickrichtung.")
    walls = _cells(raw.get("walls"), "Wände", cols, rows)
    boxes = _cells(raw.get("boxes"), "Kisten", cols, rows)
    targets = _cells(raw.get("targets"), "Ziele", cols, rows)
    wall_set, box_set = set(walls), set(boxes)
    if (rx, ry) in wall_set:
        raise ValueError("Robo steht auf einer Wand (%d|%d)." % (rx, ry))
    if (rx, ry) in box_set:
        raise ValueError("Robo steht auf einer Kiste (%d|%d)." % (rx, ry))
    for cell in boxes:
        if cell in wall_set:
            raise ValueError("Eine Kiste steht auf einer Wand (%d|%d)." % cell)
    screws = {}
    for item in raw.get("screws") if isinstance(raw.get("screws"), list) else []:
        if not isinstance(item, dict) or not _int(item.get("x")) or not _int(item.get("y")) or not _int(item.get("count")):
            raise ValueError("Schrauben: Jeder Eintrag braucht x, y und count (ganze Zahlen).")
        x, y, n = item["x"], item["y"], item["count"]
        if not (0 <= x < cols) or not (0 <= y < rows):
            raise ValueError("Schrauben: Das Feld (%d|%d) liegt außerhalb der Welt." % (x, y))
        if not (1 <= n <= 99):
            raise ValueError("Schrauben: Die Anzahl muss zwischen 1 und 99 liegen.")
        if (x, y) in wall_set or (x, y) in box_set:
            raise ValueError("Schrauben: Auf dem Feld (%d|%d) kann keine Schraube liegen." % (x, y))
        if (x, y) in screws:
            raise ValueError("Schrauben: Das Feld (%d|%d) kommt doppelt vor." % (x, y))
        screws[(x, y)] = n
    for cell in targets:
        if cell in wall_set:
            raise ValueError("Ein Ziel liegt auf einer Wand (%d|%d)." % cell)
    basket = raw.get("basket", 0)
    if basket is None:
        basket = 0
    if not _int(basket) or not (0 <= basket <= 9999):
        raise ValueError("Der Korb enthält eine ungültige Anzahl Schrauben.")
    return {"cols": cols, "rows": rows, "x": rx, "y": ry, "dir": d, "walls": wall_set, "boxes": box_set,
            "targets": set(targets), "screws": screws, "basket": basket}


class World:
    """Veraenderliche Welt. Koordinaten: x nach rechts, y nach unten."""

    def __init__(self, raw):
        d = normalize_world(raw)
        self.cols, self.rows = d["cols"], d["rows"]
        self.x, self.y, self.dir = d["x"], d["y"], d["dir"]
        self.walls, self.boxes, self.targets = d["walls"], d["boxes"], d["targets"]
        self.screws = d["screws"]
        self.basket = d["basket"]
        self.moves = 0
        self.crashed = False

    # ---- Abfragen ----
    def inside(self, x, y):
        return 0 <= x < self.cols and 0 <= y < self.rows

    def front(self, distance=1):
        dx, dy = DELTA[self.dir]
        return self.x + dx * distance, self.y + dy * distance

    def is_wall(self, x, y):
        return not self.inside(x, y) or (x, y) in self.walls

    def has_box(self, x, y):
        return (x, y) in self.boxes

    def vorne_frei(self):
        x, y = self.front()
        return not self.is_wall(x, y) and not self.has_box(x, y)

    def vorne_wand(self):
        x, y = self.front()
        return self.is_wall(x, y)

    def vorne_kiste(self):
        x, y = self.front()
        return self.has_box(x, y)

    def screws_here(self):
        return self.screws.get((self.x, self.y), 0)

    def on_target(self):
        return (self.x, self.y) in self.targets

    def total_screws_on_floor(self):
        return sum(self.screws.values())

    # ---- Zuege ----
    def _fail(self, key_or_text):
        self.crashed = True
        raise RoboFehler(MESSAGES.get(key_or_text, key_or_text))

    def step(self, code):
        """Fuehrt ein Protokollzeichen aus (v l r a b s); wirft RoboFehler."""
        if self.crashed:
            raise RoboFehler(MESSAGES["crashed"])
        if self.moves >= MAX_MOVES:
            self._fail("limit")
        if code == "v":
            x, y = self.front()
            if not self.inside(x, y):
                self._fail("edge")
            if (x, y) in self.walls:
                self._fail("wall")
            if (x, y) in self.boxes:
                self._fail("box")
            self.x, self.y = x, y
        elif code in ("l", "r"):
            i = DIRS.index(self.dir)
            self.dir = DIRS[(i + (1 if code == "r" else 3)) % 4]
        elif code == "a":
            n = self.screws.get((self.x, self.y), 0)
            if n < 1:
                self._fail("noScrew")
            if n == 1:
                del self.screws[(self.x, self.y)]
            else:
                self.screws[(self.x, self.y)] = n - 1
            self.basket += 1
        elif code == "b":
            if self.basket < 1:
                self._fail("emptyBasket")
            self.screws[(self.x, self.y)] = self.screws.get((self.x, self.y), 0) + 1
            self.basket -= 1
        elif code == "s":
            bx, by = self.front()
            if not self.has_box(bx, by):
                self._fail("noBox")
            tx, ty = self.front(2)
            if not self.inside(tx, ty) or (tx, ty) in self.walls or (tx, ty) in self.boxes:
                self._fail("blocked")
            self.boxes.discard((bx, by))
            self.boxes.add((tx, ty))
            self.x, self.y = bx, by
        else:
            self._fail("Unbekannter Befehl im Protokoll: %s" % str(code)[:10])
        self.moves += 1

    def to_dict(self):
        return {
            "cols": self.cols, "rows": self.rows,
            "robo": {"x": self.x, "y": self.y, "dir": self.dir},
            "walls": [list(c) for c in sorted(self.walls, key=lambda c: (c[1], c[0]))],
            "boxes": [list(c) for c in sorted(self.boxes, key=lambda c: (c[1], c[0]))],
            "screws": [{"x": c[0], "y": c[1], "count": n}
                       for c, n in sorted(self.screws.items(), key=lambda kv: (kv[0][1], kv[0][0]))],
            "targets": [list(c) for c in sorted(self.targets, key=lambda c: (c[1], c[0]))],
            "basket": self.basket,
        }


# ---------------------------------------------------------------------------
# Programmierschnittstelle fuer Schueler:innen
# ---------------------------------------------------------------------------

_state = {"world": None, "trace": False, "calls": 0, "reported": False}


def _bridge():
    """Im Browser (Pyodide) gibt es das Modul _kw, im Runner nicht."""
    try:
        import _kw
        return _kw.turtle
    except Exception:
        return None


def _emit(text):
    if not _state["trace"]:
        return
    bridge = _bridge()
    if bridge is not None:
        bridge(json.dumps([{"robo": text}]))
    else:
        sys.stdout.write(STDOUT_PREFIX + text + "\n")
        sys.stdout.flush()


def _load():
    world = _state["world"]
    if world is not None:
        return world
    data = None
    try:
        with open(os.path.join(os.getcwd(), WORLD_FILE), encoding="utf-8") as handle:
            data = json.load(handle)
    except (OSError, ValueError):
        data = None
    if isinstance(data, dict):
        try:
            world = World(data)
            _state["trace"] = True
        except ValueError as exc:
            raise RoboFehler("Die Welt dieser Aufgabe ist ungültig: %s" % exc)
    else:
        world = World({"cols": 8, "rows": 8, "robo": {"x": 0, "y": 0, "dir": "E"}})
    _state["world"] = world
    return world


def _fehler(text):
    if not _state["reported"]:
        _state["reported"] = True
        _emit("!" + text)


def _count(world):
    _state["calls"] += 1
    if _state["calls"] > MAX_CALLS:
        world.crashed = True
        _fehler(MESSAGES["calls"])
        raise RoboFehler(MESSAGES["calls"])


def _act(code):
    world = _load()
    _count(world)
    try:
        world.step(code)
    except RoboFehler as exc:
        _fehler(str(exc))
        raise
    _emit(code)


def vor():
    """Robo geht ein Feld vor."""
    _act("v")


def links():
    """Robo dreht sich um 90 Grad nach links."""
    _act("l")


def rechts():
    """Robo dreht sich um 90 Grad nach rechts."""
    _act("r")


def aufheben():
    """Robo hebt eine Schraube vom Feld auf und legt sie in den Korb."""
    _act("a")


def ablegen():
    """Robo legt eine Schraube aus dem Korb auf das Feld."""
    _act("b")


def schieben():
    """Robo schiebt die Kiste vor sich ein Feld weiter und rueckt nach."""
    _act("s")


def _ask(fn):
    world = _load()
    _count(world)
    return fn(world)


def vorneFrei():
    """True, wenn das Feld vor Robo frei ist (keine Wand, keine Kiste, nicht der Rand)."""
    return _ask(lambda w: w.vorne_frei())


def vorneWand():
    """True, wenn vor Robo eine Wand oder der Rand der Welt ist."""
    return _ask(lambda w: w.vorne_wand())


def vorneKiste():
    """True, wenn vor Robo eine Kiste steht."""
    return _ask(lambda w: w.vorne_kiste())


def aufSchraube():
    """True, wenn auf dem Feld von Robo mindestens eine Schraube liegt."""
    return _ask(lambda w: w.screws_here() > 0)


def aufZiel():
    """True, wenn Robo auf einem Zielfeld steht."""
    return _ask(lambda w: w.on_target())


def schraubenImKorb():
    """Anzahl der Schrauben im Korb."""
    return _ask(lambda w: w.basket)


def schraubenHier():
    """Anzahl der Schrauben auf dem Feld von Robo."""
    return _ask(lambda w: w.screws_here())


def richtung():
    """Blickrichtung als Buchstabe: 'N', 'O', 'S' oder 'W'."""
    return _ask(lambda w: "O" if w.dir == "E" else w.dir)


class Robo:
    """Alle Befehle auch als Methoden: r = Robo(); r.vor()."""

    vor = staticmethod(vor)
    links = staticmethod(links)
    rechts = staticmethod(rechts)
    aufheben = staticmethod(aufheben)
    ablegen = staticmethod(ablegen)
    schieben = staticmethod(schieben)
    vorneFrei = staticmethod(vorneFrei)
    vorneWand = staticmethod(vorneWand)
    vorneKiste = staticmethod(vorneKiste)
    aufSchraube = staticmethod(aufSchraube)
    aufZiel = staticmethod(aufZiel)
    schraubenImKorb = staticmethod(schraubenImKorb)
    schraubenHier = staticmethod(schraubenHier)
    richtung = staticmethod(richtung)


__all__ = [
    "Robo", "RoboFehler", "vor", "links", "rechts", "aufheben", "ablegen", "schieben",
    "vorneFrei", "vorneWand", "vorneKiste", "aufSchraube", "aufZiel", "schraubenImKorb",
    "schraubenHier", "richtung",
]
`;
