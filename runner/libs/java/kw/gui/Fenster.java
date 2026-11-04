package kw.gui;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;

/**
 * Ein Fenster mit Knöpfen, Texten, Eingabefeldern und Zeichenflächen.
 *
 * <p>Echtes Swing läuft auf dem Server nicht (kein Bildschirm). Diese Bibliothek schickt das Fenster
 * stattdessen an den Browser und holt Klicks und Eingaben von dort zurück. Beschreibung und Beispiele
 * stehen in README.md.
 *
 * <pre>
 * Fenster f = new Fenster("Zähler", 300, 160);
 * Beschriftung anzeige = f.beschriftung("0", 20, 20);
 * Knopf plus = f.knopf("+1", 20, 60, 100, 36);
 * int[] n = {0};
 * plus.beiKlick(() -> { n[0]++; anzeige.text("" + n[0]); });
 * f.zeigen();
 * </pre>
 *
 * <p>Es gibt höchstens ein Fenster je Programm. Alle Ereignisbehandler laufen nacheinander im
 * Thread, der {@link #zeigen()} oder {@link #warte(int)} aufgerufen hat; Synchronisieren ist nicht nötig.
 */
public class Fenster {
    private static Fenster aktuelles;

    private final String[] titel = {""};
    private int breite;
    private int hoehe;
    private String hintergrund = "#ffffff";
    private boolean kopfGesendet;
    private boolean offen = true;
    private int zaehler;
    private Runnable beiSchliessen;

    private final Map<String, Komponente> komponenten = new LinkedHashMap<>();
    private final Set<Komponente> geaenderte = new LinkedHashSet<>();
    private final List<Zeitgeber> zeitgeber = new ArrayList<>();

    /**
     * Legt ein Fenster an. Es erscheint, sobald {@link #zeigen()}, {@link #warte(int)} oder
     * {@link #aktualisieren()} aufgerufen wird.
     */
    public Fenster(String titel, int breite, int hoehe) {
        if (aktuelles != null && aktuelles.offen) {
            throw new IllegalStateException("Es kann nur ein Fenster gleichzeitig geben. Schließe das erste mit schliessen().");
        }
        aktuelles = this;
        this.titel[0] = titel == null ? "" : titel;
        this.breite = Math.max(50, Math.min(breite, 3000));
        this.hoehe = Math.max(50, Math.min(hoehe, 3000));
        Protokoll.starteLeser();
    }

    // ---------------------------------------------------------------------
    // Bausteine
    // ---------------------------------------------------------------------

    /** Legt einen Knopf an (x, y ist die linke obere Ecke). */
    public Knopf knopf(String text, int x, int y, int breite, int hoehe) {
        return anmelden(new Knopf(this, neueId("k"), text, x, y, breite, hoehe));
    }

    /** Legt einen Text an. */
    public Beschriftung beschriftung(String text, int x, int y) {
        return anmelden(new Beschriftung(this, neueId("b"), text, x, y));
    }

    /** Legt ein Eingabefeld an. */
    public Textfeld textfeld(String text, int x, int y, int breite) {
        return anmelden(new Textfeld(this, neueId("t"), text, x, y, breite));
    }

    /** Legt eine Fläche zum Zeichnen an. */
    public Zeichenflaeche zeichenflaeche(int x, int y, int breite, int hoehe) {
        return anmelden(new Zeichenflaeche(this, neueId("z"), x, y, breite, hoehe));
    }

    private <T extends Komponente> T anmelden(T k) {
        if (komponenten.size() >= 300) {
            throw new IllegalStateException("Ein Fenster kann höchstens 300 Bausteine enthalten.");
        }
        komponenten.put(k.id, k);
        return k;
    }

    private String neueId(String praefix) {
        return praefix + (++zaehler);
    }

    /** Entfernt einen Baustein aus dem Fenster. */
    public void entfernen(Komponente k) {
        if (komponenten.remove(k.id) != null) {
            geaenderte.remove(k);
            if (kopfGesendet) {
                List<String> ops = new ArrayList<>();
                ops.add("{\"op\":\"del\",\"id\":" + Protokoll.text(k.id) + "}");
                Protokoll.sende(ops);
            }
        }
    }

    // ---------------------------------------------------------------------
    // Fenster
    // ---------------------------------------------------------------------

    /** Ändert den Titel in der Titelleiste. */
    public void titel(String titel) {
        this.titel[0] = titel == null ? "" : titel;
        kopfGesendet = false;
    }

    /** Hintergrundfarbe des Fensters, z. B. "hellgrau". */
    public void hintergrund(String farbe) {
        this.hintergrund = Farben.hex(farbe);
        kopfGesendet = false;
    }

    /** Legt fest, was beim Schließen des Fensters passiert (zusätzlich zum Beenden von {@link #zeigen()}). */
    public void beiSchliessen(Runnable aktion) {
        this.beiSchliessen = aktion;
    }

    /** Ist das Fenster noch offen? */
    public boolean istOffen() {
        return offen;
    }

    /** Schließt das Fenster. {@link #zeigen()} kehrt dann zurück. */
    public void schliessen() {
        if (!offen) {
            return;
        }
        aktualisieren();
        offen = false;
        List<String> ops = new ArrayList<>();
        ops.add("{\"op\":\"schliessen\"}");
        Protokoll.sende(ops);
    }

    /** Legt einen Zeitgeber an, der {@code aktion} alle {@code millisekunden} aufruft (mindestens 10). */
    public Zeitgeber zeitgeber(int millisekunden, Runnable aktion) {
        Zeitgeber z = new Zeitgeber(millisekunden, aktion);
        zeitgeber.add(z);
        return z;
    }

    // ---------------------------------------------------------------------
    // Ereignisschleife
    // ---------------------------------------------------------------------

    /**
     * Zeigt das Fenster und bearbeitet Klicks, Eingaben und Zeitgeber, bis das Fenster geschlossen wird
     * (durch den Stopp-Knopf, {@link #schliessen()} oder das Programmende des Browsers).
     */
    public void zeigen() {
        aktualisieren();
        while (offen) {
            schritt(100);
        }
    }

    /**
     * Bearbeitet Ereignisse und Zeitgeber für die angegebene Zeit und kehrt dann zurück. Praktisch für
     * Animationen mit eigener Schleife: {@code while (f.istOffen()) { ...zeichnen...; f.warte(40); }}
     */
    public void warte(int millisekunden) {
        aktualisieren();
        long ende = System.nanoTime() + Math.max(0, millisekunden) * 1_000_000L;
        do {
            long rest = (ende - System.nanoTime()) / 1_000_000L;
            schritt(Math.max(1, Math.min(rest, 100)));
        } while (offen && System.nanoTime() < ende);
    }

    /** Schickt alle bisherigen Änderungen sofort an den Browser. */
    public void aktualisieren() {
        List<String> ops = new ArrayList<>();
        if (!kopfGesendet) {
            ops.add("{\"op\":\"fenster\",\"titel\":" + Protokoll.text(titel[0]) + ",\"breite\":" + breite
                + ",\"hoehe\":" + hoehe + ",\"farbe\":" + Protokoll.text(hintergrund) + "}");
            kopfGesendet = true;
        }
        List<Komponente> liste = new ArrayList<>(geaenderte);
        geaenderte.clear();
        for (Komponente k : liste) {
            if (!komponenten.containsKey(k.id)) {
                continue;
            }
            String set = k.nimmAenderungen();
            if (set != null) {
                ops.add(set);
            }
        }
        for (Komponente k : liste) {
            if (k instanceof Zeichenflaeche && komponenten.containsKey(k.id)) {
                String draw = ((Zeichenflaeche) k).nimmBefehle();
                if (draw != null) {
                    ops.add(draw);
                }
            }
        }
        // große Mengen in mehreren Frames senden, damit eine Zeile nicht riesig wird
        for (int i = 0; i < ops.size(); i += 200) {
            Protokoll.sende(ops.subList(i, Math.min(ops.size(), i + 200)));
        }
    }

    void meldeAenderung(Komponente k) {
        geaenderte.add(k);
    }

    private void schritt(long hoechstensWartenMs) {
        long wartezeit = hoechstensWartenMs;
        long jetzt = System.nanoTime();
        for (Zeitgeber z : zeitgeber) {
            if (z.aktiv) {
                wartezeit = Math.min(wartezeit, Math.max(0, (z.faelligNs - jetzt) / 1_000_000L));
            }
        }
        Map<String, Object> e = null;
        try {
            e = Protokoll.naechstes(Math.max(1, wartezeit));
        } catch (InterruptedException ex) {
            Thread.currentThread().interrupt();
            offen = false;
        }
        if (e != null) {
            behandle(e);
        }
        jetzt = System.nanoTime();
        for (Zeitgeber z : new ArrayList<>(zeitgeber)) {
            if (z.aktiv && z.faelligNs <= jetzt) {
                z.faelligNs = jetzt + z.abstandMs * 1_000_000L;
                sicher(z.aktion);
            }
        }
        zeitgeber.removeIf(z -> !z.aktiv);
        aktualisieren();
    }

    private void behandle(Map<String, Object> e) {
        if (e == Protokoll.ENDE || "zu".equals(e.get("e"))) {
            offen = false;
            if (beiSchliessen != null) {
                sicher(beiSchliessen);
            }
            return;
        }
        Komponente k = komponenten.get(Protokoll.text(e, "id"));
        if (k != null) {
            sicher(() -> k.ereignis(e));
        }
    }

    /** Fehler in Ereignisbehandlern erscheinen in der Konsole, das Fenster läuft weiter. */
    private void sicher(Runnable r) {
        try {
            r.run();
        } catch (RuntimeException ex) {
            ex.printStackTrace();
        }
    }
}
