/**
 * Reine Logik der Korrektur (ohne DOM, ohne Netz): Punkte, Endwert bei Erst-/Zweitkorrektur,
 * Navigation durch die Matrix, Anker und Textsegmente für Kommentare.
 * Alles hier ist per node --test prüfbar (frontend/test/grading-logic.test.js).
 */

export const POINT_STEP = 0.5;

/** 2.5 -> "2,5", 3 -> "3", null -> "". */
export function formatPoints(value) {
    if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) {
        return '';
    }
    const rounded = Math.round(Number(value) * 100) / 100;
    return String(rounded).replace('.', ',');
}

/** Rundet auf das nächste 0,5. */
export function roundHalf(value) {
    return Math.round(Number(value) * 2) / 2;
}

/**
 * Wertet eine Punkteingabe aus.
 * @returns {{ok: true, value: number|null} | {ok: false, error: string}}  value null = leer (keine Bewertung)
 */
export function parsePoints(text, max) {
    const raw = String(text ?? '').trim().replace(',', '.');
    if (raw === '') {
        return { ok: true, value: null };
    }
    if (!/^\d+(\.\d+)?$/.test(raw)) {
        return { ok: false, error: 'Bitte eine Zahl eingeben (z. B. 2,5).' };
    }
    const value = Number(raw);
    if (value > max + 1e-9) {
        return { ok: false, error: `Höchstens ${formatPoints(max)} Punkte.` };
    }
    if (Math.abs(value * 2 - Math.round(value * 2)) > 1e-6) {
        return { ok: false, error: 'Punkte gibt es in Schritten von 0,5.' };
    }
    return { ok: true, value: Math.round(value * 100) / 100 };
}

/**
 * Endwert aus den Einträgen mehrerer Lehrkräfte (gleiche Regel wie GradingService::finalEntry):
 * Eintrag der Hauptlehrkraft, sonst der einzige, sonst der zuletzt geänderte.
 * @param {{grader_id: number, points: number|null, updated_at?: string}[]} entries
 * @param {number|null} ownerId
 */
export function finalEntry(entries, ownerId) {
    const valid = (entries ?? []).filter((e) => e.points !== null && e.points !== undefined && e.points !== '');
    if (valid.length === 0) {
        return { points: null, graderId: null, source: null, differs: false, count: 0 };
    }
    let chosen = null;
    let source = null;
    if (ownerId !== null && ownerId !== undefined) {
        chosen = valid.find((e) => Number(e.grader_id) === Number(ownerId)) ?? null;
        source = chosen ? 'owner' : null;
    }
    if (!chosen) {
        if (valid.length === 1) {
            [chosen] = valid;
            source = 'single';
        } else {
            chosen = [...valid].sort((a, b) => String(b.updated_at ?? '').localeCompare(String(a.updated_at ?? '')) || Number(b.grader_id) - Number(a.grader_id))[0];
            source = 'latest';
        }
    }
    const differs = valid.some((e) => Math.abs(Number(e.points) - Number(chosen.points)) > 1e-9);
    return { points: Number(chosen.points), graderId: Number(chosen.grader_id), source, differs, count: valid.length };
}

/**
 * Reihenfolge aller Zellen für das Durchblättern.
 * mode 'person': Person für Person, innerhalb die Aufgaben. mode 'task': Aufgabe für Aufgabe, innerhalb die Personen.
 * @returns {{pid: number, tid: number}[]}
 */
export function traversal(participantIds, taskIds, mode) {
    const cells = [];
    if (mode === 'task') {
        for (const tid of taskIds) {
            for (const pid of participantIds) {
                cells.push({ pid, tid });
            }
        }
    } else {
        for (const pid of participantIds) {
            for (const tid of taskIds) {
                cells.push({ pid, tid });
            }
        }
    }
    return cells;
}

/** Nachbarzelle in der Reihenfolge oder null am Rand. */
export function neighbour(cells, current, dir) {
    const i = cells.findIndex((c) => c.pid === current.pid && c.tid === current.tid);
    if (i < 0) {
        return null;
    }
    return cells[i + dir] ?? null;
}

/**
 * Ergebnis eines Schritts: { next } oder, am Rand der Reihenfolge, { next: null, edge: 'first'|'last' }.
 * Am Rand wird trotzdem gespeichert (Enter im Punktefeld), nur gewechselt wird nicht.
 */
export function stepOutcome(cells, current, dir) {
    const next = neighbour(cells, current, dir);
    return next ? { next, edge: null } : { next: null, edge: dir > 0 ? 'last' : 'first' };
}

/** Nächste noch nicht endgültig bewertete Zelle hinter der aktuellen (ohne Umbruch), sonst null. */
export function nextOpen(cells, current, statusOf, dir = 1) {
    const i = cells.findIndex((c) => c.pid === current.pid && c.tid === current.tid);
    if (i < 0) {
        return null;
    }
    for (let k = i + dir; k >= 0 && k < cells.length; k += dir) {
        const status = statusOf(cells[k]);
        if (status !== 'graded' && status !== 'diff') {
            return cells[k];
        }
    }
    return null;
}

/** Die jeweils andere Achse: in 'person' ist ↓ die nächste Person, in 'task' die nächste Aufgabe. */
export function stepAxis(participantIds, taskIds, current, axis, dir) {
    const list = axis === 'person' ? participantIds : taskIds;
    const key = axis === 'person' ? 'pid' : 'tid';
    const i = list.indexOf(current[key]);
    if (i < 0) {
        return null;
    }
    const next = list[i + dir];
    return next === undefined ? null : { ...current, [key]: next };
}

/** Kurzbeschreibung des Ankers für Listen. */
export function anchorLabel(anchor) {
    switch (anchor?.kind) {
        case 'code':
            return anchor.from_line === anchor.to_line
                ? `Zeile ${anchor.from_line} (${anchor.file})`
                : `Zeilen ${anchor.from_line} bis ${anchor.to_line} (${anchor.file})`;
        case 'text': {
            const q = String(anchor.quote ?? '').trim();
            return q ? `„${q.length > 40 ? `${q.slice(0, 40)}…` : q}“` : 'Textstelle';
        }
        default:
            return 'Ganze Aufgabe';
    }
}

/** Zeile -> Liste der Kommentar-IDs für eine Datei. */
export function lineCommentMap(comments, file) {
    const map = new Map();
    for (const c of comments) {
        if (c.anchor?.kind !== 'code' || c.anchor.file !== file) {
            continue;
        }
        for (let line = c.anchor.from_line; line <= c.anchor.to_line; line++) {
            if (!map.has(line)) {
                map.set(line, []);
            }
            map.get(line).push(c.id);
        }
    }
    return map;
}

/**
 * Zerlegt einen Text in Abschnitte, die jeweils von denselben Kommentaren abgedeckt werden.
 * @param {string} text
 * @param {{id: number, anchor: {kind: string, from?: number, to?: number}}[]} comments
 * @returns {{text: string, ids: number[]}[]}
 */
export function textSegments(text, comments) {
    const length = text.length;
    const marks = comments
        .filter((c) => c.anchor?.kind === 'text')
        .map((c) => ({ id: c.id, from: Math.max(0, Math.min(length, Number(c.anchor.from))), to: Math.max(0, Math.min(length, Number(c.anchor.to))) }))
        .filter((m) => m.to > m.from);
    if (marks.length === 0) {
        return length === 0 ? [] : [{ text, ids: [] }];
    }
    const cuts = new Set([0, length]);
    for (const m of marks) {
        cuts.add(m.from);
        cuts.add(m.to);
    }
    const points = [...cuts].sort((a, b) => a - b);
    const out = [];
    for (let i = 0; i < points.length - 1; i++) {
        const [from, to] = [points[i], points[i + 1]];
        if (to <= from) {
            continue;
        }
        const ids = marks.filter((m) => m.from <= from && m.to >= to).map((m) => m.id);
        out.push({ text: text.slice(from, to), ids });
    }
    return out;
}

/** Zeilenbereich (1-basiert) einer Auswahl in einem Text mit Zeilenumbrüchen. */
export function linesOfRange(text, from, to) {
    const before = text.slice(0, from);
    const first = before.split('\n').length;
    // Endet die Auswahl direkt hinter einem Zeilenumbruch, gehört die Folgezeile nicht mehr dazu.
    const end = to > from && text[to - 1] === '\n' ? to - 1 : to;
    const last = text.slice(0, end).split('\n').length;
    return { from: first, to: Math.max(first, last) };
}

/** Statuszeile zur Bewertung einer Zelle. */
export function cellStatusFor(own, finalPoints, suggested, autoError) {
    if (finalPoints !== null && finalPoints !== undefined) {
        return 'graded';
    }
    if (own !== null && own !== undefined) {
        return 'graded';
    }
    if (suggested !== null && suggested !== undefined) {
        return 'suggested';
    }
    return autoError ? 'error' : 'open';
}
