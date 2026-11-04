/**
 * Aufbereitung der SQL-Ergebnisse (reine Logik).
 *
 * Der SQL-Worker liefert je Anweisung eine Rohform:
 *   { sql, columns: string[], rows: any[][], totalRows, rowsModified, error: string|null }
 * Daraus entstehen Ergebnisse für die Anzeige:
 *   { index, sql, kind: 'rows'|'modified'|'ok'|'error', columns, rows, truncated, totalRows, rowsModified, message }
 */

export const MAX_ROWS = 500;

/** Zellwert als Anzeigetext; isNull markiert SQL-NULL für eine eigene Darstellung. */
export function formatCell(value) {
    if (value === null || value === undefined) {
        return { text: 'NULL', isNull: true };
    }
    if (value instanceof Uint8Array) {
        return { text: `<BLOB, ${value.length} Byte>`, isNull: false };
    }
    if (typeof value === 'number') {
        return { text: Number.isInteger(value) ? String(value) : String(Math.round(value * 1e10) / 1e10), isNull: false };
    }
    if (typeof value === 'bigint') {
        return { text: value.toString(), isNull: false };
    }
    return { text: String(value), isNull: false };
}

/** Kurze Anzeige der Anweisung (erste Zeile, gekürzt). */
export function shortSql(sql, max = 70) {
    const one = String(sql ?? '').replace(/\s+/g, ' ').trim();
    return one.length > max ? `${one.slice(0, max - 1)}…` : one;
}

/**
 * @param {Array<object>} raw
 * @param {{maxRows?: number}} [opts]
 */
export function shapeSqlResults(raw, opts = {}) {
    const maxRows = opts.maxRows ?? MAX_ROWS;
    const out = [];
    let index = 0;
    for (const r of Array.isArray(raw) ? raw : []) {
        index++;
        const base = { index, sql: String(r.sql ?? ''), columns: [], rows: [], truncated: false, totalRows: 0, rowsModified: 0, message: '' };
        if (r.error) {
            out.push({ ...base, kind: 'error', message: String(r.error), hint: sqlHint(r.error) });
            continue;
        }
        const columns = Array.isArray(r.columns) ? r.columns : [];
        if (columns.length > 0) {
            const rows = Array.isArray(r.rows) ? r.rows : [];
            const total = Number.isFinite(r.totalRows) ? r.totalRows : rows.length;
            const shown = rows.slice(0, maxRows);
            const truncated = total > shown.length;
            out.push({
                ...base,
                kind: 'rows',
                columns,
                rows: shown,
                truncated,
                totalRows: total,
                message: total === 0
                    ? 'Keine Zeilen gefunden.'
                    : truncated
                        ? `Es werden die ersten ${shown.length} von ${total}${r.totalCapped ? ' oder mehr' : ''} Zeilen angezeigt.`
                        : `${total} ${total === 1 ? 'Zeile' : 'Zeilen'}`,
            });
            continue;
        }
        const n = Number(r.rowsModified ?? 0);
        const isChange = /^\s*(insert|update|delete|replace)\b/i.test(r.sql ?? '');
        if (isChange) {
            out.push({ ...base, kind: 'modified', rowsModified: n, message: `${n} ${n === 1 ? 'Zeile' : 'Zeilen'} geändert.` });
        } else {
            out.push({ ...base, kind: 'ok', message: 'Anweisung ausgeführt.' });
        }
    }
    return out;
}

/** Kurzer Hinweis zu häufigen SQLite-Fehlermeldungen. */
export function sqlHint(message) {
    const msg = String(message ?? '');
    let m;
    if ((m = /no such table: (.+)/.exec(msg))) {
        return `Die Tabelle „${m[1]}“ gibt es nicht. Prüfe die Schreibweise, mit „Datenbank anzeigen“ siehst du alle Tabellen.`;
    }
    if ((m = /no such column: (.+)/.exec(msg))) {
        return `Die Spalte „${m[1]}“ gibt es nicht (oder sie gehört zu einer anderen Tabelle). „Datenbank anzeigen“ listet alle Spalten.`;
    }
    if (/syntax error/.test(msg)) {
        return 'Der Aufbau der Anweisung stimmt nicht. Schau an der genannten Stelle und direkt davor nach (Schlüsselwort, Komma, Klammer).';
    }
    if (/UNIQUE constraint failed|PRIMARY KEY/.test(msg)) {
        return 'Dieser Wert existiert schon, obwohl er eindeutig sein muss.';
    }
    if (/NOT NULL constraint failed/.test(msg)) {
        return 'Für diese Spalte muss ein Wert angegeben werden.';
    }
    if (/FOREIGN KEY constraint failed/.test(msg)) {
        return 'Der Verweis auf die andere Tabelle passt nicht (der Fremdschlüssel existiert dort nicht oder wird noch benutzt).';
    }
    if (/ambiguous column name: (.+)/.test(msg)) {
        return 'Der Spaltenname kommt in mehreren Tabellen vor. Schreibe Tabelle.Spalte.';
    }
    if (/incomplete input/.test(msg)) {
        return 'Die Anweisung ist unvollständig. Fehlt ein Semikolon, eine Klammer oder ein Teil der Anweisung?';
    }
    return '';
}

/** Gibt es einen Fehler? Liefert den ersten. */
export function firstSqlError(results) {
    return results.find((r) => r.kind === 'error') ?? null;
}

/**
 * Text der letzten Ergebnistabelle für Beispieltests: eine Zeile je Datenzeile,
 * Werte mit "|" getrennt, NULL als "NULL", ohne Kopfzeile.
 */
export function resultToText(results) {
    const last = [...results].reverse().find((r) => r.kind === 'rows');
    if (!last) {
        return '';
    }
    return last.rows.map((row) => row.map((v) => formatCell(v).text).join('|')).join('\n') + (last.rows.length ? '\n' : '');
}

/** Rohdaten der Schema-Abfrage in eine Anzeigeform bringen. */
export function shapeSchema(raw) {
    return (Array.isArray(raw) ? raw : []).map((t) => ({
        name: String(t.name),
        type: t.type === 'view' ? 'view' : 'table',
        columns: (t.columns ?? []).map((c) => ({
            name: String(c.name),
            type: String(c.type || ''),
            pk: !!c.pk,
            notnull: !!c.notnull,
            fk: c.fk ?? null,
        })),
    }));
}
