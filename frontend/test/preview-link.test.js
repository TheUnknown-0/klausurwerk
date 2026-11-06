import { test } from 'node:test';
import assert from 'node:assert/strict';
import { previewExit, previewTarget, previewUrl, previewWindowName } from '../src/lib/preview-link.js';

test('previewUrl hängt Vorschau und Aufgabe an die Klausuradresse', () => {
    assert.equal(previewUrl('/klausuren/7', 42), '/klausuren/7/vorschau?aufgabe=42');
    assert.equal(previewUrl('/kw/klausuren/7/', 42), '/kw/klausuren/7/vorschau?aufgabe=42');
});

test('previewTarget liest nur gültige Kennungen', () => {
    assert.equal(previewTarget('?aufgabe=42'), 42);
    assert.equal(previewTarget('?x=1&aufgabe=5'), 5);
    assert.equal(previewTarget(''), null);
    assert.equal(previewTarget('?aufgabe='), null);
    assert.equal(previewTarget('?aufgabe=0'), null);
    assert.equal(previewTarget('?aufgabe=-3'), null);
    assert.equal(previewTarget('?aufgabe=4a'), null);
    assert.equal(previewTarget('?aufgabe=12345678901'), null);
});

test('previewTarget liest, was previewUrl schreibt', () => {
    const url = previewUrl('/klausuren/3', 99);
    assert.equal(previewTarget(url.slice(url.indexOf('?'))), 99);
});

test('previewWindowName je Klausur', () => {
    assert.equal(previewWindowName(12), 'kw-vorschau-12');
    assert.equal(previewWindowName('1x2'), 'kw-vorschau-12');
});

test('previewExit schließt den Tab aus dem Editor, sonst zurück', () => {
    const urls = { examUrl: '/klausuren/3', editorUrl: '/klausuren/3/aufgaben' };
    assert.deepEqual(previewExit({ ...urls, windowName: 'kw-vorschau-3', hasOpener: true, search: '?aufgabe=9' }), { close: true, url: '/klausuren/3/aufgaben' });
    // Tab ohne Opener (etwa neu geladen aus dem Verlauf): zurück in den Editor
    assert.deepEqual(previewExit({ ...urls, windowName: 'kw-vorschau-3', hasOpener: false, search: '?aufgabe=9' }), { close: false, url: '/klausuren/3/aufgaben' });
    // Von der Klausurseite im selben Tab geöffnet
    assert.deepEqual(previewExit({ ...urls, windowName: '', hasOpener: false, search: '' }), { close: false, url: '/klausuren/3' });
    // Fremdes Fenster mit Opener wird nicht geschlossen
    assert.deepEqual(previewExit({ ...urls, windowName: 'andere', hasOpener: true, search: '' }), { close: false, url: '/klausuren/3' });
});
