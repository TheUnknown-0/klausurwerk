# Korrektur, Ergebnisse und digitale Rückgabe (Stufe 2)

Dieses Dokument beschreibt das Modul aus drei Blickwinkeln (Lehrkraft, Schüler:in, Admin) und
enthält die technische Referenz (Routen, JSON, Datenmodell, Sicherheit, Tests). Der Text für die
Gesamtanleitung des Programms kann Abschnitte 1 bis 4 übernehmen.

## 1. Überblick

```
Beendet ──Korrektur beginnen──▶ In Korrektur ──Ergebnisse freigeben──▶ Zurückgegeben ──▶ Archiviert
                                      ▲                                      │
                                      └────────── Freigabe zurücknehmen ─────┘
```

| Schritt | Wer | Wo |
|---|---|---|
| Korrektur beginnen | Hauptlehrkraft, Admin, Lehrkraft mit „Korrigieren“ | Klausur, Korrektur, Knopf „Korrektur beginnen“ |
| Automatisch vorbewerten | wie oben | Korrektur-Übersicht |
| Punkte und Kommentare | wie oben | Einzelansicht der Korrektur |
| Rückmeldung und Abschluss je Person | wie oben | Ergebnisse |
| Freigeben, Zurücknehmen, Archivieren | nur Hauptlehrkraft und Admin | Ergebnisse |
| Ergebnis ansehen | Schüler:in, nur nach Freigabe und eigenem Abschluss | Meine Ergebnisse |

Bewertet werden kann **nur in der Phase „In Korrektur“**. Davor ist die Korrektur gesperrt, danach
(zurückgegeben, archiviert) ist sie nur lesbar. Aufsichten ohne den Schalter „Korrigieren“ haben
keinen Zugang zu Korrektur, Ergebnissen und PDF.

## 2. Anleitung für Lehrkräfte

### 2.1 Korrektur beginnen

1. Die Klausur ist im Pult beendet (alle Abgaben bestätigt). Auf der Klausurseite erscheint „Korrektur“.
2. Auf der Korrektur-Übersicht „Korrektur beginnen“ wählen. Die Klausur wechselt in „In Korrektur“.

### 2.2 Automatische Vorbewertung

Der Knopf „Automatisch vorbewerten“ prüft alle Personen und alle automatisch prüfbaren Aufgaben:

- **Auswahl, Zuordnung, Reihenfolge, Lücken:** sofort, nach dem Prüfschema des Antworttyps.
  Vorschlag = Aufgabenpunkte mal Anteil richtig, gerundet auf 0,5.
- **Code mit versteckten Tests:** je Person und Aufgabe ein Lauf im Runner (mit den erwarteten Ausgaben
  für Lehrkräfte). Summe der erreichten Testpunkte, gedeckelt auf die Aufgabenpunkte. Haben die Tests keine
  Punkte, zählt der Anteil bestandener Tests.
- **Keine Antwort:** 0 Punkte, vermerkt als „leer“.

Der Lauf geschieht in kleinen Stapeln (je Aufruf höchstens 10 Paare oder 20 Sekunden), der Fortschritt steht
auf der Seite. Fällt der Runner aus, hält der Lauf an. Betroffene Zellen sind rot („!“) und lassen sich mit
„Fehlgeschlagene wiederholen“ später erneut prüfen. „Alles neu berechnen“ löscht alle Vorschläge (nicht
eure Punkte) und rechnet neu. In der Einzelansicht lässt sich eine einzelne Aufgabe mit „Neu testen“ prüfen,
zum Beispiel nach einer korrigierten Testbeschreibung.

Vorschläge sind **nur Vorschläge**: grau in der Matrix, bis ihr sie übernehmt (Einzelansicht: „Vorschlag
übernehmen“, Übersicht: „Vorschläge übernehmen (ohne Code)“ bzw. „Alle Vorschläge übernehmen“). Beim
Sammelübernehmen bleiben Zellen unberührt, in denen ihr schon bewertet habt.

### 2.3 Korrektur-Übersicht (Matrix)

Zeilen sind Personen, Spalten Aufgaben. Eine Zelle zeigt:

| Darstellung | Bedeutung |
|---|---|
| schwarze Zahl | bewertet (eure Punkte, bzw. der Endwert) |
| graue Kursivzahl | nur Vorschlag der automatischen Prüfung |
| – | offen |
| gelb hinterlegt, ⚠ | Zweitkorrektur weicht von der Erstkorrektur ab |
| rot, ! | automatische Prüfung fehlgeschlagen |
| ⏰ | Änderungen nach Ablauf der Bearbeitungszeit |
| 📋 | Einfügungen innerhalb der Klausur (aus der Klausur heraus kopierter Text) |
| „ohne Abgabe“ | Teilnahme nicht abgegeben (Zustand im Tooltip) |

Die letzten beiden Spalten zeigen die Summe der bewerteten Aufgaben und die Summe einschließlich der Vorschläge.
Filter: Name suchen, nur Personen mit offenen Aufgaben, mit abweichender Bewertung, ohne Abgabe oder vollständig bewertet.

**Anonym korrigieren:** „Anonym korrigieren“ blendet Namen aus und zeigt stabile Nummern („Nr. 7“). Die Namen stehen
dann weder auf der Seite noch in den Schnittstellendaten. Die Reihenfolge der Nummern hat nichts mit dem Alphabet zu tun.

### 2.4 Einzelansicht

Links: Aufgabentext (mit Kontext übergeordneter Aufgaben), **Erwartungshorizont**, **richtige Lösung** (bei Auswahl,
Zuordnung, Reihenfolge, Lücken) und **Musterlösung**. Rechts: die Antwort der Person (schreibgeschützt), darüber die
Bewertung.

- **Punkte:** 0 bis zur Aufgabenpunktzahl in Schritten von 0,5 (Komma oder Punkt). Gespeichert wird automatisch kurz nach
  der Eingabe und beim Verlassen des Feldes. „0“ und „Voll“ setzen die Extremwerte, „Vorschlag übernehmen“ den Vorschlag.
- **Testergebnisse:** ausklappbar, je Test bestanden oder nicht, erreichte Punkte, Meldung, Ausgabe und Fehlerausgabe.
- **Hinweise:** Überziehung (⏰), Anzahl Einfügungen (📋), „ohne Abgabe“.
- **Replay:** Knopf „Replay“ spielt die Entstehung der Antwort ab (nur mit Zugriff auf das Pult der Klausur).
- **Durchblättern:** „Person für Person“ (alle Aufgaben einer Person, dann die nächste) oder „Eine Aufgabe für alle“
  (dieselbe Aufgabe bei allen, dann die nächste Aufgabe). Auch über die Auswahlfelder oder die Matrix.

Tastatur:

| Taste | Wirkung |
|---|---|
| Enter im Punktefeld | speichern und zur nächsten Zelle (in der gewählten Reihenfolge) |
| Alt + Pfeil rechts / links | nächste / vorherige Zelle in der gewählten Reihenfolge |
| Alt + Pfeil unten / oben | nächste / vorherige Person bzw. Aufgabe (die jeweils andere Achse) |
| Alt + O | nächste noch offene Zelle |
| Strg + Enter im Kommentarfeld | Kommentar speichern |
| Esc im Kommentarfeld | Eingabe abbrechen |

### 2.5 Kommentare

- **Code:** Zeile anklicken (Cursor) oder mehrere Zeilen markieren, dann „Kommentar zu Zeile(n) …“. Kommentierte Zeilen
  sind gelb hinterlegt und tragen eine Sprechblase in der Randspalte; ein Klick darauf springt zum Kommentar.
- **Freitext:** Textstelle mit der Maus markieren, dann „Auswahl kommentieren“. Die Stelle wird hervorgehoben.
- **Ganze Aufgabe:** „Kommentar zur ganzen Aufgabe“.

Eigene Kommentare lassen sich bearbeiten und löschen; die Hauptlehrkraft darf alle löschen.

### 2.6 Erst- und Zweitkorrektur

Jede Lehrkraft mit dem Schalter „Korrigieren“ bewertet für sich (ein Eintrag je Lehrkraft, Aufgabe und Person).

- **Endwert:** der Eintrag der Hauptlehrkraft; hat sie nichts eingetragen, der einzige vorhandene (bei mehreren der zuletzt
  geänderte). Die Hauptlehrkraft entscheidet bei Abweichungen.
- **Abweichung:** in der Matrix gelb (⚠) und in der Einzelansicht als Hinweis mit den fremden Punkten.
- **Sichtbarkeit:** Die Hauptlehrkraft sieht alle Bewertungen und Kommentare. Hinzugefügte Lehrkräfte sehen zunächst nur ihre
  eigenen und können „Fremde Bewertungen zeigen“ einschalten (Schalter oben, gilt je Sitzung).

### 2.7 Ergebnisse

Die Seite „Ergebnisse“ zeigt je Person Summe, Prozent und Note nach Notenschlüssel, einen Kommentar an die Person und den
Abschluss.

- **Notenschlüssel:** Standard ist der Schlüssel des Kurses (sonst der Standard der Klassenstufe). Ohne Kurs oder zum Abweichen
  wählt die Hauptlehrkraft einen Schlüssel aus; die Noten werden sofort neu berechnet. Änderbar nur in der Phase „In Korrektur“.
- **Rückmeldung:** freier Text je Person, wird automatisch gespeichert und erscheint in der Rückgabe und im PDF.
- **Abschließen:** je Person per Häkchen oder gesammelt über „Alle vollständig bewerteten abschließen“. Nur abgeschlossene
  Personen sehen nach der Freigabe ihr Ergebnis. **Wer nach dem Abschluss noch Punkte ändert, setzt den Abschluss dieser Person
  zurück** (Hinweis erscheint), damit nichts ungeprüft ausgeliefert wird.
- **Klausurspiegel:** Anzahl, Durchschnitt (Prozent und Note), Median, bestes und schwächstes Ergebnis, Balkendiagramm der
  Notenverteilung (mit Tabelle für Screenreader), je Aufgabe Durchschnitt und Lösungsquote, je Anforderungsbereich die Lösungsquote.
  Berücksichtigt werden Personen mit Abgabe oder Bewertung; bei Aufgaben zählen nur bewertete Zellen.
- **Export:** CSV (Semikolon, UTF-8 mit BOM) oder Excel mit Namen, Punkten je Aufgabe, Summe, Prozent, Note, Abschluss.
- **PDF:** je Person („PDF“ in der Zeile) oder als Sammel-PDF (je Person neue Seite). Inhalt: Kopf mit Schule, Klausur, Kurs, Datum,
  Name, Punkten und Note, je Aufgabe Nummer, Titel, Punkte, Antwort (Text, Code mit Zeilennummern in Monospace, Diagramm als Bild),
  die Kommentare mit Stellenangabe und die Rückmeldung.

### 2.8 Freigeben, Zurücknehmen, Archivieren

- **Ergebnisse freigeben** (Hauptlehrkraft): die Klausur wechselt in „Zurückgegeben“. Ein Hinweis nennt, wie viele Personen noch
  nicht abgeschlossen sind; sie sehen nichts.
- **Freigabe zurücknehmen:** zurück in „In Korrektur“, Schüler:innen sehen nichts mehr, Bewertung wieder möglich.
- **Archivieren:** nur aus „Zurückgegeben“; danach ist die Rückgabe für Schüler:innen beendet.
- **Erwartungshorizont zeigen:** Schalter auf der Ergebnisseite. Ist er an, sehen Schüler:innen zu jeder Aufgabe den
  Erwartungshorizont. Musterlösung, Tests und richtige Antworten sehen sie nie.

## 3. Anleitung für Schüler:innen

1. Unter „Ergebnisse“ (Menü) oder „Meine Klausuren“ steht deine Klausur, sobald sie zurückgegeben ist.
2. „Ergebnis ansehen“ zeigt oben Note, Punkte und Prozent, darunter die Rückmeldung deiner Lehrkraft.
3. Darunter stehen alle Aufgaben mit deiner Antwort, den erreichten Punkten und den Anmerkungen. Bei Code sind kommentierte
   Zeilen gelb markiert, bei Texten die kommentierten Stellen; ein Klick darauf zeigt den Kommentar.
4. „Als PDF speichern“ erzeugt ein PDF deiner Klausur mit Bewertung.
5. Siehst du „noch nicht freigegeben“ oder „deine Korrektur ist noch nicht abgeschlossen“, ist das Ergebnis noch nicht bereit.
   Schau später noch einmal vorbei.

Ob der Erwartungshorizont erscheint, entscheidet die Lehrkraft.

## 4. Hinweise für Admins

- **Rechte:** Korrigieren darf, wer an der Klausur das Recht „grade“ hat: Hauptlehrkraft, Admins, Lehrkräfte mit „Alle Klausuren“
  und hinzugefügte Lehrkräfte mit dem Schalter „Korrigieren“ (Klausurseite, Abschnitt Lehrkräfte). Freigeben, Zurücknehmen,
  Archivieren und Notenschlüssel braucht „manage“ (Hauptlehrkraft, Admins).
- **Notenschlüssel:** unter Verwaltung, Notenschlüssel. Die Korrektur nutzt den Schlüssel des Kurses, sonst den Standard der
  Klassenstufe, sonst die Auswahl in den Ergebnissen.
- **Runner:** Die Vorbewertung von Code-Aufgaben braucht den Runner-Container (`RUNNER_URL`, `RUNNER_TOKEN`). Ohne Runner
  funktioniert alles andere, Code-Zellen bleiben ohne Vorschlag und lassen sich später prüfen.
- **Audit-Log:** Begonnene Korrektur, übernommene Vorschläge, Freigabe, Zurücknehmen, Archivieren, Export und PDF werden
  protokolliert.
- **Datenhaltung:** Bewertungen, Kommentare und Ergebnisse hängen an der Teilnahme und fallen mit ihr unter die
  Aufbewahrungsfristen der Klausur.

## 5. Technische Referenz

### 5.1 Routen

Seiten (HTML):

| Route | Zweck | Recht |
|---|---|---|
| `GET /klausuren/{id}/korrektur` | Übersicht (Parameter `anonym=1`, `fremde=1`) | grade |
| `POST /klausuren/{id}/korrektur/beginnen` | finished zu grading | grade |
| `GET /klausuren/{id}/korrektur/{pid}?aufgabe=&modus=aufgabe&anonym=&fremde=` | Einzelansicht (Bundle `grading.js`) | grade |
| `GET /klausuren/{id}/ergebnisse` | Ergebnisse und Klausurspiegel | grade |
| `POST /klausuren/{id}/ergebnisse/einstellungen` | Notenschlüssel, Erwartungshorizont zeigen | manage |
| `POST /klausuren/{id}/ergebnisse/alle-abschliessen` | alle vollständig bewerteten abschließen | grade |
| `GET /klausuren/{id}/ergebnisse/export?format=csv\|xlsx` | Export | grade |
| `GET /klausuren/{id}/ergebnisse/pdf` und `/pdf/{pid}` | PDF | grade |
| `POST /klausuren/{id}/freigeben`, `/zuruecknehmen`, `/archivieren` | Statusfluss | manage |
| `GET /meine-ergebnisse`, `/meine-ergebnisse/{id}`, `/meine-ergebnisse/{id}/pdf` | Rückgabe | Schüler:in |

JSON (Fehler wie in `docs/API.md`; Pfade ohne Anwendungsbasis):

| Route | Anfrage | Antwort |
|---|---|---|
| `GET /api/korrektur/{id}/zelle/{pid}/{tid}?fremde=1` | | `participation`, `answer` (`content`, `version`, `has_overtime`), `pastes`, `auto` (`suggested_points`, `details`), `own_points`, `final` (`points`, `source`, `differs`), `others`, `comments`, `max_points` |
| `POST /api/korrektur/{id}/bewertung` | `{participation_id, task_id, points}` (leer oder null löscht) | `points`, `final`, `totals`, `unfinalized`; 422 bei ungültigen Punkten, 409 außerhalb der Korrekturphase |
| `POST /api/korrektur/{id}/kommentar` | `{participation_id, task_id, id?, anchor, body}` | `comment` |
| `POST /api/korrektur/{id}/kommentar/{cid}/loeschen` | | `success` |
| `POST /api/korrektur/{id}/vorschlaege` | `{scope: "auto"\|"all", participation_id?, task_id?}` | `applied` |
| `POST /api/korrektur/{id}/vorbewertung` | `{reset?: "all"\|"errors", limit?: 1..25}` | `processed`, `errors`, `remaining`, `total`, `aborted`, `message` |
| `POST /api/korrektur/{id}/neu-testen` | `{participation_id, task_id}` | `auto` |
| `POST /api/ergebnisse/{id}/person/{pid}` | `{general_comment?, finalized?}` | `finalized`, `total`, `max`, `percent`, `grade_label` |

Anker der Kommentare: `docs/DATENFORMATE.md` Abschnitt 5. Details der Vorbewertung: Abschnitt 6.
Zusätzlich zu den dort genannten Quellen gibt es `{"source":"empty"}` (keine Antwort, 0 Punkte) und
`{"source":"error","error":"…"}` (Prüfung fehlgeschlagen, `suggested_points` ist dann NULL).

### 5.2 Datenmodell

Genutzt aus dem Grundschema: `grading_entries` (ein Eintrag je Teilnahme, Aufgabe, Lehrkraft), `grading_comments`,
`auto_gradings`, `participation_results`. Neu in Migration `140_grading.sql`:

- `exams.show_expectation_on_return` (0/1, Standard 0)
- `exams.grading_scale_id` (NULL = Schlüssel des Kurses)

`participation_results` wird bei jeder Bewertung neu berechnet (Summe, Maximum, Prozent, Note), `general_comment` und
`finalized_at` bleiben erhalten bzw. werden wie beschrieben zurückgesetzt.

### 5.3 Klassen

| Datei | Aufgabe |
|---|---|
| `src/Services/GradingService.php` | Punkte, Endwert, Summen, Noten, Kommentare, Abschluss, Statusfluss, Matrix |
| `src/Services/AutoGrader.php` | Vorbewertung im Stapel, Runner-Anbindung (`codeScore` ist rein) |
| `src/Services/ExamStatistics.php` | Klausurspiegel (`compute` ist rein) |
| `src/Services/ResultReport.php` | Bericht einer Teilnahme für Rückgabe und PDF (liest nie Lösungsdaten) |
| `src/Services/ResultPdf.php` | FPDF-Ausgabe (Windows-1252 per iconv) |
| `src/Controllers/{Grading,Results,ResultPdf,StudentResults}Controller.php` | Seiten und JSON |
| `frontend/src/grading/*` | Einzelansicht, Übersicht, Ergebnisse, Rückgabe, Code- und Textansicht mit Kommentaren |

### 5.4 Sicherheit

- Jeder Controller ruft vor allem anderen `requireStaff()` bzw. `requireStudent()`; Klausurrechte über `ExamAccess`.
  Alle POST-Routen prüfen CSRF.
- Teilnahmen und Aufgaben werden immer an die Klausur gebunden (`WHERE id = ? AND exam_id = ?`).
- Schüler:innen lesen nur die eigene Teilnahme, nur bei Status „returned“ **und** eigenem Abschluss. `StudentResultsController`
  erwähnt die Lösungstabelle genau einmal: eine Abfrage, die ausschließlich `expectation_md` liest und nur bei gesetztem
  Schalter `show_expectation_on_return` läuft. `SolutionLeakTest` prüft das statisch. Kommentare sehen Schüler:innen nur von der
  Lehrkraft, deren Punkte zählen.
- Kommentare, Antworten und Namen werden im Browser als Text eingesetzt (nie als HTML). Aufgaben- und Lösungstexte sind
  serverseitig bereinigtes Markdown.
- Anonymes Korrigieren liefert keine Namen aus (weder HTML noch JSON).

### 5.5 Tests

```
tools/dev.sh unit                                            # u. a. GradingLogicTest, SolutionLeakTest, RouteGuardsTest
TEST_DB_NAME=klausurwerk_test_korrektur tools/dev.sh test GradingModule
cd frontend && node --test "test/grading-*.test.js"
```

`GradingModuleTest` deckt ab: Vorbewertung (Auswahl, Zuordnung, Lücken, Stapel, Einzeltest), Code-Tests mit Fake-Transport
(Nutzlast, schreibgeschützte Dateien, Runner-Ausfall und Wiederholung), Rechte (Aufsicht ohne „Korrigieren“ erhält 403),
Phasen, Erst-/Zweitkorrektur, Kommentarrechte, Statusfluss, Notenberechnung, Abschluss, Rückgabe nur nach Freigabe und eigenem
Abschluss, keine Lösungsdaten für Schüler:innen, PDF (beginnt mit `%PDF`, je Person neue Seite).
