package kw.gui;

import java.util.LinkedHashMap;
import java.util.Map;

/**
 * Gemeinsame Basis aller Bausteine im Fenster (Knopf, Beschriftung, Textfeld, Zeichenflaeche).
 * Positionen und Größen sind Pixel, (0, 0) ist links oben im Fenster.
 */
public abstract class Komponente {
    final String id;
    final Fenster fenster;
    private final Map<String, String> geaendert = new LinkedHashMap<>();
    private int x;
    private int y;
    private int breite;
    private int hoehe;

    Komponente(Fenster fenster, String id, int x, int y, int breite, int hoehe) {
        this.fenster = fenster;
        this.id = id;
        this.x = x;
        this.y = y;
        this.breite = Math.max(1, Math.min(breite, 3000));
        this.hoehe = Math.max(1, Math.min(hoehe, 3000));
        merke("x", String.valueOf(x));
        merke("y", String.valueOf(y));
        merke("b", String.valueOf(this.breite));
        merke("h", String.valueOf(this.hoehe));
    }

    /** Typ im Protokoll: knopf, beschriftung, textfeld oder flaeche. */
    abstract String typ();

    /** Merkt eine geänderte Eigenschaft vor; der Browser bekommt sie mit dem nächsten Frame. */
    final void merke(String schluessel, String json) {
        geaendert.put(schluessel, json);
        fenster.meldeAenderung(this);
    }

    /** Baut die Änderungen als Protokolloperation und leert den Vermerk (null, wenn nichts zu senden ist). */
    final String nimmAenderungen() {
        if (geaendert.isEmpty()) {
            return null;
        }
        StringBuilder sb = new StringBuilder("{\"op\":\"set\",\"id\":").append(Protokoll.text(id))
            .append(",\"typ\":").append(Protokoll.text(typ()));
        for (Map.Entry<String, String> e : geaendert.entrySet()) {
            sb.append(',').append(Protokoll.text(e.getKey())).append(':').append(e.getValue());
        }
        geaendert.clear();
        return sb.append('}').toString();
    }

    /** Wird aufgerufen, wenn der Browser ein Ereignis für diesen Baustein meldet. */
    void ereignis(Map<String, Object> e) {
    }

    public int x() {
        return x;
    }

    public int y() {
        return y;
    }

    public int breite() {
        return breite;
    }

    public int hoehe() {
        return hoehe;
    }

    /** Verschiebt den Baustein. */
    public void position(int x, int y) {
        this.x = x;
        this.y = y;
        merke("x", String.valueOf(x));
        merke("y", String.valueOf(y));
    }

    /** Ändert die Größe. */
    public void groesse(int breite, int hoehe) {
        this.breite = Math.max(1, Math.min(breite, 3000));
        this.hoehe = Math.max(1, Math.min(hoehe, 3000));
        merke("b", String.valueOf(this.breite));
        merke("h", String.valueOf(this.hoehe));
    }

    /** Macht den Baustein sichtbar oder unsichtbar. */
    public void sichtbar(boolean sichtbar) {
        merke("sichtbar", String.valueOf(sichtbar));
    }

    /** Schaltet den Baustein ein oder aus (ausgegraut, reagiert nicht). */
    public void aktiv(boolean aktiv) {
        merke("aktiv", String.valueOf(aktiv));
    }

    /** Schriftfarbe, z. B. "rot" oder "#336699". */
    public void farbe(String farbe) {
        merke("farbe", Protokoll.text(Farben.hex(farbe)));
    }

    /** Hintergrundfarbe des Bausteins. */
    public void hintergrund(String farbe) {
        merke("hintergrund", Protokoll.text(Farben.hex(farbe)));
    }

    /** Schriftgröße in Pixeln (8 bis 96). */
    public void schriftgroesse(int pixel) {
        merke("schrift", String.valueOf(Math.max(8, Math.min(pixel, 96))));
    }
}
