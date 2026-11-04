/**
 * Reine Entscheidungslogik der Überwachung (ohne DOM, testbar mit Node).
 */

/**
 * DevTools-Heuristik: Fensterrahmen deutlich größer als die Seitenfläche.
 * Im Vollbild und bei geänderter Seitenzoomstufe nie auslösen.
 */
export function devtoolsSuspected(m) {
    if (m.fullscreen) {
        return false;
    }
    if (m.baseDpr && m.dpr && Math.abs(m.dpr - m.baseDpr) > 0.01) {
        return false; // Zoom oder anderer Bildschirm
    }
    const threshold = m.threshold ?? 160;
    return m.outerW - m.innerW > threshold || m.outerH - m.innerH > threshold;
}

/**
 * Erkennt gesperrte oder warnpflichtige Tastenkombinationen.
 * @param {{key: string, ctrlKey?: boolean, metaKey?: boolean, shiftKey?: boolean, altKey?: boolean}} e
 * @returns {null|'devtools'|'viewsource'|'save'|'print'|'reload'}
 */
export function blockedShortcut(e) {
    const key = String(e.key ?? '');
    const k = key.toLowerCase();
    const mod = !!(e.ctrlKey || e.metaKey);
    if (key === 'F12') {
        return 'devtools';
    }
    if (key === 'F5') {
        return 'reload';
    }
    if (mod && e.shiftKey && ['i', 'j', 'c', 'k'].includes(k)) {
        return 'devtools';
    }
    if (e.metaKey && e.altKey && ['i', 'j', 'c', 'u'].includes(k)) {
        return 'devtools';
    }
    if (mod && !e.shiftKey && !e.altKey) {
        if (k === 'u') {
            return 'viewsource';
        }
        if (k === 's') {
            return 'save';
        }
        if (k === 'p') {
            return 'print';
        }
        if (k === 'r') {
            return 'reload';
        }
    }
    if (mod && e.shiftKey && k === 'r') {
        return 'reload';
    }
    return null;
}

/**
 * Fokusverlust mit Toleranz: kurze Verluste sind nur ein Hinweis (Details {ms}),
 * längere ein Verstoß, sobald die Schwelle überschritten ist (auch wenn der Fokus
 * nie zurückkehrt).
 */
export class BlurGuard {
    /**
     * @param {{graceMs: number, now?: () => number, setTimer?: Function, clearTimer?: Function,
     *          onInfo: (ms: number) => void, onViolation: (ms: number) => void}} o
     */
    constructor(o) {
        this.graceMs = o.graceMs;
        this.now = o.now ?? (() => Date.now());
        this.setTimer = o.setTimer ?? ((fn, ms) => setTimeout(fn, ms));
        this.clearTimer = o.clearTimer ?? ((t) => clearTimeout(t));
        this.onInfo = o.onInfo;
        this.onViolation = o.onViolation;
        this.since = null;
        this.timer = null;
        this.violated = false;
    }

    blur() {
        if (this.since !== null) {
            return;
        }
        this.since = this.now();
        this.violated = false;
        if (this.graceMs <= 0) {
            this.violated = true;
            this.onViolation(0);
            return;
        }
        this.timer = this.setTimer(() => {
            this.timer = null;
            this.violated = true;
            this.onViolation(this.now() - this.since);
        }, this.graceMs);
    }

    focus() {
        if (this.since === null) {
            return;
        }
        const ms = this.now() - this.since;
        if (this.timer !== null) {
            this.clearTimer(this.timer);
            this.timer = null;
        }
        const violated = this.violated;
        this.since = null;
        this.violated = false;
        if (!violated) {
            this.onInfo(ms);
        }
    }

    reset() {
        if (this.timer !== null) {
            this.clearTimer(this.timer);
        }
        this.timer = null;
        this.since = null;
        this.violated = false;
    }
}
