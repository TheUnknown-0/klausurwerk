/**
 * Kleine Helfer zum tolerant Bereinigen von Diagramm-Inhalten (Gegenstück zu DiagramTaskType.php).
 */

export const ID_RE = /^[A-Za-z0-9_-]{1,40}$/;

export const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
export const asObject = (v) => (isObj(v) ? v : {});

/** Einzeiliger Text, gekürzt. */
export function line(v, max = 100) {
    return typeof v === 'string' || typeof v === 'number' ? String(v).replace(/\s+/g, ' ').trim().slice(0, max) : '';
}

/** Text mit Zeilenumbrüchen, gekürzt. */
export function multiline(v, max = 300) {
    return typeof v === 'string' ? v.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '').trim().slice(0, max) : '';
}

export function num(v, min = -3000, max = 8000) {
    const n = Number(v);

    return Number.isFinite(n) ? Math.max(min, Math.min(max, Math.round(n))) : 0;
}

export const bool = (v) => v === true || v === 1 || v === '1' || v === 'true';

export const pick = (v, allowed, fallback) => (allowed.includes(v) ? v : fallback);

/** Nur Objekte aus einer Liste, höchstens max. */
export function rows(v, max) {
    return (Array.isArray(v) ? v : []).filter(isObj).slice(0, max);
}

/** Gültige, eindeutige Kennung; sonst eine neue mit Präfix. */
export function uniqueId(candidate, used, prefix) {
    let id = typeof candidate === 'string' && ID_RE.test(candidate) && !used.has(candidate) ? candidate : null;
    if (id === null) {
        let n = 1;
        while (used.has(`${prefix}${n}`)) {
            n += 1;
        }
        id = `${prefix}${n}`;
    }
    used.add(id);

    return id;
}

/** Tiefe Kopie (nur JSON-taugliche Daten). */
export const clone = (v) => JSON.parse(JSON.stringify(v));
