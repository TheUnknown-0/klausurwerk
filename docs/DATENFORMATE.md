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
    "calculator": false
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
| `run_mode` | `run` / `syntax` / `none` | Standard für Code-Aufgaben |
| `java_version` | `21` / `25` | Standard für Java-Aufgaben |
| `python_packages` | Liste aus `numpy`, `matplotlib` | zusätzlich zu laden |
| `editor.*` | bool / Zahl | Editor-Komfort |
| `aids.*` | bool | Hilfsmittel-Seitenleiste; Materialien mit `is_aid = 1` erscheinen zusätzlich |

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
- config: `{ "min_words": null, "max_chars": 20000, "placeholder": "" }`
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
{ "kind": "task" }
```

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
