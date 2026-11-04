import test from 'node:test';
import assert from 'node:assert/strict';
import { Outbox, startSeq, backoffMs, mergeAnswers, MAX_BATCH, SNAPSHOT_EVERY } from '../src/exam/autosave-core.js';
import { Autosave } from '../src/exam/autosave.js';
import { MemoryStore } from '../src/exam/store.js';
import { EventQueue } from '../src/exam/events.js';
import { clockView, formatClock, saveStatusText } from '../src/exam/clock.js';
import { isAnswered, summarize, taskStatus, flattenTasks } from '../src/exam/answers.js';
import { mergeSettings } from '../src/exam/settings.js';

test('seq startet bei max(Server, lokal) + 1', () => {
    assert.equal(startSeq(340, 12), 341);
    assert.equal(startSeq(5, 90), 91);
    assert.equal(startSeq(undefined, undefined), 1);
});

test('Outbox vergibt lückenlos aufsteigende seq und überlebt Serialisierung', () => {
    const o = new Outbox({ seq: 340 });
    const a = o.record(102, { text: 'a' }, undefined, 1000);
    const b = o.record(103, { selected: ['b'] }, { kind: 'value' }, 1001);
    assert.equal(a[0].seq, 341);
    assert.equal(b[0].seq, 342);
    const copy = new Outbox(JSON.parse(JSON.stringify(o)));
    assert.equal(copy.nextSeq(), 343);
    assert.equal(copy.pending.length, 2);
});

test('value-Items derselben Aufgabe werden zusammengefasst', () => {
    const o = new Outbox();
    o.record(1, { text: 'a' }, undefined, 1);
    o.record(1, { text: 'ab' }, undefined, 2);
    o.record(1, { text: 'abc' }, undefined, 3);
    assert.equal(o.pending.length, 1);
    assert.deepEqual(o.pending[0].payload, { text: 'abc' });
    assert.equal(o.pending[0].seq, 3);
});

test('ops-Items bleiben einzeln, alle 50 Schritte kommt ein Snapshot', () => {
    const o = new Outbox();
    const content = { files: [{ name: 'Main.java', content: 'class Main {}' }] };
    let snapshots = 0;
    for (let i = 0; i < SNAPSHOT_EVERY; i++) {
        const items = o.record(7, content, { kind: 'ops', payload: { i }, file: 'Main.java', isPaste: i === 3 }, i);
        snapshots += items.filter((x) => x.kind === 'snapshot').length;
    }
    assert.equal(snapshots, 1);
    assert.equal(o.pending.filter((x) => x.kind === 'ops').length, SNAPSHOT_EVERY);
    assert.equal(o.pending[0].payload.file, 'Main.java');
    assert.deepEqual(o.pending[0].payload.changes, { i: 0 });
    assert.equal(o.pending[3].is_paste, true);
    const snap = o.pending.at(-1);
    assert.deepEqual(snap.payload, { file: 'Main.java', text: 'class Main {}' });
});

test('Paket: höchstens 200 Items, Inhalte nur der betroffenen Aufgaben, ack entfernt', () => {
    const o = new Outbox();
    for (let i = 0; i < 250; i++) {
        o.record(5, { files: [{ name: 'a', content: String(i) }] }, { kind: 'ops', payload: [i], file: 'a' }, i);
    }
    o.record(6, { text: 'x' }, undefined, 1);
    const batch = o.prepareBatch(MAX_BATCH);
    assert.ok(batch.items.length <= MAX_BATCH);
    assert.deepEqual(Object.keys(batch.contents), ['5']);
    o.ack(batch.maxSeq);
    const rest = o.prepareBatch(MAX_BATCH);
    assert.ok(rest.items.some((i) => i.task_id === 6));
    assert.ok(rest.contents['6']);
    o.ack(rest.maxSeq);
    assert.equal(o.prepareBatch(), null);
});

test('Zusammenführen: lokal unbestätigte Änderungen liegen über dem Serverstand', () => {
    const o = new Outbox({ seq: 10 });
    o.record(1, { text: 'lokal neuer' }, undefined, 1); // seq 11
    o.record(2, { text: 'lokal' }, undefined, 2); // seq 12
    const server = {
        1: { content: { text: 'alt' }, version: 3, flagged: false },
        2: { content: { text: 'server' }, version: 4, flagged: true },
        3: { content: { text: 'nur server' }, version: 1 },
    };
    // Server kennt bis seq 11: Aufgabe 1 ist bestätigt, Aufgabe 2 noch offen
    o.reconcile(11);
    const merged = mergeAnswers(server, o, 11);
    assert.deepEqual(merged[1].content, { text: 'alt' });
    assert.deepEqual(merged[2].content, { text: 'lokal' });
    assert.equal(merged[2].version, 4);
    assert.equal(merged[3].version, 1);
    assert.equal(o.pending.length, 1);
    assert.equal(o.nextSeq(), 13);
});

test('reconcile hebt den Zähler auf den Serverstand', () => {
    const o = new Outbox({ seq: 3 });
    o.reconcile(340);
    assert.equal(o.nextSeq(), 341);
});

test('Backoff wächst und ist gedeckelt', () => {
    const r = () => 0.5;
    assert.equal(backoffMs(0, r), 1000);
    assert.equal(backoffMs(1, r), 2000);
    assert.equal(backoffMs(3, r), 8000);
    assert.equal(backoffMs(20, r), 30000);
});

function fakeAutosave(responses) {
    const calls = [];
    const post = async (path, body) => {
        calls.push({ path, body });
        const r = responses.shift() ?? { ok: true, status: 200, data: { success: true, versions: {} } };
        return r;
    };
    const store = new MemoryStore();
    const a = new Autosave({ apiBase: '/api/klausur/1', storeKey: 'k', store, post });
    return { a, calls, store };
}

test('Autosave sendet, wiederholt nur bei transienten Fehlern und pausiert bei 409', async () => {
    const { a, calls } = fakeAutosave([
        { ok: false, status: 0, transient: true, error: 'Keine Verbindung' },
    ]);
    await a.init(0, {});
    a.change(1, { text: 'x' });
    clearTimeout(a.timer);
    const first = await a.flush();
    assert.equal(first, false);
    assert.equal(a.status.kind, 'offline');
    assert.equal(a.pendingCount, 1);
    clearTimeout(a.retryTimer);
    a.retryTimer = null;
    assert.equal(await a.flush(), true);
    assert.equal(a.pendingCount, 0);
    assert.equal(calls.length, 2);
    assert.equal(calls[0].body.items[0].seq, calls[1].body.items[0].seq);

    const c = fakeAutosave([{ ok: false, status: 409, transient: false, error: 'gesperrt' }]);
    let conflict = 0;
    c.a.onConflict = () => { conflict++; };
    await c.a.init(0, {});
    c.a.change(1, { text: 'y' });
    clearTimeout(c.a.timer);
    assert.equal(await c.a.flush(), false);
    assert.equal(conflict, 1);
    assert.equal(c.a.status.kind, 'blocked');
    assert.equal(await c.a.flush(), false); // bleibt pausiert, kein weiterer Aufruf
    assert.equal(c.calls.length, 1);
    c.a.resume();
    await c.a.sending;
    assert.equal(c.calls.length, 2);
    assert.equal(c.a.pendingCount, 0);
    [a, c.a].forEach((x) => x.destroy());
});

test('Autosave: 422 verwirft das Paket und meldet', async () => {
    const msgs = [];
    const { a, calls } = fakeAutosave([{ ok: false, status: 422, transient: false, error: 'ungültig' }]);
    a.onMessage = (m) => msgs.push(m);
    await a.init(0, {});
    a.change(1, { text: 'x' });
    clearTimeout(a.timer);
    await a.flush();
    assert.equal(a.pendingCount, 0);
    assert.equal(msgs.length, 1);
    assert.equal(calls.length, 1);
    a.destroy();
});

test('Autosave: lokaler Stand wird nach Neustart geladen und nachgesendet', async () => {
    const store = new MemoryStore();
    const post1 = async () => ({ ok: false, status: 0, transient: true, error: 'offline' });
    const a1 = new Autosave({ apiBase: '/x', storeKey: 'k', store, post: post1 });
    await a1.init(0, {});
    a1.change(9, { text: 'ungesendet' });
    await a1.persistNow();
    a1.destroy();

    const sent = [];
    const post2 = async (p, body) => { sent.push(body); return { ok: true, status: 200, data: { success: true } }; };
    const a2 = new Autosave({ apiBase: '/x', storeKey: 'k', store, post: post2 });
    const merged = await a2.init(0, { 9: { content: { text: 'server' }, version: 1 } });
    assert.deepEqual(merged[9].content, { text: 'ungesendet' });
    assert.equal(a2.pendingCount, 1);
    await a2.flush();
    assert.equal(sent.length, 1);
    assert.equal(a2.pendingCount, 0);
    a2.destroy();
});

test('Ereignis-Warteschlange puffert, sendet nach release und verwirft Ablehnungen', async () => {
    const sent = [];
    const q = new EventQueue({
        apiBase: '/x', storeKey: 'e', store: new MemoryStore(),
        post: async (p, body) => { sent.push(body.events.length); return { ok: true, status: 200, data: { state: 'active' } }; },
    });
    await q.init();
    q.push('fullscreen_exit', {});
    q.push('resize', { w: 1, h: 2 });
    await q.flush();
    assert.equal(sent.length, 0); // noch gehalten
    q.release();
    await new Promise((r) => setTimeout(r, 60));
    assert.deepEqual(sent, [2]);
    assert.equal(q.length, 0);
});

test('Uhr: Restzeit aus Serveruhr, Pause, Überziehung, unbegrenzt', () => {
    const clock = { started: true, ends_at_ms: 1_000_000 + 90_000 };
    const v = clockView(clock, 1_000_000 - 5000, 5000);
    assert.equal(v.kind, 'running');
    assert.equal(v.text, '01:30');
    assert.equal(clockView(clock, 1_000_000, 200_000 - 1000 + 1000).kind, 'overtime');
    const over = clockView(clock, 0, 1_090_000 + 75_000);
    assert.equal(over.kind, 'overtime');
    assert.equal(over.text, '+01:15');
    assert.equal(over.label, 'Überziehung');
    const paused = clockView({ started: true, paused: true, remaining_ms: 3_725_000 }, 0, 0);
    assert.equal(paused.kind, 'paused');
    assert.equal(paused.text, '1:02:05');
    assert.equal(paused.label, 'pausiert');
    assert.equal(clockView({ started: true, unlimited: true }, 0, 0).kind, 'unlimited');
    assert.equal(clockView(null, 0, 0).kind, 'none');
    assert.equal(formatClock(59_999), '00:59');
});

test('Speicherstatus-Texte', () => {
    assert.equal(saveStatusText({ kind: 'saved' }, 3400), 'gespeichert vor 3 s');
    assert.equal(saveStatusText({ kind: 'saved' }, 125_000), 'gespeichert vor 2 min');
    assert.equal(saveStatusText({ kind: 'saving' }, 0), 'speichert ...');
    assert.equal(saveStatusText({ kind: 'offline' }, 0), 'offline, wird nachgereicht');
});

test('Antwortstatus und Zusammenfassung', () => {
    assert.equal(isAnswered('text', { text: '  ' }), false);
    assert.equal(isAnswered('text', { text: 'a' }), true);
    assert.equal(isAnswered('choice', { selected: [] }), false);
    assert.equal(isAnswered('matching', { pairs: { l1: '' } }), false);
    assert.equal(isAnswered('gaps', { values: { g1: ' ' } }), false);
    assert.equal(isAnswered('code', { files: [{ name: 'a', content: 'x' }] }), true);
    const tasks = [
        { id: 1, answer_type: null, children: [{ id: 2, answer_type: 'text', children: [] }, { id: 3, answer_type: 'choice', children: [] }] },
        { id: 4, answer_type: 'text', children: [] },
    ];
    assert.equal(flattenTasks(tasks).length, 4);
    const answers = { 2: { content: { text: 'x' } }, 3: { content: { selected: [] }, flagged: true } };
    const s = summarize(tasks, answers);
    assert.equal(s.total, 3);
    assert.equal(s.answered, 1);
    assert.deepEqual(s.unanswered.map((t) => t.id), [3, 4]);
    assert.deepEqual(s.flagged.map((t) => t.id), [3]);
    assert.equal(taskStatus(tasks[1], undefined, true), 'done');
    assert.equal(taskStatus({ answer_type: 'choice' }, answers[3]), 'flagged');
});

test('Einstellungen werden mit Standards aufgefüllt', () => {
    const s = mergeSettings({ editor: { font_size: 18 }, aids: { calculator: true } }, 'exam');
    assert.equal(s.editor.font_size, 18);
    assert.equal(s.editor.close_brackets, true);
    assert.equal(s.aids.calculator, true);
    assert.equal(s.aids.operators, true);
    assert.equal(mergeSettings({}, 'practice').lock_on_violation, false);
});
