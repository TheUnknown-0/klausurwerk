# Klausurwerk-Runner

Führt Schülercode abgeschottet aus: Java 21 und 25, Python 3 (nur für Tests und Robo)
und SQLite (nur für Tests). Er ist ein eigener Container, hängt nur am internen Netz
`runner_net` (kein Internet) und wird von der App über `App\Services\RunnerClient`
angesprochen. Schnittstelle: `docs/API.md`, Abschnitt 5.

## Aufbau

```
runner/
  Dockerfile            Multi-Stage: Temurin 21 und 25 (nach /opt/jdk21, /opt/jdk25) auf ubuntu:24.04,
                        python3 (stdlib, kein pip), util-linux (prlimit); Benutzer 10001, Port 8080
  kwrunner/
    main.py             Einstieg (python3 -I -B), SIGTERM-sauber, Zombie-Reaper
    config.py           Umgebungsvariablen und Bibliothekspfade
    httpd.py            ThreadingHTTPServer, Routing (Registry), Token-Prüfung (hmac.compare_digest)
    runs.py             /runs: Warteschlange, Übersetzen, Starten, Abruf, Aufräumen
    testexec.py         /tests: synchron, je Test isoliert (io, java_main, python_assert, sql_result)
    sandbox.py          Kindprozess mit rlimits (prlimit), Wächter-Thread, Ausgabe-Chunks
    javatools.py        javac/java-Kommandos, Fehler-Parser, Startklasse (Paket beachten)
    boot_py.py          Startskript im Kindprozess: Python-Lauf, python_assert, SQL-Vergleich
    compare.py          exact, trim, ignore_whitespace, contains, regex
    workspace.py        Dateinamen/Größen prüfen, Dateien schreiben, Limits begrenzen
    modules/            Erweiterungspunkt für weitere Routen (z. B. /robo)
  libs/java/            .jar-Dateien und Klassenordner: kommen beim Übersetzen und Ausführen auf den Klassenpfad
  libs/python/          Python-Module: kommen in sys.path der Läufe (python3 -I ignoriert PYTHONPATH)
  tests/test_runner.py  Integrationstests gegen den echten Container
```

### Erweiterungspunkt (`/robo`)

Eine Datei `kwrunner/modules/robo.py` mit

```python
def register(registry, ctx):
    registry.add("POST", r"/robo", handler)

def handler(ctx, req):          # req.json(), req.query, req.match
    return 200, {"ok": True}
```

wird beim Start automatisch geladen (Token-Prüfung und Fehlerbehandlung übernimmt der
Server; `RunnerError(status, "Meldung")` aus `kwrunner.sandbox` erzeugt eine saubere
Fehlerantwort). Hilfen: `ctx.cfg` (Konfiguration, `java_libs()`, `python_libs()`),
`ctx.runs.acquire_slot()/release_slot()` (Parallelitätsgrenze), `kwrunner.sandbox.Proc`,
`kwrunner.javatools.run_compile()/java_run_argv()`. Ein defektes Modul wird übersprungen
(Meldung auf stderr), der Runner startet trotzdem.

## API-Verhalten

* `GET /healthz`: `ok`, ohne Token.
* Alles andere braucht `X-Runner-Token`. Ohne gesetztes `RUNNER_TOKEN` antwortet der
  Runner mit 503, bei falschem Token mit 401.
* `POST /runs`: `language` (`java`, `python`), `java_version` (`21`, `25`, leer = 21),
  `files`, `main_file`, `mode` (`run`, `syntax`), `stdin`, `limits`. `stdin: null` oder
  leer = interaktiv (harte Wandzeit 120 s); nicht leer = wird vorgegeben, dann zählt
  die Wandzeit `seconds + 5`. Antwort `{ "run_id": "r_..." }`.
* `GET /runs/{id}?offset=n`: wie `docs/API.md` 2., zusätzlich `status`
  (`queued`, `compiling`, `running`, `finished`), `compile_ok`, `message`,
  `killed_reason` (`wall`, `cpu`, `threads`, `memory`, `disk`, `output`, `stopped`).
  `output` ist eine Liste `{stream, text}`; `stream` ist `stdout`, `stderr` oder
  `system` (Hinweise des Runners wie "Zeitlimit überschritten", deutsch).
  `compile_errors`: `{file, line, column, message, detail}` (javac auf Deutsch).
* `POST /runs/{id}/stdin` (`{"text": "..."}`), `POST /runs/{id}/stop`.
* `POST /tests`: `language`, `java_version`, `files`, `main_file`, `tests`, `sql_setup`,
  `limits` (`seconds`, `memory_mb`, `total_seconds`), `reveal`. Antwort
  `{ "results": [ {name, ok, message, stdout, stderr, duration_ms} ] }`.
  Ohne `reveal: true` stehen in den Meldungen weder erwartete Ausgaben noch Zeilen und
  Spalten des Musterergebnisses (für Schüler:innen); Lehrkräfte setzen `reveal`.
  Testtypen: Java `io`, `java_main`; Python `io`, `python_assert`; SQL `sql_result`.
* Läufe ohne Abruf für 60 s werden beendet und verworfen; Arbeitsverzeichnisse
  (`/work/<id>`, `/tmp/<id>`) verschwinden mit dem Ende des Laufs.

## Grenzen

| Was | Grenze |
|---|---|
| Dateien | höchstens 20, zusammen 1 MB, Namen `[A-Za-z0-9_.-]`, kein Pfad |
| CPU-Zeit je Lauf | `RUNNER_MAX_SECONDS` (Wächter plus `RLIMIT_CPU`); Anfrage darf nur senken |
| Wandzeit | interaktiv 120 s (`RUNNER_INTERACTIVE_SECONDS`), mit vorgegebener Eingabe und bei Tests CPU-Zeit + 3 bis 5 s |
| Java-Heap | `-Xmx` = `RUNNER_MAX_MEMORY_MB`; echter Speicher (RSS) höchstens Heap + 384 MB, sonst Abbruch |
| Threads/Prozesse je Lauf | 100 Threads, 40 Prozesse (Wächter, alle 50 ms), dazu `RLIMIT_NPROC` 300 |
| Ausgabe | 1 MB (Tests: 256 KB), danach Abbruch mit Hinweis |
| Platte je Lauf | 64 MB und 4000 Einträge (Wächter), einzelne Datei 10 MB (`RLIMIT_FSIZE`) |
| Parallelität | `RUNNER_MAX_PARALLEL` laufende Läufe, weitere warten (Status `queued`), höchstens 64 aktive Läufe, sonst HTTP 429 |
| Umgebung der Kindprozesse | leer bis auf PATH, HOME=`/tmp/<id>`, TMPDIR, LANG; der Token wird nie weitergegeben |

Java läuft mit `-XX:+ExitOnOutOfMemoryError`, `-Xss8m`, SerialGC, nur C1, ohne Perf-Daten.
Die JVM verwendet kein `user.language=de`, damit `Scanner.nextDouble` und `String.format`
mit Punkt rechnen; nur `javac` meldet Fehler auf Deutsch.

### Bekannte Grenzen der Isolation (ehrlich gesagt)

* Alle Läufe teilen sich eine UID (der Container hat kein `CAP_SETUID`). Ein Lauf könnte
  über `/proc` und `/work` die Dateien eines gleichzeitig laufenden fremden Laufs lesen.
  Die Verzeichnisse sind 0700 und die IDs zufällig, ein gezielter Angriff ist aber
  möglich. Härtung wäre ein Sandbox-Werkzeug mit eigenen Namensräumen (nicht eingebaut).
* `RLIMIT_NPROC` gilt je UID: Eine Thread- oder Fork-Bombe kann für wenige Zehntel
  Sekunden gleichzeitig laufende andere Läufe stören (Threadanlage schlägt dort fehl),
  bis der Wächter sie beendet. Der Container selbst bleibt unversehrt.
* `python_assert`: Schülercode und Prüfung laufen im selben Prozess. Wer gezielt den
  Ergebnis-Deskriptor beschreibt, kann ein "bestanden" fälschen. Für Java gilt das nicht
  (Ergebnis = Exit-Code des Testprozesses).
* `compare: regex` verwendet Pythons `re` ohne Zeitlimit (Muster stammen von Lehrkräften).

## Tests

Voraussetzung: Docker und Python 3 auf dem Entwicklungsrechner. Aus dem Projektordner:

```sh
python -m unittest discover -s runner/tests -v
```

Das Modul baut das Image (`docker build -t klausurwerk-runner-dev runner`), startet es
einzeln mit denselben Einschränkungen wie in `compose.yaml`

```sh
docker run -d --rm --name kw-runner-test --read-only --tmpfs /tmp:size=256m --tmpfs /work:size=512m \
  --cap-drop ALL --security-opt no-new-privileges --pids-limit 512 -m 2g --cpus 2 \
  -e RUNNER_TOKEN=test -p 18080:8080 klausurwerk-runner-dev
```

und stoppt den Container danach wieder. Mit `-k Limit` laufen nur die Grenzentests.
Gegen einen schon laufenden Runner: `KW_RUNNER_URL=http://127.0.0.1:18080 KW_RUNNER_TOKEN=test`
setzen (dann wird nichts gebaut oder gestartet). Der Test `test_verlassener_lauf_wird_beendet`
wartet absichtlich 75 s.

Der PHP-Client hat eigene Tests ohne Runner: `tools/dev.sh unit` (`tests/Unit/RunnerClientTest.php`).
