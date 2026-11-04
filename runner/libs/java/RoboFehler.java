/**
 * Robo kann einen Befehl nicht ausführen (gegen eine Wand gelaufen, nichts zum Aufheben, ...).
 * Das Programm endet mit dieser Meldung, wenn der Fehler nicht abgefangen wird.
 */
public class RoboFehler extends RuntimeException {
    private static final long serialVersionUID = 1L;

    public RoboFehler(String meldung) {
        super(meldung);
    }
}
