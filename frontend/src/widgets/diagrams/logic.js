/**
 * Schaltnetz zeichnen (Antworttyp "logic"). Format: docs/DATENFORMATE.md, 2.11.
 *
 * Symbole: config.symbols = "din" (Rechteck mit &, >=1, =1, 1; Standard, DIN EN 60617)
 * oder "ansi" (US-Symbole). Eingänge und Ausgänge ergeben sich aus der Konfiguration und
 * sind fest vorhanden. Verdrahtung Pin zu Pin; Live-Simulation durch Klick auf die Eingänge.
 * Zusätzliches optionales Feld je Gatter: n (2 bis 4 Eingänge).
 */
import { h } from '../../lib/dom.js';
import { registerAnswerWidget } from '../../lib/widgets.js';
import { bbox, freePosition, newId, polylinePath, round1 } from './core/geometry.js';
import { boardWidget } from './core/widget.js';
import { line, num, pick, rows, uniqueId } from './core/model-util.js';
import { field, hintText, panel, selectInput } from './core/form.js';
import { GATES, evaluate, pinCount, rowKeys } from './core/logic-sim.js';
import { INK, PAPER, SOFT, path, s, text } from './core/svg.js';

const W = 56; // Breite eines Gatterkörpers
const STUB = 8;
const HIGH = '#2563eb';
const LOW = INK;
const ERR = '#dc2626';
export const GATE_TITLE = {
    AND: 'UND-Gatter (AND)', OR: 'ODER-Gatter (OR)', NOT: 'Negation (NOT)', NAND: 'NAND-Gatter', NOR: 'NOR-Gatter', XOR: 'Exklusiv-ODER (XOR)', XNOR: 'Äquivalenz (XNOR)',
};
const DIN_LABEL = { AND: '&', NAND: '&', OR: '≥1', NOR: '≥1', XOR: '=1', XNOR: '=1', NOT: '1' };
const NEGATED = ['NAND', 'NOR', 'XNOR', 'NOT'];
const MULTI = ['AND', 'OR', 'NAND', 'NOR', 'XOR', 'XNOR'];

const inputNames = (config, model) => (Array.isArray(config?.inputs) ? config.inputs.map(String)
    : (model?.components ?? []).filter((c) => c.type === 'INPUT').map((c) => c.label));
const outputNames = (config, model) => (Array.isArray(config?.outputs) ? config.outputs.map(String)
    : (model?.components ?? []).filter((c) => c.type === 'OUTPUT').map((c) => c.label));

export const ioId = (kind, name) => `${kind === 'INPUT' ? 'in' : 'out'}_${name}`;

export function normalizeLogic(content, config = {}) {
    const ids = new Set();
    const inNames = Array.isArray(config.inputs) ? config.inputs.map(String) : null;
    const outNames = Array.isArray(config.outputs) ? config.outputs.map(String) : null;
    const components = [];
    const seenLabels = new Set();
    for (const c of rows(content?.components, 80)) {
        const type = pick(c.type, ['INPUT', 'OUTPUT', ...GATES], null);
        if (type === null) {
            continue;
        }
        const label = line(c.label, 20);
        if (type === 'INPUT' || type === 'OUTPUT') {
            const allowed = type === 'INPUT' ? inNames : outNames;
            if ((allowed !== null && !allowed.includes(label)) || seenLabels.has(`${type}:${label}`)) {
                continue;
            }
            seenLabels.add(`${type}:${label}`);
        }
        const entry = { id: uniqueId(c.id, ids, 'g'), type, label, x: num(c.x), y: num(c.y) };
        if (MULTI.includes(type)) {
            const n = Math.max(2, Math.min(4, Math.round(Number(c.n) || 2)));
            if (n !== 2) {
                entry.n = n;
            }
        }
        components.push(entry);
    }
    // feste Ein- und Ausgänge ergänzen
    const addIo = (type, names, x) => {
        names.forEach((name, i) => {
            if (!components.some((c) => c.type === type && c.label === name)) {
                components.push({ id: uniqueId(ioId(type, name), ids, type === 'INPUT' ? 'in' : 'out'), type, label: name, x, y: 30 + i * 70 });
            }
        });
    };
    addIo('INPUT', inNames ?? [], 20);
    addIo('OUTPUT', outNames ?? [], 560);

    const byId = new Map(components.map((c) => [c.id, c]));
    const wireIds = new Set();
    const used = new Set();
    const wires = [];
    for (const w of rows(content?.wires, 240)) {
        const from = w.from?.component;
        const to = w.to?.component;
        const pin = w.to?.pin;
        const a = byId.get(from);
        const b = byId.get(to);
        if (!a || !b || from === to || a.type === 'OUTPUT' || b.type === 'INPUT' || (w.from?.pin ?? 'out') !== 'out') {
            continue;
        }
        const m = /^in([0-3])$/.exec(pin ?? '');
        if (!m || Number(m[1]) >= pinCount(b) || used.has(`${to}.${pin}`)) {
            continue;
        }
        used.add(`${to}.${pin}`);
        wires.push({ id: uniqueId(w.id, wireIds, 'w'), from: { component: from, pin: 'out' }, to: { component: to, pin } });
    }

    return { components, wires };
}

/** Körper und Pins einer Komponente. */
export function compBox(c) {
    if (c.type === 'INPUT') {
        return { x: c.x, y: c.y, w: 64, h: 32, pins: { out: { x: c.x + 64 + STUB, y: c.y + 16 } }, outer: { x: c.x, y: c.y, w: 64 + STUB + 4, h: 32 } };
    }
    if (c.type === 'OUTPUT') {
        return { x: c.x, y: c.y, w: 64, h: 32, pins: { in0: { x: c.x - STUB, y: c.y + 16 } }, outer: { x: c.x - STUB - 4, y: c.y, w: 64 + STUB + 4, h: 32 } };
    }
    const n = pinCount(c);
    const hh = 20 * (n + 1);
    const pins = { out: { x: c.x + W + STUB, y: c.y + hh / 2 } };
    for (let i = 0; i < n; i++) {
        pins[`in${i}`] = { x: c.x - STUB, y: c.y + 20 * (i + 1) };
    }

    return { x: c.x, y: c.y, w: W, h: hh, pins, outer: { x: c.x - STUB - 4, y: c.y - 4, w: W + 2 * STUB + 8, h: hh + 8 } };
}

/** Position eines Anschlusses "id.pin" oder Mitte einer Komponente "id". */
export function portPoint(model, port) {
    const [id, pin] = String(port).split('.');
    const c = model.components.find((x) => x.id === id);
    if (!c) {
        return null;
    }
    const box = compBox(c);
    if (pin) {
        return box.pins[pin] ?? null;
    }

    return { x: box.x + box.w / 2, y: box.y + box.h / 2 };
}

/** Leitung von (p) nach (q) als Linienzug. */
export function wireRoute(p, q) {
    if (q.x >= p.x + 24) {
        const mx = round1((p.x + q.x) / 2);

        return [p, { x: mx, y: p.y }, { x: mx, y: q.y }, q];
    }
    const my = round1((p.y + q.y) / 2 + (Math.abs(p.y - q.y) < 30 ? 50 : 0));

    return [p, { x: p.x + 14, y: p.y }, { x: p.x + 14, y: my }, { x: q.x - 14, y: my }, { x: q.x - 14, y: q.y }, q];
}

// ---------------------------------------------------------------------------
// Symbole
// ---------------------------------------------------------------------------

function gateBody(c, symbols) {
    const box = compBox(c);
    const { x, y } = box;
    const hh = box.h;
    const g = s('g', {});
    const attrs = { fill: PAPER, stroke: INK, 'stroke-width': 1.7, 'stroke-linejoin': 'round', class: 'dg-shape' };
    const negated = NEGATED.includes(c.type);
    const bodyW = negated ? W - 8 : W; // der Negationskreis sitzt am Ausgang
    if (symbols === 'ansi') {
        const wx = bodyW;
        let d;
        switch (c.type) {
            case 'AND':
            case 'NAND':
                d = `M0 0H${wx - hh / 2}A${hh / 2} ${hh / 2} 0 0 1 ${wx - hh / 2} ${hh}H0Z`;
                break;
            case 'NOT':
                d = `M0 0L${wx} ${hh / 2}L0 ${hh}Z`;
                break;
            default: // OR, NOR, XOR, XNOR
                d = `M0 0Q${wx * 0.6} 0 ${wx} ${hh / 2}Q${wx * 0.6} ${hh} 0 ${hh}Q${wx * 0.3} ${hh / 2} 0 0Z`;
        }
        g.append(s('path', { ...attrs, d, transform: `translate(${x} ${y})` }));
        if (c.type === 'XOR' || c.type === 'XNOR') {
            g.append(path(`M-7 0Q${wx * 0.3 - 7} ${hh / 2} -7 ${hh}`, { transform: `translate(${x} ${y})`, 'stroke-width': 1.7 }));
        }
    } else {
        g.append(s('rect', { ...attrs, x, y, width: bodyW, height: hh }));
        g.append(text(DIN_LABEL[c.type], x + bodyW / 2, y + 15, { size: 14, weight: 'bold' }));
    }
    if (negated) {
        g.append(s('circle', { cx: x + bodyW + 4, cy: y + hh / 2, r: 4, fill: PAPER, stroke: INK, 'stroke-width': 1.7 }));
    }
    // Anschlussstummel
    for (const [name, p] of Object.entries(box.pins)) {
        if (name === 'out') {
            g.append(path(`M${x + (negated ? bodyW + 8 : bodyW)} ${p.y}H${p.x}`, { 'stroke-width': 1.5 }));
        } else {
            g.append(path(`M${p.x} ${p.y}H${x + (symbols === 'ansi' && c.type !== 'AND' && c.type !== 'NAND' && c.type !== 'NOT' ? 3 : 0)}`, { 'stroke-width': 1.5 }));
        }
    }

    return g;
}

function drawComponent(model, c, rc, sim, inputs) {
    const box = compBox(c);
    const g = s('g', { class: 'dg-node' });
    const symbols = rc.config?.symbols === 'ansi' ? 'ansi' : 'din';
    if (c.type === 'INPUT' || c.type === 'OUTPUT') {
        const value = c.type === 'INPUT' ? (inputs[c.label] ? 1 : 0) : (sim.values.get(c.id) ?? null);
        g.append(s('rect', { x: box.x, y: box.y, width: box.w, height: box.h, rx: 4, fill: PAPER, stroke: INK, 'stroke-width': 1.7, class: 'dg-shape' }));
        g.append(text(c.label, box.x + 18, box.y + 16, { size: 14, weight: 'bold' }));
        if (c.type === 'INPUT') {
            const chip = s('g', { class: 'dg-toggle' },
                s('rect', { x: box.x + 36, y: box.y + 4, width: 24, height: 24, rx: 5, fill: value ? HIGH : '#f3f4f6', stroke: INK, 'stroke-width': 1.3 }),
                text(String(value), box.x + 48, box.y + 16, { size: 14, weight: 'bold', fill: value ? '#ffffff' : INK }));
            rc.action(chip, 'toggle', { comp: c.id, input: c.label }, `Eingang ${c.label} umschalten, aktuell ${value}`, true);
            g.append(chip);
        } else {
            g.append(s('circle', { cx: box.x + 48, cy: box.y + 16, r: 10, fill: value === 1 ? '#facc15' : PAPER, stroke: INK, 'stroke-width': 1.3 }));
            g.append(text(value === null ? '?' : String(value), box.x + 48, box.y + 16, { size: 12, weight: 'bold' }));
        }
        const stubFrom = c.type === 'INPUT' ? box.x + box.w : box.x;
        const pin = c.type === 'INPUT' ? box.pins.out : box.pins.in0;
        g.append(path(`M${stubFrom} ${pin.y}H${pin.x}`, { 'stroke-width': 1.5 }));
    } else {
        g.append(gateBody(c, symbols));
    }
    // Trefferfläche über dem Körper
    g.append(s('rect', { x: box.x - 2, y: box.y - 2, width: box.w + 4, height: box.h + 4, fill: 'transparent', stroke: 'none' }));
    rc.mark(g, c.id, c.type === 'INPUT' ? `Eingang ${c.label}` : c.type === 'OUTPUT' ? `Ausgang ${c.label}` : `${GATE_TITLE[c.type]}${pinCount(c) > 2 ? `, ${pinCount(c)} Eingänge` : ''}`);
    rc.layer.append(g);
    // Pins (außerhalb der Auswahlgruppe, damit sie sich zuerst treffen lassen)
    for (const [name, p] of Object.entries(box.pins)) {
        const connected = name === 'out'
            ? model.wires.some((w) => w.from.component === c.id)
            : model.wires.some((w) => w.to.component === c.id && w.to.pin === name);
        const open = sim.open.has(`${c.id}.${name}`);
        const pin = s('g', { class: 'dg-pin' },
            s('circle', { cx: p.x, cy: p.y, r: 9, fill: 'transparent', stroke: 'none' }),
            s('circle', { cx: p.x, cy: p.y, r: 3.6, fill: connected ? INK : PAPER, stroke: open && !rc.exportMode ? ERR : INK, 'stroke-width': 1.5, class: 'dg-pin-dot' }));
        rc.port(pin, `${c.id}.${name}`, name === 'out' ? 'Ausgang verbinden' : `Eingang ${Number(name.slice(2)) + 1} verbinden`);
        rc.layer.append(pin);
    }
}

function drawWire(model, w, rc, sim) {
    const p = portPoint(model, `${w.from.component}.out`);
    const q = portPoint(model, `${w.to.component}.${w.to.pin}`);
    if (!p || !q) {
        return;
    }
    const pts = wireRoute(p, q);
    const value = sim.values.get(w.from.component);
    const cyc = sim.cyclic.has(w.from.component);
    const g = s('g', { class: 'dg-edge' });
    g.append(s('path', { d: polylinePath(pts), fill: 'none', stroke: 'transparent', 'stroke-width': 12, class: 'dg-hit' }));
    g.append(path(polylinePath(pts), {
        class: 'dg-shape', stroke: cyc ? ERR : value === 1 ? HIGH : LOW, 'stroke-width': value === 1 ? 2.6 : 1.6, 'stroke-dasharray': cyc ? '6 4' : undefined,
    }));
    rc.mark(g, w.id, `Leitung von ${labelOf(model, w.from.component)} zu ${labelOf(model, w.to.component)}, Eingang ${Number(w.to.pin.slice(2)) + 1}`);
    rc.layer.append(g);
}

const labelOf = (model, id) => {
    const c = model.components.find((x) => x.id === id);

    return c ? (c.label || c.type) : '?';
};

// ---------------------------------------------------------------------------
// Plugin
// ---------------------------------------------------------------------------

const isFixed = (c) => c.type === 'INPUT' || c.type === 'OUTPUT';

function addGate(board, type) {
    board.mutate((m) => {
        const id = newId('g', m.components.map((c) => c.id));
        const draft = { id, type, label: '', x: 0, y: 0 };
        const box = compBox(draft);
        const at = board.viewCenter();
        const pos = freePosition(m.components.map((c) => compBox(c).outer), { w: box.outer.w, h: box.outer.h }, { x: at.x - 30, y: at.y - 30 }, 30, 20);
        draft.x = pos.x;
        draft.y = pos.y;
        m.components.push(draft);
        board.sel = new Set([id]);
    }, { inspector: true });
}

export const logicPlugin = {
    name: 'logic',
    label: 'Schaltnetz',
    normalize: (content, config) => normalizeLogic(content, config),
    ids: (m) => [...m.components.map((c) => c.id), ...m.wires.map((w) => w.id)],
    movable: (m, id) => m.components.find((c) => c.id === id) ?? null,
    centerOf: (m, port) => portPoint(m, port),
    bounds: (m) => bbox(m.components.map((c) => compBox(c).outer)),
    render(model, rc) {
        const inputs = rc.board?.simInputs ?? {};
        const sim = evaluate(model, inputs);
        model.wires.forEach((w) => drawWire(model, w, rc, sim));
        model.components.forEach((c) => drawComponent(model, c, rc, sim, inputs));
    },
    tools(board) {
        const allowed = Array.isArray(board.config.allowed_gates) && board.config.allowed_gates.length > 0 ? board.config.allowed_gates : GATES;

        return allowed.filter((t) => GATES.includes(t)).map((type) => ({
            id: `add-${type}`, group: 'Gatter', label: type, title: `${GATE_TITLE[type]} einfügen`, run: (b) => {
                addGate(b, type);
                b.refreshInspector();
            },
        }));
    },
    connect(board, from, to) {
        const m = board.model;
        const parse = (p) => {
            const [id, pin] = String(p).split('.');
            const comp = m.components.find((c) => c.id === id);

            return comp ? { comp, pin: pin ?? null } : null;
        };
        let a = parse(from);
        let b = parse(to);
        if (!a || !b) {
            return null;
        }
        // von einem Eingangspin begonnen: Richtung drehen
        if (a.pin && a.pin !== 'out' && (b.pin === 'out' || !b.pin)) {
            [a, b] = [b, a];
        }
        if (a.comp.id === b.comp.id) {
            return { error: 'Ein Gatter kann nicht mit sich selbst verbunden werden.' };
        }
        if (a.comp.type === 'OUTPUT' || (a.pin && a.pin !== 'out')) {
            return { error: 'Eine Leitung beginnt an einem Ausgang (rechts am Gatter oder Eingang).' };
        }
        if (b.comp.type === 'INPUT' || b.pin === 'out') {
            return { error: 'Eine Leitung endet an einem Eingang (links am Gatter oder Ausgang).' };
        }
        let pin = b.pin;
        if (!pin) {
            const count = pinCount(b.comp);
            for (let i = 0; i < count; i++) {
                if (!m.wires.some((w) => w.to.component === b.comp.id && w.to.pin === `in${i}`)) {
                    pin = `in${i}`;
                    break;
                }
            }
            if (!pin) {
                return { error: 'Alle Eingänge dieses Gatters sind schon belegt. Ziehe die Leitung genau auf einen Eingang, um ihn neu zu belegen.' };
            }
        }
        // ein Eingang nimmt nur eine Leitung auf: bisherige ersetzen
        m.wires = m.wires.filter((w) => !(w.to.component === b.comp.id && w.to.pin === pin));
        const id = newId('w', m.wires.map((w) => w.id));
        m.wires.push({ id, from: { component: a.comp.id, pin: 'out' }, to: { component: b.comp.id, pin } });

        return { ok: true, select: id, message: 'Leitung gezogen.' };
    },
    remove(model, ids) {
        const set = new Set(ids);
        const fixed = new Set(model.components.filter(isFixed).map((c) => c.id));
        const del = new Set([...set].filter((id) => !fixed.has(id)));
        if (del.size === 0) {
            return false;
        }
        model.components = model.components.filter((c) => !del.has(c.id));
        const alive = new Set(model.components.map((c) => c.id));
        model.wires = model.wires.filter((w) => !del.has(w.id) && alive.has(w.from.component) && alive.has(w.to.component));

        return true;
    },
    describe(model, id) {
        const c = model.components.find((x) => x.id === id);
        if (c) {
            return isFixed(c) ? `${c.type === 'INPUT' ? 'Eingang' : 'Ausgang'} ${c.label}` : GATE_TITLE[c.type];
        }
        const w = model.wires.find((x) => x.id === id);

        return w ? `Leitung von ${labelOf(model, w.from.component)} zu ${labelOf(model, w.to.component)}` : '';
    },
    onAction(name, data, board) {
        if (name === 'toggle') {
            board.simInputs ??= {};
            const key = data.input;
            board.simInputs[key] = board.simInputs[key] ? 0 : 1;
            board.render();
            board.extras?.update?.();
            board.announce(`Eingang ${key} ist jetzt ${board.simInputs[key]}.`);
        }
    },
    onKey(e, board) {
        // Leertaste schaltet den gewählten Eingang um
        if (e.key === ' ' || e.key === 'Spacebar') {
            const c = board.model.components.find((x) => board.sel.has(x.id) && x.type === 'INPUT');
            if (c) {
                logicPlugin.onAction('toggle', { input: c.label }, board);
                return true;
            }
        }

        return false;
    },
    inspector(board) {
        const ids = [...board.sel];
        const m = board.model;
        const lead = hintText('Ziehe von einem Anschlusspunkt (kleiner Kreis) zu einem anderen, um eine Leitung zu legen. Klicke auf die Kästchen der Eingänge, um 0 und 1 zu schalten (Leertaste bei markiertem Eingang).');
        if (ids.length !== 1) {
            return panel('Schaltnetz', lead);
        }
        const c = m.components.find((x) => x.id === ids[0]);
        if (c && !isFixed(c)) {
            const allowed = Array.isArray(board.config.allowed_gates) && board.config.allowed_gates.length > 0 ? board.config.allowed_gates : GATES;
            const types = allowed.includes(c.type) ? allowed : [...allowed, c.type];
            const fields = [field('Gatter', selectInput(types.map((t) => [t, GATE_TITLE[t]]), c.type, (v) => board.mutate((model) => {
                const x = model.components.find((q) => q.id === c.id);
                if (x) {
                    x.type = v;
                    if (!MULTI.includes(v)) {
                        delete x.n;
                    }
                    const keep = pinCount(x);
                    model.wires = model.wires.filter((w) => w.to.component !== x.id || Number(w.to.pin.slice(2)) < keep);
                }
            }, { inspector: true })))];
            if (MULTI.includes(c.type)) {
                fields.push(field('Anzahl Eingänge', selectInput([['2', '2'], ['3', '3'], ['4', '4']], String(pinCount(c)), (v) => board.mutate((model) => {
                    const x = model.components.find((q) => q.id === c.id);
                    if (x) {
                        if (Number(v) === 2) {
                            delete x.n;
                        } else {
                            x.n = Number(v);
                        }
                        const keep = pinCount(x);
                        model.wires = model.wires.filter((w) => w.to.component !== x.id || Number(w.to.pin.slice(2)) < keep);
                    }
                }, { inspector: true }))));
            }

            return panel(GATE_TITLE[c.type], h('div', { class: 'dg-fields' }, ...fields), lead);
        }
        if (c) {
            return panel(`${c.type === 'INPUT' ? 'Eingang' : 'Ausgang'} ${c.label}`, hintText('Ein- und Ausgänge sind durch die Aufgabe festgelegt. Du kannst sie verschieben.'), lead);
        }

        return panel('Leitung', hintText('Entf löscht die Leitung. Ein Eingang nimmt nur eine Leitung auf; eine neue Leitung ersetzt die alte.'));
    },
    mountExtras(board, host) {
        const root = h('div', { class: 'dg-logic-extras' });
        host.append(root);
        const messages = h('div', { class: 'dg-sim-issues', 'aria-live': 'polite' });
        const table = h('div', { class: 'dg-table-wrap' });
        root.append(messages, table);

        return {
            update() {
                const m = board.model;
                const ins = inputNames(board.config, m);
                const outs = outputNames(board.config, m);
                const current = board.simInputs ?? {};
                const sim = evaluate(m, current);
                const lines = [];
                for (const e of sim.errors) {
                    lines.push(h('div', { class: 'dg-hint-line is-error' }, e));
                }
                if (m.wires.length > 0 && sim.errors.length === 0 && !board.readOnly) {
                    const labels = [...sim.open].map((k) => {
                        const [id, pin] = k.split('.');

                        return `${labelOf(m, id)} Eingang ${Number(pin.slice(2)) + 1}`;
                    });
                    if (labels.length > 0) {
                        lines.push(h('div', { class: 'dg-hint-line' }, `Nicht verbunden: ${labels.slice(0, 6).join(', ')}${labels.length > 6 ? ' ...' : ''}. Offene Eingänge zählen als 0.`));
                    }
                }
                messages.replaceChildren(...lines);

                table.replaceChildren();
                if (board.config.show_table === true && ins.length > 0 && outs.length > 0 && sim.errors.length === 0) {
                    const key = ins.map((n) => (current[n] ? '1' : '0')).join('');
                    const head = h('tr', {}, ...ins.map((n) => h('th', { scope: 'col' }, n)), ...outs.map((n) => h('th', { scope: 'col', class: 'is-out' }, n)));
                    const body = rowKeys(ins.length).map((k) => {
                        const inputs = {};
                        ins.forEach((n, i) => { inputs[n] = Number(k[i]); });
                        const r = evaluate(m, inputs);

                        return h('tr', { class: k === key ? 'is-current' : '' },
                            ...[...k].map((bit) => h('td', {}, bit)),
                            ...outs.map((n) => h('td', { class: 'is-out' }, String(r.outputs[n] ?? 0))));
                    });
                    table.append(h('table', { class: 'dg-table', 'aria-label': 'Wahrheitstabelle deiner Schaltung' }, h('thead', {}, head), h('tbody', {}, ...body)));
                }
            },
            destroy: () => root.remove(),
        };
    },
};

export const LogicWidget = boardWidget(logicPlugin);
registerAnswerWidget('logic', LogicWidget);

void SOFT;
