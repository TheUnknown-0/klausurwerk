/**
 * Replay: Verlauf einer Antwort aus den gespeicherten Schritten (answer_ops) wiederherstellen.
 * Reine Logik ohne DOM (docs/DATENFORMATE.md, Abschnitt 3):
 *
 *   kind 'snapshot'  payload { file, text }                 setzt eine Datei vollständig neu
 *   kind 'ops'       payload { file, changes }              CodeMirror ChangeSet.toJSON() auf den Text der Datei
 *   kind 'value'     payload = vollständiger Inhalt          strukturierte Typen; bei { files: [...] } alle Dateien neu
 *
 * Lücken in seq (verlorene Pakete) werden toleriert: Dateien gelten danach als "unsicher", bis der nächste
 * snapshot (oder ein value-Schritt mit files) sie neu aufsetzt. Änderungen, die nicht zur Textlänge passen,
 * werden übersprungen (der Text bleibt, die Datei gilt als unsicher).
 */
import { ChangeSet, Text } from '@codemirror/state';

export const CHECKPOINT_EVERY = 50;

function asObject(value) {
    if (typeof value === 'string') {
        try {
            const parsed = JSON.parse(value);
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch {
            return null;
        }
    }
    return value && typeof value === 'object' ? value : null;
}

/**
 * Bereitet die rohen Schritte der API auf: nach seq sortiert, doppelte seq entfernt (der spätere gewinnt),
 * Nutzdaten als Objekte. Jeder Schritt: { seq, ts, kind, payload, isPaste, isOvertime, gapBefore }.
 */
export function normalizeSteps(rawOps) {
    const bySeq = new Map();
    for (const op of Array.isArray(rawOps) ? rawOps : []) {
        if (!op || !Number.isFinite(Number(op.seq)) || !['ops', 'snapshot', 'value'].includes(op.kind)) {
            continue;
        }
        const tsRaw = Number.isFinite(Number(op.client_ts)) && Number(op.client_ts) > 0 ? Number(op.client_ts) : Number(op.server_ts_ms);
        bySeq.set(Number(op.seq), {
            seq: Number(op.seq),
            ts: Number.isFinite(tsRaw) ? tsRaw : null,
            kind: op.kind,
            payload: asObject(op.payload) ?? (op.kind === 'value' ? {} : null),
            isPaste: !!op.is_paste,
            isOvertime: !!op.is_overtime,
            taskId: op.task_id ?? null,
            gapBefore: false,
        });
    }
    const steps = [...bySeq.values()].sort((a, b) => a.seq - b.seq);
    for (let i = 1; i < steps.length; i++) {
        steps[i].gapBefore = steps[i].seq - steps[i - 1].seq > 1;
    }
    return steps;
}

export function emptyState() {
    return { files: new Map(), value: null, file: null, lastChange: null, unsure: false, hasGap: false };
}

function cloneState(state) {
    return {
        files: new Map(state.files),
        value: state.value,
        file: state.file,
        lastChange: null,
        unsure: state.unsure,
        hasGap: state.hasGap,
    };
}

function lineOf(text, pos) {
    return Text.of(text.split('\n')).lineAt(Math.max(0, Math.min(pos, text.length))).number;
}

/** Wendet einen Schritt an (verändert `state`). Rückgabe: true, wenn der Schritt Wirkung hatte. */
export function applyStep(state, step) {
    state.lastChange = null;
    if (step.gapBefore) {
        state.hasGap = true;
        state.unsure = true;
        for (const [name, f] of state.files) {
            state.files.set(name, { text: f.text, sure: false });
        }
    }
    const p = step.payload;
    if (!p) {
        return false;
    }
    if (step.kind === 'snapshot') {
        if (typeof p.file !== 'string') {
            return false;
        }
        state.files.set(p.file, { text: String(p.text ?? ''), sure: true });
        state.file = p.file;
        state.unsure = [...state.files.values()].some((f) => !f.sure);
        return true;
    }
    if (step.kind === 'value') {
        state.value = p;
        if (Array.isArray(p.files)) {
            state.files = new Map();
            for (const f of p.files) {
                if (f && typeof f.name === 'string') {
                    state.files.set(f.name, { text: String(f.content ?? ''), sure: true });
                }
            }
            if (!state.files.has(state.file)) {
                state.file = state.files.keys().next().value ?? null;
            }
            state.unsure = false;
        }
        return true;
    }
    // ops
    if (typeof p.file !== 'string' || p.changes === undefined) {
        return false;
    }
    const known = state.files.get(p.file);
    const before = known ? known.text : '';
    try {
        const set = ChangeSet.fromJSON(p.changes);
        const doc = Text.of(before.split('\n'));
        const next = set.apply(doc);
        const text = next.toString();
        let from = null;
        let to = null;
        set.iterChangedRanges((fromA, toA, fromB, toB) => {
            from = from === null ? fromB : Math.min(from, fromB);
            to = to === null ? toB : Math.max(to, toB);
        });
        state.files.set(p.file, { text, sure: known ? known.sure : false });
        state.file = p.file;
        if (from !== null) {
            state.lastChange = { file: p.file, fromLine: lineOf(text, from), toLine: lineOf(text, to) };
        }
        if (!known) {
            state.unsure = true;
        }
        return true;
    } catch {
        // passt nicht zum Text (Lücke davor): Text bleibt, Datei gilt als unsicher
        if (known) {
            state.files.set(p.file, { text: known.text, sure: false });
        }
        state.unsure = true;
        state.file = p.file;
        return false;
    }
}

/** Liefert den Zustand nach jedem Schritt; Zwischenstände werden alle `every` Schritte gemerkt. */
export class Reconstructor {
    constructor(steps, every = CHECKPOINT_EVERY) {
        this.steps = steps;
        this.every = every;
        this.checkpoints = new Map(); // Index -> Zustand NACH diesem Schritt
        this.last = { index: -1, state: emptyState() };
    }

    get length() {
        return this.steps.length;
    }

    /** Zustand nach Schritt `index` (-1 = vor dem ersten Schritt). */
    stateAt(index) {
        const target = Math.max(-1, Math.min(index, this.steps.length - 1));
        let from;
        let state;
        if (this.last.index <= target && this.last.index >= 0 && target - this.last.index < this.every * 2) {
            from = this.last.index;
            state = cloneState(this.last.state);
        } else {
            from = -1;
            state = emptyState();
            const cp = Math.floor(target / this.every) * this.every - 1;
            for (let i = cp; i >= 0; i -= this.every) {
                if (this.checkpoints.has(i)) {
                    from = i;
                    state = cloneState(this.checkpoints.get(i));
                    break;
                }
            }
        }
        for (let i = from + 1; i <= target; i++) {
            applyStep(state, this.steps[i]);
            if (i % this.every === this.every - 1 && !this.checkpoints.has(i)) {
                this.checkpoints.set(i, cloneState(state));
            }
        }
        this.last = { index: target, state: cloneState(state) };
        return state;
    }
}
