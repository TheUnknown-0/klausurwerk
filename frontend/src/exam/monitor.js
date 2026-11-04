/**
 * Überwachung der Schüleransicht (Vollbild, Fokus, Einfügen, Drag and Drop,
 * Tastenkürzel, zweiter Tab, DevTools-Heuristik, Fenstergröße).
 *
 * Die Klasse meldet Ereignisse an die EventQueue und ruft bei Verstößen
 * onViolation auf; die Oberfläche sperrt dann lokal und sofort, unabhängig
 * vom Server. Nur im Modus exam/probe aktiv (nicht practice, nicht Vorschau).
 */
import { PasteTracker, decidePaste } from './paste.js';
import { BlurGuard, blockedShortcut, devtoolsSuspected } from './monitor-logic.js';

const MESSAGES = {
    fullscreen_exit: 'Du hast den Vollbildmodus verlassen. Die Lehrkraft wurde benachrichtigt.',
    visibility_hidden: 'Du hast die Klausurseite verlassen (anderer Tab oder Fenster). Die Lehrkraft wurde benachrichtigt.',
    window_blur: 'Das Klausurfenster hat den Fokus verloren. Die Lehrkraft wurde benachrichtigt.',
    second_tab: 'Die Klausur ist in einem zweiten Tab geöffnet. Die Lehrkraft wurde benachrichtigt.',
};

export const violationMessage = (type) => MESSAGES[type] ?? 'Ein Verstoß wurde erkannt. Die Lehrkraft wurde benachrichtigt.';

const safe = {
    get(storage, key) {
        try {
            return window[storage].getItem(key);
        } catch {
            return null;
        }
    },
    set(storage, key, value) {
        try {
            window[storage].setItem(key, value);
        } catch {
            // Speicher gesperrt: ohne weiterarbeiten
        }
    },
    remove(storage, key) {
        try {
            window[storage].removeItem(key);
        } catch {
            // egal
        }
    },
};

/** Erkennt eine zweite geöffnete Klausurseite (BroadcastChannel plus localStorage-Herzschlag). */
export class TabGuard {
    constructor(examId, onSecond) {
        this.examId = examId;
        this.onSecond = onSecond;
        this.name = `kw-exam-${examId}`;
        this.instance = Math.random().toString(36).slice(2);
        this.since = Date.now();
        this.second = false;
        this.channel = null;
        this.timer = null;
        const stored = safe.get('sessionStorage', `${this.name}-tab`);
        this.tab = stored || Math.random().toString(36).slice(2);
        if (!stored) {
            safe.set('sessionStorage', `${this.name}-tab`, this.tab);
        }
    }

    start() {
        // 1. BroadcastChannel: Ein lebender älterer Tab antwortet auf "hello" mit "present".
        if (typeof BroadcastChannel !== 'undefined') {
            try {
                this.channel = new BroadcastChannel(this.name);
                this.channel.onmessage = (ev) => this.onMessage(ev.data);
                this.channel.postMessage({ type: 'hello', from: this.instance, since: this.since });
            } catch {
                this.channel = null;
            }
        }
        // 2. Nur ohne BroadcastChannel: frischer localStorage-Herzschlag eines anderen Tabs.
        //    Mit Kanal wäre das ein Fehlalarm, wenn der Browser abstürzt und sofort neu startet
        //    (der alte Herzschlag liegt noch da, aber kein Tab antwortet mehr).
        if (!this.channel) {
            try {
                const hb = JSON.parse(safe.get('localStorage', `${this.name}-hb`) || 'null');
                if (hb && hb.tab !== this.tab && Date.now() - hb.ts < 5000) {
                    this.flagSecond();
                }
            } catch {
                // kaputter Eintrag: ignorieren
            }
        }
        this.beat();
        this.timer = setInterval(() => this.beat(), 2000);
        // Beim normalen Schließen den Herzschlag sofort entfernen.
        this.onPageHide = () => {
            if (!this.second) {
                safe.remove('localStorage', `${this.name}-hb`);
            }
        };
        if (typeof window !== 'undefined' && window.addEventListener) {
            window.addEventListener('pagehide', this.onPageHide);
        }
    }

    onMessage(msg) {
        if (!msg || msg.from === this.instance) {
            return;
        }
        // Wer auf sein "hello" ein "present" bekommt, ist hinzugekommen, während schon ein Tab lief.
        // Ohne Zeitvergleich: gleiche Millisekunde oder eine verstellte Uhr dürfen nichts verdecken.
        // Starten zwei Tabs gleichzeitig, melden sich beide (beide sind dann ja offen).
        if (msg.type === 'hello' && !this.second) {
            this.channel?.postMessage({ type: 'present', from: this.instance, to: msg.from });
        } else if (msg.type === 'present' && msg.to === this.instance) {
            this.flagSecond();
        }
    }

    beat() {
        if (!this.second) {
            safe.set('localStorage', `${this.name}-hb`, JSON.stringify({ tab: this.tab, ts: Date.now() }));
        }
    }

    flagSecond() {
        if (this.second) {
            return;
        }
        this.second = true;
        this.onSecond();
    }

    stop() {
        clearInterval(this.timer);
        if (this.onPageHide && typeof window !== 'undefined' && window.removeEventListener) {
            window.removeEventListener('pagehide', this.onPageHide);
        }
        this.channel?.close();
        if (!this.second) {
            safe.remove('localStorage', `${this.name}-hb`);
        }
    }
}

export class Monitor {
    /**
     * @param {{examId: number, settings: object, enabled: boolean, events: any,
     *   isActive: () => boolean, fullscreenRequired: () => boolean,
     *   onViolation: (v: {type: string, details: object, message: string}) => void,
     *   notify: (msg: string, type?: string) => void, onSecondTab?: () => void}} o
     */
    constructor(o) {
        this.o = o;
        this.settings = o.settings ?? {};
        this.tracker = new PasteTracker();
        this.internalDrag = false;
        this.timers = [];
        this.listeners = [];
        this.lastDevtoolsAt = 0;
        this.devtoolsStreak = 0;
        this.baseDpr = window.devicePixelRatio || 1;
        this.lastFsChange = 0;
        this.lastResizeSent = 0;
        this.tabGuard = null;
        this.blur = new BlurGuard({
            graceMs: Number(this.settings.violation_grace_ms ?? 300),
            onInfo: (ms) => {
                if (this.o.isActive()) {
                    this.o.events.push('window_blur', { duration_ms: ms });
                }
            },
            onViolation: (ms) => this.violation('window_blur', { duration_ms: ms }),
        });
    }

    on(target, type, fn, opts) {
        target.addEventListener(type, fn, opts);
        this.listeners.push(() => target.removeEventListener(type, fn, opts));
    }

    start() {
        // Interne Kopiervorgänge werden immer gemerkt (auch wenn nicht überwacht wird).
        this.on(document, 'copy', (e) => this.rememberCopy(e));
        this.on(document, 'cut', (e) => this.rememberCopy(e));
        this.on(document, 'dragstart', () => {
            this.internalDrag = true;
        });
        this.on(document, 'dragend', () => {
            this.internalDrag = false;
        });
        this.on(window, 'dragover', (e) => this.onDragOver(e), true);
        this.on(window, 'drop', (e) => this.onDrop(e), true);

        if (!this.o.enabled) {
            return;
        }
        this.on(window, 'paste', (e) => this.onPaste(e), true);
        this.on(document, 'contextmenu', (e) => {
            if (this.o.isActive()) {
                e.preventDefault();
            }
        }, true);
        this.on(window, 'keydown', (e) => this.onKey(e), true);
        this.on(document, 'fullscreenchange', () => this.onFullscreenChange());
        this.on(document, 'webkitfullscreenchange', () => this.onFullscreenChange());
        this.on(document, 'visibilitychange', () => {
            if (document.visibilityState === 'hidden') {
                this.violation('visibility_hidden', {});
            }
        });
        this.on(window, 'blur', () => {
            // Fokus in einem eingebetteten Rahmen (PDF-Hilfsmittel) lässt document.hasFocus() wahr: kein Verstoß.
            setTimeout(() => {
                if (this.o.isActive() && !document.hasFocus()) {
                    this.blur.blur();
                }
            }, 0);
        });
        this.on(window, 'focus', () => this.blur.focus());
        this.on(window, 'resize', () => this.onResize());

        this.tabGuard = new TabGuard(this.o.examId, () => {
            this.o.events.push('second_tab', {});
            this.o.onSecondTab?.();
        });
        this.tabGuard.start();

        this.timers.push(setInterval(() => this.checkDevtools(), 1500));
    }

    stop() {
        this.listeners.forEach((off) => off());
        this.listeners = [];
        this.timers.forEach((t) => clearInterval(t));
        this.timers = [];
        this.blur.reset();
        this.tabGuard?.stop();
    }

    /** Fokusverfolgung zurücksetzen (z. B. nach Sperre). */
    resetFocusTracking() {
        this.blur.reset();
    }

    violation(type, details) {
        if (!this.o.isActive()) {
            return;
        }
        this.o.events.push(type, details);
        this.o.onViolation({ type, details, message: violationMessage(type) });
    }

    onFullscreenChange() {
        this.lastFsChange = Date.now();
        const inFs = !!(document.fullscreenElement || document.webkitFullscreenElement);
        if (!inFs && this.o.fullscreenRequired()) {
            this.violation('fullscreen_exit', {});
        }
    }

    onResize() {
        clearTimeout(this.resizeTimer);
        this.resizeTimer = setTimeout(() => {
            const now = Date.now();
            if (!this.o.isActive() || now - this.lastFsChange < 1500 || now - this.lastResizeSent < 3000) {
                return;
            }
            this.lastResizeSent = now;
            this.o.events.push('resize', { w: window.innerWidth, h: window.innerHeight });
        }, 800);
    }

    checkDevtools() {
        if (!this.o.isActive()) {
            this.devtoolsStreak = 0;
            return;
        }
        const suspected = devtoolsSuspected({
            outerW: window.outerWidth,
            innerW: window.innerWidth,
            outerH: window.outerHeight,
            innerH: window.innerHeight,
            fullscreen: !!(document.fullscreenElement || document.webkitFullscreenElement),
            dpr: window.devicePixelRatio || 1,
            baseDpr: this.baseDpr,
        });
        this.devtoolsStreak = suspected ? this.devtoolsStreak + 1 : 0;
        if (this.devtoolsStreak >= 3 && Date.now() - this.lastDevtoolsAt > 60000) {
            this.lastDevtoolsAt = Date.now();
            this.o.events.push('devtools_suspected', {
                dw: window.outerWidth - window.innerWidth,
                dh: window.outerHeight - window.innerHeight,
            });
        }
    }

    // ---------- Kopieren und Einfügen ----------

    rememberCopy(e) {
        let text = '';
        try {
            text = e.clipboardData?.getData('text/plain') || '';
        } catch {
            text = '';
        }
        if (!text) {
            const a = document.activeElement;
            if (a && (a.tagName === 'TEXTAREA' || (a.tagName === 'INPUT' && typeof a.selectionStart === 'number'))) {
                try {
                    text = a.value.slice(a.selectionStart, a.selectionEnd);
                } catch {
                    text = '';
                }
            }
        }
        if (!text) {
            text = String(window.getSelection?.() ?? '');
        }
        this.tracker.remember(text);
    }

    onPaste(e) {
        if (!this.o.isActive()) {
            return;
        }
        const data = e.clipboardData;
        const policy = this.settings.paste_policy ?? 'internal';
        const hasFiles = !!(data && data.files && data.files.length > 0);
        let text = '';
        try {
            text = data?.getData('text/plain') ?? '';
        } catch {
            text = '';
        }
        let decision;
        if (hasFiles && text === '') {
            decision = policy === 'allow_log'
                ? { allow: true, event: { type: 'paste_external', details: { length: 0 } } }
                : { allow: false, event: { type: 'paste_blocked', details: { length: 0 } } };
        } else {
            decision = decidePaste(policy, this.tracker, text);
        }
        if (decision.event) {
            this.o.events.push(decision.event.type, decision.event.details);
        }
        if (!decision.allow) {
            e.preventDefault();
            e.stopImmediatePropagation();
            this.o.notify('Einfügen ist nur für Text erlaubt, den du in der Klausur selbst kopiert hast.', 'warning');
        }
    }

    onDragOver(e) {
        if (!this.internalDrag) {
            e.preventDefault();
            if (e.dataTransfer) {
                e.dataTransfer.dropEffect = 'none';
            }
        }
    }

    onDrop(e) {
        if (this.internalDrag) {
            return;
        }
        // Nie zulassen: Der Browser würde sonst zur gezogenen Datei navigieren.
        e.preventDefault();
        e.stopPropagation();
        if (this.o.enabled && this.o.isActive()) {
            this.o.events.push('drop_blocked', {});
            this.o.notify('Ziehen von Inhalten von außen ist in der Klausur nicht erlaubt.', 'warning');
        }
    }

    // ---------- Tasten ----------

    onKey(e) {
        const kind = blockedShortcut(e);
        if (!kind || !this.o.isActive()) {
            return;
        }
        if (kind === 'reload') {
            this.o.notify('Neu laden ist riskant: Nicht gesendete Änderungen könnten verloren gehen. Dein Stand wird lokal gesichert, die Lehrkraft sieht den Neustart.', 'warning');
            return; // Der Browser fragt über beforeunload nach.
        }
        e.preventDefault();
        e.stopPropagation();
        if (kind === 'print') {
            this.o.notify('Drucken ist in der Klausur nicht möglich.', 'warning');
        } else {
            this.o.notify('Diese Tastenkombination ist in der Klausur gesperrt.', 'warning');
        }
    }
}

/** Reload-Marker über sessionStorage: beim nächsten Start ein reload-Ereignis melden. */
export const reloadMarker = {
    key: (examId) => `kw-exam-${examId}-unload`,
    mark(examId) {
        safe.set('sessionStorage', this.key(examId), String(Date.now()));
    },
    consume(examId) {
        const v = Number(safe.get('sessionStorage', this.key(examId)));
        safe.remove('sessionStorage', this.key(examId));
        return Number.isFinite(v) && v > 0 && Date.now() - v < 30 * 60 * 1000;
    },
};
