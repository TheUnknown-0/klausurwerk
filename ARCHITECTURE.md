# Klausurwerk: Architektur und verbindliche Konventionen

Referenz für alle, die an der Anwendung arbeiten. Aufbau und Stil folgen
Gartenarbeitstag/Berufsmesse. Fachliche Entscheidungen stehen in `KONZEPT.md`,
JSON-Schnittstellen in `docs/API.md`, Datenformate in `docs/DATENFORMATE.md`.

## Überblick

- **Stack:** PHP 8.3, eigenes schlankes MVC (kein Framework, kein Composer zur Laufzeit),
  MariaDB 11.4, serverseitige PHP-Templates, Vanilla-JS. Komplexe Oberflächen
  (Schüleransicht, Pult, Aufgaben-Editor, Korrektur, Diagramme) sind ES-Module unter
  `frontend/src/`, gebündelt mit esbuild im Docker-Build.
- **Strikte CSP** (`src/Core/Csp.php`): Skripte nur von der eigenen Herkunft, kein Inline-JS,
  `'wasm-unsafe-eval'` für WebAssembly. Cross-Origin-Isolation (COOP/COEP) für
  SharedArrayBuffer (interaktives `input()` in Python).
- **Drei Container:** `app` (PHP/Apache), `db` (MariaDB), `runner` (Java/Python/SQLite,
  ohne Internet, nur internes Netz).
- **Keine CDNs zur Laufzeit:** Fonts, Pyodide, sql.js, KaTeX liegen unter `public/assets/`.

```
public/index.php          Front-Controller (Header, CSP, Fehlerseiten, Routing)
src/bootstrap.php         baut Context (config, db, session, csrf, auth, view, settings, audit, exams)
src/Core/                 Router, Database, Session, Csrf, Csp, Auth, Permissions, Context, View
src/Controllers/          ein Controller je Modul
src/Services/             Fachlogik
src/Services/TaskTypes/   ein Antworttyp je Datei (*Type.php, automatisch registriert)
src/routes/NN-modul.php   Routen je Modul (Reihenfolge = Ladereihenfolge)
templates/                layouts/ (app, minimal, exam), partials/, pages/<modul>/
frontend/src/entries/     ein Bundle je Datei → public/assets/build/<name>.js
frontend/src/lib/         gemeinsame Bausteine (api, dom, code-editor, widgets, rich-text)
frontend/src/widgets/     Antwort-Widgets und Aufgaben-Editoren je Typ
runner/                   Runner-Container (eigenes Dockerfile)
migrations/               versionierte SQL-Migrationen, laufen beim Container-Start
tests/                    PHPUnit (Unit + Integration), tests/e2e (Playwright)
```

## Request-Fluss

`public/index.php` → `src/bootstrap.php` (liefert `Context $ctx`) → registriert alle
`src/routes/*.php` → Router matcht → `new Controller($ctx)` → Aktion `name(array $params)`.
Rückgabe: `string` = HTML, `array` = JSON, oder `$this->redirect(...)`.
Fehler: `throw new HttpException(403)`; unter `/api/` antwortet der Front-Controller mit JSON.

**Sitzungssperre:** PHP sperrt die Sitzung je Request. Hochfrequente APIs (Herzschlag,
Autosave, Pult-Abruf, Runner-Ausgabe) rufen nach den Guards `$this->ctx->session->release()`,
damit parallele Anfragen derselben Person nicht aufeinander warten.

## Rechte

Zwei Ebenen, nie vermischen:

1. **Granulare Rechte** (`Core\Permissions`, `$auth->can(P::…)`): Verwaltungsbereiche
   (Benutzer, Kurse, Stammdaten, Einstellungen). `admin` hat alles, `teacher` hat
   Rollen-Standards plus Zuweisungen, `student` nie etwas.
2. **Rechte an einer Klausur** (`Services\ExamAccess`, `$this->ctx->exams->require($id, ExamAccess::CAP_…)`):
   `view`, `monitor`, `solutions`, `grade`, `edit`, `manage`. Hauptlehrkraft, Admins und
   Lehrkräfte mit `ALLE_KLAUSUREN` haben alle; Aufsichten (`exam_staff`) haben `view` und
   `monitor`, dazu `solutions`/`grade` nur per Schalter.
3. **Schüler:innen** erreichen eine Klausur nur über ihre Teilnahme (`participations`) und nur,
   wenn sie Mitglied des Kurses der Klausur sind.

## Sicherheits-Pflichten

1. **Jede** Ausgabe in Templates durch `e(...)`. Vom Server gerendertes Markdown
   (`Services\Markdown`) ist bereits bereinigt und darf roh ausgegeben werden.
2. **Jeder** POST → `requireCsrf()` (Formularfeld `<?= $csrf->field() ?>` oder Header `X-CSRF-Token`).
3. **Nur** Prepared Statements mit `?`-Parametern.
4. **Jede** Route ist login- und rechtegeschützt. `tests/Unit/RouteGuardsTest.php` prüft das.
5. **Lösungen verlassen nie den Server in Richtung Schüler:innen.** `task_solutions` wird nur
   in Controllern gelesen, die vorher `CAP_SOLUTIONS` oder `CAP_GRADE` prüfen. Schüler-APIs
   lesen ausschließlich `tasks`, `task_files`, `exam_materials` und die eigenen `answers`.
   `tests/Unit/SolutionLeakTest.php` prüft statisch, dass Schüler-Controller `task_solutions`
   nicht erwähnen.
6. **Aufgaben vor dem Start:** `/api/klausur/{id}/inhalt` liefert erst ab Start der Klausur.
7. Uploads nur über `Services\Uploads`, Auslieferung nur über geschützte Routen.
8. Passwort-Mindestlänge 8 für Lehrkräfte/Admins.
9. CSP strikt: kein Inline-JS, keine `onclick=`. Seiten-JS unter `public/assets/js/`
   (unbundled, `'pageScripts' => [...]`) oder als Bundle (`'bundles' => ['x.js']`).
10. Datenzugriff auf Teilnahmen immer mit Bindung (`WHERE id = ? AND exam_id = ?`, bei
    Schüler:innen zusätzlich `AND user_id = ?`).
11. Zeit ist Serverzeit. Clientzeit dient nur der Anzeige und der Zuordnung von Offline-Schritten.

## Zustände einer Teilnahme

Einzige Stelle für Übergänge: `Services\ParticipationState::transition($participation, $to, $reason, $actorId)`.
Jeder Übergang schreibt `state_changed_at`, ggf. `resume_state` und ein Ereignis `state_changed`.

```
waiting ──start──▶ active ──Verstoß/lock──▶ locked ──release──▶ active
                     │  ──set_absent──▶ absent ──release──▶ active
                     │  ──pause──▶ paused ──resume──▶ active
                     │  ──Abgabe anfragen──▶ submit_requested ──reject──▶ active
                     │                                      └──approve──▶ submitted
                     └──submit (Lehrkraft)──▶ submitted ──reopen──▶ active
Gerätewechsel: jeder Zustand außer submitted ──▶ device_pending ──approve──▶ vorheriger
```

## Zeit

`Services\ExamClock::forParticipation($exam, $participation, $nowMs)` liefert Ende, Restzeit,
Pausenstatus. Formel:

```
Ende = Start + Dauer × (1 + Nachteilsausgleich/100) + Zusatzminuten
       + Gesamtpausen (exams.paused_seconds [+ laufende]) + Einzelpausen (participations.paused_seconds [+ laufende])
```
Bei Zeitende wird nichts gesperrt. Es gibt ein Signal (`time_up`), Änderungen danach sind Überziehung.

## Antworttypen

Server: `src/Services/TaskTypes/<Name>Type.php` implementiert `TaskType` (meist über
`BaseTaskType`); Registrierung automatisch. Browser: Widget und Aufgaben-Editor unter
`frontend/src/widgets/<name>.js`, registriert über `frontend/src/lib/widgets.js`.
Formate: `docs/DATENFORMATE.md`.

## Controller-Pattern (Pflicht)

```php
final class XyController extends Controller
{
    /** GET /klausuren/{id}/xy */
    public function show(array $params): string
    {
        $this->requireLogin();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_EDIT);
        return $this->render('pages/xy/show', ['title' => 'Xy', 'exam' => $exam]);
    }

    /** POST /api/xy */
    public function store(array $params): array
    {
        $this->requirePermission(P::XY);   // enthält requireLogin()
        $this->requireCsrf();
        $input = $this->jsonInput();
        // … validieren, $this->ctx->db->run(...), $this->ctx->audit->log('…', 'info', '…');
        return ['success' => true];
    }
}
```

Guards/Helfer aus `Controller`: `requireLogin()`, `requirePermission($p)`, `requireAdmin()`,
`requireStaff()`, `requireStudent()`, `requireCsrf()`, `render($tpl, $data, $layout = 'app')`,
`redirect($url)`, `flash($type, $msg)`, `jsonInput()`, `jsonError($msg, $status)`.

## Templates & Design-System

- Template je Seite unter `templates/pages/<modul>/name.php`; immer `'title'` mitgeben.
- Verfügbar: `$ctx`, `$auth`, `$csrf`, `$view` + übergebene Daten. Helfer: `e()`, `format_date()`,
  `format_datetime()`, `format_points()`, `display_name()`, `json_attr()`, `asset()`, `bundle()`.
- CSS-Bausteine aus `public/assets/css/app.css` (`page-header`, `card`, `stat-grid`, `btn-*`,
  `field`/`input`, `form-grid`, `table-wrap`/`data-table`, `badge-*`, `alert-*`, `tabs`,
  `grid-2/3`, `stack`, `cluster`, `empty-state`, `progress`, `modal`). Modul-CSS in eigener
  Datei `public/assets/css/<modul>.css`, eingebunden über `'pageStyles' => ['<modul>.css']`.
- Bestätigungen per `data-confirm="…"`, Modals per `data-open-modal`.
- Icons: Emoji. Akzentfarbe Indigo (`--primary`), Editoren dunkel (`--editor-*`).
- JSON-Daten für Bundles: `<script type="application/json" id="…"><?= json_encode(..., JSON_HEX_TAG|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) ?></script>`
  (wird nicht ausgeführt, CSP-konform).

## Module und Zuständigkeit

| Modul | Routen-Dateien | Controller / Services | Frontend |
|---|---|---|---|
| Kern & Auth | `00-auth`, `01-files` | Auth, Setup, Home, Health, File | `app.js`, `login-qr.js` |
| Verwaltung | `10` bis `19` | Users, Courses, AccessCards, GradingScales, Operators, Rooms, Settings, Retention, Permissions, AuditLog, Dashboard | `public/assets/js/*.js` |
| Klausuren & Aufgaben | `20` bis `29` | Exams, Tasks, Materials-Upload, Archive, ExamExport, Seb, Markdown-Vorschau, TaskTypes (Stufe 1) | `task-editor.js` |
| Live (Teilnahme, Pult) | `30` bis `39` | StudentExam, Pult, Material, Runner-Proxy; Services ParticipationState, ExamClock, AnswerStore, EventLog, SebGuard | `pult.js` |
| Schüleransicht | (nutzt 30–39) | | `exam.js`, `worker-python.js` |
| Korrektur | `40` bis `49` | Grading, Results, Pdf, AutoGrader | `grading.js` |
| Diagramme | `50` bis `59` | TaskTypes (Stufe 3) | `frontend/src/widgets/diagram-*` |
| Mikrowelt, GUI, Replay | `60` bis `69` | TaskTypes `robo` | `frontend/src/widgets/robo*`, `replay.js` |

## Tests

- `tests/Unit/` ohne Datenbank (Rechtekatalog, Routen-Guards, Lösungsleck, Uhr, Antworttypen).
- `tests/Integration/` gegen eine **eigene** Datenbank (`klausurwerk_test`), Schema aus `migrations/`,
  jeder Test in einer zurückgerollten Transaktion.
- `tests/e2e/` Playwright gegen den laufenden Compose-Stack.
- Ausführen: siehe `tests/README.md`.

## Deutsch & Stil

UI durchgehend Deutsch (Du-Form für Schüler:innen, neutral für Lehrkräfte).
`declare(strict_types=1);` in jeder PHP-Datei, typisierte Signaturen, kurze deutsche Kommentare.
Keine Gedankenstriche (—) in Texten und Kommentaren; Komma, Doppelpunkt oder Klammern verwenden.
