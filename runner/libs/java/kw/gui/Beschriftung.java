package kw.gui;

/** Ein Text im Fenster. */
public class Beschriftung extends Komponente {
    private String text;

    Beschriftung(Fenster fenster, String id, String text, int x, int y) {
        super(fenster, id, x, y, 300, 24);
        text(text);
    }

    @Override
    String typ() {
        return "beschriftung";
    }

    /** Text ändern. */
    public void text(String text) {
        this.text = text == null ? "" : text;
        merke("text", Protokoll.text(this.text));
    }

    /** Aktueller Text. */
    public String text() {
        return text;
    }
}
