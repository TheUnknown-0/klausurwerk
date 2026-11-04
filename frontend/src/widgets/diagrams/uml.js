/**
 * UML-Klassendiagramm (Antworttyp "uml"). Format: docs/DATENFORMATE.md, 2.8.
 *
 * Beziehungen: from = Ausgangsklasse (Vererbung/Realisierung: Unterklasse; Aggregation/
 * Komposition: das Ganze), to = Zielklasse (Pfeilspitze, Dreieck). Optional je Beziehung:
 * route = "orthogonal" | "straight".
 */
import { h, icon } from '../../lib/dom.js';
import { registerAnswerWidget } from '../../lib/widgets.js';
import {
    arrowInset, arrowShape, bbox, center, endAngle, freePosition, newId, pointAlong, polylineLength, polylinePath, routeOrthogonal,
    routeSelfLoop, routeStraight, startAngle, textWidth, trimEnd, trimStart,
} from './core/geometry.js';
import { boardWidget } from './core/widget.js';
import { bool, line, num, pick, rows, uniqueId } from './core/model-util.js';
import { checkInput, field, hintText, panel, selectInput, smallButton, textInput } from './core/form.js';
import { INK, PAPER, SOFT, path, polygon, s, text } from './core/svg.js';

export const KINDS = ['class', 'abstract', 'interface', 'enum'];
export const RELATIONS = ['association', 'directed', 'aggregation', 'composition', 'inheritance', 'realization', 'dependency'];
export const VISIBILITY = ['-', '+', '#', '~'];

export const KIND_LABEL = { class: 'Klasse', abstract: 'Abstrakte Klasse', interface: 'Interface', enum: 'Enum' };
export const RELATION_LABEL = {
    association: 'Assoziation', directed: 'Gerichtete Assoziation', aggregation: 'Aggregation', composition: 'Komposition',
    inheritance: 'Vererbung', realization: 'Realisierung', dependency: 'Abhängigkeit',
};
const RELATION_HINT = {
    association: 'Assoziation: Klicke die erste Klasse, dann die zweite.',
    directed: 'Gerichtete Assoziation: Klicke die Ausgangsklasse, dann die Zielklasse (Pfeil).',
    aggregation: 'Aggregation: Klicke zuerst das Ganze (Raute), dann den Teil.',
    composition: 'Komposition: Klicke zuerst das Ganze (gefüllte Raute), dann den Teil.',
    inheritance: 'Vererbung: Klicke zuerst die Unterklasse, dann die Oberklasse.',
    realization: 'Realisierung: Klicke zuerst die Klasse, dann das Interface.',
    dependency: 'Abhängigkeit: Klicke die abhängige Klasse, dann die Klasse, von der sie abhängt.',
};

const FONT = 13;
const ROW = 18;
const PAD = 9;
const MIN_W = 130;

// ---------------------------------------------------------------------------
// Modell
// ---------------------------------------------------------------------------

export function normalizeUml(content) {
    const ids = new Set();
    const classes = rows(content?.classes, 60).map((c) => ({
        id: uniqueId(c.id, ids, 'c'),
        name: line(c.name, 80),
        kind: pick(c.kind, KINDS, 'class'),
        x: num(c.x),
        y: num(c.y),
        attributes: rows(c.attributes, 60).map((a) => ({
            visibility: pick(a.visibility, VISIBILITY, '-'), name: line(a.name, 80), type: line(a.type, 80), static: bool(a.static),
        })),
        methods: rows(c.methods, 60).map((m) => ({
            visibility: pick(m.visibility, VISIBILITY, '+'),
            name: line(m.name, 80),
            params: line(m.params, 200),
            return_type: line(m.return_type, 80),
            static: bool(m.static),
            abstract: bool(m.abstract),
        })),
    }));
    const classIds = new Set(classes.map((c) => c.id));
    const relIds = new Set();
    const relations = [];
    for (const r of rows(content?.relations, 200)) {
        if (!classIds.has(r.from) || !classIds.has(r.to)) {
            continue;
        }
        relations.push({
            id: uniqueId(r.id, relIds, 'r'),
            from: r.from,
            to: r.to,
            kind: pick(r.kind, RELATIONS, 'association'),
            label: line(r.label, 80),
            mult_from: line(r.mult_from, 12),
            mult_to: line(r.mult_to, 12),
            route: pick(r.route, ['orthogonal', 'straight'], 'orthogonal'),
        });
    }

    return { classes, relations };
}

export const attributeLabel = (a) => `${a.visibility} ${a.name || '…'}${a.type ? `: ${a.type}` : ''}`;
export const methodLabel = (m) => `${m.visibility} ${m.name || '…'}(${m.params})${m.return_type ? `: ${m.return_type}` : ''}`;

/** Maße einer Klasse. */
export function classBox(c) {
    const stereo = c.kind === 'interface' ? '«interface»' : c.kind === 'enum' ? '«enumeration»' : '';
    const nameH = (stereo ? 40 : 28);
    const attrLines = c.attributes.map(attributeLabel);
    const methLines = c.methods.map(methodLabel);
    const attrH = Math.max(22, attrLines.length * ROW + 8);
    const methH = Math.max(22, methLines.length * ROW + 8);
    const w = Math.max(MIN_W, ...[c.name || 'Klasse', stereo, ...attrLines, ...methLines].map((t) => textWidth(t, FONT) + 2 * PAD));
    const width = Math.ceil(w / 10) * 10;

    return { x: c.x, y: c.y, w: width, h: nameH + attrH + methH, nameH, attrH, methH, stereo, attrLines, methLines };
}

const rectOf = (c) => {
    const b = classBox(c);

    return { x: b.x, y: b.y, w: b.w, h: b.h };
};

/** Linienzug einer Beziehung. */
export function relationPath(model, r) {
    const from = model.classes.find((c) => c.id === r.from);
    const to = model.classes.find((c) => c.id === r.to);
    if (!from || !to) {
        return null;
    }
    const a = rectOf(from);
    const b = rectOf(to);
    if (r.from === r.to) {
        return routeSelfLoop(a);
    }

    return r.route === 'straight' ? routeStraight(a, b) : routeOrthogonal(a, b);
}

// ---------------------------------------------------------------------------
// Darstellung
// ---------------------------------------------------------------------------

function drawRelation(model, r, rc) {
    const pts = relationPath(model, r);
    if (!pts || pts.length < 2) {
        return;
    }
    const dashed = r.kind === 'realization' || r.kind === 'dependency';
    const headKind = { directed: 'open', inheritance: 'triangle', realization: 'triangle', dependency: 'open' }[r.kind] ?? null;
    const tailKind = r.kind === 'aggregation' || r.kind === 'composition' ? 'diamond' : null;
    let body = pts;
    if (headKind) {
        body = trimEnd(body, arrowInset(headKind));
    }
    if (tailKind) {
        body = trimStart(body, arrowInset('diamond'));
    }
    const g = s('g', { class: 'dg-edge' });
    g.append(s('path', { d: polylinePath(pts), fill: 'none', stroke: 'transparent', 'stroke-width': 14, class: 'dg-hit' }));
    g.append(path(polylinePath(body), { class: 'dg-shape', 'stroke-dasharray': dashed ? '7 5' : undefined }));
    if (headKind) {
        const poly = arrowShape(headKind, pts[pts.length - 1], endAngle(pts));
        g.append(headKind === 'open'
            ? path(`M${poly[0].x} ${poly[0].y}L${poly[1].x} ${poly[1].y}L${poly[2].x} ${poly[2].y}`, { class: 'dg-shape' })
            : polygon(poly, { class: 'dg-shape' }));
    }
    if (tailKind) {
        g.append(polygon(arrowShape('diamond', pts[0], startAngle(pts)), { class: 'dg-shape', fill: r.kind === 'composition' ? INK : PAPER }));
    }

    // Beschriftungen
    const len = polylineLength(pts);
    const near = (end, offset, side) => {
        const p = end === 'from' ? pointAlong(pts, Math.min(offset, len / 2)) : pointAlong(pts, Math.max(len - offset, len / 2));
        const nx = -Math.sin(p.angle) * side;
        const ny = Math.cos(p.angle) * side;

        return { x: p.x + nx, y: p.y + ny };
    };
    if (r.mult_from) {
        const p = near('from', 22, -13);
        g.append(text(r.mult_from, p.x, p.y, { size: 12 }));
    }
    if (r.mult_to) {
        const p = near('to', 22, -13);
        g.append(text(r.mult_to, p.x, p.y, { size: 12 }));
    }
    if (r.label) {
        const mid = pointAlong(pts, len / 2);
        const p = { x: mid.x - Math.sin(mid.angle) * 12, y: mid.y + Math.cos(mid.angle) * 12 };
        g.append(text(r.label, p.x, p.y, { size: 12, italic: false }));
    }

    const label = `${RELATION_LABEL[r.kind]} von ${nameOf(model, r.from)} nach ${nameOf(model, r.to)}`;
    rc.mark(g, r.id, label);
    rc.layer.append(g);
}

const nameOf = (model, id) => model.classes.find((c) => c.id === id)?.name || 'unbenannte Klasse';

function drawClass(c, rc) {
    const b = classBox(c);
    const g = s('g', { class: 'dg-node' });
    g.append(s('rect', { x: b.x, y: b.y, width: b.w, height: b.h, fill: PAPER, stroke: INK, 'stroke-width': 1.6, class: 'dg-shape' }));
    g.append(path(`M${b.x} ${b.y + b.nameH}H${b.x + b.w}M${b.x} ${b.y + b.nameH + b.attrH}H${b.x + b.w}`));
    const nameY = b.stereo ? b.y + 28 : b.y + b.nameH / 2;
    if (b.stereo) {
        g.append(text(b.stereo, b.x + b.w / 2, b.y + 12, { size: 12 }));
    }
    g.append(text(c.name || 'Klasse', b.x + b.w / 2, nameY, {
        weight: 'bold', italic: c.kind === 'abstract', fill: c.name ? INK : SOFT,
    }));
    c.attributes.forEach((a, i) => {
        g.append(text(b.attrLines[i], b.x + PAD, b.y + b.nameH + 4 + ROW * i + ROW / 2, { anchor: 'start', underline: a.static }));
    });
    c.methods.forEach((m, i) => {
        g.append(text(b.methLines[i], b.x + PAD, b.y + b.nameH + b.attrH + 4 + ROW * i + ROW / 2, { anchor: 'start', underline: m.static, italic: m.abstract }));
    });
    rc.mark(g, c.id, `${KIND_LABEL[c.kind]} ${c.name || '(ohne Namen)'}, ${c.attributes.length} Attribute, ${c.methods.length} Methoden`);
    rc.layer.append(g);
}

// ---------------------------------------------------------------------------
// Bearbeitung
// ---------------------------------------------------------------------------

function addClass(board, kind) {
    board.mutate((m) => {
        const used = m.classes.map((c) => c.id);
        const id = newId('c', used);
        const rects = m.classes.map(rectOf);
        const at = board.viewCenter();
        const pos = freePosition(rects, { w: MIN_W, h: 80 }, { x: at.x - 65, y: at.y - 40 }, 40);
        m.classes.push({
            id, name: kind === 'enum' ? 'Farbe' : kind === 'interface' ? 'Schnittstelle' : `Klasse${m.classes.length + 1}`, kind, x: pos.x, y: pos.y,
            attributes: [], methods: [],
        });
        board.sel = new Set([id]);
    }, { inspector: true });
    board.refreshInspector();
    board.focusInspector();
}

function inspectClass(board, cid) {
    const get = (m) => m.classes.find((c) => c.id === cid);
    const cls = get(board.model);
    if (!cls) {
        return null;
    }
    const set = (fn, key, inspector = false) => board.mutate((m) => {
        const c = get(m);
        if (c) {
            fn(c);
        }
    }, { key, inspector });

    const kinds = KINDS.filter((k) => board.config.allow_interfaces !== false || k !== 'interface' || cls.kind === 'interface');
    const head = h('div', { class: 'dg-fields' },
        field('Name', textInput(cls.name, (v) => set((c) => { c.name = line(v, 80); }, `name:${cid}`), { maxlength: 80 })),
        field('Art', selectInput(kinds.map((k) => [k, KIND_LABEL[k]]), cls.kind, (v) => set((c) => { c.kind = v; }, null, true))));

    const memberList = (title, list, build, add) => {
        const wrap = h('div', { class: 'dg-members' }, h('h4', { class: 'dg-sub' }, title));
        list.forEach((item, i) => wrap.append(build(item, i)));
        wrap.append(smallButton(add.label, () => set(add.fn, null, true)));

        return wrap;
    };
    const visSel = (value, onChange, label) => selectInput(VISIBILITY.map((v) => [v, v]), value, onChange, { 'aria-label': label, class: 'input dg-input dg-vis' });
    const removeBtn = (label, onClick) => smallButton(icon('x'), onClick, { 'aria-label': label, title: label });

    const attrs = memberList('Attribute', cls.attributes, (a, i) => h('div', { class: 'dg-member' },
        visSel(a.visibility, (v) => set((c) => { c.attributes[i].visibility = v; }), `Sichtbarkeit Attribut ${i + 1}`),
        textInput(a.name, (v) => set((c) => { c.attributes[i].name = line(v, 80); }, `an:${cid}:${i}`), { 'aria-label': `Name Attribut ${i + 1}`, placeholder: 'name' }),
        textInput(a.type, (v) => set((c) => { c.attributes[i].type = line(v, 80); }, `at:${cid}:${i}`), { 'aria-label': `Typ Attribut ${i + 1}`, placeholder: 'Typ' }),
        checkInput('static', a.static, (v) => set((c) => { c.attributes[i].static = v; }), { 'aria-label': `Attribut ${i + 1} ist static` }),
        removeBtn(`Attribut ${i + 1} entfernen`, () => set((c) => { c.attributes.splice(i, 1); }, null, true))),
    { label: '+ Attribut', fn: (c) => { c.attributes.push({ visibility: '-', name: '', type: '', static: false }); } });

    const methods = memberList(cls.kind === 'enum' ? 'Methoden' : 'Methoden', cls.methods, (m, i) => h('div', { class: 'dg-member' },
        visSel(m.visibility, (v) => set((c) => { c.methods[i].visibility = v; }), `Sichtbarkeit Methode ${i + 1}`),
        textInput(m.name, (v) => set((c) => { c.methods[i].name = line(v, 80); }, `mn:${cid}:${i}`), { 'aria-label': `Name Methode ${i + 1}`, placeholder: 'name' }),
        textInput(m.params, (v) => set((c) => { c.methods[i].params = line(v, 200); }, `mp:${cid}:${i}`), { 'aria-label': `Parameter Methode ${i + 1}`, placeholder: 'int a, int b' }),
        textInput(m.return_type, (v) => set((c) => { c.methods[i].return_type = line(v, 80); }, `mr:${cid}:${i}`), { 'aria-label': `Rückgabetyp Methode ${i + 1}`, placeholder: 'Rückgabe' }),
        checkInput('static', m.static, (v) => set((c) => { c.methods[i].static = v; }), { 'aria-label': `Methode ${i + 1} ist static` }),
        checkInput('abstrakt', m.abstract, (v) => set((c) => { c.methods[i].abstract = v; }), { 'aria-label': `Methode ${i + 1} ist abstrakt` }),
        removeBtn(`Methode ${i + 1} entfernen`, () => set((c) => { c.methods.splice(i, 1); }, null, true))),
    { label: '+ Methode', fn: (c) => { c.methods.push({ visibility: '+', name: '', params: '', return_type: '', static: false, abstract: false }); } });

    return panel(`${KIND_LABEL[cls.kind]} bearbeiten`, head, attrs, methods,
        hintText('Sichtbarkeit: + public, - private, # protected, ~ package. static wird unterstrichen, abstrakte Methoden kursiv.'));
}

function inspectRelation(board, rid) {
    const get = (m) => m.relations.find((r) => r.id === rid);
    const rel = get(board.model);
    if (!rel) {
        return null;
    }
    const set = (fn, key, inspector = false) => board.mutate((m) => {
        const r = get(m);
        if (r) {
            fn(r);
        }
    }, { key, inspector });

    return panel(`${RELATION_LABEL[rel.kind]}: ${nameOf(board.model, rel.from)} → ${nameOf(board.model, rel.to)}`,
        h('div', { class: 'dg-fields' },
            field('Art', selectInput(RELATIONS.map((k) => [k, RELATION_LABEL[k]]), rel.kind, (v) => set((r) => { r.kind = v; }, null, true))),
            field('Beschriftung', textInput(rel.label, (v) => set((r) => { r.label = line(v, 80); }, `rl:${rid}`), { maxlength: 80 })),
            field(`Multiplizität bei ${nameOf(board.model, rel.from)}`, textInput(rel.mult_from, (v) => set((r) => { r.mult_from = line(v, 12); }, `rf:${rid}`), { maxlength: 12, placeholder: '1, *, 0..1' })),
            field(`Multiplizität bei ${nameOf(board.model, rel.to)}`, textInput(rel.mult_to, (v) => set((r) => { r.mult_to = line(v, 12); }, `rt:${rid}`), { maxlength: 12, placeholder: '1, *, 1..*' })),
            field('Linienführung', selectInput([['orthogonal', 'rechtwinklig'], ['straight', 'gerade']], rel.route, (v) => set((r) => { r.route = v; })))),
        h('div', { class: 'dg-actions' },
            smallButton('⇄ Richtung umkehren', () => set((r) => { [r.from, r.to] = [r.to, r.from]; [r.mult_from, r.mult_to] = [r.mult_to, r.mult_from]; }, null, true))),
        hintText('Die Pfeilspitze (bzw. das Dreieck) sitzt an der Zielklasse, die Raute bei Aggregation und Komposition an der Ausgangsklasse.'));
}

export const umlPlugin = {
    name: 'uml',
    label: 'UML-Klassendiagramm',
    normalize: (content) => normalizeUml(content),
    ids: (m) => [...m.classes.map((c) => c.id), ...m.relations.map((r) => r.id)],
    movable: (m, id) => m.classes.find((c) => c.id === id) ?? null,
    centerOf: (m, id) => {
        const c = m.classes.find((x) => x.id === id);

        return c ? center(rectOf(c)) : null;
    },
    bounds: (m) => bbox(m.classes.map(rectOf)),
    render(model, rc) {
        for (const r of model.relations) {
            drawRelation(model, r, rc);
        }
        for (const c of model.classes) {
            drawClass(c, rc);
        }
    },
    tools(board) {
        const allowIf = board.config.allow_interfaces !== false;
        const list = [
            { id: 'add-class', group: 'Klassen', label: '＋ Klasse', title: 'Neue Klasse einfügen', run: (b) => addClass(b, 'class') },
            { id: 'add-abstract', group: 'Klassen', label: '＋ Abstrakt', title: 'Neue abstrakte Klasse einfügen', run: (b) => addClass(b, 'abstract') },
            allowIf ? { id: 'add-interface', group: 'Klassen', label: '＋ Interface', title: 'Neues Interface einfügen', run: (b) => addClass(b, 'interface') } : null,
            { id: 'add-enum', group: 'Klassen', label: '＋ Enum', title: 'Neues Enum einfügen', run: (b) => addClass(b, 'enum') },
        ].filter(Boolean);
        for (const kind of RELATIONS) {
            if (!allowIf && kind === 'realization') {
                continue;
            }
            list.push({
                id: `rel-${kind}`, group: 'Beziehungen', label: RELATION_LABEL[kind], title: RELATION_HINT[kind], toggle: true, connect: true,
                kind, hint: RELATION_HINT[kind], hintTarget: 'Wähle jetzt die Zielklasse (Klick oder Enter).',
            });
        }

        return list;
    },
    connect(board, from, to, tool) {
        if (!tool?.kind) {
            return { error: 'Wähle zuerst eine Beziehungsart in der Werkzeugleiste.' };
        }
        const m = board.model;
        if (!m.classes.some((c) => c.id === from) || !m.classes.some((c) => c.id === to)) {
            return null;
        }
        if (from === to && ['inheritance', 'realization', 'aggregation', 'composition'].includes(tool.kind)) {
            return { error: `${RELATION_LABEL[tool.kind]} auf dieselbe Klasse ist nicht sinnvoll.` };
        }
        if (m.relations.some((r) => r.kind === tool.kind && r.from === from && r.to === to)) {
            return { error: 'Diese Beziehung gibt es schon.' };
        }
        const id = newId('r', m.relations.map((r) => r.id));
        m.relations.push({
            id, from, to, kind: tool.kind, label: '', mult_from: '', mult_to: '', route: 'orthogonal',
        });

        return { ok: true, select: id, message: `${RELATION_LABEL[tool.kind]} von ${nameOf(m, from)} nach ${nameOf(m, to)} angelegt.` };
    },
    remove(model, ids) {
        const set = new Set(ids);
        const before = model.classes.length + model.relations.length;
        model.classes = model.classes.filter((c) => !set.has(c.id));
        const alive = new Set(model.classes.map((c) => c.id));
        model.relations = model.relations.filter((r) => !set.has(r.id) && alive.has(r.from) && alive.has(r.to));

        return model.classes.length + model.relations.length < before;
    },
    describe(model, id) {
        const c = model.classes.find((x) => x.id === id);
        if (c) {
            return `${KIND_LABEL[c.kind]} ${c.name}, ${c.attributes.length} Attribute, ${c.methods.length} Methoden`;
        }
        const r = model.relations.find((x) => x.id === id);

        return r ? `${RELATION_LABEL[r.kind]} von ${nameOf(model, r.from)} nach ${nameOf(model, r.to)}` : '';
    },
    inspector(board) {
        const ids = [...board.sel];
        if (ids.length === 1) {
            return board.model.classes.some((c) => c.id === ids[0]) ? inspectClass(board, ids[0]) : inspectRelation(board, ids[0]);
        }

        return panel('Eigenschaften', hintText(ids.length > 1 ? `${ids.length} Elemente ausgewählt. Entf löscht sie, Pfeiltasten verschieben sie.` : 'Wähle eine Klasse oder Beziehung aus (Klick oder Tab), um Name, Attribute und Methoden zu bearbeiten. Doppelklick oder Enter springt direkt in die Eigenschaften.'));
    },
};

export const UmlWidget = boardWidget(umlPlugin);
registerAnswerWidget('uml', UmlWidget);

