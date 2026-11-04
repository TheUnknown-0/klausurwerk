/**
 * Zustand der Java-GUI (Bibliothek kw.gui): wendet Frames (Änderungslisten) auf ein Modell an.
 * Reine Logik ohne DOM; view.js zeichnet daraus Bausteine. Das Protokoll steht in
 * runner/libs/java/kw/gui/README.md.
 *
 * Frame:  { ops: [ ... ] }
 *   { op: 'fenster', titel, breite, hoehe, farbe }                         Fenster anlegen/ändern
 *   { op: 'set', id, typ, x, y, b, h, text, aktiv, sichtbar, farbe,
 *     hintergrund, schrift, hinweis, maus, tasten }                         Baustein anlegen/ändern (nur geänderte Felder)
 *   { op: 'del', id }                                                       Baustein entfernen
 *   { op: 'zeichne', id, befehle: [ ['l', x1, y1, x2, y2, farbe, breite], ['r', x, y, b, h, farbe, breite, gefuellt],
 *                                   ['k', x, y, r, farbe, breite, gefuellt], ['t', x, y, text, farbe, größe], ['c'] ] }
 *   { op: 'schliessen' }                                                    Fenster geschlossen
 */

export const TYPES = ['knopf', 'beschriftung', 'textfeld', 'flaeche'];
export const MAX_COMPONENTS = 300;
export const MAX_DRAW_COMMANDS = 20000;
const COLOR = /^(#[0-9a-fA-F]{3}|#[0-9a-fA-F]{6}|transparent)$/;

const num = (v, lo, hi, fallback) => {
    const n = Number(v);
    return Number.isFinite(n) ? Math.max(lo, Math.min(hi, Math.round(n))) : fallback;
};
const color = (v, fallback = '') => (typeof v === 'string' && COLOR.test(v) ? v : fallback);
const text = (v) => String(v ?? '').slice(0, 20000);

export function createGuiState() {
    return {
        window: null, // { titel, breite, hoehe, farbe }
        comps: new Map(), // id -> { id, typ, x, y, b, h, text, aktiv, sichtbar, farbe, hintergrund, schrift, hinweis, maus, tasten }
        draws: new Map(), // id -> Befehlsliste
        closed: false,
    };
}

/** Prüft einen Zeichenbefehl; liefert eine bereinigte Kopie oder null. */
export function cleanCommand(cmd) {
    if (!Array.isArray(cmd)) {
        return null;
    }
    const n = (i, lo = -100000, hi = 100000) => num(cmd[i], lo, hi, 0);
    switch (cmd[0]) {
        case 'c':
            return ['c'];
        case 'l':
            return ['l', n(1), n(2), n(3), n(4), color(cmd[5], '#000000'), n(6, 1, 50)];
        case 'r':
            return ['r', n(1), n(2), n(3), n(4), color(cmd[5], '#000000'), n(6, 1, 50), cmd[7] ? 1 : 0];
        case 'k':
            return ['k', n(1), n(2), n(3, 0, 100000), color(cmd[4], '#000000'), n(5, 1, 50), cmd[6] ? 1 : 0];
        case 't':
            return ['t', n(1), n(2), text(cmd[3]).slice(0, 2000), color(cmd[4], '#000000'), n(5, 8, 96)];
        default:
            return null;
    }
}

/**
 * Wendet einen Frame an. Rückgabe beschreibt, was sich geändert hat:
 *   { window: bool, set: Set<id>, del: Set<id>, draw: Map<id, { clear: bool, cmds: [...] }>, closed: bool }
 */
export function applyFrame(state, frame) {
    const changes = { window: false, set: new Set(), del: new Set(), draw: new Map(), closed: false };
    const ops = Array.isArray(frame?.ops) ? frame.ops : [];
    for (const op of ops) {
        if (!op || typeof op !== 'object') {
            continue;
        }
        switch (op.op) {
            case 'fenster':
                state.window = {
                    titel: text(op.titel).slice(0, 200),
                    breite: num(op.breite, 50, 3000, 400),
                    hoehe: num(op.hoehe, 50, 3000, 300),
                    farbe: color(op.farbe, '#ffffff'),
                };
                changes.window = true;
                break;
            case 'set': {
                const id = typeof op.id === 'string' && /^[A-Za-z0-9_-]{1,20}$/.test(op.id) ? op.id : null;
                if (!id) {
                    break;
                }
                let comp = state.comps.get(id);
                if (!comp) {
                    if (!TYPES.includes(op.typ) || state.comps.size >= MAX_COMPONENTS) {
                        break;
                    }
                    comp = {
                        id, typ: op.typ, x: 0, y: 0, b: 100, h: 30, text: '', aktiv: true, sichtbar: true,
                        farbe: '', hintergrund: '', schrift: 0, hinweis: '', maus: false, tasten: false,
                    };
                    state.comps.set(id, comp);
                }
                if ('x' in op) comp.x = num(op.x, -100000, 100000, comp.x);
                if ('y' in op) comp.y = num(op.y, -100000, 100000, comp.y);
                if ('b' in op) comp.b = num(op.b, 1, 3000, comp.b);
                if ('h' in op) comp.h = num(op.h, 1, 3000, comp.h);
                if ('text' in op) comp.text = text(op.text);
                if ('aktiv' in op) comp.aktiv = op.aktiv !== false;
                if ('sichtbar' in op) comp.sichtbar = op.sichtbar !== false;
                if ('farbe' in op) comp.farbe = color(op.farbe);
                if ('hintergrund' in op) comp.hintergrund = color(op.hintergrund);
                if ('schrift' in op) comp.schrift = num(op.schrift, 8, 96, 0);
                if ('hinweis' in op) comp.hinweis = text(op.hinweis).slice(0, 200);
                if ('maus' in op) comp.maus = !!op.maus;
                if ('tasten' in op) comp.tasten = !!op.tasten;
                changes.set.add(id);
                changes.del.delete(id);
                break;
            }
            case 'del':
                if (state.comps.delete(op.id)) {
                    state.draws.delete(op.id);
                    changes.set.delete(op.id);
                    changes.del.add(op.id);
                }
                break;
            case 'zeichne': {
                const comp = state.comps.get(op.id);
                if (!comp || comp.typ !== 'flaeche' || !Array.isArray(op.befehle)) {
                    break;
                }
                const list = state.draws.get(op.id) ?? [];
                const entry = changes.draw.get(op.id) ?? { clear: false, cmds: [] };
                for (const raw of op.befehle) {
                    const cmd = cleanCommand(raw);
                    if (!cmd) {
                        continue;
                    }
                    if (cmd[0] === 'c') {
                        list.length = 0;
                        entry.clear = true;
                        entry.cmds = [];
                        continue;
                    }
                    list.push(cmd);
                    entry.cmds.push(cmd);
                }
                if (list.length > MAX_DRAW_COMMANDS) {
                    list.splice(0, list.length - MAX_DRAW_COMMANDS);
                    entry.clear = true; // Anzeige muss aus der gekürzten Liste neu aufgebaut werden
                    entry.cmds = [...list];
                }
                state.draws.set(op.id, list);
                changes.draw.set(op.id, entry);
                break;
            }
            case 'schliessen':
                state.closed = true;
                changes.closed = true;
                break;
            default:
        }
    }
    return changes;
}
