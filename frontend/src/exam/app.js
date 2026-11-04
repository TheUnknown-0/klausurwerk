/**
 * Schüleransicht: Steuerung von Beitritt, Herzschlag, Zuständen, Überwachung,
 * Autosave, Sperrbildschirmen und Abgabe.
 */
import { get, post, url } from '../lib/api.js';
import { h, replace, toast } from '../lib/dom.js';
import { createRuntime } from '../runtime/index.js';
import { Sound } from './sound.js';
import { createStore } from './store.js';
import { Autosave } from './autosave.js';
import { EventQueue } from './events.js';
import { Monitor, reloadMarker } from './monitor.js';
import { clockView } from './clock.js';
import { enterFullscreen, fullscreenSupported, isFullscreen } from './fullscreen.js';
import { overlays } from './overlays.js';
import { WaitingView } from './view-wait.js';
import { ExamView } from './view-exam.js';
import { TechCheck } from './techcheck.js';
import { taskLabel } from './answers.js';
import { mergeSettings } from './settings.js';

const PULSE_MS = 2000;
const CONTENT_STATES = new Set(['active', 'locked', 'absent', 'paused', 'submit_requested']);

const ss = {
    get(k) {
        try {
            return sessionStorage.getItem(k);
        } catch {
            return null;
        }
    },
    set(k, v) {
        try {
            sessionStorage.setItem(k, v);
        } catch {
            // ohne Merken weiterarbeiten
        }
    },
};

export class ExamApp {
    constructor(root, boot) {
        this.root = root;
        this.boot = boot;
        this.preview = !!boot?.preview;
        this.mode = boot?.mode ?? 'exam';
        this.api = boot?.api_base ?? '';
        this.settings = mergeSettings({}, this.mode);
        this.sound = new Sound();

        this.state = 'waiting';
        this.lockReason = null;
        this.examStatus = null;
        this.phase = 'joining';
        this.joinError = null;
        this.joined = false;
        this.ready = false;
        this.readyBusy = false;
        this.running = false;

        this.offset = 0;
        this.clockData = null;
        this.clockReceived = Date.now();
        this.latencies = [];
        this.pulseFails = 0;
        this.pulseTimer = null;
        this.pulsing = false;
        this.pulseStopped = false;
        this.onlineSince = Date.now();

        this.handRaised = false;
        this.openRequests = [];
        this.releaseRequested = false;
        this.releaseRequestedAt = 0;
        this.releaseSeenOpen = false;
        this.announcements = [];
        this.annIds = new Set();
        this.annCursor = 0;
        this.firstAnnouncements = true;

        this.content = null;
        this.loadingContent = false;
        this.answers = {};
        this.tasks = [];
        this.materials = [];
        this.operators = [];
        this.view = null;
        this.waiting = null;
        this.tech = null;
        this.autosave = null;
        this.events = null;
        this.monitor = null;
        this.armed = false;
        this.localLock = null;
        this.secondTab = false;
        this.lastAlarmAt = 0;
        this.submittedAt = null;
        this.currentOverlay = null;
        this.timeUpShown = false;
        this.fatal = false;
        this.userKey = String(boot?.user?.id ?? boot?.user?.name ?? 'anon');
    }

    get monitorEnabled() {
        return !this.preview && this.mode !== 'practice';
    }

    fullscreenRequired() {
        return this.monitorEnabled && !this.boot?.seb?.detected && fullscreenSupported();
    }

    serverNow() {
        return Date.now() + this.offset;
    }

    // ---------- Start ----------

    async start() {
        if (!this.boot || !this.api) {
            this.mountShell();
            this.showMessage('Die Klausur kann nicht gestartet werden', 'Die Startdaten der Seite fehlen. Lade die Seite neu. Wenn das nicht hilft, sage der Lehrkraft Bescheid.', true);
            return;
        }
        this.mountShell();
        this.sound.keepAlive();
        this.installUnloadHandlers();
        setInterval(() => this.tick(), 500);
        if (this.preview) {
            await this.startPreview();
            return;
        }
        this.store = createStore();
        this.events = new EventQueue({
            apiBase: this.api,
            storeKey: `${this.boot.exam_id}:${this.userKey}:events`,
            store: this.store,
            onResult: (res) => this.onEventsResult(res),
        });
        await this.events.init();
        if (reloadMarker.consume(this.boot.exam_id)) {
            this.events.push('reload', {});
        }
        this.showWaiting();
        const saved = ss.get(`kw-code-${this.boot.exam_id}`);
        if (this.boot.needs_access_code && !saved) {
            this.phase = 'code';
            this.updateWaiting();
            return;
        }
        this.join(saved || null);
    }

    mountShell() {
        this.viewHost = h('div', { class: 'exam-view-host' });
        this.overlayHost = h('div', { class: 'exam-overlay-host' });
        replace(this.root, h('div', { class: 'exam-shell' }, this.viewHost, this.overlayHost));
    }

    showMessage(title, text, reload = false) {
        if (!this.viewHost) {
            this.mountShell();
        }
        const actions = [];
        if (reload) {
            const b = h('button', { type: 'button', class: 'btn btn-primary' }, 'Seite neu laden');
            b.addEventListener('click', () => location.reload());
            actions.push(b);
        }
        replace(this.viewHost, h('main', { class: 'wait-view' }, h('div', { class: 'wait-card card' }, h('h1', {}, title), h('p', {}, text), ...actions)));
    }

    // ---------- Vorschau ----------

    async startPreview() {
        this.state = 'active';
        this.clockData = { started: true, unlimited: true };
        this.showMessage(this.boot.title ?? 'Vorschau', 'Lade Aufgaben ...');
        const ok = await this.loadContent();
        if (!ok && !this.content) {
            this.showMessage('Die Vorschau konnte nicht geladen werden', this.contentError ?? 'Unbekannter Fehler.', true);
        }
    }

    // ---------- Beitritt ----------

    showWaiting() {
        this.waiting = new WaitingView({
            boot: this.boot,
            sound: this.sound,
            getLatency: () => this.avgLatency(),
            onJoin: (code) => this.join(code),
            onReady: (details) => this.onReady(details),
        });
        replace(this.viewHost, this.waiting.el);
        this.updateWaiting();
    }

    updateWaiting() {
        this.waiting?.refresh({
            phase: this.phase,
            error: this.joinError,
            examStatus: this.examStatus,
            ready: this.ready,
            readyBusy: this.readyBusy,
            running: this.running,
        });
    }

    async join(code) {
        clearTimeout(this.joinTimer);
        this.phase = 'joining';
        this.joinError = null;
        this.updateWaiting();
        const res = await post(`${this.api}/beitreten`, {
            access_code: code || null,
            client: {
                ua: navigator.userAgent,
                screen: { w: window.screen.width, h: window.screen.height },
                seb: !!this.boot.seb?.detected,
            },
        }, { timeoutMs: 15000 });
        if (res.ok) {
            if (code) {
                ss.set(`kw-code-${this.boot.exam_id}`, code);
            }
            this.joined = true;
            this.phase = 'joined';
            if (typeof res.data.server_time_ms === 'number') {
                this.offset = res.data.server_time_ms - Date.now();
            }
            this.events.release();
            this.updateWaiting();
            this.setState(res.data.participation?.state ?? 'waiting', null);
            this.startPulse();
            return;
        }
        if (res.status === 401) {
            this.phase = 'error';
            this.joinError = 'Deine Anmeldung ist abgelaufen. Melde dich bitte neu an.';
        } else if (res.status === 403) {
            this.joinError = res.error;
            this.phase = this.boot.needs_access_code ? 'code' : 'error';
            ss.set(`kw-code-${this.boot.exam_id}`, '');
        } else if (res.status === 409) {
            this.phase = 'retry';
            this.joinError = res.error;
            this.joinTimer = setTimeout(() => this.join(code), 5000);
        } else if (res.transient) {
            this.phase = 'retry';
            this.joinError = `${res.error} Es wird automatisch erneut versucht.`;
            this.joinTimer = setTimeout(() => this.join(code), 4000);
        } else {
            this.phase = 'error';
            this.joinError = res.error;
        }
        this.updateWaiting();
    }

    async onReady(details) {
        if (this.readyBusy || this.ready) {
            return;
        }
        this.readyBusy = true;
        this.updateWaiting();
        await this.sound.unlock(true);
        if (this.mode !== 'practice') {
            await enterFullscreen();
        }
        const body = details ? { details } : {};
        const res = await post(`${this.api}/bereit`, body, { timeoutMs: 10000 });
        this.readyBusy = false;
        if (res.ok) {
            this.ready = true;
        } else if (res.status === 409) {
            this.kickPulse();
        } else {
            toast(`"Bereit" konnte nicht gemeldet werden: ${res.error} Versuche es noch einmal.`, 'error');
        }
        this.updateWaiting();
    }

    // ---------- Herzschlag ----------

    avgLatency() {
        if (this.latencies.length === 0) {
            return null;
        }
        return Math.round(this.latencies.reduce((a, b) => a + b, 0) / this.latencies.length);
    }

    startPulse() {
        if (this.pulseTimer !== null || this.pulseStopped) {
            return;
        }
        this.pulseTimer = setTimeout(() => this.pulse(), 0);
    }

    kickPulse() {
        if (this.pulseStopped || !this.joined) {
            return;
        }
        clearTimeout(this.pulseTimer);
        this.pulseTimer = setTimeout(() => this.pulse(), 0);
    }

    clientState() {
        return {
            fullscreen: isFullscreen(),
            visible: document.visibilityState === 'visible',
            focused: document.hasFocus(),
            seb: !!this.boot.seb?.detected,
            screen: { w: window.screen.width, h: window.screen.height },
            online_since_ms: Math.round(this.onlineSince + this.offset),
            pending_saves: this.autosave?.pendingCount ?? 0,
            current_task_id: this.view?.currentTaskId ?? null,
        };
    }

    async pulse() {
        if (this.pulsing || this.pulseStopped) {
            return;
        }
        this.pulsing = true;
        const t0 = performance.now();
        let res;
        try {
            res = await post(`${this.api}/puls`, { client_state: this.clientState(), announcement_cursor: this.annCursor }, { timeoutMs: 6000 });
        } catch {
            res = { ok: false, transient: true, status: 0, error: 'Keine Verbindung zum Server.' };
        }
        const rtt = performance.now() - t0;
        this.pulsing = false;
        try {
            if (res.ok) {
                this.latencies.push(Math.round(rtt));
                if (this.latencies.length > 5) {
                    this.latencies.shift();
                }
                this.onPulseOk(res.data, rtt);
            } else {
                this.onPulseFail(res);
            }
        } catch (err) {
            console.warn('Herzschlag-Auswertung fehlgeschlagen', err);
        }
        if (!this.pulseStopped) {
            this.pulseTimer = setTimeout(() => this.pulse(), Math.max(200, PULSE_MS - rtt));
        }
    }

    onPulseFail(res) {
        if (res.status === 401) {
            this.pulseStopped = true;
            this.showMessage('Du bist abgemeldet', 'Deine Sitzung ist abgelaufen. Deine bisherigen Antworten sind gesichert. Melde dich neu an, um weiterzuarbeiten.');
            const a = h('a', { class: 'btn btn-primary', href: url('/login') }, 'Zur Anmeldung');
            this.viewHost.querySelector('.wait-card')?.append(a);
            return;
        }
        this.pulseFails += 1;
        this.updateConnection();
    }

    onPulseOk(d, rtt) {
        this.pulseFails = 0;
        if (typeof d.server_time_ms === 'number') {
            this.offset = d.server_time_ms - (Date.now() - rtt / 2);
        }
        this.updateConnection();
        this.examStatus = d.exam_status ?? this.examStatus;
        this.running = this.examStatus === 'running' || this.examStatus === 'paused';
        this.clockData = d.clock ?? this.clockData;
        this.clockReceived = Date.now();
        this.openRequests = Array.isArray(d.open_requests) ? d.open_requests : [];
        if (this.handRaised !== !!d.hand_raised) {
            this.handRaised = !!d.hand_raised;
            this.view?.setHand(this.handRaised);
        }
        this.handleAnnouncements(Array.isArray(d.announcements) ? d.announcements : [], d.announcement_cursor);
        this.setState(d.state, d.lock_reason ?? null);
        this.updateWaiting();
    }

    updateConnection() {
        const offline = this.pulseFails >= 3 || navigator.onLine === false;
        const state = offline ? 'offline' : this.pulseFails > 0 ? 'unstable' : 'ok';
        const text = { ok: 'verbunden', unstable: 'Verbindung schwach', offline: 'offline' }[state];
        this.view?.setConnection(state, text);
        if (this.view) {
            if (offline) {
                this.view.banner('offline', 'Keine Verbindung zum Server. Du kannst weiterarbeiten: Deine Antworten werden auf diesem Gerät gesichert und später übertragen.', { type: 'warning', dismissible: false });
            } else {
                this.view.clearBanner('offline');
            }
        }
    }

    // ---------- Zustand ----------

    setState(newState, reason) {
        if (!newState) {
            return;
        }
        const prev = this.state;
        this.state = newState;
        this.lockReason = reason;
        if (prev !== newState) {
            this.releaseRequested = false;
            if (newState !== 'active') {
                this.armed = false;
            }
            if (newState === 'submitted' && this.submittedAt === null) {
                this.submittedAt = this.serverNow();
            }
            if (newState === 'submitted') {
                this.autosave?.pause();
            }
        }
        if (newState === 'active') {
            // Auch ohne Zustandswechsel: ein kurzes 409 (Pause/Sperre und sofort wieder frei) darf das Autosave nicht dauerhaft blockieren.
            this.autosave?.resume();
        }
        if (this.localLock) {
            if (newState === 'locked') {
                this.localLock.serverLocked = true;
            } else if (newState === 'active' && this.localLock.serverLocked) {
                this.clearLocalLock();
            }
        }
        if (newState === 'locked' || newState === 'absent') {
            const open = this.openRequests.includes('release');
            if (open) {
                this.releaseRequested = true;
                this.releaseSeenOpen = true;
            } else if (this.releaseRequested && Date.now() - this.releaseRequestedAt > 4000) {
                // Anfrage ist nicht mehr offen, der Zustand aber unverändert: abgelehnt, erneut anfragen möglich.
                this.releaseRequested = false;
                if (this.releaseSeenOpen) {
                    toast('Die Lehrkraft hat deine Anfrage abgelehnt. Du kannst sie erneut stellen.', 'warning');
                }
                this.releaseSeenOpen = false;
            }
        }
        if (CONTENT_STATES.has(newState) && !this.content && this.joined) {
            this.loadContent();
        }
        this.maybeArm();
        this.refreshUi();
    }

    clearLocalLock() {
        this.localLock = null;
        this.armed = false;
        this.monitor?.resetFocusTracking();
    }

    maybeArm() {
        if (this.armed || this.state !== 'active' || this.localLock || !this.content) {
            return;
        }
        if (!this.monitorEnabled) {
            this.armed = true;
            return;
        }
        const fsOk = !this.fullscreenRequired() || isFullscreen();
        if (fsOk && this.sound.attempted) {
            this.armed = true;
        }
    }

    /** Ist die Überwachung gerade scharf? */
    monitoringActive() {
        return this.monitorEnabled && this.state === 'active' && this.armed && !this.localLock && !this.secondTab && !!this.content;
    }

    // ---------- Inhalt ----------

    async loadContent() {
        if (this.loadingContent || this.content) {
            return !!this.content;
        }
        this.loadingContent = true;
        const res = await get(`${this.api}/inhalt`, { timeoutMs: 25000 });
        this.loadingContent = false;
        if (!res.ok) {
            this.contentError = res.error;
            if (!this.preview && (res.status === 409 || res.transient)) {
                // Noch nicht freigegeben oder kurz offline: beim nächsten Herzschlag erneut.
                return false;
            }
            if (!this.preview && !this.contentErrorShown) {
                this.contentErrorShown = true;
                this.showMessage('Die Aufgaben konnten nicht geladen werden', res.error, true);
            }
            return false;
        }
        try {
            await this.buildExam(res.data);
        } catch (err) {
            console.error('Aufbau der Klausur fehlgeschlagen', err);
            this.content = null;
            this.showMessage('Die Klausur konnte nicht angezeigt werden', 'Beim Aufbau der Seite ist ein Fehler aufgetreten. Lade die Seite neu. Deine Antworten sind gesichert.', true);
            return false;
        }
        return true;
    }

    async buildExam(data) {
        this.settings = mergeSettings(data.exam?.settings ?? {}, this.mode);
        this.tasks = data.tasks ?? [];
        this.materials = data.materials ?? [];
        this.operators = data.operators ?? [];
        this.autosave = new Autosave({
            apiBase: this.api,
            storeKey: `${this.boot.exam_id}:${this.userKey}`,
            store: this.store,
            preview: this.preview,
            onStatus: (s, at) => this.view?.setSave(s, at),
            onConflict: () => this.kickPulse(),
            onMessage: (msg, type) => toast(msg, type ?? 'error', 9000),
            onVersions: (versions) => {
                for (const [id, v] of Object.entries(versions ?? {})) {
                    if (this.answers[id]) {
                        this.answers[id].version = v;
                    }
                }
            },
        });
        this.answers = this.preview
            ? Object.fromEntries(Object.entries(data.answers ?? {}).map(([id, a]) => [id, { content: a.content, version: a.version ?? 0, flagged: !!a.flagged }]))
            : await this.autosave.init(data.last_seq ?? 0, data.answers ?? {});

        let runtime;
        try {
            runtime = createRuntime({ examId: this.boot.exam_id, settings: this.settings, assets: this.boot.assets ?? {}, preview: this.preview });
        } catch (err) {
            console.warn('Laufzeit nicht verfügbar', err);
            runtime = { canRun: () => false, run() { throw new Error('Ausführung ist nicht verfügbar.'); } };
        }
        this.env = { examId: this.boot.exam_id, examMode: this.boot.mode, settings: this.settings, runtime };

        this.monitor = new Monitor({
            examId: this.boot.exam_id,
            settings: this.settings,
            enabled: this.monitorEnabled,
            events: this.events ?? { push() {} },
            isActive: () => this.monitoringActive(),
            fullscreenRequired: () => this.fullscreenRequired(),
            onViolation: (v) => this.onViolation(v),
            notify: (msg, type) => toast(msg, type ?? 'warning', 6000),
            onSecondTab: () => this.onSecondTab(),
        });
        this.monitor.start();

        this.view = new ExamView({
            boot: this.boot,
            tasks: this.tasks,
            settings: this.settings,
            answers: this.answers,
            materials: this.materials,
            operators: this.operators,
            env: this.env,
            mode: this.mode,
            preview: this.preview,
            assets: this.boot.assets ?? {},
            callbacks: {
                onChange: (task, content, meta) => {
                    this.autosave.change(task.id, content, meta);
                    if (typeof meta?.getPreviewPng === 'function') {
                        this.schedulePreview(task.id, meta.getPreviewPng);
                    }
                },
                onFlag: (task, flagged, content) => this.autosave.flag(task.id, flagged, content),
                onMounted: (task, content) => {
                    if (content && Array.isArray(content.files)) {
                        this.autosave.snapshots(task.id, content);
                    }
                },
                onHand: () => this.toggleHand(),
                onSubmit: () => this.openSubmitDialog(),
                onCurrent: () => {},
            },
        });
        this.content = data;
        if (this.waiting) {
            this.waiting.destroy();
            this.waiting = null;
        }
        replace(this.viewHost, this.view.el);
        if (this.mode === 'probe' && this.tasks.length === 0) {
            this.tech = new TechCheck({ sound: this.sound, seb: !!this.boot.seb?.detected, getLatency: () => this.avgLatency() });
            replace(this.view.main, this.tech.el);
        } else {
            this.view.restorePosition();
        }
        this.view.setHand(this.handRaised);
        this.view.setAnnouncements(this.announcements);
        this.updateConnection();
        if (this.preview) {
            this.armed = true;
        }
        this.maybeArm();
        this.tick();
        this.refreshUi();
    }

    // ---------- Anzeige-Steuerung ----------

    desiredOverlay() {
        if (this.preview) {
            return null;
        }
        if (this.secondTab) {
            return { key: 'second', build: () => overlays.secondTab({ onRetry: () => location.reload() }) };
        }
        if (this.localLock || this.state === 'locked') {
            const requested = this.releaseRequested;
            const message = this.localLock?.message ?? null;
            const reason = this.state === 'locked' ? this.lockReason : null;
            return {
                key: `locked:${requested}:${message ?? ''}:${reason ?? ''}`,
                build: () => overlays.locked({ message, reason, requested, onRequest: () => this.requestRelease() }),
            };
        }
        switch (this.state) {
            case 'absent':
                return { key: `absent:${this.releaseRequested}`, build: () => overlays.absent({ requested: this.releaseRequested, onRequest: () => this.requestRelease() }) };
            case 'paused':
                return { key: 'paused', build: () => overlays.paused() };
            case 'device_pending':
                return { key: 'device', build: () => overlays.devicePending() };
            case 'submit_requested':
                return { key: 'submit', build: () => overlays.submitRequested({ onWithdraw: () => this.withdrawSubmit() }) };
            case 'submitted':
                return { key: 'submitted', build: () => overlays.submitted({ at: this.submittedAt, seb: !!this.boot.seb?.detected }) };
            case 'active':
                if (this.content && this.monitorEnabled && !this.armed) {
                    const first = !this.everArmed;
                    return { key: `resume:${first}`, build: () => overlays.resume({ first, fullscreen: this.fullscreenRequired(), onClick: () => this.onResumeClick() }) };
                }
                return null;
            default:
                return null;
        }
    }

    refreshUi() {
        if (this.armed) {
            this.everArmed = true;
        }
        const want = this.desiredOverlay();
        const key = want?.key ?? null;
        if (key !== this.currentOverlay?.key) {
            this.currentOverlay?.el.remove();
            this.currentOverlay = null;
            if (want) {
                const built = want.build();
                this.currentOverlay = { key, el: built.el };
                this.overlayHost.append(built.el);
                setTimeout(() => (built.focusEl ?? built.el.querySelector('button, a'))?.focus({ preventScroll: true }), 30);
            } else if (this.view) {
                this.view.focusMain();
            }
        }
        const blocked = key !== null;
        this.viewHost.inert = blocked;
        if (blocked) {
            this.viewHost.setAttribute('aria-hidden', 'true');
        } else {
            this.viewHost.removeAttribute('aria-hidden');
        }
        if (this.view) {
            const wantReadOnly = !this.preview && (blocked || this.state !== 'active');
            if (this.view.readOnly !== wantReadOnly) {
                this.view.setReadOnly(wantReadOnly);
            }
        }
    }

    async onResumeClick() {
        await this.sound.unlock(true);
        if (this.fullscreenRequired()) {
            const ok = await enterFullscreen();
            if (!ok) {
                toast('Der Vollbildmodus konnte nicht aktiviert werden. Erlaube ihn im Browser oder sage der Lehrkraft Bescheid.', 'error', 9000);
            }
        }
        this.maybeArm();
        this.refreshUi();
    }

    // ---------- Verstöße ----------

    onViolation(v) {
        const now = Date.now();
        const lock = !!this.settings.lock_on_violation;
        if (now - this.lastAlarmAt > 2500) {
            this.sound.alarm();
            this.lastAlarmAt = now;
        }
        if (lock) {
            this.localLock = { type: v.type, message: v.message, at: now, serverLocked: false };
            this.armed = false;
            this.releaseRequested = false;
        } else {
            this.view?.banner('violation', v.message, { type: 'warning' });
        }
        this.refreshUi();
    }

    onSecondTab() {
        this.secondTab = true;
        this.sound.alarm();
        this.refreshUi();
    }

    onEventsResult(res) {
        if (!res.ok) {
            return;
        }
        const st = res.data?.state;
        if (this.localLock && this.events.length === 0) {
            if (st === 'locked') {
                this.localLock.serverLocked = true;
                this.lockReason = res.data.lock_reason ?? this.lockReason;
            } else if (st === 'active') {
                // Der Server sperrt nicht (z. B. Einstellung): lokale Sperre aufheben.
                this.clearLocalLock();
                this.maybeArm();
            }
        }
        this.kickPulse();
        this.refreshUi();
    }

    // ---------- Anfragen ----------

    async requestRelease() {
        this.releaseRequested = true;
        this.releaseRequestedAt = Date.now();
        this.releaseSeenOpen = false;
        this.refreshUi();
        const res = await post(`${this.api}/anfrage`, { type: 'release' });
        if (!res.ok) {
            this.releaseRequested = false;
            this.refreshUi();
            toast(res.transient ? 'Keine Verbindung. Versuche es gleich noch einmal.' : res.error, 'error');
        }
    }

    async toggleHand() {
        const raise = !this.handRaised;
        this.handRaised = raise;
        this.view?.setHand(raise);
        const res = await post(`${this.api}/anfrage`, { type: 'hand', cancel: !raise });
        if (!res.ok) {
            this.handRaised = !raise;
            this.view?.setHand(this.handRaised);
            toast(res.transient ? 'Keine Verbindung. Die Hand konnte nicht gemeldet werden.' : res.error, 'error');
        }
    }

    async withdrawSubmit() {
        const res = await post(`${this.api}/anfrage`, { type: 'submit', cancel: true });
        if (res.ok) {
            this.setState(res.data.state ?? 'active', null);
        } else {
            toast(res.transient ? 'Keine Verbindung. Versuche es gleich noch einmal.' : res.error, 'error');
        }
    }

    // ---------- Abgabe ----------

    /**
     * PNG-Vorschau einer Zeichenaufgabe (für Korrektur und PDF) höchstens alle
     * 10 s je Aufgabe senden. Vorher speichern, damit die Antwort existiert.
     */
    schedulePreview(taskId, getPng) {
        if (this.preview) {
            return;
        }
        this.previewFns ??= new Map();
        this.previewTimers ??= new Map();
        this.previewFns.set(taskId, getPng);
        if (this.previewTimers.has(taskId)) {
            return;
        }
        this.previewTimers.set(taskId, setTimeout(() => this.sendPreview(taskId), 10000));
    }

    async sendPreview(taskId) {
        clearTimeout(this.previewTimers?.get(taskId));
        this.previewTimers?.delete(taskId);
        const getPng = this.previewFns?.get(taskId);
        if (!getPng) {
            return;
        }
        this.previewFns.delete(taskId);
        try {
            await this.autosave.flush();
            const png = await getPng();
            if (typeof png === 'string' && png.startsWith('data:image/png;base64,')) {
                await post(`${this.api}/vorschaubild`, { task_id: taskId, png }, { timeoutMs: 20000 });
            }
        } catch {
            // Vorschau ist nur eine Hilfe für die Korrektur; der Inhalt selbst ist gespeichert.
        }
    }

    /** Alle ausstehenden Vorschaubilder sofort senden (vor der Abgabe). */
    async flushPreviews() {
        const ids = [...(this.previewFns?.keys() ?? [])];
        await Promise.all(ids.map((id) => this.sendPreview(id)));
    }

    openSubmitDialog() {
        this.flushPreviews();
        if (!this.view || this.state !== 'active') {
            return;
        }
        const s = this.view.getSummary();
        const list = (title, tasks) => (tasks.length === 0 ? null : h('div', { class: 'submit-list' },
            h('strong', {}, title),
            h('ul', {}, ...tasks.map((t) => h('li', {}, taskLabel(t))))));
        const msg = h('p', { class: 'submit-msg', role: 'status' });
        const cancel = h('button', { type: 'button', class: 'btn' }, 'Zurück zur Klausur');
        const confirm = h('button', { type: 'button', class: 'btn btn-primary' }, 'Jetzt abgeben');
        const dlg = h('dialog', { class: 'modal', 'aria-labelledby': 'submit-title' },
            h('div', { class: 'modal-header' }, h('h3', { id: 'submit-title' }, 'Klausur abgeben?')),
            h('div', { class: 'modal-body' },
                h('p', {}, `Du hast ${s.answered} von ${s.total} Aufgaben bearbeitet.`),
                list('Noch nicht bearbeitet', s.unanswered),
                list('Für später markiert', s.flagged),
                s.unanswered.length === 0 && s.flagged.length === 0 ? h('p', {}, 'Alle Aufgaben sind bearbeitet.') : null,
                h('p', {}, 'Die Lehrkraft bestätigt deine Abgabe. Danach kannst du nichts mehr ändern.'),
                msg),
            h('div', { class: 'modal-footer' }, cancel, confirm));
        let force = false;
        cancel.addEventListener('click', () => {
            dlg.close();
            dlg.remove();
        });
        dlg.addEventListener('close', () => dlg.remove());
        confirm.addEventListener('click', async () => {
            confirm.disabled = cancel.disabled = true;
            msg.textContent = 'Speichere deine Antworten ...';
            const saved = await this.autosave.flush();
            if (!saved && !force && this.autosave.pendingCount > 0) {
                msg.textContent = 'Es konnten nicht alle Änderungen übertragen werden (keine Verbindung?). Du kannst es noch einmal versuchen oder trotzdem abgeben; die Lehrkraft sieht den Stand deines Geräts.';
                force = true;
                confirm.textContent = 'Trotzdem abgeben';
                confirm.disabled = cancel.disabled = false;
                return;
            }
            msg.textContent = 'Sende Abgabe ...';
            const res = await post(`${this.api}/anfrage`, { type: 'submit' });
            if (res.ok) {
                dlg.close();
                dlg.remove();
                this.setState(res.data.state ?? 'submit_requested', null);
            } else {
                msg.textContent = res.transient ? 'Keine Verbindung zum Server. Versuche es gleich noch einmal.' : res.error;
                confirm.disabled = cancel.disabled = false;
            }
        });
        document.body.append(dlg);
        dlg.showModal();
    }

    // ---------- Durchsagen ----------

    handleAnnouncements(list, cursor) {
        if (typeof cursor === 'number') {
            this.annCursor = Math.max(this.annCursor, cursor);
        }
        const fresh = list.filter((a) => !this.annIds.has(a.id));
        if (fresh.length === 0) {
            this.firstAnnouncements = false;
            return;
        }
        for (const a of fresh) {
            this.annIds.add(a.id);
            this.announcements.push(a);
        }
        this.view?.setAnnouncements(this.announcements);
        const newest = fresh[fresh.length - 1];
        const recent = this.serverNow() - (newest.created_at_ms ?? 0) < 120000;
        if (!this.firstAnnouncements || recent) {
            if (this.view) {
                this.view.banner(`ann-${newest.id}`, `Durchsage: ${newest.message}`, { type: 'info' });
            } else {
                toast(`Durchsage: ${newest.message}`, 'info', 15000);
            }
            if (!this.firstAnnouncements) {
                this.sound.announce();
            }
        }
        this.firstAnnouncements = false;
    }

    // ---------- Uhr ----------

    tick() {
        if (!this.view) {
            return;
        }
        const cv = clockView(this.clockData, this.offset, Date.now(), { receivedLocal: this.clockReceived });
        this.view.setClock(cv);
        if (this.autosave) {
            this.view.setSave(this.autosave.status, this.autosave.lastSavedAt);
        }
        if (!this.preview && this.state === 'active' && cv.kind === 'overtime' && !this.timeUpShown) {
            this.timeUpShown = true;
            const marker = `kw-timeup-${this.boot.exam_id}`;
            const seen = ss.get(marker) === '1';
            ss.set(marker, '1');
            if (!seen) {
                this.sound.timeUp();
            }
            this.view.banner('timeup', 'Die Bearbeitungszeit ist abgelaufen. Du kannst weiterschreiben, bis die Lehrkraft die Klausur einsammelt; Änderungen ab jetzt werden markiert.', { type: 'warning' });
        }
    }

    // ---------- Verlassen der Seite ----------

    installUnloadHandlers() {
        window.addEventListener('beforeunload', (e) => {
            if (this.preview || !this.joined || this.state === 'submitted' || this.secondTab) {
                return;
            }
            this.autosave?.flushKeepalive();
            if ((this.monitorEnabled && this.content) || (this.autosave?.pendingCount ?? 0) > 0) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        window.addEventListener('pagehide', () => {
            if (this.preview || !this.joined) {
                return;
            }
            reloadMarker.mark(this.boot.exam_id);
            this.autosave?.persistNow();
        });
        window.addEventListener('online', () => {
            this.onlineSince = Date.now();
            this.kickPulse();
            this.autosave?.flush();
        });
        window.addEventListener('offline', () => this.updateConnection());
    }
}
