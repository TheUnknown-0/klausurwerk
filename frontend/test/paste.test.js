import test from 'node:test';
import assert from 'node:assert/strict';
import { PasteTracker, decidePaste, normalizeText } from '../src/exam/paste.js';
import { blockedShortcut, devtoolsSuspected, BlurGuard } from '../src/exam/monitor-logic.js';

test('Zeilenenden werden vereinheitlicht', () => {
    assert.equal(normalizeText('a\r\nb\rc\nd'), 'a\nb\nc\nd');
});

test('intern kopierter Text darf eingefügt werden, auch mit anderen Zeilenenden', () => {
    const t = new PasteTracker();
    t.remember('int x = 1;\nint y = 2;');
    assert.equal(t.isInternal('int x = 1;\r\nint y = 2;'), true);
    assert.equal(t.isInternal('int x = 1;'), true); // Teilstück
    assert.equal(t.isInternal('System.exit(0);'), false);
});

test('Merkliste ist begrenzt', () => {
    const t = new PasteTracker(2);
    t.remember('eins');
    t.remember('zwei');
    t.remember('drei');
    assert.equal(t.isInternal('eins'), false);
    assert.equal(t.isInternal('drei'), true);
});

test('Richtlinien: internal, block, allow_log', () => {
    const t = new PasteTracker();
    t.remember('abc');
    assert.deepEqual(decidePaste('internal', t, 'abc'), { allow: true, event: null });
    const ext = decidePaste('internal', t, 'fremd');
    assert.equal(ext.allow, false);
    assert.deepEqual(ext.event, { type: 'paste_blocked', details: { length: 5 } });
    assert.equal(decidePaste('block', t, 'abc').allow, false);
    const log = decidePaste('allow_log', t, 'fremd');
    assert.equal(log.allow, true);
    assert.equal(log.event.type, 'paste_external');
    assert.equal(decidePaste('allow_log', t, 'abc').event, null);
});

test('Tastenkürzel', () => {
    assert.equal(blockedShortcut({ key: 'F12' }), 'devtools');
    assert.equal(blockedShortcut({ key: 'I', ctrlKey: true, shiftKey: true }), 'devtools');
    assert.equal(blockedShortcut({ key: 'k', ctrlKey: true, shiftKey: true }), 'devtools');
    assert.equal(blockedShortcut({ key: 'u', ctrlKey: true }), 'viewsource');
    assert.equal(blockedShortcut({ key: 's', ctrlKey: true }), 'save');
    assert.equal(blockedShortcut({ key: 'p', ctrlKey: true }), 'print');
    assert.equal(blockedShortcut({ key: 'F5' }), 'reload');
    assert.equal(blockedShortcut({ key: 'r', ctrlKey: true }), 'reload');
    assert.equal(blockedShortcut({ key: 'c', ctrlKey: true }), null);
    assert.equal(blockedShortcut({ key: 'a' }), null);
});

test('DevTools-Heuristik ignoriert Vollbild und Zoom', () => {
    const base = { outerW: 1920, innerW: 1500, outerH: 1000, innerH: 900, fullscreen: false, dpr: 1, baseDpr: 1 };
    assert.equal(devtoolsSuspected(base), true);
    assert.equal(devtoolsSuspected({ ...base, fullscreen: true }), false);
    assert.equal(devtoolsSuspected({ ...base, dpr: 2 }), false);
    assert.equal(devtoolsSuspected({ ...base, innerW: 1900, innerH: 960 }), false);
});

test('BlurGuard: kurzer Verlust ist Hinweis, langer ein Verstoß', () => {
    let now = 0;
    const timers = [];
    const log = [];
    const g = new BlurGuard({
        graceMs: 300,
        now: () => now,
        setTimer: (fn, ms) => timers.push({ fn, at: now + ms }) - 1,
        clearTimer: (i) => { timers[i] = null; },
        onInfo: (ms) => log.push(['info', ms]),
        onViolation: (ms) => log.push(['violation', ms]),
    });
    g.blur();
    now = 120;
    g.focus();
    assert.deepEqual(log, [['info', 120]]);

    log.length = 0;
    g.blur();
    now = 1000;
    timers.filter(Boolean).at(-1).fn();
    assert.deepEqual(log, [['violation', 880]]);
    g.focus();
    assert.equal(log.length, 1); // keine zusätzliche Info nach einem Verstoß
});

test('BlurGuard mit Schwelle 0 meldet sofort', () => {
    const log = [];
    const g = new BlurGuard({ graceMs: 0, onInfo: () => log.push('info'), onViolation: () => log.push('violation') });
    g.blur();
    assert.deepEqual(log, ['violation']);
});
