import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createPlayer, SPEEDS } from '../src/robo/player.js';
import { createTraceFilter, TRACE_PREFIX } from '../src/robo/trace-filter.js';
import { wrapRuntime, WORLD_FILE } from '../src/robo/session.js';
import { BaseSession } from '../src/runtime/session.js';

const world = { cols: 5, rows: 3, robo: { x: 0, y: 0, dir: 'E' }, walls: [[3, 0]], boxes: [], screws: [{ x: 1, y: 0, count: 1 }], targets: [] };

/** Zeitgeber ohne echte Zeit: run() führt alle fälligen Rückrufe der Reihe nach aus. */
function fakeScheduler() {
    const queue = [];
    let id = 0;
    return {
        setTimeout: (fn, ms) => { queue.push({ id: ++id, fn, ms }); return id; },
        clearTimeout: (n) => { const i = queue.findIndex((q) => q.id === n); if (i >= 0) queue.splice(i, 1); },
        pending: () => queue.length,
        runNext() { const q = queue.shift(); q?.fn(); return !!q; },
        runAll(limit = 100000) { let n = 0; while (queue.length && n++ < limit) queue.shift().fn(); },
        delays: () => queue.map((q) => q.ms),
    };
}

function setup(extra = {}) {
    const sched = fakeScheduler();
    const log = { steps: [], resets: 0, states: [] };
    const player = createPlayer({
        world, scheduler: sched, ...extra,
        onStep: (s) => log.steps.push(s.code + (s.crashed ? '!' : '')),
        onReset: () => { log.resets++; },
        onState: (s) => log.states.push(s),
    });
    return { sched, log, player };
}

test('spielt Züge nacheinander im gewählten Takt ab', () => {
    const { sched, log, player } = setup();
    player.setSpeed(2); // 6 Schritte pro Sekunde
    player.begin();
    player.push(['v', 'a', 'r']);
    assert.deepEqual(sched.delays(), [0], 'erster Zug sofort');
    sched.runNext();
    assert.deepEqual(log.steps, ['v']);
    assert.equal(sched.delays()[0], Math.round(1000 / SPEEDS[2]));
    sched.runAll();
    assert.deepEqual(log.steps, ['v', 'a', 'r']);
    assert.equal(player.state.status, 'playing', 'wartet auf weitere Züge, bis das Programm endet');
    player.programEnded();
    assert.equal(player.state.status, 'done');
    assert.equal(player.world.basket, 1);
});

test('Pause, Einzelschritt, Weiter', () => {
    const { sched, log, player } = setup();
    player.begin();
    player.push(['v', 'l', 'l']);
    sched.runNext();
    player.pause();
    assert.equal(player.state.status, 'paused');
    assert.equal(sched.pending(), 0);
    player.step();
    assert.deepEqual(log.steps, ['v', 'l']);
    assert.equal(player.state.status, 'paused');
    player.play();
    sched.runAll();
    assert.deepEqual(log.steps, ['v', 'l', 'l']);
});

test('Fehler im Protokoll beendet die Wiedergabe mit Meldung', () => {
    const { sched, log, player } = setup();
    player.begin();
    player.push(['v', 'v', 'v', 'v', 'v']); // Wand bei Feld 3
    player.programEnded();
    sched.runAll();
    assert.equal(player.state.status, 'crashed');
    assert.match(player.state.message, /Wand/);
    assert.equal(log.steps.at(-1), 'v!');
    assert.equal(player.world.crashed, true);
});

test('Fehlerzeile "!Text" aus der Bibliothek wird als Absturz gezeigt', () => {
    const { sched, player } = setup();
    player.begin();
    player.push(['v', '!Hier liegt keine Schraube, die Robo aufheben kann.']);
    player.programEnded();
    sched.runAll();
    assert.equal(player.state.status, 'crashed');
    assert.equal(player.state.message, 'Hier liegt keine Schraube, die Robo aufheben kann.');
});

test('Zurücksetzen behält das Protokoll, Nochmal spielt es erneut ab', () => {
    const { sched, log, player } = setup();
    player.begin();
    player.push(['v', 'a']);
    player.programEnded();
    sched.runAll();
    assert.equal(player.state.status, 'done');
    player.reset();
    assert.equal(player.world.x, 0);
    assert.equal(player.world.basket, 0);
    assert.equal(player.state.status, 'paused');
    assert.equal(player.state.total, 2);
    player.play();
    sched.runAll();
    assert.equal(player.state.status, 'done');
    assert.equal(log.steps.length, 4);
});

test('Sofort-Modus wendet alle Züge in einem Rutsch an', () => {
    const { sched, log, player } = setup();
    player.setSpeed(SPEEDS.length - 1);
    player.begin();
    player.push(['l', 'r', 'l', 'r', 'v']);
    player.programEnded();
    sched.runNext();
    assert.equal(log.steps.length, 5);
    assert.equal(player.state.status, 'done');
});

test('neuer Lauf leert das Protokoll', () => {
    const { sched, log, player } = setup();
    player.begin();
    player.push(['v']);
    sched.runAll();
    player.begin();
    assert.equal(player.state.total, 0);
    assert.equal(player.world.x, 0);
    assert.equal(log.resets, 2);
});

test('Trace-Filter: Protokollzeilen herausziehen, Rest durchreichen', () => {
    const codes = [];
    const text = [];
    const f = createTraceFilter({ onCode: (c) => codes.push(c), onText: (t) => text.push(t) });
    f.write(`Hallo\n${TRACE_PREFIX}v\n${TRACE_PREFIX}r\r\nWelt\n`);
    f.write('Eingabe: ');
    f.write(`${TRACE_PREFIX.slice(0, 3)}`);
    f.write(`${TRACE_PREFIX.slice(3)}a\n`);
    f.write('Zweite Zeile\n');
    f.write(`${TRACE_PREFIX}!Wand`);
    f.write('\nEnde');
    f.end();
    assert.deepEqual(codes, ['v', 'r', 'a', '!Wand']);
    // Das Präfix mitten in der Zeile ("Eingabe: " ohne Zeilenende) wird erkannt, der Text davor bleibt sichtbar.
    assert.equal(text.join(''), 'Hallo\nWelt\nEingabe: Zweite Zeile\nEnde');
});

test('Trace-Filter: Zeichen für Zeichen und ungültige Codes', () => {
    const codes = [];
    const text = [];
    const f = createTraceFilter({ onCode: (c) => codes.push(c), onText: (t) => text.push(t) });
    for (const ch of `x${TRACE_PREFIX}v\n${TRACE_PREFIX}zzz\n\u0001andere\n`) {
        f.write(ch);
    }
    f.end();
    assert.deepEqual(codes, ['v']);
    assert.equal(text.join(''), 'x\u0001andere\n');
});

// ---------- Sitzung ----------

class FakeSession extends BaseSession {
    constructor() { super(); this.inputs = []; this.stopped = false; }
    sendInput(t) { this.inputs.push(t); }
    stop() { this.stopped = true; }
}

function fakeRuntime(inner) {
    const calls = [];
    return { calls, canRun: () => true, interactiveInput: true, run(opts) { calls.push(opts); return inner; } };
}

test('Java: Welt als Eingabezeile, Protokoll aus der Ausgabe in den Player', () => {
    const { sched, player } = setup();
    const inner = new FakeSession();
    const runtime = fakeRuntime(inner);
    const wrapped = wrapRuntime(runtime, { language: 'java', getWorld: () => world, player });
    assert.equal(wrapped.canRun(), true);
    assert.equal(wrapped.interactiveInput, true);
    const session = wrapped.run({ files: [{ name: 'Main.java', content: '' }], mode: 'run', stdin: null });
    assert.match(runtime.calls[0].stdin, /^@KWROBO \{/);
    const seen = [];
    session.onOutput((t, s) => seen.push([t, s]));
    inner._output(`${TRACE_PREFIX}v\nText\n${TRACE_PREFIX}a\n`, 'stdout');
    inner._output('Fehler\n', 'stderr');
    let exited = null;
    session.onExit((r) => { exited = r; });
    inner._finish({ exitCode: 0 });
    sched.runAll();
    assert.deepEqual(seen, [['Text\n', 'stdout'], ['Fehler\n', 'stderr']]);
    assert.equal(exited.exitCode, 0);
    assert.equal(player.state.status, 'done');
    assert.equal(player.world.basket, 1);
});

test('Python: Welt als Datei, Züge über die Turtle-Nachricht', () => {
    const { sched, player } = setup();
    const inner = new FakeSession();
    const runtime = fakeRuntime(inner);
    const wrapped = wrapRuntime(runtime, { language: 'python', getWorld: () => world, player });
    const session = wrapped.run({ files: [{ name: 'main.py', content: '' }, { name: WORLD_FILE, content: 'alt' }], mode: 'run' });
    const files = runtime.calls[0].files;
    assert.equal(files.filter((f) => f.name === WORLD_FILE).length, 1);
    assert.equal(JSON.parse(files.find((f) => f.name === WORLD_FILE).content).cols, 5);
    const turtle = [];
    session.onTurtle((c) => turtle.push(c));
    inner._emit('turtle', [{ robo: 'v' }, { cmd: 'forward' }, { robo: 'zzz' }, { robo: 'a' }]);
    inner._finish({ exitCode: 0 });
    sched.runAll();
    assert.deepEqual(turtle, [[{ cmd: 'forward' }]]);
    assert.equal(player.state.steps, 2);
    session.sendInput('x');
    assert.deepEqual(inner.inputs, ['x']);
});

test('Syntaxprüfung läuft ohne Welt und ohne Hülle', () => {
    const { player } = setup();
    const inner = new FakeSession();
    const runtime = fakeRuntime(inner);
    const wrapped = wrapRuntime(runtime, { language: 'python', getWorld: () => world, player });
    const session = wrapped.run({ files: [{ name: 'main.py', content: '' }], mode: 'syntax' });
    assert.equal(session, inner);
    assert.equal(runtime.calls[0].files.length, 1);
});
