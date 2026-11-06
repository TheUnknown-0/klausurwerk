/**
 * Eingebauter Browser der Schüleransicht: freigegebene Webseiten einer Aufgabe, ohne den Tab zu
 * verlassen. Default deny: Der Server (WebProxyController) prüft jede Adresse gegen die Freigaben
 * der Aufgabe, gesperrte Links zeigen einen Hinweis und erscheinen im Pult.
 *
 * Das Fenster ist abgeschottet: sandbox ohne allow-scripts und ohne allow-same-origin. Fremde
 * Seiten laufen ohne JavaScript und kommen nicht an Sitzung, Cookies oder die Klausurseite.
 */
import { h, icon } from '../lib/dom.js';
import { post } from '../lib/api.js';

/** Startadresse im Proxy für eine freigegebene Seite. */
export function proxyUrl(base, siteUrl) {
    return `${base}?u=${encodeURIComponent(siteUrl)}`;
}

export class WebPanel {
    /** @param {{api: string, onToggle?: (open: boolean) => void}} o */
    constructor(o) {
        this.o = o;
        this.open = false;
        this.taskId = null;
        this.base = null;
        this.sites = [];
        this.title = h('strong', {}, 'Webseiten');
        this.closeBtn = h('button', { type: 'button', class: 'modal-close', 'aria-label': 'Webseiten schließen' }, icon('x'));
        this.closeBtn.addEventListener('click', () => this.close());
        this.siteBar = h('div', { class: 'web-sites', role: 'toolbar', 'aria-label': 'Freigegebene Webseiten' });
        this.frame = h('iframe', {
            class: 'web-frame',
            title: 'Freigegebene Webseite',
            // Kein allow-scripts, kein allow-same-origin, keine Pop-ups: nur Lesen und Suchformulare.
            sandbox: 'allow-forms',
            referrerpolicy: 'no-referrer',
            loading: 'eager',
        });
        this.status = h('div', { class: 'web-status', role: 'status' }, '');
        this.el = h('aside', { class: 'web-panel', hidden: true, 'aria-label': 'Webseiten' },
            h('div', { class: 'aids-head' }, this.title, this.closeBtn),
            this.siteBar,
            this.status,
            this.frame,
            h('p', { class: 'web-hint' }, 'Nur freigegebene Seiten. Andere Links sind gesperrt und werden der Lehrkraft gemeldet.'));
        this.el.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.close();
            }
        });
    }

    /** Öffnet den Browser für eine Aufgabe (oder schließt ihn, wenn er für sie schon offen ist). */
    async toggleFor(task) {
        if (this.open && this.taskId === task.id) {
            this.close();
            return;
        }
        await this.openFor(task);
    }

    async openFor(task) {
        this.taskId = task.id;
        this.title.textContent = `Webseiten zu Aufgabe ${task.number ?? ''}`.trim();
        this.siteBar.replaceChildren();
        this.status.textContent = 'Öffne den Klausur-Browser ...';
        this.status.hidden = false;
        this.frame.hidden = true;
        this.show(true);
        const res = await post(`${this.o.api}/webseiten`, { task_id: task.id }, { timeoutMs: 15000 });
        if (this.taskId !== task.id) {
            return; // inzwischen eine andere Aufgabe
        }
        if (!res.ok) {
            this.status.textContent = res.error ?? 'Der Klausur-Browser ist gerade nicht verfügbar.';
            return;
        }
        this.base = res.data.base;
        this.sites = res.data.sites ?? [];
        this.sites.forEach((site, i) => {
            const b = h('button', { type: 'button', class: 'btn btn-sm web-site', title: site.url, 'aria-pressed': 'false' }, site.label || site.url);
            b.addEventListener('click', () => this.load(i));
            this.siteBar.append(b);
        });
        this.load(0);
    }

    load(index) {
        const site = this.sites[index];
        if (!site || !this.base) {
            return;
        }
        [...this.siteBar.children].forEach((b, i) => b.setAttribute('aria-pressed', i === index ? 'true' : 'false'));
        this.status.hidden = true;
        this.frame.hidden = false;
        this.frame.src = proxyUrl(this.base, site.url);
    }

    /** Aufgabe gewechselt: Browser folgt, wenn die neue Aufgabe Seiten hat, sonst schließt er. */
    taskChanged(task) {
        if (!this.open || !task || task.id === this.taskId) {
            return;
        }
        if ((task.web_sites ?? []).length > 0) {
            this.openFor(task);
        } else {
            this.close();
        }
    }

    show(open) {
        this.open = open;
        this.el.hidden = !open;
        this.o.onToggle?.(open);
    }

    close() {
        this.taskId = null;
        this.frame.removeAttribute('src');
        this.show(false);
    }
}
