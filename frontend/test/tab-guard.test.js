import test from 'node:test';
import assert from 'node:assert/strict';

// Kleiner Speicher-Ersatz; sessionStorage wird je "Tab" geleert, localStorage ist geteilt.
class MemoryStorage {
    constructor() { this.map = new Map(); }
    getItem(k) { return this.map.has(k) ? this.map.get(k) : null; }
    setItem(k, v) { this.map.set(k, String(v)); }
    removeItem(k) { this.map.delete(k); }
    clear() { this.map.clear(); }
}

globalThis.window = globalThis.window ?? {};
window.localStorage = new MemoryStorage();
window.sessionStorage = new MemoryStorage();
window.addEventListener = window.addEventListener ?? (() => {});
window.removeEventListener = window.removeEventListener ?? (() => {});

const { TabGuard } = await import('../src/exam/monitor.js');
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

function newTab(examId, onSecond) {
    window.sessionStorage.clear();
    return new TabGuard(examId, onSecond);
}

test('alter Herzschlag ohne lebenden Tab (Browser-Neustart) löst keinen Alarm aus', async () => {
    window.localStorage.setItem('kw-exam-71-hb', JSON.stringify({ tab: 'abgestuerzt', ts: Date.now() }));
    let flagged = 0;
    const guard = newTab(71, () => { flagged += 1; });
    guard.start();
    await wait(150);
    guard.stop();
    assert.equal(flagged, 0);
});

test('ein zweiter lebender Tab wird erkannt, der erste bleibt unbehelligt', async () => {
    let first = 0;
    let second = 0;
    const a = newTab(72, () => { first += 1; });
    a.start();
    await wait(20);
    const b = newTab(72, () => { second += 1; });
    b.start();
    await wait(150);
    a.stop();
    b.stop();
    assert.equal(first, 0);
    assert.equal(second, 1);
});

test('gleicher Startzeitpunkt (gleiche Millisekunde) verdeckt den zweiten Tab nicht', async () => {
    const realNow = Date.now;
    Date.now = () => 1_700_000_000_000;
    let first = 0;
    let second = 0;
    try {
        const a = newTab(75, () => { first += 1; });
        a.start();
        await wait(20);
        const b = newTab(75, () => { second += 1; });
        b.start();
        await wait(150);
        a.stop();
        b.stop();
    } finally {
        Date.now = realNow;
    }
    assert.equal(first, 0);
    assert.equal(second, 1);
});

test('ohne BroadcastChannel greift der frische Herzschlag', async () => {
    const saved = globalThis.BroadcastChannel;
    globalThis.BroadcastChannel = undefined;
    try {
        window.localStorage.setItem('kw-exam-73-hb', JSON.stringify({ tab: 'anderer', ts: Date.now() }));
        let flagged = 0;
        const guard = newTab(73, () => { flagged += 1; });
        guard.start();
        guard.stop();
        assert.equal(flagged, 1);
    } finally {
        globalThis.BroadcastChannel = saved;
    }
});

test('beim Schließen (pagehide) verschwindet der Herzschlag', () => {
    let handler = null;
    const addSaved = window.addEventListener;
    window.addEventListener = (type, fn) => { if (type === 'pagehide') handler = fn; };
    try {
        const guard = newTab(74, () => {});
        guard.start();
        assert.notEqual(window.localStorage.getItem('kw-exam-74-hb'), null);
        handler();
        assert.equal(window.localStorage.getItem('kw-exam-74-hb'), null);
        guard.stop();
    } finally {
        window.addEventListener = addSaved;
    }
});
