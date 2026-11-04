/**
 * Warteraum (und Probe-Modus mit Technik-Check).
 * Die Ansicht ist zustandslos: die App ruft refresh(info) auf.
 */
import { h } from '../lib/dom.js';
import { TechCheck } from './techcheck.js';

const MODE_TEXT = { exam: 'Klausur', practice: 'Übung', probe: 'Technik-Check' };

export class WaitingView {
    /**
     * @param {{boot: object, sound: any, getLatency: () => number|null,
     *   onJoin: (code: string|null) => void, onReady: (details: object|null) => void}} o
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
                this.tech ? this.tech.el : null,
                h('div', { class: 'wait-actions' }, this.readyBtn)));
    }

    /**
     * @param {{phase: 'code'|'joining'|'joined'|'error'|'retry', error?: string, examStatus?: string,
     *   ready?: boolean, readyBusy?: boolean, running?: boolean}} info
     */
    refresh(info) {
        const { phase } = info;
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
        this.readyBtn.disabled = phase !== 'joined' || !!info.ready || !!info.readyBusy;
        this.readyBtn.textContent = info.ready ? 'Bereit ✓' : (info.readyBusy ? 'Einen Moment ...' : 'Bereit');
        if (phase === 'code' && document.activeElement === document.body) {
            this.codeInput.focus();
        }
    }

    destroy() {
        this.tech?.destroy();
        this.el.remove();
    }
}
