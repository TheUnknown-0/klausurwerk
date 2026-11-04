/**
 * ER-Diagramm in Chen-Notation (Antworttyp "er"). Format: docs/DATENFORMATE.md, 2.14.
 * x/y sind hier die Mittelpunkte der Symbole. Das Attribut-Feld "owner" verweist auf eine
 * Entität oder eine Beziehung.
 */
import { h } from '../../lib/dom.js';
import { registerAnswerWidget } from '../../lib/widgets.js';
import {
    bbox, diamondBorderPoint, ellipseBorderPoint, freePosition, newId, rectBorderPoint, textWidth,
} from './core/geometry.js';
import { boardWidget } from './core/widget.js';
import { bool, line, num, pick, rows, uniqueId } from './core/model-util.js';
import { checkInput, field, hintText, panel, selectInput, smallButton, textInput } from './core/form.js';
import { INK, PAPER, SOFT, path, polygon, s, text } from './core/svg.js';

export const CARDINALITIES = ['1', 'n', 'm', '0..1', '0..n', '1..n'];

export function normalizeEr(content) {
    const ids = new Set();
    const entities = rows(content?.entities, 30).map((e) => ({
        id: uniqueId(e.id, ids, 'e'), name: line(e.name, 60), x: num(e.x), y: num(e.y), weak: bool(e.weak),
    }));
    const entityIds = new Set(entities.map((e) => e.id));
    const relationships = rows(content?.relationships, 50).map((r) => ({
        id: uniqueId(r.id, ids, 'r'),
        name: line(r.name, 60),
        x: num(r.x),
        y: num(r.y),
        ends: rows(r.ends, 4).filter((end) => entityIds.has(end.entity)).map((end) => ({
            entity: end.entity, cardinality: pick(end.cardinality, CARDINALITIES, '1'),
        })),
    }));
    const owners = new Set([...entityIds, ...relationships.map((r) => r.id)]);
    const attributes = [];
    for (const a of rows(content?.attributes, 150)) {
        if (owners.has(a.owner)) {
            attributes.push({ id: uniqueId(a.id, ids, 'a'), owner: a.owner, name: line(a.name, 60), key: bool(a.key), x: num(a.x), y: num(a.y) });
        }
    }

    return { entities, attributes, relationships };
}

// ---------------------------------------------------------------------------
// Maße und Ränder
// ---------------------------------------------------------------------------

export function entitySize(e) {
    return { w: Math.max(100, Math.ceil((textWidth(e.name || 'Entität', 14) + 36) / 10) * 10), h: 46 };
}

export function relSize(r) {
    return { w: Math.max(120, Math.ceil((textWidth(r.name || 'Beziehung', 13) * 1.55 + 36) / 10) * 10), h: 70 };
}

export function attrSize(a) {
    return { w: Math.max(80, Math.ceil((textWidth(a.name || 'Attribut', 13) + 30) / 10) * 10), h: 36 };
}

function find(model, id) {
    const e = model.entities.find((x) => x.id === id);
    if (e) {
        return { kind: 'entity', obj: e, size: entitySize(e) };
    }
    const r = model.relationships.find((x) => x.id === id);
    if (r) {
        return { kind: 'relationship', obj: r, size: relSize(r) };
    }
    const a = model.attributes.find((x) => x.id === id);

    return a ? { kind: 'attribute', obj: a, size: attrSize(a) } : null;
}

const rectOfItem = (item) => ({ x: item.obj.x - item.size.w / 2, y: item.obj.y - item.size.h / 2, w: item.size.w, h: item.size.h });

/** Randpunkt eines Symbols in Richtung p. */
function borderPoint(item, p) {
    const c = { x: item.obj.x, y: item.obj.y };
    if (item.kind === 'entity') {
        return rectBorderPoint(rectOfItem(item), p);
    }
    if (item.kind === 'relationship') {
        return diamondBorderPoint(c, item.size.w / 2, item.size.h / 2, p);
    }

    return ellipseBorderPoint(c, item.size.w / 2, item.size.h / 2, p);
}

/** Verbindungslinie zwischen zwei Symbolen. */
function connection(a, b) {
    const ca = { x: a.obj.x, y: a.obj.y };
    const cb = { x: b.obj.x, y: b.obj.y };

    return [borderPoint(a, cb), borderPoint(b, ca)];
}

// ---------------------------------------------------------------------------
// Darstellung
// ---------------------------------------------------------------------------

function drawEntity(model, e, rc) {
    const size = entitySize(e);
    const g = s('g', { class: 'dg-node' });
    const x = e.x - size.w / 2;
    const y = e.y - size.h / 2;
    g.append(s('rect', { x, y, width: size.w, height: size.h, fill: PAPER, stroke: INK, 'stroke-width': 1.6, class: 'dg-shape' }));
    if (e.weak) {
        g.append(s('rect', { x: x + 5, y: y + 5, width: size.w - 10, height: size.h - 10, fill: 'none', stroke: INK, 'stroke-width': 1.4 }));
    }
    g.append(text(e.name || 'Entität', e.x, e.y, { size: 14, weight: 'bold', fill: e.name ? INK : SOFT }));
    rc.mark(g, e.id, `${e.weak ? 'Schwache Entität' : 'Entität'} ${e.name || '(ohne Namen)'}`);
    rc.layer.append(g);
}

function drawRelationship(model, r, rc) {
    const size = relSize(r);
    const g = s('g', { class: 'dg-node' });
    const pts = [
        { x: r.x, y: r.y - size.h / 2 }, { x: r.x + size.w / 2, y: r.y }, { x: r.x, y: r.y + size.h / 2 }, { x: r.x - size.w / 2, y: r.y },
    ];
    g.append(polygon(pts, { class: 'dg-shape', 'stroke-width': 1.6 }));
    g.append(text(r.name || 'Beziehung', r.x, r.y, { size: 13, fill: r.name ? INK : SOFT }));
    rc.mark(g, r.id, `Beziehung ${r.name || '(ohne Namen)'}`);
    rc.layer.append(g);
}

function drawAttribute(model, a, rc) {
    const size = attrSize(a);
    const g = s('g', { class: 'dg-node' });
    g.append(s('ellipse', { cx: a.x, cy: a.y, rx: size.w / 2, ry: size.h / 2, fill: PAPER, stroke: INK, 'stroke-width': 1.5, class: 'dg-shape' }));
    g.append(text(a.name || 'Attribut', a.x, a.y, { size: 13, underline: a.key, fill: a.name ? INK : SOFT }));
    rc.mark(g, a.id, `${a.key ? 'Schlüsselattribut' : 'Attribut'} ${a.name || '(ohne Namen)'}`);
    rc.layer.append(g);
}

function drawLinks(model, rc) {
    // Attribut -> Besitzer
    for (const a of model.attributes) {
        const from = find(model, a.id);
        const to = find(model, a.owner);
        if (from && to) {
            const [p, q] = connection(from, to);
            rc.layer.append(path(`M${p.x} ${p.y}L${q.x} ${q.y}`, { 'stroke-width': 1.3 }));
        }
    }
    // Beziehung -> Entität mit Kardinalität
    for (const r of model.relationships) {
        const rel = find(model, r.id);
        r.ends.forEach((end, i) => {
            const ent = find(model, end.entity);
            if (!ent) {
                return;
            }
            const [p, q] = connection(rel, ent);
            const g = s('g', { class: 'dg-edge' });
            g.append(s('path', { d: `M${p.x} ${p.y}L${q.x} ${q.y}`, stroke: 'transparent', 'stroke-width': 14, fill: 'none', class: 'dg-hit' }));
            g.append(path(`M${p.x} ${p.y}L${q.x} ${q.y}`, { class: 'dg-shape', 'stroke-width': ent.obj.weak ? 2.6 : 1.5 }));
            const dx = p.x - q.x;
            const dy = p.y - q.y;
            const len = Math.hypot(dx, dy) || 1;
            const lx = q.x + (dx / len) * 22 + (-dy / len) * 11;
            const ly = q.y + (dy / len) * 22 + (dx / len) * 11;
            const label = text(end.cardinality, lx, ly, { size: 13, weight: 'bold' });
            label.setAttribute('stroke', '#ffffff');
            label.setAttribute('stroke-width', '3');
            label.setAttribute('paint-order', 'stroke');
            g.append(label);
            rc.mark(g, `${r.id}:${i}`, `Verbindung ${r.name || 'Beziehung'} zu ${ent.obj.name || 'Entität'}, Kardinalität ${end.cardinality}`);
            rc.layer.append(g);
        });
    }
}

// ---------------------------------------------------------------------------
// Bearbeitung
// ---------------------------------------------------------------------------

function addSymbol(board, kind) {
    board.mutate((m) => {
        const used = [...m.entities, ...m.relationships, ...m.attributes].map((x) => x.id);
        const prefix = kind === 'entity' ? 'e' : kind === 'relationship' ? 'r' : 'a';
        const id = newId(prefix, used);
        const owner = kind === 'attribute' ? [...board.sel].map((s2) => find(m, s2)).find((it) => it && it.kind !== 'attribute') : null;
        const size = kind === 'entity' ? { w: 110, h: 46 } : kind === 'relationship' ? { w: 130, h: 70 } : { w: 90, h: 36 };
        const all = [...m.entities, ...m.relationships, ...m.attributes].map((x) => find(m, x.id)).map(rectOfItem);
        const at = owner ? { x: owner.obj.x + 20, y: owner.obj.y - 110 } : board.viewCenter();
        const pos = freePosition(all, size, { x: at.x - size.w / 2, y: at.y - size.h / 2 }, 20, 12);
        const cx = pos.x + size.w / 2;
        const cy = pos.y + size.h / 2;
        if (kind === 'entity') {
            m.entities.push({ id, name: `Entität${m.entities.length + 1}`, x: cx, y: cy, weak: false });
        } else if (kind === 'relationship') {
            m.relationships.push({ id, name: '', x: cx, y: cy, ends: [] });
        } else {
            if (!owner) {
                return;
            }
            m.attributes.push({ id, owner: owner.obj.id, name: '', key: false, x: cx, y: cy });
        }
        board.sel = new Set([id]);
    }, { inspector: true });
    board.refreshInspector();
    board.focusInspector();
}

const nameOf = (model, id) => {
    const item = find(model, id);

    return item ? item.obj.name || (item.kind === 'entity' ? 'Entität' : item.kind === 'relationship' ? 'Beziehung' : 'Attribut') : '?';
};

export const erPlugin = {
    name: 'er',
    label: 'ER-Diagramm (Chen-Notation)',
    normalize: (content) => normalizeEr(content),
    ids: (m) => [
        ...m.entities.map((e) => e.id), ...m.relationships.map((r) => r.id), ...m.attributes.map((a) => a.id),
        ...m.relationships.flatMap((r) => r.ends.map((_, i) => `${r.id}:${i}`)),
    ],
    movable: (m, id) => find(m, id)?.obj ?? null,
    centerOf: (m, id) => {
        const item = find(m, id);

        return item ? { x: item.obj.x, y: item.obj.y } : null;
    },
    bounds(m) {
        const all = [...m.entities, ...m.relationships, ...m.attributes].map((x) => rectOfItem(find(m, x.id)));

        return bbox(all);
    },
    render(model, rc) {
        drawLinks(model, rc);
        model.entities.forEach((e) => drawEntity(model, e, rc));
        model.relationships.forEach((r) => drawRelationship(model, r, rc));
        model.attributes.forEach((a) => drawAttribute(model, a, rc));
    },
    tools() {
        return [
            { id: 'add-entity', group: 'Symbole', label: '＋ Entität', title: 'Entität (Rechteck) einfügen', run: (b) => addSymbol(b, 'entity') },
            { id: 'add-relationship', group: 'Symbole', label: '＋ Beziehung', title: 'Beziehung (Raute) einfügen', run: (b) => addSymbol(b, 'relationship') },
            {
                id: 'add-attribute', group: 'Symbole', label: '＋ Attribut', title: 'Attribut (Ellipse) zur gewählten Entität oder Beziehung',
                run: (b) => {
                    if (![...b.sel].some((id) => find(b.model, id) && find(b.model, id).kind !== 'attribute')) {
                        b.setHint('Wähle zuerst die Entität oder Beziehung, zu der das Attribut gehört.', true);
                        b.announce('Wähle zuerst die Entität oder Beziehung, zu der das Attribut gehört.');
                        return;
                    }
                    addSymbol(b, 'attribute');
                },
            },
            {
                id: 'link', group: 'Verbinden', label: '— Verbinden', toggle: true, connect: true,
                title: 'Beziehung mit Entität verbinden oder ein Attribut einer anderen Entität zuordnen',
                hint: 'Verbinden: Klicke die Beziehung, dann die Entität (Kardinalität stellst du danach ein). Attribut, dann Entität: ordnet das Attribut neu zu.',
                hintTarget: 'Wähle jetzt die Entität bzw. den Besitzer.',
            },
        ];
    },
    connect(board, from, to) {
        const m = board.model;
        const a = find(m, from);
        const b = find(m, to);
        if (!a || !b || from === to) {
            return a && from === to ? { error: 'Wähle zwei verschiedene Symbole.' } : null;
        }
        const [first, second] = a.kind === 'attribute' || (a.kind === 'entity' && b.kind === 'relationship') ? [b, a] : [a, b];
        if (a.kind === 'attribute' && b.kind !== 'attribute') {
            a.obj.owner = b.obj.id;
            return { ok: true, select: a.obj.id, message: 'Attribut neu zugeordnet.' };
        }
        if (first.kind === 'relationship' && second.kind === 'entity') {
            if (first.obj.ends.length >= 4) {
                return { error: 'Eine Beziehung kann höchstens vier Entitäten verbinden.' };
            }
            if (first.obj.ends.filter((end) => end.entity === second.obj.id).length >= 2) {
                return { error: 'Diese Entität ist schon zweimal mit der Beziehung verbunden.' };
            }
            first.obj.ends.push({ entity: second.obj.id, cardinality: '1' });

            return { ok: true, select: `${first.obj.id}:${first.obj.ends.length - 1}`, message: 'Verbindung angelegt. Stelle die Kardinalität ein.' };
        }

        return { error: 'Verbinde eine Beziehung mit einer Entität, oder ordne ein Attribut zu.' };
    },
    remove(model, ids) {
        const del = new Set(ids.filter((id) => !id.includes(':')));
        const endsToDrop = ids.filter((id) => id.includes(':')).map((id) => id.split(':'));
        let changed = false;
        for (const [rid, idx] of endsToDrop.sort((p, q) => Number(q[1]) - Number(p[1]))) {
            const r = model.relationships.find((x) => x.id === rid);
            if (r && r.ends[Number(idx)]) {
                r.ends.splice(Number(idx), 1);
                changed = true;
            }
        }
        if (del.size > 0) {
            changed = true;
            model.entities = model.entities.filter((e) => !del.has(e.id));
            model.relationships = model.relationships.filter((r) => !del.has(r.id));
            const alive = new Set([...model.entities.map((e) => e.id), ...model.relationships.map((r) => r.id)]);
            for (const r of model.relationships) {
                r.ends = r.ends.filter((end) => alive.has(end.entity));
            }
            model.attributes = model.attributes.filter((a) => !del.has(a.id) && alive.has(a.owner));
        }

        return changed;
    },
    describe(model, id) {
        if (id.includes(':')) {
            const [rid, idx] = id.split(':');
            const r = model.relationships.find((x) => x.id === rid);
            const end = r?.ends[Number(idx)];

            return end ? `Verbindung ${nameOf(model, rid)} zu ${nameOf(model, end.entity)}, Kardinalität ${end.cardinality}` : '';
        }
        const item = find(model, id);

        return item ? `${item.kind === 'entity' ? 'Entität' : item.kind === 'relationship' ? 'Beziehung' : 'Attribut'} ${nameOf(model, id)}` : '';
    },
    inspector(board) {
        const ids = [...board.sel];
        if (ids.length !== 1) {
            return panel('Eigenschaften', hintText(ids.length > 1
                ? `${ids.length} Elemente ausgewählt. Entf löscht sie, Pfeiltasten verschieben sie.`
                : 'Wähle ein Symbol aus (Klick oder Tab), um Namen, Schlüssel und Kardinalitäten zu bearbeiten.'));
        }
        const id = ids[0];
        const m = board.model;
        if (id.includes(':')) {
            return inspectEnd(board, id);
        }
        const item = find(m, id);
        if (!item) {
            return null;
        }
        const set = (fn, key, inspector = false) => board.mutate((model) => {
            const it = find(model, id);
            if (it) {
                fn(it.obj);
            }
        }, { key, inspector });

        if (item.kind === 'entity') {
            const own = m.attributes.filter((a) => a.owner === id);
            return panel('Entität bearbeiten',
                h('div', { class: 'dg-fields' },
                    field('Name', textInput(item.obj.name, (v) => set((o) => { o.name = line(v, 60); }, `n:${id}`), { maxlength: 60 })),
                    checkInput('Schwache Entität (doppelter Rahmen)', item.obj.weak, (v) => set((o) => { o.weak = v; }))),
                ownAttributes(board, id, own));
        }
        if (item.kind === 'relationship') {
            const own = m.attributes.filter((a) => a.owner === id);
            const ends = h('div', { class: 'dg-members' }, h('h4', { class: 'dg-sub' }, 'Verbundene Entitäten'));
            item.obj.ends.forEach((end, i) => ends.append(h('div', { class: 'dg-member' },
                h('span', {}, nameOf(m, end.entity)),
                selectInput(CARDINALITIES.map((c) => [c, c]), end.cardinality, (v) => set((o) => { o.ends[i].cardinality = v; }), { 'aria-label': `Kardinalität zu ${nameOf(m, end.entity)}` }),
                smallButton('✕', () => set((o) => { o.ends.splice(i, 1); }, null, true), { 'aria-label': `Verbindung zu ${nameOf(m, end.entity)} entfernen` }))));
            if (item.obj.ends.length === 0) {
                ends.append(hintText('Noch keine Entität verbunden. Nutze „Verbinden“ in der Werkzeugleiste.'));
            }

            return panel('Beziehung bearbeiten',
                h('div', { class: 'dg-fields' }, field('Name', textInput(item.obj.name, (v) => set((o) => { o.name = line(v, 60); }, `n:${id}`), { maxlength: 60 }))),
                ends, ownAttributes(board, id, own));
        }
        const owners = [...m.entities, ...m.relationships];

        return panel('Attribut bearbeiten', h('div', { class: 'dg-fields' },
            field('Name', textInput(item.obj.name, (v) => set((o) => { o.name = line(v, 60); }, `n:${id}`), { maxlength: 60 })),
            checkInput('Schlüsselattribut (unterstrichen)', item.obj.key, (v) => set((o) => { o.key = v; })),
            field('Gehört zu', selectInput(owners.map((o) => [o.id, o.name || o.id]), item.obj.owner, (v) => set((o) => { o.owner = v; }, null, true)))));
    },
};

function inspectEnd(board, id) {
    const [rid, idx] = id.split(':');
    const m = board.model;
    const r = m.relationships.find((x) => x.id === rid);
    const end = r?.ends[Number(idx)];
    if (!end) {
        return null;
    }

    return panel(`Verbindung: ${nameOf(m, rid)} zu ${nameOf(m, end.entity)}`, h('div', { class: 'dg-fields' },
        field('Kardinalität', selectInput(CARDINALITIES.map((c) => [c, c]), end.cardinality, (v) => board.mutate((model) => {
            const rel = model.relationships.find((x) => x.id === rid);
            if (rel?.ends[Number(idx)]) {
                rel.ends[Number(idx)].cardinality = v;
            }
        }))),
        field('Entität', selectInput(m.entities.map((e) => [e.id, e.name || e.id]), end.entity, (v) => board.mutate((model) => {
            const rel = model.relationships.find((x) => x.id === rid);
            if (rel?.ends[Number(idx)]) {
                rel.ends[Number(idx)].entity = v;
            }
        }, { inspector: true })),
        )));
}

function ownAttributes(board, ownerId, list) {
    const wrap = h('div', { class: 'dg-members' }, h('h4', { class: 'dg-sub' }, 'Attribute'));
    list.forEach((a) => {
        wrap.append(h('div', { class: 'dg-member' },
            textInput(a.name, (v) => board.mutate((model) => {
                const x = model.attributes.find((q) => q.id === a.id);
                if (x) {
                    x.name = line(v, 60);
                }
            }, { key: `an:${a.id}` }), { 'aria-label': 'Attributname', placeholder: 'Attribut' }),
            checkInput('Schlüssel', a.key, (v) => board.mutate((model) => {
                const x = model.attributes.find((q) => q.id === a.id);
                if (x) {
                    x.key = v;
                }
            })),
            smallButton('✕', () => board.mutate((model) => {
                model.attributes = model.attributes.filter((q) => q.id !== a.id);
            }, { inspector: true }), { 'aria-label': `Attribut ${a.name || ''} entfernen` })));
    });
    wrap.append(smallButton('+ Attribut', () => {
        board.mutate((model) => {
            const owner = find(model, ownerId);
            const used = [...model.entities, ...model.relationships, ...model.attributes].map((x) => x.id);
            const id = newId('a', used);
            const n = model.attributes.filter((a) => a.owner === ownerId).length;
            model.attributes.push({ id, owner: ownerId, name: '', key: false, x: owner.obj.x + 120 + n * 10, y: owner.obj.y - 90 - n * 40 });
        }, { inspector: true });
    }));

    return wrap;
}

export const ErWidget = boardWidget(erPlugin);
registerAnswerWidget('er', ErWidget);
