/**
 * Verbindet das Code-Widget mit der Robo-Animation, ohne die Laufzeit-Module zu ändern.
 *
 * wrapRuntime(runtime, opts) liefert eine Laufzeit, die alles an die echte weiterreicht und nur
 * run() abwandelt:
 *  - Python: Die Welt kommt als zusätzliche Datei robo_world.json in den Lauf; die Bibliothek robo
 *    meldet jeden Zug als Turtle-Nachricht {robo: "v"} (wird hier herausgefiltert).
 *  - Java: Die Welt kommt als erste Eingabezeile "@KWROBO {json}"; Robo.java schreibt Protokollzeilen
 *    auf stdout (trace-filter.js entfernt sie aus der Konsole).
 * Beide Wege füttern denselben Player.
 */
import { BaseSession } from '../runtime/session.js';
import { createTraceFilter } from './trace-filter.js';
import { worldToLine, isTraceCode } from './world.js';

export const WORLD_FILE = 'robo_world.json';

class RoboSession extends BaseSession {
    constructor(inner, language, player) {
        super();
        this.inner = inner;
        this.player = player;
        this.language = language;
        this.filter = createTraceFilter({
            onCode: (code) => player.push([code]),
            onText: (text, stream) => this._output(text, stream),
        });
        player.begin();

        inner.onOutput((text, stream) => {
            if (language === 'java' && stream === 'stdout') {
                this.filter.write(text, stream);
            } else {
                this._output(text, stream);
            }
        });
        this.turtleListeners = [];
        inner.onTurtle?.((cmds) => {
            const codes = [];
            const rest = [];
            for (const cmd of Array.isArray(cmds) ? cmds : []) {
                if (cmd && typeof cmd.robo === 'string') {
                    if (isTraceCode(cmd.robo)) {
                        codes.push(cmd.robo);
                    }
                } else {
                    rest.push(cmd);
                }
            }
            if (codes.length > 0) {
                player.push(codes);
            }
            if (rest.length > 0) {
                this._emit('turtle', rest);
            }
        });
        inner.onImage?.((url) => this._emit('image', url));
        inner.onInputRequest?.((waiting) => this._emit('inputRequest', waiting));
        inner.onStatus?.((text) => this._emit('status', text));
        inner.onExit((result) => {
            this.filter.end();
            player.programEnded();
            this._finish(result);
        });
    }

    sendInput(text) {
        this.inner.sendInput(text);
    }

    stop() {
        this.inner.stop();
    }
}

/**
 * @param {any} runtime  echte Laufzeit (runtime/index.js)
 * @param {{ language: string, getWorld: () => any, player: any }} opts
 */
export function wrapRuntime(runtime, { language, getWorld, player }) {
    const wrapped = Object.create(runtime);
    wrapped.run = (opts) => {
        const syntaxOnly = opts.mode === 'syntax';
        let args = opts;
        if (!syntaxOnly) {
            const world = getWorld();
            if (language === 'python') {
                const files = (opts.files ?? []).filter((f) => f.name !== WORLD_FILE);
                files.push({ name: WORLD_FILE, content: JSON.stringify(world), readonly: true });
                args = { ...opts, files };
            } else {
                args = { ...opts, stdin: worldToLine(world) };
            }
        }
        const inner = runtime.run(args);
        if (syntaxOnly) {
            return inner;
        }
        return new RoboSession(inner, language, player);
    };
    return wrapped;
}
