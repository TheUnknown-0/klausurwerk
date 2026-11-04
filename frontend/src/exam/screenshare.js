/**
 * Bildschirmfreigabe für die Live-Wand (getDisplayMedia).
 *
 * Ablauf: Freigabe anfordern (nach Klick), Fläche prüfen (nur der ganze Bildschirm zählt), dann im
 * Takt des Servers ein kleines JPEG-Standbild senden (Übersicht 480 px, Fokus 1280 px).
 * Es wird nichts aufgezeichnet: kein Ton, nur einzelne Bilder, der Server hält nur das letzte im
 * Arbeitsspeicher. Verweigern oder Beenden meldet nur ein Ereignis (Alarm im Pult), sperrt aber nie.
 *
 * Zustände (status): idle, requesting, active, wrong, denied, stopped, skipped, unsupported.
 * Alle Browserzugriffe sind einsetzbar (Optionen), damit die Abläufe mit Node testbar sind.
 */
import { post as apiPost } from '../lib/api.js';
import {
    SCREEN_DEFAULT_INTERVAL_MS, SCREEN_MAX_BYTES, captureSpec, dataUrlBytes, denyReason, failDelay,
    isFocusInterval, judgeSurface, screenInterval,
} from './live-logic.js';

/** Höchstdauer der Schonfrist bei offenem Auswahldialog. */
export const PICK_GRACE_MS = 30000;

export class ScreenShare {
    /**
     * @param {{api: string, events: {push: Function}, post?: Function, onChange?: (status: string) => void,
     *   getDisplayMedia?: Function, createVideo?: () => any, createCanvas?: () => any,
     *   setTimer?: Function, clearTimer?: Function, now?: () => number}} o
     */
    constructor(o) {
        this.api = o.api;
        this.events = o.events;
        this.post = o.post ?? apiPost;
        this.onChange = o.onChange ?? (() => {});
        this.getDisplayMedia = o.getDisplayMedia ?? ((c) => navigator.mediaDevices.getDisplayMedia(c));
        this.createVideo = o.createVideo ?? defaultVideo;
        this.createCanvas = o.createCanvas ?? (() => document.createElement('canvas'));
        this.setTimer = o.setTimer ?? ((fn, ms) => setTimeout(fn, ms));
        this.clearTimer = o.clearTimer ?? ((t) => clearTimeout(t));
        this.now = o.now ?? (() => Date.now());

        this.status = ScreenShare.supported() || o.getDisplayMedia ? 'idle' : 'unsupported';
        this.stream = null;
        this.track = null;
        this.video = null;
        this.canvas = null;
        this.surface = 'unknown';
        /** Auswahldialog des Browsers offen oder gerade erst geschlossen: dann keine Fokus-Verstösse. */
        this.picking = false;
        this.pickingSince = 0;
        this.pickingUntil = 0;
        /** Senden erlaubt (Teilnahme aktiv/gesperrt/pausiert und Klausur läuft). */
        this.sendingAllowed = false;
        this.intervalMs = SCREEN_DEFAULT_INTERVAL_MS;
        this.quality = null;
        this.fails = 0;
        this.timer = null;
        this.sending = false;
        this.disabled = false;
        this.stopping = false;
    }

    static supported() {
        return typeof navigator !== 'undefined' && !!navigator.mediaDevices && typeof navigator.mediaDevices.getDisplayMedia === 'function';
    }

    /** Läuft die Übertragung? */
    get active() {
        return this.status === 'active';
    }

    /**
     * Ist der Auswahldialog offen oder gerade erst geschlossen (Schonfrist für den Fokus)?
     * Die Schonfrist endet spätestens nach PICK_GRACE_MS, auch wenn der Dialog noch offen ist:
     * sonst ließe sich die Überwachung abschalten, indem man den Dialog einfach offen lässt.
     */
    isPicking() {
        const now = this.now();
        return (this.picking && now - this.pickingSince < PICK_GRACE_MS) || now < this.pickingUntil;
    }

    setStatus(s) {
        if (this.status !== s) {
            this.status = s;
            this.onChange(s);
        }
    }

    /**
     * Freigabe anfordern. Muss aus einer Nutzergeste (Klick) aufgerufen werden.
     * @returns {Promise<string>} neuer Status
     */
    async request() {
        if (this.status === 'unsupported' || this.status === 'requesting') {
            return this.status;
        }
        this.releaseStream();
        this.disabled = false;
        this.setStatus('requesting');
        this.picking = true;
        this.pickingSince = this.now();
        let stream;
        try {
            stream = await this.getDisplayMedia({ video: { displaySurface: 'monitor' }, audio: false });
        } catch (err) {
            this.picking = false;
            this.pickingUntil = this.now() + 1500;
            this.events.push('screen_share_denied', { reason: denyReason(err) });
            this.setStatus('denied');

            return this.status;
        }
        this.picking = false;
        this.pickingUntil = this.now() + 1500;
        const track = stream.getVideoTracks?.()[0] ?? null;
        if (!track) {
            stream.getTracks?.().forEach((t) => t.stop());
            this.events.push('screen_share_denied', { reason: 'error' });
            this.setStatus('denied');

            return this.status;
        }
        const verdict = judgeSurface(track.getSettings?.().displaySurface);
        if (!verdict.ok) {
            stream.getTracks().forEach((t) => t.stop());
            this.events.push('screen_share_wrong_surface', { surface: verdict.surface });
            this.surface = verdict.surface;
            this.setStatus('wrong');

            return this.status;
        }
        this.stream = stream;
        this.track = track;
        this.surface = verdict.surface;
        this.video = this.createVideo();
        this.video.srcObject = stream;
        try {
            await this.video.play?.();
        } catch {
            // Wiedergabe wird erneut versucht, sobald Bilder gebraucht werden
        }
        track.addEventListener('ended', () => this.onEnded(track));
        this.fails = 0;
        this.intervalMs = SCREEN_DEFAULT_INTERVAL_MS;
        this.quality = null;
        this.events.push('screen_share_started', { surface: verdict.surface });
        this.setStatus('active');
        this.schedule(1000);

        return this.status;
    }

    onEnded(track) {
        if (track !== this.track || this.stopping) {
            return;
        }
        this.releaseStream();
        this.events.push('screen_share_stopped', {});
        this.setStatus('stopped');
    }

    /** Ohne Freigabe fortfahren (die Lehrkraft hat bereits den Alarm bekommen). */
    skip() {
        this.releaseStream();
        this.setStatus('skipped');
    }

    /** Alles beenden (Abgabe, Klausurende, Seitenwechsel): Tracks stoppen, kein Ereignis. */
    stop() {
        this.releaseStream();
        if (this.status !== 'unsupported') {
            this.setStatus('idle');
        }
    }

    releaseStream() {
        this.clearTimer(this.timer);
        this.timer = null;
        this.stopping = true;
        try {
            this.stream?.getTracks?.().forEach((t) => t.stop());
        } finally {
            this.stopping = false;
        }
        if (this.video) {
            try {
                this.video.srcObject = null;
                this.video.remove?.();
            } catch {
                // egal
            }
        }
        this.stream = null;
        this.track = null;
        this.video = null;
    }

    /** Senden erlauben oder pausieren (je nach Zustand der Teilnahme). */
    setSendingAllowed(flag) {
        const was = this.sendingAllowed;
        this.sendingAllowed = !!flag;
        if (this.sendingAllowed && !was && this.active) {
            this.schedule(500);
        }
    }

    schedule(ms) {
        this.clearTimer(this.timer);
        this.timer = null;
        if (this.active && !this.disabled) {
            this.timer = this.setTimer(() => this.tick(), ms);
        }
    }

    /** Ein Sendeversuch; nie überlappend. Gibt zurück, ob ein Bild gesendet wurde (für Tests). */
    async tick() {
        if (!this.active || this.disabled || this.sending) {
            return false;
        }
        if (!this.sendingAllowed) {
            this.schedule(1000);

            return false;
        }
        let delay = this.intervalMs;
        let sent = false;
        this.sending = true;
        try {
            const shot = this.capture();
            if (shot) {
                const res = await this.post(`${this.api}/bildschirm`, shot, { timeoutMs: 15000 });
                sent = true;
                delay = this.handle(res);
            } else {
                // Noch kein Bild vom Video: bald erneut
                delay = 500;
            }
        } catch {
            this.fails += 1;
            delay = failDelay(this.intervalMs, this.fails);
        } finally {
            this.sending = false;
        }
        this.schedule(delay);

        return sent;
    }

    handle(res) {
        if (res.ok) {
            this.fails = 0;
            this.intervalMs = screenInterval(res.data?.data?.screen_interval_ms);

            return this.intervalMs;
        }
        if (res.status === 409 && /eingeschaltet/i.test(res.error ?? '')) {
            this.disabled = true;

            return this.intervalMs;
        }
        if (res.status === 413 || res.status === 422) {
            // zu gross oder abgelehnt: stärker komprimieren
            this.quality = Math.max(0.3, (this.quality ?? (isFocusInterval(this.intervalMs) ? 0.75 : 0.6)) - 0.15);
        }
        this.fails += 1;

        return failDelay(this.intervalMs, this.fails);
    }

    /** Aktuelles Bild des Videos als Nutzlast für POST /bildschirm, oder null. */
    capture() {
        const v = this.video;
        if (!v || !v.videoWidth || !v.videoHeight || (v.readyState !== undefined && v.readyState < 2)) {
            v?.play?.()?.catch?.(() => {});

            return null;
        }
        const focus = isFocusInterval(this.intervalMs);
        const spec = captureSpec(v.videoWidth, v.videoHeight, focus);
        const c = this.canvas ?? (this.canvas = this.createCanvas());
        c.width = spec.width;
        c.height = spec.height;
        const ctx = c.getContext('2d');
        if (!ctx) {
            return null;
        }
        ctx.drawImage(v, 0, 0, spec.width, spec.height);
        let q = this.quality ?? spec.quality;
        let jpeg = c.toDataURL('image/jpeg', q);
        let guard = 0;
        while (dataUrlBytes(jpeg) > SCREEN_MAX_BYTES && guard++ < 3) {
            q = Math.max(0.3, q - 0.15);
            jpeg = c.toDataURL('image/jpeg', q);
        }
        if (!jpeg.startsWith('data:image/jpeg;base64,') || dataUrlBytes(jpeg) > SCREEN_MAX_BYTES) {
            return null;
        }

        return { jpeg, width: spec.width, height: spec.height, surface: this.surface };
    }
}

function defaultVideo() {
    const v = document.createElement('video');
    v.muted = true;
    v.playsInline = true;
    v.autoplay = true;
    v.className = 'share-video';
    v.setAttribute('aria-hidden', 'true');
    v.tabIndex = -1;
    document.body.append(v);

    return v;
}
