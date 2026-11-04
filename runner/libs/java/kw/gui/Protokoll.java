package kw.gui;

import java.io.BufferedReader;
import java.io.IOException;
import java.io.InputStreamReader;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.BlockingQueue;
import java.util.concurrent.LinkedBlockingQueue;

/**
 * Das Protokoll zwischen Programm und Browser (nur für die Bibliothek, nicht für Schüler:innen).
 *
 * <p>Frames gehen als Zeilen {@code U+0001 "KWGUI:" + JSON} auf die Standardausgabe, Ereignisse kommen
 * als JSON-Zeilen über die Standardeingabe. Das Format steht in README.md.
 */
final class Protokoll {
    static final String PREFIX = new String(new char[] {1}) + "KWGUI:";
    static final Map<String, Object> ENDE = new HashMap<>();

    private static final BlockingQueue<Map<String, Object>> EREIGNISSE = new LinkedBlockingQueue<>();
    private static boolean leserLaeuft;

    private Protokoll() {
    }

    // ---------------------------------------------------------------------
    // Ausgabe
    // ---------------------------------------------------------------------

    /** Schreibt einen Frame mit den Änderungen {@code ops} (Liste von JSON-Texten). */
    static void sende(List<String> ops) {
        if (ops.isEmpty()) {
            return;
        }
        StringBuilder sb = new StringBuilder(PREFIX).append("{\"ops\":[");
        for (int i = 0; i < ops.size(); i++) {
            if (i > 0) {
                sb.append(',');
            }
            sb.append(ops.get(i));
        }
        sb.append("]}");
        System.out.println(sb);
        System.out.flush();
    }

    /** JSON-Text in Anführungszeichen. */
    static String text(String s) {
        if (s == null) {
            s = "";
        }
        if (s.length() > 20000) {
            s = s.substring(0, 20000);
        }
        StringBuilder sb = new StringBuilder("\"");
        for (int i = 0; i < s.length(); i++) {
            char c = s.charAt(i);
            switch (c) {
                case '"': sb.append("\\\""); break;
                case '\\': sb.append("\\\\"); break;
                case '\n': sb.append("\\n"); break;
                case '\r': sb.append("\\r"); break;
                case '\t': sb.append("\\t"); break;
                default:
                    if (c < 0x20) {
                        sb.append(String.format("\\u%04x", (int) c));
                    } else {
                        sb.append(c);
                    }
            }
        }
        return sb.append('"').toString();
    }

    // ---------------------------------------------------------------------
    // Eingabe
    // ---------------------------------------------------------------------

    /** Startet (einmal) den Thread, der Ereigniszeilen von der Standardeingabe liest. */
    static synchronized void starteLeser() {
        if (leserLaeuft) {
            return;
        }
        leserLaeuft = true;
        Thread t = new Thread(() -> {
            try (BufferedReader in = new BufferedReader(new InputStreamReader(System.in, StandardCharsets.UTF_8))) {
                String zeile;
                while ((zeile = in.readLine()) != null) {
                    Map<String, Object> e = parse(zeile);
                    if (e != null) {
                        EREIGNISSE.add(e);
                    }
                }
            } catch (IOException e) {
                // Eingabe geschlossen
            }
            EREIGNISSE.add(ENDE);
        }, "kw-gui-eingabe");
        t.setDaemon(true);
        t.start();
    }

    static Map<String, Object> naechstes(long wartenMs) throws InterruptedException {
        return EREIGNISSE.poll(wartenMs, java.util.concurrent.TimeUnit.MILLISECONDS);
    }

    /** Liest ein flaches JSON-Objekt aus Texten und Zahlen. Alles andere ergibt {@code null}. */
    static Map<String, Object> parse(String zeile) {
        String s = zeile.trim();
        if (s.length() < 2 || s.charAt(0) != '{' || s.charAt(s.length() - 1) != '}') {
            return null;
        }
        Map<String, Object> m = new HashMap<>();
        int[] pos = {1};
        try {
            while (true) {
                ueberspringe(s, pos);
                if (s.charAt(pos[0]) == '}') {
                    return m;
                }
                String key = zeichenkette(s, pos);
                ueberspringe(s, pos);
                if (s.charAt(pos[0]++) != ':') {
                    return null;
                }
                ueberspringe(s, pos);
                char c = s.charAt(pos[0]);
                Object value;
                if (c == '"') {
                    value = zeichenkette(s, pos);
                } else {
                    int start = pos[0];
                    while (pos[0] < s.length() && "+-0123456789.eE".indexOf(s.charAt(pos[0])) >= 0) {
                        pos[0]++;
                    }
                    if (start == pos[0]) {
                        if (s.startsWith("true", start)) {
                            pos[0] += 4;
                            value = Boolean.TRUE;
                        } else if (s.startsWith("false", start)) {
                            pos[0] += 5;
                            value = Boolean.FALSE;
                        } else {
                            return null;
                        }
                    } else {
                        value = Double.valueOf(s.substring(start, pos[0]));
                    }
                }
                m.put(key, value);
                ueberspringe(s, pos);
                if (s.charAt(pos[0]) == ',') {
                    pos[0]++;
                }
            }
        } catch (RuntimeException e) {
            return null;
        }
    }

    private static void ueberspringe(String s, int[] pos) {
        while (pos[0] < s.length() && Character.isWhitespace(s.charAt(pos[0]))) {
            pos[0]++;
        }
    }

    private static String zeichenkette(String s, int[] pos) {
        if (s.charAt(pos[0]++) != '"') {
            throw new IllegalArgumentException();
        }
        StringBuilder sb = new StringBuilder();
        while (true) {
            char c = s.charAt(pos[0]++);
            if (c == '"') {
                return sb.toString();
            }
            if (c == '\\') {
                char e = s.charAt(pos[0]++);
                switch (e) {
                    case 'n': sb.append('\n'); break;
                    case 't': sb.append('\t'); break;
                    case 'r': sb.append('\r'); break;
                    case 'u':
                        sb.append((char) Integer.parseInt(s.substring(pos[0], pos[0] + 4), 16));
                        pos[0] += 4;
                        break;
                    default: sb.append(e);
                }
            } else {
                sb.append(c);
            }
        }
    }

    static String text(Map<String, Object> e, String key) {
        Object v = e.get(key);
        return v == null ? "" : String.valueOf(v);
    }

    static int zahl(Map<String, Object> e, String key) {
        Object v = e.get(key);
        return v instanceof Double ? (int) Math.round((Double) v) : 0;
    }

    /** Hilfsliste für Fenster: leere Operationsliste. */
    static List<String> neueListe() {
        return new ArrayList<>();
    }
}
