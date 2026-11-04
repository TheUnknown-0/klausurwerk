/**
 * Zeichenkern für alle SVG-Diagramme: Leinwand mit Raster, Zoom/Pan, Auswahl, Verschieben,
 * Verbinden, Löschen, Rückgängig/Wiederholen, Tastaturkürzel und Eigenschaften-Bereich.
 *
 * Ein Diagrammtyp ist ein "Plugin" (siehe unten); das Board kümmert sich um alles Übrige.
 *
 * Plugin-Schnittstelle:
 *   name, label                       Kennung und Beschriftung für Screenreader
 *   normalize(content, config)        Modell aus gespeichertem Inhalt (tolerant)
 *   serialize?(model)                 Inhalt zum Speichern (Standard: Kopie des Modells)
 *   render(model, rc)                 zeichnet in rc.layer (siehe makeContext)
 *   bounds(model)                     umgebendes Rechteck oder null
 *   movable?(model, id)               Objekt mit x/y oder null
 *   tools?(board)                     Palette: [{id, label, title?, group?, toggle?, hint?, run?(board)}]
 *   connect?(board, from, to, tool)   {ok, select?} | {error} | null
 *   remove?(model, ids)               löscht Elemente (und Abhängiges)
 *   inspector?(board)                 DOM für den Eigenschaften-Bereich
 *   describe?(model, id)              Text für Screenreader
 *   onKey?(e, board) -> bool          zusätzliche Tasten
 *   onAction?(name, data, board)      Klick auf ein data-action-Element
 *   mountExtras?(board, host)         zusätzliche Bedienelemente unter der Leinwand
 *   freeLayout (true)                 false: kein Verschieben/Raster (Struktogramm)
 */
import { h, debounce } from '../../../lib/dom.js';
import { History } from './history.js';
import { GRID, clamp, snap } from './geometry.js';
import { s } from './svg.js';
import { svgToPng } from './png.js';

let boardCounter = 0;

/** Ersten Vorfahren (inklusive el) mit Attribut attr bis zur Wurzel root. */
function ancestorWith(el, attr, root) {
    let cur = el;
    while (cur && cur !== root) {
        if (typeof cur.getAttribute === 'function' && cur.getAttribute(attr) !== null) {
            return cur;
        }
        cur = cur.parentNode;
    }

    return null;
}

/** Findet ein Element mit data-id unterhalb von root (ohne querySelector). */
function findById(root, id) {
    const stack = [root];
    while (stack.length > 0) {
        const el = stack.pop();
        if (typeof el.getAttribute === 'function' && el.getAttribute('data-id') === id) {
            return el;
        }
        for (const child of el.children ?? []) {
            stack.push(child);
        }
    }

    return null;
}

const isTyping = (el) => {
    const tag = el?.tagName?.toLowerCase?.();

    return tag === 'input' || tag === 'textarea' || tag === 'select' || el?.isContentEditable === true;
};

export class DiagramBoard {
    /**
     * @param {HTMLElement} host
     * @param {any} plugin
     * @param {any} content gespeicherter Inhalt
     * @param {{readOnly?: boolean, config?: any, mode?: string, onChange?: () => void}} [opts]
     */
    constructor(host, plugin, content, opts = {}) {
        this.plugin = plugin;
        this.config = opts.config ?? {};
        this.readOnly = !!opts.readOnly;
        this.mode = opts.mode ?? 'exam';
        this.onChange = opts.onChange ?? (() => {});
        this.model = plugin.normalize(content, this.config);
        this.history = new History(this.model);
        this.sel = new Set();
        this.tool = null;
        this.pendingFrom = null;
        this.snapOn = true;
        this.view = { tx: 24, ty: 24, zoom: 1 };
        this.gesture = null;
        this.userMoved = false;
        this.destroyed = false;
        this.uid = ++boardCounter;
        this.extras = null;
        this.toolButtons = new Map();

        this.dirty = false;
        this.fireChange = debounce(() => {
            if (!this.destroyed && this.dirty) {
                this.dirty = false;
                this.onChange();
            }
        }, 300);

        this.build(host);
        this.render();
        this.refreshInspector();
        this.refreshToolbar();
        this.extras = plugin.mountExtras?.(this, this.extrasHost) ?? null;
        this.extras?.update?.();
    }

    /** Meldet eine inhaltliche Änderung (entprellt, 300 ms). */
    emit() {
        this.dirty = true;
        this.fireChange();
    }

    // ------------------------------------------------------------------ Aufbau

    build(host) {
        const p = this.plugin;
        this.root = h('div', {
            class: 'dg-root',
            dataset: { type: p.name, readonly: this.readOnly ? '1' : '0', mode: this.mode },
        });
        this.toolbar = h('div', { class: 'dg-toolbar', role: 'toolbar', 'aria-label': `Werkzeuge: ${p.label}` });
        this.hint = h('div', { class: 'dg-hint', 'aria-live': 'polite' });
        this.status = h('div', { class: 'sr-only', role: 'status', 'aria-live': 'polite' });

        const gridId = `dg-grid-${this.uid}`;
        this.gridRect = s('rect', { x: -4000, y: -4000, width: 12000, height: 12000, fill: `url(#${gridId})`, 'pointer-events': 'none' });
        this.layer = s('g', { class: 'dg-layer' });
        this.overlay = s('g', { class: 'dg-overlay', 'pointer-events': 'none' });
        this.world = s('g', { class: 'dg-world' }, this.gridRect, this.layer, this.overlay);
        this.svg = s('svg', {
            class: 'dg-svg',
            role: 'group',
            tabindex: 0,
            'aria-label': `${p.label}. Pfeiltasten verschieben, Entf löscht, Strg+Z macht rückgängig.`,
            focusable: 'true',
        },
        s('defs', {}, s('pattern', { id: gridId, width: GRID * 2, height: GRID * 2, patternUnits: 'userSpaceOnUse' },
            s('path', { d: `M${GRID * 2} 0H0V${GRID * 2}`, fill: 'none', stroke: '#e5e7eb', 'stroke-width': 1 }))),
        this.world);

        this.zoomBar = h('div', { class: 'dg-zoom', role: 'group', 'aria-label': 'Ansicht' },
            this.zoomButton('−', 'Verkleinern', () => this.zoomBy(1 / 1.25)),
            this.zoomButton('+', 'Vergrößern', () => this.zoomBy(1.25)),
            this.zoomButton('⤢', 'Alles einpassen', () => this.fit()));
        this.viewport = h('div', { class: 'dg-viewport' }, this.svg, this.zoomBar);
        this.inspectorHost = h('div', { class: 'dg-inspector', 'aria-label': 'Eigenschaften' });
        this.extrasHost = h('div', { class: 'dg-extras' });
        this.main = h('div', { class: 'dg-main' }, this.viewport, this.inspectorHost);
        this.root.append(this.toolbar, this.hint, this.main, this.extrasHost, this.status);
        host.append(this.root);

        this.applyView();
        this.bind();
        this.buildToolbar();
    }

    zoomButton(label, title, onClick) {
        return h('button', { type: 'button', class: 'btn btn-sm dg-zoom-btn', title, 'aria-label': title, onclick: onClick }, label);
    }

    buildToolbar() {
        this.toolbar.replaceChildren();
        this.toolButtons.clear();
        this.toolbar.hidden = this.readOnly;
        this.hint.hidden = this.readOnly;
        if (this.readOnly) {
            return;
        }
        const group = (label, ...buttons) => h('div', { class: 'dg-tool-group', role: 'group', 'aria-label': label },
            h('span', { class: 'dg-tool-label' }, label), ...buttons);
        const btn = (id, label, title, onClick, extra = {}) => {
            const b = h('button', { type: 'button', class: 'btn btn-sm dg-tool', title: title ?? label, onclick: onClick, ...extra }, label);
            this.toolButtons.set(id, b);

            return b;
        };

        // typspezifische Werkzeuge, nach group gebündelt
        const groups = new Map();
        for (const t of this.plugin.tools?.(this) ?? []) {
            const g = t.group ?? 'Werkzeuge';
            if (!groups.has(g)) {
                groups.set(g, []);
            }
            groups.get(g).push(btn(`tool:${t.id}`, t.label, t.title, () => this.useTool(t), { 'aria-pressed': t.toggle ? 'false' : undefined }));
        }
        for (const [label, buttons] of groups) {
            this.toolbar.append(group(label, ...buttons));
        }

        this.toolbar.append(group('Bearbeiten',
            btn('undo', '↶ Rückgängig', 'Rückgängig (Strg+Z)', () => this.undo()),
            btn('redo', '↷ Wiederholen', 'Wiederholen (Strg+Y)', () => this.redo()),
            btn('delete', '🗑 Löschen', 'Auswahl löschen (Entf)', () => this.removeSelection()),
            this.plugin.freeLayout === false ? null : btn('snap', '▦ Raster', 'Am Raster ausrichten', () => {
                this.snapOn = !this.snapOn;
                this.refreshToolbar();
            }, { 'aria-pressed': 'true' })));
    }

    // ----------------------------------------------------------- Ereignisse

    bind() {
        const svg = this.svg;
        svg.addEventListener('pointerdown', (e) => this.onPointerDown(e));
        svg.addEventListener('pointermove', (e) => this.onPointerMove(e));
        svg.addEventListener('pointerup', (e) => this.onPointerUp(e));
        svg.addEventListener('pointercancel', (e) => this.onPointerUp(e, true));
        svg.addEventListener('dblclick', (e) => {
            const item = ancestorWith(e.target, 'data-id', svg);
            if (item && !this.readOnly) {
                this.activate(item.getAttribute('data-id'));
            }
        });
        svg.addEventListener('wheel', (e) => {
            if (e.ctrlKey || e.metaKey) {
                e.preventDefault();
                const p = this.clientToLocal(e);
                this.zoomBy(e.deltaY < 0 ? 1.1 : 1 / 1.1, p.x, p.y);
            }
        }, { passive: false });
        svg.addEventListener('focusin', (e) => {
            const item = ancestorWith(e.target, 'data-id', svg);
            if (item && !this.readOnly && this.gesture === null) {
                const id = item.getAttribute('data-id');
                if (!this.sel.has(id)) {
                    this.select([id], { fromFocus: true });
                }
            }
        });
        this.root.addEventListener('keydown', (e) => this.onKeyDown(e));

        if (typeof ResizeObserver !== 'undefined') {
            this.resizeObserver = new ResizeObserver(() => {
                if (!this.userMoved && !this.destroyed) {
                    this.fit({ silent: true });
                }
            });
            this.resizeObserver.observe(this.viewport);
        }
    }

    /** Pixel im Viewport (links oben = 0,0). */
    clientToLocal(e) {
        const r = this.svg.getBoundingClientRect?.() ?? { left: 0, top: 0 };

        return { x: e.clientX - r.left, y: e.clientY - r.top };
    }

    /** Koordinaten in der Diagrammfläche. */
    worldPoint(e) {
        const p = this.clientToLocal(e);

        return { x: (p.x - this.view.tx) / this.view.zoom, y: (p.y - this.view.ty) / this.view.zoom };
    }

    gridSnap(v) {
        return this.snapOn && this.plugin.freeLayout !== false ? snap(v) : Math.round(v);
    }

    onPointerDown(e) {
        if (e.pointerType === 'mouse' && e.button !== 0) {
            return;
        }
        const target = e.target;
        const actionEl = ancestorWith(target, 'data-action', this.svg);
        const portEl = ancestorWith(target, 'data-port', this.svg);
        const itemEl = ancestorWith(target, 'data-id', this.svg);
        const pt = this.worldPoint(e);

        if (actionEl && (!this.readOnly || actionEl.getAttribute('data-always') !== null)) {
            this.gesture = { type: 'action', el: actionEl, pt };
            this.svg.setPointerCapture?.(e.pointerId);
            e.preventDefault?.();
            return;
        }
        if (this.readOnly) {
            // Nur ansehen: Ansicht verschieben.
            this.startPan(e);
            return;
        }
        if (portEl && this.plugin.connect) {
            this.svg.focus?.();
            this.gesture = { type: 'connect', from: portEl.getAttribute('data-port'), pt, moved: false };
            this.svg.setPointerCapture?.(e.pointerId);
            e.preventDefault?.();
            return;
        }
        if (this.tool?.connect && itemEl) {
            const id = itemEl.getAttribute('data-id');
            if (this.pendingFrom !== null) {
                // zweiter Klick: Ziel (derselbe Klick auf denselben Knoten ergibt eine Schleife)
                this.finishConnect(this.pendingFrom, id);
                e.preventDefault?.();
                return;
            }
            this.gesture = { type: 'connect', from: id, pt, moved: false, click: true };
            this.svg.setPointerCapture?.(e.pointerId);
            e.preventDefault?.();
            return;
        }
        if (itemEl) {
            const id = itemEl.getAttribute('data-id');
            if (e.shiftKey) {
                const next = new Set(this.sel);
                if (next.has(id)) {
                    next.delete(id);
                } else {
                    next.add(id);
                }
                this.select([...next]);
            } else if (!this.sel.has(id)) {
                this.select([id]);
            }
            const ids = [...this.sel].filter((x) => this.plugin.movable?.(this.model, x));
            if (ids.length > 0 && this.sel.has(id)) {
                this.gesture = {
                    type: 'drag',
                    start: pt,
                    startClient: { x: e.clientX, y: e.clientY },
                    origins: new Map(ids.map((x) => {
                        const m = this.plugin.movable(this.model, x);

                        return [x, { x: m.x, y: m.y }];
                    })),
                    moved: false,
                };
                this.svg.setPointerCapture?.(e.pointerId);
            }
            return;
        }
        // Hintergrund: Auswahl aufheben, Ansicht verschieben
        if (!e.shiftKey && (this.sel.size > 0 || this.pendingFrom !== null)) {
            this.pendingFrom = null;
            this.select([]);
        }
        this.startPan(e);
    }

    startPan(e) {
        this.gesture = { type: 'pan', startClient: { x: e.clientX, y: e.clientY }, origin: { tx: this.view.tx, ty: this.view.ty }, moved: false };
        this.svg.setPointerCapture?.(e.pointerId);
        this.svg.classList?.add('is-panning');
    }

    onPointerMove(e) {
        const g = this.gesture;
        if (!g) {
            return;
        }
        if (g.type === 'pan') {
            const dx = e.clientX - g.startClient.x;
            const dy = e.clientY - g.startClient.y;
            if (Math.abs(dx) + Math.abs(dy) > 2) {
                g.moved = true;
                this.userMoved = true;
            }
            this.view.tx = g.origin.tx + dx;
            this.view.ty = g.origin.ty + dy;
            this.applyView();
        } else if (g.type === 'drag') {
            const pt = this.worldPoint(e);
            const screenDist = Math.abs(e.clientX - g.startClient.x) + Math.abs(e.clientY - g.startClient.y);
            if (!g.moved && screenDist < 4) {
                return;
            }
            g.moved = true;
            for (const [id, o] of g.origins) {
                const m = this.plugin.movable(this.model, id);
                if (m) {
                    m.x = this.gridSnap(o.x + (pt.x - g.start.x));
                    m.y = this.gridSnap(o.y + (pt.y - g.start.y));
                }
            }
            this.render();
        } else if (g.type === 'connect') {
            const pt = this.worldPoint(e);
            if (!g.moved && Math.hypot(pt.x - g.pt.x, pt.y - g.pt.y) < 4) {
                return;
            }
            g.moved = true;
            this.drawRubber(g.from, pt);
        }
    }

    onPointerUp(e, cancelled = false) {
        const g = this.gesture;
        this.gesture = null;
        this.svg.classList?.remove('is-panning');
        try {
            this.svg.releasePointerCapture?.(e.pointerId);
        } catch {
            // Capture war schon weg
        }
        this.overlay.replaceChildren();
        if (!g || cancelled) {
            if (g?.type === 'drag' && g.moved) {
                this.afterChange('drag');
            }
            return;
        }
        if (g.type === 'drag') {
            if (g.moved) {
                this.afterChange('drag');
            } else {
                const id = [...g.origins.keys()][0];
                if (id !== undefined && this.sel.size === 1) {
                    this.plugin.onClick?.(id, this);
                }
            }
        } else if (g.type === 'action') {
            const under = this.elementUnder(e);
            if (under && (under === g.el || g.el.contains?.(under))) {
                this.runAction(g.el);
            }
        } else if (g.type === 'connect') {
            const under = this.elementUnder(e);
            const portEl = under ? ancestorWith(under, 'data-port', this.svg) : null;
            const itemEl = under ? ancestorWith(under, 'data-id', this.svg) : null;
            const to = portEl ? portEl.getAttribute('data-port') : itemEl ? itemEl.getAttribute('data-id') : null;
            if (g.click && !g.moved) {
                this.pendingFrom = g.from;
                this.setHint(this.tool?.hintTarget ?? 'Wähle jetzt das Ziel.');
                this.render();
            } else if (to !== null && to !== g.from) {
                this.finishConnect(g.from, to);
            } else if (to === g.from && g.moved && !portEl) {
                // auf sich selbst losgelassen: Schleife (falls der Typ das kennt)
                this.finishConnect(g.from, to);
            }
        }
    }

    elementUnder(e) {
        return typeof document.elementFromPoint === 'function' ? document.elementFromPoint(e.clientX, e.clientY) : e.target;
    }

    /** Gummiband vom Startpunkt zum Zeiger. */
    drawRubber(from, pt) {
        this.overlay.replaceChildren();
        const c = this.plugin.centerOf?.(this.model, from);
        if (!c) {
            return;
        }
        this.overlay.append(s('line', {
            x1: c.x, y1: c.y, x2: pt.x, y2: pt.y, stroke: '#4f46e5', 'stroke-width': 2, 'stroke-dasharray': '6 4',
        }));
    }

    finishConnect(from, to) {
        this.pendingFrom = null;
        const result = this.plugin.connect?.(this, from, to, this.tool);
        if (result?.error) {
            this.announce(result.error);
            this.setHint(result.error, true);
            if (result.select) {
                this.select([result.select]);
            }
        } else if (result?.ok) {
            this.afterChange(null, { inspector: true });
            if (result.select) {
                this.select([result.select]);
            }
            this.setHint(this.tool?.hint ?? '');
            this.announce(result.message ?? 'Verbindung hergestellt.');
        }
        this.render();
    }

    runAction(el) {
        const name = el.getAttribute('data-action');
        const data = {};
        for (const attr of el.attributes ?? []) {
            if (attr.name.startsWith('data-') && attr.name !== 'data-action') {
                data[attr.name.slice(5)] = attr.value;
            }
        }
        this.plugin.onAction?.(name, data, this);
    }

    // ------------------------------------------------------------- Tastatur

    onKeyDown(e) {
        if (isTyping(e.target)) {
            return;
        }
        const mod = e.ctrlKey || e.metaKey;
        const key = e.key;
        const inSvg = e.target === this.svg || this.svg.contains?.(e.target) === true;
        if (mod && (key === 'z' || key === 'Z') && !e.shiftKey) {
            e.preventDefault();
            this.undo();
            return;
        }
        if (mod && (key === 'y' || key === 'Y' || ((key === 'z' || key === 'Z') && e.shiftKey))) {
            e.preventDefault();
            this.redo();
            return;
        }
        if (!inSvg) {
            return;
        }
        const actionEl = e.target && typeof e.target.getAttribute === 'function' ? ancestorWith(e.target, 'data-action', this.svg) : null;
        if (this.readOnly) {
            if (actionEl && actionEl.getAttribute('data-always') !== null && (key === 'Enter' || key === ' ')) {
                e.preventDefault();
                this.runAction(actionEl);
                return;
            }
            this.keyPan(e);
            return;
        }
        if (this.plugin.onKey?.(e, this) === true) {
            e.preventDefault();
            return;
        }
        if (actionEl && (key === 'Enter' || key === ' ')) {
            e.preventDefault();
            this.runAction(actionEl);
            return;
        }
        if (key === 'Escape') {
            if (this.tool || this.pendingFrom !== null) {
                this.setTool(null);
            } else {
                this.select([]);
            }
            return;
        }
        if (key === 'Delete' || key === 'Backspace') {
            if (this.sel.size > 0) {
                e.preventDefault();
                this.removeSelection();
            }
            return;
        }
        if (key === 'Enter' || key === 'F2') {
            const id = [...this.sel][0];
            if (id !== undefined) {
                e.preventDefault();
                if (this.tool?.connect) {
                    this.keyboardConnect(id);
                } else {
                    this.activate(id);
                }
            }
            return;
        }
        if (key.startsWith('Arrow')) {
            const step = e.shiftKey ? GRID * 5 : GRID;
            const dx = key === 'ArrowLeft' ? -step : key === 'ArrowRight' ? step : 0;
            const dy = key === 'ArrowUp' ? -step : key === 'ArrowDown' ? step : 0;
            const movable = [...this.sel].filter((id) => this.plugin.movable?.(this.model, id));
            if (movable.length > 0 && this.plugin.freeLayout !== false) {
                e.preventDefault();
                for (const id of movable) {
                    const m = this.plugin.movable(this.model, id);
                    m.x = snap(m.x + dx);
                    m.y = snap(m.y + dy);
                }
                this.afterChange('move');
            } else if (this.sel.size === 0) {
                this.keyPan(e);
            }
            return;
        }
        if (key === '+' || key === '=') {
            this.zoomBy(1.25);
        } else if (key === '-') {
            this.zoomBy(1 / 1.25);
        } else if (key === '0') {
            this.fit();
        }
    }

    keyPan(e) {
        const step = 40;
        const map = { ArrowLeft: [step, 0], ArrowRight: [-step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] };
        if (map[e.key]) {
            e.preventDefault();
            this.view.tx += map[e.key][0];
            this.view.ty += map[e.key][1];
            this.userMoved = true;
            this.applyView();
        } else if (e.key === '+' || e.key === '=') {
            this.zoomBy(1.25);
        } else if (e.key === '-') {
            this.zoomBy(1 / 1.25);
        } else if (e.key === '0') {
            this.fit();
        }
    }

    keyboardConnect(id) {
        if (this.pendingFrom === null) {
            this.pendingFrom = id;
            this.setHint(this.tool?.hintTarget ?? 'Wähle jetzt das Ziel mit Tab und bestätige mit Enter.');
            this.announce('Start gewählt. Wähle das Ziel und drücke Enter.');
            this.render();
        } else if (this.pendingFrom !== id) {
            this.finishConnect(this.pendingFrom, id);
        } else {
            this.finishConnect(id, id);
        }
    }

    /** Eigenschaften des Elements bearbeiten (Fokus in den Eigenschaften-Bereich). */
    activate(id) {
        if (this.plugin.onActivate?.(id, this) === true) {
            return;
        }
        this.select([id]);
        this.focusInspector();
    }

    focusInspector() {
        const stack = [this.inspectorHost];
        while (stack.length > 0) {
            const el = stack.shift();
            const tag = el.tagName?.toLowerCase?.();
            if (tag === 'input' || tag === 'select' || tag === 'textarea' || tag === 'button') {
                el.focus?.();
                return true;
            }
            stack.push(...(el.children ?? []));
        }

        return false;
    }

    // --------------------------------------------------------------- Werkzeuge

    useTool(t) {
        if (t.toggle) {
            this.setTool(this.tool?.id === t.id ? null : t);
        } else {
            this.setTool(null);
            t.run?.(this);
        }
    }

    setTool(t) {
        this.tool = t;
        this.pendingFrom = null;
        this.setHint(t?.hint ?? '');
        if (t) {
            this.announce(t.hint ?? t.label);
        }
        this.refreshToolbar();
        this.render();
    }

    setHint(text, isError = false) {
        this.hint.textContent = text ?? '';
        this.hint.classList?.toggle('is-error', !!isError);
    }

    announce(text) {
        this.status.textContent = '';
        // Leerer Zwischenschritt, damit gleiche Meldungen erneut vorgelesen werden.
        setTimeout(() => { this.status.textContent = text; }, 20);
    }

    refreshToolbar() {
        if (this.readOnly) {
            return;
        }
        const set = (id, fn) => {
            const b = this.toolButtons.get(id);
            if (b) {
                fn(b);
            }
        };
        set('undo', (b) => { b.disabled = !this.history.canUndo; });
        set('redo', (b) => { b.disabled = !this.history.canRedo; });
        set('delete', (b) => { b.disabled = this.sel.size === 0; });
        set('snap', (b) => b.setAttribute('aria-pressed', this.snapOn ? 'true' : 'false'));
        for (const t of this.plugin.tools?.(this) ?? []) {
            set(`tool:${t.id}`, (b) => {
                if (t.toggle) {
                    const on = this.tool?.id === t.id;
                    b.setAttribute('aria-pressed', on ? 'true' : 'false');
                    b.classList.toggle('is-active', on);
                }
                b.disabled = t.disabled?.(this) === true;
            });
        }
    }

    // ----------------------------------------------------------- Zustand/Modell

    /**
     * Ändert das Modell und hält die Änderung fest.
     * @param {(model: any) => void} fn
     * @param {{key?: string|null, inspector?: boolean}} [opts] key: gleiche Schlüssel werden zu einem Undo-Schritt
     */
    mutate(fn, opts = {}) {
        if (this.readOnly) {
            return;
        }
        fn(this.model);
        this.afterChange(opts.key ?? null, { inspector: !!opts.inspector });
    }

    afterChange(key = null, { inspector = false } = {}) {
        this.history.record(this.model, key);
        this.render();
        if (inspector) {
            this.refreshInspector();
        }
        this.refreshToolbar();
        this.extras?.update?.();
        this.emit();
    }

    /** Ersetzt das Modell von außen (Live-Mitlesen im Pult), ohne Änderung zu melden. */
    setContent(content) {
        this.model = this.plugin.normalize(content, this.config);
        this.history.reset(this.model);
        const ids = new Set(this.allIds());
        this.sel = new Set([...this.sel].filter((id) => ids.has(id)));
        this.render();
        if (!this.inspectorHasFocus()) {
            this.refreshInspector();
        }
        this.refreshToolbar();
        this.extras?.update?.();
    }

    /** Neue Konfiguration (Lehrkraft ändert Eingänge, Alphabet ...): Modell neu normalisieren. */
    setConfig(config) {
        this.config = config ?? {};
        this.model = this.plugin.normalize(this.plugin.serialize ? this.plugin.serialize(this.model) : this.model, this.config);
        this.history.reset(this.model);
        this.buildToolbar();
        this.render();
        this.refreshInspector();
        this.refreshToolbar();
        this.extras?.update?.();
    }

    getContent() {
        return JSON.parse(JSON.stringify(this.plugin.serialize ? this.plugin.serialize(this.model) : this.model));
    }

    allIds() {
        return this.plugin.ids ? this.plugin.ids(this.model) : [];
    }

    inspectorHasFocus() {
        const a = document.activeElement;

        return !!a && this.inspectorHost.contains?.(a) === true;
    }

    undo() {
        const m = this.history.undo();
        if (m) {
            this.restore(m);
        }
    }

    redo() {
        const m = this.history.redo();
        if (m) {
            this.restore(m);
        }
    }

    restore(model) {
        this.model = model;
        const ids = new Set(this.allIds());
        this.sel = new Set([...this.sel].filter((id) => ids.has(id)));
        this.render();
        this.refreshInspector();
        this.refreshToolbar();
        this.extras?.update?.();
        this.emit();
    }

    removeSelection() {
        if (this.readOnly || this.sel.size === 0 || !this.plugin.remove) {
            return;
        }
        const ids = [...this.sel];
        const removed = this.plugin.remove(this.model, ids);
        if (removed === false) {
            this.announce('Das lässt sich nicht löschen.');
            return;
        }
        this.sel = new Set();
        this.afterChange(null, { inspector: true });
        this.announce('Gelöscht.');
    }

    select(ids, { fromFocus = false } = {}) {
        this.sel = new Set(ids);
        this.render();
        this.refreshInspector();
        this.refreshToolbar();
        const first = ids[0];
        if (first !== undefined && this.plugin.describe && !fromFocus) {
            this.announce(this.plugin.describe(this.model, first));
        }
    }

    // ------------------------------------------------------------- Darstellung

    makeContext(layer, exportMode = false) {
        const board = this;
        const editable = !this.readOnly && !exportMode;

        return {
            layer,
            sel: exportMode ? new Set() : this.sel,
            readOnly: this.readOnly || exportMode,
            exportMode,
            config: this.config,
            board: exportMode ? null : this,
            tool: exportMode ? null : this.tool,
            pendingFrom: exportMode ? null : this.pendingFrom,
            snapOn: this.snapOn,
            /** Macht ein Element auswählbar (und per Tastatur erreichbar). */
            mark(el, id, label) {
                if (exportMode) {
                    return el;
                }
                el.setAttribute('data-id', id);
                el.classList.add('dg-item');
                if (board.sel.has(id)) {
                    el.classList.add('is-selected');
                }
                if (board.pendingFrom === id) {
                    el.classList.add('is-pending');
                }
                if (editable) {
                    el.setAttribute('tabindex', '0');
                    el.setAttribute('role', 'button');
                    el.setAttribute('aria-label', label ?? id);
                    el.setAttribute('aria-pressed', board.sel.has(id) ? 'true' : 'false');
                } else {
                    el.setAttribute('role', 'img');
                    el.setAttribute('aria-label', label ?? id);
                }

                return el;
            },
            /** Klickbares Element mit Aktion (Einfügepunkt, Schalter). */
            action(el, name, data = {}, label = '', always = false) {
                if (exportMode || (!editable && !always)) {
                    return el;
                }
                el.setAttribute('data-action', name);
                if (always) {
                    el.setAttribute('data-always', '1');
                }
                for (const [k, v] of Object.entries(data)) {
                    el.setAttribute(`data-${k}`, String(v));
                }
                el.classList.add('dg-action');
                el.setAttribute('tabindex', '0');
                el.setAttribute('role', 'button');
                el.setAttribute('aria-label', label || name);

                return el;
            },
            /** Anschlusspunkt, an dem eine Verbindung beginnt oder endet. */
            port(el, port, label = '') {
                if (exportMode || !editable) {
                    return el;
                }
                el.setAttribute('data-port', port);
                el.classList.add('dg-port');
                if (label) {
                    el.setAttribute('aria-label', label);
                }

                return el;
            },
        };
    }

    render() {
        if (this.destroyed) {
            return;
        }
        const active = document.activeElement;
        const focusedId = active && this.layer.contains?.(active) ? active.getAttribute?.('data-id') ?? null : null;
        const focusedAction = focusedId === null && active && this.layer.contains?.(active) ? active.getAttribute?.('data-action') : null;
        const focusedKey = focusedAction ? `${active.getAttribute('data-key') ?? ''}|${active.getAttribute('data-index') ?? ''}` : null;
        this.layer.replaceChildren();
        this.plugin.render(this.model, this.makeContext(this.layer));
        this.gridRect.setAttribute('visibility', this.snapOn && this.plugin.freeLayout !== false ? 'visible' : 'hidden');
        if (focusedId !== null) {
            findById(this.layer, focusedId)?.focus?.({ preventScroll: true });
        } else if (focusedKey !== null) {
            this.refocusAction(focusedAction, focusedKey);
        }
    }

    refocusAction(name, key) {
        const stack = [this.layer];
        while (stack.length > 0) {
            const el = stack.pop();
            if (typeof el.getAttribute === 'function' && el.getAttribute('data-action') === name
                && `${el.getAttribute('data-key') ?? ''}|${el.getAttribute('data-index') ?? ''}` === key) {
                el.focus?.({ preventScroll: true });
                return;
            }
            stack.push(...(el.children ?? []));
        }
    }

    refreshInspector() {
        if (this.destroyed) {
            return;
        }
        this.inspectorHost.replaceChildren();
        const content = this.readOnly ? null : this.plugin.inspector?.(this);
        this.inspectorHost.hidden = !content;
        this.main.classList?.toggle('has-inspector', !!content);
        if (content) {
            this.inspectorHost.append(content);
        }
    }

    // ------------------------------------------------------------ Ansicht

    applyView() {
        const { tx, ty, zoom } = this.view;
        this.world.setAttribute('transform', `translate(${Math.round(tx * 10) / 10} ${Math.round(ty * 10) / 10}) scale(${zoom})`);
    }

    zoomBy(factor, cx = null, cy = null) {
        const rect = this.svg.getBoundingClientRect?.() ?? { width: 600, height: 400 };
        const px = cx ?? (rect.width || 600) / 2;
        const py = cy ?? (rect.height || 400) / 2;
        const next = clamp(this.view.zoom * factor, 0.25, 3);
        const f = next / this.view.zoom;
        this.view.tx = px - (px - this.view.tx) * f;
        this.view.ty = py - (py - this.view.ty) * f;
        this.view.zoom = next;
        this.userMoved = true;
        this.applyView();
    }

    /** Mittelpunkt des sichtbaren Bereichs in Diagrammkoordinaten. */
    viewCenter() {
        const rect = this.svg.getBoundingClientRect?.() ?? { width: 600, height: 400 };

        return {
            x: ((rect.width || 600) / 2 - this.view.tx) / this.view.zoom,
            y: ((rect.height || 400) / 2 - this.view.ty) / this.view.zoom,
        };
    }

    /** Passt die Ansicht an den Inhalt an (nicht größer als 100 %, außer maxZoom). */
    fit({ silent = false, maxZoom = 1 } = {}) {
        const rect = this.svg.getBoundingClientRect?.() ?? { width: 0, height: 0 };
        if (!rect.width || !rect.height) {
            return;
        }
        const b = this.plugin.bounds(this.model);
        if (!b) {
            this.view = { tx: 24, ty: 24, zoom: 1 };
        } else {
            const m = 28;
            const zoom = clamp(Math.min((rect.width - 2 * m) / b.w, (rect.height - 2 * m) / b.h), 0.3, maxZoom);
            this.view = {
                zoom,
                tx: (rect.width - b.w * zoom) / 2 - b.x * zoom,
                ty: Math.max(m, (rect.height - b.h * zoom) / 2) - b.y * zoom,
            };
        }
        if (!silent) {
            this.userMoved = false;
        }
        this.applyView();
    }

    // -------------------------------------------------------------- Export

    /** Eigenständiges SVG (weißer Hintergrund) für die PNG-Vorschau. */
    exportSvg() {
        const b = this.plugin.bounds(this.model);
        if (!b) {
            return null;
        }
        const margin = 20;
        const width = Math.ceil(b.w + margin * 2);
        const height = Math.ceil(b.h + margin * 2);
        const g = s('g', { transform: `translate(${margin - b.x} ${margin - b.y})` });
        this.plugin.render(this.model, this.makeContext(g, true));
        const svg = s('svg', { xmlns: 'http://www.w3.org/2000/svg', width, height, viewBox: `0 0 ${width} ${height}` },
            s('rect', { x: 0, y: 0, width, height, fill: '#ffffff' }), g);

        return { svg, width, height };
    }

    async getPreviewPng() {
        const out = this.exportSvg();

        return out ? svgToPng(out.svg, out.width, out.height) : null;
    }

    // -------------------------------------------------------- Lebensende

    setReadOnly(flag) {
        this.readOnly = !!flag;
        this.root.dataset.readonly = this.readOnly ? '1' : '0';
        this.tool = null;
        this.pendingFrom = null;
        this.gesture = null;
        this.buildToolbar();
        this.render();
        this.refreshInspector();
        this.refreshToolbar();
        this.extras?.update?.();
    }

    destroy() {
        if (this.destroyed) {
            return;
        }
        this.fireChange.cancel?.();
        if (this.dirty) {
            this.dirty = false;
            this.onChange();
        }
        this.destroyed = true;
        this.resizeObserver?.disconnect?.();
        this.extras?.destroy?.();
        this.root.remove();
    }
}

