# Klausurwerk: Konzept (Entwurf 1, 2026-11-04)

Webanwendung für Informatik-Klausuren an einer Berliner Schule (Klassen 10 bis 12, Abitur nach
12 Jahren): Programmieren in Java, Python und SQL, dazu Freitext-, Auswahl- und Zeichenaufgaben.
Lehrkräfte überwachen die Klausur live in einem Pult, Schüler:innen arbeiten im Vollbild.

Dieses Dokument ist die Diskussionsgrundlage vor dem Bau. Was hier steht, wird nach der
Abstimmung zu `ARCHITECTURE.md` und `README.md` im Stil von Gartenarbeitstag und Berufsmesse.
Offene Entscheidungen stehen gesammelt in Abschnitt 17.

---

## 1. Rahmen (aus der Fragerunde)

| Thema | Entscheidung |
|---|---|
| Geräte | gemischt: Schulrechner (Windows) und eigene Laptops |
| Safe Exam Browser | optional, pro Klausur einstellbar („nur mit SEB“ wird serverseitig geprüft) |
| Betrieb | eigener Server, Docker Compose hinter dem Reverse-Proxy (`proxy_network`), Internet vorhanden |
| Deployment | Git, GitHub, Image über GitHub Actions in die GHCR |
| Login Schüler:innen | Stammdaten per CSV, feste Zugangskarte (PDF mit QR + Code), Lehrkraft/Admin kann zurücksetzen |
| Login Lehrkräfte/Admin | Passwort plus Code per E-Mail |
| Rollen | Admin, Lehrkraft, Schüler:in; die erstellende Lehrkraft fügt weitere Lehrkräfte zu einer Klausur hinzu |
| Modi | Klausur, Übung, Probe (Technik-Check) |
| Design | gleiches Design-System wie Gartenarbeitstag, Akzent **tiefes Indigo**, dunkle Editor-Ansicht |
| Bundesland | Berlin (Operatorenliste, Noten 1 bis 6 bzw. Punkte 0 bis 15) |
| Termin | keiner; Qualität vor Tempo, Ausbau in vier Stufen |

---

## 2. Was technisch geht und was nicht

Das gehört an den Anfang, weil sich alle weiteren Entscheidungen daran ausrichten.

**Lösungen sind sicher.** Musterlösung, Erwartungshorizont und versteckte Testfälle liegen in
eigenen Tabellen und verlassen den Server nie in Richtung Schüler-Browser. Kein Endpunkt, den eine
Schüler-Sitzung aufrufen darf, liest diese Tabellen. Ein Test (`tests/Unit/SolutionLeakTest.php`)
prüft das für jede Route.

**Der Quellcode der Oberfläche lässt sich im normalen Browser nicht verstecken.** Was der Browser
ausführt, kann man mit F12 lesen. Möglich ist nur, es unattraktiv zu machen:

- Schüler-JS wird im Docker-Build gebündelt, minifiziert und verschleiert, ohne Source-Maps.
- Rechtsklick, F12, Strg+Umschalt+I/J/C, Strg+U werden abgefangen (hält Neugierige ab, nicht Entschlossene).
- Eine Heuristik erkennt geöffnete DevTools (Fenstergrößen-Differenz, Debugger-Timing) und meldet
  das als Ereignis ins Pult. Das ist ein Hinweis, kein Beweis.
- Was wirklich schützt: **Safe Exam Browser.** Dort sind DevTools, Alt-Tab und andere Programme
  gesperrt. Ist eine Klausur auf „nur mit SEB“ gestellt, prüft der Server bei jeder Anfrage den
  Header `X-SafeExamBrowser-ConfigKeyHash` (SHA-256 aus URL und Config-Key) und lehnt alles
  andere ab.

**Was die Überwachung erkennen kann:** Vollbild verlassen, Tab/Fenster gewechselt oder minimiert,
Fokus verloren (Alt-Tab), Einfügen von außerhalb, zweiter Tab mit derselben Klausur,
Verbindungsabbruch, Neuladen, Fenstergröße geändert, DevTools-Verdacht.

**Was sie nicht erkennen kann:** Handy unter dem Tisch, zweiter Rechner, Zettel, ein zweiter
Monitor, auf den man nur schaut. Dafür ist die Aufsicht im Raum zuständig.

**Ton beim Verlassen:** Browser spielen Ton erst nach einer Nutzeraktion ab. Der Knopf
„Klausur beginnen“ im Warteraum schaltet den Ton frei; danach funktioniert das Signal auch, wenn
das Fenster im Hintergrund ist. Die Probe (Modus 3) testet genau das.

---

## 3. Stack und Aufbau

Wie Gartenarbeitstag: PHP 8.3, eigenes schlankes MVC, MariaDB 11.4, serverseitige Templates,
strikte CSP, lokale Fonts, keine CDNs zur Laufzeit. Neu sind ein Build-Schritt und ein
Runner-Container.

```
public/index.php          Front-Controller (CSP, COOP/COEP, Fehlerseiten, Routing)
src/bootstrap.php         Context (config, db, session, csrf, auth, view, settings, audit)
src/Core/                 Router, Database, Session, Csrf, Auth, Permissions, Context, View, Seb
src/Controllers/          ein Controller je Modul
src/Services/             Fachlogik (ExamClock, ParticipationState, Autosave, EventLog, Cards …)
src/routes/NN-modul.php   Routen je Modul
templates/                layouts/, partials/, pages/<modul>/
frontend/                 Quellen für gebündeltes JS (Editor, Schüleransicht, Pult, Worker)
public/assets/            CSS, Fonts, gebaute Bundles, Pyodide, sql.js
runner/                   Java-Runner (eigener Container)
migrations/               versionierte SQL-Migrationen
tests/                    PHPUnit (Unit + Integration)
```

**Build (Multi-Stage-Dockerfile):** Stufe `node` baut `frontend/` mit esbuild (CodeMirror 6,
Schüleransicht, Pult) und verschleiert die Schüler-Bundles. Stufe `php:8.3-apache` übernimmt nur
die fertigen Dateien. Auf dem Server ändert sich nichts: `docker compose pull && up -d`.

**Container:**

| Dienst | Aufgabe |
|---|---|
| `app` | PHP/Apache, wie bisher, im `proxy_network` |
| `db` | MariaDB 11.4 |
| `runner` | JDK 21, kompiliert und führt Java-Code aus; hängt nur an einem internen Netz ohne Internet |

**Live-Kommunikation:** kurzes Polling statt WebSockets. Apache/PHP hält keine Dauerverbindungen
gut aus, und Polling übersteht WLAN-Aussetzer ohne Sonderlogik.

- Schüler:in: Herzschlag alle 2 s (Zustand, Uhr, Durchsagen), Autosave nach Änderung, spätestens alle 5 s.
- Pult: alle 1 bis 2 s ein Abruf „alles seit Cursor X“ (Zustände, neue Ereignisse, Anfragen).
- Bei 35 Schüler:innen sind das rund 20 bis 30 kleine Anfragen pro Sekunde, für einen kleinen VPS
  unkritisch. Die Abstände sind in den Einstellungen änderbar.

**Uhr:** Die Serverzeit ist maßgeblich. Der Client misst beim Herzschlag den Versatz und zeigt die
Restzeit danach an; eine verstellte Rechneruhr ändert nichts.

---

## 4. Rollen und Rechte

| Rolle | Kann |
|---|---|
| `admin` | alles; Lehrkräfte anlegen, Einstellungen, Aufbewahrungsfristen, Audit-Log |
| `teacher` | eigene Kurse und Klausuren; Schüler:innen seiner Kurse verwalten, Karten drucken, Passwort zurücksetzen |
| `student` | eigene Klausuren schreiben, Übungen, korrigierte Klausuren nach Freigabe ansehen |

**Lehrkräfte an einer Klausur** (`exam_staff`): Die erstellende Lehrkraft ist Hauptlehrkraft.
Sie fügt weitere Lehrkräfte hinzu. Diese dürfen immer das Pult bedienen und Antworten live sehen.
Zwei Schalter pro hinzugefügter Lehrkraft, von der Hauptlehrkraft gesetzt:

- `can_view_solutions`: Aufgaben, Musterlösung, Erwartungshorizont, Tests sehen
- `can_grade`: mitkorrigieren (Stufe 2)

Granulare Rechte wie bei dir über `Permissions::KONSTANTEN`, mit Rechteseite für Admins.

---

## 5. Anmeldung

**Schüler:innen:** Feste Zugangskarte (PDF, mehrere je A4-Seite) mit Name, Kurs, Benutzername,
Passwort und QR-Code, der Benutzername und Passwort ins Login-Formular füllt. Lehrkraft oder Admin
kann eine Karte zurücksetzen: neues Passwort, neue Karte, alte ungültig (`card_version`).
Login-Drossel wie bei dir (`login_attempts`).

**Zugang zu einer Klausur:** Die Klausur hängt an einem Kurs. Eingeloggte Schüler:innen des Kurses
sehen sie, sobald die Lehrkraft den Warteraum öffnet. Ein Klausurcode an der Tafel ist zusätzlich
möglich (Schalter pro Klausur), damit niemand von zu Hause aus einen Platz belegt.

**Gerätebindung:** Beim Eintritt in den Warteraum bekommt das Gerät ein Token (Cookie). Meldet sich
dieselbe Person während der Klausur an einem anderen Gerät an, landet sie im Zustand
„wartet auf Freigabe“, und das Pult zeigt „Gerätewechsel angefragt“. Erst nach Freigabe wird das
neue Gerät gebunden, das alte gesperrt. Der letzte Stand wird übernommen.

**Lehrkräfte/Admin:** Passwort plus 6-stelliger Code per E-Mail (vorhandene `login_codes`-Logik).
Ohne konfigurierten Mailserver Hinweis im Setup; Admin kann den Zwang abschalten.

---

## 6. Ablauf einer Klausur

### Klausur-Status

```
Entwurf → Bereit → Warteraum offen → Läuft ⇄ Pausiert → Beendet → in Korrektur → zurückgegeben → archiviert
```

- **Entwurf:** Aufgaben, Einstellungen, Lehrkräfte bearbeiten.
- **Bereit:** gesperrt gegen versehentliche Änderungen, Karten und Probelauf möglich.
- **Warteraum offen:** Schüler:innen melden sich an, sehen „Warte auf Start“, klicken einmal
  „Bereit“ (schaltet Ton und Vollbild frei). Das Pult zeigt, wer da ist.
- **Läuft:** Lehrkraft drückt „Start“, die Uhr läuft für alle gleich. Wer später kommt, bekommt die
  Restzeit; die Lehrkraft kann ihm Zeit dazugeben.
- **Beendet:** alle Abgaben bestätigt.

### Zustand je Teilnahme (`participations.state`)

| Zustand | Bedeutung | Schüleransicht |
|---|---|---|
| `waiting` | im Warteraum | Warteseite |
| `active` | arbeitet | Klausur |
| `locked` | Verstoß, wartet auf Freigabe | Sperrbildschirm mit Grund |
| `absent` | Toilette o. Ä., von Lehrkraft gesetzt | Sperrbildschirm „Abwesend“, Knopf „Zurück, Freigabe anfragen“ |
| `paused` | Pause (einzeln oder alle) | Sperrbildschirm „Pause“, Uhr steht |
| `submit_requested` | Schüler:in will abgeben | Hinweis „Abgabe wird bestätigt“, Bearbeitung gesperrt bis Entscheidung |
| `submitted` | abgegeben (immer durch Lehrkraft bestätigt) | Bestätigungsseite |
| `device_pending` | Gerätewechsel angefragt | Hinweis „Warte auf Freigabe“ |

Jeder Übergang wird mit Zeit, auslösender Person und Grund in `participation_events` protokolliert.
Erlaubte Übergänge liegen an **einer** Stelle (`Services\ParticipationState`), wie dein `LimitCheck`.

### Zeit

Individuelles Ende = Start + Dauer × (1 + Nachteilsausgleich) + gutgeschriebene Minuten + Pausenzeit.

- **Nachteilsausgleich** (z. B. +25 %) hängt dauerhaft an der Person und wird automatisch angewendet.
- **Zeit verlängern** im Pult für einzelne oder alle.
- **Pause** für alle oder einzelne: Uhr steht, Bildschirm gesperrt.
- **Toilette:** Nur die Lehrkraft setzt das, durch Klick auf die Kachel. Ob die Uhr dabei steht,
  wird vorher in der Klausur eingestellt (Standard: Uhr läuft weiter). Nach der Rückkehr fragt die
  Person die Freigabe an, oder die Lehrkraft gibt direkt frei.
- **Zeitende:** ein Signal und eine Meldung bei Schüler:in und im Pult. **Es wird nichts gesperrt
  und nichts abgegeben.** Weiterarbeit bleibt möglich, Änderungen ab diesem Moment werden als
  Überziehung markiert (im Replay und in der Korrektur sichtbar). Erst wenn die Lehrkraft
  „Abgeben“ drückt (alle oder einzeln), wird gesperrt und abgegeben.
- **Selbst abgeben:** Schüler:in drückt „Abgeben“ (mit Sicherheitsabfrage), die Lehrkraft bestätigt
  im Pult oder lehnt ab (dann geht es weiter).

**Abgeben heißt immer:** Der Server nimmt den letzten Stand, prüft, dass alle Änderungen bis zur
letzten Sequenznummer angekommen sind, und zeigt das im Pult an („vollständig“ oder
„letzter Stand vor 40 s, Gerät offline“). Die Lehrkraft sieht das, bevor sie bestätigt.

---

## 7. Überwachung und Alarm

**Ereignisse** (`participation_events.type`): `fullscreen_exit`, `visibility_hidden`, `window_blur`,
`paste_external`, `paste_blocked`, `second_tab`, `connection_lost`, `connection_restored`, `reload`,
`resize`, `devtools_suspected`, `seb_missing`, sowie alle Zustandswechsel.

**Bei Verstoß** (Vollbild verlassen, Browser/Tab verlassen):

1. Sofort lokal: Bildschirm sperren, ein einheitlicher kurzer Ton, Meldung
   „Du hast den Vollbildmodus verlassen. Die Lehrkraft wurde benachrichtigt.“
2. Ereignis an den Server (bei fehlender Verbindung gepuffert und nachgereicht).
3. Pult: derselbe Ton, Meldung oben, Kachel wird rot, Eintrag im Zeitstrahl.
4. Freigabe nur durch die Lehrkraft. Danach zurück in den Vollbildmodus und weiter.

Ein kurzes Wackeln unter 300 ms (Systemdialoge, die den Fokus kurz stehlen) wird als Hinweis
protokolliert, aber nicht gesperrt. Die Schwelle ist einstellbar.

**Kopieren/Einfügen:** Innerhalb der Klausur erlaubt. Die Anwendung merkt sich, was in ihr kopiert
wurde; Einfügen von etwas anderem wird blockiert und als `paste_blocked` mit Länge protokolliert.
Auch Drag & Drop von außen wird blockiert.

**Pult:**

- Kacheln je Schüler:in: Name, Zustand (Farbe), Vollbild/Fokus, Verbindung, letzte Aktivität,
  Fortschritt (Aufgaben bearbeitet/markiert), Restzeit, Hand gehoben.
- Kacheln frei anordnen wie die **Sitzordnung im Raum** (Drag & Drop, je Raum gespeichert).
- Zeitstrahl aller Ereignisse, filterbar.
- Klick auf Kachel: Aktionen (Freigeben, Zeit dazu, Pause, Abwesend, Abgabe bestätigen,
  Gerätewechsel freigeben) und **Live mitlesen** (aktuelle Antworten, alle 2 s aktualisiert).
- Stummschalter für den Ton.
- **Durchsage** an alle oder einzelne (erscheint als Banner, wird protokolliert).
- **Hand heben:** Knopf in der Schüleransicht, Kachel leuchtet, Lehrkraft quittiert.

**Replay:** Der Editor schickt mit jedem Autosave die Änderungsschritte (CodeMirror-Changesets mit
Zeitstempel). Daraus lässt sich die Entstehung jeder Antwort abspielen, mit Markierung von
Einfügungen und Überziehungszeit. Grundfunktion in Stufe 1 (Daten speichern), Abspieler mit
Zeitleiste in Stufe 4.

---

## 8. Autosave und Ausfälle

- Jede Antwort hat eine fortlaufende Version. Der Client schickt Änderungen mit Sequenznummer, der
  Server bestätigt die höchste empfangene Nummer. Doppelte oder alte Pakete werden verworfen.
- Lokale Sicherung in IndexedDB bei jeder Änderung. Bricht die Verbindung ab, kann weitergeschrieben
  werden (Hinweis „offline, wird gespeichert, sobald Verbindung da ist“), und das Pult zeigt
  „Verbindung weg seit …“.
- Nach Absturz oder Neuladen auf demselben Gerät: Stand vom Server, ergänzt um lokal noch nicht
  bestätigte Änderungen. Neuladen ist ein protokolliertes Ereignis.
- Anderes Gerät: nur mit Freigabe (Abschnitt 5).

---

## 9. Aufgaben

**Aufbau:** Aufgabe mit Teilaufgaben (1a, 1b …), je Teilaufgabe Punkte, Operator und
Anforderungsbereich (I/II/III), eigener Antworttyp. Aufgabentext in Markdown mit Live-Vorschau
(Überschriften, Code-Blöcke mit Hervorhebung, Tabellen, Formeln über KaTeX lokal). Der Server
rendert und bereinigt das HTML.

**Material:** Bilder, Tabellen, PDF-Anhänge, Code-Ausschnitte. Ausgeliefert über eine geschützte
Route, nur an Teilnehmende während der Klausur.

**Operatoren:** Liste für Berlin als Startbestand, vom Admin bearbeitbar. Beim Erstellen
auswählbar, im Klausurspiegel nach Anforderungsbereich ausgewertet.

**Antworttypen:**

| Typ | Stufe | Automatisch prüfbar |
|---|---|---|
| Code (Java, Python, SQL), optional mit Gerüst und mehreren Dateien | 1 | Tests (in der Korrektur) |
| Freitext (einfache Formatierung) | 1 | nein |
| Multiple Choice / Mehrfachauswahl | 1 | ja |
| Zuordnung / Reihenfolge | 1 | ja |
| Lückentext / Lückencode | 1 | ja (exakt oder Muster) |
| Freies Zeichnen / Bild-Upload | 3 | nein |
| UML-Klassendiagramm | 3 | teilweise (Struktur vergleichen) |
| Struktogramm / PAP | 3 | nein |
| Logikschaltung, Wahrheitstabelle, endlicher Automat | 3 | teilweise (Simulation) |
| ER-Modell, Relationenmodell | 3 | teilweise |

**Getrennte Speicherung:** `tasks` enthält nur, was Schüler:innen sehen dürfen.
`task_solutions` (Musterlösung, Erwartungshorizont, Tests, korrekte MC-Antworten) ist eine eigene
Tabelle, auf die nur Lehrkräfte-Controller zugreifen.

**Zufall:** MC-Antworten auf Wunsch je Person gemischt (deterministisch aus Teilnahme-ID).

**Wiederverwendung:** Kein eigener Pool. Stattdessen ein durchsuchbares Archiv aller früheren
Klausuren (Text, Sprache, Klassenstufe, Operator). Aufgaben werden in die neue Klausur **kopiert**,
spätere Änderungen wirken nicht zurück. Export/Import ganzer Klausuren als Datei (JSON +
Materialien, ZIP), z. B. zum Tausch mit Kolleg:innen.

---

## 10. Einstellbare Umgebung

Pro Klausur, mit Abweichung pro Aufgabe:

- **Sprache und Version:** Java 21, Python (Pyodide-Version), SQL (SQLite).
- **Ausführung:** ausführbar / nur Syntaxprüfung / nur schreiben.
- **Editor-Komfort:** Autovervollständigung, Fehleranzeige beim Tippen, Klammern schließen,
  Formatierer, Einrückhilfen, je einzeln an/aus.
- **Hilfsmittel:** Java-API-Auszug, Python-Doku-Auszug, SQL-Spickzettel, Operatorenliste,
  eigene PDFs, Taschenrechner. Alles lokal, im Klausurfenster als Seitenleiste.
- **Vorgegebene Dateien:** Klassen, Bibliotheken, CSV/TXT, Datenbanken; je Datei „schreibgeschützt“
  oder „bearbeitbar“.
- **Python-Pakete:** Standardbibliothek; numpy/matplotlib pro Klausur zuschaltbar.
- **Überwachung:** Sperre bei Verstoß (Standard an), SEB-Pflicht, Klausurcode, Toilette hält Uhr an
  (Standard aus), Fokus-Schwelle.

---

## 11. Code ausführen

| Sprache | Wo | Wie |
|---|---|---|
| Python | Browser | Pyodide in einem Web Worker. `input()` wartet echt (SharedArrayBuffer, daher COOP/COEP-Header). Eigene Turtle-Umsetzung auf einer Zeichenfläche (das echte `turtle` braucht Tk). Vorgegebene Dateien liegen im virtuellen Dateisystem. Endlosschleifen: Stopp-Knopf beendet den Worker. |
| SQL | Browser | SQLite als WebAssembly (sql.js). Jede Person arbeitet auf ihrer eigenen Kopie der vorgegebenen Datenbank, „Zurücksetzen“ stellt den Ausgangszustand her. |
| Java (Konsole, OOP) | Server | `runner`-Container mit JDK 21: kompiliert und startet in einem Kindprozess unter eigenem Benutzer, ohne Netz, mit Zeitlimit, Speicherlimit, Prozesslimit, schreibgeschütztem Dateisystem und tmpfs. Interaktive Eingabe über eine Lauf-Sitzung (starten, Eingabe senden, Ausgabe abholen). Warteschlange mit einstellbarer Parallelität. |
| Java-GUI | Browser (Stufe 4) | CheerpJ führt echtes Swing im Browser aus. Lizenz ist zu klären (Abschnitt 17). Ersatzweise eine eigene kleine Grafik-Bibliothek. |

**Wichtig:** Versteckte Tests laufen nie im Browser. In Stufe 1 gibt es für Schüler:innen nur
„Ausführen“ und sichtbare Beispieltests, die die Lehrkraft ausdrücklich freigibt. Versteckte Tests
laufen in der Korrektur auf dem Server.

---

## 12. Schüleransicht

- Geteilte Ansicht: Aufgabe links, Editor rechts, Konsole/Ergebnis unten; Trennlinien verschiebbar.
- Aufgabenleiste: alle Aufgaben, frei springen, Zustand je Aufgabe (leer, bearbeitet, „später“).
- Kopfzeile: Restzeit (Serverzeit), „zuletzt gespeichert vor x s“, Verbindung, Hand heben, Abgeben.
- Durchsagen als Banner.
- Hell/Dunkel umschaltbar, Schriftgröße im Editor einstellbar.

---

## 13. Korrektur und Rückgabe (Stufe 2)

- Korrekturansicht je Person und Aufgabe, daneben Erwartungshorizont und Musterlösung.
- Zeilenkommentare im Code, Kommentare an Textstellen, Punkte je Teilaufgabe.
- Testergebnisse (versteckte Tests auf dem Server) mit Punktvorschlag; die Lehrkraft entscheidet.
- MC, Zuordnung, Lücken werden automatisch vorbewertet, überschreibbar.
- Ansicht „eine Aufgabe für alle“ zum zügigen Korrigieren.
- Zweitkorrektur durch hinzugefügte Lehrkräfte mit `can_grade`.
- Notenschlüssel pro Kurs: Noten 1 bis 6 (mit Tendenzen) oder Punkte 0 bis 15, Prozentgrenzen
  einstellbar, Vorlage je Klassenstufe.
- Klausurspiegel, Auswertung nach Aufgabe und Anforderungsbereich.
- PDF je Person (Aufgaben, Antworten, Korrektur, Note) und Sammel-PDF; FPDF wie bei dir.
- Digitale Rückgabe: erst nach Freigabe durch die Hauptlehrkraft, nur lesen.

---

## 14. Datenmodell (Entwurf)

| Tabelle | Zweck |
|---|---|
| `users` | Konten: Rolle, Name, Benutzername, Passwort-Hash, `card_version`, `accommodation_percent` (Nachteilsausgleich), aktiv |
| `courses`, `course_members` | Kurse/Klassen, Klassenstufe, Schuljahr, Notenschema, Lehrkraft |
| `exams` | Titel, Kurs, Modus (`exam`/`practice`/`probe`), Status, Dauer, Startzeit, Einstellungen (JSON: Sperre, SEB, Klausurcode, Toilette-Uhr, Editor-Komfort, Hilfsmittel, Pakete), Hauptlehrkraft |
| `exam_staff` | weitere Lehrkräfte je Klausur mit `can_view_solutions`, `can_grade` |
| `tasks` | Aufgaben und Teilaufgaben (`parent_id`), Reihenfolge, Markdown, Punkte, Operator, AFB, Antworttyp, schülersichtige Konfiguration (JSON) |
| `task_solutions` | Musterlösung, Erwartungshorizont, Tests, korrekte Antworten; **nie** an Schüler:innen |
| `task_files`, `exam_materials` | vorgegebene Dateien und Materialien, schreibgeschützt ja/nein |
| `participations` | Person je Klausur: Zustand, Gerätetoken, Startzeit, Zusatzminuten, Pausensumme, letzte bestätigte Sequenz, zuletzt gesehen, Abgabe (Zeit, bestätigt von) |
| `answers` | aktueller Stand je Teilnahme und Aufgabe: Inhalt (JSON), Version, „später“-Markierung, Überziehung ja/nein |
| `answer_ops` | Änderungsschritte für Replay (Sequenz, Zeit, Changeset, Einfügung ja/nein) |
| `participation_events` | Ereignisse und Zustandswechsel mit Zeit, Typ, Details, auslösender Person |
| `exam_announcements` | Durchsagen (an alle oder einzelne) |
| `exam_requests` | Hand heben, Freigabe-, Abgabe-, Gerätewechsel-Anfragen mit Status |
| `rooms`, `room_layouts` | Räume und Kachelanordnung, Sitzzuordnung je Klausur |
| `operators` | Operatorenliste mit AFB |
| `grading_*` | Stufe 2: Punkte, Kommentare, Notenschlüssel, Freigabe |
| `settings`, `audit_logs`, `login_attempts`, `login_codes` | wie in deinen Projekten |

**Datenbankseitige Garantien** (wie dein `assigned_block_key`): genau eine Teilnahme je Person und
Klausur (UNIQUE), genau ein aktueller Antwortstand je Teilnahme und Aufgabe (UNIQUE),
Sequenznummern je Teilnahme eindeutig.

---

## 15. Datenschutz und Aufbewahrung

- Admin stellt Fristen ein, getrennt für Antworten/Noten und für Ereignisse/Replay-Daten.
- Nach Ablauf erinnert das Dashboard; gelöscht wird nach Bestätigung, protokolliert im Audit-Log.
- Ereignisprotokoll und Replay sind nur der Hauptlehrkraft, berechtigten Lehrkräften und Admins
  zugänglich.
- Keine Kamera, kein Mikrofon (Permissions-Policy sperrt das). Bildschirmübertragung nur, wenn die
  Lehrkraft sie für eine Klausur einschaltet (Abschnitt 19): Standbilder, nur im Arbeitsspeicher,
  nie gespeichert.
- Eine kurze Datenschutzinfo für Schüler:innen und Eltern liefere ich als Textvorlage mit; die
  rechtliche Prüfung gehört zur Schule bzw. zum Datenschutzbeauftragten.

---

## 16. Ausbaustufen

**Stufe 1: Kern**
Setup-Assistent, Konten und Kurse (CSV-Import), Zugangskarten-PDF, Lehrer-Login mit Mail-Code,
Klausur anlegen (Aufgaben, Teilaufgaben, Markdown, Material, Einstellungen), Lehrkräfte hinzufügen,
Schüleransicht mit Code-Editor (Python und SQL im Browser, Java im Runner), Freitext, MC,
Zuordnung, Lücken, Warteraum, Start, Uhr mit Nachteilsausgleich, Pause, Toilette, Zeitende-Signal,
Abgabe mit Bestätigung, Überwachung mit Sperre und Ton, Copy/Paste-Regel, Live-Pult mit Sitzplan,
Mitlesen, Durchsage, Hand heben, Autosave mit lokaler Sicherung, Gerätewechsel, SEB-Prüfung,
Replay-Daten speichern, Modi Übung und Probe, Archivsuche, Export/Import, Tests.

**Stufe 2: Korrektur**
Abschnitt 13.

**Stufe 3: Zeichnen**
UML, Struktogramm/PAP, Logik/Automaten, ER/Relationenmodell, freies Zeichnen. Diagramme werden als
JSON-Modell gespeichert und als SVG dargestellt, damit sie korrigierbar, druckbar und teilweise
automatisch prüfbar sind.

**Stufe 4: Extras**
Eigene Gitterwelt (Figur, Hindernisse, Gegenstände, Befehle und Sensoren; Java und Python,
animiert im Browser), Java-GUI über CheerpJ oder eigene Grafik-Bibliothek, Replay-Abspieler mit
Zeitleiste.

---

## 17. Offene Punkte

Bitte zu jedem kurz Bescheid geben; bei einigen habe ich eine Empfehlung.

1. **CheerpJ-Lizenz.** Laut Hersteller frei für persönliche und die meisten nicht-kommerziellen
   Projekte, aber nur über deren CDN; Selbst-Hosten braucht eine Lizenz, und Schulen sollen ihre
   Berechtigung anfragen. Empfehlung: in Stufe 4 bei Leaning Technologies anfragen. Bis dahin
   kein Einfluss auf Stufe 1 bis 3.
2. **SQL-Dialekt.** Im Browser läuft SQLite. Im Berliner Unterricht wird oft MySQL/MariaDB
   verwendet; Unterschiede gibt es z. B. bei `AUTO_INCREMENT`, Datumsfunktionen, `LIMIT`-Varianten.
   Reicht SQLite, oder brauchst du echtes MariaDB (dann serverseitig mit einer Wegwerf-Datenbank
   je Person, mehr Aufwand und Last)?
3. **Notenschema Berlin.** Mein Stand: Klasse 10 (Einführungsphase am Gymnasium) Noten 1 bis 6,
   Klasse 11/12 (Qualifikationsphase) Punkte 0 bis 15. Stimmt das für deine Schule, und hast du eine
   übliche Prozenttabelle?
4. **Operatorenliste.** Hast du die offizielle Berliner/Brandenburger Operatorenliste Informatik als
   PDF? Dann übernehme ich sie genau statt aus dem Gedächtnis.
5. **Abiturklassen.** Gibt es in Berlin vorgegebene Klassen (Listen, Bäume, Datenbankzugriff), die
   im Unterricht verwendet werden und als Bibliothek bereitstehen sollen?
6. **Domain und Port.** Unter welcher Adresse soll Klausurwerk laufen, und welcher Host-Port ist
   frei (Vorschlag 9030)?
7. **Mailserver.** Gibt es einen SMTP-Zugang für die Lehrer-Codes (wie bei Gartenarbeitstag)?
8. **SEB-Konfiguration.** Soll Klausurwerk die `.seb`-Konfigurationsdatei pro Klausur selbst
   erzeugen (Start-URL, erlaubte Adressen, Beenden-Passwort), oder pflegst du die selbst?
9. **Server-Leistung.** Für den Java-Runner plane ich 2 CPU-Kerne und 2 GB RAM zusätzlich ein, mit
   Warteschlange bei Überlast. Was hat dein Server?
10. **GitHub.** Name des Repos und GitHub-Konto bzw. Organisation für die GHCR.

## 18. Entscheidungen zum Abschluss (4. November 2026)

- **Java bleibt im Runner auf dem Server.** CheerpJ (Java im Browser) wird nicht eingebaut: keine
  Lizenzfrage, der Runner ist umgesetzt, abgeschottet (eigene UID, seccomp, keine Kanäle zwischen
  Läufen) und getestet.
- **Restrisiken akzeptiert.** Zeitstempel einer Antwort kommen vom Schülerrechner (der Server prüft
  und begrenzt sie), und ein gezielt präpariertes Programm kann in der automatischen Prüfung ein
  „bestanden“ vortäuschen. Automatische Bewertungen bleiben deshalb Vorschläge, die die Lehrkraft
  bestätigt.
- **Noch offen, später:**
  - Test mit echtem Safe Exam Browser: unverschlüsselte und verschlüsselte `.seb`-Datei öffnen,
    „Nur mit Safe Exam Browser“ aktivieren, optional Browser Exam Key eintragen. Wenn der Server
    steht.
  - Einrichtung auf dem echten Server: Domain, Reverse-Proxy mit HTTPS (Cross-Origin-Isolation
    prüfen), SMTP, dann Probelauf. Die Domain steht noch nicht fest.

## 19. Live-Wand und Löschen per Auswahl (4. November 2026)

- **Live-Wand im Pult** (Umschalter „Sitzplan / Live-Wand“): je Teilnahme eine Karte mit Aufgabe,
  Code-Ausschnitt um die Cursorzeile und letzten Konsolenzeilen (`live_view`, Standard an, auch
  im SEB).
- **Bildschirmfreigabe des ganzen Bildschirms** (`screen_share`, Standard aus): Standbilder, in der
  Übersicht etwa alle 5 s klein, in der Großansicht (Fokus) etwa 1 pro Sekunde groß. Kein Ton, keine
  Aufzeichnung.
- **Beenden oder Verweigern** ergibt nur einen Alarm, keine Sperre. Im SEB entfällt die Freigabe
  ohne Alarm.
- **Solange der Auswahldialog offen ist**, ruht die Fokusüberwachung höchstens 30 s.
- **Speicherung: keine.** Nur das jeweils letzte Bild und der letzte Live-Zustand liegen im tmpfs
  des App-Containers und werden beim Klausurende gelöscht.
- **Löschen überall einheitlich:**
  - Auswählen (Klick oder Fokus), dann Entf, Rücktaste oder Knopf „Löschen“.
  - In Textfeldern löscht die Rücktaste nur Zeichen.
  - Aufgaben und Dateien mit Inhalt nur nach Rückfrage, Diagrammelemente mit Strg+Z.
