/**
 * Technik-Check für den Probe-Modus: Vollbild, Ton, Verbindung, SEB, Bildschirm.
 */
import { h } from '../lib/dom.js';
import { enterFullscreen, exitFullscreen, isFullscreen, fullscreenSupported } from './fullscreen.js';

export class TechCheck {
    /** @param {{sound: any, seb: boolean, getLatency: () => number|null}} o */
    constructor(o) {
        this.o = o;
        this.result = { fullscreen: null, sound: null };
        this.rows = {};
        this.el = h('section', { class: 'tech-check card', 'aria-labelledby': 'tech-title' },
            h('h2', { id: 'tech-title' }, 'Technik-Check'),
            h('p', { class: 'w-hint' }, 'Prüfe, ob dein Gerät für die Klausur bereit ist. Die Lehrkraft sieht das Ergebnis.'));
        this.list = h('ul', { class: 'tech-list' });
        this.el.append(this.list);
        this.buildRows();
        this.refresh();
        this.timer = setInterval(() => this.refresh(), 2000);
    }

    row(key, label) {
        const status = h('span', { class: 'badge' }, '...');
        const detail = h('span', { class: 'tech-detail' });
        const actions = h('span', { class: 'tech-actions' });
        const li = h('li', { class: 'tech-row' }, h('span', { class: 'tech-label' }, label), status, detail, actions);
        this.list.append(li);
        this.rows[key] = { status, detail, actions };
        return this.rows[key];
    }

    buildRows() {
        const fs = this.row('fullscreen', 'Vollbild');
        const fsBtn = h('button', { type: 'button', class: 'btn btn-sm' }, 'Vollbild testen');
        fsBtn.addEventListener('click', async () => {
            if (!fullscreenSupported()) {
                this.result.fullscreen = false;
                this.refresh();
                return;
            }
            const was = isFullscreen();
            const ok = await enterFullscreen();
            this.result.fullscreen = ok;
            this.refresh();
            if (ok && !was) {
                setTimeout(() => {
                    exitFullscreen();
                }, 1500);
            }
        });
        fs.actions.append(fsBtn);

        const snd = this.row('sound', 'Ton');
        const play = h('button', { type: 'button', class: 'btn btn-sm' }, 'Ton testen');
        const heard = h('button', { type: 'button', class: 'btn btn-sm', hidden: true }, 'Ich habe es gehört');
        const notHeard = h('button', { type: 'button', class: 'btn btn-sm', hidden: true }, 'Nichts gehört');
        play.addEventListener('click', async () => {
            await this.o.sound.unlock(false);
            this.o.sound.alarm();
            heard.hidden = false;
            notHeard.hidden = false;
        });
        heard.addEventListener('click', () => {
            this.result.sound = true;
            heard.hidden = notHeard.hidden = true;
            this.refresh();
        });
        notHeard.addEventListener('click', () => {
            this.result.sound = false;
            heard.hidden = notHeard.hidden = true;
            this.refresh();
        });
        snd.actions.append(play, heard, notHeard);

        this.row('connection', 'Verbindung');
        this.row('seb', 'Safe Exam Browser');
        this.row('screen', 'Bildschirm');
    }

    setBadge(row, kind, text) {
        row.status.className = `badge badge-${kind}`;
        row.status.textContent = text;
    }

    refresh() {
        const r = this.rows;
        if (this.result.fullscreen === null) {
            this.setBadge(r.fullscreen, 'info', fullscreenSupported() ? 'ungeprüft' : 'nicht möglich');
        } else {
            this.setBadge(r.fullscreen, this.result.fullscreen ? 'success' : 'danger', this.result.fullscreen ? 'funktioniert' : 'fehlgeschlagen');
        }
        r.fullscreen.detail.textContent = isFullscreen() ? 'Gerade im Vollbild' : '';
        if (this.result.sound === null) {
            this.setBadge(r.sound, 'info', 'ungeprüft');
        } else {
            this.setBadge(r.sound, this.result.sound ? 'success' : 'danger', this.result.sound ? 'gehört' : 'nicht gehört');
        }
        const latency = this.o.getLatency();
        if (latency === null) {
            this.setBadge(r.connection, 'info', 'misst ...');
            r.connection.detail.textContent = '';
        } else {
            this.setBadge(r.connection, latency < 400 ? 'success' : 'warning', latency < 400 ? 'gut' : 'langsam');
            r.connection.detail.textContent = `${latency} ms`;
        }
        this.setBadge(r.seb, 'info', this.o.seb ? 'erkannt' : 'nicht erkannt');
        r.screen.detail.textContent = this.screenText();
        // Unter 1024 x 600 passen Aufgabe und Editor nicht mehr nebeneinander.
        const small = window.innerWidth < 1024 || window.innerHeight < 600;
        this.setBadge(r.screen, small ? 'warning' : 'success', small ? 'klein' : 'ok');
    }

    screenText() {
        const s = window.screen;
        return `${s.width} x ${s.height}, Fenster ${window.innerWidth} x ${window.innerHeight}, Faktor ${window.devicePixelRatio || 1}`;
    }

    /** Ergebnis für das Ereignis "ready". */
    details() {
        return {
            fullscreen_ok: this.result.fullscreen,
            sound_ok: this.result.sound,
            latency_ms: this.o.getLatency(),
            seb: !!this.o.seb,
            screen: { w: window.screen.width, h: window.screen.height },
            window: { w: window.innerWidth, h: window.innerHeight },
            dpr: window.devicePixelRatio || 1,
        };
    }

    destroy() {
        clearInterval(this.timer);
        this.el.remove();
    }
}
