import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { installDom, key, pointer } from './support/dom-stub.js';

const body = installDom();
await import('../src/widgets/editors/diagrams/index.js');
const { taskEditor } = await import('../src/lib/widgets.js');

const fixtures = {};

function mount(type, { config = {}, solution = {}, locked = false } = {}) {
    const host = document.createElement('div');
    body.append(host);
    let changes = 0;
    const inst = taskEditor(type).mount(host, { config, solution, files: [], tests: [], locked, onChange: () => { changes += 1; } });

    return { host, inst, changes: () => changes };
}

const button = (host, label) => host.find((e) => e.localName === 'button' && e.textContent.trim() === label)[0];
const click = (el) => {
    pointer(el, 'pointerdown', 5, 5);
    pointer(el, 'pointerup', 5, 5);
};
const save = (type, v) => {
    fixtures[type] = v;
};

test('für alle neun Diagramm-Typen gibt es einen Aufgaben-Editor', () => {
    for (const t of ['drawing', 'uml', 'structogram', 'flowchart', 'logic', 'truth_table', 'automaton', 'er', 'relational']) {
        const { inst } = mount(t);
        const v = inst.getValue();
        assert.ok(v.config && typeof v.config === 'object', t);
        assert.ok(v.solution && typeof v.solution === 'object', t);
        assert.ok(Array.isArray(inst.validate()), t);
        inst.destroy();
    }
});

test('Zeichnung: Größe, Upload und Fehlermeldungen', () => {
    const { host, inst, changes } = mount('drawing', { config: { width: 800, height: 500, allow_upload: true } });
    assert.deepEqual(inst.getValue().config, { width: 800, height: 500, background_material_id: null, allow_upload: true });
    const [w] = host.find((e) => e.localName === 'input' && e.getAttribute('type') === 'number');
    w.value = '100';
    w.dispatch('input', {});
    assert.ok(changes() >= 1);
    assert.ok(inst.validate()[0].includes('Breite'));
    w.value = '1200';
    w.dispatch('input', {});
    assert.deepEqual(inst.validate(), []);
    save('drawing', inst.getValue());
    inst.destroy();
});

test('UML: Musterdiagramm mit demselben Widget zeichnen', () => {
    const { host, inst, changes } = mount('uml', { config: { allow_interfaces: true } });
    button(host, '＋ Klasse').click();
    button(host, '＋ Interface').click();
    assert.equal(inst.getValue().solution.classes.length, 2);
    assert.equal(inst.getValue().config.allow_interfaces, true);
    button(host, 'Realisierung').click();
    click(host.find((e) => e.getAttribute('data-id') === 'c1')[0]);
    click(host.find((e) => e.getAttribute('data-id') === 'c2')[0]);
    assert.equal(inst.getValue().solution.relations.length, 1);
    assert.deepEqual(inst.validate(), []);
    // Interfaces verbieten: Werkzeug verschwindet
    const box = host.find((e) => e.localName === 'input' && e.getAttribute('type') === 'checkbox' && e.parentNode?.textContent.includes('Interfaces erlauben'))[0];
    box.checked = false;
    box.dispatch('change', {});
    assert.equal(inst.getValue().config.allow_interfaces, false);
    assert.equal(button(host, '＋ Interface'), undefined);
    assert.ok(changes() > 0);
    save('uml', inst.getValue());
    inst.destroy();
});

test('Logik: Namen, Gatter, Musterschaltung und Tabelle', () => {
    const { host, inst } = mount('logic', { config: { inputs: ['A', 'B'], outputs: ['X'] } });
    const fields = host.find((e) => e.localName === 'input' && e.getAttribute('maxlength') === '120');
    fields[0].value = 'A, B, C';
    fields[0].dispatch('input', {});
    let v = inst.getValue();
    assert.deepEqual(v.config.inputs, ['A', 'B', 'C']);
    assert.equal(Object.keys(v.solution.truth_table).length, 8);
    assert.deepEqual(inst.validate(), []);

    fields[0].value = '1x, B';
    fields[0].dispatch('input', {});
    assert.ok(inst.validate().some((e) => e.includes('Ungültiger Name')));
    fields[0].value = 'A, B';
    fields[0].dispatch('input', {});
    fields[1].value = 'A';
    fields[1].dispatch('input', {});
    assert.ok(inst.validate().some((e) => e.includes('gleichzeitig')));
    fields[1].value = 'X';
    fields[1].dispatch('input', {});
    assert.deepEqual(inst.validate(), []);

    // Musterschaltung: X = A AND B
    button(host, 'AND').click();
    const gate = inst.getValue().solution.circuit.components.find((c) => c.type === 'AND');
    const svg = host.find((e) => e.localName === 'svg' && e.className.includes('dg-svg'))[0];
    const port = (p) => host.find((e) => e.getAttribute('data-port') === p)[0];
    const drag = (from, to) => {
        pointer(port(from), 'pointerdown', 1, 1);
        pointer(svg, 'pointermove', 90, 90);
        pointer(port(to), 'pointerup', 90, 90);
    };
    drag('in_A.out', `${gate.id}.in0`);
    drag('in_B.out', `${gate.id}.in1`);
    drag(`${gate.id}.out`, 'out_X.in0');
    button(host, 'Tabelle aus Schaltung berechnen').click();
    v = inst.getValue();
    assert.deepEqual(v.solution.truth_table, { '00': { X: 0 }, '01': { X: 0 }, 10: { X: 0 }, 11: { X: 1 } });
    assert.ok(v.solution.circuit.wires.length === 3);
    assert.deepEqual(v.config.allowed_gates, ['AND', 'OR', 'NOT', 'NAND', 'NOR', 'XOR', 'XNOR']);
    const status = host.find((e) => e.className.includes('te-status-line'))[0];
    assert.ok(status.textContent.includes('passt zur Wahrheitstabelle'), status.textContent);
    // Tabellenzelle kippen: Hinweis auf Abweichung
    const cell = host.find((e) => e.className.includes('w-truth-cell') && e.getAttribute('aria-label')?.startsWith('Zeile 00'))[0];
    cell.click();
    assert.ok(status.textContent.includes('stimmt nur in'), status.textContent);
    save('logic', inst.getValue());
    inst.destroy();
});

test('Wahrheitstabelle: Lösung vollständig, Vorgaben, unvollständig meldet Fehler', () => {
    const { host, inst } = mount('truth_table', { config: { inputs: ['A', 'B'], outputs: ['X'] } });
    const cells = () => host.find((e) => e.className.includes('w-truth-cell'));
    assert.equal(cells().length, 4);
    assert.deepEqual(inst.getValue().solution, { rows: {} });
    cells()[0].click(); // 0
    cells()[1].click(); // 0
    assert.ok(inst.validate().some((e) => e.includes('unvollständig')));
    cells()[1].click(); // 1
    cells()[2].click(); // 0
    cells()[3].click();
    cells()[3].click(); // 1
    assert.deepEqual(inst.validate(), []);
    assert.deepEqual(inst.getValue().solution.rows, { '00': { X: '0' }, '01': { X: '1' }, 10: { X: '0' }, 11: { X: '1' } });
    // Vorgabe markieren
    const modes = host.find((e) => e.localName === 'input' && e.getAttribute('type') === 'radio');
    modes[1].checked = true;
    modes[1].dispatch('change', {});
    cells()[3].click();
    assert.deepEqual(inst.getValue().config.prefilled, { 11: { X: '1' } });
    save('truth_table', inst.getValue());
    inst.destroy();
});

test('Automat: Testwörter prüfen, aus Musterautomat erzeugen', () => {
    const { host, inst } = mount('automaton', { config: { kind: 'dfa', alphabet: ['a', 'b'] } });
    const areas = host.find((e) => e.localName === 'textarea');
    areas[0].value = 'ab\naab';
    areas[0].dispatch('input', {});
    areas[1].value = 'ε\nb';
    areas[1].dispatch('input', {});
    assert.deepEqual(inst.validate(), []);
    assert.deepEqual(inst.getValue().solution, { accept: ['ab', 'aab'], reject: ['', 'b'] });
    areas[1].value = 'ab';
    areas[1].dispatch('input', {});
    assert.ok(inst.validate().some((e) => e.includes('akzeptiert und bei verworfen')));
    areas[1].value = 'abc';
    areas[1].dispatch('input', {});
    assert.ok(inst.validate().some((e) => e.includes('nicht im Alphabet')));
    areas[1].value = 'b';
    areas[1].dispatch('input', {});

    // Musterautomat: akzeptiert alle Wörter, die auf b enden
    button(host, '＋ Zustand').click();
    button(host, '＋ Zustand').click();
    button(host, '→ Übergang').click();
    const state = (id) => host.find((e) => e.getAttribute('data-id') === id)[0];
    click(state('q1'));
    click(state('q2'));
    click(state('q2'));
    click(state('q1'));
    inst.instanceForTest = null;
    const status = host.find((e) => e.className.includes('te-status-line'))[0];
    assert.ok(status.textContent.length > 0);
    button(host, 'Testwörter aus Musterautomat erzeugen').click();
    const v = inst.getValue();
    assert.ok(v.solution.automaton.states.length === 2);
    assert.ok(Array.isArray(v.solution.accept) && Array.isArray(v.solution.reject));
    assert.ok(v.solution.accept.length + v.solution.reject.length > 0);
    assert.deepEqual(inst.validate(), []);
    save('automaton', inst.getValue());
    inst.destroy();
});

test('Struktogramm, PAP, ER, Relationenschema: Muster zeichnen', () => {
    // Struktogramm
    let m = mount('structogram');
    button(m.host, 'Anweisung').click();
    click(m.host.find((e) => e.getAttribute('data-action') === 'slot')[0]);
    assert.equal(m.inst.getValue().solution.root.children.length, 1);
    save('structogram', m.inst.getValue());
    m.inst.destroy();
    // PAP
    m = mount('flowchart');
    button(m.host, 'Start').click();
    assert.equal(m.inst.getValue().solution.nodes.length, 1);
    save('flowchart', m.inst.getValue());
    m.inst.destroy();
    // ER
    m = mount('er');
    button(m.host, '＋ Entität').click();
    button(m.host, '＋ Entität').click();
    button(m.host, '＋ Beziehung').click();
    save('er', m.inst.getValue());
    assert.equal(m.inst.getValue().solution.entities.length, 2);
    m.inst.destroy();
    // Relationenschema
    m = mount('relational');
    button(m.host, '+ Tabelle').click();
    assert.ok(m.inst.validate().some((e) => e.includes('Namen')));
    const names = m.host.find((e) => e.className.includes('rel-name'));
    names[0].value = 'Kurs';
    names[0].dispatch('input', {});
    const cols = m.host.find((e) => e.localName === 'input' && e.getAttribute('placeholder') === 'Spaltenname');
    cols[0].value = 'id';
    cols[0].dispatch('input', {});
    assert.deepEqual(m.inst.validate(), []);
    save('relational', m.inst.getValue());
    m.inst.destroy();
});

test('gesperrte Aufgabe: Konfiguration deaktiviert, Musterlösung bleibt bearbeitbar', () => {
    const { host, inst } = mount('truth_table', { config: { inputs: ['A'], outputs: ['X'] }, locked: true });
    const names = host.find((e) => e.localName === 'input' && e.getAttribute('maxlength') === '120');
    assert.ok(names.every((n) => n.disabled === true || n.getAttribute('disabled') !== null));
    const cell = host.find((e) => e.className.includes('w-truth-cell'))[0];
    cell.click();
    assert.equal(inst.getValue().solution.rows['0'], undefined, 'noch unvollständig (eine von zwei Zeilen)');
    inst.destroy();
});

test.after(() => {
    const dir = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', '..', '.tmp-build');
    fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(path.join(dir, 'diagram-editor-fixtures.json'), JSON.stringify(fixtures, null, 2));
});

void key;
