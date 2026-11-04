/**
 * Zustand des Pults und Polling gegen /api/pult/{id}/stand.
 *
 * Der Server liefert bei jedem Abruf den kompletten Stand plus neue Ereignisse seit dem Cursor.
 * Aus server_time_ms wird der Versatz zur Clientuhr berechnet, alle Anzeigen rechnen damit.
 */
import { get, post } from '../lib/api.js';

const MAX_EVENTS = 800;
/** So lange pulsiert eine Kachel nach einem Alarm rot. */
export const ALARM_PULSE_MS = 10000;

export function createStore(boot) {
    const state = {
        boot,
        exam: null,
        participants: [],
        notJoined: [],
        events: [],
        cursor: 0,
        offset: 0,
        selection: new Set(),
        alarmUntil: new Map(),
        detailId: null,
        loaded: false,
        connection: { ok: true, failures: 0, fatal: null, lastOkAt: 0 },
    };
    const listeners = new Set();
    const emit = (what = 'stand') => listeners.forEach((fn) => fn(what));

    const api = {
        state,
        subscribe(fn) {
            listeners.add(fn);
            return () => listeners.delete(fn);
        },
        emit,
        /** Serverzeit in ms. */
        now: () => Date.now() + state.offset,
        participant: (id) => state.participants.find((p) => p.id === id) ?? null,
        isSelected: (id) => state.selection.has(id),
        select(ids, mode = 'replace') {
            if (mode === 'replace') {
                state.selection = new Set(ids);
            } else if (mode === 'toggle') {
                ids.forEach((id) => (state.selection.has(id) ? state.selection.delete(id) : state.selection.add(id)));
            } else if (mode === 'add') {
                ids.forEach((id) => state.selection.add(id));
            }
            emit('selection');
        },
        clearSelection() {
            if (state.selection.size > 0) {
                state.selection = new Set();
                emit('selection');
            }
        },
        openDetail(id) {
            state.detailId = id;
            emit('detail');
        },
        closeDetail() {
            state.detailId = null;
            emit('detail');
        },
    };
    return api;
}

/**
 * Startet das Polling. Rückgabe: { refresh(), stop() }.
 *
 * @param {ReturnType<typeof createStore>} store
 * @param {{onAlarms: (events: any[]) => void}} hooks
 */
export function startPoller(store, hooks) {
    const { state } = store;
    let timer = null;
    let stopped = false;
    let running = false;
    let again = false;

    async function poll() {
        if (running) {
            again = true;
            return;
        }
        running = true;
        try {
            const res = await get(`${state.boot.api_base}/stand?cursor=${state.cursor}`, { timeoutMs: 8000 });
            if (res.ok) {
                apply(res.data);
            } else {
                fail(res);
            }
        } finally {
            running = false;
            if (!stopped) {
                const delay = again ? 0 : interval();
                again = false;
                timer = setTimeout(poll, delay);
            }
        }
    }

    function interval() {
        const base = Math.max(500, state.boot.poll_ms || 1500);
        // Bei Störung seltener fragen, aber nie länger als 5 s warten.
        return state.connection.ok ? base : Math.min(5000, base * (1 + state.connection.failures));
    }

    function apply(data) {
        const previousCursor = state.cursor;
        state.offset = data.server_time_ms - Date.now();
        state.exam = data.exam;
        state.participants = data.participants;
        state.notJoined = data.not_joined;
        state.cursor = Math.max(state.cursor, data.cursor ?? 0);
        state.loaded = true;
        state.connection = { ok: true, failures: 0, fatal: null, lastOkAt: Date.now() };

        const known = new Set(state.events.map((e) => e.id));
        const fresh = data.events.filter((e) => !known.has(e.id));
        if (fresh.length > 0) {
            state.events = [...state.events, ...fresh].slice(-MAX_EVENTS);
        }

        // Alarme gibt es erst ab dem zweiten Abruf; beim Öffnen des Pults klingeln alte Ereignisse nicht.
        if (previousCursor > 0) {
            const alarms = fresh.filter((e) => e.severity === 'alarm' && e.participation_id !== null && e.id > previousCursor);
            const until = Date.now() + ALARM_PULSE_MS;
            alarms.forEach((e) => state.alarmUntil.set(e.participation_id, until));
            if (alarms.length > 0) {
                hooks.onAlarms(alarms);
            }
        }
        for (const [id, until] of state.alarmUntil) {
            if (until < Date.now()) {
                state.alarmUntil.delete(id);
            }
        }
        // Ausgewählte Personen, die es nicht mehr gibt, aus der Auswahl nehmen.
        const ids = new Set([...state.participants.map((p) => p.id), ...state.notJoined.map((n) => `u${n.user_id}`)]);
        state.selection = new Set([...state.selection].filter((id) => ids.has(id)));

        store.emit('stand');
    }

    function fail(res) {
        const failures = state.connection.failures + 1;
        let fatal = null;
        if (res.status === 401) {
            fatal = 'Deine Anmeldung ist abgelaufen. Bitte lade die Seite neu und melde dich an.';
        } else if (res.status === 403 || res.status === 404) {
            fatal = res.error;
        }
        state.connection = { ...state.connection, ok: failures < 2 && fatal === null, failures, fatal };
        store.emit('connection');
    }

    poll();

    return {
        refresh: () => {
            clearTimeout(timer);
            poll();
        },
        stop: () => {
            stopped = true;
            clearTimeout(timer);
        },
    };
}

/**
 * Führt eine Pult-Aktion aus.
 *
 * @returns {Promise<{ok: boolean, affected: number, errors: any[], error?: string, data: any}>}
 */
export async function runAction(store, action, participationIds, extra = {}) {
    const body = { action, ...extra };
    if (participationIds !== undefined) {
        body.participation_ids = participationIds;
    }
    const res = await post(`${store.state.boot.api_base}/aktion`, body, { timeoutMs: 15000 });
    const data = res.data ?? {};

    return {
        ok: res.ok,
        affected: data.affected ?? 0,
        errors: data.errors ?? [],
        error: res.ok ? undefined : res.error,
        data,
    };
}
