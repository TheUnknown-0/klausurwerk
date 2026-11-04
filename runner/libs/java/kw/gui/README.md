# kw.gui: kleine Fenster-Bibliothek für Java

Mit `kw.gui` bauen Schüler:innen Programme mit Fenster, Knöpfen, Eingabefeldern und
Zeichenflächen, ohne Swing zu lernen. Die Bibliothek liegt im Klassenpfad des Runners; im Programm
genügt `import kw.gui.*;`.

**Wichtig: Echtes Swing läuft hier nicht.** Der Runner hat keinen Bildschirm (headless, kein X11),
`javax.swing.JFrame` und `java.awt.Frame` werfen eine `HeadlessException`. `kw.gui` ersetzt Swing
deshalb: Das Programm beschreibt sein Fenster, der Browser zeichnet es im Reiter **Fenster** unter
dem Editor, und Klicks und Eingaben laufen zum Programm zurück. Dadurch sind die Namen und die
Aufgabenstellung dem Unterricht angepasst, aber **Swing-Code lässt sich nicht einfach übernehmen**.

Eine Aufgabe nutzt die Bibliothek, wenn im Aufgaben-Editor bei der Java-Aufgabe der Haken
„Programm hat eine grafische Oberfläche“ (`config.gui = true`) gesetzt ist. Nur dann zeigt das
Code-Widget den Reiter „Fenster“ und reicht Ereignisse weiter.

## Beispiel 1: Zähler

```java
import kw.gui.*;

public class Main {
    public static void main(String[] args) {
        Fenster f = new Fenster("Zähler", 300, 160);
        Beschriftung anzeige = f.beschriftung("0", 20, 20);
        Knopf plus = f.knopf("+1", 20, 60, 100, 36);
        Knopf nullKnopf = f.knopf("Zurücksetzen", 130, 60, 140, 36);

        int[] n = {0};                       // Lambdas dürfen nur Felder ändern, daher ein Feld
        plus.beiKlick(() -> {
            n[0]++;
            anzeige.text("" + n[0]);
        });
        nullKnopf.beiKlick(() -> {
            n[0] = 0;
            anzeige.text("0");
        });

        f.zeigen();                          // läuft, bis das Fenster geschlossen wird
    }
}
```

## Beispiel 2: Zeichnen mit der Maus

```java
import kw.gui.*;

public class Main {
    public static void main(String[] args) {
        Fenster f = new Fenster("Malen", 440, 360);
        Zeichenflaeche z = f.zeichenflaeche(20, 20, 400, 280);
        Knopf weg = f.knopf("Löschen", 20, 310, 100, 34);

        z.rechteck(0, 0, 399, 279);          // Rahmen
        z.beiMausklick((x, y) -> {
            z.farbe("blau");
            z.kreisGefuellt(x, y, 12);
        });
        weg.beiKlick(() -> {
            z.loeschen();
            z.farbe("schwarz");
            z.rechteck(0, 0, 399, 279);
        });
        f.zeigen();
    }
}
```

## Beispiel 3: Textfeld und Zeitgeber (Animation)

```java
import kw.gui.*;

public class Main {
    public static void main(String[] args) {
        Fenster f = new Fenster("Ball", 360, 300);
        Textfeld name = f.textfeld("", 20, 20, 200);
        name.hinweis("Dein Name");
        Beschriftung gruss = f.beschriftung("", 20, 60);
        name.beiAenderung(text -> gruss.text("Hallo " + text + "!"));

        Zeichenflaeche z = f.zeichenflaeche(20, 100, 320, 180);
        int[] x = {20};
        f.zeitgeber(40, () -> {              // alle 40 Millisekunden
            z.loeschen();
            z.farbe("rot");
            z.kreisGefuellt(x[0], 90, 14);
            x[0] = (x[0] + 4) % 320;
        });
        f.zeigen();
    }
}
```

Statt `zeigen()` geht auch eine eigene Schleife mit `warte(ms)`:

```java
while (f.istOffen()) {
    // zeichnen ...
    f.warte(40);                             // Ereignisse bearbeiten, dann weiter
}
```

## Überblick

| Klasse | Wichtige Methoden |
|---|---|
| `Fenster(titel, breite, hoehe)` | `knopf(text, x, y, b, h)`, `beschriftung(text, x, y)`, `textfeld(text, x, y, b)`, `zeichenflaeche(x, y, b, h)`, `entfernen(baustein)`, `titel(text)`, `hintergrund(farbe)`, `zeitgeber(ms, aktion)`, `zeigen()`, `warte(ms)`, `aktualisieren()`, `schliessen()`, `istOffen()`, `beiSchliessen(aktion)` |
| `Knopf` | `beiKlick(aktion)`, `text(…)`, `text()` |
| `Beschriftung` | `text(…)`, `text()` |
| `Textfeld` | `text()`, `text(…)`, `hinweis(…)`, `beiAenderung(text -> …)`, `beiEingabe(text -> …)` (Eingabetaste) |
| `Zeichenflaeche` | `farbe(…)`, `linienbreite(px)`, `schriftgroesse(px)`, `linie(x1, y1, x2, y2)`, `rechteck(x, y, b, h)`, `rechteckGefuellt(…)`, `kreis(x, y, r)`, `kreisGefuellt(…)`, `text(s, x, y)`, `loeschen()`, `beiMausklick((x, y) -> …)`, `beiTaste(taste -> …)` |
| alle Bausteine | `position(x, y)`, `groesse(b, h)`, `sichtbar(ja)`, `aktiv(ja)`, `farbe(…)`, `hintergrund(…)`, `schriftgroesse(px)` |
| `Zeitgeber` | `stoppen()`, `laeuft()` |

* Koordinaten sind Pixel, `(0, 0)` ist links oben. Bei Zeichenflächen zählt die Fläche selbst.
* Farben: `schwarz`, `weiss`, `rot`, `gruen`, `blau`, `gelb`, `orange`, `violett`, `rosa`, `braun`,
  `grau`, `hellgrau`, `dunkelgrau`, `tuerkis`, `hellblau`, `hellgruen` oder `#rrggbb` / `#rgb`.
* Alle Ereignisbehandler laufen nacheinander in dem Thread, der `zeigen()` bzw. `warte()` aufruft.
  Mehrere Threads und `synchronized` sind nicht nötig. Eine Ausnahme in einem Behandler erscheint in der
  Konsole, das Fenster läuft weiter.
* Es gibt höchstens **ein** Fenster je Programm und höchstens 300 Bausteine.
* Das Programm darf nicht gleichzeitig `System.in` lesen (zum Beispiel mit `Scanner`): Über die
  Standardeingabe kommen die Ereignisse des Browsers. `System.out.println` funktioniert wie sonst
  und erscheint in der Konsole.
* Ein Lauf endet nach höchstens 120 Sekunden (Zeitlimit für interaktive Programme) oder wenn
  Schüler:innen auf „Stopp“ klicken.

## Technische Beschreibung des Protokolls

Für Lehrkräfte, die die Bibliothek erweitern wollen. Beide Richtungen sind Zeilen mit JSON.

**Programm → Browser**: Zeilen auf der Standardausgabe der Form `U+0001 "KWGUI:" + {"ops":[…]}`. Der Runner
filtert sie aus der Ausgabe (`runner/kwrunner/modules/_guifilter.py`, nur bei `gui: true` im Lauf) und liefert
sie als `gui_frames` in `GET /runs/{id}`; die App leitet sie unverändert weiter (`GET /api/ausfuehren/{id}`).
Jeder Frame ist eine Änderungsliste (Frame-Diff), der Browser hält den Zustand und wendet sie an
(`frontend/src/gui/state.js`, Darstellung in `frontend/src/gui/view.js`):

| `op` | Felder | Bedeutung |
|---|---|---|
| `fenster` | `titel`, `breite`, `hoehe`, `farbe` | Fenster anlegen oder ändern |
| `set` | `id`, `typ` (`knopf`, `beschriftung`, `textfeld`, `flaeche`) und nur die geänderten Felder `x`, `y`, `b`, `h`, `text`, `aktiv`, `sichtbar`, `farbe`, `hintergrund`, `schrift`, `hinweis`, `maus`, `tasten` | Baustein anlegen oder ändern |
| `del` | `id` | Baustein entfernen |
| `zeichne` | `id`, `befehle` | Zeichenbefehle an eine Fläche anhängen: `["l",x1,y1,x2,y2,farbe,breite]`, `["r",x,y,b,h,farbe,breite,gefüllt]`, `["k",x,y,r,farbe,breite,gefüllt]`, `["t",x,y,text,farbe,größe]`, `["c"]` (löschen) |
| `schliessen` | | Fenster wurde vom Programm geschlossen |

**Browser → Programm**: Zeilen mit JSON über `POST /api/ausfuehren/{runId}/eingabe`, vom Runner auf die
Standardeingabe des Programms geschrieben:

| Ereignis | Felder |
|---|---|
| `{"e":"klick","id":"k2"}` | Knopf geklickt |
| `{"e":"text","id":"t4","wert":"…"}` | Text im Feld geändert (verzögert um 150 ms) |
| `{"e":"enter","id":"t4","wert":"…"}` | Eingabetaste im Textfeld |
| `{"e":"maus","id":"z3","x":12,"y":34}` | Klick auf eine Zeichenfläche |
| `{"e":"taste","id":"z3","taste":"ArrowUp"}` | Taste auf der fokussierten Fläche (nur nach `beiTaste`) |
| `{"e":"zu"}` | Fenster geschlossen (beendet `zeigen()`) |

Der Browser prüft jeden Frame streng (Typen, Farben, Zahlenbereiche, höchstens 20000 Zeichenbefehle je
Fläche); Programmtexte erscheinen immer als Text, nie als HTML.
