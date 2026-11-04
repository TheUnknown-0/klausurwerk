# Gestaltung: „Klausurbogen“

Klausurwerk sieht aus wie das, worum es geht: eine Klausur auf Papier. Keine generische
App-Optik (keine bunten Verläufe, keine Pillen-Knöpfe überall, keine Emoji-Icons).

## Idee

| Element | Vorbild | Umsetzung |
|---|---|---|
| Fläche | Karopapier im Schulheft | `body` mit feinem 5-mm-Raster (`--grid-line`), Bögen (`.card`) in `--surface` |
| Akzent | Füllertinte Königsblau | `--primary` für alles, was man tut (Knöpfe, Links, aktive Zustände) |
| Korrektur und Alarm | Rotstift der Lehrkraft | `--danger` nur für Fehler, Sperre, Alarm, Korrekturanmerkungen |
| Nebensächliches | Bleistift | `--ink-faint` |
| Rand | roter Heftrand | `--margin-line` an Seitenleiste und Deckblatt (Anmeldung) |
| Kennzahlen | Punktekästchen auf dem Klausurkopf | `.stat-card` mit Eselsohr, Zahl in der Serife |
| Marken | Stempel | `.badge`: Mono, Großbuchstaben, dünner Rahmen in Textfarbe |
| Tabellen | liniertes Papier | Linien in `--rule-line`, Kopf in Mono-Kapitälchen |
| Dunkelmodus | Schultafel | Schiefergrün, Kreideweiß, blaue und rote Kreide |

## Schrift

- Überschriften: **Newsreader** (Buchserife), Gewicht 500 bis 600. `h1` groß, ruhig.
- Text und Bedienung: **Atkinson Hyperlegible Next**, entworfen für Lesbarkeit (wichtig für
  Schüler:innen unter Prüfungsdruck).
- Code, Kennungen, Zeitstempel, Abschnittsüberschriften in Navigation und Tabellenköpfen:
  **IBM Plex Mono** (`--font-mono`), klein, Großbuchstaben, Sperrung `.1em`.
- Zahlen in Tabellen und Uhren: `font-variant-numeric: tabular-nums` (Klasse `.num`).

## Regeln für jedes Modul

1. **Farben nur über Variablen** aus `app.css` (`--ink`, `--primary`, `--danger` …). Keine
   eigenen Hex-Werte, außer in Diagramm-Zeichenflächen (die sind immer „Papier weiß, Tinte
   schwarz“) und im Editor (`--editor-*`).
2. **Keine Emoji als Icons.** In PHP `<?= icon('lock') ?>`, in JS `icon('lock')` aus
   `frontend/src/lib/dom.js`. Verfügbare Namen: siehe `public/assets/img/icons.svg`
   (`id="i-<name>"`). Ein Icon steht vor dem Text, nicht statt des Textes; reine
   Icon-Knöpfe brauchen `aria-label`. Pfeile im Fließtext (→, ←) sind Text und dürfen bleiben.
3. **Ecken klein** (`--radius-sm` 4 px, `--radius` 6 px, `--radius-lg` 8 px), keine runden Pillen
   außer bei Avatar und Schaltern. **Schatten flach** (`--shadow`), keine weichen
   Glow-Effekte, keine Farbverläufe auf Bedienelementen.
4. **Hierarchie durch Typografie und Linien**, nicht durch Farbflächen: Abschnittstitel in der
   Serife, Trennung durch `--rule-line`, Beschriftungen in Mono-Kapitälchen.
5. **Zustände** (Pult-Kacheln, Speichern, Abgabe) mit Farbe UND Form/Text (Barrierefreiheit):
   z. B. gesperrt = roter Rand + Schloss-Icon + Wort „Gesperrt“.
6. **Bewegung sparsam**: Bögen und Kopf erscheinen beim Laden leicht ansteigend (`kw-rise`,
   gestaffelt). Keine Dauerschleifen-Animationen außer Alarm (Pult) und laufender Ausführung.
   `prefers-reduced-motion` wird global beachtet.
7. **Schüleransicht ist ruhiger als die Verwaltung**: viel Weißraum, Aufgabentext wie auf dem
   Bogen (Serife für Aufgabentitel, Text in Atkinson 17 px, Zeilenlänge höchstens etwa 72 Zeichen),
   Uhr groß in der Serife mit Ziffern in Tabellenbreite.
8. Hell und dunkel immer beide prüfen.

## Bausteine (app.css)

`page-header` (mit `page-eyebrow` in Mono), `card` (`card-header`, `card-body`, `card-footer`),
`stat-grid`/`stat-card`, `btn` (`btn-primary`, `btn-danger`, `btn-danger-ghost`, `btn-ghost`,
`btn-sm`, `btn-lg`), `field`/`input`/`hint`, `form-grid`, `switch`, `table-wrap`/`data-table`,
`badge-*`, `alert-*`, `tabs`/`tab`, `empty-state`, `progress`, `modal`, `menu`, `grid-2/3`,
`stack`, `cluster`, `icon`.

## Icon-Zuordnung (Emoji → Icon)

| Bisher | Icon | Bisher | Icon |
|---|---|---|---|
| 🏠 | `house` | 📝 | `file-pen-line` |
| 🗂️ | `archive` | 👩‍🎓 / 🎓 | `graduation-cap` |
| 🪑 | `armchair` | 👥 | `users` |
| 🪪 | `id-card` | 🔤 | `type` |
| 🔑 | `key-round` | ⚙️ | `settings` |
| 🗑️ | `trash-2` | 📜 | `scroll-text` |
| 🔒 / 🔓 | `lock` / `lock-open` | 💬 | `message-square` |
| ➕ | `plus` | ✅ / ✔️ / ✓ | `check` / `circle-check` |
| ✕ | `x` | ⚑ / 🚩 | `flag` |
| ✋ | `hand` | ⬇️ / 📥 | `download` |
| 📤 / ⬆️ | `upload` | 📋 | `clipboard-list` |
| ⚠ | `triangle-alert` | ↶ / ↷ | `undo-2` / `redo-2` |
| ☰ | `menu` | ◐ | `sun-moon` |
| 👁 | `eye` | 🙈 | `eye-off` |
| 🖥️ / 💻 | `monitor` | 🖼️ | `image` |
| 📢 | `megaphone` | 🔔 / 🔇 | `bell` / `bell-off` |
| ⏰ / ⏱ / ⏳ | `alarm-clock` / `timer` / `hourglass` | ⏸ / ▶ | `pause` / `play` |
| 🚪 | `log-out` bzw. `door-open` | 🖨️ | `printer` |
| 🔄 / ↻ | `refresh-cw` | 🔍 | `search` |
| ⛶ | `maximize` | ✏️ / ✎ | `pencil` |
| 📊 | `chart-column` | 🚻 / 🚶 | `door-open` |
| 🧰 | `briefcase` | 📄 | `file-text` |
| ↩️ | `rotate-ccw` | 🏁 | `flag` |
