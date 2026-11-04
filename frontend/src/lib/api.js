/**
 * Gemeinsamer HTTP-Helfer für alle Bundles.
 *
 *  - setzt X-CSRF-Token (aus <meta name="csrf-token">) und Accept: JSON
 *  - liefert IMMER ein Objekt zurück, wirft nicht:
 *      { ok: true,  status, data }                    fachlich erfolgreich (2xx)
 *      { ok: false, status, data, error, transient }  Ablehnung oder Störung
 *    transient = true heißt: Netzwerkfehler, Timeout, 5xx oder keine JSON-Antwort.
 *    Aufrufer (Autosave!) dürfen nur bei transient erneut senden.
 */

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content ?? '';

export const csrfToken = () => meta('csrf-token');

/** Basis-Pfad der Anwendung (BASE_URL), ohne abschließenden Slash. */
export const baseUrl = () => meta('base-url').replace(/\/$/, '');

/** Baut eine URL relativ zur Anwendungswurzel: url('/api/x') */
export const url = (path) => baseUrl() + path;

/**
 * @param {string} path  Pfad ab Anwendungswurzel ('/api/…') oder absolute URL
 * @param {{method?: string, json?: any, body?: BodyInit, timeoutMs?: number, signal?: AbortSignal, keepalive?: boolean}} [opts]
 */
export async function request(path, opts = {}) {
    const headers = { 'X-CSRF-Token': csrfToken(), Accept: 'application/json' };
    let body = opts.body;
    if (opts.json !== undefined) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(opts.json);
    }
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), opts.timeoutMs ?? 15000);
    if (opts.signal) {
        opts.signal.addEventListener('abort', () => controller.abort(), { once: true });
    }
    const target = /^https?:/.test(path) ? path : url(path);
    try {
        const res = await fetch(target, {
            method: opts.method ?? (body !== undefined ? 'POST' : 'GET'),
            headers,
            body,
            credentials: 'same-origin',
            signal: controller.signal,
            keepalive: opts.keepalive ?? false,
        });
        let data = null;
        try {
            data = await res.json();
        } catch {
            return { ok: false, status: res.status, data: null, transient: true, error: `Unerwartete Antwort (HTTP ${res.status}).` };
        }
        if (res.ok && data?.success !== false) {
            return { ok: true, status: res.status, data };
        }
        return {
            ok: false,
            status: res.status,
            data,
            transient: res.status >= 500 || res.status === 429,
            error: data?.error ?? `Fehler (HTTP ${res.status}).`,
        };
    } catch (e) {
        return { ok: false, status: 0, data: null, transient: true, error: e?.name === 'AbortError' ? 'Zeitüberschreitung.' : 'Keine Verbindung zum Server.' };
    } finally {
        clearTimeout(timeout);
    }
}

export const get = (path, opts = {}) => request(path, { ...opts, method: 'GET' });
export const post = (path, json, opts = {}) => request(path, { ...opts, method: 'POST', json: json ?? {} });

/** Liest einen JSON-Bootstrap-Block: <script type="application/json" id="…">. */
export function bootData(id) {
    const el = document.getElementById(id);
    if (!el) {
        return null;
    }
    try {
        return JSON.parse(el.textContent || 'null');
    } catch {
        return null;
    }
}
