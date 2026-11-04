/**
 * Programmablaufplan (PAP) nach DIN 66001 (Antworttyp "flowchart").
 * Format: docs/DATENFORMATE.md, 2.10.
 */
import { h } from '../../lib/dom.js';
import { registerAnswerWidget } from '../../lib/widgets.js';
import {
    arrowInset, arrowShape, bbox, center, endAngle, freePosition, newId, pointAlong, polylineLength, polylinePath, routeOrthogonal, textBlock, trimEnd,
} from './core/geometry.js';
import { boardWidget } from './core/widget.js';
import { line, multiline, num, pick, rows, uniqueId } from './core/model-util.js';
import { field, hintText, panel, selectInput, smallButton, textArea, textInput } from './core/form.js';
import { INK, PAPER, SOFT, line as svgLine, path, polygon, s, text } from './core/svg.js';

export const NODE_TYPES = ['start', 'end', 'process', 'decision', 'io', 'subroutine', 'connector'];
export const NODE_LABEL = {
    start: 'Start', end: 'Ende', process: 'Operation', decision: 'Verzweigung', io: 'Ein-/Ausgabe', subroutine: 'Unterprogramm', connector: 'Verbinder',
};
const DEFAULT_TEXT = { start: 'Start', end: 'Ende' };
const SKEW = 18;

export function normalizeFlowchart(content) {
    const ids = new Set();
    const nodes = rows(content?.nodes, 120).map((n) => ({
        id: uniqueId(n.id, ids, 'n'),
        type: pick(n.type, NODE_TYPES, 'process'),
        text: multiline(n.text, 200),
        x: num(n.x),
        y: num(n.y),
    }));
    const nodeIds = new Set(nodes.map((n) => n.id));
    const edgeIds = new Set();
    const edges = [];
    for (const e of rows(content?.edges, 300)) {
        if (nodeIds.has(e.from) && nodeIds.has(e.to)) {
            edges.push({ id: uniqueId(e.id, edgeIds, 'e'), from: e.from, to: e.to, label: line(e.label, 40) });
        }
    }

    return { nodes, edges };
}

/** Maße eines Knotens. */
export function nodeSize(n) {
    const t = textBlock(n.text, 13);
    switch (n.type) {
        case 'start':
        case 'end':
            return { w: Math.max(110, Math.ceil(t.w + 44)), h: Math.max(42, Math.ceil(t.h + 22)) };
        case 'decision':
            return { w: Math.max(150, Math.ceil(t.w * 1.55 + 40)), h: Math.max(84, Math.ceil(t.h * 1.8 + 40)) };
        case 'io':
            return { w: Math.max(140, Math.ceil(t.w + 40 + SKEW)), h: Math.max(46, Math.ceil(t.h + 24)) };
        case 'connector': {
            const d = Math.max(40, Math.ceil(Math.max(t.w, t.h) + 16));

            return { w: d, h: d };
        }
        default:
            return { w: Math.max(130, Math.ceil(t.w + 36 + (n.type === 'subroutine' ? 22 : 0))), h: Math.max(46, Math.ceil(t.h + 24)) };
    }
}

const snapSize = (v) => Math.ceil(v / 10) * 10;

export function nodeRect(n) {
    const size = nodeSize(n);

    return { x: n.x, y: n.y, w: snapSize(size.w), h: snapSize(size.h) };
}

/** Rechteck, an dem Pfeile andocken (Parallelogramm: ohne die Schrägen). */
const anchorRect = (n) => {
    const r = nodeRect(n);

    return n.type === 'io' ? { x: r.x + SKEW / 2, y: r.y, w: r.w - SKEW, h: r.h } : r;
};

function drawNode(n, rc) {
    const r = nodeRect(n);
    const g = s('g', { class: 'dg-node' });
    const attrs = { class: 'dg-shape', fill: PAPER, stroke: INK, 'stroke-width': 1.6, 'stroke-linejoin': 'round' };
    switch (n.type) {
        case 'start':
        case 'end':
            g.append(s('rect', { ...attrs, x: r.x, y: r.y, width: r.w, height: r.h, rx: r.h / 2, ry: r.h / 2 }));
            break;
        case 'decision':
            g.append(polygon([
                { x: r.x + r.w / 2, y: r.y }, { x: r.x + r.w, y: r.y + r.h / 2 }, { x: r.x + r.w / 2, y: r.y + r.h }, { x: r.x, y: r.y + r.h / 2 },
            ], attrs));
            break;
        case 'io':
            g.append(polygon([
                { x: r.x + SKEW, y: r.y }, { x: r.x + r.w, y: r.y }, { x: r.x + r.w - SKEW, y: r.y + r.h }, { x: r.x, y: r.y + r.h },
            ], attrs));
            break;
        case 'connector':
            g.append(s('ellipse', { ...attrs, cx: r.x + r.w / 2, cy: r.y + r.h / 2, rx: r.w / 2, ry: r.h / 2 }));
            break;
        case 'subroutine':
            g.append(s('rect', { ...attrs, x: r.x, y: r.y, width: r.w, height: r.h }));
            g.append(svgLine(r.x + 10, r.y, r.x + 10, r.y + r.h), svgLine(r.x + r.w - 10, r.y, r.x + r.w - 10, r.y + r.h));
            break;
        default:
            g.append(s('rect', { ...attrs, x: r.x, y: r.y, width: r.w, height: r.h }));
    }
    const c = center(r);
    g.append(text(n.text, c.x, c.y, { size: 13 }));
    rc.mark(g, n.id, `${NODE_LABEL[n.type]}${n.text ? `: ${n.text.replace(/\n/g, ' ')}` : ' (leer)'}`);
    rc.layer.append(g);
}

/** Linienzug einer Kante. */
export function edgePath(model, e) {
    const a = model.nodes.find((n) => n.id === e.from);
    const b = model.nodes.find((n) => n.id === e.to);
    if (!a || !b || a.id === b.id) {
        return null;
    }

    return routeOrthogonal(anchorRect(a), anchorRect(b), { up: 'around' });
}

function drawEdge(model, e, rc) {
    const pts = edgePath(model, e);
    if (!pts || pts.length < 2) {
        return;
    }
    const g = s('g', { class: 'dg-edge' });
    g.append(s('path', { d: polylinePath(pts), fill: 'none', stroke: 'transparent', 'stroke-width': 14, class: 'dg-hit' }));
    g.append(path(polylinePath(trimEnd(pts, arrowInset('filled'))), { class: 'dg-shape' }));
    g.append(polygon(arrowShape('filled', pts[pts.length - 1], endAngle(pts)), { class: 'dg-shape', fill: INK }));
    if (e.label) {
        const p = pointAlong(pts, Math.min(18, polylineLength(pts) / 2));
        const side = p.angle > 1 || p.angle < -1 ? 1 : -1;
        const lx = p.x + (Math.abs(Math.sin(p.angle)) > 0.7 ? 14 : 0);
        const ly = p.y + (Math.abs(Math.sin(p.angle)) > 0.7 ? 0 : 14 * side);
        const label = text(e.label, lx, ly, { size: 12, weight: 'bold' });
        label.setAttribute('stroke', '#ffffff');
        label.setAttribute('stroke-width', '3');
        label.setAttribute('paint-order', 'stroke');
        g.append(label);
    }
    rc.mark(g, e.id, `Pfeil von ${labelOf(model, e.from)} nach ${labelOf(model, e.to)}${e.label ? `, ${e.label}` : ''}`);
    rc.layer.append(g);
}

const labelOf = (model, id) => {
    const n = model.nodes.find((x) => x.id === id);

    return n ? (n.text ? n.text.split('\n')[0] : NODE_LABEL[n.type]) : '?';
};

function addNode(board, type) {
    board.mutate((m) => {
        const id = newId('n', m.nodes.map((n) => n.id));
        const draft = { id, type, text: DEFAULT_TEXT[type] ?? '', x: 0, y: 0 };
        const size = nodeRect(draft);
        const at = board.viewCenter();
        // möglichst unterhalb des zuletzt gewählten Knotens einfügen
        const anchor = m.nodes.find((n) => board.sel.has(n.id));
        const start = anchor ? { x: anchor.x, y: anchor.y + nodeRect(anchor).h + 40 } : { x: at.x - size.w / 2, y: at.y - size.h / 2 };
        const pos = freePosition(m.nodes.map(nodeRect), size, start, 20, 24);
        draft.x = pos.x;
        draft.y = pos.y;
        m.nodes.push(draft);
        board.sel = new Set([id]);
    }, { inspector: true });
    board.refreshInspector();
    board.focusInspector();
}

export const flowchartPlugin = {
    name: 'flowchart',
    label: 'Programmablaufplan',
    normalize: (content) => normalizeFlowchart(content),
    ids: (m) => [...m.nodes.map((n) => n.id), ...m.edges.map((e) => e.id)],
    movable: (m, id) => m.nodes.find((n) => n.id === id) ?? null,
    centerOf: (m, id) => {
        const n = m.nodes.find((x) => x.id === id);

        return n ? center(nodeRect(n)) : null;
    },
    bounds: (m) => bbox(m.nodes.map(nodeRect)),
    render(model, rc) {
        for (const e of model.edges) {
            drawEdge(model, e, rc);
        }
        for (const n of model.nodes) {
            drawNode(n, rc);
        }
    },
    tools() {
        const list = NODE_TYPES.map((type) => ({
            id: `add-${type}`, group: 'Symbole', label: NODE_LABEL[type], title: `${NODE_LABEL[type]} einfügen`, run: (b) => addNode(b, type),
        }));
        list.push({
            id: 'arrow', group: 'Verbinden', label: '→ Pfeil', title: 'Zwei Symbole mit einem Pfeil verbinden', toggle: true, connect: true,
            hint: 'Pfeil: Klicke das Start-Symbol, dann das Ziel-Symbol (oder ziehe von einem zum anderen). Tastatur: Symbol wählen, Enter, Ziel wählen, Enter.',
            hintTarget: 'Wähle jetzt das Ziel-Symbol.',
        });

        return list;
    },
    connect(board, from, to) {
        const m = board.model;
        if (from === to) {
            return { error: 'Ein Pfeil muss zwei verschiedene Symbole verbinden.' };
        }
        if (!m.nodes.some((n) => n.id === from) || !m.nodes.some((n) => n.id === to)) {
            return null;
        }
        if (m.edges.some((e) => e.from === from && e.to === to)) {
            return { error: 'Diese Verbindung gibt es schon.' };
        }
        const src = m.nodes.find((n) => n.id === from);
        let label = '';
        if (src.type === 'decision') {
            const used = m.edges.filter((e) => e.from === from).map((e) => e.label);
            label = !used.includes('Ja') ? 'Ja' : !used.includes('Nein') ? 'Nein' : '';
        }
        const id = newId('e', m.edges.map((e) => e.id));
        m.edges.push({ id, from, to, label });

        return { ok: true, select: id, message: 'Pfeil angelegt.' };
    },
    remove(model, ids) {
        const set = new Set(ids);
        const before = model.nodes.length + model.edges.length;
        model.nodes = model.nodes.filter((n) => !set.has(n.id));
        const alive = new Set(model.nodes.map((n) => n.id));
        model.edges = model.edges.filter((e) => !set.has(e.id) && alive.has(e.from) && alive.has(e.to));

        return model.nodes.length + model.edges.length < before;
    },
    describe(model, id) {
        const n = model.nodes.find((x) => x.id === id);
        if (n) {
            return `${NODE_LABEL[n.type]} ${n.text.replace(/\n/g, ' ')}`;
        }
        const e = model.edges.find((x) => x.id === id);

        return e ? `Pfeil von ${labelOf(model, e.from)} nach ${labelOf(model, e.to)}` : '';
    },
    inspector(board) {
        const ids = [...board.sel];
        if (ids.length !== 1) {
            return panel('Eigenschaften', hintText(ids.length > 1
                ? `${ids.length} Elemente ausgewählt. Entf löscht sie, Pfeiltasten verschieben sie.`
                : 'Wähle ein Symbol oder einen Pfeil aus (Klick oder Tab), um den Text zu bearbeiten. Doppelklick oder Enter springt direkt in die Eigenschaften.'));
        }
        const id = ids[0];
        const node = board.model.nodes.find((n) => n.id === id);
        if (node) {
            const set = (fn, key, inspector = false) => board.mutate((m) => {
                const n = m.nodes.find((x) => x.id === id);
                if (n) {
                    fn(n);
                }
            }, { key, inspector });

            return panel(`${NODE_LABEL[node.type]} bearbeiten`, h('div', { class: 'dg-fields' },
                field('Text', textArea(node.text, (v) => set((n) => { n.text = multiline(v, 200); }, `t:${id}`), { rows: 3, maxlength: 200 })),
                field('Symbol', selectInput(NODE_TYPES.map((t) => [t, NODE_LABEL[t]]), node.type, (v) => set((n) => { n.type = v; }, null, true)))),
            hintText('Tipp: Bei einer Verzweigung beschriften die Pfeile die Antworten (Ja/Nein).'));
        }
        const edge = board.model.edges.find((e) => e.id === id);
        if (!edge) {
            return null;
        }
        const setEdge = (fn, key, inspector = false) => board.mutate((m) => {
            const e = m.edges.find((x) => x.id === id);
            if (e) {
                fn(e);
            }
        }, { key, inspector });

        return panel(`Pfeil: ${labelOf(board.model, edge.from)} → ${labelOf(board.model, edge.to)}`,
            h('div', { class: 'dg-fields' },
                field('Beschriftung', textInput(edge.label, (v) => setEdge((e) => { e.label = line(v, 40); }, `l:${id}`), { maxlength: 40, placeholder: 'z. B. Ja / Nein' }))),
            h('div', { class: 'dg-actions' },
                smallButton('Ja', () => setEdge((e) => { e.label = 'Ja'; }, null, true)),
                smallButton('Nein', () => setEdge((e) => { e.label = 'Nein'; }, null, true)),
                smallButton('⇄ Richtung umkehren', () => setEdge((e) => { [e.from, e.to] = [e.to, e.from]; }, null, true))));
    },
};

export const FlowchartWidget = boardWidget(flowchartPlugin);
registerAnswerWidget('flowchart', FlowchartWidget);

void SOFT;
