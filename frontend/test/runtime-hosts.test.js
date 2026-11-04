/**
 * Tests der Sitzungen von Python, Java und SQL mit gespielten Gegenstellen
 * (Worker bzw. Server-API), ohne Browser.
 */
import test, { mock, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { PythonHost, STATE, SILENCE_LIMIT_MS, HARD_STOP_MS } from '../src/runtime/python.js';
import { JavaSession } from '../src/runtime/java.js';
import { SqlHost, SqlSession } from '../src/runtime/sql.js';

// ---------- Python ----------
class FakeWorker {
    constructor() {
        this.sent = [];
        this.terminated = false;
        this.onmessage = null;
    }

    postMessage(m) {
        this.sent.push(m);
        if (m.type === 'init') {
            queueMicrotask(() => this.onmessage({ data: { type: 'ready' } }));
        }
    }

    terminate() {
        this.terminated = true;
    }

    emit(m) {
        this.onmessage({ data: m });
    }

    get lastRun() {
        return this.sent.filter((m) => m.type === 'run').pop();
    }
}

const live = [];
afterEach(() => {
    // übrig gebliebene Zeitgeber der Sitzungen aufräumen
    live.forEach((s) => s._finish({}));
    live.length = 0;
});

async function startPython(opts = {}, hostOpts = {}) {
    const worker = new FakeWorker();
    const host = new PythonHost({ createWorker: () => worker, shared: false, ...hostOpts });
    const session = host.run({ files: [{ name: 'main.py', content: 'print(1)' }], mainFile: 'main.py', mode: 'run', ...opts });
    live.push(session);
    await host.ready;
    await new Promise((r) => setImmediate(r));
    return { worker, host, session };
}

test('Python: Lauf wird mit Dateien, Paketen und vorgegebener Eingabe gestartet', async () => {
    const { worker, session } = await startPython({ stdin: 'Mia\n5\n', pythonPackages: ['numpy'] });
    const run = worker.lastRun;
    assert.equal(run.mainFile, 'main.py');
    assert.deepEqual(run.packages, ['numpy']);
    assert.deepEqual(run.stdinLines, ['Mia', '5']);
    assert.equal(run.interactive, false);
    const got = [];
    session.onOutput((t, s) => got.push([t, s]));
    let exit = null;
    session.onExit((r) => { exit = r; });
    worker.emit({ type: 'started', runId: run.runId });
    worker.emit({ type: 'output', runId: run.runId, items: [{ stream: 'stdout', text: 'Hallo\n' }, { stream: 'stdin', text: 'Mia\n' }] });
    worker.emit({ type: 'done', runId: run.runId, result: { status: 'ok' } });
    assert.deepEqual(got, [['Hallo\n', 'stdout'], ['Mia\n', 'stdin']]);
    assert.equal(exit.exitCode, 0);
});

test('Python: Fehler wird verständlich ausgegeben und als Diagnose geliefert', async () => {
    const { worker, session } = await startPython();
    const run = worker.lastRun;
    let text = '';
    session.onOutput((t, s) => { if (s === 'stderr') text += t; });
    let exit = null;
    session.onExit((r) => { exit = r; });
    worker.emit({
        type: 'done', runId: run.runId,
        result: { status: 'error', type: 'ZeroDivisionError', message: 'division by zero', file: 'main.py', line: 2, column: null, traceback: 'Traceback...', frames: [{ file: 'main.py', line: 2 }] },
    });
    assert.match(text, /Fehler in main\.py, Zeile 2/);
    assert.match(text, /durch null/);
    assert.equal(exit.exitCode, 1);
    assert.equal(exit.diagnostics[0].line, 2);
    assert.deepEqual(exit.compileErrors, []);
});

test('Python: SyntaxError zählt als Kompilierfehler, Syntaxprüfung liefert Liste', async () => {
    const a = await startPython();
    let exit = null;
    a.session.onExit((r) => { exit = r; });
    a.worker.emit({ type: 'done', runId: a.worker.lastRun.runId, result: { status: 'error', type: 'SyntaxError', message: 'invalid syntax', file: 'main.py', line: 1, column: 7, traceback: '', frames: [] } });
    assert.equal(exit.compileErrors.length, 1);

    const b = await startPython({ mode: 'syntax' });
    assert.equal(b.worker.lastRun.mode, 'syntax');
    let exit2 = null;
    b.session.onExit((r) => { exit2 = r; });
    b.worker.emit({ type: 'done', runId: b.worker.lastRun.runId, result: { status: 'syntax', errors: [{ file: 'a.py', line: 3, column: 2, type: 'SyntaxError', message: 'oops' }] } });
    assert.equal(exit2.exitCode, 1);
    assert.equal(exit2.compileErrors[0].file, 'a.py');
    assert.match(exit2.compileErrors[0].message, /SyntaxError: oops/);
});

test('Python: interaktives input() über den gemeinsamen Speicher', async () => {
    const { worker, host, session } = await startPython({}, { shared: true });
    const run = worker.lastRun;
    assert.equal(run.interactive, true);
    const waiting = [];
    session.onInputRequest((w) => waiting.push(w));
    worker.emit({ type: 'started', runId: run.runId });
    worker.emit({ type: 'input-request', runId: run.runId });
    assert.deepEqual(waiting, [true]);
    session.sendInput('äb\n');
    assert.equal(Atomics.load(host.ctrl, 0), STATE.READY);
    assert.equal(Atomics.load(host.ctrl, 1), 3);
    assert.equal(new TextDecoder().decode(host.data.slice(0, 3)), 'äb');
    assert.deepEqual(waiting, [true, false]);
});

test('Python: früh getippte Eingaben werden bei der Anfrage geliefert', async () => {
    const { worker, host, session } = await startPython({}, { shared: true });
    const run = worker.lastRun;
    worker.emit({ type: 'started', runId: run.runId });
    session.sendInput('1');
    session.sendInput('2');
    assert.equal(Atomics.load(host.ctrl, 0), STATE.IDLE);
    worker.emit({ type: 'input-request', runId: run.runId });
    assert.equal(Atomics.load(host.ctrl, 1), 1);
    assert.equal(String.fromCharCode(host.data[0]), '1');
    Atomics.store(host.ctrl, 0, STATE.IDLE);
    worker.emit({ type: 'input-request', runId: run.runId });
    assert.equal(String.fromCharCode(host.data[0]), '2');
});

test('Python: Stopp unterbricht (SIGINT), bricht das Warten ab und beendet notfalls hart', async () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const { worker, host, session } = await startPython({}, { shared: true });
        const run = worker.lastRun;
        worker.emit({ type: 'started', runId: run.runId });
        worker.emit({ type: 'input-request', runId: run.runId });
        let exit = null;
        session.onExit((r) => { exit = r; });
        session.stop();
        assert.equal(host.interrupt[0], 2);
        assert.equal(Atomics.load(host.ctrl, 0), STATE.CANCEL);
        assert.equal(exit, null);
        mock.timers.tick(HARD_STOP_MS + 10);
        assert.ok(worker.terminated);
        assert.equal(exit.stopped, true);
        assert.equal(host.worker, null);
    } finally {
        mock.timers.reset();
    }
});

test('Python: reagiert das Programm auf den Stopp, endet es ohne Neustart', async () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const { worker, session } = await startPython({}, { shared: true });
        const run = worker.lastRun;
        worker.emit({ type: 'started', runId: run.runId });
        let exit = null;
        session.onExit((r) => { exit = r; });
        session.stop();
        worker.emit({ type: 'done', runId: run.runId, result: { status: 'stopped' } });
        mock.timers.tick(HARD_STOP_MS * 5);
        assert.equal(exit.stopped, true);
        assert.equal(exit.exitCode, 130);
        assert.ok(!worker.terminated);
    } finally {
        mock.timers.reset();
    }
});

test('Python: 60 Sekunden ohne Ausgabe beenden den Worker, Warten auf Eingabe zählt nicht', async () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const a = await startPython({}, { shared: true });
        a.worker.emit({ type: 'started', runId: a.worker.lastRun.runId });
        let exit = null;
        a.session.onExit((r) => { exit = r; });
        mock.timers.tick(SILENCE_LIMIT_MS - 1000);
        a.worker.emit({ type: 'output', runId: a.worker.lastRun.runId, items: [{ stream: 'stdout', text: 'x' }] });
        mock.timers.tick(SILENCE_LIMIT_MS - 1000);
        assert.equal(exit, null);
        mock.timers.tick(2000);
        assert.equal(exit.timedOut, true);
        assert.ok(a.worker.terminated);

        const b = await startPython({}, { shared: true });
        b.worker.emit({ type: 'started', runId: b.worker.lastRun.runId });
        b.worker.emit({ type: 'input-request', runId: b.worker.lastRun.runId });
        let exit2 = null;
        b.session.onExit((r) => { exit2 = r; });
        mock.timers.tick(SILENCE_LIMIT_MS * 3);
        assert.equal(exit2, null);
    } finally {
        mock.timers.reset();
    }
});

test('Python: ohne gemeinsamen Speicher gibt es keine Eingaben, Stopp beendet den Worker sofort', async () => {
    const { worker, host, session } = await startPython();
    assert.equal(host.interactive, false);
    worker.emit({ type: 'started', runId: worker.lastRun.runId });
    session.sendInput('x');
    let exit = null;
    session.onExit((r) => { exit = r; });
    session.stop();
    assert.ok(worker.terminated);
    assert.equal(exit.stopped, true);
});

test('Python: Worker-Fehler beim Laden endet mit Meldung', async () => {
    const worker = new FakeWorker();
    worker.postMessage = function postMessage(m) {
        this.sent.push(m);
        if (m.type === 'init') {
            queueMicrotask(() => this.onmessage({ data: { type: 'init-error', message: 'kaputt' } }));
        }
    };
    const host = new PythonHost({ createWorker: () => worker, shared: false });
    const session = host.run({ files: [], mainFile: 'main.py' });
    const exit = await new Promise((resolve) => session.onExit(resolve));
    assert.match(exit.error, /kaputt/);
    assert.ok(worker.terminated);
});

// ---------- Java ----------
const flush = () => new Promise((r) => setTimeout(r, 15));

function javaApi(script) {
    const calls = [];
    return {
        calls,
        post: async (path, body) => {
            calls.push(['POST', path, body]);
            return script.post(path, body);
        },
        get: async (path) => {
            calls.push(['GET', path]);
            return script.get(path);
        },
    };
}

test('Java: Start, Abfrage mit Offset, Kompilierfehler als Diagnosen', async () => {
    let n = 0;
    const api = javaApi({
        post: () => ({ ok: true, data: { run_id: 'r_1' } }),
        get: () => {
            n++;
            if (n === 1) {
                return { ok: true, data: { output: [{ stream: 'stdout', text: 'a' }], offset: 1, finished: false } };
            }
            return {
                ok: true,
                data: {
                    output: [], offset: 1, finished: true, exit_code: 1, timed_out: false,
                    compile_errors: [{ file: 'Main.java', line: 3, column: 5, message: "';' expected" }], gui_frames: [],
                },
            };
        },
    });
    const s = new JavaSession({
        examId: 12, task: { id: 102 }, javaVersion: '21', mainFile: 'Main.java', mode: 'run', stdin: null,
        files: [{ name: 'Main.java', content: 'x', readonly: false }, { name: 'Lib.java', content: 'y', readonly: true }],
    }, { get: api.get, post: api.post, pollMs: 1 });
    const out = [];
    s.onOutput((t, st) => out.push([t, st]));
    const exit = new Promise((resolve) => s.onExit(resolve));
    await s.start();
    const result = await exit;
    const start = api.calls[0];
    assert.equal(start[1], '/api/ausfuehren');
    assert.deepEqual(start[2], {
        language: 'java', java_version: '21', files: [{ name: 'Main.java', content: 'x' }], main_file: 'Main.java',
        mode: 'run', stdin: null, gui: false, exam_id: 12, task_id: 102,
    });
    assert.match(api.calls[1][1], /\/api\/ausfuehren\/r_1\?offset=0/);
    assert.match(api.calls[2][1], /offset=1/);
    assert.equal(out[0][0], 'a');
    assert.match(out[1][0], /Zeile 3: ';' expected/);
    assert.equal(result.exitCode, 1);
    assert.equal(result.compileErrors[0].column, 5);
    assert.equal(result.compileErrors[0].severity, 'error');
});

test('Java: ohne Aufgabe (Lehrkraft) gehen alle Dateien und keine IDs mit', async () => {
    const api = javaApi({ post: () => ({ ok: false, status: 429, error: 'Zu viele Starts.' }), get: () => null });
    const s = new JavaSession({ task: null, mainFile: 'A.java', mode: 'syntax', files: [{ name: 'A.java', content: 'c', readonly: true }] }, { ...api, pollMs: 1 });
    const out = [];
    s.onOutput((t) => out.push(t));
    const exit = new Promise((resolve) => s.onExit(resolve));
    await s.start();
    const result = await exit;
    assert.deepEqual(api.calls[0][2].files, [{ name: 'A.java', content: 'c' }]);
    assert.equal(api.calls[0][2].mode, 'syntax');
    assert.ok(!('exam_id' in api.calls[0][2]));
    assert.ok(!('task_id' in api.calls[0][2]));
    assert.match(result.error, /Zu viele Starts/);
    assert.match(out.join(''), /Zu viele Starts/);
});

test('Java: kurze Netzstörungen werden überbrückt, dauerhafte beenden den Lauf', async () => {
    let n = 0;
    const flaky = javaApi({
        post: () => ({ ok: true, data: { run_id: 'r_2' } }),
        get: () => {
            n++;
            if (n <= 2) {
                return { ok: false, transient: true, error: 'x' };
            }
            return { ok: true, data: { output: [], offset: 0, finished: true, exit_code: 0 } };
        },
    });
    const s = new JavaSession({ mainFile: 'M.java', files: [] }, { ...flaky, pollMs: 1 });
    const exit = new Promise((resolve) => s.onExit(resolve));
    await s.start();
    assert.equal((await exit).exitCode, 0);

    const dead = javaApi({ post: () => ({ ok: true, data: { run_id: 'r_3' } }), get: () => ({ ok: false, transient: true, error: 'x' }) });
    const s2 = new JavaSession({ mainFile: 'M.java', files: [] }, { ...dead, pollMs: 1 });
    const exit2 = new Promise((resolve) => s2.onExit(resolve));
    await s2.start();
    assert.match((await exit2).error, /Verbindung/);
});

test('Java: Eingabe und Stopp gehen an die richtigen Adressen, GUI-Bilder werden weitergereicht', async () => {
    let finished = false;
    const frames = [];
    const api = javaApi({
        post: (path) => (path === '/api/ausfuehren' ? { ok: true, data: { run_id: 'r_4' } } : { ok: true, data: {} }),
        get: () => (finished
            ? { ok: true, data: { output: [], offset: 0, finished: true, exit_code: null, timed_out: false } }
            : { ok: true, data: { output: [], offset: 0, finished: false, gui_frames: [{ id: 1 }] } }),
    });
    const s = new JavaSession({ mainFile: 'M.java', files: [], onGuiFrames: (f) => frames.push(...f) }, { ...api, pollMs: 1 });
    const exit = new Promise((resolve) => s.onExit(resolve));
    await s.start();
    s.sendInput('42');
    await flush();
    s.stop();
    await flush();
    finished = true;
    const result = await exit;
    const posts = api.calls.filter((c) => c[0] === 'POST').map((c) => [c[1], c[2]]);
    assert.deepEqual(posts[1], ['/api/ausfuehren/r_4/eingabe', { text: '42\n' }]);
    assert.equal(posts[2][0], '/api/ausfuehren/r_4/stopp');
    assert.ok(frames.length > 0);
    assert.equal(result.stopped, true);
});

test('Java: Zeitlimit wird gemeldet', async () => {
    const api = javaApi({
        post: () => ({ ok: true, data: { run_id: 'r_5' } }),
        get: () => ({ ok: true, data: { output: [], offset: 0, finished: true, exit_code: null, timed_out: true } }),
    });
    const s = new JavaSession({ mainFile: 'M.java', files: [] }, { ...api, pollMs: 1 });
    const out = [];
    s.onOutput((t) => out.push(t));
    const exit = new Promise((resolve) => s.onExit(resolve));
    await s.start();
    const result = await exit;
    assert.equal(result.timedOut, true);
    assert.match(out.join(''), /Zeitlimit/);
});

// ---------- SQL ----------
const fakeSqlHost = (answer) => ({ request: async () => answer, discard() {}, requests: [] });

test('SQL: Ergebnisse je Anweisung, Fehlermeldung in der Konsole', async () => {
    const host = fakeSqlHost({
        results: [
            { sql: 'CREATE TABLE t(a)', columns: [], rows: [], rowsModified: 0 },
            { sql: 'SELECT a FROM t', columns: ['a'], rows: [[1]], totalRows: 1 },
            { sql: 'SELEKT 1', error: 'near "SELEKT": syntax error' },
        ],
    });
    const s = new SqlSession(host, { files: [{ name: 'abfrage.sql', content: 'SELECT 1;' }], mainFile: 'abfrage.sql', sqlSetup: '', mode: 'run' });
    const out = [];
    s.onOutput((t, st) => out.push([t, st]));
    const exit = new Promise((resolve) => s.onExit(resolve));
    s._begin();
    const r = await exit;
    assert.equal(r.sqlResults.length, 3);
    assert.equal(r.exitCode, 1);
    assert.match(out[0][0], /Fehler in Anweisung 3/);
    assert.equal(out[0][1], 'stderr');
});

test('SQL: Setup-Fehler, leere Datei und Syntaxmodus', async () => {
    const bad = new SqlSession(fakeSqlHost({ setupError: 'no such table: x', results: [] }), { files: [{ name: 'a.sql', content: 'SELECT 1' }], mainFile: 'a.sql' });
    const e1 = new Promise((resolve) => bad.onExit(resolve));
    bad._begin();
    assert.match((await e1).error, /Einrichtungsfehler/);

    const empty = new SqlSession(fakeSqlHost({ results: [] }), { files: [{ name: 'a.sql', content: '  \n' }], mainFile: 'a.sql' });
    const e2 = new Promise((resolve) => empty.onExit(resolve));
    empty._begin();
    assert.equal((await e2).exitCode, 0);

    const syn = new SqlSession(fakeSqlHost({ results: [{ sql: 'SELECT 1', columns: ['1'], rows: [[1]], totalRows: 1 }] }), { files: [{ name: 'a.sql', content: 'SELECT 1' }], mainFile: 'a.sql', mode: 'syntax' });
    const e3 = new Promise((resolve) => syn.onExit(resolve));
    syn._begin();
    assert.deepEqual((await e3).sqlResults, []);
});

test('SQL: Stopp verwirft den Worker', async () => {
    let discarded = false;
    const host = { request: () => new Promise(() => {}), discard: () => { discarded = true; } };
    const s = new SqlSession(host, { files: [{ name: 'a.sql', content: 'SELECT 1' }], mainFile: 'a.sql' });
    const exit = new Promise((resolve) => s.onExit(resolve));
    s._begin();
    s.stop();
    const r = await exit;
    assert.ok(discarded);
    assert.equal(r.stopped, true);
});

test('SQL: SqlHost fordert den Worker an und liefert das Schema', async () => {
    const posted = [];
    const worker = {
        postMessage(m) {
            posted.push(m);
            if (m.type === 'init') {
                queueMicrotask(() => this.onmessage({ data: { type: 'ready' } }));
            } else {
                queueMicrotask(() => this.onmessage({ data: { type: 'schema', runId: m.runId, setupError: null, tables: [{ name: 't', type: 'table', columns: [{ name: 'a', type: 'INT', pk: 1 }] }] } }));
            }
        },
        terminate() {},
    };
    const host = new SqlHost({ createWorker: () => worker, assets: { sqljs: '/assets/vendor/sqljs/' } });
    const schema = await host.schema('CREATE TABLE t(a INT PRIMARY KEY);');
    assert.equal(schema[0].columns[0].pk, true);
    assert.equal(posted[0].type, 'init');
    assert.match(posted[0].base, /\/assets\/vendor\/sqljs\/$/);
    assert.equal(posted[1].setup, 'CREATE TABLE t(a INT PRIMARY KEY);');
});
