/**
 * Ereignis-Warteschlange: Überwachungsereignisse werden lokal gepuffert
 * (IndexedDB) und gesendet, sobald eine Verbindung besteht.
 */
import { post as apiPost } from '../lib/api.js';
import { backoffMs } from './autosave-core.js';

const MAX_BATCH = 50;
const MAX_QUEUE = 500;

export class EventQueue {
    /**
     * @param {{apiBase: string, storeKey: string, store: any, preview?: boolean,
     *   post?: Function, onResult?: Function, now?: () => number}} o
     */
    constructor(o) {
        this.apiBase = o.apiBase;
        this.storeKey = o.storeKey;
        this.store = o.store;
        this.preview = !!o.preview;
        this.post = o.post ?? apiPost;
        this.onResult = o.onResult ?? (() => {});
        this.now = o.now ?? (() => Date.now());
        this.queue = [];
        this.timer = null;
        this.attempt = 0;
        this.sending = false;
        /** Solange gehalten (vor dem Beitritt), wird nur gesammelt. */
        this.held = true;
    }

    release() {
        this.held = false;
        if (this.queue.length > 0) {
            this.kick(0);
        }
    }

    async init() {
        if (this.preview) {
            return;
        }
        try {
            const saved = await this.store.get(this.storeKey);
            if (Array.isArray(saved)) {
                this.queue = saved.concat(this.queue);
            }
        } catch {
            // ohne lokalen Speicher weiterarbeiten
        }
    }

    get length() {
        return this.queue.length;
    }

    /** Neues Ereignis; im Vorschaumodus wird nichts gemeldet. */
    push(type, details = {}) {
        if (this.preview) {
            return;
        }
        this.queue.push({ type, client_ts: this.now(), details });
        if (this.queue.length > MAX_QUEUE) {
            this.queue.splice(0, this.queue.length - MAX_QUEUE);
        }
        this.persist();
        this.kick(30);
    }

    persist() {
        Promise.resolve(this.store.set(this.storeKey, this.queue)).catch(() => {});
    }

    kick(ms) {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.flush(), ms);
    }

    async flush() {
        if (this.held || this.sending || this.queue.length === 0 || this.preview) {
            return;
        }
        this.sending = true;
        try {
            while (this.queue.length > 0) {
                const batch = this.queue.slice(0, MAX_BATCH);
                const res = await this.post(`${this.apiBase}/ereignis`, { events: batch }, { timeoutMs: 10000 });
                if (res.ok || !res.transient) {
                    // Bei Ablehnung (4xx) verwerfen, sonst bliebe die Schlange hängen.
                    this.queue.splice(0, batch.length);
                    this.attempt = 0;
                    this.persist();
                    this.onResult(res);
                    continue;
                }
                this.kick(backoffMs(this.attempt++));
                return;
            }
        } finally {
            this.sending = false;
        }
    }
}
