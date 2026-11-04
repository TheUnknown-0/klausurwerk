# Interne Schnittstellen (JSON)

Alle Endpunkte unter `/api/` antworten mit JSON. Schreibende Anfragen (POST)
brauchen den Header `X-CSRF-Token` (setzt `frontend/src/lib/api.js` automatisch).
Fehler: HTTP-Status ≥ 400 und `{ "success": false, "error": "…" }`.
Erfolg: HTTP 200 und `{ "success": true, … }`.

Zeiten: Millisekunden seit Epoche (`*_ms`) in Serverzeit. Der Client berechnet
`offset = server_time_ms - Date.now()` bei jedem Herzschlag und rechnet damit.

Datenformate von Aufgaben, Antworten und Ereignissen: `docs/DATENFORMATE.md`.

---

## 1. Schüleransicht

Seiten (HTML):

| Pfad | Zweck |
|---|---|
| `GET /meine-klausuren` | Liste: offene Warteräume, laufende Klausuren, Übungen, beendete |
| `GET /klausur/{id}` | Klausurseite (Layout `exam`, Bundle `exam.js`). Beitritt passiert hier. |
| `GET /klausur/{id}/material/{mid}` | Material-Datei (Bild/PDF); nur Teilnehmende ab Start und Lehrkräfte mit `view` |
| `GET /klausuren/{id}/vorschau` | Lehrkräfte: gleiche Seite im Vorschaumodus (keine Teilnahme, nichts wird gespeichert) |

Die Klausurseite enthält einen JSON-Bootstrap-Block:

```html
<div id="exam-app"></div>
<script type="application/json" id="exam-boot">{ … }</script>
```

```json
{
  "exam_id": 12,
  "mode": "exam",
  "preview": false,
  "title": "Klausur Q1: Listen",
  "user": { "name": "Mia Muster" },
  "api_base": "/api/klausur/12",
  "needs_access_code": false,
  "seb": { "required": false, "detected": false },
  "assets": {
    "pyodide": "/assets/vendor/pyodide/",
    "sqljs": "/assets/vendor/sqljs/",
    "aids": "/assets/aids/"
  }
}
```

### 1.1 `POST /api/klausur/{id}/beitreten`

Legt die Teilnahme an (Zustand `waiting`) bzw. nimmt sie wieder auf.
Request: `{ "access_code": "AB12CD" | null, "client": { "ua": "…", "screen": { "w": 1920, "h": 1080 }, "seb": false } }`

Gerätebindung: Der Server setzt ein HttpOnly-Cookie `kw_device_<examId>` (zufälliges Token,
gespeichert wird nur der SHA-256-Hash). Kommt dieselbe Person mit einem anderen Token, während
die Teilnahme schon läuft, wird sie `device_pending` und das Pult bekommt eine Anfrage.

Response:
```json
{ "success": true, "participation": { "id": 55, "state": "waiting" }, "server_time_ms": 1767000000000 }
```
Fehler: 403 (nicht im Kurs / falscher Klausurcode / SEB fehlt), 409 (Klausur nicht im Warteraum oder läuft nicht).

### 1.2 `POST /api/klausur/{id}/bereit`

Nach Klick auf „Bereit“ (Ton und Vollbild freigeschaltet). Request: `{}`.
Response: `{ "success": true }`.

### 1.3 `GET /api/klausur/{id}/inhalt`

Aufgaben und gespeicherte Antworten. **Erst ab Start** (Klausurstatus `running`/`paused`,
Teilnahme nicht `waiting`); vorher 409. Vorschau (Lehrkraft): immer.

```json
{
  "success": true,
  "exam": { "id": 12, "title": "…", "mode": "exam", "settings": { … ExamSettings::forStudent … } },
  "tasks": [
    {
      "id": 101, "parent_id": null, "number": "1", "title": "Listen",
      "body_html": "<p>…</p>", "points": 10, "operator": "implementieren", "afb": "II",
      "answer_type": null, "config": {}, "files": [], "children": [
        {
          "id": 102, "parent_id": 101, "number": "1a", "title": "",
          "body_html": "…", "points": 4, "operator": null, "afb": null,
          "answer_type": "code",
          "config": { … studentConfig … },
          "files": [ { "name": "Main.java", "content": "…", "readonly": false } ],
          "children": []
        }
      ]
    }
  ],
  "answers": { "102": { "content": { … }, "version": 7, "flagged": false } },
  "materials": [ { "id": 5, "name": "Aufgabenblatt.pdf", "url": "/klausur/12/material/5", "is_aid": true, "mime": "application/pdf" } ],
  "operators": [ { "name": "erläutern", "afb": "II", "description": "…" } ],
  "last_seq": 340
}
```
`config` ist `TaskType::studentConfig()` (gemischt, mit gerenderten `text_html`-Feldern für Optionen).
`operators` nur, wenn `settings.aids.operators` aktiv ist (sonst leere Liste).

### 1.4 `POST /api/klausur/{id}/puls` (Herzschlag, alle 2 s)

Request:
```json
{
  "client_state": { "fullscreen": true, "visible": true, "focused": true, "seb": false,
                    "screen": { "w": 1920, "h": 1080 }, "online_since_ms": 1767000000000,
                    "pending_saves": 0, "current_task_id": 102 },
  "announcement_cursor": 0
}
```
Response:
```json
{
  "success": true,
  "server_time_ms": 1767000002000,
  "exam_status": "running",
  "state": "active",
  "lock_reason": null,
  "clock": {
    "started": true, "ends_at_ms": 1767005400000, "remaining_ms": 5398000,
    "paused": false, "time_up": false, "unlimited": false
  },
  "announcements": [ { "id": 3, "message": "Aufgabe 2b: gemeint ist x.", "created_at_ms": 1767000001000, "personal": false } ],
  "announcement_cursor": 3,
  "hand_raised": false,
  "open_requests": [ "release" ],
  "last_seq": 340
}
```
`state` ist maßgeblich: Der Client zeigt Sperrbildschirme für `locked`, `absent`, `paused`,
`submit_requested`, `device_pending`, die Bestätigungsseite für `submitted`.

### 1.5 `POST /api/klausur/{id}/speichern`

Autosave. Der Client schickt Pakete in Reihenfolge; jedes Paket enthält den vollständigen
neuen Inhalt jeder geänderten Aufgabe und die Replay-Schritte.

```json
{
  "items": [
    { "seq": 341, "task_id": 102, "client_ts": 1767000001500, "kind": "ops",
      "payload": { "file": "Main.java", "changes": [ … ] }, "is_paste": false },
    { "seq": 342, "task_id": 103, "client_ts": 1767000001700, "kind": "value", "payload": { "selected": ["b"] } }
  ],
  "contents": {
    "102": { "content": { "files": [ … ] }, "flagged": false },
    "103": { "content": { "selected": ["b"] }, "flagged": true }
  }
}
```
Regeln (Server, `Services\AnswerStore`):
- Schritte mit `seq ≤ last_seq` werden ignoriert (doppelt gesendet), Lücken sind erlaubt
  (verlorene Pakete), `last_seq` wird auf das Maximum gesetzt.
- Gespeichert wird nur in Zustand `active`. In anderen Zuständen nur Schritte, deren
  `client_ts` (mit Offset korrigiert) vor `participations.state_changed_at` + 2 s liegt
  (Änderungen, die vor der Sperre offline entstanden). Danach 409.
- Nach `submitted`: immer 409.
- Nach Zeitende: gespeichert, aber `is_overtime = 1` und `answers.has_overtime = 1`.
- Inhalt wird mit `TaskType::normalizeContent()` geprüft; ungültig → 422 für das Paket.

Response: `{ "success": true, "ack_seq": 342, "versions": { "102": 8, "103": 3 }, "server_time_ms": … }`

### 1.6 `POST /api/klausur/{id}/ereignis`

Request: `{ "events": [ { "type": "fullscreen_exit", "client_ts": 1767000001000, "details": {} } ] }`
Erlaubte `type`: siehe DATENFORMATE.md Abschnitt 4 (nur die Client-Ereignisse).
Verstöße (`fullscreen_exit`, `visibility_hidden`, `window_blur` über der Schwelle, `second_tab`)
sperren bei `lock_on_violation` sofort (`active` → `locked`). Im Modus `practice` nichts sperren.
Response: `{ "success": true, "state": "locked", "lock_reason": "Vollbild verlassen" }`

Zusätzlich für die Bildschirmfreigabe: `screen_share_started {surface}`, `screen_share_stopped {}`,
`screen_share_denied {reason}`, `screen_share_wrong_surface {surface}`. Die letzten drei sind Alarme im
Pult, sperren aber nie.

### 1.7 `POST /api/klausur/{id}/anfrage`

Request: `{ "type": "hand" | "release" | "submit", "cancel": false }`
- `hand`: Hand heben/senken (`cancel: true` senkt).
- `release`: nach Sperre/Abwesenheit um Freigabe bitten.
- `submit`: Abgabe anfragen (`active` → `submit_requested`), nur wenn `allow_self_submit`.
  `cancel: true` zieht zurück (`submit_requested` → `active`).
Response: `{ "success": true, "state": "…" }`

### 1.8 `POST /api/klausur/{id}/datei` (Stufe 3, Antworttyp `drawing` mit Upload)

Multipart: `task_id`, `file` (PNG/JPEG/PDF, ≤ 10 MB). Response `{ "success": true, "file": { "id", "name", "url" } }`.
Auslieferung: `GET /klausur/{id}/antwortdatei/{fid}`.

---

## 2. Code-Ausführung (Java im Runner)

Python und SQL laufen im Browser. Java läuft auf dem Server; die App leitet an den
Runner weiter (`Services\RunnerClient`) und begrenzt pro Person (höchstens 1 laufender
Lauf, höchstens 30 Starts pro Minute).

### `POST /api/ausfuehren`
Request:
```json
{ "exam_id": 12, "task_id": 102, "language": "java", "java_version": "21",
  "files": [ { "name": "Main.java", "content": "…" } ], "main_file": "Main.java",
  "mode": "run", "stdin": null, "gui": false }
```
`mode`: `run` (kompilieren + starten, interaktiv) | `syntax` (nur kompilieren).
Der Server ergänzt schreibgeschützte `task_files` selbst (die Schüleransicht schickt nur
bearbeitbare Dateien) und prüft, dass `run_mode` der Aufgabe den Modus erlaubt.
Lehrkräfte (Vorschau, Aufgaben-Editor) dürfen ohne `exam_id`/`task_id` ausführen.

Response: `{ "success": true, "run_id": "r_8f3a…" }`

### `GET /api/ausfuehren/{runId}?offset=0`
```json
{ "success": true, "output": [ { "stream": "stdout", "text": "Gib eine Zahl ein: " } ],
  "offset": 1, "finished": false, "exit_code": null, "timed_out": false,
  "compile_errors": [ { "file": "Main.java", "line": 3, "column": 5, "message": "';' expected" } ],
  "gui_frames": [] }
```
Der Client fragt alle 250 ms nach, bis `finished`.

### `POST /api/ausfuehren/{runId}/eingabe` Request `{ "text": "5\n" }`
### `POST /api/ausfuehren/{runId}/stopp`

---

## 3. Pult (Lehrkräfte, Fähigkeit `monitor`)

Seite: `GET /klausuren/{id}/pult` (Bundle `pult.js`).

### 3.1 `GET /api/pult/{id}/stand?cursor=<event_id>`

Alle 1 bis 2 s.
```json
{
  "success": true,
  "server_time_ms": 1767000002000,
  "exam": {
    "id": 12, "status": "running", "mode": "exam", "started_at_ms": 1767000000000,
    "duration_minutes": 90, "paused": false, "settings": { … }, "room": { "id": 2, "name": "R 204", "cols": 8, "rows": 6 }
  },
  "participants": [
    {
      "id": 55, "user_id": 301, "name": "Mia Muster", "state": "active", "lock_reason": null,
      "online": true, "last_seen_ms_ago": 1200, "client_state": { "fullscreen": true, "visible": true, "focused": true, "seb": false },
      "progress": { "answered": 4, "flagged": 1, "total": 7 },
      "clock": { "ends_at_ms": 1767005400000, "remaining_ms": 5398000, "time_up": false, "extra_minutes": 0, "accommodation_percent": 25 },
      "hand_raised": false,
      "open_requests": [ { "id": 9, "type": "release", "created_at_ms": 1767000001500 } ],
      "last_save_ms_ago": 3000, "pending": false,
      "position": { "col": 2, "row": 1 },
      "alarm": false
    }
  ],
  "not_joined": [ { "user_id": 302, "name": "Ben Beispiel", "position": null } ],
  "events": [ { "id": 901, "participation_id": 55, "name": "Mia Muster", "type": "fullscreen_exit", "severity": "alarm", "details": {}, "created_at_ms": 1767000001000, "actor": null } ],
  "cursor": 901
}
```
`alarm` = seit dem letzten Abruf ein Alarm-Ereignis bei dieser Person (für Ton und rote Kachel).
`not_joined` = Kursmitglieder ohne Teilnahme.

### 3.2 `POST /api/pult/{id}/aktion`

Request: `{ "action": "…", "participation_ids": [55, 56] | "all", "minutes": 10, "message": "…", "request_id": 9 }`

| action | Wirkung |
|---|---|
| `open_lobby` | Klausur `ready`/`draft` → `lobby` |
| `start` | `lobby` → `running`, setzt `started_at`; alle `waiting` → `active` |
| `pause_all` / `resume_all` | `running` ⇄ `paused` (Uhr steht für alle) |
| `release` | `locked`/`absent`/`device_pending` → vorheriger Zustand (meist `active`) |
| `lock` | `active` → `locked` (manuell, `message` = Grund) |
| `set_absent` | `active` → `absent` (Toilette); Uhr steht nur bei `toilet_pauses_clock` |
| `pause` / `resume` | Einzelpause |
| `add_time` | `minutes` (auch negativ, nicht unter 0 Zusatzzeit) |
| `submit` | Abgabe durch Lehrkraft (alle Zustände außer `submitted`) |
| `approve_submit` / `reject_submit` | Antwort auf Abgabe-Anfrage |
| `approve_device` / `reject_device` | Gerätewechsel |
| `ack_hand` | Hand quittieren |
| `reopen` | `submitted` → `active` (Abgabe zurücknehmen, mit Begründung `message`) |
| `announce` | Durchsage an `participation_ids` oder `"all"` |
| `finish` | Klausur `running`/`paused` → `finished` (nur wenn alle `submitted`, sonst 409 mit Liste) |

Response: `{ "success": true, "affected": 2, "errors": [ { "participation_id": 57, "error": "…" } ] }`

### 3.3 `GET /api/pult/{id}/antworten/{participationId}`
Live mitlesen: `{ "success": true, "answers": { "102": { "content": {…}, "version": 8, "updated_at_ms": …, "has_overtime": false, "flagged": false } }, "tasks": [ … wie /inhalt, ohne Mischen … ] }`

### 3.4 `GET /api/pult/{id}/replay/{participationId}?task_id=102&after_seq=0&limit=2000`
`{ "success": true, "ops": [ { "seq", "task_id", "client_ts", "server_ts_ms", "kind", "payload", "is_paste", "is_overtime" } ], "has_more": false }`

### 3.5 `POST /api/pult/{id}/sitzplan`
`{ "positions": [ { "user_id": 301, "col": 2, "row": 1 } ] }` → speichert in `room_positions` für den Raum der Klausur (400 ohne Raum).

### 3.6 `GET /api/pult/{id}/ereignisse/{participationId}`
Vollständiges Protokoll einer Person (für die Detailansicht).

### 3.7 Live-Wand (`live`, `bildschirm`, `fokus`)

Antworten dieser Endpunkte haben die Form `{ "ok": true, "data": { … } }`, Fehler wie üblich
`{ "success": false, "error": "…" }`. Alle Daten liegen nur im Arbeitsspeicher (`Services\LiveStore`).

- `GET /api/pult/{id}/live` → `data: { participants: [ { participation_id, live: { task_id, view, file, cursor_line, code_excerpt, excerpt_start_line, console_tail, client_ms, received_ms } | null, live_age_ms | null, screen: { ts_ms, width, height, surface } | null, screen_age_ms | null } ], settings: { live_view, screen_share } }`.
  Einträge älter als 120 s erscheinen als `null`.
- `GET /api/pult/{id}/bildschirm/{participationId}` → `image/jpeg`, `Cache-Control: no-store`; 404 ohne (oder mit veraltetem) Bild.
- `POST /api/pult/{id}/fokus` `{ "participation_id": 301 | null }` → setzt den Fokus für 30 s (das Pult wiederholt alle 10 s, solange die Großansicht offen ist); `null` hebt auf. 404 für Teilnahmen einer anderen Klausur. Antwort `data: { participation_id }`.

---

## 4. Lehrkräfte: Hilfs-Endpunkte

| Pfad | Zweck |
|---|---|
| `POST /api/markdown/vorschau` | `{ "markdown": "…", "exam_id": 12 }` → `{ "html": "…" }` (gleiches Rendering wie in der Klausur) |
| `GET /api/benutzer/suche?q=…&role=teacher` | Personensuche für Auswahlfelder |

---

## 5. Runner (intern, nur App ↔ Runner)

Basis `http://runner:8080`, Header `X-Runner-Token: <RUNNER_TOKEN>`.

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/healthz` | `ok` |
| POST | `/runs` | Lauf starten: `{ "language": "java", "java_version": "21", "files": [...], "main_file": "Main.java", "mode": "run|syntax", "stdin": null, "gui": false, "limits": { "seconds": 10, "memory_mb": 256 } }` → `{ "run_id" }` |
| GET | `/runs/{id}?offset=n` | Ausgabe ab Index n, Status (Format wie 2.) |
| POST | `/runs/{id}/stdin` | `{ "text": "…" }` |
| POST | `/runs/{id}/stop` | Lauf beenden |
| POST | `/tests` | Versteckte Tests synchron ausführen: `{ "language": "java|python|sql", "java_version", "files": [...], "main_file", "tests": [ … ], "sql_setup": "…", "limits": {…} }` → `{ "results": [ { "name", "ok", "message", "stdout", "stderr", "duration_ms" } ] }` |
| POST | `/robo` | Robo-Programm in Testwelt ausführen (Stufe 4): `{ "language", "files", "main_file", "world", "goal" }` → `{ "ok", "trace": [...], "final_world": {...}, "message" }` |

Läufe ohne Abruf für 60 s werden beendet und verworfen.

---

## 6. Ergänzungen aus der Umsetzung (verbindlich)

**Schüleransicht**
- `POST /bereit` nimmt optional `{ "details": { fullscreen_ok, sound_ok, latency_ms, seb, screen, window, dpr } }` (Technik-Check) und speichert es im Ereignis `ready`.
- `POST /puls` liefert zusätzlich `state_since_ms` und `submitted_at_ms`.
- `POST /speichern` nimmt `client_now_ms` (Date.now() des Clients). Der Server berechnet daraus den Offset für Offline-Schritte. Die Antwort enthält zusätzlich `rejected` (Liste verworfener Schritte mit Grund).
- In der Vorschau (`preview: true`) ruft die Schüleransicht nur `/inhalt` und `/api/ausfuehren` auf.
- `ctx.env` der Widgets enthält `examMode` (`exam` | `practice` | `probe`). Beispieltests zeigt das Code-Widget nur in `practice` und in der Vorschau.
- Seiten mit Antwort-Widgets binden `exam.css` und `code.css` ein.

**Pult**
- Aktion `reject_release` (Freigabe-Anfrage ablehnen, Person bleibt gesperrt).
- `exam.base_ends_at_ms`, `exam.base_remaining_ms` (Uhr ohne persönliche Zuschläge), `participants[].state_since_ms`, `participants[].ready`, `participants[].username`.
- `GET /antworten/{pid}?tasks=0` liefert nur die Antworten ohne Aufgabenbaum.
- Übergang `device_pending` → `submitted` ist erlaubt (Abgabe durch die Lehrkraft).
- Sitzplätze (`room_positions.col`, `.row`) sind **nullbasiert** (0 bis cols-1 bzw. rows-1).

**Personensuche**
- `GET /api/benutzer/suche?q=&role=teacher|student&course_id=` (Lehrkräfte/Admins, höchstens 20 Treffer):
  `{ "success": true, "users": [ { "id", "name", "username", "role", "kurse": [ { "id", "name" } ] } ] }`.
  `role=teacher` liefert Lehrkräfte und Admins.
- `GET /api/benutzer/benutzername?vorname=&nachname=` → `{ "success": true, "username": "mia.muster2" }`.

**Runner**
- `GET /runs/{id}` liefert zusätzlich `status` (`queued`, `compiling`, `running`, `finished`), `compile_ok`, `message`, `killed_reason`. `output[].stream` kennt neben `stdout`/`stderr` auch `system` (Hinweise des Runners, z. B. Zeitlimit). `compile_errors[]` hat zusätzlich `detail`.
- `POST /runs`: `stdin` null oder leer = interaktiv (harte Wandzeit 120 s), sonst vorgegeben, danach EOF.
- `POST /tests`: optional `reveal` (bool, Standard false; nur für Lehrkräfte-Ansichten true: dann enthalten Meldungen erwartete Ausgaben) und `limits.total_seconds` (Gesamtbudget, höchstens 300).
- Erweiterungen: `runner/kwrunner/modules/<name>.py` mit `register(registry, ctx)`; Bibliotheken in `runner/libs/java` (Klassenpfad) und `runner/libs/python` (sys.path).

**SQL-Beispieltests** vergleichen die letzte Ergebnistabelle als Text: Werte je Zeile mit `|` getrennt, `NULL` als `NULL`, keine Kopfzeile.

## 7. Korrektur, Diagramme, Mikrowelt (Stufen 2 bis 4)

- Korrektur, Ergebnisse, Rückgabe: alle Routen und JSON-Formate in [KORREKTUR.md](KORREKTUR.md).
- Robo-Gitterwelt, Java-GUI (`kw.gui`, Feld `gui_frames`), Replay: [stufe4-robo-gui-replay.md](stufe4-robo-gui-replay.md).
- `POST /api/klausur/{id}/vorschaubild` `{ "task_id", "png": "data:image/png;base64,…" }`: PNG-Vorschau einer
  Zeichenaufgabe (höchstens 2 MB, nur zu einer gespeicherten Antwort, nur im Zustand `active`). Die
  Schüleransicht sendet sie höchstens alle 10 s je Aufgabe und vor der Abgabe.
- Live-Wand (Schüler:innen, nur eigene Teilnahme, CSRF; Teilnahme `active`/`locked`/`paused` und Klausur `running`/`paused`, sonst 409):
  - `POST /api/klausur/{id}/live` `{ task_id: int|null, view: "task"|"overview"|"aids"|…, file: string|null, cursor_line: int|null, code_excerpt, excerpt_start_line: int, console_tail, client_ms: int }`
    → `{ "ok": true, "data": { "focus": bool, "screen_interval_ms": 5000 } }` (bei Fokus 1000). `code_excerpt` wird auf 60 Zeilen bzw. 4000 Zeichen
    (Anfang), `console_tail` auf 20 Zeilen bzw. 2000 Zeichen (Ende) gekürzt. 409, wenn `live_view` aus ist; 422 bei ungültigen Typen; 429 über 2 Anfragen je Sekunde.
  - `POST /api/klausur/{id}/bildschirm` `{ jpeg: "data:image/jpeg;base64,…", width, height, surface: "monitor"|"window"|"browser"|"unknown" }`
    → wie oben. Höchstens 600 KB Bilddaten (413), Signatur `FF D8 FF` (422), 409, wenn `screen_share` aus ist, 429 über 2 je Sekunde.
- `POST /speichern` liefert zusätzlich `invalid` (`{ task_id: Meldung }`): nur diese Antworten wurden
  zurückgewiesen, alle anderen Schritte und Inhalte des Pakets sind gespeichert.
