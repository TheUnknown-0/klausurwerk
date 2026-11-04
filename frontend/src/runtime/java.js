/**
 * Java über die Server-API (docs/API.md, Abschnitt 2):
 *   POST /api/ausfuehren              Lauf starten -> run_id
 *   GET  /api/ausfuehren/{id}?offset  Ausgabe abholen (alle 250 ms)
 *   POST /api/ausfuehren/{id}/eingabe Eingabe senden
 *   POST /api/ausfuehren/{id}/stopp   Lauf beenden
 */
import { get as apiGet, post as apiPost } from '../lib/api.js';
import { BaseSession } from './session.js';
import { normalizeDiagnostics } from './diagnostics.js';

export const POLL_MS = 250;
export const MAX_POLL_FAILURES = 8;
export const STOP_GRACE_MS = 6000;

export class JavaSession extends BaseSession {
    /**
     * @param {{
     *   examId?: number|null, task?: any, javaVersion?: string, files: {name:string, content:string, readonly?:boolean}[],
     *   mainFile: string, mode?: 'run'|'syntax', stdin?: string|null, gui?: boolean,
     *   onGuiFrames?: (frames: any[]) => void,
     * }} opts
     * @param {{get?: Function, post?: Function, pollMs?: number}} [deps]  austauschbar für Tests
     */
    constructor(opts, deps = {}) {
        super();
        this.opts = opts;
        this.api = { get: deps.get ?? apiGet, post: deps.post ?? apiPost };
        this.pollMs = deps.pollMs ?? POLL_MS;
        this.runId = null;
        this.offset = 0;
        this.failures = 0;
        this.timer = null;
        this.stopRequested = false;
        this.stopTimer = null;
        this.inputChain = Promise.resolve();
        this.compileErrors = [];
        this.pollInFlight = false;
    }

    async start() {
        const o = this.opts;
        const taskId = o.task?.id ?? null;
        // Ist die Aufgabe bekannt, ergänzt der Server die schreibgeschützten Dateien selbst.
        const files = (o.files ?? []).filter((f) => taskId === null || !f.readonly).map((f) => ({ name: f.name, content: f.content }));
        const body = {
            language: 'java',
            java_version: String(o.javaVersion ?? '21'),
            files,
            main_file: o.mainFile,
            mode: o.mode === 'syntax' ? 'syntax' : 'run',
            stdin: o.stdin ?? null,
            gui: !!o.gui,
        };
        if (o.examId !== null && o.examId !== undefined) {
            body.exam_id = o.examId;
        }
        if (taskId !== null) {
            body.task_id = taskId;
        }
        const res = await this.api.post('/api/ausfuehren', body, { timeoutMs: 20000 });
        if (this.finished) {
            return;
        }
        if (!res.ok || !res.data?.run_id) {
            const message = res.error || 'Der Lauf konnte nicht gestartet werden.';
            this._output(`${message}\n`, 'stderr');
            this._finish({ exitCode: null, error: message });
            return;
        }
        this.runId = String(res.data.run_id);
        if (this.stopRequested) {
            this._sendStop();
        }
        this._schedule(0);
    }

    _schedule(ms) {
        clearTimeout(this.timer);
        if (this.finished) {
            return;
        }
        this.timer = setTimeout(() => this._poll(), ms);
    }

    async _poll() {
        if (this.finished || this.pollInFlight || !this.runId) {
            return;
        }
        this.pollInFlight = true;
        let res;
        try {
            res = await this.api.get(`/api/ausfuehren/${encodeURIComponent(this.runId)}?offset=${this.offset}`, { timeoutMs: 10000 });
        } finally {
            this.pollInFlight = false;
        }
        if (this.finished) {
            return;
        }
        if (!res.ok) {
            if (res.transient && ++this.failures < MAX_POLL_FAILURES) {
                this._schedule(this.pollMs * 2);
                return;
            }
            const message = res.transient ? 'Die Verbindung zum Server ist abgebrochen.' : (res.error || 'Der Lauf ist nicht mehr abrufbar.');
            this._output(`${message}\n`, 'stderr');
            this._finish({ exitCode: null, error: message, stopped: this.stopRequested });
            return;
        }
        this.failures = 0;
        const d = res.data;
        for (const item of d.output ?? []) {
            this._output(String(item.text ?? ''), item.stream === 'stderr' ? 'stderr' : 'stdout');
        }
        if (Number.isFinite(d.offset)) {
            this.offset = d.offset;
        }
        if (Array.isArray(d.compile_errors) && d.compile_errors.length > 0) {
            this.compileErrors = normalizeDiagnostics(d.compile_errors);
        }
        if (Array.isArray(d.gui_frames) && d.gui_frames.length > 0) {
            this.opts.onGuiFrames?.(d.gui_frames);
        }
        if (d.finished) {
            this._complete(d);
            return;
        }
        this._schedule(this.pollMs);
    }

    _complete(d) {
        clearTimeout(this.stopTimer);
        const errors = this.compileErrors;
        if (errors.length > 0) {
            const lines = errors.map((e) => `Fehler in ${e.file ?? 'Quelltext'}, Zeile ${e.line}: ${e.message}`);
            this._output(`Der Code lässt sich nicht übersetzen:\n${lines.join('\n')}\n`, 'stderr');
        }
        if (d.timed_out) {
            this._output('Das Zeitlimit für den Lauf wurde erreicht. Das Programm wurde beendet.\n', 'stderr');
        }
        this._finish({
            exitCode: Number.isFinite(d.exit_code) ? d.exit_code : (errors.length > 0 ? 1 : null),
            timedOut: !!d.timed_out,
            compileErrors: errors,
            stopped: this.stopRequested && !d.timed_out,
            error: errors.length > 0 ? 'Kompilierfehler' : null,
        });
    }

    sendInput(text) {
        if (this.finished) {
            return;
        }
        const payload = String(text ?? '');
        const body = payload.endsWith('\n') ? payload : `${payload}\n`;
        this.inputChain = this.inputChain.then(async () => {
            // die Lauf-ID gibt es erst nach dem Start
            for (let i = 0; i < 100 && !this.runId && !this.finished; i++) {
                await new Promise((r) => setTimeout(r, 50));
            }
            if (this.finished || !this.runId) {
                return;
            }
            await this.api.post(`/api/ausfuehren/${encodeURIComponent(this.runId)}/eingabe`, { text: body });
        });
    }

    _sendStop() {
        this.api.post(`/api/ausfuehren/${encodeURIComponent(this.runId)}/stopp`, {});
    }

    stop() {
        if (this.finished || this.stopRequested) {
            return;
        }
        this.stopRequested = true;
        if (this.runId) {
            this._sendStop();
        }
        // Falls der Server nicht mehr antwortet, nach einer Weile selbst beenden.
        this.stopTimer = setTimeout(() => {
            this._output('Programm gestoppt.\n', 'system');
            this._finish({ exitCode: null, stopped: true });
        }, STOP_GRACE_MS);
    }

    _finish(result) {
        clearTimeout(this.timer);
        clearTimeout(this.stopTimer);
        super._finish(result);
    }
}

export function runJava(opts, deps) {
    const session = new JavaSession(opts, deps);
    session.start().catch((e) => {
        session._output(`${e?.message ?? e}\n`, 'stderr');
        session._finish({ exitCode: null, error: String(e?.message ?? e) });
    });
    return session;
}
