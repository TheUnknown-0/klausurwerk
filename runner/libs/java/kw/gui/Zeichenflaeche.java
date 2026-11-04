package kw.gui;

import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.function.Consumer;

/**
 * Eine Fläche zum Zeichnen. Koordinaten sind Pixel, (0, 0) ist links oben in der Fläche.
 * Gezeichnet wird in der Farbe, die zuletzt mit {@link #farbe(String)} eingestellt wurde.
 */
public class Zeichenflaeche extends Komponente {
    private final List<String> befehle = new ArrayList<>();
    private String stift = "#000000";
    private int linienbreite = 2;
    private int textgroesse = 16;
    private MausHandler beiMausklick;
    private Consumer<String> beiTaste;

    Zeichenflaeche(Fenster fenster, String id, int x, int y, int breite, int hoehe) {
        super(fenster, id, x, y, breite, hoehe);
    }

    @Override
    String typ() {
        return "flaeche";
    }

    /** Zeichenfarbe, z. B. "rot" oder "#336699". */
    @Override
    public void farbe(String farbe) {
        stift = Farben.hex(farbe);
    }

    /** Dicke der Linien und Umrisse in Pixeln. */
    public void linienbreite(int pixel) {
        linienbreite = Math.max(1, Math.min(pixel, 50));
    }

    /** Größe der Schrift für {@link #text(String, int, int)}. */
    @Override
    public void schriftgroesse(int pixel) {
        textgroesse = Math.max(8, Math.min(pixel, 96));
    }

    /** Zeichnet eine Linie. */
    public void linie(int x1, int y1, int x2, int y2) {
        befehl("[\"l\"," + x1 + "," + y1 + "," + x2 + "," + y2 + "," + Protokoll.text(stift) + "," + linienbreite + "]");
    }

    /** Zeichnet den Umriss eines Rechtecks (x, y ist die linke obere Ecke). */
    public void rechteck(int x, int y, int breite, int hoehe) {
        befehl("[\"r\"," + x + "," + y + "," + breite + "," + hoehe + "," + Protokoll.text(stift) + "," + linienbreite + ",0]");
    }

    /** Zeichnet ein ausgefülltes Rechteck. */
    public void rechteckGefuellt(int x, int y, int breite, int hoehe) {
        befehl("[\"r\"," + x + "," + y + "," + breite + "," + hoehe + "," + Protokoll.text(stift) + "," + linienbreite + ",1]");
    }

    /** Zeichnet den Umriss eines Kreises (Mittelpunkt x, y und Radius). */
    public void kreis(int x, int y, int radius) {
        befehl("[\"k\"," + x + "," + y + "," + radius + "," + Protokoll.text(stift) + "," + linienbreite + ",0]");
    }

    /** Zeichnet einen ausgefüllten Kreis. */
    public void kreisGefuellt(int x, int y, int radius) {
        befehl("[\"k\"," + x + "," + y + "," + radius + "," + Protokoll.text(stift) + "," + linienbreite + ",1]");
    }

    /** Schreibt Text; x, y ist die linke Unterkante der ersten Zeile. */
    public void text(String text, int x, int y) {
        befehl("[\"t\"," + x + "," + y + "," + Protokoll.text(text) + "," + Protokoll.text(stift) + "," + textgroesse + "]");
    }

    /** Löscht alles, was auf der Fläche gezeichnet wurde. */
    public void loeschen() {
        befehle.clear();
        befehl("[\"c\"]");
    }

    /** Wird bei einem Mausklick auf die Fläche aufgerufen, mit den Pixelkoordinaten. */
    public void beiMausklick(MausHandler aktion) {
        this.beiMausklick = aktion;
        merke("maus", String.valueOf(aktion != null));
    }

    /** Wird aufgerufen, wenn eine Taste gedrückt wird, während die Fläche den Fokus hat (z. B. "a", "ArrowUp"). */
    public void beiTaste(Consumer<String> aktion) {
        this.beiTaste = aktion;
        merke("tasten", String.valueOf(aktion != null));
    }

    private void befehl(String json) {
        befehle.add(json);
        fenster.meldeAenderung(this);
    }

    /** Baut die Zeichenoperationen und leert die Liste (null, wenn nichts zu senden ist). */
    final String nimmBefehle() {
        if (befehle.isEmpty()) {
            return null;
        }
        StringBuilder sb = new StringBuilder("{\"op\":\"zeichne\",\"id\":").append(Protokoll.text(id)).append(",\"befehle\":[");
        for (int i = 0; i < befehle.size(); i++) {
            if (i > 0) {
                sb.append(',');
            }
            sb.append(befehle.get(i));
        }
        befehle.clear();
        return sb.append("]}").toString();
    }

    @Override
    void ereignis(Map<String, Object> e) {
        String art = String.valueOf(e.get("e"));
        if ("maus".equals(art) && beiMausklick != null) {
            beiMausklick.klick(Protokoll.zahl(e, "x"), Protokoll.zahl(e, "y"));
        } else if ("taste".equals(art) && beiTaste != null) {
            beiTaste.accept(Protokoll.text(e, "taste"));
        }
    }
}
