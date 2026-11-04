/**
 * Autosave-Kern (rein, ohne DOM und ohne Netzwerk).
 *
 * Der Outbox hält die noch nicht bestätigten Schritte (seq-Zähler, Items) und
 * eine lokale Spiegelung der vollständigen Inhalte. Format der Items und des
 * Pakets: docs/API.md 1.5, docs/DATENFORMATE.md Abschnitt 3.
 */

export const MAX_BATCH = 200;
export const SNAPSHOT_EVERY = 50;
/** Dateien über dieser Länge werden nicht als Snapshot mitgeschickt. */
export const SNAPSHOT_MAX_CHARS = 120000;

const num = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);

/** Erste zu vergebende seq: Maximum aus Serverstand und lokalem Zähler, plus 1. */
export function startSeq(serverLastSeq, localSeq) {
    return Math.max(num(serverLastSeq), num(localSeq)) + 1;
}

/** Wartezeit vor dem n-ten Wiederholungsversuch (0-basiert), 1 s bis 30 s. */
export function backoffMs(attempt, random = Math.random) {
    const base = Math.min(30000, 1000 * 2 ** Math.max(0, attempt));
    return Math.round(base * (0.85 + random() * 0.3));
}

/** Dateiinhalt aus einem Code-Inhalt ({files: [{name, content}]}). */
function fileText(content, name) {
    const files = Array.isArray(content?.files) ? content.files : [];
    const f = files.find((x) => x?.name === name);
    return typeof f?.content === 'string' ? f.content : null;
}

export class Outbox {
    /**
     * @param {{seq?: number, pending?: object[], contents?: object, opCounts?: object}} [state]
     */
    constructor(state = {}) {
        this.seq = num(state.seq);
        this.pending = Array.isArray(state.pending) ? state.pending.slice() : [];
        /** @type {Record<string, {content: any, flagged: boolean}>} */
        this.contents = state.contents && typeof state.contents === 'object' ? { ...state.contents } : {};
        this.opCounts = state.opCounts && typeof state.opCounts === 'object' ? { ...state.opCounts } : {};
    }

    toJSON() {
        return { seq: this.seq, pending: this.pending, contents: this.contents, opCounts: this.opCounts };
    }

    /** Hebt den Zähler an, falls der Server weiter ist, und verwirft bestätigte Items. */
    reconcile(serverLastSeq) {
        const last = num(serverLastSeq);
        if (last > this.seq) {
            this.seq = last;
        }
        this.pending = this.pending.filter((i) => i.seq > last);
    }

    nextSeq() {
        this.seq += 1;
        return this.seq;
    }

    /**
     * Nimmt eine Änderung auf und liefert die erzeugten Items.
     * @param {string|number} taskId
     * @param {any} content  vollständiger neuer Inhalt
     * @param {{kind?: string, payload?: any, file?: string, isPaste?: boolean}|undefined} meta
     * @param {number} clientTs
     * @param {boolean} [flagged]  null/undefined = Markierung unverändert
     */
    record(taskId, content, meta, clientTs, flagged) {
        const key = String(taskId);
        const prev = this.contents[key];
        this.contents[key] = { content, flagged: flagged ?? prev?.flagged ?? false };
        const out = [];
        const base = { task_id: Number(taskId), client_ts: clientTs };
        if (meta?.kind === 'ops' && meta.payload !== undefined) {
            const item = { seq: this.nextSeq(), ...base, kind: 'ops', payload: { file: meta.file ?? null, changes: meta.payload }, is_paste: !!meta.isPaste };
            out.push(item);
            const count = (this.opCounts[key] ?? 0) + 1;
            this.opCounts[key] = count;
            if (count % SNAPSHOT_EVERY === 0 && meta.file) {
                const text = fileText(content, meta.file);
                if (text !== null && text.length <= SNAPSHOT_MAX_CHARS) {
                    out.push({ seq: this.nextSeq(), ...base, kind: 'snapshot', payload: { file: meta.file, text }, is_paste: false });
                }
            }
        } else {
            // Ältere, noch nicht bestätigte value-Items derselben Aufgabe sind überholt
            // (jedes trägt den vollen Inhalt). Das hält Speicher und Warteschlange klein.
            this.pending = this.pending.filter((i) => !(i.kind === 'value' && i.task_id === base.task_id));
            out.push({ seq: this.nextSeq(), ...base, kind: 'value', payload: content, is_paste: false });
        }
        this.pending.push(...out);
        return out;
    }

    /** Snapshot je Datei (beim ersten Öffnen einer Code-Aufgabe), ohne den Inhalt zu ändern. */
    recordSnapshots(taskId, content, clientTs) {
        const files = Array.isArray(content?.files) ? content.files : [];
        const out = [];
        for (const f of files) {
            if (typeof f?.name === 'string' && typeof f.content === 'string' && f.content.length <= SNAPSHOT_MAX_CHARS) {
                out.push({ seq: this.nextSeq(), task_id: Number(taskId), client_ts: clientTs, kind: 'snapshot', payload: { file: f.name, text: f.content }, is_paste: false });
            }
        }
        this.pending.push(...out);
        if (out.length > 0 && !this.contents[String(taskId)]) {
            this.contents[String(taskId)] = { content, flagged: false };
        }
        return out;
    }

    /** Setzt nur die Markierung ("später"). */
    setFlag(taskId, flagged) {
        const key = String(taskId);
        const prev = this.contents[key];
        this.contents[key] = { content: prev?.content ?? null, flagged: !!flagged };
    }

    get hasPending() {
        return this.pending.length > 0;
    }

    /**
     * Baut das nächste Paket oder null. Ältere value-Items derselben Aufgabe
     * werden zusammengefasst (der volle Inhalt steckt ohnehin im letzten).
     * @returns {{items: object[], contents: object, maxSeq: number}|null}
     */
    prepareBatch(max = MAX_BATCH) {
        if (this.pending.length === 0) {
            return null;
        }
        const slice = this.pending.slice(0, max);
        const lastValueSeq = new Map();
        for (const i of slice) {
            if (i.kind === 'value') {
                lastValueSeq.set(i.task_id, i.seq);
            }
        }
        const items = slice.filter((i) => i.kind !== 'value' || lastValueSeq.get(i.task_id) === i.seq);
        const contents = {};
        for (const i of items) {
            const c = this.contents[String(i.task_id)];
            if (c && c.content !== null && c.content !== undefined) {
                contents[String(i.task_id)] = { content: c.content, flagged: !!c.flagged };
            }
        }
        return { items, contents, maxSeq: slice[slice.length - 1].seq };
    }

    /** Entfernt alle Items bis einschließlich seq (bestätigt oder verworfen). */
    ack(maxSeq) {
        this.pending = this.pending.filter((i) => i.seq > maxSeq);
    }
}

/**
 * Legt lokal noch nicht bestätigte Änderungen über die Serverinhalte.
 * @param {Record<string, {content: any, version?: number, flagged?: boolean}>} serverAnswers
 * @param {Outbox} outbox
 * @param {number} serverLastSeq
 * @returns {Record<string, {content: any, version: number, flagged: boolean}>}
 */
export function mergeAnswers(serverAnswers, outbox, serverLastSeq) {
    const result = {};
    for (const [id, a] of Object.entries(serverAnswers ?? {})) {
        result[id] = { content: a?.content ?? null, version: num(a?.version), flagged: !!a?.flagged };
    }
    const touched = new Set(outbox.pending.filter((i) => i.seq > num(serverLastSeq)).map((i) => String(i.task_id)));
    for (const id of touched) {
        const local = outbox.contents[id];
        if (local && local.content !== null && local.content !== undefined) {
            result[id] = { content: local.content, version: result[id]?.version ?? 0, flagged: !!local.flagged };
        }
    }
    return result;
}
