/**
 * Warteraum (und Probe-Modus mit Technik-Check).
 * Die Ansicht ist zustandslos: die App ruft refresh(info) auf.
 */
import { h, icon, replace } from '../lib/dom.js';
import { TechCheck } from './techcheck.js';

const MODE_TEXT = { exam: 'Klausur', practice: 'Übung', probe: 'Technik-Check' };

export class WaitingView {
    /**
     * @param {{boot: object, sound: any, getLatency: () => number|null,
     *   onJoin: (code: string|null) => void, onReady: (details: object|null) => void,
     *   share?: {status: string, onRequest: () => void, onSkip: () => void}|null}} o
     *   share: nur gesetzt, wenn die Bildschirmfreigabe verlangt wird (Live-Wand).
     */
    constructor(o) {
        this.o = o;
        const boot = o.boot;
        this.tech = boot.mode === 'probe' ? new TechCheck({ sound: o.sound, seb: !!boot.seb?.detected, getLatency: o.getLatency }) : null;

        this.status = h('p', { class: 'wait-status', role: 'status', 'aria-live': 'polite' }, 'Verbinde ...');
        this.error = h('div', { class: 'alert alert-error', role: 'alert', hidden: true });

        this.codeInput = h('input', {
            type: 'text', id: 'access-code', class: 'input', autocomplete: 'off', autocapitalize: 'characters',
            spellcheck: 'false', maxlength: 32, required: true, 'aria-describedby': 'access-code-hint',
        });
        this.codeBtn = h('button', { type: 'submit', class: 'btn btn-primary' }, 'Beitreten');
        this.codeForm = h('form', { class: 'wait-code', hidden: true },
            h('div', { class: 'field' },
                h('label', { for: 'access-code' }, 'Klausurcode'),
                this.codeInput,
                h('div', { id: 'access-code-hint', class: 'hint' }, 'Den Code nennt dir die Lehrkraft im Raum.')),
            this.codeBtn);
        this.codeForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const v = this.codeInput.value.trim();
            if (v !== '') {
                o.onJoin(v);
            }
        });

        this.retryBtn = h('button', { type: 'button', class: 'btn', hidden: true }, 'Erneut versuchen');
        this.retryBtn.addEventListener('click', () => o.onJoin(this.codeInput.value.trim() || null));

        this.share = o.share ?? null;
        this.shareStatus = this.share?.status ?? 'idle';
        this.phase = 'joining';
        this.shareBox = this.share ? this.buildShare() : null;

        this.readyBtn = h('button', { type: 'button', class: 'btn btn-primary btn-lg wait-ready', disabled: true }, 'Bereit');
        this.readyBtn.addEventListener('click', () => o.onReady(this.tech ? this.tech.details() : null));

        const mode = boot.mode ?? 'exam';
        const rules = [];
        if (mode === 'practice') {
            rules.push('Das ist eine Übung. Es gibt keine Sperre, und die Ergebnisse zählen nicht.');
        } else {
            rules.push('Die Klausur läuft im Vollbild. Verlasse den Vollbildmodus nicht.');
            rules.push('Wechsle nicht zu anderen Fenstern oder Tabs und öffne die Seite nur einmal.');
            rules.push('Schalte den Ton deines Geräts an. Bei einem Verstoß ertönt ein Signal, und die Lehrkraft wird benachrichtigt.');
            rules.push('Einfügen ist nur für Text erlaubt, den du in der Klausur selbst kopiert hast.');
        }
        if (boot.seb?.required) {
            rules.push('Diese Klausur ist nur im Safe Exam Browser möglich.');
        }

        this.el = h('main', { class: 'wait-view' },
            h('div', { class: 'wait-card card' },
                h('p', { class: 'wait-kind' }, MODE_TEXT[mode] ?? 'Klausur'),
                h('h1', { class: 'wait-title' }, boot.title ?? 'Klausur'),
                h('p', { class: 'wait-user' }, boot.user?.name ? `Angemeldet als ${boot.user.name}` : null),
                this.status,
                this.error,
                this.codeForm,
                this.retryBtn,
                h('h2', { class: 'wait-rules-title' }, 'Bitte beachte'),
                h('ul', { class: 'wait-rules' }, ...rules.map((r) => h('li', {}, r))),
                this.shareBox,
                this.tech ? this.tech.el : null,
                h('div', { class: 'wait-actions' }, this.readyBtn)));
        this.renderShare();
    }

    /** Schritt "Bildschirm freigeben" (nur wenn die Klausur es verlangt, nie im Safe Exam Browser). */
    buildShare() {
        this.shareState = h('p', { class: 'wait-share-state', role: 'status', 'aria-live': 'polite' });
        this.shareActions = h('div', { class: 'wait-share-actions' });
        return h('section', { class: 'wait-share', 'aria-labelledby': 'wait-share-title' },
            h('h2', { id: 'wait-share-title', class: 'wait-rules-title' }, 'Bildschirm freigeben'),
            h('p', {}, 'Für diese Klausur zeigt dein Bildschirm der Lehrkraft, ob alles in Ordnung ist. ',
                'Gib bitte den ganzen Bildschirm frei (nicht nur ein Fenster oder einen Tab).'),
            h('p', { class: 'hint' }, 'Datenschutz: Es werden nur einzelne, verkleinerte Standbilder übertragen, kein Ton und keine Aufzeichnung. ',
                'Die Bilder werden nicht gespeichert, nur das jeweils letzte liegt kurz im Arbeitsspeicher des Servers und wird nach der Klausur gelöscht. ',
                'Solange übertragen wird, siehst du oben die Anzeige „Bildschirm wird übertragen“.'),
            this.shareState,
            this.shareActions);
    }

    /** Ist der Schritt erledigt (freigegeben oder bewusst ohne Freigabe fortgefahren)? */
    shareDone() {
        return !this.share || this.shareStatus === 'active' || this.shareStatus === 'skipped';
    }

    setShare(status) {
        this.shareStatus = status;
        this.renderShare();
        this.updateReady();
    }

    renderShare() {
        if (!this.share) {
            return;
        }
        const st = this.shareStatus;
        const joined = this.phase === 'joined';
        const btn = (label, handler, { primary = false, disabled = false, ic = null } = {}) => {
            const b = h('button', { type: 'button', class: primary ? 'btn btn-primary' : 'btn', disabled }, ic ? icon(ic) : null, label);
            b.addEventListener('click', handler);
            return b;
        };
        const request = () => this.share.onRequest();
        const skip = () => this.share.onSkip();
        const text = {
            idle: 'Noch nicht freigegeben. Klicke auf „Bildschirm freigeben“ und wähle „Gesamter Bildschirm“.',
            requesting: 'Warte auf deine Auswahl im Dialog des Browsers ...',
            active: 'Dein Bildschirm wird freigegeben. Du kannst jetzt auf „Bereit“ klicken.',
            wrong: 'Bitte den ganzen Bildschirm freigeben, nicht nur ein Fenster oder einen Tab.',
            denied: 'Die Freigabe wurde abgelehnt oder abgebrochen. Die Lehrkraft wird benachrichtigt. Du kannst es noch einmal versuchen oder ohne Freigabe fortfahren.',
            stopped: 'Die Freigabe wurde beendet. Gib den Bildschirm erneut frei oder fahre ohne Freigabe fort. Die Lehrkraft wird benachrichtigt.',
            skipped: 'Du fährst ohne Bildschirmfreigabe fort. Die Lehrkraft wurde benachrichtigt.',
            unsupported: 'Dieser Browser kann den Bildschirm nicht freigeben. Du kannst ohne Freigabe fortfahren; die Lehrkraft wird benachrichtigt.',
        }[st] ?? '';
        this.shareState.textContent = text;
        this.shareState.dataset.state = st;
        const actions = [];
        if (st === 'idle') {
            actions.push(btn('Bildschirm freigeben', request, { primary: true, disabled: !joined, ic: 'monitor' }));
        } else if (st === 'requesting') {
            actions.push(btn('Einen Moment ...', request, { primary: true, disabled: true, ic: 'hourglass' }));
        } else if (st === 'active') {
            actions.push(h('span', { class: 'badge badge-success' }, icon('check'), 'Bildschirm wird übertragen'));
        } else if (st === 'skipped') {
            actions.push(btn('Doch freigeben', request, { ic: 'monitor' }));
        } else if (st === 'unsupported') {
            actions.push(btn('Ohne Freigabe fortfahren', skip, { primary: true }));
        } else {
            actions.push(btn(st === 'stopped' ? 'Erneut freigeben' : 'Erneut versuchen', request, { primary: true, ic: 'refresh-cw' }));
            actions.push(btn('Ohne Freigabe fortfahren', skip));
        }
        replace(this.shareActions, ...actions);
    }

    updateReady() {
        const info = this.lastInfo;
        const busy = !!info?.readyBusy;
        this.readyBtn.disabled = this.phase !== 'joined' || !!info?.ready || busy || !this.shareDone();
        this.readyBtn.title = this.phase === 'joined' && !this.shareDone() && !info?.ready ? 'Gib zuerst den Bildschirm frei oder fahre ohne Freigabe fort.' : '';
    }

    /**
     * @param {{phase: 'code'|'joining'|'joined'|'error'|'retry', error?: string, examStatus?: string,
     *   ready?: boolean, readyBusy?: boolean, running?: boolean}} info
     */
    refresh(info) {
        const { phase } = info;
        const phaseChanged = this.phase !== phase;
        this.phase = phase;
        this.lastInfo = info;
        this.codeForm.hidden = phase !== 'code';
        this.retryBtn.hidden = phase !== 'error';
        this.error.hidden = !info.error;
        this.error.textContent = info.error ?? '';
        this.codeBtn.disabled = false;

        let text = 'Verbinde ...';
        if (phase === 'code') {
            text = 'Gib den Klausurcode ein, um beizutreten.';
        } else if (phase === 'joining') {
            text = 'Trete bei ...';
        } else if (phase === 'retry') {
            text = 'Der Warteraum ist noch nicht geöffnet. Diese Seite versucht es automatisch erneut.';
        } else if (phase === 'error') {
            text = 'Beitritt nicht möglich.';
        } else if (phase === 'joined') {
            text = info.running
                ? 'Die Klausur startet gleich ...'
                : (info.ready ? 'Du bist bereit. Warte auf den Start durch die Lehrkraft.' : 'Warte auf den Start durch die Lehrkraft.');
        }
        this.status.textContent = text;
        this.updateReady();
        if (phaseChanged) {
            this.renderShare();
        }
        replace(this.readyBtn, ...(info.ready ? [icon('check'), 'Bereit'] : [info.readyBusy ? 'Einen Moment ...' : 'Bereit']));
        if (phase === 'code' && document.activeElement === document.body) {
            this.codeInput.focus();
        }
    }

    destroy() {
        this.tech?.destroy();
        this.el.remove();
    }
}
