/**
 * Textmarker im Aufgabentext: Schüler:innen markieren Stellen im Ausgangstext wie auf Papier.
 * Text markieren, dann im kleinen Menü "Gelb" oder "Grün"; ein Klick in eine Markierung bietet
 * "Entfernen" an.
 *
 * Darstellung über die CSS Custom Highlight API (ändert das DOM nicht, Formeln und Zeilennummern
 * bleiben unberührt). Gespeichert wird nur im Browser (localStorage je Klausur und Person), denn
 * Markierungen sind Arbeitshilfe, keine Antwort: Sie gehen nicht an den Server, nicht in die
 * Korrektur und bei einem Gerätewechsel nicht mit. Ohne die API (alte Browser) bleibt die
 * Funktion einfach aus.
 */
import { h } from '../lib/dom.js';

const COLORS = [
    { key: 1, label: 'Gelb' },
    { key: 2, label: 'Grün' },
];

export const highlightSupported = () => typeof CSS !== 'undefined' && 'highlights' in CSS && typeof Highlight === 'function';

/**
 * Fügt eine Markierung hinzu: Überschneidungen gleicher Farbe werden zusammengelegt, andere Farben
 * an der Stelle überschrieben. Marken: [from, to, color], sortiert.
 */
export function addMark(marks, from, to, color) {
    if (!(to > from)) {
        return marks.slice();
    }
    const out = [];
    let start = from;
    let end = to;
    for (const [a, b, c] of marks) {
        if (b < from || a > to) {
            out.push([a, b, c]);
            continue;
        }
        if (c === color) {
            start = Math.min(start, a);
            end = Math.max(end, b);
            continue;
        }
        // andere Farbe: nur die Teile außerhalb behalten
        if (a < from) {
            out.push([a, from, c]);
        }
        if (b > to) {
            out.push([to, b, c]);
        }
    }
    out.push([start, end, color]);
    return out.sort((x, y) => x[0] - y[0] || x[1] - y[1]);
}

/** Entfernt alles, was den Bereich [from, to] berührt (bei from === to: die Markierung an der Stelle). */
export function removeMarks(marks, from, to) {
    return marks.filter(([a, b]) => (from === to ? !(a <= from && from <= b) : (b <= from || a >= to)));
}

/** Textposition (Zeichen ab Anfang von root) eines DOM-Punkts. */
function offsetOf(root, node, offset) {
    const range = document.createRange();
    range.selectNodeContents(root);
    range.setEnd(node, offset);
    return range.toString().length;
}

/** Bereich aus Textpositionen. */
function rangeFor(root, from, to) {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let pos = 0;
    let startNode = null;
    let startOff = 0;
    let node = walker.nextNode();
    while (node) {
        const len = node.nodeValue.length;
        if (!startNode && from <= pos + len) {
            startNode = node;
            startOff = from - pos;
        }
        if (startNode && to <= pos + len) {
            const r = document.createRange();
            r.setStart(startNode, startOff);
            r.setEnd(node, to - pos);
            return r;
        }
        pos += len;
        node = walker.nextNode();
    }
    return null;
}

export class TextHighlighter {
    /** @param {{storageKey: string}} o */
    constructor(o) {
        this.key = o.storageKey;
        this.bodies = new Map(); // taskId -> element
        this.marks = this.load();
        this.enabled = highlightSupported();
        this.menu = null;
        this.pending = null; // { taskId, from, to }
        if (!this.enabled) {
            return;
        }
        this.highlights = new Map(COLORS.map((c) => {
            const hl = new Highlight();
            CSS.highlights.set(`kw-mark-${c.key}`, hl);
            return [c.key, hl];
        }));
        this.buildMenu();
        document.addEventListener('mouseup', () => setTimeout(() => this.onSelection(), 0));
        document.addEventListener('keyup', (ev) => {
            if (ev.shiftKey || ev.key === 'Escape') {
                setTimeout(() => this.onSelection(ev.key === 'Escape'), 0);
            }
        });
        window.addEventListener('scroll', () => this.hideMenu(), true);
    }

    load() {
        try {
            const raw = window.localStorage.getItem(this.key);
            const data = raw ? JSON.parse(raw) : {};
            return data && typeof data === 'object' ? data : {};
        } catch {
            return {};
        }
    }

    save() {
        try {
            window.localStorage.setItem(this.key, JSON.stringify(this.marks));
        } catch {
            // Speicher voll oder gesperrt: Markierungen gelten dann nur bis zum Neuladen.
        }
    }

    /** Aufgabentext anmelden (nach dem Setzen des HTML und der Formeln). */
    register(taskId, body) {
        if (!this.enabled) {
            return;
        }
        this.bodies.set(String(taskId), body);
        body.classList.add('can-highlight');
        this.paint();
    }

    paint() {
        if (!this.enabled) {
            return;
        }
        for (const hl of this.highlights.values()) {
            hl.clear();
        }
        for (const [taskId, body] of this.bodies) {
            for (const [from, to, color] of this.marks[taskId] ?? []) {
                const r = rangeFor(body, from, to);
                if (r) {
                    this.highlights.get(color)?.add(r);
                }
            }
        }
    }

    buildMenu() {
        const keep = (ev) => ev.preventDefault();
        this.colorBtns = COLORS.map((c) => {
            const b = h('button', { type: 'button', class: `hl-btn hl-c${c.key}`, title: `${c.label} markieren` }, c.label);
            b.addEventListener('mousedown', keep);
            b.addEventListener('click', () => this.apply(c.key));
            return b;
        });
        this.removeBtn = h('button', { type: 'button', class: 'hl-btn hl-remove', title: 'Markierung entfernen' }, 'Entfernen');
        this.removeBtn.addEventListener('mousedown', keep);
        this.removeBtn.addEventListener('click', () => this.remove());
        this.menu = h('div', { class: 'hl-menu', role: 'toolbar', 'aria-label': 'Textmarker', hidden: true }, ...this.colorBtns, this.removeBtn);
        document.body.append(this.menu);
    }

    /** Welche angemeldete Aufgabe enthält den Knoten? */
    bodyOf(node) {
        for (const [taskId, body] of this.bodies) {
            if (body.contains(node)) {
                return [taskId, body];
            }
        }
        return [null, null];
    }

    onSelection(close = false) {
        if (close) {
            this.hideMenu();
            return;
        }
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) {
            this.hideMenu();
            return;
        }
        const range = sel.getRangeAt(0);
        const [taskId, body] = this.bodyOf(range.startContainer);
        if (!taskId || !body.contains(range.endContainer)) {
            this.hideMenu();
            return;
        }
        const from = offsetOf(body, range.startContainer, range.startOffset);
        const to = offsetOf(body, range.endContainer, range.endOffset);
        const list = this.marks[taskId] ?? [];
        const inside = list.some(([a, b]) => a <= from && from <= b && (from !== to || (a < from && from < b)));
        if (from === to && !inside) {
            this.hideMenu();
            return;
        }
        this.pending = { taskId, from, to };
        for (const b of this.colorBtns) {
            b.hidden = from === to;
        }
        this.removeBtn.hidden = !inside && !list.some(([a, b]) => b > from && a < to);
        const rect = range.getBoundingClientRect();
        this.menu.hidden = false;
        const top = Math.max(8, rect.top - this.menu.offsetHeight - 6);
        const left = Math.min(window.innerWidth - this.menu.offsetWidth - 8, Math.max(8, rect.left));
        this.menu.style.top = `${top}px`;
        this.menu.style.left = `${left}px`;
    }

    hideMenu() {
        if (this.menu) {
            this.menu.hidden = true;
        }
        this.pending = null;
    }

    apply(color) {
        const p = this.pending;
        if (!p) {
            return;
        }
        this.marks[p.taskId] = addMark(this.marks[p.taskId] ?? [], p.from, p.to, color);
        this.save();
        this.paint();
        window.getSelection()?.removeAllRanges();
        this.hideMenu();
    }

    remove() {
        const p = this.pending;
        if (!p) {
            return;
        }
        this.marks[p.taskId] = removeMarks(this.marks[p.taskId] ?? [], p.from, p.to);
        this.save();
        this.paint();
        window.getSelection()?.removeAllRanges();
        this.hideMenu();
    }
}
