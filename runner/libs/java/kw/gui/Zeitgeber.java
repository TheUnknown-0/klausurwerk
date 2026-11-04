package kw.gui;

/**
 * Ruft eine Aktion in regelmäßigen Abständen auf (während {@link Fenster#zeigen()} oder
 * {@link Fenster#warte(int)} läuft). Erzeugt mit {@link Fenster#zeitgeber(int, Runnable)}.
 */
public class Zeitgeber {
    final int abstandMs;
    final Runnable aktion;
    long faelligNs;
    boolean aktiv = true;

    Zeitgeber(int abstandMs, Runnable aktion) {
        this.abstandMs = Math.max(10, abstandMs);
        this.aktion = aktion;
        this.faelligNs = System.nanoTime() + this.abstandMs * 1_000_000L;
    }

    /** Hält den Zeitgeber an. */
    public void stoppen() {
        aktiv = false;
    }

    /** Läuft der Zeitgeber noch? */
    public boolean laeuft() {
        return aktiv;
    }
}
