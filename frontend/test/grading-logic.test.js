import test from 'node:test';
import assert from 'node:assert/strict';
import {
    anchorLabel, cellStatusFor, finalEntry, formatPoints, lineCommentMap, linesOfRange,
    neighbour, nextOpen, parsePoints, roundHalf, stepAxis, stepOutcome, textSegments, traversal,
} from '../src/grading/logic.js';

test('Punkte werden deutsch formatiert', () => {
    assert.equal(formatPoints(2.5), '2,5');
    assert.equal(formatPoints(3), '3');
    assert.equal(formatPoints(0), '0');
    assert.equal(formatPoints(null), '');
    assert.equal(formatPoints('1.25'), '1,25');
});

test('Rundung auf halbe Punkte', () => {
    assert.equal(roundHalf(2.4), 2.5);
    assert.equal(roundHalf(2.2), 2);
    assert.equal(roundHalf(2.75), 3);
});

test('Punkteingabe wird geprüft', () => {
    assert.deepEqual(parsePoints('', 4), { ok: true, value: null });
    assert.deepEqual(parsePoints(' 2,5 ', 4), { ok: true, value: 2.5 });
    assert.deepEqual(parsePoints('4', 4), { ok: true, value: 4 });
    assert.equal(parsePoints('4,5', 4).ok, false);
    assert.equal(parsePoints('1,3', 4).ok, false);
    assert.equal(parsePoints('-1', 4).ok, false);
    assert.equal(parsePoints('abc', 4).ok, false);
    assert.equal(parsePoints('1,', 4).ok, false);
});

test('Endwert: Hauptlehrkraft entscheidet, einziger Eintrag zählt, sonst der jüngste', () => {
    const owner = finalEntry([
        { grader_id: 2, points: 3, updated_at: '2026-01-02' },
        { grader_id: 1, points: 2.5, updated_at: '2026-01-01' },
    ], 1);
    assert.equal(owner.points, 2.5);
    assert.equal(owner.source, 'owner');
    assert.equal(owner.differs, true);

    const single = finalEntry([{ grader_id: 7, points: 3 }], 1);
    assert.equal(single.points, 3);
    assert.equal(single.source, 'single');
    assert.equal(single.differs, false);

    const latest = finalEntry([
        { grader_id: 2, points: 1, updated_at: '2026-01-01 10:00' },
        { grader_id: 3, points: 4, updated_at: '2026-01-01 11:00' },
    ], 1);
    assert.equal(latest.points, 4);
    assert.equal(latest.source, 'latest');

    assert.equal(finalEntry([{ grader_id: 1, points: null }], 1).points, null);
    assert.equal(finalEntry([], 1).count, 0);
    assert.equal(finalEntry([{ grader_id: 1, points: 2 }, { grader_id: 2, points: 2 }], 1).differs, false);
});

test('Durchblättern: Person für Person und eine Aufgabe für alle', () => {
    const people = [1, 2];
    const tasks = [10, 20, 30];
    const byPerson = traversal(people, tasks, 'person');
    assert.deepEqual(byPerson.slice(0, 4), [
        { pid: 1, tid: 10 }, { pid: 1, tid: 20 }, { pid: 1, tid: 30 }, { pid: 2, tid: 10 },
    ]);
    const byTask = traversal(people, tasks, 'task');
    assert.deepEqual(byTask.slice(0, 3), [{ pid: 1, tid: 10 }, { pid: 2, tid: 10 }, { pid: 1, tid: 20 }]);
    assert.equal(byTask.length, 6);

    assert.deepEqual(neighbour(byTask, { pid: 2, tid: 10 }, 1), { pid: 1, tid: 20 });
    assert.deepEqual(neighbour(byTask, { pid: 1, tid: 10 }, -1), null);
    assert.equal(neighbour(byTask, { pid: 9, tid: 9 }, 1), null);
    assert.equal(neighbour(byTask, { pid: 2, tid: 30 }, 1), null);
});

test('Nächste offene Zelle überspringt bewertete', () => {
    const cells = traversal([1, 2], [10, 20], 'person');
    const status = (c) => (c.pid === 1 ? 'graded' : c.tid === 10 ? 'diff' : 'suggested');
    assert.deepEqual(nextOpen(cells, { pid: 1, tid: 10 }, status), { pid: 2, tid: 20 });
    assert.equal(nextOpen(cells, { pid: 2, tid: 20 }, status), null);
    assert.deepEqual(nextOpen(cells, { pid: 2, tid: 20 }, status, -1), null);
    assert.deepEqual(nextOpen(cells, { pid: 2, tid: 10 }, () => 'open', -1), { pid: 1, tid: 20 });
});

test('Achsenschritt wechselt Person oder Aufgabe', () => {
    const cur = { pid: 2, tid: 20 };
    assert.deepEqual(stepAxis([1, 2, 3], [10, 20], cur, 'person', 1), { pid: 3, tid: 20 });
    assert.deepEqual(stepAxis([1, 2, 3], [10, 20], cur, 'task', -1), { pid: 2, tid: 10 });
    assert.equal(stepAxis([1, 2, 3], [10, 20], cur, 'task', 1), null);
});

test('Anker werden lesbar beschrieben', () => {
    assert.equal(anchorLabel({ kind: 'code', file: 'Main.java', from_line: 3, to_line: 3 }), 'Zeile 3 (Main.java)');
    assert.equal(anchorLabel({ kind: 'code', file: 'a.py', from_line: 3, to_line: 5 }), 'Zeilen 3 bis 5 (a.py)');
    assert.equal(anchorLabel({ kind: 'text', quote: 'Hallo Welt' }), '„Hallo Welt“');
    assert.equal(anchorLabel({ kind: 'task' }), 'Ganze Aufgabe');
    assert.ok(anchorLabel({ kind: 'text', quote: 'x'.repeat(100) }).endsWith('…“'));
});

test('Zeilen mit Kommentar je Datei', () => {
    const comments = [
        { id: 1, anchor: { kind: 'code', file: 'A.java', from_line: 2, to_line: 3 } },
        { id: 2, anchor: { kind: 'code', file: 'A.java', from_line: 3, to_line: 3 } },
        { id: 3, anchor: { kind: 'code', file: 'B.java', from_line: 1, to_line: 1 } },
        { id: 4, anchor: { kind: 'task' } },
    ];
    const map = lineCommentMap(comments, 'A.java');
    assert.deepEqual([...map.keys()], [2, 3]);
    assert.deepEqual(map.get(3), [1, 2]);
    assert.equal(lineCommentMap(comments, 'C.java').size, 0);
});

test('Text wird in kommentierte Abschnitte zerlegt', () => {
    const text = 'Eine Liste besteht aus Knoten.';
    assert.deepEqual(textSegments(text, []), [{ text, ids: [] }]);
    assert.deepEqual(textSegments('', []), []);

    const segs = textSegments(text, [
        { id: 1, anchor: { kind: 'text', from: 5, to: 10 } },
        { id: 2, anchor: { kind: 'text', from: 8, to: 14 } },
        { id: 3, anchor: { kind: 'task' } },
    ]);
    assert.equal(segs.map((s) => s.text).join(''), text);
    assert.deepEqual(segs.map((s) => s.ids), [[], [1], [1, 2], [2], []]);
    assert.deepEqual(segs.map((s) => s.text), ['Eine ', 'Lis', 'te', ' bes', 'teht aus Knoten.']);

    // Ränder außerhalb des Textes werden begrenzt
    const clipped = textSegments('abc', [{ id: 9, anchor: { kind: 'text', from: 1, to: 99 } }]);
    assert.deepEqual(clipped, [{ text: 'a', ids: [] }, { text: 'bc', ids: [9] }]);
});

test('Zeilen einer Textauswahl', () => {
    const text = 'eins\nzwei\ndrei';
    assert.deepEqual(linesOfRange(text, 0, 4), { from: 1, to: 1 });
    assert.deepEqual(linesOfRange(text, 5, 13), { from: 2, to: 3 });
    // Auswahl endet direkt hinter dem Zeilenumbruch: Folgezeile gehört nicht dazu
    assert.deepEqual(linesOfRange(text, 0, 5), { from: 1, to: 1 });
});

test('Zellstatus aus Bewertung und Vorschlag', () => {
    assert.equal(cellStatusFor(null, 3, null, false), 'graded');
    assert.equal(cellStatusFor(2, null, null, false), 'graded');
    assert.equal(cellStatusFor(null, null, 1.5, false), 'suggested');
    assert.equal(cellStatusFor(null, null, null, true), 'error');
    assert.equal(cellStatusFor(null, null, null, false), 'open');
});

test('stepOutcome meldet den Rand, damit dort trotzdem gespeichert wird', () => {
    const cells = [{ pid: 1, tid: 10 }, { pid: 1, tid: 20 }];
    assert.deepEqual(stepOutcome(cells, { pid: 1, tid: 10 }, 1), { next: { pid: 1, tid: 20 }, edge: null });
    assert.deepEqual(stepOutcome(cells, { pid: 1, tid: 20 }, 1), { next: null, edge: 'last' });
    assert.deepEqual(stepOutcome(cells, { pid: 1, tid: 10 }, -1), { next: null, edge: 'first' });
});
