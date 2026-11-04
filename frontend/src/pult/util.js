/**
 * Gemeinsame Konstanten und Formatierer des Pults.
 */
import { formatDuration } from '../lib/dom.js';

export const STATE_LABELS = {
    waiting: 'Im Warteraum',
    active: 'Arbeitet',
    locked: 'Gesperrt',
    absent: 'Abwesend',
    paused: 'Pausiert',
    submit_requested: 'Abgabe angefragt',
    submitted: 'Abgegeben',
    device_pending: 'Gerätewechsel',
};

export const EXAM_STATUS_LABELS = {
    draft: 'Entwurf',
    ready: 'Bereit',
    lobby: 'Warteraum offen',
    running: 'Läuft',
    paused: 'Pausiert',
    finished: 'Beendet',
    grading: 'In Korrektur',
    returned: 'Zurückgegeben',
    archived: 'Archiviert',
};

export const EVENT_LABELS = {
    joined: 'ist beigetreten',
    ready: 'ist bereit',
    fullscreen_exit: 'hat den Vollbildmodus verlassen',
    visibility_hidden: 'hat Fenster oder Tab gewechselt',
    window_blur: 'hat den Fokus verloren',
    paste_blocked: 'Einfügen von außen blockiert',
    paste_external: 'Einfügen von außen',
    drop_blocked: 'Ziehen von außen blockiert',
    second_tab: 'hat die Klausur in einem zweiten Tab geöffnet',
    devtools_suspected: 'Verdacht auf Entwicklerwerkzeuge',
    resize: 'Fenstergröße geändert',
    reload: 'hat die Seite neu geladen',
    connection_lost: 'Verbindung verloren',
    connection_restored: 'Verbindung wieder da',
    seb_missing: 'nicht im Safe Exam Browser',
    time_up: 'Zeit abgelaufen',
    state_changed: 'Zustand geändert',
    announcement: 'Durchsage',
    hand_raised: 'hebt die Hand',
    hand_lowered: 'Hand gesenkt',
    device_change_requested: 'möchte das Gerät wechseln',
    time_added: 'Zeit geändert',
    request_rejected: 'Anfrage abgelehnt',
};

export const SEVERITY_LABELS = { alarm: 'Alarm', warning: 'Warnung', info: 'Info' };

export const REQUEST_LABELS = {
    hand: 'Hand gehoben',
    release: 'Bittet um Freigabe',
    submit: 'Möchte abgeben',
    device: 'Gerätewechsel',
};

/** Kurzgrund für Alarmmeldungen (Name + Grund). */
export function alarmReason(event) {
    const base = EVENT_LABELS[event.type] ?? event.type;
    if (event.type === 'window_blur' && event.details?.duration_ms) {
        return `${base} (${Math.round(event.details.duration_ms / 100) / 10} s)`;
    }
    return base;
}

/** Beschreibung eines Ereignisses für Zeitstrahl und Protokoll. */
export function describeEvent(event) {
    const d = event.details ?? {};
    switch (event.type) {
        case 'state_changed': {
            if (d.scope === 'exam') {
                return `Klausur: ${EXAM_STATUS_LABELS[d.from] ?? d.from} → ${EXAM_STATUS_LABELS[d.to] ?? d.to}`;
            }
            const text = `${STATE_LABELS[d.from] ?? d.from} → ${STATE_LABELS[d.to] ?? d.to}`;
            return d.reason ? `${text} (${d.reason})` : text;
        }
        case 'announcement':
            return `Durchsage: ${d.message ?? ''}`;
        case 'time_added':
            return `Zeit ${d.minutes >= 0 ? '+' : ''}${d.minutes} min (Zusatzzeit gesamt ${d.extra_minutes} min)`;
        case 'time_up':
            return d.scope === 'exam' ? 'Grundzeit der Klausur abgelaufen' : 'Zeit abgelaufen';
        case 'window_blur':
            return d.duration_ms ? `Fokus verloren (${Math.round(d.duration_ms / 100) / 10} s)` : 'Fokus verloren';
        case 'paste_blocked':
        case 'paste_external':
            return `${EVENT_LABELS[event.type]}${d.length ? ` (${d.length} Zeichen)` : ''}`;
        case 'resize':
            return `Fenstergröße ${d.w ?? '?'} x ${d.h ?? '?'}`;
        case 'connection_lost':
            return d.silent_ms ? `Verbindung verloren (seit ${Math.round(d.silent_ms / 1000)} s ohne Herzschlag)` : 'Verbindung verloren';
        default:
            return EVENT_LABELS[event.type] ?? event.type;
    }
}

const pad = (n) => String(n).padStart(2, '0');

/** Uhrzeit hh:mm:ss aus Millisekunden seit Epoche. */
export function clockTime(ms) {
    if (ms === null || ms === undefined) {
        return '–';
    }
    const d = new Date(ms);
    return `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

/** Restzeit mit Vorzeichen-Schutz: H:MM:SS, MM:SS. */
export function remaining(ms) {
    return ms === null || ms === undefined ? '–' : formatDuration(ms);
}

/** "vor 12 s", "vor 3 min", "vor 2 h". */
export function ago(ms) {
    if (ms === null || ms === undefined) {
        return '–';
    }
    const s = Math.max(0, Math.round(ms / 1000));
    if (s < 60) {
        return `vor ${s} s`;
    }
    if (s < 3600) {
        return `vor ${Math.floor(s / 60)} min`;
    }
    return `vor ${Math.floor(s / 3600)} h`;
}

/** Karten-Reihenfolge für Zustände (für "Wichtigstes zuerst" in Listen). */
export const STATE_ORDER = ['locked', 'device_pending', 'submit_requested', 'absent', 'paused', 'active', 'waiting', 'submitted'];

export function plural(n, one, many) {
    return `${n} ${n === 1 ? one : many}`;
}

/** localStorage ohne Ausnahmen. */
export const storage = {
    get(key, fallback = null) {
        try {
            const value = window.localStorage.getItem(key);
            return value === null ? fallback : value;
        } catch {
            return fallback;
        }
    },
    set(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch {
            // blockiert: egal
        }
    },
};
