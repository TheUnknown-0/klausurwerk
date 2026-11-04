/**
 * Fehlerstellen (Zeile/Spalte) in Bereiche des Editors umrechnen und
 * Fehlerlisten vereinheitlichen. Reine Logik, ohne DOM.
 */

/** Zeichen, die zu einem "Wort" an der Fehlerstelle gehören. */
const WORD = /[A-Za-z0-9_$]/;

/**
 * Rechnet eine Fehlerstelle (Zeile und Spalte 1-basiert) in {from, to} um.
 * Ohne Spalte wird die ganze Zeile (ohne führenden Leerraum) markiert.
 * Mit Spalte wird das Wort an der Stelle markiert, sonst ein Zeichen.
 */
export function lineColToRange(text, line, column = null) {
    const lines = String(text ?? '').split('\n');
    const ln = Math.min(Math.max(1, Math.trunc(Number(line) || 1)), lines.length);
    let offset = 0;
    for (let i = 0; i < ln - 1; i++) {
        offset += lines[i].length + 1;
    }
    const content = lines[ln - 1].replace(/\r$/, '');
    const lineStart = offset;
    const lineEnd = offset + content.length;
    const col = Number(column);
    if (column === null || column === undefined || !Number.isFinite(col) || col < 1) {
        const indent = content.length - content.trimStart().length;
        const from = lineStart + (indent < content.length ? indent : 0);
        return { from, to: Math.max(from, lineEnd) };
    }
    let idx = Math.min(Math.trunc(col) - 1, content.length);
    if (idx >= content.length) {
        // hinter dem Zeilenende (z. B. "';' expected"): letztes Zeichen markieren
        idx = Math.max(0, content.length - 1);
        return { from: lineStart + idx, to: Math.max(lineStart + idx, lineEnd) };
    }
    let end = idx;
    if (WORD.test(content[idx])) {
        while (end < content.length && WORD.test(content[end])) {
            end++;
        }
    } else {
        end = idx + 1;
    }
    return { from: lineStart + idx, to: lineStart + end };
}

/**
 * Bringt Fehlerlisten unterschiedlicher Herkunft in eine Form:
 * { file, line, column, message, severity }.
 */
export function normalizeDiagnostics(list, defaultFile = null) {
    const out = [];
    for (const d of Array.isArray(list) ? list : []) {
        if (!d || (d.message === undefined && d.msg === undefined)) {
            continue;
        }
        const line = Number(d.line ?? d.lineno ?? 0);
        out.push({
            file: d.file ?? d.filename ?? defaultFile,
            line: Number.isFinite(line) && line > 0 ? Math.trunc(line) : 1,
            column: d.column ?? d.col ?? d.offset ?? null,
            message: String(d.message ?? d.msg),
            severity: d.severity === 'warning' ? 'warning' : 'error',
        });
    }
    return out;
}

/** Gruppiert Diagnosen nach Datei. */
export function groupByFile(diagnostics) {
    const map = new Map();
    for (const d of diagnostics) {
        const key = d.file ?? '';
        if (!map.has(key)) {
            map.set(key, []);
        }
        map.get(key).push(d);
    }
    return map;
}

/** Diagnosen für CodeMirror (setDiagnostics) aus Text und Liste. */
export function toEditorDiagnostics(text, diagnostics) {
    return diagnostics.map((d) => {
        const { from, to } = lineColToRange(text, d.line, d.column);
        return { from, to, severity: d.severity, message: d.message };
    });
}

/**
 * Liest javac-Ausgabe, falls der Server sie einmal als Text liefert:
 *   Main.java:3: error: ';' expected
 *       int x = 5
 *               ^
 */
export function parseJavacOutput(text) {
    const lines = String(text ?? '').split(/\r?\n/);
    const out = [];
    const head = /^(?:.*[\\/])?([A-Za-z0-9_$.-]+\.java):(\d+):\s*(error|warning):\s*(.*)$/;
    for (let i = 0; i < lines.length; i++) {
        const m = head.exec(lines[i]);
        if (!m) {
            continue;
        }
        let column = null;
        // Quellzeile und Zeile mit ^ folgen
        const caret = lines[i + 2];
        if (caret !== undefined && /^\s*\^\s*$/.test(caret)) {
            column = caret.indexOf('^') + 1;
        }
        out.push({
            file: m[1],
            line: Number(m[2]),
            column,
            message: m[4].trim(),
            severity: m[3] === 'warning' ? 'warning' : 'error',
        });
    }
    return out;
}
