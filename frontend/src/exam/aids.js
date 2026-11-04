/**
 * Hilfsmittel-Seitenleiste: Java-API, Python-Doku, SQL-Spickzettel (statische
 * HTML-Fragmente aus /assets/aids/), Operatorenliste, Materialien mit is_aid
 * und der Taschenrechner.
 */
import { h, setTrustedHtml, icon } from '../lib/dom.js';
import { url } from '../lib/api.js';
import { createCalculator } from './calculator-ui.js';

const STATIC = [
    { key: 'java_api', label: 'Java-API', file: 'java-api.html' },
    { key: 'python_doc', label: 'Python', file: 'python-doc.html' },
    { key: 'sql_cheatsheet', label: 'SQL', file: 'sql-cheatsheet.html' },
];

export function availableAids(settings, materials, operators) {
    const aids = settings?.aids ?? {};
    const list = [];
    for (const s of STATIC) {
        if (aids[s.key]) {
            list.push({ id: s.key, label: s.label, kind: 'static', file: s.file });
        }
    }
    if (aids.operators && (operators ?? []).length > 0) {
        list.push({ id: 'operators', label: 'Operatoren', kind: 'operators' });
    }
    for (const m of materials ?? []) {
        if (m.is_aid) {
            list.push({ id: `material-${m.id}`, label: m.name, kind: 'material', material: m });
        }
    }
    if (aids.calculator) {
        list.push({ id: 'calculator', label: 'Taschenrechner', kind: 'calculator' });
    }
    return list;
}

export class AidsPanel {
    /** @param {{settings: object, materials: object[], operators: object[], assets: {aids?: string}}} o */
    constructor(o) {
        this.o = o;
        this.items = availableAids(o.settings, o.materials, o.operators);
        this.cache = new Map();
        this.tabs = new Map();
        this.current = null;
        this.calculator = null;
        this.open = false;
        this.body = h('div', { class: 'aids-body' });
        this.tablist = h('div', { class: 'aids-tabs', role: 'tablist', 'aria-label': 'Hilfsmittel' });
        this.closeBtn = h('button', { type: 'button', class: 'modal-close', 'aria-label': 'Hilfsmittel schließen' }, icon('x'));
        this.closeBtn.addEventListener('click', () => this.toggle(false));
        this.el = h('aside', { class: 'aids-panel', hidden: true, 'aria-label': 'Hilfsmittel' },
            h('div', { class: 'aids-head' }, h('strong', {}, 'Hilfsmittel'), this.closeBtn),
            this.tablist, this.body);
        this.el.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.toggle(false);
            }
        });
        this.items.forEach((item, i) => {
            const tab = h('button', {
                type: 'button', class: 'aids-tab', role: 'tab', id: `aid-tab-${item.id}`,
                'aria-selected': 'false', tabindex: i === 0 ? '0' : '-1',
            }, item.label);
            tab.addEventListener('click', () => this.select(item.id));
            tab.addEventListener('keydown', (e) => this.tabKey(e, i));
            this.tabs.set(item.id, tab);
            this.tablist.append(tab);
        });
    }

    get hasAids() {
        return this.items.length > 0;
    }

    tabKey(e, i) {
        let next = null;
        if (e.key === 'ArrowRight') {
            next = (i + 1) % this.items.length;
        } else if (e.key === 'ArrowLeft') {
            next = (i - 1 + this.items.length) % this.items.length;
        }
        if (next !== null) {
            e.preventDefault();
            this.select(this.items[next].id);
            this.tabs.get(this.items[next].id).focus();
        }
    }

    toggle(open = !this.open) {
        this.open = open;
        this.el.hidden = !open;
        if (open) {
            if (!this.current && this.items.length > 0) {
                this.select(this.items[0].id);
            }
        }
        this.o.onToggle?.(open);
    }

    select(id) {
        const item = this.items.find((i) => i.id === id);
        if (!item) {
            return;
        }
        this.current = id;
        for (const [key, tab] of this.tabs) {
            tab.setAttribute('aria-selected', key === id ? 'true' : 'false');
            tab.tabIndex = key === id ? 0 : -1;
        }
        this.body.replaceChildren();
        this.body.setAttribute('role', 'tabpanel');
        this.body.setAttribute('aria-labelledby', `aid-tab-${id}`);
        switch (item.kind) {
            case 'static': this.showStatic(item); break;
            case 'operators': this.showOperators(); break;
            case 'material': this.showMaterial(item.material); break;
            case 'calculator': this.showCalculator(); break;
            default: break;
        }
    }

    async showStatic(item) {
        const holder = h('div', { class: 'aid-doc' }, h('p', { class: 'w-hint' }, 'Lade ...'));
        this.body.append(holder);
        try {
            let html = this.cache.get(item.file);
            if (html === undefined) {
                const base = this.o.assets?.aids ?? '/assets/aids/';
                const res = await fetch(url(base + item.file), { credentials: 'same-origin' });
                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}`);
                }
                html = await res.text();
                this.cache.set(item.file, html);
            }
            if (this.current === item.id) {
                setTrustedHtml(holder, html); // statisches, vertrauenswürdiges Fragment
            }
        } catch {
            holder.replaceChildren(
                h('p', { class: 'alert alert-error' }, 'Das Hilfsmittel konnte nicht geladen werden.'),
                (() => {
                    const b = h('button', { type: 'button', class: 'btn btn-sm' }, 'Erneut versuchen');
                    b.addEventListener('click', () => this.select(item.id));
                    return b;
                })(),
            );
        }
    }

    showOperators() {
        const dl = h('dl', { class: 'aid-operators' });
        for (const op of this.o.operators ?? []) {
            dl.append(
                h('dt', {}, op.name, op.afb ? h('span', { class: 'badge badge-primary' }, `AFB ${op.afb}`) : null),
                h('dd', {}, op.description ?? ''),
            );
        }
        this.body.append(dl);
    }

    showMaterial(m) {
        const src = url(m.url);
        if (String(m.mime ?? '').startsWith('image/')) {
            this.body.append(h('img', { class: 'aid-image', src, alt: m.name }));
        } else {
            this.body.append(h('iframe', { class: 'aid-frame', src, title: m.name }));
        }
    }

    showCalculator() {
        if (!this.calculator) {
            this.calculator = createCalculator();
        }
        this.body.append(this.calculator.el);
        this.calculator.focus();
    }
}
