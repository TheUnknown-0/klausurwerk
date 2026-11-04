package kw.gui;

/** Wird bei einem Mausklick auf die Zeichenfläche aufgerufen; x und y sind Pixel in der Fläche. */
@FunctionalInterface
public interface MausHandler {
    void klick(int x, int y);
}
