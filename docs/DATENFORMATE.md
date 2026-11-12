# Datenformate

Verbindliche JSON-Formate für Klausur-Einstellungen, Aufgaben-Konfiguration,
Lösungen, Antworten und Ereignisse. Serverseitig prüft jeder Antworttyp seine
Formate in `src/Services/TaskTypes/<Name>Type.php`; im Browser nutzen die
Widgets (`frontend/src/widgets/…`) dieselben Formate.

Grundsätze:

- IDs in Konfigurationen sind kurze Strings (`[A-Za-z0-9_-]{1,40}`), z. B. `"a"`, `"opt3"`.
- Alles, was eine Lösung verrät, steht in `task_solutions.solution` / `.tests` /
  `.model_answer` / `.expectation_md`, **nie** in `tasks.config`.
- Unbekannte Felder werden beim Speichern verworfen.
- Antworten (`answers.content`) sind höchstens 512 KB groß (JSON).

---

## 1. Klausur-Einstellungen (`exams.settings`)

```json
{
  "lock_on_violation": true,
  "violation_grace_ms": 300,
  "paste_policy": "internal",
  "require_seb": false,
  "require_access_code": false,
  "toilet_pauses_clock": false,
  "live_view": true,
  "screen_share": false,
  "allow_self_submit": true,
  "shuffle_choices": true,
  "show_points": true,
  "correction_marks": "auto",
  "correction_marks_show_quotient": false,
  "run_mode": "run",
  "java_version": "21",
  "python_packages": [],
  "editor": {
    "autocomplete": false,
    "lint": false,
    "close_brackets": true,
    "font_size": 15
  },
  "aids": {
    "java_api": false,
    "python_doc": false,
    "sql_cheatsheet": false,
    "operators": true,
    "calculator": false,
    "periodic_table": false
  }
}
```

| Feld | Werte | Bedeutung |
|---|---|---|
| `lock_on_violation` | bool | Verstoß (Vollbild/Fokus verlassen) sperrt bis zur Freigabe |
| `violation_grace_ms` | 0 bis 5000 | Fokusverluste kürzer als das zählen nur als Hinweis |
| `paste_policy` | `internal` / `block` / `allow_log` | Einfügen: nur Klausur-intern / gar nicht / alles, aber protokolliert |
| `require_seb` | bool | nur mit Safe Exam Browser (Config-Key-Prüfung) |
| `require_access_code` | bool | Klausurcode (`exams.access_code`) beim Beitritt |
| `toilet_pauses_clock` | bool | Abwesenheit hält die Uhr der Person an |
| `live_view` | bool (Standard `true`) | Live-Wand: Live-Ansicht der Klausurseite im Pult (Aufgabe, Code-Ausschnitt, Konsole) |
| `screen_share` | bool (Standard `false`) | Live-Wand: Bildschirmfreigabe (ganzer Bildschirm) verlangen; Standbilder im Pult. Im SEB entfällt sie |
| `allow_self_submit` | bool | Knopf „Abgeben“ (Lehrkraft muss bestätigen) |
| `shuffle_choices` | bool | MC-Optionen/Zuordnungen je Person mischen |
| `show_points` | bool | Punkte je Aufgabe in der Schüleransicht anzeigen |
| `correction_marks` | `auto` / `deutsch` / `fremdsprache` / `latein` / `aus` | Korrekturzeichen am Rand von Freitext-Antworten; `auto` nach dem Fach der Klausur (Abschnitt 5). Einstellbar in der Korrektur |
| `correction_marks_show_quotient` | bool (Standard `false`) | Fehlerquotient auch in Rückgabe und PDF zeigen (Abschnitt 5) |
| `run_mode` | `run` / `syntax` / `none` | Standard für Code-Aufgaben |
| `java_version` | `21` / `25` | Standard für Java-Aufgaben |
| `python_packages` | Liste aus `numpy`, `matplotlib` | zusätzlich zu laden |
| `editor.*` | bool / Zahl | Editor-Komfort |
| `aids.*` | bool | Hilfsmittel-Seitenleiste; Materialien mit `is_aid = 1` erscheinen zusätzlich (Audio/Video nie). Startwerte je Fach: `Services\Subjects::defaultAids` (Chemie: Periodensystem und Taschenrechner) |

Modus `practice` (Übung): Überwachung aus, Uhr optional (`duration_minutes` NULL = ohne Limit),
sichtbare Beispieltests mit sofortigem Ergebnis.
Modus `probe` (Technik-Check): keine Aufgaben nötig; prüft Vollbild, Ton, Verbindung, SEB.

Standardwerte liefert `Services\ExamSettings::defaults()`, normalisiert wird mit
`Services\ExamSettings::normalize(array)`.

---

## 2. Aufgaben

`tasks.answer_type` NULL = Container (Überschrift mit Teilaufgaben, eigener Text).
Teilaufgaben haben `parent_id`. Nummerierung wird angezeigt, nicht gespeichert
(1, 2, … für oberste Ebene; a, b, … für Teilaufgaben).

`tasks.body_md`: Markdown. Bilder: `![Beschriftung](material:<exam_materials.id>)`.
Formeln: `$…$`, `$$…$$`. Code: ```` ```java ```` usw.

`tasks.web_sites` (JSON, NULL = keine): Freigaben für den eingebauten Browser, Default deny.
`[ { "url": "https://docs.python.org/3/library/", "label": "Python-Bibliothek", "exact": false } ]`.
Freigegeben ist alles, was mit `url` beginnt (an Pfadgrenzen; http/https und `www.` gleich), mit `exact` nur
genau diese Seite. Teilaufgaben erben die Freigaben ihrer Überschrift. Höchstens 20 je Aufgabe
(`Services\Web\WebSites`).

### Antworttypen (Übersicht)

| answer_type | Stufe | Gruppe | Auto-Bewertung |
|---|---|---|---|
| `code` | 1 | Programmieren | Tests im Runner (Korrektur) |
| `text` | 1 | Text & Auswahl | nein |
| `choice` | 1 | Text & Auswahl | ja |
| `matching` | 1 | Text & Auswahl | ja |
| `ordering` | 1 | Text & Auswahl | ja |
| `gaps` | 1 | Text & Auswahl | ja |
| `drawing` | 3 | Diagramme | nein |
| `uml` | 3 | Diagramme | teilweise |
| `structogram` | 3 | Diagramme | nein |
| `flowchart` | 3 | Diagramme | nein |
| `logic` | 3 | Diagramme | ja (Simulation) |
| `truth_table` | 3 | Diagramme | ja |
| `automaton` | 3 | Diagramme | ja (Testwörter) |
| `er` | 3 | Diagramme | teilweise |
| `relational` | 3 | Diagramme | teilweise |
| `structure` | 3 | Diagramme | teilweise (Summenformel, Ladung, Bindungsgerüst) |
| `robo` | 4 | Mikrowelt | ja (Testwelten im Runner) |

---

### 2.1 `code`

**config**
```json
{
  "language": "java",
  "java_version": null,
  "run_mode": null,
  "main_file": "Main.java",
  "allow_new_files": false,
  "stdin": "",
  "gui": false,
  "sample_tests": [
    { "name": "Beispiel 1", "input": "3\n4\n", "expected_output": "7\n", "compare": "trim" }
  ],
  "sql_setup": "",
  "python_packages": null
}
```
- `language`: `java` | `python` | `sql`
- `java_version`, `run_mode`, `python_packages`: `null` = Klausur-Einstellung
- `main_file`: Startdatei (Java: Klasse mit `main`; Python: Skript; SQL: Abfragedatei)
- `stdin`: vorbelegte Eingabe (sonst interaktiv)
- `gui`: Java mit Klausurwerk-GUI-Bibliothek (Stufe 4)
- `sample_tests`: für Schüler:innen **sichtbare** Beispiele (nur Übung/Probe zeigen sie automatisch an; in Klausuren nur, wenn die Lehrkraft sie in den Aufgabentext übernimmt). `compare`: `exact` | `trim` | `ignore_whitespace` | `contains` | `regex`
- `sql_setup`: SQL-Skript (CREATE + INSERT), das vor jeder Ausführung eine frische SQLite-Datenbank aufbaut

Vorgegebene Dateien stehen in `task_files` (`filename`, `content`, `is_readonly`, `position`).

**content**
```json
{ "files": [ { "name": "Main.java", "content": "public class Main { … }" } ] }
```
Enthält alle **bearbeitbaren** Dateien (Startinhalt = `task_files.content`). Schreibgeschützte
Dateien sind nicht enthalten; neue Dateien nur bei `allow_new_files`. Dateinamen:
`[A-Za-z0-9_.-]{1,80}`, höchstens 20 Dateien.

**solution**: `{}` (Musterlösung steht in `model_answer`, Tests in `tests`).

**tests** (`task_solutions.tests`, nie an Schüler:innen)
```json
[
  { "name": "Addition", "type": "io", "input": "2\n5\n", "expected_output": "7", "compare": "trim", "points": 1 },
  { "name": "Methode summe", "type": "java_main", "code": "public class KwTest { public static void main(String[] a) { if (new Rechner().summe(2,3) != 5) System.exit(1); } }", "points": 2 },
  { "name": "Funktion", "type": "python_assert", "code": "assert summe(2, 3) == 5", "points": 1 },
  { "name": "Abfrage", "type": "sql_result", "expected_query": "SELECT name FROM schueler ORDER BY name", "order_sensitive": true, "points": 2 }
]
```
- `io`: Programm mit `input` starten, Ausgabe mit `expected_output` vergleichen.
- `java_main`: zusätzliche Klasse wird mitkompiliert und gestartet; Exit-Code 0 = bestanden.
- `python_assert`: Code wird nach dem Schülerprogramm im selben Namensraum ausgeführt (`__name__` ist dabei nicht `"__main__"`). Bricht das Laden an einem `input()` im Hauptteil ab (keine Eingabe, `EOFError`), wird mit dem bis dahin definierten Namensraum weitergeprüft; die Testmeldung bekommt einen Hinweis.
- `sql_result`: Ergebnis der Schülerabfrage (letzte Anweisung) wird mit dem Ergebnis von `expected_query` auf frischer Datenbank verglichen.

### 2.2 `text`
- config: `{ "min_words": null, "max_chars": 20000, "placeholder": "", "lang": "", "chars": "" }`
  - `lang`: Sprache der Antwort (`de`, `en`, `fr`, `es`, `it`, `pl`, `ru`, `la`, `grc`), `''` = wie das Fach der Klausur.
  - `chars`: Zeichenleiste (`none`, `de`, `en`, `fr`, `es`, `it`, `pl`, `la`, `chem`, `math`), `''` = wie das Fach.
    `chem` und `math` bieten Hoch-/Tiefstellen: Ziffern und Vorzeichen werden als Unicode-Zeichen getippt
    (H₂O, Fe³⁺), die Antwort bleibt Klartext.
- content: `{ "text": "…" }` (Klartext; Absätze mit Leerzeile)
- solution: `{}`

### 2.3 `choice`
- config: `{ "multiple": false, "options": [ { "id": "a", "text_md": "…" } ], "shuffle": true }`
- content: `{ "selected": ["a"] }`
- solution: `{ "correct": ["a"], "scoring": "all_or_nothing" }` (`all_or_nothing` | `partial`: richtig gewählte minus falsch gewählte, nicht unter 0)
- Schüleransicht bekommt Optionen ggf. gemischt (Seed = `participations.shuffle_seed` + Aufgaben-ID) und mit gerendertem `text_html`.

### 2.4 `matching`
- config: `{ "left": [ { "id": "l1", "text_md": "…" } ], "right": [ { "id": "r1", "text_md": "…" } ], "shuffle": true }`
- content: `{ "pairs": { "l1": "r2" } }`
- solution: `{ "pairs": { "l1": "r1" } }`; Bewertung anteilig je richtiger Zuordnung.

### 2.5 `ordering`
- config: `{ "items": [ { "id": "s1", "text_md": "…" } ] }` (Schüleransicht immer gemischt)
- content: `{ "order": ["s3", "s1", "s2"] }`
- solution: `{ "order": ["s1", "s2", "s3"], "scoring": "all_or_nothing" }` (`partial` = Anteil richtiger Positionen)

### 2.6 `gaps`
- config:
  ```json
  { "mode": "code", "language": "java",
    "template": "for (int i = 0; i {{g1}} 10; i++) {\n    summe {{g2}} i;\n}",
    "gaps": [ { "id": "g1", "width": 4, "options": null }, { "id": "g2", "width": 3, "options": ["+=", "-=", "="] } ] }
  ```
  Bei `mode: "text"` zusätzlich `lang` und `chars` wie bei `text` (2.2). Beim Vergleich gelten
  chemische Schreibweisen als gleich: Hoch-/Tiefzahlen wie Ziffern (H₂O = H2O, Fe³⁺ = Fe3+),
  `→` = `->`, `⇌` = `<=>`, `−` = `-` (`GapsType::normalizeNotation`).
  `mode`: `text` | `code`. `{{id}}` markiert eine Lücke. `options` ≠ null = Auswahlliste.
- content: `{ "values": { "g1": "<", "g2": "+=" } }`
- solution: `{ "gaps": { "g1": { "accepted": ["<", "<= 9"], "case_sensitive": true, "trim": true, "regex": null } } }`

### 2.7 bis 2.15: Diagramme (Stufe 3)

Koordinaten in Pixeln innerhalb der Zeichenfläche. Jedes Element hat eine `id`.
Zusätzlich speichert das Widget eine PNG-Vorschau (`answers.preview_png`, Data-URL,
höchstens 2 MB) für Korrektur und PDF.

- **`drawing`**: config `{ "width": 900, "height": 600, "background_material_id": null, "allow_upload": false }`;
  content `{ "strokes": [ { "color": "#000", "width": 2, "points": [[x,y], …] } ], "texts": [ { "id", "x", "y", "text" } ], "upload_ids": [] }`
- **`uml`**: config `{ "diagram": "class", "allow_interfaces": true }`;
  content `{ "classes": [ { "id", "name", "kind": "class|abstract|interface|enum", "x", "y", "attributes": [ { "visibility": "-|+|#|~", "name", "type", "static": false } ], "methods": [ { "visibility", "name", "params": "int a, int b", "return_type", "static": false, "abstract": false } ] } ], "relations": [ { "id", "from", "to", "kind": "association|directed|aggregation|composition|inheritance|realization|dependency", "label": "", "mult_from": "", "mult_to": "" } ] }`;
  solution: gleiche Struktur (Musterdiagramm) für den Vergleich.
- **`structogram`**: content `{ "root": { "type": "sequence", "children": [ … ] } }` mit Blöcken
  `statement {text}`, `call {text}`, `if {cond, then: sequence, else: sequence}`,
  `switch {expr, cases: [{label, body: sequence}]}`, `while {cond, body}`, `do_while {cond, body}`,
  `for {head, body}`, `break {text}`. Jeder Block hat `id`.
- **`flowchart`**: content `{ "nodes": [ { "id", "type": "start|end|process|decision|io|subroutine|connector", "text", "x", "y" } ], "edges": [ { "id", "from", "to", "label": "" } ] }`
- **`logic`**: config `{ "inputs": ["A","B"], "outputs": ["X"], "allowed_gates": ["AND","OR","NOT","NAND","NOR","XOR","XNOR"] }`;
  content `{ "components": [ { "id", "type": "INPUT|OUTPUT|AND|OR|NOT|NAND|NOR|XOR|XNOR", "label", "x", "y" } ], "wires": [ { "id", "from": { "component", "pin": "out" }, "to": { "component", "pin": "in0" } } ] }`;
  solution `{ "truth_table": { "00": { "X": 0 }, "01": { "X": 1 }, … } }` (Schlüssel = Eingangsbits in Reihenfolge von `inputs`).
- **`truth_table`**: config `{ "inputs": ["A","B"], "outputs": ["X"], "prefilled": {} }`;
  content `{ "rows": { "00": { "X": "0" } } }`; solution `{ "rows": { "00": { "X": "0" } } }`.
- **`automaton`**: config `{ "kind": "dfa|nfa", "alphabet": ["a","b"] }`;
  content `{ "states": [ { "id", "name", "x", "y", "initial": false, "accepting": false } ], "transitions": [ { "id", "from", "to", "symbols": ["a"] } ] }`;
  solution `{ "accept": ["ab", "aab"], "reject": ["", "b"] }`.
- **`er`**: config `{ "notation": "chen" }`;
  content `{ "entities": [ { "id", "name", "x", "y", "weak": false } ], "attributes": [ { "id", "owner", "name", "key": false, "x", "y" } ], "relationships": [ { "id", "name", "x", "y", "ends": [ { "entity", "cardinality": "1|n|m|0..1|0..n|1..n" } ] } ] }`
- **`relational`**: content `{ "tables": [ { "id", "name", "columns": [ { "name", "pk": false, "fk": null } ] } ] }` (`fk`: `"Tabelle.spalte"` oder null)

### 2.15a `structure` (Strukturformel, Chemie)

Lewis- und Strukturformeln. Atome sind Knoten, Bindungen verbinden zwei Atome; dazu freie Beschriftungen
und Reaktionspfeile, damit eine Reaktion mehrerer Moleküle in einer Zeichnung Platz hat.

- config: `{ "score_pairs": false, "start": null }`. `score_pairs`: Elektronenpaare, Radikale und Atomladungen
  in der Vorbewertung berücksichtigen. `start`: optionale Vorgabe-Startzeichnung (gleiche Struktur wie `content`),
  gilt nur, solange die Schüler:in noch nichts gespeichert hat.
- content: `{ "atoms": [ { "id", "el", "x", "y", "charge": 0, "pairs": [], "radicals": 0 } ], "bonds": [ { "id", "a", "b", "order": 1, "stereo": "none" } ], "texts": [ { "id", "x", "y", "text" } ], "arrows": [ { "id", "x", "y", "len": 100, "kind": "forward" } ] }`
  - `atoms[].el`: gültiges Elementsymbol (Ordnungszahl 1 bis 118, Schreibweise `Xy`); sonst wird das Atom verworfen.
    `charge`: -2 bis 3. `pairs`: freie Elektronenpaare als Striche, Liste verschiedener Seiten aus `top|right|bottom|left` (also 0 bis 4).
    `radicals`: einzelne Elektronen, 0 bis 4. `x`/`y` sind Mittelpunkte.
  - `bonds[]`: `a`/`b` verweisen auf Atome (verschieden, je Atompaar höchstens eine Bindung). `order`: 1 bis 3.
    `stereo`: `none|wedge|dash` (nur bei `order` 1, Keil oder gestrichelt in Richtung von `a` nach `b`, schmales Ende bei `a`).
  - `texts[].text`: einzeilig, höchstens 80 Zeichen. `arrows[]`: waagerecht, `len` 40 bis 400, `kind`: `forward` (→) oder `equilibrium` (⇌).
  - Höchstzahlen: 150 Atome, 200 Bindungen, 30 Beschriftungen, 20 Pfeile; Koordinaten -3000 bis 8000. Fremdwerte werden verworfen.
  - Wasserstoffatome werden gezeichnet (keine impliziten H); die Summenformel zählt nur gezeichnete Atome.
- solution: gleiche Struktur wie `content` (Musterzeichnung). Ohne Atome gibt es keine Vorbewertung.
- Anzeige: Summenformel je zusammenhängendem Molekül in Hill-Reihenfolge (mit C zuerst C, H, dann alphabetisch; sonst
  alphabetisch) mit Unicode-Tiefzahlen, z. B. `C₂H₆O`, dazu die Gesamtladung. Berechnet im Browser und in PHP (`StructureComparer`).
- Vorbewertung (`StructureComparer`): Moleküle werden einander zugeordnet (Gerüst, Summenformel, Ladung, Elementüberlappung).
  Je Molekül ein Eintrag für Summenformel, Gesamtladung (nur wenn die Musterlösung geladen ist) und Bindungsgerüst
  (Graph-Isomorphie: Element = Knotenfarbe, Bindungsordnung = Kantenfarbe, Stereo wird nicht verglichen), mit `score_pairs`
  zusätzlich Atomladung, Radikale und Anzahl der Elektronenpaare (nicht deren Seite). Reaktionspfeile zählen nach Art.
  Die Hinweise stehen in den Beschriftungen der fehlenden Einträge, z. B. "Bindungsgerüst von C₂H₆O: Summenformel stimmt, aber Bindung C=O fehlt".
  Überzählige Moleküle und Pfeile stehen in `extra`. Beschriftungen werden nicht verglichen.

### 2.16 `robo` (Stufe 4)
- config:
  ```json
  { "language": "python", "java_version": null,
    "world": { "cols": 10, "rows": 8, "robo": { "x": 0, "y": 0, "dir": "E" },
               "walls": [[3,2]], "boxes": [[5,5]], "screws": [ { "x": 4, "y": 0, "count": 1 } ],
               "targets": [[9,7]] },
    "goal_md": "Sammle alle Schrauben ein." }
  ```
- content wie `code`: `{ "files": [ { "name": "robo.py", "content": "…" } ] }`
- tests: `[ { "name": "Welt 2", "type": "robo_world", "world": { … }, "goal": { "screws_collected": "all", "robo_at": [9,7] }, "points": 2 } ]`

---

## 3. Antwort-Änderungen für das Replay (`answer_ops`)

Jedes gespeicherte Paket enthält neben dem vollständigen Inhalt eine Liste von
Änderungsschritten. `seq` zählt je Teilnahme lückenlos hoch (Client-Zähler, beginnt
bei 1, überlebt Neuladen über IndexedDB).

| kind | payload |
|---|---|
| `ops` | `{ "file": "Main.java", "changes": <CodeMirror ChangeSet.toJSON()> }` |
| `snapshot` | `{ "file": "Main.java", "text": "…" }` (spätestens alle 50 Schritte und beim Laden) |
| `value` | vollständiger Inhalt (strukturierte Typen: MC, Diagramme, …) |

`is_paste`: Schritt kam aus Einfügen (nur Klausur-intern erlaubt, sonst blockiert).
`is_overtime`: Server setzt das, wenn der Schritt nach dem Zeitende der Person eintraf.

---

## 4. Ereignisse (`participation_events.type`)

| type | severity | Auslöser |
|---|---|---|
| `joined` | info | Beitritt in den Warteraum |
| `ready` | info | „Bereit“ geklickt (Ton/Vollbild freigeschaltet) |
| `fullscreen_exit` | alarm | Vollbild verlassen |
| `visibility_hidden` | alarm | Tab/Fenster verborgen (anderer Tab, minimiert) |
| `window_blur` | alarm (≥ Schwelle) / info | Fokus verloren (Alt-Tab) |
| `paste_blocked` | warning | Einfügen von außen blockiert (`details.length`) |
| `paste_external` | warning | Einfügen von außen erlaubt und protokolliert (`paste_policy = allow_log`) |
| `drop_blocked` | warning | Drag & Drop von außen blockiert |
| `second_tab` | alarm | Klausur in zweitem Tab geöffnet |
| `devtools_suspected` | warning | Heuristik für Entwicklerwerkzeuge |
| `resize` | info | Fenstergröße geändert (`details.w`, `details.h`) |
| `reload` | warning | Seite neu geladen |
| `connection_lost` | warning | Server erkennt fehlenden Herzschlag (> 10 s) |
| `connection_restored` | info | Herzschlag wieder da |
| `seb_missing` | alarm | Klausur verlangt SEB, Anfrage ohne gültigen Config-Key |
| `time_up` | info | Zeit der Person abgelaufen (Signal) |
| `state_changed` | info | jeder Zustandswechsel: `details.from`, `details.to`, `details.reason` |
| `announcement` | info | Durchsage gesendet |
| `hand_raised` / `hand_lowered` | info | Hand heben / quittiert |
| `device_change_requested` | warning | Anmeldung an anderem Gerät |
| `time_added` | info | Zeit verlängert (`details.minutes`) |
| `screen_share_started` | info | Bildschirmfreigabe läuft (`details.surface`: `monitor`/`window`/`browser`/`unknown`) |
| `screen_share_stopped` | alarm | Freigabe beendet; keine Sperre |
| `screen_share_denied` | alarm | Freigabe verweigert (`details.reason`); keine Sperre |
| `screen_share_wrong_surface` | alarm | nur Fenster oder Tab statt des ganzen Bildschirms (`details.surface`); keine Sperre |
| `web_blocked` | warning | eingebauter Browser: nicht freigegebene Adresse aufgerufen (`details.url`, `details.task_id`); vom Server geschrieben, höchstens einmal je Minute und Adresse; kein Verstoß |

Alarm-Ereignisse lösen im Pult Ton und Meldung aus. Die vier `screen_share_*`-Ereignisse kommen
über `POST /api/klausur/{id}/ereignis` wie `window_blur`, sind aber nie ein Verstoß (`EventLog::VIOLATIONS`):
die Person arbeitet weiter.

### 4.1 Live-Wand: flüchtige Daten (kein Datenbankformat)

Live-Zustand und Standbilder liegen nur im Arbeitsspeicher (`Services\LiveStore`, tmpfs `LIVE_DIR`,
Standard `/run/klausurwerk-live`), je Teilnahme nur der letzte Stand, nie in der Datenbank:

```
<LIVE_DIR>/e<Klausur-ID>/p<Teilnahme-ID>.live.json    letzter Live-Zustand (+ received_ms)
<LIVE_DIR>/e<Klausur-ID>/p<Teilnahme-ID>.screen.jpg   letztes Standbild
<LIVE_DIR>/e<Klausur-ID>/p<Teilnahme-ID>.screen.json  {ts_ms, width, height, surface}
<LIVE_DIR>/e<Klausur-ID>/focus.json                   {participation_id, until_ms}
```
Einträge älter als 120 s gelten als veraltet. Beim Ende der Klausur (`finished`), beim Löschen und
beim Archivieren wird das Klausurverzeichnis gelöscht.

---

## 5. Korrektur-Anmerkungen (`grading_comments.anchor`)

```json
{ "kind": "code", "file": "Main.java", "from_line": 12, "to_line": 14 }
{ "kind": "text", "from": 120, "to": 168, "quote": "…" }
{ "kind": "text", "from": 120, "to": 128, "quote": "ging", "mark": "Gr" }
{ "kind": "task" }
```

`from` und `to` zählen in UTF-16-Einheiten des Antworttextes (wie `String.length` in JavaScript).

**Korrekturzeichen (`mark`, optional, nur bei `kind: "text"` an Aufgaben vom Typ `text`).** Das Kürzel muss zum aktiven
Zeichensatz der Klausur gehören (sonst 422) und passt auf `^[A-Za-z!]{1,5}$`. Bei Kommentaren mit `mark` darf `body` leer
sein (ein reines Zeichen am Rand), sonst nicht. Kommentare ohne `mark` bleiben unverändert.

Zeichensätze (`src/Services/CorrectionMarks.php`), je Zeichen Kürzel, Name, zählt als Fehler, Gewicht:

| Satz | Zeichen (Gewicht 1, wenn nicht genannt) |
|---|---|
| `deutsch` | R Rechtschreibung, Gr Grammatik, Z Zeichensetzung, A Ausdruck, W Wortwahl, Sb Satzbau, T Tempus, Bz Bezug, St Stil, Wdh Wiederholung |
| `fremdsprache` | sp spelling, gr grammar, voc vocabulary, wo word order, t tense, prep preposition, st style, ex expression, ! omission (Auslassung) |
| `latein` | Vok Vokabel, Gr Grammatik/Form, K Konstruktion, S Sinnfehler (Gewicht 2), Ausl Auslassung |
| `aus` | keine |

Der Satz kommt aus der Klausur-Einstellung `correction_marks` (Abschnitt 1): `auto` (Standard) wählt nach dem Fach
(`setForSubject`: Deutsch, Fremdsprachen wie Englisch/Französisch/Spanisch/Italienisch/Russisch, Latein/Altgriechisch, sonst
`aus`), oder ein fester Satz. `correction_marks_show_quotient` (Standard `false`) schaltet den Fehlerquotienten in Rückgabe
und PDF frei. Beide Werte gehören nicht zu den Profilen und nicht zu "Erweitert"; die Einstellungsseite lässt sie unverändert.

Zählung: je Kategorie die Anzahl; gewichtete Fehler = Summe der Gewichte aller Zeichen, die als Fehler zählen; Wörter =
durch Leerraum getrennte Stücke des Antworttextes; **Fehlerquotient = gewichtete Fehler / Wörter × 100**, eine
Nachkommastelle (ohne Wörter: keiner). Über mehrere Textaufgaben rechnet man aus den Summen, nicht aus dem Mittel der
Quotienten. Zeichen außerhalb des aktiven Satzes zählen nicht. Der Quotient ist ein Hinweis und setzt keine Punkte.

## 6. Automatische Vorbewertung (`auto_gradings.details`)

```json
{ "source": "choice", "ratio": 0.5, "items": [ { "id": "a", "ok": true } ] }
{ "source": "tests", "passed": 3, "total": 4,
  "results": [ { "name": "Addition", "ok": true, "points": 1, "earned": 1, "message": "", "stdout": "…", "stderr": "" } ] }
```
`suggested_points` = Punkte der Aufgabe × ratio (Tests: Summe `earned`, gedeckelt auf Aufgabenpunkte).

## 7. Ergänzungen aus Stufe 3 und 4

- `drawing.texts[].color`; `uml.relations[].route` (`orthogonal` | `straight`), Semantik: `from` = Unterklasse bzw. das Ganze, `to` = Zielklasse.
- `logic.config.symbols` (`din` | `ansi`), `config.show_table`; Komponenten haben optional `n` (2 bis 4 Eingänge); `solution.circuit` (Musterschaltung, optional).
- `automaton.config.allow_simulation`, `config.allow_epsilon`; `solution.automaton` (Musterautomat, optional).
- `er`: `x`/`y` sind Mittelpunkte. Leere Musterlösungen sind bei allen Diagrammtypen erlaubt (dann nur manuelle Bewertung).
- `robo`: `config.start_code`; `main_file` darf nicht `robo.py`/`Robo.java` heißen (Standard `main.py`); Testwelten in `task_solutions.tests`
  (Ziel zusätzlich `robo_on_target`, `boxes_on_targets`). Antwort des Runners: `ok`, `trace`, `final_world`, `message`, `goal_results`, `steps`, `stdout`, `stderr`.
- Vorbewertung (`auto_gradings.details.source`) zusätzlich `empty` (keine Antwort) und `error` (Prüfung fehlgeschlagen, wiederholbar).
