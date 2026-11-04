/**
 * Autosave der Schüleransicht.
 *
 * Änderungen landen im Outbox (autosave-core.js) und werden lokal in IndexedDB
 * gespiegelt. Gesendet wird 1 s nach der letzten Änderung, spätestens nach 5 s,
 * höchstens 200 Items je Anfrage. Wiederholt wird nur bei transienten Fehlern
 * (mit Backoff). 409 pausiert das Senden, bis der Zustand wieder aktiv ist.
 */
import { post as apiPost } from '../lib/api.js';
import { Outbox, mergeAnswers, backoffMs, MAX_BATCH } from './autosave-core.js';

const SEND_DELAY_MS = 1000;
const SEND_MAX_MS = 5000;
const PERSIST_DELAY_MS = 300;

export class Autosave {
    /**
     * @param {{apiBase: string, storeKey: string, store: any, preview?: boolean,
     *   post?: Function, onStatus?: Function, onConflict?: Function, onMessage?: Function,
     *   onVersions?: Function, now?: () => number}} o
     */
    constructor(o) {
        this.apiBase = o.apiBase;
        this.storeKey = o.storeKey;
        this.store = o.store;
        this.preview = !!o.preview;
        this.post = o.post ?? apiPost;
        this.onStatus = o.onStatus ?? (() => {});
        this.onConflict = o.onConflict ?? (() => {});
        this.onMessage = o.onMessage ?? (() => {});
        this.onVersions = o.onVersions ?? (() => {});
        this.now = o.now ?? (() => Date.now());
        this.outbox = new Outbox();
        this.status = { kind: 'idle' };
        this.lastSavedAt = null;
        this.firstDirtyAt = null;
        this.timer = null;
        this.retryTimer = null;
        this.persistTimer = null;
        this.attempt = 0;
        this.blocked = false;
        this.sending = null;
        this.destroyed = false;
    }

    /**
     * Lädt den lokalen Stand und legt unbestätigte Änderungen über die Serverinhalte.
     * @returns {Promise<Record<string, {content: any, version: number, flagged: boolean}>>}
     */
    async init(lastSeq, serverAnswers) {
        let saved = null;
        if (!this.preview) {
            try {
                saved = await this.store.get(this.storeKey);
            } catch {
                saved = null;
            }
        }
        this.outbox = new Outbox(saved ?? {});
        this.outbox.reconcile(lastSeq);
        const merged = mergeAnswers(serverAnswers, this.outbox, lastSeq);
        // Inhalte, die der Server schon kennt, brauchen keine lokale Kopie mehr.
        for (const [id, a] of Object.entries(merged)) {
            if (!this.outbox.pending.some((i) => String(i.task_id) === id)) {
                this.outbox.contents[id] = { content: a.content, flagged: a.flagged };
            }
        }
        this.persistSoon();
        if (this.outbox.hasPending) {
            this.setStatus({ kind: 'saving' });
            this.schedule();
        }
        return merged;
    }

    get pendingCount() {
        return this.outbox.pending.length;
    }

    /** Änderung eines Widgets. */
    change(taskId, content, meta) {
        this.outbox.record(taskId, content, meta, this.now());
        this.afterChange();
    }

    /** Markierung "später" umschalten (mit aktuellem Inhalt, damit der Server die Aufgabe kennt). */
    flag(taskId, flagged, content) {
        if (content !== null && content !== undefined) {
            this.outbox.record(taskId, content, { kind: 'value' }, this.now(), !!flagged);
        } else {
            this.outbox.setFlag(taskId, !!flagged);
        }
        this.afterChange();
    }

    /** Ausgangsstand von Code-Dateien für das Replay (beim ersten Öffnen). */
    snapshots(taskId, content) {
        const items = this.outbox.recordSnapshots(taskId, content, this.now());
        if (items.length > 0) {
            this.afterChange();
        }
    }

    afterChange() {
        if (this.preview || this.destroyed) {
            return;
        }
        if (this.status.kind !== 'offline' && this.status.kind !== 'blocked') {
            this.setStatus({ kind: 'saving' });
        }
        this.persistSoon();
        this.schedule();
    }

    schedule() {
        if (this.preview || this.blocked || this.retryTimer || !this.outbox.hasPending) {
            return;
        }
        const t = this.now();
        if (this.firstDirtyAt === null) {
            this.firstDirtyAt = t;
        }
        clearTimeout(this.timer);
        const wait = Math.min(SEND_DELAY_MS, Math.max(0, this.firstDirtyAt + SEND_MAX_MS - t));
        this.timer = setTimeout(() => {
            this.flush();
        }, wait);
    }

    setStatus(status) {
        if (status.kind === 'saved') {
            this.lastSavedAt = this.now();
        }
        this.status = status;
        this.onStatus(status, this.lastSavedAt);
    }

    persistSoon() {
        if (this.preview) {
            return;
        }
        clearTimeout(this.persistTimer);
        this.persistTimer = setTimeout(() => this.persistNow(), PERSIST_DELAY_MS);
    }

    persistNow() {
        if (this.preview) {
            return Promise.resolve();
        }
        clearTimeout(this.persistTimer);
        return Promise.resolve(this.store.set(this.storeKey, this.outbox.toJSON())).catch(() => {});
    }

    /** Sendet alles Ausstehende. Liefert true, wenn nichts mehr offen ist. */
    flush() {
        if (this.preview || this.destroyed) {
            return Promise.resolve(true);
        }
        if (this.sending) {
            return this.sending;
        }
        clearTimeout(this.timer);
        this.firstDirtyAt = null;
        this.sending = this.run().finally(() => {
            this.sending = null;
        });
        return this.sending;
    }

    async run() {
        for (;;) {
            if (!this.outbox.hasPending) {
                if (this.status.kind !== 'blocked') {
                    this.setStatus(this.lastSavedAt === null ? { kind: 'idle' } : { kind: 'saved' });
                }
                return true;
            }
            if (this.blocked) {
                return false;
            }
            const batch = this.outbox.prepareBatch(MAX_BATCH);
            this.setStatus({ kind: 'saving' });
            const res = await this.post(`${this.apiBase}/speichern`, { items: batch.items, contents: batch.contents, client_now_ms: Date.now() }, { timeoutMs: 20000 });
            if (this.destroyed) {
                return false;
            }
            if (res.ok) {
                this.outbox.ack(batch.maxSeq);
                this.attempt = 0;
                this.onVersions(res.data?.versions ?? {});
                this.persistSoon();
                this.setStatus({ kind: 'saved' });
                continue;
            }
            if (res.status === 409) {
                this.blocked = true;
                this.setStatus({ kind: 'blocked' });
                this.onConflict(res);
                return false;
            }
            if (res.status === 422) {
                // Dieses Paket ist ungültig: verwerfen, damit der Rest durchkommt. Der Inhalt bleibt lokal gesichert.
                this.outbox.ack(batch.maxSeq);
                this.persistSoon();
                this.setStatus({ kind: 'error' });
                this.onMessage(`Eine Antwort konnte nicht gespeichert werden (${res.error}). Sie bleibt auf diesem Gerät gesichert; ändere sie bitte noch einmal.`, 'error');
                continue;
            }
            // transient (Netz, Zeitüberschreitung, 5xx) oder unbekannter Fehler: mit Wartezeit erneut
            this.setStatus({ kind: res.transient ? 'offline' : 'error' });
            if (!res.transient && this.attempt === 0) {
                this.onMessage(`Speichern nicht möglich: ${res.error}`, 'error');
            }
            this.retryLater();
            return false;
        }
    }

    retryLater() {
        const wait = backoffMs(this.attempt);
        this.attempt += 1;
        clearTimeout(this.retryTimer);
        this.retryTimer = setTimeout(() => {
            this.retryTimer = null;
            this.flush();
        }, wait);
    }

    /** Nach einem 409: Senden wieder erlauben (Zustand ist wieder aktiv). */
    resume() {
        if (!this.blocked) {
            return;
        }
        this.blocked = false;
        this.attempt = 0;
        this.setStatus(this.outbox.hasPending ? { kind: 'saving' } : { kind: 'saved' });
        this.flush();
    }

    /** Hält das Senden an (z. B. nach der Abgabe); lokale Sicherung bleibt. */
    pause() {
        this.blocked = true;
    }

    /** Verwirft ausstehende Items, wenn der Zustand dauerhaft nicht mehr speichert (abgegeben). */
    abandonPending() {
        this.outbox.ack(Number.MAX_SAFE_INTEGER);
        this.blocked = true;
        this.persistSoon();
    }

    /** Letzter Versuch beim Verlassen der Seite (keepalive, ohne Antwort abzuwarten). */
    flushKeepalive() {
        if (this.preview || this.blocked || !this.outbox.hasPending) {
            return;
        }
        const batch = this.outbox.prepareBatch(MAX_BATCH);
        const body = { items: batch.items, contents: batch.contents, client_now_ms: Date.now() };
        try {
            if (JSON.stringify(body).length < 60000) {
                this.post(`${this.apiBase}/speichern`, body, { keepalive: true, timeoutMs: 5000 });
            }
        } catch {
            // beim Verlassen der Seite nichts weiter tun
        }
        this.persistNow();
    }

    destroy() {
        this.destroyed = true;
        clearTimeout(this.timer);
        clearTimeout(this.retryTimer);
        clearTimeout(this.persistTimer);
    }
}
