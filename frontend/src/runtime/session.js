/**
 * Gemeinsame Basis aller Lauf-Sitzungen (RunSession).
 *
 * Ereignisse, die ausgelöst werden, bevor jemand zuhört (run() liefert die
 * Sitzung zurück, die Ausführung beginnt aber sofort), werden gepuffert und
 * beim Registrieren des Zuhörers nachgereicht. So geht keine Ausgabe verloren.
 */
export class BaseSession {
    constructor() {
        this._listeners = { output: [], exit: [], image: [], turtle: [], inputRequest: [], status: [] };
        this._backlog = [];
        this.finished = false;
        this.result = null;
    }

    onOutput(cb) { return this._listen('output', cb); }
    onExit(cb) { return this._listen('exit', cb); }
    onImage(cb) { return this._listen('image', cb); }
    onTurtle(cb) { return this._listen('turtle', cb); }
    /** Das Programm wartet auf eine Eingabe (true) bzw. hat sie bekommen (false). */
    onInputRequest(cb) { return this._listen('inputRequest', cb); }
    /** Statusmeldungen ("Lade Python …"). */
    onStatus(cb) { return this._listen('status', cb); }

    sendInput() {}
    stop() {}

    _listen(kind, cb) {
        this._listeners[kind].push(cb);
        // gepufferte Ereignisse dieser Art nachliefern
        const rest = [];
        for (const ev of this._backlog) {
            if (ev.kind === kind) {
                this._call(cb, ev.args);
            } else {
                rest.push(ev);
            }
        }
        this._backlog = rest;
        return this;
    }

    _emit(kind, ...args) {
        const list = this._listeners[kind];
        if (list.length === 0) {
            this._backlog.push({ kind, args });
            return;
        }
        for (const cb of list) {
            this._call(cb, args);
        }
    }

    _call(cb, args) {
        try {
            cb(...args);
        } catch (e) {
            console.error('Fehler in Ereignis-Zuhörer', e);
        }
    }

    _output(text, stream = 'stdout') {
        if (text && !this.finished) {
            this._emit('output', text, stream);
        }
    }

    /** Beendet die Sitzung genau einmal. */
    _finish(result) {
        if (this.finished) {
            return;
        }
        this.finished = true;
        this.result = {
            exitCode: null, timedOut: false, error: null, compileErrors: [], sqlResults: null, stopped: false,
            ...result,
        };
        this._emit('exit', this.result);
    }
}
