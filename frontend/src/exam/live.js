/**
 * Live-Zustand für die Live-Wand: sendet die aktuelle Aufgabe, die Ansicht, den Code-Ausschnitt um
 * die Cursorzeile und die letzten Konsolenzeilen an die Lehrkraft (POST /live).
 *
 * Prüft alle 2 s; gesendet wird bei Änderung (höchstens alle 2 s), sonst alle 10 s als
 * Lebenszeichen. Nichts davon wird gespeichert, der Server hält nur den letzten Stand im Arbeitsspeicher.
 * Ohne DOM, damit die Taktlogik mit Node testbar ist; das Einsammeln liefert live-collect.js.
 */
import { post as apiPost } from '../lib/api.js';
import { LIVE_CHECK_MS, failDelay, shouldSendLive } from './live-logic.js';

export class LiveReporter {
    /**
     * @param {{api: string, collect: () => object|null, active: () => boolean,
     *   post?: Function, now?: () => number, setTimer?: Function, clearTimer?: Function,
     *   onFocus?: (focus: boolean) => void}} o
     *   collect liefert den aktuellen Zustand (null = nichts zu melden), active sagt, ob jetzt gesendet werden darf.
     */
    constructor(o) {
        this.api = o.api;
        this.collect = o.collect;
        this.active = o.active;
        this.post = o.post ?? apiPost;
        this.now = o.now ?? (() => Date.now());
        this.setTimer = o.setTimer ?? ((fn, ms) => setTimeout(fn, ms));
        this.clearTimer = o.clearTimer ?? ((t) => clearTimeout(t));
        this.onFocus = o.onFocus ?? (() => {});
        this.timer = null;
        this.running = false;
        this.sending = false;
        this.disabled = false;
        this.prev = null;
        this.lastSentAt = null;
        this.fails = 0;
    }

    start() {
        if (this.running || this.disabled) {
            return;
        }
        this.running = true;
        this.schedule(0);
    }

    stop() {
        this.running = false;
        this.clearTimer(this.timer);
        this.timer = null;
    }

    schedule(ms) {
        this.clearTimer(this.timer);
        if (this.running) {
            this.timer = this.setTimer(() => this.tick(), ms);
        }
    }

    /** Eine Prüfrunde; gibt zurück, ob gesendet wurde (für Tests). */
    async tick() {
        if (!this.running || this.sending) {
            return false;
        }
        let sent = false;
        let delay = LIVE_CHECK_MS;
        try {
            if (this.active()) {
                const next = this.collect();
                const now = this.now();
                if (next && shouldSendLive({ prev: this.prev, next, lastSentAt: this.lastSentAt, now })) {
                    this.sending = true;
                    sent = true;
                    const res = await this.post(`${this.api}/live`, { ...next, client_ms: now }, { timeoutMs: 6000 });
                    this.sending = false;
                    delay = this.handle(res, next, now);
                }
            }
        } catch {
            this.sending = false;
            this.fails += 1;
            delay = failDelay(LIVE_CHECK_MS, this.fails);
        }
        this.schedule(delay);

        return sent;
    }

    handle(res, next, now) {
        if (res.ok) {
            this.prev = next;
            this.lastSentAt = now;
            this.fails = 0;
            const focus = res.data?.data?.focus;
            if (typeof focus === 'boolean') {
                this.onFocus(focus);
            }

            return LIVE_CHECK_MS;
        }
        if (res.status === 409 && /eingeschaltet/i.test(res.error ?? '')) {
            // Live-Ansicht ist für diese Klausur aus: nicht weiter versuchen.
            this.disabled = true;
            this.running = false;

            return LIVE_CHECK_MS;
        }
        // 409 (Zustand), 429, Netzwerkfehler, 5xx: zurückhaltend wiederholen. Der Stand bleibt "ungesendet".
        this.fails += 1;

        return failDelay(LIVE_CHECK_MS, this.fails);
    }
}
