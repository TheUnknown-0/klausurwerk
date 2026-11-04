import test from 'node:test';
import assert from 'node:assert/strict';
import { LineBuffer } from '../src/runtime/console.js';

test('Text wird in Zeilen zerlegt, die letzte Zeile bleibt offen', () => {
    const b = new LineBuffer();
    b.append('Name? ');
    assert.equal(b.currentLineText(), 'Name? ');
    b.append('Mia\nHallo Mia\n', 'stdin');
    assert.equal(b.toText(), 'Name? Mia\nHallo Mia\n');
    assert.equal(b.lines.length, 3);
    assert.equal(b.currentLineText(), '');
});

test('Abschnitte nach Herkunft, gleiche Herkunft wird zusammengefasst', () => {
    const b = new LineBuffer();
    b.append('a', 'stdout');
    b.append('b', 'stdout');
    b.append('c', 'stderr');
    assert.deepEqual(b.lines[0].segments, [{ text: 'ab', stream: 'stdout' }, { text: 'c', stream: 'stderr' }]);
});

test('CRLF zählt als ein Zeilenumbruch, einzelnes CR überschreibt die Zeile', () => {
    const b = new LineBuffer();
    b.append('eins\r\nzwei');
    assert.equal(b.toText(), 'eins\nzwei');
    b.append('\rdrei');
    assert.equal(b.toText(), 'eins\ndrei');
});

test('Begrenzung auf maxLines: älteste Zeilen fallen weg und werden gezählt', () => {
    const b = new LineBuffer({ maxLines: 5 });
    let removed = 0;
    for (let i = 1; i <= 20; i++) {
        removed += b.append(`z${i}\n`);
    }
    assert.equal(b.lines.length, 5);
    assert.equal(b.dropped, 16);
    assert.equal(removed, 16);
    assert.equal(b.lines[0].segments[0].text, 'z17');
});

test('Standardgrenze sind 2000 Zeilen', () => {
    const b = new LineBuffer();
    b.append('x\n'.repeat(5000));
    assert.equal(b.lines.length, 2000);
    assert.equal(b.dropped, 3001);
});

test('sehr lange Zeilen werden gekürzt', () => {
    const b = new LineBuffer({ maxLineLength: 100 });
    b.append('a'.repeat(250));
    assert.equal(b.lines[0].length, 100);
    assert.ok(b.lines[0].clipped);
    b.append('\nweiter');
    assert.equal(b.lines[1].segments[0].text, 'weiter');
    assert.ok(!b.lines[1].clipped);
});

test('clear setzt alles zurück', () => {
    const b = new LineBuffer({ maxLines: 2 });
    b.append('1\n2\n3\n');
    b.clear();
    assert.equal(b.toText(), '');
    assert.equal(b.dropped, 0);
    assert.equal(b.lines.length, 1);
});
