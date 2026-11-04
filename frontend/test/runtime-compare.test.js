import test from 'node:test';
import assert from 'node:assert/strict';
import { compareOutput, parseRegex, visibleWhitespace } from '../src/runtime/compare.js';
import { evaluateSample, runSampleTests, collectSession } from '../src/runtime/sample-tests.js';
import { BaseSession } from '../src/runtime/session.js';

test('exact: nur Zeilenenden werden vereinheitlicht', () => {
    assert.ok(compareOutput('7\r\n', '7\n', 'exact').ok);
    assert.ok(!compareOutput('7\n', '7', 'exact').ok);
    assert.ok(!compareOutput(' 7\n', '7\n', 'exact').ok);
});

test('trim: Ränder egal, Innenraum zählt', () => {
    assert.ok(compareOutput('\n 7 \n\n', '7', 'trim').ok);
    assert.ok(!compareOutput('7  8', '7 8', 'trim').ok);
    assert.ok(compareOutput('a\nb\n', 'a\r\nb', 'trim').ok);
});

test('trim ist der Standard, unbekannte Modi fallen darauf zurück', () => {
    assert.ok(compareOutput('x\n', 'x').ok);
    assert.ok(compareOutput('x\n', 'x', 'unbekannt').ok);
});

test('ignore_whitespace: Leerraum zählt als ein Leerzeichen', () => {
    assert.ok(compareOutput('a   b\n\n c', 'a b c', 'ignore_whitespace').ok);
    assert.ok(!compareOutput('ab', 'a b', 'ignore_whitespace').ok);
});

test('contains: erwartete Ausgabe irgendwo enthalten', () => {
    assert.ok(compareOutput('Ergebnis: 42 Punkte\n', '42', 'contains').ok);
    assert.ok(!compareOutput('Ergebnis: 41', '42', 'contains').ok);
});

test('regex: mit und ohne Begrenzer, Flags, ungültige Muster', () => {
    assert.ok(compareOutput('Summe = 12', 'Summe = \\d+', 'regex').ok);
    assert.ok(compareOutput('SUMME = 12', '/summe = \\d+/i', 'regex').ok);
    assert.ok(!compareOutput('Summe = x', 'Summe = \\d+', 'regex').ok);
    const bad = compareOutput('x', '(', 'regex');
    assert.ok(!bad.ok);
    assert.match(bad.message, /ungültig/);
    assert.deepEqual(parseRegex('/a/gxi'), { pattern: 'a', flags: 'i' });
});

test('sichtbare Leerzeichen im Testbericht', () => {
    assert.equal(visibleWhitespace('a 	b'), 'a →b');
    assert.equal(visibleWhitespace('x  '), 'x··');
    assert.ok(visibleWhitespace('x'.repeat(1000)).length < 450);
});

const fakeSession = (stdout, result) => {
    const s = new BaseSession();
    setTimeout(() => {
        s._output(stdout, 'stdout');
        s._finish(result);
    }, 0);
    return s;
};

test('evaluateSample: Programmausgabe, Fehler, Zeitlimit', () => {
    const t = { name: 'T1', expected_output: '7\n', compare: 'trim' };
    assert.ok(evaluateSample(t, 'python', { stdout: '7', stderr: '', result: { exitCode: 0 } }).ok);
    const wrong = evaluateSample(t, 'python', { stdout: '8', stderr: '', result: { exitCode: 0 } });
    assert.ok(!wrong.ok);
    assert.equal(wrong.actual, '8');
    const crash = evaluateSample(t, 'python', { stdout: '', stderr: 'Boom', result: { exitCode: 1 } });
    assert.match(crash.message, /Fehler/);
    assert.match(evaluateSample(t, 'java', { stdout: '', stderr: '', result: { timedOut: true } }).message, /zu lange/);
    assert.match(evaluateSample(t, 'java', { stdout: '', stderr: '', result: { compileErrors: [{ line: 1 }] } }).message, /übersetzen/);
    assert.equal(evaluateSample({ expected_output: '1' }, 'python', { stdout: '1', result: {} }, 2).name, 'Beispiel 3');
});

test('evaluateSample für SQL vergleicht die letzte Ergebnistabelle', () => {
    const results = [
        { kind: 'ok', sql: 'create' },
        { kind: 'rows', columns: ['n', 'v'], rows: [['a', 1], ['b', null]] },
    ];
    const t = { name: 'SQL', expected_output: 'a|1\nb|NULL\n', compare: 'exact' };
    assert.ok(evaluateSample(t, 'sql', { stdout: '', result: { sqlResults: results } }).ok);
    const err = evaluateSample(t, 'sql', { stdout: '', result: { sqlResults: [{ kind: 'error', message: 'kaputt' }] } });
    assert.match(err.message, /kaputt/);
});

test('runSampleTests führt der Reihe nach aus und meldet Fortschritt', async () => {
    const seen = [];
    const progress = [];
    const results = await runSampleTests({
        tests: [{ input: '1\n', expected_output: '1' }, { input: '2\n', expected_output: '3' }],
        language: 'python',
        runOne: async (test) => {
            seen.push(test.input);
            return collectSession(fakeSession(test.input.trim(), { exitCode: 0 }));
        },
        onProgress: (d, n) => progress.push([d, n]),
    });
    assert.deepEqual(seen, ['1\n', '2\n']);
    assert.deepEqual(results.map((r) => r.ok), [true, false]);
    assert.deepEqual(progress, [[1, 2], [2, 2]]);
});

test('runSampleTests bricht ab, wenn isCancelled gesetzt ist', async () => {
    let n = 0;
    const results = await runSampleTests({
        tests: [{}, {}, {}],
        language: 'python',
        isCancelled: () => n >= 1,
        runOne: async () => {
            n++;
            return { stdout: '', stderr: '', result: {} };
        },
    });
    assert.equal(results.length, 1);
});

test('Sitzung puffert Ereignisse bis der Zuhörer da ist und meldet Ende nur einmal', () => {
    const s = new BaseSession();
    s._output('a', 'stdout');
    s._output('b', 'stderr');
    s._finish({ exitCode: 0 });
    s._finish({ exitCode: 1 });
    const got = [];
    s.onOutput((t, st) => got.push([t, st]));
    const exits = [];
    s.onExit((r) => exits.push(r.exitCode));
    assert.deepEqual(got, [['a', 'stdout'], ['b', 'stderr']]);
    assert.deepEqual(exits, [0]);
    s._output('nach Ende', 'stdout');
    assert.equal(got.length, 2);
});
