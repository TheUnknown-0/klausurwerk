import java.io.File;
import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;

/**
 * Robo: Gitterwelt für den Informatikunterricht (Klausurwerk).
 *
 * <pre>
 * public class Main {
 *     public static void main(String[] args) {
 *         while (Robo.vorneFrei()) {
 *             Robo.vor();
 *         }
 *         Robo.links();
 *     }
 * }
 * </pre>
 *
 * Befehle: vor(), links(), rechts(), aufheben(), ablegen(), schieben().
 * Fragen: vorneFrei(), vorneWand(), vorneKiste(), aufSchraube(), aufZiel(),
 * schraubenImKorb(), schraubenHier(), richtung() ('N', 'O', 'S' oder 'W').
 *
 * Alle Methoden sind statisch; {@code new Robo().vor()} funktioniert ebenfalls.
 * Ein Fehler (z. B. gegen eine Wand laufen) wirft einen {@link RoboFehler} und beendet
 * damit das Programm mit einer Meldung.
 *
 * Die Regeln stimmen mit runner/libs/python/robo.py und frontend/src/robo/world.js überein.
 * Protokoll der Züge (Zeilen auf der Standardausgabe, Präfix U+0001 "KWR:"): v l r a b s,
 * Fehler als "!Meldung". Das Protokoll wird nur geschrieben, wenn die Welt vorgegeben ist
 * (Datei robo_world.json oder erste Eingabezeile "@KWROBO {json}").
 */
public class Robo {
    private static final String WORLD_FILE = "robo_world.json";
    private static final String STDIN_PREFIX = "@KWROBO ";
    private static final String TRACE_PREFIX = new String(new char[] {1}) + "KWR:";
    private static final int MAX_MOVES = 10000;
    private static final int MAX_CALLS = 200000;
    private static final String DIRS = "NESW";
    private static final int[][] DELTA = {{0, -1}, {1, 0}, {0, 1}, {-1, 0}};

    private static World world;
    private static boolean trace;
    private static boolean reported;
    private static int calls;

    /** Erlaubt die Schreibweise {@code Robo r = new Robo(); r.vor();}. */
    public Robo() {
    }

    // ---------------------------------------------------------------------
    // Befehle
    // ---------------------------------------------------------------------

    /** Robo geht ein Feld vor. */
    public static void vor() {
        act('v');
    }

    /** Robo dreht sich um 90 Grad nach links. */
    public static void links() {
        act('l');
    }

    /** Robo dreht sich um 90 Grad nach rechts. */
    public static void rechts() {
        act('r');
    }

    /** Robo hebt eine Schraube vom Feld auf und legt sie in den Korb. */
    public static void aufheben() {
        act('a');
    }

    /** Robo legt eine Schraube aus dem Korb auf das Feld. */
    public static void ablegen() {
        act('b');
    }

    /** Robo schiebt die Kiste vor sich ein Feld weiter und rückt nach. */
    public static void schieben() {
        act('s');
    }

    // ---------------------------------------------------------------------
    // Fragen
    // ---------------------------------------------------------------------

    /** true, wenn das Feld vor Robo frei ist (keine Wand, keine Kiste, nicht der Rand). */
    public static boolean vorneFrei() {
        World w = ask();
        int[] f = w.front(1);
        return !w.isWall(f[0], f[1]) && !w.hasBox(f[0], f[1]);
    }

    /** true, wenn vor Robo eine Wand oder der Rand der Welt ist. */
    public static boolean vorneWand() {
        World w = ask();
        int[] f = w.front(1);
        return w.isWall(f[0], f[1]);
    }

    /** true, wenn vor Robo eine Kiste steht. */
    public static boolean vorneKiste() {
        World w = ask();
        int[] f = w.front(1);
        return w.hasBox(f[0], f[1]);
    }

    /** true, wenn auf dem Feld von Robo mindestens eine Schraube liegt. */
    public static boolean aufSchraube() {
        return ask().screwsHere() > 0;
    }

    /** true, wenn Robo auf einem Zielfeld steht. */
    public static boolean aufZiel() {
        World w = ask();
        return w.targets.contains(w.key(w.x, w.y));
    }

    /** Anzahl der Schrauben im Korb. */
    public static int schraubenImKorb() {
        return ask().basket;
    }

    /** Anzahl der Schrauben auf dem Feld von Robo. */
    public static int schraubenHier() {
        return ask().screwsHere();
    }

    /** Blickrichtung als Buchstabe: 'N', 'O', 'S' oder 'W'. */
    public static char richtung() {
        char d = DIRS.charAt(ask().dir);
        return d == 'E' ? 'O' : d;
    }

    // ---------------------------------------------------------------------
    // Innenleben
    // ---------------------------------------------------------------------

    private static synchronized void act(char code) {
        World w = load();
        count(w);
        try {
            w.step(code);
        } catch (RoboFehler e) {
            report(e.getMessage());
            throw e;
        }
        emit(String.valueOf(code));
    }

    private static synchronized World ask() {
        World w = load();
        count(w);
        return w;
    }

    private static void count(World w) {
        calls++;
        if (calls > MAX_CALLS) {
            w.crashed = true;
            String m = "Robo wurde zu oft befragt oder bewegt. Läuft eine Endlosschleife?";
            report(m);
            throw fail(m);
        }
    }

    private static void report(String message) {
        if (!reported) {
            reported = true;
            emit("!" + message);
        }
    }

    private static void emit(String text) {
        if (trace) {
            System.out.println(TRACE_PREFIX + text.replace('\n', ' ').replace('\r', ' '));
            System.out.flush();
        }
    }

    /** RoboFehler ohne die Rahmen dieser Klasse im Stapel, damit die erste Zeile auf den Schülercode zeigt. */
    private static RoboFehler fail(String message) {
        RoboFehler e = new RoboFehler(message);
        StackTraceElement[] all = e.getStackTrace();
        int start = 0;
        while (start < all.length && (all[start].getClassName().equals("Robo") || all[start].getClassName().startsWith("Robo$"))) {
            start++;
        }
        StackTraceElement[] cut = new StackTraceElement[all.length - start];
        System.arraycopy(all, start, cut, 0, cut.length);
        e.setStackTrace(cut);
        return e;
    }

    private static World load() {
        if (world != null) {
            return world;
        }
        String json = readWorldFile();
        if (json == null) {
            json = readWorldStdin();
        }
        if (json != null) {
            try {
                Object parsed = new Json(json).parse();
                world = new World(parsed);
                trace = true;
            } catch (RuntimeException e) {
                String m = "Die Welt dieser Aufgabe ist ungültig: " + e.getMessage();
                throw new RoboFehler(m);
            }
        } else {
            Map<String, Object> m = new HashMap<>();
            m.put("cols", 8L);
            m.put("rows", 8L);
            Map<String, Object> robo = new HashMap<>();
            robo.put("x", 0L);
            robo.put("y", 0L);
            robo.put("dir", "E");
            m.put("robo", robo);
            world = new World(m);
        }
        return world;
    }

    private static String readWorldFile() {
        File f = new File(WORLD_FILE);
        if (!f.isFile() || f.length() > 400000) {
            return null;
        }
        try {
            return new String(Files.readAllBytes(f.toPath()), StandardCharsets.UTF_8);
        } catch (IOException e) {
            return null;
        }
    }

    /** Erste Eingabezeile "@KWROBO {json}", falls vorhanden (der Runner liefert sie sofort nach dem Start). */
    private static String readWorldStdin() {
        try {
            if (!System.in.markSupported()) {
                return null;
            }
            long until = System.currentTimeMillis() + 400;
            while (System.in.available() == 0 && System.currentTimeMillis() < until) {
                Thread.sleep(20);
            }
            if (System.in.available() == 0) {
                return null;
            }
            System.in.mark(450000);
            java.io.ByteArrayOutputStream buf = new java.io.ByteArrayOutputStream();
            int b;
            while ((b = System.in.read()) != -1 && b != '\n' && buf.size() < 400000) {
                buf.write(b);
            }
            String line = new String(buf.toByteArray(), StandardCharsets.UTF_8);
            if (line.startsWith(STDIN_PREFIX)) {
                return line.substring(STDIN_PREFIX.length());
            }
            System.in.reset();
            return null;
        } catch (IOException | InterruptedException e) {
            return null;
        }
    }

    // ---------------------------------------------------------------------
    // Welt
    // ---------------------------------------------------------------------

    private static final class World {
        final int cols;
        final int rows;
        int x;
        int y;
        int dir; // Index in DIRS
        final Set<Integer> walls = new HashSet<>();
        final Set<Integer> boxes = new HashSet<>();
        final Set<Integer> targets = new HashSet<>();
        final Map<Integer, Integer> screws = new HashMap<>();
        int basket;
        int moves;
        boolean crashed;

        @SuppressWarnings("unchecked")
        World(Object raw) {
            if (!(raw instanceof Map)) {
                throw new IllegalArgumentException("Die Welt fehlt.");
            }
            Map<String, Object> m = (Map<String, Object>) raw;
            cols = intOf(m.get("cols"), "cols");
            rows = intOf(m.get("rows"), "rows");
            if (cols < 2 || rows < 2 || cols > 30 || rows > 30) {
                throw new IllegalArgumentException("Die Welt muss zwischen 2 und 30 Spalten und Zeilen haben.");
            }
            Object roboObj = m.get("robo");
            if (!(roboObj instanceof Map)) {
                throw new IllegalArgumentException("Robo fehlt.");
            }
            Map<String, Object> r = (Map<String, Object>) roboObj;
            x = intOf(r.get("x"), "robo.x");
            y = intOf(r.get("y"), "robo.y");
            if (!inside(x, y)) {
                throw new IllegalArgumentException("Robo steht außerhalb der Welt.");
            }
            String d = r.get("dir") == null ? "E" : String.valueOf(r.get("dir")).toUpperCase();
            if (d.equals("O")) {
                d = "E";
            }
            dir = d.length() == 1 ? DIRS.indexOf(d.charAt(0)) : -1;
            if (dir < 0) {
                throw new IllegalArgumentException("Ungültige Blickrichtung.");
            }
            cells(m.get("walls"), walls);
            cells(m.get("boxes"), boxes);
            cells(m.get("targets"), targets);
            if (walls.contains(key(x, y)) || boxes.contains(key(x, y))) {
                throw new IllegalArgumentException("Robo steht auf einer Wand oder Kiste.");
            }
            Object sc = m.get("screws");
            if (sc instanceof List) {
                for (Object o : (List<Object>) sc) {
                    if (!(o instanceof Map)) {
                        throw new IllegalArgumentException("Schrauben: ungültiger Eintrag.");
                    }
                    Map<String, Object> s = (Map<String, Object>) o;
                    int sx = intOf(s.get("x"), "x");
                    int sy = intOf(s.get("y"), "y");
                    int n = intOf(s.get("count"), "count");
                    if (!inside(sx, sy) || n < 1) {
                        throw new IllegalArgumentException("Schrauben: ungültiges Feld oder ungültige Anzahl.");
                    }
                    screws.put(key(sx, sy), n);
                }
            }
            Object bk = m.get("basket");
            basket = bk == null ? 0 : intOf(bk, "basket");
        }

        @SuppressWarnings("unchecked")
        private void cells(Object raw, Set<Integer> into) {
            if (!(raw instanceof List)) {
                return;
            }
            for (Object o : (List<Object>) raw) {
                if (!(o instanceof List) || ((List<Object>) o).size() != 2) {
                    throw new IllegalArgumentException("Jede Position muss die Form [x, y] haben.");
                }
                List<Object> p = (List<Object>) o;
                int cx = intOf(p.get(0), "x");
                int cy = intOf(p.get(1), "y");
                if (!inside(cx, cy)) {
                    throw new IllegalArgumentException("Das Feld (" + cx + "|" + cy + ") liegt außerhalb der Welt.");
                }
                into.add(key(cx, cy));
            }
        }

        private static int intOf(Object o, String name) {
            if (o instanceof Long) {
                long v = (Long) o;
                if (v >= -100000 && v <= 100000) {
                    return (int) v;
                }
            }
            throw new IllegalArgumentException("Ungültige Zahl bei " + name + ".");
        }

        int key(int cx, int cy) {
            return cy * cols + cx;
        }

        boolean inside(int cx, int cy) {
            return cx >= 0 && cy >= 0 && cx < cols && cy < rows;
        }

        int[] front(int distance) {
            return new int[] {x + DELTA[dir][0] * distance, y + DELTA[dir][1] * distance};
        }

        boolean isWall(int cx, int cy) {
            return !inside(cx, cy) || walls.contains(key(cx, cy));
        }

        boolean hasBox(int cx, int cy) {
            return inside(cx, cy) && boxes.contains(key(cx, cy));
        }

        int screwsHere() {
            Integer n = screws.get(key(x, y));
            return n == null ? 0 : n;
        }

        private void crash(String message) {
            crashed = true;
            throw Robo.fail(message);
        }

        void step(char code) {
            if (crashed) {
                throw Robo.fail("Robo ist kaputt und kann keine Befehle mehr ausführen.");
            }
            if (moves >= MAX_MOVES) {
                crash("Robo hat mehr als " + MAX_MOVES + " Befehle ausgeführt. Läuft eine Endlosschleife?");
            }
            switch (code) {
                case 'v': {
                    int[] f = front(1);
                    if (!inside(f[0], f[1])) {
                        crash("Robo ist gegen den Rand der Welt gelaufen.");
                    }
                    if (walls.contains(key(f[0], f[1]))) {
                        crash("Robo ist gegen eine Wand gelaufen.");
                    }
                    if (boxes.contains(key(f[0], f[1]))) {
                        crash("Robo ist gegen eine Kiste gelaufen. Mit schieben() kann er sie wegschieben.");
                    }
                    x = f[0];
                    y = f[1];
                    break;
                }
                case 'l':
                    dir = (dir + 3) % 4;
                    break;
                case 'r':
                    dir = (dir + 1) % 4;
                    break;
                case 'a': {
                    int n = screwsHere();
                    if (n < 1) {
                        crash("Hier liegt keine Schraube, die Robo aufheben kann.");
                    }
                    if (n == 1) {
                        screws.remove(key(x, y));
                    } else {
                        screws.put(key(x, y), n - 1);
                    }
                    basket++;
                    break;
                }
                case 'b': {
                    if (basket < 1) {
                        crash("Der Korb ist leer, Robo hat nichts zum Ablegen.");
                    }
                    screws.put(key(x, y), screwsHere() + 1);
                    basket--;
                    break;
                }
                case 's': {
                    int[] f = front(1);
                    if (!hasBox(f[0], f[1])) {
                        crash("Vor Robo steht keine Kiste, die er schieben könnte.");
                    }
                    int[] t = front(2);
                    if (!inside(t[0], t[1]) || walls.contains(key(t[0], t[1])) || boxes.contains(key(t[0], t[1]))) {
                        crash("Die Kiste lässt sich nicht schieben, dahinter ist kein freies Feld.");
                    }
                    boxes.remove(key(f[0], f[1]));
                    boxes.add(key(t[0], t[1]));
                    x = f[0];
                    y = f[1];
                    break;
                }
                default:
                    crash("Unbekannter Befehl im Protokoll.");
            }
            moves++;
        }
    }

    // ---------------------------------------------------------------------
    // Kleiner JSON-Leser (Objekte, Listen, ganze Zahlen, Strings, true/false/null)
    // ---------------------------------------------------------------------

    private static final class Json {
        private final String s;
        private int i;

        Json(String text) {
            s = text;
        }

        Object parse() {
            Object v = value();
            ws();
            if (i != s.length()) {
                throw new IllegalArgumentException("Unerwartete Zeichen am Ende der Weltdaten.");
            }
            return v;
        }

        private void ws() {
            while (i < s.length() && Character.isWhitespace(s.charAt(i))) {
                i++;
            }
        }

        private Object value() {
            ws();
            if (i >= s.length()) {
                throw new IllegalArgumentException("Die Weltdaten sind unvollständig.");
            }
            char c = s.charAt(i);
            if (c == '{') {
                return object();
            }
            if (c == '[') {
                return array();
            }
            if (c == '"') {
                return string();
            }
            if (s.startsWith("true", i)) {
                i += 4;
                return Boolean.TRUE;
            }
            if (s.startsWith("false", i)) {
                i += 5;
                return Boolean.FALSE;
            }
            if (s.startsWith("null", i)) {
                i += 4;
                return null;
            }
            return number();
        }

        private Map<String, Object> object() {
            Map<String, Object> m = new HashMap<>();
            i++;
            ws();
            if (i < s.length() && s.charAt(i) == '}') {
                i++;
                return m;
            }
            while (true) {
                ws();
                String k = string();
                ws();
                expect(':');
                m.put(k, value());
                ws();
                if (i < s.length() && s.charAt(i) == ',') {
                    i++;
                    continue;
                }
                expect('}');
                return m;
            }
        }

        private List<Object> array() {
            List<Object> l = new ArrayList<>();
            i++;
            ws();
            if (i < s.length() && s.charAt(i) == ']') {
                i++;
                return l;
            }
            while (true) {
                l.add(value());
                ws();
                if (i < s.length() && s.charAt(i) == ',') {
                    i++;
                    continue;
                }
                expect(']');
                return l;
            }
        }

        private void expect(char c) {
            if (i >= s.length() || s.charAt(i) != c) {
                throw new IllegalArgumentException("Erwartet: " + c);
            }
            i++;
        }

        private String string() {
            expect('"');
            StringBuilder sb = new StringBuilder();
            while (i < s.length()) {
                char c = s.charAt(i++);
                if (c == '"') {
                    return sb.toString();
                }
                if (c == '\\' && i < s.length()) {
                    char e = s.charAt(i++);
                    switch (e) {
                        case 'n': sb.append('\n'); break;
                        case 't': sb.append('\t'); break;
                        case 'u':
                            sb.append((char) Integer.parseInt(s.substring(i, i + 4), 16));
                            i += 4;
                            break;
                        default: sb.append(e);
                    }
                } else {
                    sb.append(c);
                }
            }
            throw new IllegalArgumentException("Zeichenkette nicht beendet.");
        }

        private Long number() {
            int start = i;
            if (i < s.length() && (s.charAt(i) == '-' || s.charAt(i) == '+')) {
                i++;
            }
            while (i < s.length() && Character.isDigit(s.charAt(i))) {
                i++;
            }
            if (start == i) {
                throw new IllegalArgumentException("Zahl erwartet.");
            }
            return Long.valueOf(s.substring(start, i));
        }
    }
}
