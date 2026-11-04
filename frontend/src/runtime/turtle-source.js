/**
 * Python-Quelltext des Moduls 'turtle' für Pyodide (Ersatz für das Tk-basierte
 * Standardmodul). Das Modul rechnet Position und Richtung selbst, schickt die
 * Zeichenbefehle als JSON an den Hauptthread (_kw.turtle) und kommt ohne Tk aus.
 * Läuft das Modul außerhalb von Pyodide (Tests), sammelt es die Befehle in
 * turtle._collected.
 *
 * Wichtig beim Bearbeiten: kein Backtick und keine Zeichenfolge dollar+geschweifte
 * Klammer im Python-Text (String.raw).
 */
export const TURTLE_PY = String.raw`"""Turtle-Grafik fuer Klausurwerk (ersetzt das Tk-basierte turtle-Modul)."""
import math
import json

try:
    import _kw
    _emit_js = _kw.turtle
except Exception:
    _emit_js = None

_collected = []
_buf = []
_next_id = [0]
_state = {"colormode": 1.0, "fullcircle": 360.0}


class Terminator(Exception):
    pass


def _r(v):
    v = round(float(v), 6)
    return 0.0 if v == 0 else v


def _flush():
    """Schickt gesammelte Befehle an die Zeichenflaeche."""
    global _buf
    if not _buf:
        return
    data = json.dumps(_buf)
    _buf = []
    if _emit_js is not None:
        _emit_js(data)
    else:
        _collected.extend(json.loads(data))


def _emit(cmd):
    _buf.append(cmd)
    if len(_buf) >= 200:
        _flush()


def _color(*args):
    """Wandelt Farbangaben in CSS-Farben um."""
    if len(args) == 1:
        c = args[0]
        if isinstance(c, (tuple, list)) and len(c) == 3:
            args = tuple(c)
        elif isinstance(c, str):
            c = c.strip()
            if c.startswith("#") or c.startswith("rgb"):
                return c
            return c.replace(" ", "").lower() or "black"
        else:
            raise TypeError("Ungueltige Farbe: %r" % (c,))
    if len(args) == 3:
        top = _state["colormode"]
        vals = []
        for v in args:
            v = float(v)
            if v < 0 or v > top:
                raise ValueError("Farbwerte muessen zwischen 0 und %s liegen" % top)
            vals.append(int(round(v / top * 255)))
        return "#%02x%02x%02x" % tuple(vals)
    raise TypeError("Ungueltige Farbangabe")


_SPEEDS = {"fastest": 0, "fast": 10, "normal": 6, "slow": 3, "slowest": 1}


def _xy(x, y):
    if y is None:
        if isinstance(x, Turtle):
            return x._x, x._y
        x, y = x[0], x[1]
    return float(x), float(y)


class Turtle:
    """Schildkroete, die Zeichenbefehle erzeugt."""

    def __init__(self, shape="classic", undobuffersize=1000, visible=True):
        self._id = _next_id[0]
        _next_id[0] += 1
        self._x = 0.0
        self._y = 0.0
        self._h = 0.0
        self._pen = True
        self._pc = "black"
        self._fc = "black"
        self._w = 1.0
        self._speed = 3
        self._shown = bool(visible)
        self._filling = False
        self._fillpts = []
        self._shape = shape
        self._sprite()

    # ---- interne Helfer ----
    def _k(self):
        return _state["fullcircle"] / 360.0

    def _sprite(self):
        _emit({"t": "sprite", "id": self._id, "shown": self._shown, "c": self._pc,
               "x": _r(self._x), "y": _r(self._y), "h": _r(self._h)})

    def _move(self, nx, ny, pts=None, heading=None):
        pts = pts or [[self._x, self._y], [nx, ny]]
        if heading is not None:
            self._h = heading
        _emit({"t": "path", "id": self._id, "pts": [[_r(a), _r(b)] for a, b in pts],
               "pen": self._pen, "c": self._pc, "w": self._w, "h": _r(self._h), "s": self._speed})
        self._x, self._y = nx, ny
        if self._filling:
            for a, b in pts[1:]:
                self._fillpts.append([_r(a), _r(b)])

    def _turn(self, deg):
        if deg == 0:
            return
        start = self._h
        self._h = start + deg
        _emit({"t": "turn", "id": self._id, "from": _r(start), "to": _r(self._h), "s": self._speed})

    # ---- Bewegung ----
    def forward(self, distance):
        a = math.radians(self._h)
        self._move(self._x + distance * math.cos(a), self._y + distance * math.sin(a))

    def back(self, distance):
        self.forward(-distance)

    def left(self, angle):
        self._turn(angle * self._k())

    def right(self, angle):
        self._turn(-angle * self._k())

    def goto(self, x, y=None):
        nx, ny = _xy(x, y)
        self._move(nx, ny)

    def setx(self, x):
        self._move(float(x), self._y)

    def sety(self, y):
        self._move(self._x, float(y))

    def setheading(self, to_angle):
        target = float(to_angle) * self._k()
        delta = (target - self._h) % 360.0
        if delta > 180.0:
            delta -= 360.0
        self._turn(delta)

    def home(self):
        self.goto(0, 0)
        self.setheading(0)

    def circle(self, radius, extent=None, steps=None):
        extent = 360.0 if extent is None else float(extent) * self._k()
        extent = abs(extent)
        if radius == 0 or extent == 0:
            return
        sign = 1.0 if radius > 0 else -1.0
        if steps is None:
            steps = max(6, int(extent / 4.0) + 1)
        h = math.radians(self._h)
        cx = self._x + radius * (-math.sin(h))
        cy = self._y + radius * math.cos(h)
        vx = -radius * (-math.sin(h))
        vy = -radius * math.cos(h)
        pts = [[self._x, self._y]]
        for i in range(1, steps + 1):
            phi = math.radians(sign * extent * i / steps)
            pts.append([cx + vx * math.cos(phi) - vy * math.sin(phi),
                        cy + vx * math.sin(phi) + vy * math.cos(phi)])
        end = pts[-1]
        self._move(end[0], end[1], pts=pts, heading=self._h + sign * extent)

    # ---- Stift ----
    def penup(self):
        self._pen = False

    def pendown(self):
        self._pen = True

    def isdown(self):
        return self._pen

    def pensize(self, width=None):
        if width is None:
            return self._w
        self._w = max(float(width), 0.0)

    def pencolor(self, *args):
        if not args:
            return self._pc
        self._pc = _color(*args)
        self._sprite()

    def fillcolor(self, *args):
        if not args:
            return self._fc
        self._fc = _color(*args)

    def color(self, *args):
        if not args:
            return self._pc, self._fc
        if len(args) == 2:
            self._pc = _color(args[0])
            self._fc = _color(args[1])
        else:
            self._pc = self._fc = _color(*args)
        self._sprite()

    def speed(self, speed=None):
        if speed is None:
            return self._speed
        if isinstance(speed, str):
            speed = _SPEEDS.get(speed, 3)
        speed = int(speed)
        self._speed = speed if 0 < speed <= 10 else 0

    def begin_fill(self):
        self._filling = True
        self._fillpts = [[_r(self._x), _r(self._y)]]
        _emit({"t": "beginfill", "id": self._id})

    def end_fill(self):
        if self._filling:
            self._filling = False
            _emit({"t": "endfill", "id": self._id, "pts": self._fillpts, "c": self._fc})
            self._fillpts = []

    def filling(self):
        return self._filling

    # ---- Sichtbarkeit, Beschriftung ----
    def hideturtle(self):
        self._shown = False
        self._sprite()

    def showturtle(self):
        self._shown = True
        self._sprite()

    def isvisible(self):
        return self._shown

    def shape(self, name=None):
        if name is None:
            return self._shape
        self._shape = name

    def shapesize(self, *args, **kwargs):
        return None

    def write(self, arg, move=False, align="left", font=("Arial", 8, "normal")):
        _emit({"t": "text", "id": self._id, "x": _r(self._x), "y": _r(self._y), "s": str(arg),
               "c": self._pc, "font": [str(font[0]), font[1], str(font[2]) if len(font) > 2 else "normal"],
               "align": align})

    def dot(self, size=None, *color):
        if size is None:
            size = max(self._w + 4, self._w * 2)
        _emit({"t": "dot", "id": self._id, "x": _r(self._x), "y": _r(self._y), "d": _r(size),
               "c": _color(*color) if color else self._pc})

    def stamp(self):
        a = math.radians(self._h)
        pts = []
        for dx, dy in ((12, 0), (-8, 6), (-8, -6)):
            pts.append([_r(self._x + dx * math.cos(a) - dy * math.sin(a)),
                        _r(self._y + dx * math.sin(a) + dy * math.cos(a))])
        _emit({"t": "endfill", "id": self._id, "pts": pts, "c": self._pc})
        return None

    def clear(self):
        _emit({"t": "clear", "id": self._id})

    def reset(self):
        self.clear()
        self._pen = True
        self._pc = self._fc = "black"
        self._w = 1.0
        self._speed = 3
        self._filling = False
        self._x = self._y = self._h = 0.0
        self._shown = True
        self._sprite()

    def undo(self):
        return None

    # ---- Abfragen ----
    def position(self):
        return (self._x, self._y)

    pos = position

    def xcor(self):
        return self._x

    def ycor(self):
        return self._y

    def heading(self):
        return (self._h % 360.0) / self._k()

    def distance(self, x, y=None):
        tx, ty = _xy(x, y)
        return math.hypot(tx - self._x, ty - self._y)

    def towards(self, x, y=None):
        tx, ty = _xy(x, y)
        ang = math.degrees(math.atan2(ty - self._y, tx - self._x)) % 360.0
        return ang / self._k()

    def degrees(self, fullcircle=360.0):
        _state["fullcircle"] = float(fullcircle)

    def radians(self):
        _state["fullcircle"] = 2 * math.pi

    def getscreen(self):
        return Screen()

    def onclick(self, *a, **k):
        return None

    onrelease = ondrag = onclick

    def tracer(self, *a, **k):
        return None

    def clone(self):
        t = Turtle(self._shape, visible=self._shown)
        t._pc, t._fc, t._w, t._speed = self._pc, self._fc, self._w, self._speed
        t._x, t._y, t._h, t._pen = self._x, self._y, self._h, self._pen
        t._sprite()
        return t

    # Kurzformen
    fd = forward
    bk = back
    backward = back
    lt = left
    rt = right
    setpos = goto
    setposition = goto
    seth = setheading
    pu = up = penup
    pd = down = pendown
    width = pensize
    ht = hideturtle
    st = showturtle
    turtlesize = shapesize


RawTurtle = Pen = RawPen = Turtle


class _Screen:
    def bgcolor(self, *args):
        if not args:
            return "white"
        _emit({"t": "bg", "c": _color(*args)})

    def colormode(self, cmode=None):
        if cmode is None:
            return _state["colormode"]
        _state["colormode"] = 255.0 if float(cmode) == 255 else 1.0

    def window_width(self):
        return 640

    def window_height(self):
        return 480

    def screensize(self, *a, **k):
        return (640, 480)

    def textinput(self, title, prompt):
        return input(str(prompt))

    def numinput(self, title, prompt, default=None, minval=None, maxval=None):
        try:
            return float(input(str(prompt)))
        except ValueError:
            return default

    def mainloop(self):
        _flush()

    done = mainloop

    def exitonclick(self):
        _flush()

    def bye(self):
        _flush()

    def clear(self):
        _emit({"t": "clear", "id": "all"})

    def _noop(self, *a, **k):
        return None

    setup = title = tracer = update = delay = listen = mode = _noop
    onkey = onkeypress = onkeyrelease = onscreenclick = onclick = ontimer = _noop
    setworldcoordinates = bgpic = register_shape = addshape = _noop
    resetscreen = reset = _noop


_screen = _Screen()
_default_turtle = [None]


def Screen():
    return _screen


TurtleScreen = _Screen


def getscreen():
    return _screen


def _default():
    if _default_turtle[0] is None:
        _default_turtle[0] = Turtle()
    return _default_turtle[0]


def getturtle():
    return _default()


getpen = getturtle


def _delegate_turtle(name):
    def f(*args, **kwargs):
        return getattr(_default(), name)(*args, **kwargs)
    f.__name__ = name
    return f


def _delegate_screen(name):
    def f(*args, **kwargs):
        return getattr(_screen, name)(*args, **kwargs)
    f.__name__ = name
    return f


for _n in ("forward", "fd", "back", "bk", "backward", "left", "lt", "right", "rt", "goto", "setpos",
           "setposition", "setx", "sety", "setheading", "seth", "home", "circle", "penup", "pu", "up",
           "pendown", "pd", "down", "isdown", "pensize", "width", "pencolor", "fillcolor", "color",
           "speed", "begin_fill", "end_fill", "filling", "hideturtle", "ht", "showturtle", "st",
           "isvisible", "shape", "shapesize", "turtlesize", "write", "dot", "stamp", "clear", "reset",
           "undo", "position", "pos", "xcor", "ycor", "heading", "distance", "towards", "degrees",
           "radians", "onclick", "onrelease", "ondrag"):
    globals()[_n] = _delegate_turtle(_n)

for _n in ("bgcolor", "colormode", "window_width", "window_height", "screensize", "textinput",
           "numinput", "mainloop", "done", "exitonclick", "bye", "setup", "title", "tracer", "update",
           "delay", "listen", "mode", "onkey", "onkeypress", "onkeyrelease", "onscreenclick", "ontimer",
           "setworldcoordinates", "bgpic", "register_shape", "addshape", "resetscreen"):
    globals()[_n] = _delegate_screen(_n)
`;
