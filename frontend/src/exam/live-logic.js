/**
 * Reine Logik der Live-Wand (Schülerseite), ohne DOM und ohne Netzwerk, testbar mit Node:
 * Code-Ausschnitt, Konsolenende, Takt, Bildgrössen und Bewertung der Freigabe.
 */

export const LIVE_MAX_CODE_LINES = 60;
export const LIVE_MAX_CODE_CHARS = 4000;
export const LIVE_MAX_CONSOLE_LINES = 20;
export const LIVE_MAX_CONSOLE_CHARS = 2000;

/** Lebenszeichen und Mindestabstand des Live-Zustands. */
export const LIVE_CHECK_MS = 2000;
export const LIVE_KEEPALIVE_MS = 10000;

export const SCREEN_DEFAULT_INTERVAL_MS = 5000;
export const SCREEN_MIN_INTERVAL_MS = 500;
export const SCREEN_MAX_INTERVAL_MS = 15000;
/** Der Server nimmt höchstens 600 KB Bilddaten an; etwas Luft lassen. */
export const SCREEN_MAX_BYTES = 560 * 1024;

const splitLines = (text) => String(text ?? '').replace(/\r\n?/g, '\n').split('\n');

/**
 * Code rund um die Cursorzeile (1-basiert). Höchstens maxLines Zeilen und maxChars Zeichen;
 * wird es zu viel, fallen zuerst die am weitesten vom Cursor entfernten Zeilen weg.
 * @returns {{excerpt: string, startLine: number}}
 */
export function excerptAround(text, cursorLine, maxLines = LIVE_MAX_CODE_LINES, maxChars = LIVE_MAX_CODE_CHARS) {
    const lines = splitLines(text);
    const total = lines.length;
    const cursor = Math.min(Math.max(1, Math.floor(Number(cursorLine) || 1)), total);
    let start = Math.max(1, cursor - Math.floor(maxLines / 2));
    let end = Math.min(total, start + maxLines - 1);
    start = Math.max(1, end - maxLines + 1);
    const size = () => {
        let n = 0;
        for (let i = start; i <= end; i++) {
            n += lines[i - 1].length + 1;
        }
        return n - 1;
    };
    while (size() > maxChars && (start < cursor || end > cursor)) {
        if (cursor - start >= end - cursor && start < cursor) {
            start += 1;
        } else {
            end -= 1;
        }
    }
    let excerpt = lines.slice(start - 1, end).join('\n');
    if (excerpt.length > maxChars) {
        excerpt = excerpt.slice(0, maxChars);
    }

    return { excerpt, startLine: start };
}

/** Letzte Konsolenzeilen (Ende bleibt erhalten), höchstens maxLines Zeilen und maxChars Zeichen. */
export function tailLines(text, maxLines = LIVE_MAX_CONSOLE_LINES, maxChars = LIVE_MAX_CONSOLE_CHARS) {
    let lines = splitLines(text);
    while (lines.length > 0 && lines[lines.length - 1] === '') {
        lines.pop();
    }
    lines = lines.slice(-maxLines);
    const out = lines.join('\n');

    return out.length > maxChars ? out.slice(out.length - maxChars) : out;
}

/** Gleicher Inhalt? (client_ms zählt nicht) */
export function sameLive(a, b) {
    if (!a || !b) {
        return false;
    }
    return a.task_id === b.task_id && a.view === b.view && a.file === b.file && a.cursor_line === b.cursor_line
        && a.code_excerpt === b.code_excerpt && a.excerpt_start_line === b.excerpt_start_line && a.console_tail === b.console_tail;
}

/**
 * Soll jetzt gesendet werden? Bei Änderung höchstens alle 2 s, ohne Änderung alle 10 s als Lebenszeichen.
 * @param {{prev: object|null, next: object, lastSentAt: number|null, now: number}} o
 */
export function shouldSendLive({ prev, next, lastSentAt, now }) {
    if (lastSentAt === null || lastSentAt === undefined) {
        return true;
    }
    const age = now - lastSentAt;
    if (age >= LIVE_KEEPALIVE_MS) {
        return true;
    }

    return age >= LIVE_CHECK_MS && !sameLive(prev, next);
}

/** Wartezeit nach einem Fehler: verdoppelt sich, höchstens 30 s. */
export function failDelay(baseMs, fails) {
    return Math.min(30000, baseMs * 2 ** Math.max(0, Math.min(fails, 6)));
}

// ---------- Bildschirm ----------

/** Takt aus der Serverantwort, begrenzt; ohne gültige Angabe der Standard. */
export function screenInterval(ms) {
    const n = Number(ms);
    if (!Number.isFinite(n) || n <= 0) {
        return SCREEN_DEFAULT_INTERVAL_MS;
    }

    return Math.max(SCREEN_MIN_INTERVAL_MS, Math.min(SCREEN_MAX_INTERVAL_MS, Math.round(n)));
}

/** Übersicht (Takt ab 5 s): 480 px breit; Fokus (schneller Takt): 1280 px breit, bessere Qualität. */
export function isFocusInterval(ms) {
    return screenInterval(ms) < 2500;
}

/** Grösse und JPEG-Qualität; nie über die Quelle hinaus vergrössern. */
export function captureSpec(srcW, srcH, focus) {
    const target = focus ? 1280 : 480;
    const w = Math.max(1, Math.floor(Number(srcW) || 0));
    const h = Math.max(1, Math.floor(Number(srcH) || 0));
    const width = Math.min(target, w);
    const height = Math.max(1, Math.round(h * (width / w)));

    return { width, height, quality: focus ? 0.75 : 0.6 };
}

/** Grösse der Bilddaten in Byte aus einer Data-URL (Base64). */
export function dataUrlBytes(dataUrl) {
    const s = String(dataUrl ?? '');
    const i = s.indexOf(',');
    if (i < 0) {
        return 0;
    }
    const b64 = s.slice(i + 1);
    const pad = b64.endsWith('==') ? 2 : b64.endsWith('=') ? 1 : 0;

    return Math.floor((b64.length * 3) / 4) - pad;
}

/**
 * Bewertung der freigegebenen Fläche (track.getSettings().displaySurface).
 * Nicht meldende Browser (z. B. ohne displaySurface) werden als "unknown" angenommen,
 * denn dort ist die Prüfung nicht möglich.
 * @returns {{ok: boolean, surface: 'monitor'|'window'|'browser'|'unknown'}}
 */
export function judgeSurface(displaySurface) {
    if (displaySurface === 'monitor') {
        return { ok: true, surface: 'monitor' };
    }
    if (displaySurface === 'window' || displaySurface === 'application') {
        return { ok: false, surface: 'window' };
    }
    if (displaySurface === 'browser') {
        return { ok: false, surface: 'browser' };
    }

    return { ok: true, surface: 'unknown' };
}

/** Grund für screen_share_denied aus dem Fehler von getDisplayMedia. */
export function denyReason(err) {
    switch (err?.name) {
        case 'NotAllowedError':
            return 'not_allowed';
        case 'NotFoundError':
            return 'not_found';
        case 'NotReadableError':
        case 'AbortError':
            return 'not_readable';
        case 'NotSupportedError':
        case 'TypeError':
            return 'unsupported';
        default:
            return 'error';
    }
}

/**
 * Ist die Bildschirmfreigabe für diese Teilnahme gefordert?
 * Nur Klausur/Technik-Check, nie Übung und Vorschau, nie im Safe Exam Browser.
 */
export function shareRequired({ screenShare, mode, preview, seb }) {
    return !!screenShare && !preview && !seb && (mode === 'exam' || mode === 'probe');
}

/** Sendet der Live-Zustand? Gleiche Modi wie die Überwachung (exam/probe), nicht Übung und Vorschau. */
export function liveRequired({ liveView, mode, preview }) {
    return !!liveView && !preview && (mode === 'exam' || mode === 'probe');
}

/** Nimmt der Server in diesem Zustand Live-Daten an? */
export function acceptsLive(state, examRunning) {
    return !!examRunning && (state === 'active' || state === 'locked' || state === 'paused');
}
