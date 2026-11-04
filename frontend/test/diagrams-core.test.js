import test from 'node:test';
import assert from 'node:assert/strict';

import {
    arrowShape, bbox, circleEdge, circleLoop, clampPoints, distToPolyline, ellipseBorderPoint, diamondBorderPoint, freePosition, newId,
    pointAlong, pointsToPath, polylineLength, rectBorderPoint, routeOrthogonal, routeSelfLoop, routeStraight, simplifyPoints, snap, textBlock, textWidth,
    trimEnd,
} from '../src/widgets/diagrams/core/geometry.js';
import { History } from '../src/widgets/diagrams/core/history.js';
import { compareTables, evaluate, gateValue, pinCount, rowKeys, truthTable } from '../src/widgets/diagrams/core/logic-sim.js';
import {
    accepts, closure, dfaIssues, generateWords, run, startSet, transitionTable,
} from '../src/widgets/diagrams/core/automaton-sim.js';
import {
    BLOCK_TYPES, collectIds, findBlock, flatOrder, indentBlock, insertBlock, moveBlock, newBlock, normalizeStructogram, outdentBlock, removeBlock,
} from '../src/widgets/diagrams/core/structogram-model.js';
import { layoutStructogram, measureBlock } from '../src/widgets/diagrams/core/structogram-layout.js';
import {
    compareRows, fkTargets, isPrefilled, nextCell, normalizeRows, normalizeSchema, renameReferences, renameTableReferences, schemaSegments, schemaText,
} from '../src/widgets/diagrams/core/tables-model.js';

// ---------------------------------------------------------------- Geometrie

test('snap rundet aufs Raster', () => {
    assert.equal(snap(14), 10);
    assert.equal(snap(15), 20);
    assert.equal(snap(-4), 0);
    assert.equal(snap(7, 5), 5);
});

test('Douglas-Peucker: gerade Linien schrumpfen, Ecken bleiben', () => {
    const line = Array.from({ length: 50 }, (_, i) => [i, i * 2]);
    assert.deepEqual(simplifyPoints(line, 0.5), [[0, 0], [49, 98]]);
    const corner = [[0, 0], [10, 0], [20, 0], [20, 10], [20, 20]];
    assert.deepEqual(simplifyPoints(corner, 0.5), [[0, 0], [20, 0], [20, 20]]);
    assert.deepEqual(simplifyPoints([[1, 1], [1, 1], [1, 1]], 1), [[1, 1]]);
    assert.deepEqual(simplifyPoints([[0, 0], [5, 5]], 1), [[0, 0], [5, 5]]);
    assert.deepEqual(simplifyPoints([], 1), []);
});

test('Douglas-Peucker ist robust bei sehr langen Linien (kein Rekursionsfehler)', () => {
    const wave = Array.from({ length: 20000 }, (_, i) => [i, Math.sin(i / 7) * 30]);
    const out = simplifyPoints(wave, 1);
    assert.ok(out.length < wave.length && out.length > 100);
    assert.deepEqual(out[0], wave[0]);
    assert.deepEqual(out[out.length - 1], wave[wave.length - 1]);
});

test('Punkte werden gerundet und begrenzt', () => {
    assert.deepEqual(clampPoints([[-5, 1.26], [999, 500.04]], 100, 200), [[0, 1.3], [100, 200]]);
    assert.equal(pointsToPath([]), '');
    assert.match(pointsToPath([[1, 2]]), /^M1 2/);
    assert.match(pointsToPath([[0, 0], [10, 10], [20, 0]]), /^M0 0Q10 10 15 5L20 0$/);
});

test('Abstand zu Linienzug', () => {
    assert.equal(distToPolyline({ x: 5, y: 3 }, [[0, 0], [10, 0]]), 3);
    assert.equal(distToPolyline({ x: 13, y: 4 }, [[0, 0], [10, 0]]), 5);
    assert.equal(distToPolyline({ x: 3, y: 4 }, [[0, 0]]), 5);
    assert.equal(distToPolyline({ x: 0, y: 0 }, []), Infinity);
});

test('Randpunkte von Rechteck, Ellipse und Raute', () => {
    const r = { x: 0, y: 0, w: 100, h: 50 };
    assert.deepEqual(rectBorderPoint(r, { x: 300, y: 25 }), { x: 100, y: 25 });
    assert.deepEqual(rectBorderPoint(r, { x: 50, y: -100 }), { x: 50, y: 0 });
    const e = ellipseBorderPoint({ x: 0, y: 0 }, 20, 10, { x: 100, y: 0 });
    assert.ok(Math.abs(e.x - 20) < 1e-9 && Math.abs(e.y) < 1e-9);
    const d = diamondBorderPoint({ x: 0, y: 0 }, 20, 10, { x: 100, y: 0 });
    assert.ok(Math.abs(d.x - 20) < 1e-9);
    const dd = diamondBorderPoint({ x: 0, y: 0 }, 20, 10, { x: 10, y: 5 });
    assert.ok(Math.abs(dd.x - 10) < 1e-9 && Math.abs(dd.y - 5) < 1e-9);
});

test('rechtwinklige Kantenführung', () => {
    const a = { x: 0, y: 0, w: 100, h: 40 };
    // direkt darunter, überlappend: gerade senkrecht
    const down = routeOrthogonal(a, { x: 10, y: 100, w: 100, h: 40 });
    assert.equal(down.length, 2);
    assert.equal(down[0].x, down[1].x);
    assert.equal(down[0].y, 40);
    assert.equal(down[1].y, 100);
    // schräg unten rechts: Z-Form mit vier Punkten
    const z = routeOrthogonal(a, { x: 300, y: 100, w: 100, h: 40 });
    assert.equal(z.length, 4);
    for (let i = 1; i < z.length; i++) {
        assert.ok(z[i].x === z[i - 1].x || z[i].y === z[i - 1].y, 'nur waagerecht oder senkrecht');
    }
    // rechts daneben auf gleicher Höhe: waagerecht
    const side = routeOrthogonal(a, { x: 200, y: 0, w: 100, h: 40 });
    assert.equal(side[0].y, side[1].y);
    assert.equal(side[0].x, 100);
    assert.equal(side[1].x, 200);
    // Rücksprung nach oben läuft seitlich herum
    const up = routeOrthogonal({ x: 0, y: 200, w: 100, h: 40 }, a, { up: 'around' });
    assert.equal(up.length, 4);
    assert.ok(up[1].x > 100);
    // überlappend: gerade Linie
    assert.equal(routeOrthogonal(a, { x: 50, y: 20, w: 100, h: 40 }).length, 2);
});

test('gerade Kanten und Schleifen', () => {
    const s = routeStraight({ x: 0, y: 0, w: 100, h: 40 }, { x: 300, y: 0, w: 100, h: 40 });
    assert.equal(s[0].x, 100);
    assert.equal(s[1].x, 300);
    const loop = routeSelfLoop({ x: 0, y: 0, w: 100, h: 40 });
    assert.ok(loop.length >= 4 && loop[0].y === 0);
});

test('Länge, Punkt entlang, Kürzen', () => {
    const pts = [{ x: 0, y: 0 }, { x: 10, y: 0 }, { x: 10, y: 10 }];
    assert.equal(polylineLength(pts), 20);
    assert.deepEqual({ x: pointAlong(pts, 15).x, y: pointAlong(pts, 15).y }, { x: 10, y: 5 });
    assert.deepEqual(trimEnd(pts, 4)[2], { x: 10, y: 6 });
    assert.equal(trimEnd(pts, 0), pts);
});

test('Pfeilspitzen', () => {
    const tri = arrowShape('triangle', { x: 100, y: 50 }, 0);
    assert.deepEqual(tri[0], { x: 100, y: 50 });
    assert.ok(tri[1].x < 100 && tri[2].x < 100 && tri[1].y < 50 && tri[2].y > 50);
    assert.equal(arrowShape('diamond', { x: 0, y: 0 }, 0).length, 4);
    assert.deepEqual(arrowShape('keine', { x: 0, y: 0 }, 0), []);
});

test('Kreiskanten: gerade, Bogen, Schleife', () => {
    const straight = circleEdge({ x: 0, y: 0 }, { x: 100, y: 0 }, 28, 0);
    assert.deepEqual({ x: straight.start.x, y: straight.start.y }, { x: 28, y: 0 });
    assert.deepEqual({ x: straight.end.x, y: straight.end.y }, { x: 72, y: 0 });
    assert.equal(straight.angle, 0);
    const bent = circleEdge({ x: 0, y: 0 }, { x: 100, y: 0 }, 28, 24);
    assert.match(bent.d, /Q/);
    assert.ok(bent.label.y !== 0);
    const loop = circleLoop({ x: 50, y: 50 }, 28);
    assert.match(loop.d, /C/);
    assert.ok(loop.label.y < 50);
});

test('Freie Stelle, Rahmen, IDs, Text', () => {
    const rects = [{ x: 40, y: 40, w: 100, h: 60 }];
    const p = freePosition(rects, { w: 100, h: 60 }, { x: 40, y: 40 }, 30, 10);
    assert.ok(!(p.x < 140 && p.x + 100 > 40 && p.y < 100 && p.y + 60 > 40), 'liegt nicht auf dem vorhandenen Rechteck');
    assert.deepEqual(bbox([{ x: 0, y: 0, w: 10, h: 10 }, { x: 20, y: 5, w: 10, h: 30 }]), { x: 0, y: 0, w: 30, h: 35 });
    assert.equal(bbox([]), null);
    assert.equal(newId('c', ['c1', 'c2', 'c4']), 'c3');
    assert.ok(textWidth('Wwww') > textWidth('iiii'));
    assert.equal(textBlock('a\nbb\nccc').lines.length, 3);
});

// ---------------------------------------------------------------- Verlauf

test('Verlauf: Rückgängig, Wiederholen, Zusammenfassen', () => {
    const h = new History({ n: 0 });
    assert.equal(h.canUndo, false);
    assert.equal(h.record({ n: 1 }, null, 0), true);
    assert.equal(h.record({ n: 1 }, null, 0), false, 'unverändert');
    h.record({ n: 2 }, null, 0);
    assert.deepEqual(h.undo(), { n: 1 });
    assert.deepEqual(h.undo(), { n: 0 });
    assert.equal(h.undo(), null);
    assert.deepEqual(h.redo(), { n: 1 });
    // neuer Zweig löscht die Wiederholen-Liste
    h.record({ n: 9 }, null, 0);
    assert.equal(h.canRedo, false);
    assert.deepEqual(h.current(), { n: 9 });
});

test('Verlauf fasst gleiche Schlüssel kurz hintereinander zusammen', () => {
    const h = new History({ t: '' });
    h.record({ t: 'a' }, 'text', 1000);
    h.record({ t: 'ab' }, 'text', 1500);
    h.record({ t: 'abc' }, 'text', 1900);
    assert.deepEqual(h.undo(), { t: '' }, 'ein einziger Schritt');
    h.redo();
    h.record({ t: 'abcd' }, 'text', 9000); // zu spät: eigener Schritt
    assert.deepEqual(h.undo(), { t: 'abc' });
    h.redo();
    h.record({ t: 'x' }, 'anders', 9100);
    assert.deepEqual(h.undo(), { t: 'abcd' });
});

test('Verlauf ist begrenzt', () => {
    const h = new History(0, { limit: 5 });
    for (let i = 1; i <= 20; i++) {
        h.record(i, null, i);
    }
    let steps = 0;
    while (h.undo() !== null) {
        steps += 1;
    }
    assert.equal(steps, 4);
    h.reset('neu');
    assert.equal(h.canUndo, false);
    assert.equal(h.current(), 'neu');
});

// ---------------------------------------------------------------- Logik

const comp = (id, type, label = '', n) => ({ id, type, label, x: 0, y: 0, ...(n ? { n } : {}) });
const wire = (id, from, to, pin) => ({ id, from: { component: from, pin: 'out' }, to: { component: to, pin } });
const circuit = () => ({
    components: [comp('iA', 'INPUT', 'A'), comp('iB', 'INPUT', 'B'), comp('g1', 'AND'), comp('g2', 'NOT'), comp('g3', 'OR'), comp('oX', 'OUTPUT', 'X')],
    wires: [wire('w1', 'iA', 'g1', 'in0'), wire('w2', 'iB', 'g1', 'in1'), wire('w3', 'iA', 'g2', 'in0'), wire('w4', 'g1', 'g3', 'in0'), wire('w5', 'g2', 'g3', 'in1'), wire('w6', 'g3', 'oX', 'in0')],
});

test('Gatterfunktionen', () => {
    const table = {
        AND: [0, 0, 0, 1], OR: [0, 1, 1, 1], NAND: [1, 1, 1, 0], NOR: [1, 0, 0, 0], XOR: [0, 1, 1, 0], XNOR: [1, 0, 0, 1],
    };
    for (const [type, expect] of Object.entries(table)) {
        [[0, 0], [0, 1], [1, 0], [1, 1]].forEach((inp, i) => assert.equal(gateValue(type, inp), expect[i], `${type} ${inp}`));
    }
    assert.equal(gateValue('NOT', [0]), 1);
    assert.equal(gateValue('XOR', [1, 1, 1]), 1);
    assert.equal(pinCount(comp('a', 'NOT')), 1);
    assert.equal(pinCount(comp('a', 'AND', '', 3)), 3);
    assert.equal(pinCount(comp('a', 'AND', '', 9)), 4);
    assert.equal(pinCount(comp('a', 'INPUT')), 0);
});

test('Schaltung wird ausgewertet, Wahrheitstabelle stimmt mit dem Server überein', () => {
    const t = truthTable(circuit(), ['A', 'B'], ['X']);
    assert.deepEqual(t.errors, []);
    assert.deepEqual(t.table, { '00': { X: 1 }, '01': { X: 1 }, 10: { X: 0 }, 11: { X: 1 } });
    const r = evaluate(circuit(), { A: 1, B: 1 });
    assert.equal(r.values.get('g1'), 1);
    assert.equal(r.outputs.X, 1);
    assert.deepEqual(rowKeys(2), ['00', '01', '10', '11']);
    assert.equal(compareTables({ 10: { X: 0 }, 11: { X: 1 } }, t.table), 1);
    assert.equal(compareTables({ 10: { X: 1 }, 11: { X: 1 } }, t.table), 0.5);
});

test('Zyklen werden erkannt', () => {
    const loop = {
        components: [comp('g1', 'NOT'), comp('g2', 'NOT'), comp('oX', 'OUTPUT', 'X')],
        wires: [wire('w1', 'g1', 'g2', 'in0'), wire('w2', 'g2', 'g1', 'in0'), wire('w3', 'g2', 'oX', 'in0')],
    };
    const r = evaluate(loop, {});
    assert.ok(r.errors[0].includes('Rückkopplung'));
    assert.ok(r.cyclic.size > 0);
    assert.deepEqual(truthTable(loop, ['A'], ['X']).table, {});
});

test('offene Eingänge zählen als 0 und werden gemeldet', () => {
    const c = { components: [comp('iA', 'INPUT', 'A'), comp('g1', 'AND'), comp('oX', 'OUTPUT', 'X')], wires: [wire('w1', 'iA', 'g1', 'in0'), wire('w2', 'g1', 'oX', 'in0')] };
    const r = evaluate(c, { A: 1 });
    assert.equal(r.outputs.X, 0);
    assert.ok(r.open.has('g1.in1'));
});

// ---------------------------------------------------------------- Automaten

/** DEA: endet auf "ab" */
const dfa = () => ({
    states: [
        { id: 'q0', name: 'q0', initial: true, accepting: false },
        { id: 'q1', name: 'q1', initial: false, accepting: false },
        { id: 'q2', name: 'q2', initial: false, accepting: true },
    ],
    transitions: [
        { id: 't1', from: 'q0', to: 'q1', symbols: ['a'] }, { id: 't2', from: 'q0', to: 'q0', symbols: ['b'] },
        { id: 't3', from: 'q1', to: 'q1', symbols: ['a'] }, { id: 't4', from: 'q1', to: 'q2', symbols: ['b'] },
        { id: 't5', from: 'q2', to: 'q1', symbols: ['a'] }, { id: 't6', from: 'q2', to: 'q0', symbols: ['b'] },
    ],
});

test('DEA-Simulation', () => {
    for (const w of ['ab', 'aab', 'bbab']) {
        assert.equal(accepts(dfa(), w), true, w);
    }
    for (const w of ['', 'a', 'b', 'abb', 'ba']) {
        assert.equal(accepts(dfa(), w), false, w);
    }
    const r = run(dfa(), 'ab');
    assert.deepEqual(r.sets, [['q0'], ['q1'], ['q2']]);
    assert.equal(r.accepted, true);
    assert.deepEqual(dfaIssues(dfa(), ['a', 'b']), []);
});

test('NEA mit Epsilon, Hänger und fehlender Start', () => {
    const nfa = {
        states: [{ id: 's0', initial: true }, { id: 's1' }, { id: 's2', accepting: true }],
        transitions: [{ from: 's0', to: 's0', symbols: ['a', 'b'] }, { from: 's0', to: 's1', symbols: ['a'] }, { from: 's1', to: 's2', symbols: ['ε'] }],
    };
    assert.equal(accepts(nfa, 'bba'), true);
    assert.equal(accepts(nfa, 'ab'), false);
    assert.deepEqual(closure(['s1'], transitionTable(nfa)), ['s1', 's2']);
    assert.deepEqual(startSet(nfa), ['s0']);
    const stuck = run(dfa(), 'ac');
    assert.equal(stuck.stuckAt, 1);
    assert.equal(run({ states: [{ id: 'x' }], transitions: [] }, 'a'), null);
});

test('DEA-Hinweise und Testwörter', () => {
    const nd = dfa();
    nd.transitions.push({ id: 't9', from: 'q0', to: 'q2', symbols: ['a'] });
    assert.match(dfaIssues(nd, ['a', 'b']).join(' '), /nicht deterministisch/);
    const incomplete = dfa();
    incomplete.transitions.pop();
    assert.match(dfaIssues(incomplete, ['a', 'b']).join(' '), /nicht vollständig/);
    const words = generateWords(dfa(), ['a', 'b'], { maxLength: 4, perKind: 5 });
    assert.ok(words.accept.length > 0 && words.reject.length > 0);
    assert.ok(words.accept.every((w) => accepts(dfa(), w)));
    assert.ok(words.reject.every((w) => !accepts(dfa(), w)));
    assert.deepEqual(generateWords({ states: [], transitions: [] }, ['a']), { accept: [], reject: [] });
});

// ---------------------------------------------------------------- Struktogramm

const sample = () => normalizeStructogram({
    root: {
        type: 'sequence',
        children: [
            { id: 'b1', type: 'statement', text: 'summe := 0' },
            {
                id: 'b2', type: 'for', head: 'i von 1 bis n', body: {
                    type: 'sequence', children: [{
                        id: 'b3', type: 'if', cond: 'i gerade', then: { type: 'sequence', children: [{ id: 'b4', type: 'statement', text: 'summe := summe + i' }] }, else: { type: 'sequence', children: [] },
                    }],
                },
            },
            { id: 'b5', type: 'switch', expr: 'x', cases: [{ label: '1', body: { type: 'sequence', children: [] } }, { label: 'sonst', body: { type: 'sequence', children: [{ id: 'b6', type: 'break', text: '' }] } }] },
        ],
    },
});

test('Struktogramm-Modell: Normalisieren, Suchen, Einfügen, Löschen', () => {
    const m = sample();
    assert.deepEqual(flatOrder(m.root), ['b1', 'b2', 'b3', 'b4', 'b5', 'b6']);
    assert.equal(findBlock(m.root, 'b4').key, 'b3/then');
    assert.equal(findBlock(m.root, 'b6').key, 'b5/case/1');
    const added = newBlock('while', collectIds(m.root));
    assert.equal(added.id, 'b7');
    assert.ok(insertBlock(m.root, 'b3/else', 0, added));
    assert.equal(findBlock(m.root, 'b7').key, 'b3/else');
    assert.equal(insertBlock(m.root, 'gibt/es/nicht', 0, newBlock('statement', collectIds(m.root))), false);
    assert.equal(removeBlock(m.root, 'b2').id, 'b2');
    assert.deepEqual(flatOrder(m.root), ['b1', 'b5', 'b6']);
    assert.equal(removeBlock(m.root, 'nix'), null);
});

test('Struktogramm-Modell: Verschieben, Einrücken, Ausrücken', () => {
    const m = sample();
    assert.equal(moveBlock(m.root, 'b2', -1), true);
    assert.deepEqual(m.root.children.map((b) => b.id), ['b2', 'b1', 'b5']);
    assert.equal(moveBlock(m.root, 'b2', -1), false);
    // b1 in die Schleife b2 aufnehmen (steht jetzt direkt dahinter, daher "vorheriger Block" = b2)
    assert.equal(indentBlock(m.root, 'b1'), true);
    assert.equal(findBlock(m.root, 'b1').key, 'b2/body');
    assert.equal(outdentBlock(m.root, 'b1'), true);
    assert.equal(findBlock(m.root, 'b1').key, 'root');
    assert.equal(outdentBlock(m.root, 'b1'), false);
    assert.equal(indentBlock(m.root, 'b2'), false, 'erster Block');
});

test('Struktogramm-Modell: kaputte Daten werden bereinigt', () => {
    const m = normalizeStructogram({ root: { children: [{ id: 'a', type: 'statement' }, { id: 'a', type: 'statement', text: 'doppelt' }, { type: 'unbekannt' }, null, 'x'] } });
    assert.equal(m.root.children.length, 2);
    assert.notEqual(m.root.children[0].id, m.root.children[1].id);
    assert.deepEqual(normalizeStructogram(null).root, { type: 'sequence', children: [] });
    for (const type of Object.keys(BLOCK_TYPES)) {
        assert.equal(newBlock(type, []).type, type);
    }
});

test('Struktogramm-Layout: Breite folgt dem Text, Blöcke liegen lückenlos', () => {
    const m = sample();
    const l = layoutStructogram(m.root, { minWidth: 260 });
    assert.ok(l.width >= 260);
    const b1 = l.blocks.get('b1');
    const b2 = l.blocks.get('b2');
    const b5 = l.blocks.get('b5');
    assert.equal(b1.y + b1.h, b2.y, 'b2 schließt direkt an b1 an');
    assert.equal(b2.y + b2.h, b5.y);
    assert.equal(b1.w, l.width);
    assert.equal(l.height, b5.y + b5.h);
    // Kind liegt innerhalb des Elternblocks
    const b3 = l.blocks.get('b3');
    assert.ok(b3.x >= b2.x && b3.x + b3.w <= b2.x + b2.w && b3.y >= b2.y && b3.y + b3.h <= b2.y + b2.h);
    // Einfügepunkte: am Anfang, zwischen und nach den Blöcken
    const rootSlots = l.slots.filter((s) => s.key === 'root');
    assert.equal(rootSlots.length, 4);
    assert.ok(l.slots.some((s) => s.key === 'b3/else' && s.label === 'leer'));
    assert.ok(l.prims.some((p) => p.t === 'text' && p.text === 'Ja'));
    assert.ok(l.prims.some((p) => p.t === 'line'));
});

test('Struktogramm-Layout: lange Texte machen die Blöcke breiter, mehrzeilige höher', () => {
    const short = normalizeStructogram({ root: { children: [{ id: 'a', type: 'statement', text: 'x' }] } });
    const long = normalizeStructogram({ root: { children: [{ id: 'a', type: 'statement', text: 'x'.repeat(120) }] } });
    const multi = normalizeStructogram({ root: { children: [{ id: 'a', type: 'statement', text: 'a\nb\nc' }] } });
    assert.ok(layoutStructogram(long.root).width > layoutStructogram(short.root).width);
    assert.ok(layoutStructogram(multi.root).height > layoutStructogram(short.root).height);
    assert.ok(measureBlock(short.root.children[0]).h >= 28);
    const empty = layoutStructogram({ type: 'sequence', children: [] });
    assert.ok(empty.height > 0 && empty.slots.length === 1);
});

// ---------------------------------------------------------------- Tabellen

test('Wahrheitstabelle: Zeilen bereinigen und vorgegebene Zellen erzwingen', () => {
    const config = { inputs: ['A', 'B'], outputs: ['X'], prefilled: { 11: { X: '1' } } };
    const m = normalizeRows({ rows: { '00': { X: 0 }, '77': { X: '1' }, '01': { X: 'z' }, 11: { X: '0' } } }, config);
    assert.deepEqual(m.rows, { '00': { X: '0' }, 11: { X: '1' } });
    assert.equal(isPrefilled(config, '11', 'X'), true);
    assert.equal(isPrefilled(config, '00', 'X'), false);
    assert.equal(nextCell(''), '0');
    assert.equal(nextCell('0'), '1');
    assert.equal(nextCell('1'), '');
    assert.equal(compareRows({ rows: { '00': { X: '0' }, '01': { X: '1' }, 11: { X: '1' } } }, { rows: { '00': { X: '0' }, '01': { X: '0' } } }, config), 0.5);
});

test('Relationenschema: Normalisieren, Darstellung, Verweise', () => {
    const schema = normalizeSchema({
        tables: [
            { id: 't1', name: 'Kurs', columns: [{ name: 'id', pk: true }, { name: 'titel' }] },
            { id: 't2', name: 'Schueler', columns: [{ name: 'id', pk: true }, { name: 'name' }, { name: 'kurs_id', fk: 'Kurs.id' }, { name: 'x', fk: 'ohnepunkt' }] },
        ],
    });
    assert.equal(schema.tables[1].columns[2].fk, 'Kurs.id');
    assert.equal(schema.tables[1].columns[3].fk, null);
    assert.equal(schemaText(schema.tables[1]), 'Schueler(_id_, name, ↑kurs_id, x)');
    assert.deepEqual(schemaSegments(schema.tables[0]).filter((s) => s.pk).map((s) => s.text), ['id']);
    assert.deepEqual(fkTargets(schema, 't2'), ['Kurs.id', 'Kurs.titel']);
    renameTableReferences(schema, 'Kurs', 'Lehrgang');
    assert.equal(schema.tables[1].columns[2].fk, 'Lehrgang.id');
    renameReferences(schema, 'Lehrgang.id', 'Lehrgang.nr');
    assert.equal(schema.tables[1].columns[2].fk, 'Lehrgang.nr');
    assert.deepEqual(normalizeSchema(null), { tables: [] });
});
