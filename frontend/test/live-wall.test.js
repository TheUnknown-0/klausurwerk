import test from 'node:test';
import assert from 'node:assert/strict';

import {
    acceptsLive, captureSpec, dataUrlBytes, denyReason, excerptAround, failDelay, isFocusInterval, judgeSurface,
    liveRequired, sameLive, screenInterval, shareRequired, shouldSendLive, tailLines,
} from '../src/exam/live-logic.js';
import { LiveReporter } from '../src/exam/live.js';
import { PICK_GRACE_MS, ScreenShare } from '../src/exam/screenshare.js';

const numbered = (n) => Array.from({ length: n }, (_, i) => `zeile ${i + 1}`).join('\n');

test('Code-Ausschnitt: kurzer Text bleibt ganz, Start bei Zeile 1', () => {
    const r = excerptAround('a\nb\nc', 2);
    assert.equal(r.excerpt, 'a\nb\nc');
    assert.equal(r.startLine, 1);
});

test('Code-Ausschnitt: höchstens 60 Zeilen um die Cursorzeile', () => {
    const r = excerptAround(numbered(500), 250);
    const lines = r.excerpt.split('\n');
    assert.equal(lines.length, 60);
    assert.ok(r.startLine <= 250 && 250 < r.startLine + 60);
    assert.equal(lines[250 - r.startLine], 'zeile 250');
});

test('Code-Ausschnitt: am Anfang und am Ende bleibt das Fenster voll', () => {
    const a = excerptAround(numbered(200), 1);
    assert.equal(a.startLine, 1);
    assert.equal(a.excerpt.split('\n').length, 60);
    const b = excerptAround(numbered(200), 200);
    assert.equal(b.startLine, 141);
    assert.equal(b.excerpt.split('\n').length, 60);
    // Cursor ausserhalb wird begrenzt
    assert.equal(excerptAround('x\ny', 99).startLine, 1);
});

test('Code-Ausschnitt: höchstens 4000 Zeichen, die Cursorzeile bleibt erhalten', () => {
    const long = Array.from({ length: 100 }, (_, i) => `${i + 1}:${'x'.repeat(95)}`).join('\n');
    const r = excerptAround(long, 50);
    assert.ok(r.excerpt.length <= 4000, `Länge ${r.excerpt.length}`);
    assert.ok(r.excerpt.includes('50:'));
    assert.ok(r.excerpt.split('\n').length <= 60);
    // eine einzelne riesige Zeile wird hart gekürzt
    assert.equal(excerptAround('y'.repeat(9000), 1).excerpt.length, 4000);
});

test('Konsolenende: letzte 20 Zeilen und 2000 Zeichen, leere Schlusszeilen fallen weg', () => {
    assert.equal(tailLines('a\nb\n\n\n'), 'a\nb');
    const t = tailLines(numbered(100));
    assert.equal(t.split('\n').length, 20);
    assert.ok(t.endsWith('zeile 100'));
    const w = tailLines(`${'q'.repeat(5000)}\nende`);
    assert.equal(w.length, 2000);
    assert.ok(w.endsWith('ende'));
    assert.equal(tailLines(''), '');
});

test('Takt des Live-Zustands: Änderung nach 2 s, sonst Lebenszeichen nach 10 s', () => {
    const a = { task_id: 1, view: 'task', file: 'Main.java', cursor_line: 3, code_excerpt: 'x', excerpt_start_line: 1, console_tail: '' };
    const b = { ...a, cursor_line: 4 };
    assert.equal(shouldSendLive({ prev: null, next: a, lastSentAt: null, now: 0 }), true, 'erstes Mal immer');
    assert.equal(shouldSendLive({ prev: a, next: b, lastSentAt: 1000, now: 1500 }), false, 'Änderung, aber zu früh');
    assert.equal(shouldSendLive({ prev: a, next: b, lastSentAt: 1000, now: 3000 }), true, 'Änderung nach 2 s');
    assert.equal(shouldSendLive({ prev: a, next: { ...a }, lastSentAt: 1000, now: 6000 }), false, 'unverändert, noch kein Lebenszeichen');
    assert.equal(shouldSendLive({ prev: a, next: { ...a }, lastSentAt: 1000, now: 11000 }), true, 'Lebenszeichen nach 10 s');
    assert.equal(sameLive(a, { ...a, client_ms: 5 }), true, 'client_ms zählt nicht');
});

test('Wartezeit nach Fehlern wächst und ist auf 30 s begrenzt', () => {
    assert.equal(failDelay(2000, 0), 2000);
    assert.equal(failDelay(2000, 1), 4000);
    assert.equal(failDelay(2000, 3), 16000);
    assert.equal(failDelay(5000, 9), 30000);
});

test('Bildgrössen: Übersicht 480, Fokus 1280, nie vergrössern, Seitenverhältnis bleibt', () => {
    assert.deepEqual(captureSpec(1920, 1080, false), { width: 480, height: 270, quality: 0.6 });
    assert.deepEqual(captureSpec(1920, 1080, true), { width: 1280, height: 720, quality: 0.75 });
    assert.deepEqual(captureSpec(300, 200, true), { width: 300, height: 200, quality: 0.75 });
    assert.equal(captureSpec(0, 0, false).width, 1);
});

test('Takt der Bilder: Standard 5 s, Serverwert begrenzt, Fokus ab schnellem Takt', () => {
    assert.equal(screenInterval(undefined), 5000);
    assert.equal(screenInterval('x'), 5000);
    assert.equal(screenInterval(1000), 1000);
    assert.equal(screenInterval(10), 500);
    assert.equal(screenInterval(999999), 15000);
    assert.equal(isFocusInterval(1000), true);
    assert.equal(isFocusInterval(5000), false);
});

test('Datengrösse einer Data-URL', () => {
    assert.equal(dataUrlBytes('data:image/jpeg;base64,AAAA'), 3);
    assert.equal(dataUrlBytes('data:image/jpeg;base64,AAA='), 2);
    assert.equal(dataUrlBytes('data:image/jpeg;base64,AA=='), 1);
    assert.equal(dataUrlBytes('kaputt'), 0);
});

test('Fläche: nur der ganze Bildschirm zählt; Fenster und Tab sind falsch; unbekannt wird angenommen', () => {
    assert.deepEqual(judgeSurface('monitor'), { ok: true, surface: 'monitor' });
    assert.deepEqual(judgeSurface('window'), { ok: false, surface: 'window' });
    assert.deepEqual(judgeSurface('application'), { ok: false, surface: 'window' });
    assert.deepEqual(judgeSurface('browser'), { ok: false, surface: 'browser' });
    assert.deepEqual(judgeSurface(undefined), { ok: true, surface: 'unknown' });
});

test('Gründe der Ablehnung', () => {
    assert.equal(denyReason({ name: 'NotAllowedError' }), 'not_allowed');
    assert.equal(denyReason({ name: 'NotFoundError' }), 'not_found');
    assert.equal(denyReason({ name: 'NotReadableError' }), 'not_readable');
    assert.equal(denyReason({ name: 'TypeError' }), 'unsupported');
    assert.equal(denyReason(null), 'error');
});

test('Wann gilt was: Modi, Vorschau, Safe Exam Browser, Zustände', () => {
    assert.equal(shareRequired({ screenShare: true, mode: 'exam', preview: false, seb: false }), true);
    assert.equal(shareRequired({ screenShare: true, mode: 'probe', preview: false, seb: false }), true);
    assert.equal(shareRequired({ screenShare: true, mode: 'practice', preview: false, seb: false }), false);
    assert.equal(shareRequired({ screenShare: true, mode: 'exam', preview: true, seb: false }), false);
    assert.equal(shareRequired({ screenShare: true, mode: 'exam', preview: false, seb: true }), false, 'im SEB ohne Alarm übersprungen');
    assert.equal(shareRequired({ screenShare: false, mode: 'exam', preview: false, seb: false }), false);
    assert.equal(liveRequired({ liveView: true, mode: 'exam', preview: false }), true);
    assert.equal(liveRequired({ liveView: true, mode: 'practice', preview: false }), false);
    assert.equal(liveRequired({ liveView: true, mode: 'exam', preview: true }), false);
    assert.equal(liveRequired({ liveView: false, mode: 'exam', preview: false }), false);
    assert.equal(acceptsLive('active', true), true);
    assert.equal(acceptsLive('locked', true), true);
    assert.equal(acceptsLive('paused', true), true);
    assert.equal(acceptsLive('waiting', true), false);
    assert.equal(acceptsLive('submit_requested', true), false);
    assert.equal(acceptsLive('active', false), false);
});

// ---------- LiveReporter ----------

function fakeClock() {
    const c = { t: 0, timers: [] };
    c.now = () => c.t;
    c.setTimer = (fn, ms) => {
        const h = { fn, at: c.t + ms };
        c.timers.push(h);
        return h;
    };
    c.clearTimer = (h) => {
        c.timers = c.timers.filter((x) => x !== h);
    };
    /** Nächsten Timer ausführen. */
    c.fire = async () => {
        c.timers.sort((a, b) => a.at - b.at);
        const next = c.timers.shift();
        if (!next) {
            return false;
        }
        c.t = Math.max(c.t, next.at);
        await next.fn();
        return true;
    };
    return c;
}

const okRes = (data = { focus: false, screen_interval_ms: 5000 }) => ({ ok: true, status: 200, data: { ok: true, data } });

test('LiveReporter sendet sofort, dann nur bei Änderung oder nach 10 s', async () => {
    const clock = fakeClock();
    const calls = [];
    let state = { task_id: 1, view: 'task', file: null, cursor_line: 1, code_excerpt: 'a', excerpt_start_line: 1, console_tail: '' };
    const rep = new LiveReporter({
        api: '/api/klausur/7', collect: () => state, active: () => true,
        post: async (url, body) => { calls.push({ url, body }); return okRes(); },
        now: clock.now, setTimer: clock.setTimer, clearTimer: clock.clearTimer,
    });
    rep.start();
    await clock.fire();
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/api/klausur/7/live');
    assert.equal(calls[0].body.client_ms, 0);
    await clock.fire(); // t=2000, unverändert
    assert.equal(calls.length, 1);
    state = { ...state, cursor_line: 2 };
    await clock.fire(); // t=4000, geändert
    assert.equal(calls.length, 2);
    for (let i = 0; i < 4; i++) {
        await clock.fire(); // t=6000..12000, unverändert
    }
    assert.equal(calls.length, 2);
    await clock.fire(); // t=14000: 10 s seit t=4000
    assert.equal(calls.length, 3);
    rep.stop();
    assert.equal(clock.timers.length, 0);
});

test('LiveReporter sendet nichts, solange der Zustand es nicht erlaubt', async () => {
    const clock = fakeClock();
    let allowed = false;
    let n = 0;
    const rep = new LiveReporter({
        api: '/x', collect: () => ({ task_id: null }), active: () => allowed,
        post: async () => { n += 1; return okRes(); },
        now: clock.now, setTimer: clock.setTimer, clearTimer: clock.clearTimer,
    });
    rep.start();
    await clock.fire();
    await clock.fire();
    assert.equal(n, 0);
    allowed = true;
    await clock.fire();
    assert.equal(n, 1);
});

test('LiveReporter: 409 "nicht eingeschaltet" beendet das Senden, 429 und Netzfehler wiederholen verzögert', async () => {
    const clock = fakeClock();
    let mode = 'off';
    let n = 0;
    const rep = new LiveReporter({
        api: '/x', collect: () => ({ task_id: 1 }), active: () => true,
        post: async () => {
            n += 1;
            if (mode === 'off') {
                return { ok: false, status: 409, error: 'Die Live-Ansicht ist für diese Klausur nicht eingeschaltet.' };
            }
            return { ok: false, status: 429, transient: true, error: 'Zu viele Anfragen.' };
        },
        now: clock.now, setTimer: clock.setTimer, clearTimer: clock.clearTimer,
    });
    rep.start();
    await clock.fire();
    assert.equal(n, 1);
    assert.equal(rep.disabled, true);
    assert.equal(await clock.fire(), false, 'kein weiterer Timer');

    const clock2 = fakeClock();
    mode = 'busy';
    n = 0;
    const rep2 = new LiveReporter({
        api: '/x', collect: () => ({ task_id: 1 }), active: () => true,
        post: async () => { n += 1; return { ok: false, status: 429, transient: true, error: 'Zu viele Anfragen.' }; },
        now: clock2.now, setTimer: clock2.setTimer, clearTimer: clock2.clearTimer,
    });
    rep2.start();
    await clock2.fire();
    const t1 = clock2.t;
    await clock2.fire();
    const t2 = clock2.t;
    await clock2.fire();
    assert.equal(n, 3);
    assert.ok(t2 - t1 >= 4000, 'wird zurückhaltender');
    assert.ok(clock2.t - t2 >= 8000);
});

// ---------- ScreenShare ----------

function fakeTrack(displaySurface) {
    const t = {
        stopped: false, listeners: {},
        getSettings: () => (displaySurface === undefined ? {} : { displaySurface }),
        addEventListener: (type, fn) => { t.listeners[type] = fn; },
        stop: () => { t.stopped = true; },
        end: () => t.listeners.ended?.(),
    };
    return t;
}
const fakeStream = (track) => ({ getVideoTracks: () => [track], getTracks: () => [track] });

function fakeVideo() {
    return { videoWidth: 1920, videoHeight: 1080, readyState: 4, srcObject: null, play: async () => {}, remove() {} };
}

function fakeCanvas(log = []) {
    return {
        width: 0, height: 0,
        getContext: () => ({ drawImage: (...a) => log.push(a) }),
        toDataURL: (type, q) => { log.push(['q', q]); return 'data:image/jpeg;base64,/9j/AAAA'; },
    };
}

function makeShare(opts = {}) {
    const clock = fakeClock();
    const events = [];
    const statuses = [];
    const posts = [];
    const canvasLog = [];
    const share = new ScreenShare({
        api: '/api/klausur/3',
        events: { push: (type, details) => events.push({ type, details }) },
        onChange: (s) => statuses.push(s),
        getDisplayMedia: opts.getDisplayMedia,
        createVideo: fakeVideo,
        createCanvas: () => fakeCanvas(canvasLog),
        post: opts.post ?? (async (url, body) => { posts.push({ url, body }); return okRes(); }),
        now: clock.now, setTimer: clock.setTimer, clearTimer: clock.clearTimer,
    });
    return { share, events, statuses, posts, clock, canvasLog };
}

test('Freigabe des ganzen Bildschirms: started, aktiv, Anfrage mit displaySurface monitor', async () => {
    let asked = null;
    const track = fakeTrack('monitor');
    const { share, events, statuses } = makeShare({ getDisplayMedia: async (c) => { asked = c; return fakeStream(track); } });
    assert.equal(await share.request(), 'active');
    assert.deepEqual(asked, { video: { displaySurface: 'monitor' }, audio: false });
    assert.deepEqual(events, [{ type: 'screen_share_started', details: { surface: 'monitor' } }]);
    assert.deepEqual(statuses, ['requesting', 'active']);
    assert.equal(share.active, true);
});

test('Nur ein Fenster oder Tab: wrong_surface, Tracks gestoppt, nicht aktiv, Wiederholung möglich', async () => {
    for (const surface of ['window', 'browser']) {
        const track = fakeTrack(surface);
        const { share, events } = makeShare({ getDisplayMedia: async () => fakeStream(track) });
        assert.equal(await share.request(), 'wrong');
        assert.equal(track.stopped, true);
        assert.deepEqual(events, [{ type: 'screen_share_wrong_surface', details: { surface } }]);
        assert.equal(share.active, false);
    }
    // zweiter Versuch gelingt
    let n = 0;
    const tracks = [fakeTrack('window'), fakeTrack('monitor')];
    const { share, events } = makeShare({ getDisplayMedia: async () => fakeStream(tracks[n++]) });
    await share.request();
    assert.equal(await share.request(), 'active');
    assert.deepEqual(events.map((e) => e.type), ['screen_share_wrong_surface', 'screen_share_started']);
});

test('Verweigerung: screen_share_denied, dann "ohne Freigabe fortfahren" ohne weiteres Ereignis', async () => {
    const { share, events } = makeShare({ getDisplayMedia: async () => { throw Object.assign(new Error('x'), { name: 'NotAllowedError' }); } });
    assert.equal(await share.request(), 'denied');
    assert.deepEqual(events, [{ type: 'screen_share_denied', details: { reason: 'not_allowed' } }]);
    assert.equal(share.isPicking(), true, 'Schonfrist nach dem Dialog');
    share.skip();
    assert.equal(share.status, 'skipped');
    assert.equal(events.length, 1);
});

test('Offener Auswahldialog schont die Überwachung höchstens 30 s', async () => {
    let resolve;
    const track = fakeTrack('monitor');
    const { share, clock } = makeShare({ getDisplayMedia: () => new Promise((r) => { resolve = r; }) });
    const pending = share.request();
    assert.equal(share.isPicking(), true, 'Dialog offen');
    clock.t += PICK_GRACE_MS - 1;
    assert.equal(share.isPicking(), true, 'kurz vor Ablauf');
    clock.t += 2;
    assert.equal(share.isPicking(), false, 'Dialog zu lange offen: Überwachung wieder scharf');
    resolve(fakeStream(track));
    await pending;
    assert.equal(share.isPicking(), true, 'kurze Schonfrist nach dem Schließen');
    clock.t += 1500;
    assert.equal(share.isPicking(), false);
});

test('Ende der Freigabe durch den Browser: screen_share_stopped und Neustart möglich', async () => {
    const t1 = fakeTrack('monitor');
    const t2 = fakeTrack('monitor');
    const tracks = [t1, t2];
    const { share, events } = makeShare({ getDisplayMedia: async () => fakeStream(tracks.shift()) });
    await share.request();
    t1.end();
    assert.equal(share.status, 'stopped');
    assert.deepEqual(events.map((e) => e.type), ['screen_share_started', 'screen_share_stopped']);
    assert.equal(await share.request(), 'active');
    assert.equal(events.at(-1).type, 'screen_share_started');
});

test('Eigenes Beenden (Abgabe, Seitenwechsel) stoppt Tracks ohne Ereignis', async () => {
    const track = fakeTrack('monitor');
    const { share, events } = makeShare({ getDisplayMedia: async () => fakeStream(track) });
    await share.request();
    share.stop();
    assert.equal(track.stopped, true);
    assert.equal(share.status, 'idle');
    track.end(); // spätes ended-Ereignis darf nichts melden
    assert.deepEqual(events.map((e) => e.type), ['screen_share_started']);
});

test('Bilder: erst nach Freigabe des Sendens, 480 breit, Takt aus der Antwort, Fokus 1280', async () => {
    const track = fakeTrack('monitor');
    const answers = [okRes({ focus: true, screen_interval_ms: 1000 }), okRes({ focus: false, screen_interval_ms: 5000 })];
    const { share, posts, clock, canvasLog } = makeShare({
        getDisplayMedia: async () => fakeStream(track),
        post: async (url, body) => { posts.push({ url, body }); return answers.shift() ?? okRes(); },
    });
    await share.request();
    await clock.fire();
    assert.equal(posts.length, 0, 'noch nicht erlaubt');
    share.setSendingAllowed(true);
    await clock.fire();
    assert.equal(posts.length, 1);
    assert.equal(posts[0].url, '/api/klausur/3/bildschirm');
    assert.equal(posts[0].body.width, 480);
    assert.equal(posts[0].body.height, 270);
    assert.equal(posts[0].body.surface, 'monitor');
    assert.ok(posts[0].body.jpeg.startsWith('data:image/jpeg;base64,/9j/'));
    assert.deepEqual(canvasLog.find((x) => x[0] === 'q'), ['q', 0.6]);
    assert.equal(share.intervalMs, 1000);
    const before = clock.t;
    await clock.fire();
    assert.equal(clock.t - before >= 1000 || posts.length === 2, true);
    assert.equal(posts[1].body.width, 1280, 'Fokus: gross');
    assert.equal(share.intervalMs, 5000);
});

test('Bilder werden nicht überlappend gesendet', async () => {
    const track = fakeTrack('monitor');
    let release;
    let n = 0;
    const { share } = makeShare({
        getDisplayMedia: async () => fakeStream(track),
        post: () => { n += 1; return new Promise((r) => { release = () => r(okRes()); }); },
    });
    await share.request();
    share.setSendingAllowed(true);
    const first = share.tick();
    assert.equal(await share.tick(), false, 'zweiter Versuch während des ersten');
    release();
    assert.equal(await first, true);
    assert.equal(n, 1);
});

test('Bilder: 409 "nicht eingeschaltet" beendet das Senden, Fehler verzögern und verringern die Qualität', async () => {
    const track = fakeTrack('monitor');
    let res = { ok: false, status: 409, error: 'Die Bildschirmfreigabe ist für diese Klausur nicht eingeschaltet.' };
    const { share, clock } = makeShare({
        getDisplayMedia: async () => fakeStream(track),
        post: async () => res,
    });
    await share.request();
    share.setSendingAllowed(true);
    await share.tick();
    assert.equal(share.disabled, true);
    clock.timers = [];
    assert.equal(await share.tick(), false);

    res = { ok: false, status: 413, error: 'zu gross' };
    share.disabled = false;
    await share.tick();
    assert.ok(share.quality !== null && share.quality < 0.6, 'Qualität sinkt nach 413');
    assert.equal(share.fails, 1);
    res = { ok: false, status: 0, transient: true, error: 'Keine Verbindung zum Server.' };
    await share.tick();
    assert.equal(share.fails, 2);
});

test('LiveReporter: nach stop() sendet start() wieder (Abgabe wieder geöffnet)', async () => {
    const clock = fakeClock();
    let n = 0;
    const rep = new LiveReporter({
        api: '/x', collect: () => ({ task_id: 1 }), active: () => true,
        post: async () => { n += 1; return okRes(); },
        now: clock.now, setTimer: clock.setTimer, clearTimer: clock.clearTimer,
    });
    rep.start();
    await clock.fire();
    assert.equal(n, 1);
    rep.stop();
    assert.equal(await clock.fire(), false);
    rep.start();
    for (let i = 0; i < 8 && n < 2; i++) {
        await clock.fire(); // Wiederholung nach dem Mindestabstand
    }
    assert.equal(n, 2);
});
