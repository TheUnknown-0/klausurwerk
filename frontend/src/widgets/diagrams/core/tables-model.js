/**
 * Modelle der tabellenartigen Diagramme (Wahrheitstabelle, Relationenschema). Rein, ohne DOM.
 */
import { rowKeys } from './logic-sim.js';

// ---------------------------------------------------------------------------
// Wahrheitstabelle
// ---------------------------------------------------------------------------

export { rowKeys };

/** Bereinigt rows gegen die Konfiguration; vorgegebene Zellen werden erzwungen. */
export function normalizeRows(content, config) {
    const inputs = Array.isArray(config?.inputs) ? config.inputs : [];
    const outputs = Array.isArray(config?.outputs) ? config.outputs : [];
    const valid = new Set(rowKeys(inputs.length));
    const rows = {};
    const take = (src) => {
        for (const [key, row] of Object.entries(src && typeof src === 'object' ? src : {})) {
            if (!valid.has(key) || !row || typeof row !== 'object') {
                continue;
            }
            for (const out of outputs) {
                const v = row[out];
                if (v === 0 || v === 1 || v === '0' || v === '1') {
                    rows[key] ??= {};
                    rows[key][out] = String(v);
                }
            }
        }
    };
    take(content?.rows);
    take(config?.prefilled);

    return { rows };
}

/** Nächster Zellwert beim Klicken/Leertaste: leer, 0, 1, leer. */
export const nextCell = (value) => (value === '' || value === undefined || value === null ? '0' : value === '0' ? '1' : '');

/** Ist die Zelle durch die Aufgabe vorgegeben? */
export const isPrefilled = (config, key, out) => config?.prefilled?.[key]?.[out] !== undefined;

/** Zellenweiser Vergleich (Anteil richtiger Zellen ohne vorgegebene), für die Vorschau der Lehrkraft. */
export function compareRows(expected, actual, config) {
    let total = 0;
    let right = 0;
    for (const [key, row] of Object.entries(expected?.rows ?? {})) {
        for (const [out, v] of Object.entries(row)) {
            if (isPrefilled(config, key, out)) {
                continue;
            }
            total += 1;
            if (String(actual?.rows?.[key]?.[out] ?? '') === String(v)) {
                right += 1;
            }
        }
    }

    return total === 0 ? 0 : right / total;
}

// ---------------------------------------------------------------------------
// Relationenschema
// ---------------------------------------------------------------------------

const clip = (v, max) => (typeof v === 'string' ? v.replace(/\s+/g, ' ').trim().slice(0, max) : '');

/** Bereinigt ein Relationenschema (tolerant). */
export function normalizeSchema(content) {
    const ids = new Set();
    const tables = [];
    for (const t of Array.isArray(content?.tables) ? content.tables.slice(0, 30) : []) {
        let id = typeof t?.id === 'string' && /^[A-Za-z0-9_-]{1,40}$/.test(t.id) ? t.id : null;
        if (id === null || ids.has(id)) {
            id = freeId('t', ids);
        }
        ids.add(id);
        const columns = (Array.isArray(t?.columns) ? t.columns.slice(0, 40) : []).map((c) => {
            const fk = typeof c?.fk === 'string' && /^[^.]+\.[^.]+$/.test(clip(c.fk, 120)) ? clip(c.fk, 120) : null;

            return { name: clip(c?.name, 60), pk: !!c?.pk, fk };
        });
        tables.push({ id, name: clip(t?.name, 60), columns });
    }

    return { tables };
}

export function freeId(prefix, used) {
    const set = used instanceof Set ? used : new Set(used);
    let n = 1;
    while (set.has(`${prefix}${n}`)) {
        n += 1;
    }

    return `${prefix}${n}`;
}

/** Bausteine der Schreibweise Tabelle(id, name, ↑kurs_id): [{text, pk, fk}] inkl. Klammern und Kommas. */
export function schemaSegments(table) {
    const out = [{ text: table.name || '(Name)', bold: true }, { text: '(' }];
    table.columns.forEach((c, i) => {
        if (i > 0) {
            out.push({ text: ', ' });
        }
        if (c.fk) {
            out.push({ text: '↑', fk: true });
        }
        out.push({ text: c.name || '?', pk: c.pk });
    });
    out.push({ text: ')' });

    return out;
}

/** Schema als Text: Primärschlüssel _unterstrichen_, Fremdschlüssel mit ↑. */
export function schemaText(table) {
    return schemaSegments(table).map((s) => (s.pk ? `_${s.text}_` : s.text)).join('');
}

/** Mögliche Fremdschlüssel-Ziele "Tabelle.spalte" (alle benannten Spalten anderer Tabellen). */
export function fkTargets(schema, exceptTableId = null) {
    const out = [];
    for (const t of schema.tables) {
        if (t.id === exceptTableId || !t.name) {
            continue;
        }
        for (const c of t.columns) {
            if (c.name) {
                out.push(`${t.name}.${c.name}`);
            }
        }
    }

    return out;
}

/** Benennt Tabelle/Spalte um und zieht Fremdschlüssel-Verweise nach. */
export function renameReferences(schema, oldRef, newRef) {
    for (const t of schema.tables) {
        for (const c of t.columns) {
            if (c.fk === oldRef) {
                c.fk = newRef;
            }
        }
    }
}

/** Alle Verweise auf eine Tabelle umschreiben. */
export function renameTableReferences(schema, oldName, newName) {
    if (!oldName) {
        return;
    }
    for (const t of schema.tables) {
        for (const c of t.columns) {
            if (c.fk && c.fk.startsWith(`${oldName}.`)) {
                c.fk = `${newName}.${c.fk.slice(oldName.length + 1)}`;
            }
        }
    }
}
