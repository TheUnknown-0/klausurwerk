/**
 * Strukturformel (Chemie), Antworttyp "structure". Format: docs/DATENFORMATE.md, 2.15a.
 * Atome sind Knoten (x/y = Mittelpunkt), Bindungen verbinden zwei Atome, dazu freie Beschriftungen
 * und Reaktionspfeile. Unter der Zeichenfläche stehen Summenformel und Ladung je Molekül.
 * Modell und Summenformel: ./core/structure-model.js (ohne DOM, per Node getestet).
 */
import { h } from '../../lib/dom.js';
import { registerAnswerWidget } from '../../lib/widgets.js';
import { bbox, freePosition, newId, snap, textWidth } from './core/geometry.js';
import { boardWidget } from './core/widget.js';
import { checkInput, field, hintText, panel, selectInput, smallButton, textInput } from './core/form.js';
import { INK, PAPER, line, path, polygon, s, text } from './core/svg.js';
import {
    ARROW_KINDS, MAX_ARROWS, MAX_ATOMS, MAX_BONDS, MAX_TEXTS, PALETTE, SIDES, SIDE_LABEL, TEMPLATES,
    chargeText, chemText, molecules, normalizeStructure, symbolOf, template,
} from './core/structure-model.js';

export { normalizeStructure } from './core/structure-model.js';

const ORDER_NAME = { 1: 'Einfachbindung', 2: 'Doppelbindung', 3: 'Dreifachbindung' };
const SIGN = (q) => (q === 0 ? '' : `${Math.abs(q) === 1 ? '' : Math.abs(q)}${q > 0 ? '+' : '−'}`);

// ---------------------------------------------------------------------------
// Hilfen
// ---------------------------------------------------------------------------

const radius = (a) => (a.el.length > 1 ? 14 : 11);
const allIds = (m) => [...m.atoms, ...m.bonds, ...m.texts, ...m.arrows].map((x) => x.id);
const atomById = (m, id) => m.atoms.find((a) => a.id === id) ?? null;

function textSize(t) {
    return { w: Math.max(30, Math.ceil(textWidth(chemText(t.text), 15)) + 12), h: 24 };
}

/** Umgebendes Rechteck eines Atoms, Textes oder Pfeils. */
function itemRect(m, id) {
    const a = atomById(m, id);
    if (a) {
        return { x: a.x - 18, y: a.y - 18, w: 36, h: 36 };
    }
    const t = m.texts.find((x) => x.id === id);
    if (t) {
        const sz = textSize(t);

        return { x: t.x - sz.w / 2, y: t.y - sz.h / 2, w: sz.w, h: sz.h };
    }
    const r = m.arrows.find((x) => x.id === id);

    return r ? { x: r.x - r.len / 2, y: r.y - 14, w: r.len, h: 28 } : null;
}

const allRects = (m) => [...m.atoms, ...m.texts, ...m.arrows].map((x) => itemRect(m, x.id));

/** Beste freie Richtung (Schritte von 30 Grad) für ein neues Atom neben from. */
function freeDirection(m, from, dist = 56) {
    let best = { score: -1, angle: 0 };
    for (let deg = 0; deg < 360; deg += 30) {
        const rad = (deg * Math.PI) / 180;
        const x = from.x + Math.cos(rad) * dist;
        const y = from.y + Math.sin(rad) * dist;
        const score = Math.min(...m.atoms.filter((a) => a.id !== from.id).map((a) => Math.hypot(a.x - x, a.y - y)), 1000);
        if (score > best.score + 1) {
            best = { score, angle: rad };
        }
    }

    return {
        x: snap(from.x + Math.cos(best.angle) * dist), y: snap(from.y + Math.sin(best.angle) * dist), angle: best.angle,
    };
}

const selectedAtoms = (board) => [...board.sel].map((id) => atomById(board.model, id)).filter(Boolean);
const selectedBonds = (board) => [...board.sel].map((id) => board.model.bonds.find((b) => b.id === id)).filter(Boolean);

function warn(board, message) {
    board.setHint(message, true);
    board.announce(message);
}

// ---------------------------------------------------------------------------
// Darstellung
// ---------------------------------------------------------------------------

function drawBond(m, b, rc) {
    const A = atomById(m, b.a);
    const B = atomById(m, b.b);
    if (!A || !B) {
        return;
    }
    const dx = B.x - A.x;
    const dy = B.y - A.y;
    const len = Math.hypot(dx, dy);
    if (len < 1) {
        return;
    }
    const ux = dx / len;
    const uy = dy / len;
    const nx = -uy;
    const ny = ux;
    const p = { x: A.x + ux * (radius(A) + 2), y: A.y + uy * (radius(A) + 2) };
    const q = { x: B.x - ux * (radius(B) + 2), y: B.y - uy * (radius(B) + 2) };
    const g = s('g', { class: 'dg-edge' });
    g.append(s('path', { d: `M${A.x} ${A.y}L${B.x} ${B.y}`, stroke: 'transparent', 'stroke-width': 16, fill: 'none', class: 'dg-hit' }));
    if (len > radius(A) + radius(B) + 4) {
        if (b.order === 1 && b.stereo === 'wedge') {
            g.append(polygon([p, { x: q.x + nx * 5, y: q.y + ny * 5 }, { x: q.x - nx * 5, y: q.y - ny * 5 }], { class: 'dg-shape', fill: INK, 'stroke-width': 1 }));
        } else if (b.order === 1 && b.stereo === 'dash') {
            for (let i = 0; i <= 6; i += 1) {
                const f = i / 6;
                const w = 0.8 + f * 5;
                const cx = p.x + (q.x - p.x) * f;
                const cy = p.y + (q.y - p.y) * f;
                g.append(line(cx - nx * w, cy - ny * w, cx + nx * w, cy + ny * w, { class: 'dg-shape', 'stroke-width': 1.6 }));
            }
        } else {
            const offsets = b.order === 1 ? [0] : b.order === 2 ? [-3.5, 3.5] : [-5, 0, 5];
            for (const o of offsets) {
                g.append(line(p.x + nx * o, p.y + ny * o, q.x + nx * o, q.y + ny * o, { class: 'dg-shape', 'stroke-width': 1.8 }));
            }
        }
    }
    rc.mark(g, b.id, `Bindung ${A.el} ${b.order === 1 ? '-' : b.order === 2 ? '=' : '≡'} ${B.el}, ${ORDER_NAME[b.order]}`);
    rc.layer.append(g);
}

/** Positionen der Striche und Punkte rund um das Atom. */
function drawElectrons(a, g) {
    const r = radius(a) + 5;
    const pos = {
        top: { x: a.x, y: a.y - r, dx: 1, dy: 0 },
        bottom: { x: a.x, y: a.y + r, dx: 1, dy: 0 },
        left: { x: a.x - r, y: a.y, dx: 0, dy: 1 },
        right: { x: a.x + r, y: a.y, dx: 0, dy: 1 },
    };
    for (const side of a.pairs) {
        const c = pos[side];
        g.append(line(c.x - c.dx * 5, c.y - c.dy * 5, c.x + c.dx * 5, c.y + c.dy * 5, { 'stroke-width': 2, 'pointer-events': 'none' }));
    }
    const free = SIDES.filter((side) => !a.pairs.includes(side));
    const sides = free.length > 0 ? free : SIDES;
    for (let i = 0; i < a.radicals; i += 1) {
        const c = pos[sides[i % sides.length]];
        const shift = i >= sides.length ? 6 : 0;
        g.append(s('circle', { cx: c.x + c.dy * shift, cy: c.y + c.dx * shift, r: 2.2, fill: INK, 'pointer-events': 'none' }));
    }
}

function drawAtom(m, a, rc) {
    const g = s('g', { class: 'dg-node' });
    g.append(s('circle', { cx: a.x, cy: a.y, r: 19, fill: 'transparent', stroke: 'none', class: 'dg-hit' }));
    g.append(s('circle', { cx: a.x, cy: a.y, r: radius(a) + 2, fill: PAPER, stroke: 'none', class: 'dg-shape' }));
    g.append(text(a.el, a.x, a.y, { size: 17, weight: 'bold' }));
    if (a.charge !== 0) {
        const c = text(SIGN(a.charge), a.x + radius(a) + 4, a.y - radius(a) - 2, { size: 12, weight: 'bold', anchor: 'start' });
        g.append(c);
    }
    drawElectrons(a, g);
    const info = [`Atom ${a.el}`];
    if (a.charge !== 0) {
        info.push(`Ladung ${chargeText(a.charge)}`);
    }
    if (a.pairs.length > 0) {
        info.push(`${a.pairs.length} freie Elektronenpaare`);
    }
    if (a.radicals > 0) {
        info.push(`${a.radicals} einzelne Elektronen`);
    }
    rc.mark(g, a.id, info.join(', '));
    rc.layer.append(g);
}

function drawText(m, t, rc) {
    const sz = textSize(t);
    const g = s('g', { class: 'dg-node' });
    g.append(s('rect', { x: t.x - sz.w / 2, y: t.y - sz.h / 2, width: sz.w, height: sz.h, rx: 4, fill: 'transparent', stroke: 'none', class: 'dg-shape' }));
    g.append(text(chemText(t.text), t.x, t.y, { size: 15 }));
    rc.mark(g, t.id, `Beschriftung ${t.text}`);
    rc.layer.append(g);
}

function drawArrow(m, r, rc) {
    const g = s('g', { class: 'dg-node' });
    const x1 = r.x - r.len / 2;
    const x2 = r.x + r.len / 2;
    g.append(s('rect', { x: x1 - 4, y: r.y - 14, width: r.len + 8, height: 28, fill: 'transparent', stroke: 'none', class: 'dg-shape' }));
    if (r.kind === 'equilibrium') {
        g.append(path(`M${x1} ${r.y - 4}L${x2} ${r.y - 4}M${x2 - 10} ${r.y - 11}L${x2} ${r.y - 4}`, { 'stroke-width': 1.8, 'pointer-events': 'none' }));
        g.append(path(`M${x2} ${r.y + 4}L${x1} ${r.y + 4}M${x1 + 10} ${r.y + 11}L${x1} ${r.y + 4}`, { 'stroke-width': 1.8, 'pointer-events': 'none' }));
    } else {
        g.append(path(`M${x1} ${r.y}L${x2} ${r.y}`, { 'stroke-width': 1.8, 'pointer-events': 'none' }));
        g.append(polygon([{ x: x2, y: r.y }, { x: x2 - 11, y: r.y - 6 }, { x: x2 - 11, y: r.y + 6 }], { fill: INK, 'stroke-width': 1, 'pointer-events': 'none' }));
    }
    rc.mark(g, r.id, r.kind === 'equilibrium' ? 'Gleichgewichtspfeil' : 'Reaktionspfeil');
    rc.layer.append(g);
}

// ---------------------------------------------------------------------------
// Bearbeitung
// ---------------------------------------------------------------------------

function addAtom(board, el) {
    if (board.model.atoms.length >= MAX_ATOMS) {
        warn(board, `Es sind höchstens ${MAX_ATOMS} Atome möglich.`);
        return;
    }
    board.mutate((m) => {
        const from = selectedAtoms(board).length === 1 ? selectedAtoms(board)[0] : null;
        const id = newId('a', allIds(m));
        let pos;
        if (from) {
            const d = freeDirection(m, from);
            pos = { x: d.x, y: d.y };
        } else {
            const c = board.viewCenter();
            const free = freePosition(allRects(m), { w: 36, h: 36 }, { x: c.x - 18, y: c.y - 18 }, 20, 6);
            pos = { x: free.x + 18, y: free.y + 18 };
        }
        m.atoms.push({ id, el, x: pos.x, y: pos.y, charge: 0, pairs: [], radicals: 0 });
        if (from && m.bonds.length < MAX_BONDS) {
            m.bonds.push({ id: newId('b', allIds(m)), a: from.id, b: id, order: 1, stereo: 'none' });
        }
        board.sel = new Set([id]);
    }, { inspector: true });
    board.announce(`Atom ${el} eingefügt.`);
}

/** Setzt die Bindungsordnung der gewählten Bindung(en) oder legt zwischen zwei gewählten Atomen eine Bindung an. */
function setOrder(board, order) {
    const bonds = selectedBonds(board);
    const atoms = selectedAtoms(board);
    if (bonds.length > 0) {
        board.mutate((m) => {
            for (const b of m.bonds.filter((x) => board.sel.has(x.id))) {
                b.order = order;
                if (order !== 1) {
                    b.stereo = 'none';
                }
            }
        }, { inspector: true });
        board.announce(`${ORDER_NAME[order]} gesetzt.`);
        return true;
    }
    if (atoms.length === 2) {
        const [a, b] = atoms;
        if (!board.model.bonds.some((x) => (x.a === a.id && x.b === b.id) || (x.a === b.id && x.b === a.id)) && board.model.bonds.length >= MAX_BONDS) {
            warn(board, `Es sind höchstens ${MAX_BONDS} Bindungen möglich.`);
            return true;
        }
        let created = null;
        board.mutate((m) => {
            const old = m.bonds.find((x) => (x.a === a.id && x.b === b.id) || (x.a === b.id && x.b === a.id));
            if (old) {
                old.order = order;
                old.stereo = order === 1 ? old.stereo : 'none';
                created = old.id;
            } else {
                created = newId('b', allIds(m));
                m.bonds.push({ id: created, a: a.id, b: b.id, order, stereo: 'none' });
            }
            board.sel = new Set([created]);
        }, { inspector: true });
        board.announce(`${ORDER_NAME[order]} zwischen ${a.el} und ${b.el}.`);
        return true;
    }
    warn(board, 'Wähle eine Bindung oder zwei Atome, um die Bindungsart zu setzen.');

    return false;
}

function addTemplate(board, name) {
    const t = template(name);
    const m0 = board.model;
    if (!t || m0.atoms.length + t.atoms.length > MAX_ATOMS || m0.bonds.length + t.bonds.length + 1 > MAX_BONDS) {
        warn(board, 'Die Vorlage passt nicht mehr in die Zeichnung (zu viele Atome oder Bindungen).');
        return;
    }
    const from = t.attach !== null && selectedAtoms(board).length === 1 ? selectedAtoms(board)[0] : null;
    board.mutate((m) => {
        const used = allIds(m);
        const newIds = new Map();
        const mk = (prefix) => {
            const id = newId(prefix, used);
            used.push(id);

            return id;
        };
        let origin;
        let cos = 1;
        let sin = 0;
        if (from) {
            const d = freeDirection(m, from);
            origin = { x: d.x, y: d.y };
            cos = Math.cos(d.angle);
            sin = Math.sin(d.angle);
        } else {
            const xs = t.atoms.map((a) => a.x);
            const ys = t.atoms.map((a) => a.y);
            const w = Math.max(...xs) - Math.min(...xs) + 60;
            const hgt = Math.max(...ys) - Math.min(...ys) + 60;
            const c = board.viewCenter();
            const free = freePosition(allRects(m), { w, h: hgt }, { x: c.x - w / 2, y: c.y - hgt / 2 }, 20, 6);
            origin = { x: free.x + 30 - Math.min(...xs), y: free.y + 30 - Math.min(...ys) };
        }
        for (const a of t.atoms) {
            const id = mk('a');
            newIds.set(a.key, id);
            m.atoms.push({
                id,
                el: a.el,
                x: Math.round(origin.x + a.x * cos - a.y * sin),
                y: Math.round(origin.y + a.x * sin + a.y * cos),
                charge: 0,
                pairs: [],
                radicals: 0,
            });
        }
        for (const [k1, k2, order] of t.bonds) {
            m.bonds.push({ id: mk('b'), a: newIds.get(k1), b: newIds.get(k2), order, stereo: 'none' });
        }
        if (from) {
            m.bonds.push({ id: mk('b'), a: from.id, b: newIds.get(t.attach), order: 1, stereo: 'none' });
        }
        board.sel = new Set(newIds.values());
    }, { inspector: true });
    board.announce(`${TEMPLATES.find((x) => x[0] === name)?.[1] ?? 'Vorlage'} eingefügt.`);
}

function addLabel(board) {
    if (board.model.texts.length >= MAX_TEXTS) {
        warn(board, `Es sind höchstens ${MAX_TEXTS} Beschriftungen möglich.`);
        return;
    }
    board.mutate((m) => {
        const id = newId('t', allIds(m));
        const c = board.viewCenter();
        const free = freePosition(allRects(m), { w: 70, h: 24 }, { x: c.x - 35, y: c.y - 12 }, 20, 6);
        m.texts.push({ id, x: free.x + 35, y: free.y + 12, text: 'Text' });
        board.sel = new Set([id]);
    }, { inspector: true });
    board.focusInspector();
}

function addArrow(board, kind) {
    if (board.model.arrows.length >= MAX_ARROWS) {
        warn(board, `Es sind höchstens ${MAX_ARROWS} Pfeile möglich.`);
        return;
    }
    board.mutate((m) => {
        const id = newId('r', allIds(m));
        const c = board.viewCenter();
        const free = freePosition(allRects(m), { w: 100, h: 28 }, { x: c.x - 50, y: c.y - 14 }, 20, 6);
        m.arrows.push({ id, x: free.x + 50, y: free.y + 14, len: 100, kind });
        board.sel = new Set([id]);
    }, { inspector: true });
}

// ---------------------------------------------------------------------------
// Plugin
// ---------------------------------------------------------------------------

function nameOf(m, id) {
    const a = atomById(m, id);

    return a ? a.el : '?';
}

export const structurePlugin = {
    name: 'structure',
    label: 'Strukturformel',
    normalize: (content, config) => normalizeStructure(content, config),
    ids: (m) => allIds(m),
    movable: (m, id) => atomById(m, id) ?? m.texts.find((x) => x.id === id) ?? m.arrows.find((x) => x.id === id) ?? null,
    centerOf(m, id) {
        const o = atomById(m, id) ?? m.texts.find((x) => x.id === id) ?? m.arrows.find((x) => x.id === id);

        return o ? { x: o.x, y: o.y } : null;
    },
    bounds: (m) => bbox(allRects(m)),
    render(model, rc) {
        model.bonds.forEach((b) => drawBond(model, b, rc));
        model.arrows.forEach((r) => drawArrow(model, r, rc));
        model.texts.forEach((t) => drawText(model, t, rc));
        model.atoms.forEach((a) => drawAtom(model, a, rc));
    },
    tools() {
        return [
            ...PALETTE.map((el) => ({
                id: `atom-${el}`, group: 'Atome', label: el, title: `Atom ${el} einfügen (ist ein Atom gewählt, wird es dort angebunden)`, run: (b) => addAtom(b, el),
            })),
            {
                id: 'link', group: 'Bindungen', label: 'Binden', toggle: true, connect: true,
                title: 'Zwei Atome verbinden. Noch einmal auf dasselbe Paar klicken ändert die Bindungsart (einfach, doppelt, dreifach).',
                hint: 'Binden: Klicke ein Atom, dann das zweite. Wiederholtes Verbinden macht aus der Bindung eine Doppel- und Dreifachbindung.',
                hintTarget: 'Wähle jetzt das zweite Atom.',
            },
            { id: 'order-1', group: 'Bindungen', label: 'Einfach', title: 'Einfachbindung (Taste 1)', run: (b) => setOrder(b, 1) },
            { id: 'order-2', group: 'Bindungen', label: 'Doppel', title: 'Doppelbindung (Taste 2)', run: (b) => setOrder(b, 2) },
            { id: 'order-3', group: 'Bindungen', label: 'Dreifach', title: 'Dreifachbindung (Taste 3)', run: (b) => setOrder(b, 3) },
            ...TEMPLATES.map(([id, label, title]) => ({ id: `tpl-${id}`, group: 'Vorlagen', label, title, run: (b) => addTemplate(b, id) })),
            { id: 'add-text', group: 'Reaktion', label: '＋ Text', title: 'Freie Beschriftung einfügen', run: (b) => addLabel(b) },
            { id: 'add-arrow', group: 'Reaktion', label: '→', title: 'Reaktionspfeil einfügen', run: (b) => addArrow(b, 'forward') },
            { id: 'add-equilibrium', group: 'Reaktion', label: '⇌', title: 'Gleichgewichtspfeil einfügen', run: (b) => addArrow(b, 'equilibrium') },
        ];
    },
    connect(board, from, to) {
        const m = board.model;
        const a = atomById(m, from);
        const b = atomById(m, to);
        if (!a || !b) {
            return { error: 'Verbinde zwei Atome miteinander.' };
        }
        if (a.id === b.id) {
            return { error: 'Wähle zwei verschiedene Atome.' };
        }
        const old = m.bonds.find((x) => (x.a === a.id && x.b === b.id) || (x.a === b.id && x.b === a.id));
        if (old) {
            old.order = (old.order % 3) + 1;
            if (old.order !== 1) {
                old.stereo = 'none';
            }

            return { ok: true, select: old.id, message: `${ORDER_NAME[old.order]} zwischen ${a.el} und ${b.el}.` };
        }
        if (m.bonds.length >= MAX_BONDS) {
            return { error: `Es sind höchstens ${MAX_BONDS} Bindungen möglich.` };
        }
        const id = newId('b', allIds(m));
        m.bonds.push({ id, a: a.id, b: b.id, order: 1, stereo: 'none' });

        return { ok: true, select: id, message: `Einfachbindung zwischen ${a.el} und ${b.el}.` };
    },
    onKey(e, board) {
        if (e.ctrlKey || e.metaKey || e.altKey || !['1', '2', '3'].includes(e.key)) {
            return false;
        }
        if (selectedBonds(board).length === 0 && selectedAtoms(board).length !== 2) {
            return false;
        }

        return setOrder(board, Number(e.key));
    },
    remove(model, ids) {
        const del = new Set(ids);
        model.atoms = model.atoms.filter((a) => !del.has(a.id));
        model.bonds = model.bonds.filter((b) => !del.has(b.id) && !del.has(b.a) && !del.has(b.b));
        model.texts = model.texts.filter((t) => !del.has(t.id));
        model.arrows = model.arrows.filter((r) => !del.has(r.id));

        return true;
    },
    describe(model, id) {
        const a = atomById(model, id);
        if (a) {
            return `Atom ${a.el}${a.charge !== 0 ? `, Ladung ${chargeText(a.charge)}` : ''}`;
        }
        const b = model.bonds.find((x) => x.id === id);
        if (b) {
            return `${ORDER_NAME[b.order]} zwischen ${nameOf(model, b.a)} und ${nameOf(model, b.b)}`;
        }
        const t = model.texts.find((x) => x.id === id);
        if (t) {
            return `Beschriftung ${t.text}`;
        }

        return model.arrows.some((x) => x.id === id) ? 'Reaktionspfeil' : '';
    },
    inspector(board) {
        const ids = [...board.sel];
        if (ids.length !== 1) {
            return panel('Eigenschaften', hintText(ids.length > 1
                ? `${ids.length} Elemente ausgewählt. Entf löscht sie, Pfeiltasten verschieben sie.`
                : 'Wähle ein Atom, eine Bindung, eine Beschriftung oder einen Pfeil, um die Eigenschaften zu bearbeiten. Tipp: Ist ein Atom gewählt, hängen die Knöpfe der Palette neue Atome daran an. Mit den Tasten 1, 2 und 3 stellst du die Bindungsart ein.'));
        }
        const id = ids[0];
        const m = board.model;
        const set = (fn, key = null, inspector = false) => board.mutate((model) => {
            fn(model, id);
        }, { key, inspector });

        const atom = atomById(m, id);
        if (atom) {
            return inspectAtom(board, atom, set);
        }
        const bond = m.bonds.find((x) => x.id === id);
        if (bond) {
            return panel(`Bindung ${nameOf(m, bond.a)} und ${nameOf(m, bond.b)}`, h('div', { class: 'dg-fields' },
                field('Bindungsart', selectInput([['1', 'Einfachbindung'], ['2', 'Doppelbindung'], ['3', 'Dreifachbindung']], String(bond.order), (v) => set((model, i) => {
                    const b = model.bonds.find((x) => x.id === i);
                    b.order = Number(v);
                    if (b.order !== 1) {
                        b.stereo = 'none';
                    }
                }, null, true))),
                bond.order === 1 ? field('Darstellung', selectInput([['none', 'Gewöhnlich'], ['wedge', 'Keil (zum Betrachter)'], ['dash', 'Gestrichelt (vom Betrachter weg)']], bond.stereo, (v) => set((model, i) => {
                    model.bonds.find((x) => x.id === i).stereo = v;
                }))) : null,
                bond.order === 1 && bond.stereo !== 'none'
                    ? smallButton('⇄ Richtung umkehren', () => set((model, i) => {
                        const b = model.bonds.find((x) => x.id === i);
                        [b.a, b.b] = [b.b, b.a];
                    }, null, true))
                    : null));
        }
        const t = m.texts.find((x) => x.id === id);
        if (t) {
            return panel('Beschriftung', h('div', { class: 'dg-fields' },
                field('Text', textInput(t.text, (v) => set((model, i) => {
                    const x = model.texts.find((q) => q.id === i);
                    x.text = v.replace(/\s+/g, ' ').slice(0, 80) || x.text;
                }, `t:${id}`), { maxlength: 80 }), { hint: 'Ziffern direkt nach einem Buchstaben werden tiefgestellt (H2O wird H₂O).' })));
        }
        const r = m.arrows.find((x) => x.id === id);
        if (r) {
            const lens = [...new Set([60, 80, 100, 140, 180, 240, 320, r.len])].sort((p, q) => p - q);

            return panel('Pfeil', h('div', { class: 'dg-fields' },
                field('Art', selectInput([['forward', 'Reaktionspfeil →'], ['equilibrium', 'Gleichgewicht ⇌']], r.kind, (v) => set((model, i) => {
                    model.arrows.find((x) => x.id === i).kind = ARROW_KINDS.includes(v) ? v : 'forward';
                }))),
                field('Länge', selectInput(lens.map((l) => [String(l), `${l}`]), String(r.len), (v) => set((model, i) => {
                    model.arrows.find((x) => x.id === i).len = Number(v);
                })))));
        }

        return null;
    },
    mountExtras(board, host) {
        const box = h('div', { class: 'dg-formula', 'aria-live': 'polite' });
        host.append(box);

        return {
            update() {
                const mols = molecules(board.model);
                if (mols.length === 0) {
                    box.replaceChildren(h('div', { class: 'dg-formula-empty' }, 'Summenformel: Zeichne Atome, dann erscheint hier die Summenformel je Molekül.'));
                    return;
                }
                box.replaceChildren(...mols.map((mol, i) => h('div', { class: 'dg-formula-row' },
                    h('span', { class: 'dg-formula-label' }, mols.length > 1 ? `Molekül ${i + 1}` : 'Summenformel'),
                    h('strong', { class: 'dg-formula-text' }, mol.formula),
                    h('span', { class: 'dg-formula-charge' }, `Gesamtladung: ${chargeText(mol.charge)}`))));
            },
            destroy() {
                box.remove();
            },
        };
    },
};

/** Eigenschaften eines Atoms: Element, Ladung, freie Elektronenpaare, einzelne Elektronen. */
function inspectAtom(board, atom, set) {
    const elements = PALETTE.includes(atom.el) ? PALETTE : [...PALETTE, atom.el];
    const elSelect = selectInput(elements.map((e) => [e, e]), atom.el, (v) => set((model, i) => {
        atomById(model, i).el = v;
    }, null, true), { 'aria-label': 'Element' });
    const note = h('div', { class: 'dg-field-hint', 'aria-live': 'polite' });
    const free = textInput('', (v) => {
        const sym = symbolOf(v);
        if (v.trim() === '') {
            note.textContent = '';
        } else if (sym === null) {
            note.textContent = `"${v.trim().slice(0, 4)}" ist kein Elementsymbol.`;
        } else {
            note.textContent = `Element ${sym} gesetzt.`;
            set((model, i) => {
                atomById(model, i).el = sym;
            }, `el:${atom.id}`);
            if (elements.includes(sym)) {
                elSelect.value = sym;
            }
        }
    }, { maxlength: 2, 'aria-label': 'Anderes Element (Symbol)', placeholder: 'z. B. Si' });

    const charges = [[-2, '2−'], [-1, '−'], [0, 'ungeladen'], [1, '+'], [2, '2+'], [3, '3+']];
    const pairChecks = SIDES.map((side) => checkInput(`Elektronenpaar ${SIDE_LABEL[side]}`, atom.pairs.includes(side), (on) => set((model, i) => {
        const a = atomById(model, i);
        const has = new Set(a.pairs);
        if (on) {
            has.add(side);
        } else {
            has.delete(side);
        }
        a.pairs = SIDES.filter((x) => has.has(x));
    })));

    return panel(`Atom ${atom.el}`, h('div', { class: 'dg-fields' },
        field('Element', elSelect),
        field('Anderes Element', free),
        note,
        field('Ladung', selectInput(charges.map(([v, l]) => [String(v), l]), String(atom.charge), (v) => set((model, i) => {
            atomById(model, i).charge = Number(v);
        }))),
        h('div', { class: 'dg-members' }, h('h4', { class: 'dg-sub' }, 'Freie Elektronenpaare (Striche)'), ...pairChecks),
        field('Einzelne Elektronen (Radikal)', selectInput([0, 1, 2, 3, 4].map((n) => [String(n), String(n)]), String(atom.radicals), (v) => set((model, i) => {
            atomById(model, i).radicals = Number(v);
        })))));
}

export const StructureWidget = boardWidget(structurePlugin);
registerAnswerWidget('structure', StructureWidget);
