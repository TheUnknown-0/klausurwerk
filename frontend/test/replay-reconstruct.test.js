import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ChangeSet, Text } from '@codemirror/state';
import { Reconstructor, applyStep, emptyState, normalizeSteps } from '../src/replay/reconstruct.js';
import { GAP_MS, SHORT_GAP_MS, buildTimeline, formatGap, indexAt, nextMarker } from '../src/replay/timeline.js';

/** Baut aus Text-Änderungen eine Schrittfolge, wie der Editor sie speichern würde. */
function editor(initial = '') {
    let doc = Text.of(initial.split('\n'));
    return {
        get text() { return doc.toString(); },
        change(from, to, insert) {
            const set = ChangeSet.of({ from, to, insert }, doc.length);
            doc = set.apply(doc);
            return set.toJSON();
        },
    };
}

const op = (seq, payload, extra = {}) => ({ seq, task_id: 5, client_ts: 1_000_000 + seq * 1000, kind: 'ops', payload, is_paste: false, is_overtime: false, ...extra });
const snap = (seq, file, text, extra = {}) => ({ seq, task_id: 5, client_ts: 1_000_000 + seq * 1000, kind: 'snapshot', payload: { file, text }, is_paste: false, is_overtime: false, ...extra });

test('Snapshot und Änderungen ergeben den Text', () => {
    const ed = editor('class A {}');
    const steps = normalizeSteps([
        snap(1, 'Main.java', 'class A {}'),
        op(2, { file: 'Main.java', changes: ed.change(9, 9, '\n  int x;\n') }),
        op(3, { file: 'Main.java', changes: ed.change(0, 5, 'public class') }),
    ]);
    const r = new Reconstructor(steps);
    assert.equal(r.stateAt(0).files.get('Main.java').text, 'class A {}');
    assert.equal(r.stateAt(1).files.get('Main.java').text, 'class A {\n  int x;\n}');
    const last = r.stateAt(2);
    assert.equal(last.files.get('Main.java').text, ed.text);
    assert.equal(last.unsure, false);
    assert.equal(last.hasGap, false);
    assert.deepEqual(last.lastChange, { file: 'Main.java', fromLine: 1, toLine: 1 });
});

test('mehrere Dateien getrennt', () => {
    const a = editor('a');
    const b = editor('b');
    const steps = normalizeSteps([
        snap(1, 'A.java', 'a'), snap(2, 'B.java', 'b'),
        op(3, { file: 'A.java', changes: a.change(1, 1, '1') }),
        op(4, { file: 'B.java', changes: b.change(0, 1, 'zzz') }),
    ]);
    const s = new Reconstructor(steps).stateAt(3);
    assert.equal(s.files.get('A.java').text, 'a1');
    assert.equal(s.files.get('B.java').text, 'zzz');
    assert.equal(s.file, 'B.java');
});

test('Lücke in seq: unsicher bis zum nächsten Snapshot, danach wieder sicher', () => {
    const ed = editor('abc');
    const steps = normalizeSteps([
        snap(1, 'M.py', 'abc'),
        op(2, { file: 'M.py', changes: ed.change(3, 3, 'd') }),
        // Schritte 3 und 4 gingen verloren
        op(5, { file: 'M.py', changes: (ed.change(4, 4, 'e'), ed.change(5, 5, 'f')) }),
        snap(6, 'M.py', 'abcdefg'),
    ]);
    assert.equal(steps[2].gapBefore, true);
    const r = new Reconstructor(steps);
    const broken = r.stateAt(2);
    assert.equal(broken.hasGap, true);
    assert.equal(broken.unsure, true, 'Änderung passt nicht zum Text: wird übersprungen');
    assert.equal(broken.files.get('M.py').text, 'abcd');
    const fixed = r.stateAt(3);
    assert.equal(fixed.files.get('M.py').text, 'abcdefg');
    assert.equal(fixed.unsure, false);
    assert.equal(fixed.hasGap, true, 'Hinweis auf die Lücke bleibt');
});

test('Änderung ohne bekannten Text beginnt leer und gilt als unsicher', () => {
    const steps = normalizeSteps([op(7, { file: 'X.py', changes: [[0, 'hallo']] })]);
    const s = new Reconstructor(steps).stateAt(0);
    assert.equal(s.files.get('X.py').text, 'hallo');
    assert.equal(s.unsure, true);
});

test('value-Schritte: Inhalt und Dateien', () => {
    const steps = normalizeSteps([
        { seq: 1, kind: 'value', payload: { selected: ['a'] }, client_ts: 10 },
        { seq: 2, kind: 'value', payload: { selected: ['b'] }, client_ts: 20 },
        { seq: 3, kind: 'value', payload: { files: [{ name: 'Main.java', content: 'x' }] }, client_ts: 30 },
    ]);
    const r = new Reconstructor(steps);
    assert.deepEqual(r.stateAt(1).value, { selected: ['b'] });
    const s = r.stateAt(2);
    assert.equal(s.files.get('Main.java').text, 'x');
    assert.equal(s.file, 'Main.java');
});

test('Nutzdaten als JSON-Text, unsortiert, doppelte seq, ungültige Schritte', () => {
    const steps = normalizeSteps([
        { seq: 3, kind: 'snapshot', payload: JSON.stringify({ file: 'a', text: 'drei' }), client_ts: 3 },
        { seq: 1, kind: 'snapshot', payload: { file: 'a', text: 'eins' }, client_ts: 1 },
        { seq: 1, kind: 'snapshot', payload: { file: 'a', text: 'eins neu' }, client_ts: 1 },
        { seq: 'x', kind: 'snapshot', payload: {} },
        { seq: 4, kind: 'unbekannt', payload: {} },
        null,
    ]);
    assert.deepEqual(steps.map((s) => s.seq), [1, 3]);
    assert.equal(new Reconstructor(steps).stateAt(0).files.get('a').text, 'eins neu');
    assert.equal(new Reconstructor(steps).stateAt(1).files.get('a').text, 'drei');
});

test('Zwischenstände: Springen gibt dasselbe wie lineares Abspielen', () => {
    const ed = editor('');
    const raw = [snap(1, 'M.py', '')];
    for (let i = 0; i < 230; i++) {
        raw.push(op(2 + i, { file: 'M.py', changes: ed.change(i, i, String.fromCharCode(97 + (i % 26))) }));
        if (i % 50 === 49) {
            raw.push(snap(1000 + i, 'M.py', ed.text, { seq: 2 + i + 0.5 }));
        }
    }
    const steps = normalizeSteps(raw.filter((s) => Number.isInteger(s.seq)));
    const r1 = new Reconstructor(steps, 10);
    const linear = emptyState();
    steps.forEach((s, i) => {
        applyStep(linear, s);
        if (i === 100 || i === 17 || i === steps.length - 1) {
            assert.equal(new Reconstructor(steps, 10).stateAt(i).files.get('M.py').text, linear.files.get('M.py').text, `Index ${i}`);
        }
    });
    // vorwärts, rückwärts, wieder vorwärts mit demselben Objekt
    const a = r1.stateAt(150).files.get('M.py').text;
    const b = r1.stateAt(20).files.get('M.py').text;
    const c = r1.stateAt(150).files.get('M.py').text;
    assert.equal(a, c);
    assert.equal(b.length, 20);
    assert.equal(r1.stateAt(-1).files.size, 0);
    assert.equal(r1.stateAt(99999).files.get('M.py').text, ed.text);
});

// ---------- Zeitachse ----------

const stepsAt = (times, extra = {}) => times.map((ts, i) => ({ ts, isPaste: !!extra.paste?.includes(i), isOvertime: !!extra.over?.includes(i) }));

test('Zeitachse: Wiedergabezeit folgt den Zeitstempeln, lange Pausen werden verkürzt', () => {
    const t = buildTimeline(stepsAt([1000, 1500, 2500, 2500 + GAP_MS + 1, 2500 + GAP_MS + 501]));
    assert.deepEqual(t.virtual, [0, 500, 1500, 1500 + SHORT_GAP_MS, 1500 + SHORT_GAP_MS + 500]);
    assert.equal(t.total, 1500 + SHORT_GAP_MS + 500);
    assert.deepEqual(t.skipped, [{ index: 3, ms: GAP_MS + 1 }]);
    assert.equal(t.real[2], 2500);
});

test('Zeitachse: genau eine Minute wird nicht verkürzt; fehlende und rückwärts laufende Zeiten', () => {
    const t = buildTimeline(stepsAt([0, GAP_MS]));
    assert.equal(t.skipped.length, 0);
    assert.equal(t.virtual[1], GAP_MS);
    const odd = buildTimeline([{ ts: 5000 }, { ts: null }, { ts: 4000 }, { ts: 6000 }]);
    assert.deepEqual(odd.real, [5000, 5000, 5000, 6000]);
    assert.ok(odd.virtual.every((v, i) => i === 0 || v > odd.virtual[i - 1]), 'streng steigend');
    assert.equal(buildTimeline([]).total, 0);
});

test('Marker für Einfügungen und Überziehung, Suchen und Springen', () => {
    const t = buildTimeline(stepsAt([0, 1000, 2000, 3000, 4000], { paste: [1, 3], over: [3, 4] }));
    assert.deepEqual(t.markers.map((m) => [m.index, m.type]), [[1, 'paste'], [3, 'paste'], [3, 'overtime'], [4, 'overtime']]);
    assert.equal(nextMarker(t, 'paste', -1, 1).index, 1);
    assert.equal(nextMarker(t, 'paste', 1, 1).index, 3);
    assert.equal(nextMarker(t, 'paste', 3, 1), null);
    assert.equal(nextMarker(t, 'paste', 3, -1).index, 1);
    assert.equal(nextMarker(t, 'paste', 1, -1), null);
    assert.equal(nextMarker(t, 'overtime', 0, 1).index, 3);
});

test('Index zu einer Wiedergabezeit', () => {
    const t = buildTimeline(stepsAt([0, 1000, 2000]));
    assert.equal(indexAt(t, -5), -1);
    assert.equal(indexAt(t, 0), 0);
    assert.equal(indexAt(t, 999), 0);
    assert.equal(indexAt(t, 1000), 1);
    assert.equal(indexAt(t, 99999), 2);
    assert.equal(indexAt(buildTimeline([]), 10), -1);
});

test('Pausen lesbar benennen', () => {
    assert.equal(formatGap(5 * 60000), '5 Minuten');
    assert.equal(formatGap(60000), '1 Minute');
    assert.equal(formatGap(3600000), '1 Stunde');
    assert.equal(formatGap(3 * 3600000 + 5 * 60000), '3 Stunden 5 Minuten');
});
