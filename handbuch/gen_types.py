"""Erzeugt manual/04-typen.html (Abschnitt 4.6, alle 16 Antworttypen, Screenshots Editor und Schüleransicht)."""
UI = '<span class="ui">{}</span>'
T = [
 ("choice", "Multiple Choice", "Text und Auswahl",
  "Der Schüler kreuzt eine oder mehrere Antworten an. Die Optionen können Markdown mit Code und Formeln enthalten und je Person gemischt werden.",
  "Aufgabentext, dann pro Option ein Textfeld mit Häkchen „richtig“; mit den Pfeil-Symbolen ordnen Sie die Optionen, mit dem Kreuz-Symbol entfernen Sie eine. Schalter <b>Mehrere Antworten möglich</b> und <b>Optionen je Person mischen</b>. Bewertungsart: alles oder nichts, oder anteilig (richtig Gewählte minus falsch Gewählte, nicht unter 0).",
  "Ja, vollständig. Vorschlag = Aufgabenpunkte mal Anteil richtig, gerundet auf 0,5."),
 ("gaps", "Lückentext", "Text und Auswahl",
  "Ein Text oder Code mit Lücken. Jede Lücke ist ein kurzes Eingabefeld oder eine Auswahlliste.",
  "<b>Art</b> „Lückentext“ oder „Lückencode“ (mit Sprache für die Hervorhebung). Im Feld <b>Text mit Lücken</b> markiert <span class=\"ui\">Lücke einfügen</span> eine Lücke als <code>{{g1}}</code>. Je Lücke: <b>Richtige Antworten</b> (eine je Zeile, mehrere gelten), optional <b>Auswahlliste</b>, Groß-/Kleinschreibung und Leerzeichen-Regeln.",
  "Ja. Jede Lücke zählt anteilig; akzeptiert werden alle eingetragenen richtigen Antworten."),
 ("text", "Freitext", "Text und Auswahl",
  "Ein Textfeld für Erläuterungen und Begründungen, mit Platzhaltertext.",
  "Optionale Werte für Mindestwörter, höchste Zeichenzahl (Standard 20000) und Platzhalter. Der <b>Erwartungshorizont</b> ist für die Korrektur wichtig.",
  "Nein, immer manuell. Die Korrektur zeigt Erwartungshorizont und Musterlösung daneben."),
 ("matching", "Zuordnung", "Text und Auswahl",
  "Jedem Begriff links wird aus einer Liste die passende Aussage rechts zugeordnet, oder die Aussage wird auf den Begriff gezogen. Begriffe und Aussagen dürfen verschieden viele sein (Ablenker).",
  "Zwei Spalten <b>Links</b> und <b>Rechts</b> mit Hinzufügen- und Löschknöpfen, darunter die <b>Richtige Zuordnung</b> als Auswahllisten. Mischen je Person ist möglich.",
  "Ja. Anteilig je richtiger Zuordnung."),
 ("ordering", "Reihenfolge", "Text und Auswahl",
  "Die Einträge erscheinen gemischt und werden mit den Pfeiltasten oder per Ziehen in die richtige Reihenfolge gebracht.",
  "Liste der Einträge in der <i>richtigen</i> Reihenfolge; die Schüleransicht mischt immer. Bewertung: alles oder nichts, oder anteilig (Anteil richtiger Positionen).",
  "Ja."),
 ("code-python", "Programmcode (Python)", "Programmieren",
  "Ein Editor mit Syntaxhervorhebung und Dateireitern, darunter die Konsole, dazu die Knöpfe <span class=\"ui\">Ausführen</span>, <span class=\"ui\">Stopp</span> und <span class=\"ui\">Konsole leeren</span>. Python läuft im Browser; <code>input()</code> wartet auf eine Eingabe in der Konsole.",
  "<b>Sprache</b>, <b>Startdatei</b>, <b>Ausführung</b> (wie Klausur, Programme ausführen, nur Syntax prüfen, nicht ausführen), <b>Vorbelegte Eingabe (stdin)</b>, <b>Vorgegebene Dateien</b> (je Datei schreibgeschützt oder bearbeitbar, mit Startinhalt), neue Dateien erlauben. Darunter <b>Beispieltests</b> (für Schüler sichtbar), <b>Versteckte Tests</b> (nur für die Korrektur) und die <b>Musterlösung</b>.",
  "Ja, durch Tests im Runner (Korrektur). Testtypen: <code>io</code> (Eingabe und erwartete Ausgabe, Vergleich exakt, getrimmt, ohne Leerraum, enthält oder Regex) und <code>python_assert</code> (Funktionsaufrufe prüfen)."),
 ("code-java", "Programmcode (Java)", "Programmieren",
  "Wie Python, aber mit mehreren Klassen in Reitern. Vorgegebene schreibgeschützte Dateien sind mit einem Schloss markiert. Beim Ausführen wird auf dem Server kompiliert; Fehler erscheinen auf Deutsch mit Zeilennummer.",
  "Zusätzlich die <b>Java-Version</b> (wie Klausur, 21 oder 25) und der Haken <b>Programm hat eine grafische Oberfläche (Klausurwerk-GUI-Bibliothek)</b>, der im Schülerfenster den Reiter „Fenster“ einblendet. Tests: <code>io</code> und <code>java_main</code> (eine zusätzliche Testklasse, Exit-Code 0 = bestanden).",
  "Ja, im Runner."),
 ("code-sql", "Programmcode (SQL)", "Programmieren",
  "Abfragen schreiben und ausführen. Das Ergebnis erscheint als Tabelle auf dem Reiter „Ergebnis“; <span class=\"ui\">Datenbank anzeigen</span> zeigt das Schema. Jeder Schüler arbeitet auf einer eigenen Kopie der Datenbank (SQLite).",
  "<b>SQL-Aufbauskript</b> (CREATE und INSERT, läuft vor jeder Ausführung auf einer frischen Datenbank). Tests vom Typ <code>sql_result</code>: Das Ergebnis der letzten Anweisung wird mit dem einer Musterabfrage verglichen, wahlweise mit beachteter Reihenfolge.",
  "Ja, durch Vergleich der Ergebnisse."),
 ("drawing", "Zeichnung (Freihand)", "Diagramme",
  "Eine Zeichenfläche mit Stift, Radierer, Textwerkzeug, Verschieben, Farben und Strichstärke, Rückgängig und Wiederholen. Funktioniert mit Maus, Stift und Finger.",
  "Breite und Höhe der Fläche (200 bis 3000 Pixel), optional ein <b>Hintergrundbild aus dem Klausurmaterial</b> und der Haken, dass Schüler zusätzlich eine Datei hochladen dürfen (PNG, JPEG, PDF, höchstens 10 MB).",
  "Nein, manuell. Die Korrektur zeigt ein Bild der Zeichnung."),
 ("uml", "UML-Klassendiagramm", "Diagramme",
  "Werkzeugleiste mit Klasse, Abstrakt, Interface und Enum sowie den Beziehungen Assoziation, gerichtete Assoziation, Aggregation, Komposition, Vererbung, Realisierung und Abhängigkeit. Klassen werden auf der Fläche platziert; im Eigenschaften-Fenster rechts bearbeitet man Name, Attribute und Methoden mit Sichtbarkeit und Typ.",
  "Haken <b>Interfaces erlauben</b>. Als <b>Musterlösung</b> zeichnen Sie das Diagramm im selben Editor.",
  "Teilweise: Klausurwerk vergleicht Klassen, Attribute, Methoden und Beziehungen mit der Musterlösung und schlägt Punkte vor. Eine leere Musterlösung heißt rein manuelle Bewertung."),
 ("structogram", "Struktogramm", "Diagramme",
  "Struktogramme werden aus Blöcken zusammengesetzt: Anweisung, Aufruf, Verzweigung, Mehrfachauswahl, Schleifen mit Kopf- und Fußprüfung, Zählschleife und Abbruch. Blöcke werden eingefügt, verschachtelt und im Text bearbeitet.",
  "Außer Text und Punkten keine Einstellungen; eine Musterlösung kann gezeichnet werden.",
  "Nein, manuell (Musterlösung daneben)."),
 ("flowchart", "Programmablaufplan", "Diagramme",
  "Die Symbole Start, Ende, Prozess, Entscheidung, Ein-/Ausgabe, Unterprogramm und Verbinder werden platziert und mit beschrifteten Pfeilen verbunden.",
  "Außer Text und Punkten keine Einstellungen; Musterlösung optional.",
  "Nein, manuell."),
 ("logic", "Schaltnetz (Logikgatter)", "Diagramme",
  "Eingänge, Ausgänge und Gatter (UND, ODER, NICHT, NAND, NOR, XOR, XNOR) werden platziert und mit Leitungen verbunden. Optional sieht der Schüler die Wahrheitstabelle seiner Schaltung.",
  "<b>Eingänge</b> und <b>Ausgänge</b> (durch Komma getrennt, höchstens 6 bzw. 4), <b>Erlaubte Gatter</b>, <b>Symbole</b> (DIN EN 60617 oder ANSI) und der Haken zur Anzeige der Wahrheitstabelle. Als Lösung tragen Sie die <b>Musterlösung: Wahrheitstabelle</b> ein (Klick auf eine Zelle wechselt 0 und 1).",
  "Ja, durch Simulation: Die Schaltung wird für alle Eingangskombinationen gerechnet und mit der Tabelle verglichen."),
 ("truth_table", "Wahrheitstabelle", "Diagramme",
  "Eine Tabelle mit allen Eingangskombinationen, deren Ausgangsspalten per Klick (leer, 0, 1) ausgefüllt werden.",
  "<b>Eingänge</b> und <b>Ausgänge</b>. Klick-Modus: „Lösungswert setzen“ oder „Zelle als vorgegeben markieren“ (diese Zellen sind für Schüler schon ausgefüllt).",
  "Ja, zellenweise."),
 ("automaton", "Endlicher Automat", "Diagramme",
  "Zustände werden platziert, als Anfangs- und akzeptierender Zustand markiert und durch beschriftete Übergänge verbunden. Optional dürfen Schüler Wörter schrittweise ausprobieren.",
  "<b>Art des Automaten</b> (DEA oder NEA), <b>Alphabet</b> (durch Komma getrennt), Haken für die Simulation und die <b>Musterlösung: Testwörter</b> in zwei Listen („Werden akzeptiert“, „Werden verworfen“, ein Wort je Zeile, das leere Wort als ε). Optional ein Musterautomat.",
  "Ja: Der gezeichnete Automat wird auf die Testwörter angewendet."),
 ("er", "ER-Diagramm (Chen)", "Diagramme",
  "Entitäten (auch schwache), Attribute (mit Schlüsselmarkierung) und Beziehungen mit Kardinalitäten (1, n, m, 0..1, 0..n, 1..n) werden platziert und verbunden.",
  "Außer Text und Punkten keine Einstellungen; Musterlösung optional.",
  "Teilweise (Strukturvergleich mit der Musterlösung), sonst manuell."),
 ("relational", "Relationenschema", "Diagramme",
  "Tabellen mit Spalten, Primärschlüssel-Markierung und Fremdschlüssel-Verweisen (<code>Tabelle.spalte</code>).",
  "Außer Text und Punkten keine Einstellungen; Musterlösung optional.",
  "Teilweise (Vergleich mit der Musterlösung), sonst manuell."),
 ("robo", "Robo (Gitterwelt)", "Mikrowelt",
  "Die Welt mit Robo und der Editor. Mit <span class=\"ui\">Ausführen</span> läuft das Programm, und Robo bewegt sich animiert (Tempo, Pause, Einzelschritt, Zurück zum Start). Details in 7.6.",
  "<b>Sprache</b> (Python oder Java), <b>Ziel für die Schüler</b> (Klartext), <b>Welt der Aufgabe</b> per Klick gemalt (Werkzeuge Robo, Wand, Kiste, Schraube mit Anzahl, Ziel, Radierer; Größe 2 bis 30) und <b>Startcode</b>. Dazu Testwelten mit eigener Welt, Punkten und Bedingungen.",
  "Ja: Die Testwelten laufen im Runner. Bedingungen: alle oder mindestens n Schrauben im Korb, Robo auf Feld oder Ziel, Blickrichtung, Kisten auf Feldern oder Zielen."),
]

FILLED = {"uml", "structogram", "flowchart", "logic", "truth_table", "automaton", "er", "relational", "drawing"}
out = ['<h2 id="k4-6"><span class="num">4.6</span>Die 16 Antworttypen</h2>',
       '<p>Jede Teilaufgabe hat genau einen <b>Antworttyp</b>. Eine Aufgabe ohne Antworttyp („Überschrift ohne Antwort (Container)“) fasst nur Teilaufgaben zusammen. Zu jedem Typ sehen Sie links den Editor der Lehrkraft (Ausschnitt ab dem Feld Antworttyp) und rechts die Ansicht des Schülers in der Vorschau. Die Typen Programmcode in Python, Java und SQL zählen als ein Antworttyp (<code>code</code>) mit drei Sprachen, die Typen Auswahl bis Lücken gehören zur Gruppe „Text und Auswahl“.</p>',
       '<table><thead><tr><th>Typ</th><th>Gruppe</th><th>Automatische Bewertung</th></tr></thead><tbody>']
for k, name, grp, stu, ed, auto in T:
    out.append(f'<tr><td><b>{name}</b></td><td>{grp}</td><td>{auto}</td></tr>')
out.append('</tbody></table>')
for i, (k, name, grp, stu, ed, auto) in enumerate(T, 1):
    out.append(f'<h3><span class="num">4.6.{i}</span>{name}</h3>')
    right = {"robo": "x-544-robo-lauf"}.get(k, f"x-550-schueler-{k}" if k in FILLED else f"x-310-vorschau-{k}")
    cap = "Ansicht des Schülers (mit Beispielantwort)" if right.startswith(("x-550", "x-544")) else "Ansicht des Schülers (Vorschau)"
    out.append(f'<div class="figrow"><figure><img src="img/f-300-editor-{k}-2.jpg" alt="Editor {name}"><figcaption><b>{{fig}}</b> Editor der Lehrkraft</figcaption></figure>'
               f'<figure><img src="img/{right}.jpg" alt="Schüleransicht {name}"><figcaption><b>{{fig}}</b> {cap}</figcaption></figure></div>')
    out.append(f'<p><b>Schüler:</b> {stu}</p><p><b>Lehrkraft:</b> {ed}</p><p><b>Automatisch bewertet:</b> {auto}</p>')
open("manual/04-typen.html", "w", encoding="utf-8").write("\n".join(out))
print(len("\n".join(out)))
