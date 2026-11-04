package kw.gui;

import java.util.Map;
import java.util.function.Consumer;

/** Ein Eingabefeld für eine Zeile Text. */
public class Textfeld extends Komponente {
    private String text;
    private Consumer<String> beiAenderung;
    private Consumer<String> beiEingabe;

    Textfeld(Fenster fenster, String id, String text, int x, int y, int breite) {
        super(fenster, id, x, y, breite, 30);
        text(text);
    }

    @Override
    String typ() {
        return "textfeld";
    }

    /** Text im Feld ändern. */
    public void text(String text) {
        this.text = text == null ? "" : text;
        merke("text", Protokoll.text(this.text));
    }

    /** Text, der gerade im Feld steht. */
    public String text() {
        return text;
    }

    /** Platzhalter, solange das Feld leer ist. */
    public void hinweis(String hinweis) {
        merke("hinweis", Protokoll.text(hinweis));
    }

    /** Wird bei jeder Änderung des Textes aufgerufen (mit dem neuen Text). */
    public void beiAenderung(Consumer<String> aktion) {
        this.beiAenderung = aktion;
    }

    /** Wird aufgerufen, wenn die Eingabetaste gedrückt wird (mit dem Text). */
    public void beiEingabe(Consumer<String> aktion) {
        this.beiEingabe = aktion;
    }

    @Override
    void ereignis(Map<String, Object> e) {
        String art = String.valueOf(e.get("e"));
        if ("text".equals(art) || "enter".equals(art)) {
            text = Protokoll.text(e, "wert");
            if (text.length() > 20000) {
                text = text.substring(0, 20000);
            }
            Consumer<String> ziel = "text".equals(art) ? beiAenderung : beiEingabe;
            if (ziel != null) {
                ziel.accept(text);
            }
        }
    }
}
