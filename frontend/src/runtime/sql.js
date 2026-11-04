/**
 * SQL im Browser (sql.js, SQLite als WebAssembly) in einem eigenen Worker
 * (public/assets/js/worker-sql.js). Ein Worker statt Hauptthread, damit eine
 * Endlosabfrage (rekursive CTE) die Seite nicht einfriert und per Stopp
 * beendet werden kann. Jeder Lauf baut eine frische Datenbank aus sql_setup.
 */
import { url } from '../lib/api.js';
import { BaseSession } from './session.js';
import { MAX_ROWS, firstSqlError, shapeSchema, shapeSqlResults, shortSql } from './sql-results.js';

export const SQL_TIMEOUT_MS = 20_000;

let counter = 0;

export class SqlHost {
    /** @param {{assets?: {sqljs?: string}, createWorker?: () => Worker}} opts */
    constructor({ assets = {}, createWorker = null } = {}) {
        this.assets = assets;
        this.createWorker = createWorker ?? (() => new Worker(url('/assets/js/worker-sql.js'), { name: 'kw-sql' }));
        this.worker = null;
        this.ready = null;
        this.pending = new Map();
    }

    ensure() {
        if (this.worker && this.ready) {
            return this.ready;
        }
        const base = new URL(this.assets.sqljs || '/assets/vendor/sqljs/', globalThis.location?.href ?? 'http://localhost/').href;
        const worker = this.createWorker();
        this.worker = worker;
        this.ready = new Promise((resolve, reject) => {
            const fail = (message) => {
                if (this.worker === worker) {
                    this.discard();
                }
                reject(new Error(message));
            };
            worker.onmessage = (ev) => {
                const m = ev.data;
                if (m.type === 'ready') {
                    worker.onmessage = (e) => this._onMessage(e.data);
                    resolve();
                } else if (m.type === 'init-error') {
                    fail(`SQL konnte nicht geladen werden: ${m.message}`);
                }
            };
            worker.onerror = (ev) => fail(`SQL-Worker nicht verfügbar${ev?.message ? `: ${ev.message}` : '.'}`);
            worker.postMessage({ type: 'init', base });
        });
        this.ready.catch(() => {});
        return this.ready;
    }

    warmup() {
        return this.ensure().catch(() => {});
    }

    /** Worker verwerfen; offene Anfragen werden mit Fehler beantwortet. */
    discard(reason = 'abgebrochen') {
        if (this.worker) {
            this.worker.onmessage = null;
            this.worker.onerror = null;
            this.worker.terminate();
        }
        this.worker = null;
        this.ready = null;
        for (const [, p] of this.pending) {
            p.reject(new Error(reason));
        }
        this.pending.clear();
    }

    _onMessage(m) {
        const p = this.pending.get(m.runId);
        if (p) {
            this.pending.delete(m.runId);
            p.resolve(m);
        }
    }

    /** Sendet eine Anfrage und wartet auf die Antwort mit gleicher runId. */
    request(message) {
        const runId = `sql${++counter}`;
        return this.ensure().then(() => new Promise((resolve, reject) => {
            this.pending.set(runId, { resolve, reject });
            this.worker.postMessage({ ...message, runId });
        }));
    }

    /** Tabellen und Spalten der frischen Datenbank (Hilfe "Datenbank anzeigen"). */
    async schema(setup) {
        const answer = await this.request({ type: 'schema', setup: setup ?? '' });
        if (answer.setupError) {
            throw new Error(`Das Einrichtungsskript der Aufgabe hat einen Fehler: ${answer.setupError}`);
        }
        return shapeSchema(answer.tables);
    }

    run(opts) {
        const session = new SqlSession(this, opts);
        session._begin();
        return session;
    }
}

export class SqlSession extends BaseSession {
    constructor(host, opts) {
        super();
        this.host = host;
        this.opts = opts;
        this.stopped = false;
    }

    async _begin() {
        const o = this.opts;
        const main = (o.files ?? []).find((f) => f.name === o.mainFile) ?? (o.files ?? []).find((f) => /\.sql$/i.test(f.name));
        const sql = main?.content ?? '';
        if (sql.trim() === '') {
            this._output('Die Abfragedatei ist leer. Schreibe eine SQL-Anweisung und führe sie aus.\n', 'system');
            this._finish({ exitCode: 0, sqlResults: [] });
            return;
        }
        let timer = null;
        try {
            timer = this.timer = setTimeout(() => {
                if (!this.finished) {
                    this.host.discard('Zeitüberschreitung');
                }
            }, SQL_TIMEOUT_MS);
            const answer = await this.host.request({ type: 'run', setup: o.sqlSetup ?? '', sql, maxKeep: MAX_ROWS + 1 });
            clearTimeout(timer);
            if (this.finished) {
                return;
            }
            if (answer.setupError) {
                const msg = `Das Einrichtungsskript der Aufgabe hat einen Fehler: ${answer.setupError}\nBitte melde das der Lehrkraft.\n`;
                this._output(msg, 'stderr');
                this._finish({ exitCode: 1, error: `Einrichtungsfehler: ${answer.setupError}`, sqlResults: [] });
                return;
            }
            const shaped = shapeSqlResults(answer.results);
            const err = firstSqlError(shaped);
            if (err) {
                this._output(`Fehler in Anweisung ${err.index} (${shortSql(err.sql)}):\n${err.message}\n${err.hint ? `Hinweis: ${err.hint}\n` : ''}`, 'stderr');
            }
            this._finish({
                exitCode: err ? 1 : 0,
                error: err ? err.message : null,
                // im Modus "syntax" keine Daten zeigen, nur Fehler
                sqlResults: this.opts.mode === 'syntax' ? shaped.filter((r) => r.kind === 'error') : shaped,
            });
        } catch (e) {
            clearTimeout(timer);
            if (this.finished) {
                return;
            }
            if (this.stopped) {
                this._finish({ exitCode: null, stopped: true });
            } else if (/Zeit/.test(e.message)) {
                this._output(`Die Abfrage dauert zu lange (mehr als ${SQL_TIMEOUT_MS / 1000} Sekunden) und wurde abgebrochen.\n`, 'stderr');
                this._finish({ exitCode: null, timedOut: true, error: 'Zeitüberschreitung' });
            } else {
                this._output(`${e.message}\n`, 'stderr');
                this._finish({ exitCode: null, error: e.message });
            }
        }
    }

    _finish(result) {
        clearTimeout(this.timer);
        super._finish(result);
    }

    stop() {
        if (this.finished) {
            return;
        }
        this.stopped = true;
        this._output('Abfrage gestoppt.\n', 'system');
        this.host.discard('abgebrochen');
        this._finish({ exitCode: null, stopped: true });
    }
}
