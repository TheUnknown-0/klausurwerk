/**
 * Web Worker für Python (Pyodide). Wird als Modul-Worker geladen:
 *   new Worker(url('/assets/build/worker-python.js'), { type: 'module' })
 *
 * Nachrichten vom Hauptthread:
 *   { type:'init', base, ctrl, data, interrupt }  Pyodide laden (base = URL des Pyodide-Ordners)
 *   { type:'run', runId, files, mainFile, mode, packages, stdin, interactive }
 *
 * Nachrichten an den Hauptthread:
 *   ready | init-error { message } | status { text } | started { runId }
 *   output { runId, items:[{stream,text}] } | input-request { runId }
 *   turtle { runId, json } | image { runId, dataUrl } | done { runId, result }
 *
 * Interaktives input(): Der Worker setzt ctrl[0] = WARTET und blockiert mit
 * Atomics.wait, bis der Hauptthread den Text in data schreibt und ctrl[0]
 * auf BEREIT (oder ABBRUCH/ENDE) setzt. Abbruch durch interrupt[0] = 2 (SIGINT).
 */
import { PY_RUNNER } from '../runtime/python-runner.js';
import { TURTLE_PY } from '../runtime/turtle-source.js';

const WAITING = 1;
const READY = 2;
const CANCEL = 3;
const EOF = 4;
const ALLOWED_PACKAGES = ['numpy', 'matplotlib'];

let pyodide = null;
let runner = null;
let ctrl = null;
let data = null;
let interrupt = null;
let currentRun = null;
const loadedPackages = new Set();
let matplotlibReady = false;
let queue = Promise.resolve();

const post = (message) => self.postMessage(message);

// ---------- Ausgabe bündeln ----------
let outItems = [];
let outSize = 0;
let lastFlush = 0;

function flushOut() {
    if (outItems.length > 0 && currentRun) {
        post({ type: 'output', runId: currentRun, items: outItems });
    }
    outItems = [];
    outSize = 0;
    lastFlush = Date.now();
}

function writeOut(stream, text) {
    if (!text) {
        return;
    }
    const last = outItems[outItems.length - 1];
    if (last && last.stream === stream) {
        last.text += text;
    } else {
        outItems.push({ stream, text });
    }
    outSize += text.length;
    // einzelne Ausgaben sofort, schnelle Folgen gebündelt (höchstens alle 25 ms)
    if (outSize > 16384 || Date.now() - lastFlush >= 25) {
        flushOut();
    }
}

// ---------- Brücke zu Python ----------
function readInput() {
    flushOut();
    if (!ctrl) {
        return 'E';
    }
    Atomics.store(ctrl, 0, WAITING);
    post({ type: 'input-request', runId: currentRun });
    while (Atomics.load(ctrl, 0) === WAITING) {
        Atomics.wait(ctrl, 0, WAITING, 1000);
    }
    const state = Atomics.load(ctrl, 0);
    let reply = 'C';
    if (state === READY) {
        const length = Math.max(0, Math.min(Atomics.load(ctrl, 1), data.length));
        // Kopie: TextDecoder akzeptiert keine Sichten auf SharedArrayBuffer
        reply = `T${new TextDecoder().decode(data.slice(0, length))}`;
    } else if (state === EOF) {
        reply = 'E';
    } else if (state !== CANCEL) {
        reply = 'C';
    }
    Atomics.store(ctrl, 0, 0);
    return reply;
}

const bridge = {
    write(stream, text) {
        writeOut(stream, String(text));
    },
    echo(text) {
        writeOut('stdin', String(text));
    },
    read_input: readInput,
    turtle(json) {
        flushOut();
        post({ type: 'turtle', runId: currentRun, json: String(json) });
    },
    image(b64) {
        flushOut();
        post({ type: 'image', runId: currentRun, dataUrl: `data:image/png;base64,${b64}` });
    },
};

// ---------- Start ----------
async function init(msg) {
    ctrl = msg.ctrl ? new Int32Array(msg.ctrl) : null;
    data = msg.data ? new Uint8Array(msg.data) : null;
    interrupt = msg.interrupt ? new Uint8Array(msg.interrupt) : null;
    const base = msg.base;
    const mod = await import(/* webpackIgnore: true */ `${base}pyodide.mjs`);
    pyodide = await mod.loadPyodide({
        indexURL: base,
        lockFileURL: `${base}pyodide-lock.json`,
        // Ausgaben aus C-Code (selten) trotzdem sichtbar machen
        stdout: (text) => writeOut('stdout', `${text}\n`),
        stderr: (text) => writeOut('stderr', `${text}\n`),
    });
    pyodide.registerJsModule('_kw', bridge);
    pyodide.FS.mkdirTree('/kwlib');
    pyodide.FS.writeFile('/kwlib/turtle.py', TURTLE_PY);
    const scope = pyodide.globals.get('dict')();
    pyodide.runPython(PY_RUNNER, { globals: scope });
    runner = {
        prepare: scope.get('prepare'),
        run: scope.get('run'),
        check: scope.get('check'),
        installMatplotlib: scope.get('install_matplotlib'),
    };
    if (interrupt) {
        pyodide.setInterruptBuffer(interrupt);
    }
    post({ type: 'ready' });
}

async function ensurePackages(list) {
    const wanted = (Array.isArray(list) ? list : []).filter((p) => ALLOWED_PACKAGES.includes(p));
    const missing = wanted.filter((p) => !loadedPackages.has(p));
    if (missing.length > 0) {
        post({ type: 'status', text: `Lade ${missing.join(', ')} (beim ersten Mal dauert das einen Moment) …` });
        await pyodide.loadPackage(missing);
        missing.forEach((p) => loadedPackages.add(p));
    }
    if (wanted.includes('matplotlib') && !matplotlibReady) {
        post({ type: 'status', text: 'Richte matplotlib ein …' });
        runner.installMatplotlib();
        matplotlibReady = true;
    }
}

async function run(msg) {
    currentRun = msg.runId;
    outItems = [];
    outSize = 0;
    lastFlush = 0;
    let result;
    try {
        if (msg.mode === 'syntax') {
            const errors = JSON.parse(runner.check(JSON.stringify({ files: msg.files })));
            result = { status: 'syntax', errors };
        } else {
            await ensurePackages(msg.packages);
            runner.prepare(JSON.stringify({
                files: msg.files,
                main: msg.mainFile,
                stdin_lines: msg.stdinLines ?? [],
                interactive: !!msg.interactive && !!ctrl,
            }));
            if (interrupt) {
                interrupt[0] = 0;
            }
            if (ctrl) {
                Atomics.store(ctrl, 0, 0);
            }
            post({ type: 'started', runId: msg.runId });
            result = JSON.parse(runner.run(msg.mainFile));
        }
    } catch (e) {
        result = { status: 'crash', message: String(e?.message ?? e) };
    }
    flushOut();
    post({ type: 'done', runId: msg.runId, result });
    currentRun = null;
}

self.onmessage = (event) => {
    const msg = event.data;
    // Nachrichten nacheinander abarbeiten (run läuft synchron in Python)
    queue = queue.then(async () => {
        try {
            if (msg.type === 'init') {
                await init(msg);
            } else if (msg.type === 'run') {
                await run(msg);
            }
        } catch (e) {
            if (msg.type === 'init') {
                post({ type: 'init-error', message: String(e?.message ?? e) });
            } else {
                post({ type: 'done', runId: msg.runId, result: { status: 'crash', message: String(e?.message ?? e) } });
            }
        }
    });
};
