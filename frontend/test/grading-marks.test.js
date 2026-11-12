import test from 'node:test';
import assert from 'node:assert/strict';
import {
    combineMarks, countWords, errorQuotient, formatCounts, formatQuotient, marginLines, markCodes, summarizeMarks,
} from '../src/grading/logic.js';

const defs = [
    { code: 'R', name: 'Rechtschreibung', counts: true, weight: 1 },
    { code: 'Gr', name: 'Grammatik', counts: true, weight: 1 },
    { code: 'S', name: 'Sinnfehler', counts: true, weight: 2 },
    { code: 'H', name: 'Hinweis', counts: false, weight: 1 },
    { code: 'h', name: 'Halber Fehler', counts: true, weight: 0.5 },
];
const mark = (id, code, from = 0, to = 1) => ({ id, anchor: { kind: 'text', from, to, quote: 'x', mark: code } });

test('Wörter werden gezählt', () => {
    assert.equal(countWords(''), 0);
    assert.equal(countWords('  \n '), 0);
    assert.equal(countWords('Ein Satz\nmit  vier'), 4);
});

test('Fehlerquotient: gewichtete Fehler je 100 Wörter, eine Nachkommastelle', () => {
    assert.equal(errorQuotient(5, 120), 4.2);
    assert.equal(errorQuotient(0, 50), 0);
    assert.equal(errorQuotient(3, 0), null);
    assert.equal(formatQuotient(4.2), '4,2');
    assert.equal(formatQuotient(5), '5,0');
    assert.equal(formatQuotient(null), '');
});

test('Zählung je Kategorie mit Gewicht, ohne Nicht-Fehler und ohne unbekannte Kürzel', () => {
    const comments = [mark(1, 'R'), mark(2, 'R'), mark(3, 'Gr'), mark(4, 'S'), mark(5, 'H'), mark(6, 'h'), mark(7, 'XX'),
        { id: 8, anchor: { kind: 'text', from: 0, to: 1, quote: 'x' } }, { id: 9, anchor: { kind: 'task' } }];
    const codes = markCodes(comments);
    assert.deepEqual(codes, ['R', 'R', 'Gr', 'S', 'H', 'h', 'XX']);
    const s = summarizeMarks(codes, 100, defs);
    assert.deepEqual(s.counts, { R: 2, Gr: 1, S: 1, H: 1, h: 1 });
    assert.equal(s.errors, 5);
    assert.equal(s.weighted, 5.5);
    assert.equal(s.words, 100);
    assert.equal(s.quotient, 5.5);
    assert.equal(formatCounts(s.counts, defs), 'R 2, Gr 1, S 1, H 1, h 1');
});

test('Zusammenfassen mehrerer Aufgaben rechnet aus den Summen', () => {
    const a = summarizeMarks(['R', 'Gr'], 50, defs);
    const b = summarizeMarks(['R'], 150, defs);
    const all = combineMarks([a, b]);
    assert.deepEqual(all.counts, { R: 2, Gr: 1 });
    assert.equal(all.weighted, 3);
    assert.equal(all.words, 200);
    assert.equal(all.quotient, 1.5);
    assert.equal(combineMarks([]).quotient, null);
});

test('Randspalte: Zeichen stehen in der Zeile, in der die Stelle beginnt', () => {
    const text = 'eins\nzwei drei\n\nvier';
    const lines = marginLines(text, [mark(1, 'Gr', 7, 11), mark(2, 'R', 0, 4), mark(3, 'Z', 12, 14), mark(4, 'T', 5, 9), mark(5, 'R', 16, 20)]);
    assert.equal(lines.length, 4);
    assert.deepEqual(lines.map((l) => l.marks.map((m) => m.code)), [['R'], ['T', 'Gr', 'Z'], [], ['R']]);
    assert.deepEqual(lines.map((l) => [l.from, l.to]), [[0, 4], [5, 14], [15, 15], [16, 20]]);
});
