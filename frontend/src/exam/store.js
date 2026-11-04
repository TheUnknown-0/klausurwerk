/**
 * Lokaler Speicher der Schüleransicht: IndexedDB-Datenbank 'klausurwerk',
 * Object Store 'exam_state', ein Datensatz je Schlüssel
 * "<examId>:<Nutzer>" (Autosave-Outbox) und "<examId>:<Nutzer>:events".
 *
 * Fällt IndexedDB aus (privates Fenster, gesperrt), arbeitet der Speicher im
 * Arbeitsspeicher weiter; die Oberfläche muss davon nichts merken.
 */

const DB_NAME = 'klausurwerk';
const STORE = 'exam_state';
/** Hängt IndexedDB (selten, aber möglich), darf die Oberfläche nie blockieren. */
const IDB_TIMEOUT_MS = 2500;

function withTimeout(promise, ms = IDB_TIMEOUT_MS) {
    return new Promise((resolve, reject) => {
        const t = setTimeout(() => reject(new Error('IndexedDB Zeitüberschreitung')), ms);
        promise.then((v) => { clearTimeout(t); resolve(v); }, (e) => { clearTimeout(t); reject(e); });
    });
}

/** Speicher nur im Arbeitsspeicher (Fallback und Tests). */
export class MemoryStore {
    constructor() {
        this.map = new Map();
    }

    async get(key) {
        const v = this.map.get(key);
        return v === undefined ? null : structuredCloneSafe(v);
    }

    async set(key, value) {
        this.map.set(key, structuredCloneSafe(value));
    }

    async delete(key) {
        this.map.delete(key);
    }
}

function structuredCloneSafe(v) {
    try {
        return JSON.parse(JSON.stringify(v));
    } catch {
        return null;
    }
}

export class IdbStore {
    constructor() {
        this.dbPromise = null;
        this.fallback = new MemoryStore();
        this.broken = false;
    }

    open() {
        if (this.dbPromise) {
            return this.dbPromise;
        }
        this.dbPromise = new Promise((resolve, reject) => {
            if (typeof indexedDB === 'undefined') {
                reject(new Error('kein IndexedDB'));
                return;
            }
            let req;
            try {
                req = indexedDB.open(DB_NAME, 1);
            } catch (e) {
                reject(e);
                return;
            }
            req.onupgradeneeded = () => {
                const db = req.result;
                if (!db.objectStoreNames.contains(STORE)) {
                    db.createObjectStore(STORE, { keyPath: 'key' });
                }
            };
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error ?? new Error('IndexedDB-Fehler'));
            req.onblocked = () => reject(new Error('IndexedDB blockiert'));
        });
        return this.dbPromise;
    }

    async tx(mode, fn) {
        const db = await this.open();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, mode);
            const store = tx.objectStore(STORE);
            const req = fn(store);
            tx.oncomplete = () => resolve(req?.result ?? null);
            tx.onerror = () => reject(tx.error ?? new Error('IndexedDB-Fehler'));
            tx.onabort = () => reject(tx.error ?? new Error('IndexedDB abgebrochen'));
        });
    }

    async get(key) {
        if (this.broken) {
            return this.fallback.get(key);
        }
        try {
            const row = await withTimeout(this.tx('readonly', (s) => s.get(key)));
            return row ? row.value : null;
        } catch {
            this.broken = true;
            return this.fallback.get(key);
        }
    }

    async set(key, value) {
        if (this.broken) {
            return this.fallback.set(key, value);
        }
        try {
            await withTimeout(this.tx('readwrite', (s) => s.put({ key, value })));
        } catch {
            this.broken = true;
            await this.fallback.set(key, value);
        }
        return undefined;
    }

    async delete(key) {
        if (this.broken) {
            return this.fallback.delete(key);
        }
        try {
            await withTimeout(this.tx('readwrite', (s) => s.delete(key)));
        } catch {
            this.broken = true;
        }
        return undefined;
    }
}

export const createStore = () => new IdbStore();
