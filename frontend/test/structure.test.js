import test from 'node:test';
import assert from 'node:assert/strict';

import { installDom, key, pointer } from './support/dom-stub.js';
import {
    chargeText, chemText, hillFormula, molecules, normalizeStructure, normalizeStructureConfig, subscript, symbolOf, template,
} from '../src/widgets/diagrams/core/structure-model.js';

const body = installDom();
await import('../src/widgets/diagrams/index.js');
await import('../src/widgets/editors/diagrams/index.js');
const { answerWidget, taskEditor } = await import('../src/lib/widgets.js');

const atom = (id, el, x = 0, y = 0, extra = {}) => ({ id, el, x, y, charge: 0, pairs: [], radicals: 0, ...extra });
const bond = (id, a, b, order = 1) => ({ id, a, b, order, stereo: 'none' });

// ---------- Modell ----------

test('Summenformel in Hill-Reihenfolge mit Tiefzahlen', () => {
    assert.equal(subscript(12), '₁₂');
    assert.equal(hillFormula({ O: 1, H: 6, C: 2 }), 'C₂H₆O');
    assert.equal(hillFormula({ H: 4, C: 1 }), 'CH₄');
    assert.equal(hillFormula({ Na: 1, Cl: 1 }), 'ClNa');
    assert.equal(hillFormula({ H: 2, O: 1 }), 'H₂O');
    assert.equal(hillFormula({ C: 1, Cl: 4 }), 'CCl₄');
    assert.equal(chargeText(0), 'ungeladen');
    assert.equal(chargeText(1), '+');
    assert.equal(chargeText(-2), '2−');
});

test('Moleküle: Zusammenhang, Formel und Gesamtladung', () => {
    const model = {
        atoms: [
            atom('n', 'N', 0, 0, { charge: 1 }), atom('h1', 'H', 50), atom('h2', 'H', 50, 50), atom('h3', 'H', 0, 50), atom('h4', 'H', -50),
            atom('o', 'O', 300), atom('h5', 'H', 350),
        ],
        bonds: [bond('b1', 'n', 'h1'), bond('b2', 'n', 'h2'), bond('b3', 'n', 'h3'), bond('b4', 'n', 'h4'), bond('b5', 'o', 'h5')],
        texts: [],
        arrows: [],
    };
    const mols = molecules(model);
    assert.equal(mols.length, 2);
    assert.equal(mols[0].formula, 'H₄N');
    assert.equal(mols[0].charge, 1);
    assert.equal(mols[0].bondIds.length, 4);
    assert.equal(mols[1].formula, 'HO');
});

test('Ethanol und Dimethylether haben dieselbe Summenformel', () => {
    const ethanol = {
        atoms: [atom('c1', 'C'), atom('c2', 'C', 50), atom('o', 'O', 100), ...[1, 2, 3, 4, 5, 6].map((i) => atom(`h${i}`, 'H', i * 10, 90))],
        bonds: [], texts: [], arrows: [],
    };
    ethanol.bonds = [bond('b1', 'c1', 'c2'), bond('b2', 'c2', 'o'), ...[1, 2, 3].map((i) => bond(`bh${i}`, 'c1', `h${i}`)), bond('b6', 'c2', 'h4'), bond('b7', 'c2', 'h5'), bond('b8', 'o', 'h6')];
    assert.equal(molecules(ethanol)[0].formula, 'C₂H₆O');
});

test('Normalisierung verwirft Fremdwerte und begrenzt', () => {
    assert.equal(symbolOf('cl'), 'Cl');
    assert.equal(symbolOf('Xx'), null);
    assert.equal(symbolOf('<b>'), null);
    const m = normalizeStructure({
        atoms: [
            { id: 'a1', el: 'cl', x: 99999, y: -99999, charge: 9, pairs: ['top', 'top', 'unten', 'left'], radicals: 7 },
            { id: 'a2', el: 'Xx' },
            { id: 'a3', el: 'O', charge: -5 },
            null,
        ],
        bonds: [
            { id: 'b1', a: 'a1', b: 'a3', order: 5, stereo: 'wedge' },
            { id: 'b2', a: 'a3', b: 'a1', order: 1 },
            { id: 'b3', a: 'a1', b: 'a1' },
            { id: 'b4', a: 'a1', b: 'fehlt' },
        ],
        texts: [{ id: 't1', text: '  Reaktion  1 ' }, { id: 't2', text: '' }],
        arrows: [{ id: 'r1', len: 5000, kind: 'doppel' }, { id: 'r2', kind: 'equilibrium' }],
    });
    assert.equal(m.atoms.length, 2);
    assert.equal(m.atoms[0].el, 'Cl');
    assert.equal(m.atoms[0].x, 8000);
    assert.equal(m.atoms[0].y, -3000);
    assert.equal(m.atoms[0].charge, 3);
    assert.deepEqual(m.atoms[0].pairs, ['top', 'left']);
    assert.equal(m.atoms[0].radicals, 4);
    assert.equal(m.atoms[1].charge, -2);
    assert.equal(m.bonds.length, 1);
    assert.equal(m.bonds[0].order, 3);
    assert.equal(m.bonds[0].stereo, 'none');
    assert.equal(m.texts.length, 1);
    assert.equal(m.texts[0].text, 'Reaktion 1');
    assert.equal(m.arrows[0].len, 400);
    assert.equal(m.arrows[0].kind, 'forward');
    assert.equal(m.arrows[1].kind, 'equilibrium');
    const many = normalizeStructure({ atoms: Array.from({ length: 400 }, (_, i) => ({ id: `a${i}`, el: 'C' })) });
    assert.equal(many.atoms.length, 150);
    for (const junk of [null, 5, 'x', [], { atoms: 'x', bonds: 7, texts: [1], arrows: {} }]) {
        assert.deepEqual(Object.keys(normalizeStructure(junk)), ['atoms', 'bonds', 'texts', 'arrows']);
    }
});

test('Konfiguration: Startzeichnung gilt nur ohne gespeicherte Antwort', () => {
    const cfg = normalizeStructureConfig({ score_pairs: 'true', start: { atoms: [atom('a1', 'C')] } });
    assert.equal(cfg.score_pairs, true);
    assert.equal(cfg.start.atoms.length, 1);
    assert.equal(normalizeStructureConfig({ start: { atoms: [] } }).start, null);
    assert.equal(normalizeStructure(null, cfg).atoms.length, 1);
    assert.equal(normalizeStructure({}, cfg).atoms.length, 1);
    assert.equal(normalizeStructure({ atoms: [] }, cfg).atoms.length, 0, 'bewusst geleerte Antwort bleibt leer');
});

test('Vorlagen und Beschriftungen', () => {
    const benzene = template('benzene');
    assert.equal(benzene.atoms.length, 12);
    assert.equal(benzene.bonds.filter((b) => b[2] === 2).length, 3);
    assert.equal(hillFormula(molecules(normalizeStructure({
        atoms: benzene.atoms.map((a) => atom(a.key, a.el, a.x, a.y)),
        bonds: benzene.bonds.map(([a, b, o], i) => bond(`b${i}`, a, b, o)),
    }))[0].counts), 'C₆H₆');
    assert.equal(template('cyclohexane').atoms.length, 18);
    assert.equal(template('carboxy').attach, 'c');
    assert.equal(template('nix'), null);
    assert.equal(chemText('H2O + 2 CO2'), 'H₂O + 2 CO₂');
});

// ---------- Widget ----------

function mount(type, { config = {}, content = null, readOnly = false } = {}) {
    const host = document.createElement('div');
    body.append(host);
    const changes = [];
    const inst = answerWidget(type).mount(host, {
        task: { id: 7, answer_type: type, config }, content, readOnly, mode: 'exam', env: { examId: 3 }, onChange: (c, meta) => changes.push({ c, meta }),
    });

    return { host, inst, changes };
}
const byId = (host, id) => host.find((e) => e.getAttribute('data-id') === id)[0];
const button = (host, label) => host.find((e) => e.localName === 'button' && e.textContent.trim() === label)[0];
const click = (el) => {
    pointer(el, 'pointerdown', 10, 10);
    pointer(el, 'pointerup', 10, 10);
};
const formulaText = (host) => host.find((e) => e.className.includes('dg-formula-text')).map((e) => e.textContent);

test('Widget ist registriert, hängt leer ein und übersteht kaputte Inhalte', async () => {
    assert.ok(answerWidget('structure'));
    for (const junk of [null, {}, [], 'x', 5, { atoms: 'x', bonds: [null], texts: 3 }]) {
        const { inst, changes } = mount('structure', { content: junk });
        assert.deepEqual(Object.keys(inst.getContent()), ['atoms', 'bonds', 'texts', 'arrows']);
        inst.setReadOnly(true);
        inst.setReadOnly(false);
        const png = await inst.getPreviewPng();
        assert.ok(png === null || png.startsWith('data:image/png'));
        inst.destroy();
        assert.equal(changes.length, 0);
    }
});

test('Atome anhängen, Bindungsart per Taste, Summenformel live', () => {
    const { host, inst } = mount('structure');
    button(host, 'C').click();
    assert.equal(inst.getContent().atoms.length, 1);
    button(host, 'O').click(); // am gewählten C angehängt
    let c = inst.getContent();
    assert.equal(c.atoms.length, 2);
    assert.equal(c.bonds.length, 1);
    assert.deepEqual(formulaText(host), ['CO']);
    // gewählt ist jetzt das O; Bindung wählen, Taste 2
    click(byId(host, 'b1'));
    const svg = host.find((e) => e.localName === 'svg' && e.className.includes('dg-svg'))[0];
    key(svg, '2');
    c = inst.getContent();
    assert.equal(c.bonds[0].order, 2);
    key(svg, '3');
    assert.equal(inst.getContent().bonds[0].order, 3);
    // Entf löscht die Bindung
    key(svg, 'Delete');
    assert.equal(inst.getContent().bonds.length, 0);
    assert.deepEqual(formulaText(host), ['C', 'O']);
    inst.destroy();
});

test('Verbinden-Werkzeug: erneutes Verbinden steigert die Bindungsordnung', () => {
    const { host, inst } = mount('structure', { content: { atoms: [atom('a1', 'C', 0, 0), atom('a2', 'O', 80, 0)] } });
    button(host, 'Binden').click();
    for (let i = 0; i < 3; i += 1) {
        click(byId(host, 'a1'));
        click(byId(host, 'a2'));
        assert.equal(inst.getContent().bonds[0].order, [1, 2, 3][i]);
    }
    click(byId(host, 'a1'));
    click(byId(host, 'a2'));
    assert.equal(inst.getContent().bonds[0].order, 1);
    inst.destroy();
});

test('Vorlagen: Benzolring frei, OH am gewählten Atom', () => {
    const { host, inst } = mount('structure');
    button(host, 'Benzolring').click();
    assert.equal(inst.getContent().atoms.length, 12);
    assert.deepEqual(formulaText(host), ['C₆H₆']);
    inst.destroy();
    const b = mount('structure', { content: { atoms: [atom('a1', 'C', 100, 100)] } });
    click(byId(b.host, 'a1'));
    button(b.host, 'OH').click();
    const c = b.inst.getContent();
    assert.equal(c.atoms.length, 3);
    assert.equal(c.bonds.length, 2);
    assert.deepEqual(formulaText(b.host), ['CHO']);
    b.inst.destroy();
});

test('Undo und Redo, Startzeichnung, Beschriftung und Pfeile', () => {
    const { host, inst, changes } = mount('structure', { config: { start: { atoms: [atom('a1', 'C', 50, 50)] } } });
    assert.equal(inst.getContent().atoms.length, 1, 'Startzeichnung sichtbar');
    button(host, 'H').click();
    assert.equal(inst.getContent().atoms.length, 2);
    button(host, 'Rückgängig').click();
    assert.equal(inst.getContent().atoms.length, 1);
    button(host, 'Wiederholen').click();
    assert.equal(inst.getContent().atoms.length, 2);
    button(host, '＋ Text').click();
    button(host, '→').click();
    button(host, '⇌').click();
    const c = inst.getContent();
    assert.equal(c.texts.length, 1);
    assert.deepEqual(c.arrows.map((a) => a.kind), ['forward', 'equilibrium']);
    assert.ok(changes.length === 0 || changes.every((x) => x.meta.kind === 'value'));
    inst.destroy();
});

test('Aufgaben-Editor: Konfiguration, Vorgabe und Musterlösung', () => {
    const host = document.createElement('div');
    body.append(host);
    const inst = taskEditor('structure').mount(host, { config: { score_pairs: true, start: { atoms: [atom('a1', 'C')] } }, solution: { atoms: [atom('a1', 'O')] }, files: [], tests: [], onChange() {} });
    const v = inst.getValue();
    assert.equal(v.config.score_pairs, true);
    assert.equal(v.config.start.atoms.length, 1);
    assert.equal(v.solution.atoms[0].el, 'O');
    assert.deepEqual(inst.validate(), []);
    inst.destroy();
    const empty = document.createElement('div');
    body.append(empty);
    const e = taskEditor('structure').mount(empty, { config: {}, solution: {}, files: [], tests: [], onChange() {} });
    assert.equal(e.getValue().config.start, null);
    assert.equal(e.getValue().config.score_pairs, false);
    e.destroy();
});
