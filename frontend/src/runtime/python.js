/**
 * Python im Browser: verwaltet den Pyodide-Worker und die Läufe.
 *
 * Der Worker (entries/worker-python.js) lädt Pyodide aus assets.pyodide. Ein
 * Lauf läuft immer im selben Worker. Er wird neu gestartet, wenn ein Programm
 * sich nicht mehr unterbrechen lässt (Stopp) oder 60 Sekunden lang weder etwas
 * ausgibt noch auf eine Eingabe wartet.
 */
import { url } from '../lib/api.js';
import { BaseSession } from './session.js';
import { formatPythonError, pythonDiagnostics } from './python-errors.js';

export const STATE = { IDLE: 0, WAITING: 1, READY: 2, CANCEL: 3, EOF: 4 };
export const SILENCE_LIMIT_MS = 60_000;
export const HARD_STOP_MS = 2_500;
export const MAX_OUTPUT_CHARS = 4_000_000;

/** Gibt es SharedArrayBuffer und Cross-Origin-Isolation (für interaktives input())? */
export function canUseSharedMemory() {
    return typeof SharedArrayBuffer !== 'undefined' && globalThis.crossOriginIsolated === true;
}

export class PythonHost {
    /**
     * @param {{ assets?: {pyodide?: string}, createWorker?: () => Worker, shared?: boolean|null }} opts
     */
    constructor({ assets = {}, createWorker = null, shared = null } = {}) {
        this.assets = assets;
        this.createWorker = createWorker ?? (() => new Worker(url('/assets/build/worker-python.js'), { type: 'module', name: 'kw-python' }));
        this.worker = null;
        this.ready = null;
        this.session = null;
        this.shared = shared ?? canUseSharedMemory();
        if (this.shared) {
            this.ctrlBuf = new SharedArrayBuffer(16);
            this.dataBuf = new SharedArrayBuffer(65536);
            this.interruptBuf = new SharedArrayBuffer(1);
            this.ctrl = new Int32Array(this.ctrlBuf);
            this.data = new Uint8Array(this.dataBuf);
            this.interrupt = new Uint8Array(this.interruptBuf);
        }
    }

    /** true, wenn input() in der Konsole interaktiv beantwortet werden kann. */
    get interactive() {
        return this.shared;
    }

    /** Startet den Worker (falls nötig) und wartet, bis Pyodide bereit ist. */
    ensure(onStatus = null) {
        if (this.worker && this.ready) {
            return this.ready;
        }
        const base = new URL(this.assets.pyodide || '/assets/vendor/pyodide/', globalThis.location?.href ?? 'http://localhost/').href;
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
                    worker.onmessage = (e) => this._onMessage(worker, e.data);
                    resolve();
                } else if (m.type === 'init-error') {
                    fail(`Python konnte nicht geladen werden: ${m.message}`);
                } else if (m.type === 'status') {
                    onStatus?.(m.text);
                }
            };
            worker.onerror = (ev) => fail(`Python-Worker nicht verfügbar${ev?.message ? `: ${ev.message}` : '.'}`);
            worker.postMessage({
                type: 'init', base,
                ctrl: this.ctrlBuf ?? null, data: this.dataBuf ?? null, interrupt: this.interruptBuf ?? null,
            });
        });
        // Fehler hier nicht unbehandelt lassen, die Sitzung wertet ihn aus
        this.ready.catch(() => {});
        return this.ready;
    }

    warmup() {
        return this.ensure().catch(() => {});
    }

    /** Worker verwerfen (Neustart beim nächsten Lauf). */
    discard() {
        if (this.worker) {
            this.worker.onmessage = null;
            this.worker.onerror = null;
            this.worker.terminate();
        }
        this.worker = null;
        this.ready = null;
        if (this.ctrl) {
            Atomics.store(this.ctrl, 0, STATE.IDLE);
        }
    }

    _onMessage(worker, m) {
        if (worker !== this.worker) {
            return;
        }
        this.session?._onWorkerMessage(m);
    }

    run(opts) {
        if (this.session && !this.session.finished) {
            // ein Lauf nach dem anderen: den alten hart beenden
            this.session._abortHard('Der vorherige Lauf wurde beendet.');
        }
        const session = new PythonSession(this, opts);
        this.session = session;
        session._begin();
        return session;
    }
}

let runCounter = 0;

export class PythonSession extends BaseSession {
    constructor(host, opts) {
        super();
        this.host = host;
        this.opts = opts;
        this.runId = `py${++runCounter}`;
        this.started = false;
        this.runSent = false;
        this.waiting = false;
        this.stopRequested = false;
        this.inputQueue = [];
        this.outputChars = 0;
        this.watchdog = null;
        this.hardTimer = null;
        this.mode = opts.mode === 'syntax' ? 'syntax' : 'run';
    }

    async _begin() {
        try {
            await this.host.ensure((text) => this._emit('status', text));
        } catch (e) {
            this._output(`${e.message}\n`, 'stderr');
            this._finish({ exitCode: null, error: e.message });
            return;
        }
        if (this.finished) {
            return;
        }
        if (this.stopRequested) {
            this._finish({ exitCode: null, stopped: true });
            return;
        }
        this._armWatchdog();
        const o = this.opts;
        const stdinLines = typeof o.stdin === 'string' && o.stdin !== ''
            ? o.stdin.replace(/\r\n?/g, '\n').replace(/\n$/, '').split('\n')
            : [];
        this.host.worker.postMessage({
            type: 'run', runId: this.runId, mode: this.mode,
            files: (o.files ?? []).map((f) => ({ name: f.name, content: f.content })),
            mainFile: o.mainFile, packages: o.pythonPackages ?? [], stdinLines, interactive: this.host.interactive && !o.batch,
        });
        this.runSent = true;
    }

    _armWatchdog() {
        clearTimeout(this.watchdog);
        this.watchdog = null;
        if (this.finished || this.waiting) {
            return;
        }
        this.watchdog = setTimeout(() => {
            this._abortHard(null, true);
        }, SILENCE_LIMIT_MS);
    }

    _onWorkerMessage(m) {
        if (this.finished || (m.runId && m.runId !== this.runId)) {
            return;
        }
        switch (m.type) {
            case 'status':
                this._emit('status', m.text);
                break;
            case 'started':
                this.started = true;
                if (this.stopRequested) {
                    this._interrupt();
                }
                break;
            case 'output':
                for (const item of m.items) {
                    this.outputChars += item.text.length;
                    this._output(item.text, item.stream);
                }
                if (this.outputChars > MAX_OUTPUT_CHARS) {
                    this._output('\nDie Ausgabe ist sehr groß geworden. Das Programm wird gestoppt.\n', 'system');
                    this.stop();
                }
                break;
            case 'input-request':
                this._onInputRequest();
                return;
            case 'turtle':
                try {
                    this._emit('turtle', JSON.parse(m.json));
                } catch {
                    // fehlerhafte Befehle ignorieren
                }
                break;
            case 'image':
                this._emit('image', m.dataUrl);
                break;
            case 'done':
                this._onDone(m.result);
                return;
            default:
        }
        this._armWatchdog();
    }

    _onInputRequest() {
        if (this.stopRequested) {
            this._cancelInput();
            return;
        }
        if (this.inputQueue.length > 0) {
            this._deliver(this.inputQueue.shift());
            return;
        }
        this.waiting = true;
        clearTimeout(this.watchdog);
        this.watchdog = null;
        this._emit('inputRequest', true);
    }

    _deliver(line) {
        const host = this.host;
        const bytes = new TextEncoder().encode(line);
        const length = Math.min(bytes.length, host.data.length);
        host.data.set(bytes.subarray(0, length), 0);
        Atomics.store(host.ctrl, 1, length);
        Atomics.store(host.ctrl, 0, STATE.READY);
        Atomics.notify(host.ctrl, 0);
        if (this.waiting) {
            this.waiting = false;
            this._emit('inputRequest', false);
        }
        this._armWatchdog();
    }

    _cancelInput() {
        const host = this.host;
        if (!host.ctrl) {
            return;
        }
        Atomics.store(host.ctrl, 0, STATE.CANCEL);
        Atomics.notify(host.ctrl, 0);
        if (this.waiting) {
            this.waiting = false;
            this._emit('inputRequest', false);
        }
    }

    sendInput(text) {
        if (this.finished || !this.host.interactive) {
            return;
        }
        const line = String(text ?? '').replace(/\r?\n$/, '');
        if (this.waiting) {
            this._deliver(line);
        } else {
            this.inputQueue.push(line);
        }
    }

    _interrupt() {
        if (this.host.interrupt) {
            this.host.interrupt[0] = 2;
        }
    }

    stop() {
        if (this.finished || this.stopRequested) {
            return;
        }
        this.stopRequested = true;
        if (!this.started) {
            // Pyodide lädt noch (dann endet die Sitzung nach dem Laden) oder Pakete werden geholt
            if (this.runSent) {
                this.hardTimer = setTimeout(() => this._abortHard(null, false, true), HARD_STOP_MS * 12);
            }
            return;
        }
        if (!this.host.interrupt) {
            this._abortHard(null, false, true);
            return;
        }
        this._interrupt();
        this._cancelInput();
        this.hardTimer = setTimeout(() => this._abortHard(null, false, true), HARD_STOP_MS);
    }

    /** Worker beenden, wenn das Programm sich nicht unterbrechen lässt oder zu lange schweigt. */
    _abortHard(note, timedOut = false, stopped = false) {
        if (this.finished) {
            return;
        }
        this.host.discard();
        let message = note;
        if (timedOut) {
            message = 'Das Programm hat 60 Sekunden lang nichts ausgegeben und wurde beendet. Vielleicht läuft eine Endlosschleife?';
        } else if (stopped) {
            message = 'Das Programm hat nicht auf den Stopp reagiert und wurde hart beendet.';
        }
        if (message) {
            this._output(`${message}\n`, 'stderr');
        }
        this._finish({ exitCode: null, timedOut, stopped, error: message || null });
    }

    _onDone(r) {
        clearTimeout(this.hardTimer);
        clearTimeout(this.watchdog);
        this.waiting = false;
        switch (r.status) {
            case 'ok':
                this._finish({ exitCode: 0 });
                break;
            case 'exit':
                this._finish({ exitCode: r.exit_code ?? 0 });
                break;
            case 'stopped':
                this._output('\nProgramm gestoppt.\n', 'system');
                this._finish({ exitCode: 130, stopped: true });
                break;
            case 'syntax': {
                const errors = (r.errors ?? []).map((e) => ({
                    file: e.file, line: e.line ?? 1, column: e.column ?? null,
                    message: `${e.type}: ${e.message}`, severity: 'error',
                }));
                this._finish({ exitCode: errors.length ? 1 : 0, compileErrors: errors });
                break;
            }
            case 'error': {
                this._output(formatPythonError(r), 'stderr');
                const diagnostics = pythonDiagnostics(r);
                const isSyntax = r.type === 'SyntaxError' || r.type === 'IndentationError' || r.type === 'TabError';
                this._finish({
                    exitCode: 1,
                    error: `${r.type}: ${r.message}`,
                    compileErrors: isSyntax ? diagnostics : [],
                    diagnostics: isSyntax ? [] : diagnostics,
                });
                break;
            }
            default:
                this._output(`Interner Fehler: ${r.message ?? 'unbekannt'}\n`, 'stderr');
                this._finish({ exitCode: null, error: r.message ?? 'Interner Fehler' });
                if (r.message && /memory|abort|unreachable/i.test(r.message)) {
                    this.host.discard();
                }
        }
    }

    _finish(result) {
        clearTimeout(this.watchdog);
        clearTimeout(this.hardTimer);
        super._finish(result);
    }
}
