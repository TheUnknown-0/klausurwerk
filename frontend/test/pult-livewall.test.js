import test from 'node:test';
import assert from 'node:assert/strict';
import {
    ageLevel, ageNow, ageText, chooseView, consoleRows, excerptRows, screenChanged, screenUrl,
    STALE_MS, surfaceWarning, viewTitle,
} from '../src/pult/livewall-logic.js';

test('Altersanzeige in Sekunden, Minuten und Stunden', () => {
    assert.equal(ageText(null), 'noch nichts');
    assert.equal(ageText(0), 'vor 0 s');
    assert.equal(ageText(3400), 'vor 3 s');
    assert.equal(ageText(59000), 'vor 59 s');
    assert.equal(ageText(125000), 'vor 2 min');
    assert.equal(ageText(7300000), 'vor 2 h');
    assert.equal(ageText(-5), 'vor 0 s');
});

test('Veraltet ab 20 s, fehlende Daten sind "none"', () => {
    assert.equal(ageLevel(null), 'none');
    assert.equal(ageLevel(undefined), 'none');
    assert.equal(ageLevel(STALE_MS), 'fresh');
    assert.equal(ageLevel(STALE_MS + 1), 'stale');
});

test('Alter wird seit dem Abruf fortgeschrieben', () => {
    assert.equal(ageNow(null, 0, 5000), null);
    assert.equal(ageNow(2000, 1000, 4000), 5000);
    assert.equal(ageNow(2000, 4000, 1000), 2000);
});

test('Auswahl der Ansicht je Schalter und Datenlage', () => {
    const live = { received_ms: 1 };
    const screen = { ts_ms: 1 };
    assert.equal(chooseView({ live, screen }, { live_view: false, screen_share: false }), 'disabled');
    assert.equal(chooseView({ live, screen }, { live_view: true, screen_share: true }), 'both');
    assert.equal(chooseView({ live: null, screen }, { live_view: true, screen_share: true }), 'screen');
    assert.equal(chooseView({ live, screen: null }, { live_view: true, screen_share: true }), 'code');
    assert.equal(chooseView({ live, screen: null }, { live_view: true, screen_share: false }), 'code');
    assert.equal(chooseView({ live: null, screen: null }, { live_view: true, screen_share: false }), 'empty');
    assert.equal(chooseView({ live, screen }, { live_view: false, screen_share: true }), 'screen');
    assert.equal(chooseView({ live, screen: null }, { live_view: false, screen_share: true }), 'empty');
});

test('Bild wird nur bei neuem ts_ms neu geladen', () => {
    assert.equal(screenChanged(null, { ts_ms: 5 }), true);
    assert.equal(screenChanged(5, { ts_ms: 5 }), false);
    assert.equal(screenChanged(5, { ts_ms: 6 }), true);
    assert.equal(screenChanged(5, null), true);
    assert.equal(screenChanged(null, null), false);
    assert.equal(screenUrl('/api/pult/2', 7, 99), '/api/pult/2/bildschirm/7?t=99');
});

test('Code-Ausschnitt mit Nummern und Cursorzeile', () => {
    const live = { code_excerpt: 'a\nb\nc\n', excerpt_start_line: 10, cursor_line: 11 };
    assert.deepEqual(excerptRows(live), [
        { no: 10, text: 'a', cursor: false },
        { no: 11, text: 'b', cursor: true },
        { no: 12, text: 'c', cursor: false },
    ]);
    assert.deepEqual(excerptRows({ code_excerpt: '' }), []);
    assert.deepEqual(excerptRows(null), []);
});

test('Langer Ausschnitt wird um die Cursorzeile begrenzt', () => {
    const code = Array.from({ length: 30 }, (_, i) => `z${i + 1}`).join('\n');
    const rows = excerptRows({ code_excerpt: code, excerpt_start_line: 1, cursor_line: 15 }, 7);
    assert.equal(rows.length, 7);
    assert.equal(rows.find((r) => r.cursor).no, 15);
    assert.equal(rows[0].no, 12);
    const top = excerptRows({ code_excerpt: code, excerpt_start_line: 1, cursor_line: 1 }, 7);
    assert.equal(top[0].no, 1);
    const bottom = excerptRows({ code_excerpt: code, excerpt_start_line: 1, cursor_line: 30 }, 7);
    assert.equal(bottom.at(-1).no, 30);
    const none = excerptRows({ code_excerpt: code, excerpt_start_line: 1, cursor_line: null }, 5);
    assert.equal(none.length, 5);
    assert.ok(none.every((r) => !r.cursor));
});

test('Cursorzeile außerhalb des Ausschnitts markiert nichts', () => {
    const rows = excerptRows({ code_excerpt: 'x\ny', excerpt_start_line: 5, cursor_line: 99 });
    assert.ok(rows.every((r) => !r.cursor));
});

test('Konsole zeigt die letzten Zeilen', () => {
    assert.deepEqual(consoleRows({ console_tail: '1\n2\n3\n4\n' }, 2), ['3', '4']);
    assert.deepEqual(consoleRows({ console_tail: '  \n' }), []);
    assert.deepEqual(consoleRows(null), []);
});

test('Titel der Ansicht und Hinweis zur Fläche', () => {
    const names = new Map([[4, '2a Sortieren']]);
    assert.equal(viewTitle({ view: 'task', task_id: 4 }, names), '2a Sortieren');
    assert.equal(viewTitle({ view: 'task', task_id: 9 }, names), 'Aufgabe 9');
    assert.equal(viewTitle({ view: 'overview', task_id: null }, names), 'Übersicht');
    assert.equal(viewTitle(null, names), '');
    assert.equal(surfaceWarning({ surface: 'monitor' }), '');
    assert.equal(surfaceWarning({ surface: 'window' }), 'Nur ein Fenster freigegeben');
    assert.equal(surfaceWarning({ surface: 'browser' }), 'Nur ein Tab freigegeben');
    assert.equal(surfaceWarning(null), '');
});
