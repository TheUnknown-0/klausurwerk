package kw.gui;

import java.util.Map;

/** Ein Knopf. {@code knopf.beiKlick(() -> ...)} legt fest, was beim Klick passiert. */
public class Knopf extends Komponente {
    private String text;
    private Runnable beiKlick;

    Knopf(Fenster fenster, String id, String text, int x, int y, int breite, int hoehe) {
        super(fenster, id, x, y, breite, hoehe);
        text(text);
    }

    @Override
    String typ() {
        return "knopf";
    }

    /** Beschriftung ändern. */
    public void text(String text) {
        this.text = text == null ? "" : text;
        merke("text", Protokoll.text(this.text));
    }

    /** Aktuelle Beschriftung. */
    public String text() {
        return text;
    }

    /** Legt fest, was bei einem Klick geschieht. */
    public void beiKlick(Runnable aktion) {
        this.beiKlick = aktion;
    }

    @Override
    void ereignis(Map<String, Object> e) {
        if ("klick".equals(e.get("e")) && beiKlick != null) {
            beiKlick.run();
        }
    }
}
