package kw.gui;

import java.util.HashMap;
import java.util.Map;

/** Farbnamen auf Deutsch und Hexwerte. Unbekannte Namen ergeben Schwarz. */
final class Farben {
    private static final Map<String, String> NAMEN = new HashMap<>();

    static {
        NAMEN.put("schwarz", "#000000");
        NAMEN.put("weiss", "#ffffff");
        NAMEN.put("weiß", "#ffffff");
        NAMEN.put("rot", "#d62828");
        NAMEN.put("gruen", "#2a9d3f");
        NAMEN.put("grün", "#2a9d3f");
        NAMEN.put("blau", "#1d4ed8");
        NAMEN.put("gelb", "#f4c20d");
        NAMEN.put("orange", "#f77f00");
        NAMEN.put("violett", "#7b2cbf");
        NAMEN.put("lila", "#7b2cbf");
        NAMEN.put("rosa", "#f48fb1");
        NAMEN.put("braun", "#7f5539");
        NAMEN.put("grau", "#6b7280");
        NAMEN.put("hellgrau", "#d1d5db");
        NAMEN.put("dunkelgrau", "#374151");
        NAMEN.put("tuerkis", "#0e9aa7");
        NAMEN.put("türkis", "#0e9aa7");
        NAMEN.put("hellblau", "#7dd3fc");
        NAMEN.put("hellgruen", "#86efac");
        NAMEN.put("hellgrün", "#86efac");
        NAMEN.put("durchsichtig", "transparent");
    }

    private Farben() {
    }

    static String hex(String name) {
        if (name == null) {
            return "#000000";
        }
        String n = name.trim().toLowerCase();
        String bekannt = NAMEN.get(n);
        if (bekannt != null) {
            return bekannt;
        }
        if (n.matches("#[0-9a-f]{3}|#[0-9a-f]{6}")) {
            return n;
        }
        return "#000000";
    }
}
