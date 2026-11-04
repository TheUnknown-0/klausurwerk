import test from 'node:test';
import assert from 'node:assert/strict';

import { installDom, key, pointer, sleep } from './support/dom-stub.js';

const body = installDom();
await import('../src/widgets/diagrams/index.js');
const { answerWidget } = await import('../src/lib/widgets.js');

/** Hängt ein Widget ein und sammelt die gemeldeten Änderungen. */
function mount(type, { config = {}, content = null, readOnly = false, mode = 'exam', env = {} } = {}) {
    const host = document.createElement('div');
    body.append(host);
    const changes = [];
    const ctx = {
        task: { id: 7, answer_type: type, config },
        content,
        readOnly,
        mode,
        env: { examId: 3, ...env },
        onChange: (c, meta) => changes.push({ c, meta }),
    };
    const inst = answerWidget(type).mount(host, ctx);

    return { host, inst, changes, ctx };
}

const byId = (host, id) => host.find((e) => e.getAttribute('data-id') === id)[0];
const button = (host, label) => host.find((e) => e.localName === 'button' && e.textContent.trim() === label)[0];
const svgOf = (host) => host.find((e) => e.localName === 'svg' && e.className.includes('dg-svg'))[0];
const click = (el) => {
    pointer(el, 'pointerdown', 10, 10);
    pointer(el, 'pointerup', 10, 10);
};

test('alle neun Diagramm-Widgets sind registriert', () => {
    for (const t of ['drawing', 'uml', 'structogram', 'flowchart', 'logic', 'truth_table', 'automaton', 'er', 'relational']) {
        assert.ok(answerWidget(t), t);
    }
});

test('jedes Widget lässt sich leer einhängen, auf readOnly schalten und entfernen', async () => {
    for (const [type, config] of [
        ['drawing', { width: 400, height: 300 }], ['uml', {}], ['structogram', {}], ['flowchart', {}],
        ['logic', { inputs: ['A', 'B'], outputs: ['X'] }], ['truth_table', { inputs: ['A'], outputs: ['X'] }],
        ['automaton', { kind: 'dfa', alphabet: ['a', 'b'] }], ['er', {}], ['relational', {}],
    ]) {
        const { inst, changes } = mount(type, { config });
        assert.equal(typeof inst.getContent, 'function', type);
        assert.equal(typeof inst.getPreviewPng, 'function', type);
        assert.ok(inst.getContent() && typeof inst.getContent() === 'object', type);
        inst.setReadOnly(true);
        inst.setReadOnly(false);
        inst.setContent(inst.getContent());
        const png = await inst.getPreviewPng();
        assert.ok(png === null || png.startsWith('data:image/png'), type);
        inst.destroy();
        assert.equal(changes.length, 0, `${type}: keine Änderung ohne Eingabe`);
    }
});

test('kaputte Inhalte stürzen kein Widget ab', () => {
    for (const type of ['drawing', 'uml', 'structogram', 'flowchart', 'logic', 'truth_table', 'automaton', 'er', 'relational']) {
        for (const junk of [{}, [], 'x', 5, { classes: 'x', nodes: 7, strokes: [null], rows: 'a', tables: [1, 2], root: [], states: {}, entities: [null] }]) {
            const { inst } = mount(type, { config: { inputs: ['A'], outputs: ['X'], alphabet: ['a'] }, content: junk });
            assert.ok(inst.getContent(), type);
            inst.destroy();
        }
    }
});

test('UML: Klassen anlegen, verbinden, löschen, rückgängig', async () => {
    const { host, inst, changes } = mount('uml');
    const toolbar = host.find((e) => e.getAttribute('role') === 'toolbar')[0];
    assert.equal(toolbar.hidden, false);
    button(host, '＋ Klasse').click();
    button(host, '＋ Interface').click();
    let c = inst.getContent();
    assert.equal(c.classes.length, 2);
    assert.equal(c.classes[1].kind, 'interface');
    assert.equal(c.classes[0].x % 10, 0, 'am Raster ausgerichtet');

    // Realisierung: Klasse -> Interface (zwei Klicks)
    button(host, 'Realisierung').click();
    click(byId(host, 'c1'));
    click(byId(host, 'c2'));
    c = inst.getContent();
    assert.equal(c.relations.length, 1);
    assert.deepEqual([c.relations[0].from, c.relations[0].to, c.relations[0].kind], ['c1', 'c2', 'realization']);
    // Duplikate werden abgelehnt
    click(byId(host, 'c1'));
    click(byId(host, 'c2'));
    assert.equal(inst.getContent().relations.length, 1);

    await sleep(350);
    assert.ok(changes.length >= 1, 'entprellte Meldung');
    const last = changes[changes.length - 1];
    assert.equal(last.c.classes.length, 2);
    assert.equal(last.meta.kind, 'value');
    assert.equal(typeof last.meta.getPreviewPng, 'function');

    // Klasse c2 löschen: Beziehung verschwindet mit
    const svg = svgOf(host);
    key(svg, 'Escape');
    click(byId(host, 'c2'));
    key(svg, 'Delete');
    c = inst.getContent();
    assert.deepEqual(c.classes.map((x) => x.id), ['c1']);
    assert.equal(c.relations.length, 0);
    key(svg, 'z', { ctrlKey: true });
    c = inst.getContent();
    assert.equal(c.classes.length, 2);
    assert.equal(c.relations.length, 1);
    key(svg, 'y', { ctrlKey: true });
    assert.equal(inst.getContent().classes.length, 1);
    inst.destroy();
});

test('UML: Verschieben per Zeiger und Pfeiltasten, Raster', () => {
    const { host, inst } = mount('uml', { content: { classes: [{ id: 'c1', name: 'A', kind: 'class', x: 100, y: 100, attributes: [], methods: [] }], relations: [] } });
    const svg = svgOf(host);
    const node = byId(host, 'c1');
    pointer(node, 'pointerdown', 100, 100);
    pointer(svg, 'pointermove', 153, 128);
    pointer(svg, 'pointerup', 153, 128);
    const moved = inst.getContent().classes[0];
    // Ansicht ist um (24,24) verschoben, Zoom 1: Differenz 53/28 -> Raster 10
    assert.deepEqual([moved.x, moved.y], [150, 130]);
    key(svg, 'ArrowRight');
    key(svg, 'ArrowDown', { shiftKey: true });
    const moved2 = inst.getContent().classes[0];
    assert.deepEqual([moved2.x, moved2.y], [160, 180]);
    inst.destroy();
});

test('UML: Formular im Eigenschaften-Bereich ändert die Klasse (Tippen ergibt einen Undo-Schritt)', () => {
    const { host, inst } = mount('uml');
    button(host, '＋ Klasse').click();
    const inputs = host.find((e) => e.localName === 'input' && e.getAttribute('maxlength') === '80');
    const name = inputs[0];
    for (const v of ['P', 'Pe', 'Per', 'Pers', 'Person']) {
        name.value = v;
        name.dispatch('input', {});
    }
    assert.equal(inst.getContent().classes[0].name, 'Person');
    button(host, '+ Attribut').click();
    assert.equal(inst.getContent().classes[0].attributes.length, 1);
    button(host, '+ Methode').click();
    assert.equal(inst.getContent().classes[0].methods.length, 1);
    const svg = svgOf(host);
    key(svg, 'z', { ctrlKey: true });
    key(svg, 'z', { ctrlKey: true });
    assert.equal(inst.getContent().classes[0].name, 'Person');
    assert.equal(inst.getContent().classes[0].attributes.length, 0);
    key(svg, 'z', { ctrlKey: true });
    assert.equal(inst.getContent().classes[0].name, 'Klasse1', 'das gesamte Tippen war ein Schritt');
    inst.destroy();
});

test('readOnly: keine Werkzeugleiste, keine Änderung, Undo gesperrt', () => {
    const content = { classes: [{ id: 'c1', name: 'A', kind: 'class', x: 0, y: 0, attributes: [], methods: [] }], relations: [] };
    const { host, inst, changes } = mount('uml', { content, readOnly: true, mode: 'grading' });
    const toolbar = host.find((e) => e.getAttribute('role') === 'toolbar')[0];
    assert.equal(toolbar.hidden, true);
    const svg = svgOf(host);
    key(svg, 'Delete');
    key(svg, 'z', { ctrlKey: true });
    pointer(byId(host, 'c1'), 'pointerdown', 10, 10);
    pointer(svg, 'pointermove', 90, 90);
    pointer(svg, 'pointerup', 90, 90);
    assert.deepEqual(inst.getContent(), JSON.parse(JSON.stringify(inst.getContent())));
    assert.equal(inst.getContent().classes[0].x, 0);
    assert.equal(byId(host, 'c1').getAttribute('tabindex'), null, 'nicht tabbar ohne Bearbeitung');
    inst.setContent({ classes: [{ id: 'c1', name: 'Neu', kind: 'class', x: 5, y: 5, attributes: [], methods: [] }], relations: [] });
    assert.equal(inst.getContent().classes[0].name, 'Neu');
    inst.destroy();
    assert.equal(changes.length, 0);
});

test('Flussdiagramm: Symbole einfügen und mit Pfeilen verbinden (Verzweigung beschriftet Ja/Nein)', () => {
    const { host, inst } = mount('flowchart');
    button(host, 'Start').click();
    button(host, 'Verzweigung').click();
    button(host, 'Ende').click();
    button(host, 'Operation').click();
    let c = inst.getContent();
    assert.deepEqual(c.nodes.map((n) => n.type), ['start', 'decision', 'end', 'process']);
    assert.equal(c.nodes[0].text, 'Start');
    assert.ok(new Set(c.nodes.map((n) => `${n.x},${n.y}`)).size === 4, 'keine übereinanderliegenden Symbole');
    button(host, '→ Pfeil').click();
    click(byId(host, 'n1'));
    click(byId(host, 'n2'));
    click(byId(host, 'n2'));
    click(byId(host, 'n3'));
    click(byId(host, 'n2'));
    click(byId(host, 'n4'));
    c = inst.getContent();
    assert.equal(c.edges.length, 3);
    assert.deepEqual(c.edges.map((e) => e.label), ['', 'Ja', 'Nein']);
    // Selbstverbindung abgelehnt
    click(byId(host, 'n1'));
    click(byId(host, 'n1'));
    assert.equal(inst.getContent().edges.length, 3);
    inst.destroy();
});

test('Logik: feste Ein- und Ausgänge, Gatter verdrahten, Live-Simulation', () => {
    const config = { inputs: ['A', 'B'], outputs: ['X'], allowed_gates: ['AND', 'NOT'], show_table: true };
    const { host, inst } = mount('logic', { config });
    let c = inst.getContent();
    assert.deepEqual(c.components.map((x) => x.id).sort(), ['in_A', 'in_B', 'out_X']);
    assert.equal(button(host, 'OR'), undefined, 'nicht erlaubte Gatter fehlen');
    button(host, 'AND').click();
    c = inst.getContent();
    const gate = c.components.find((x) => x.type === 'AND');
    assert.ok(gate);

    const port = (p) => host.find((e) => e.getAttribute('data-port') === p)[0];
    const svg = svgOf(host);
    const drag = (from, to) => {
        pointer(port(from), 'pointerdown', 10, 10);
        pointer(svg, 'pointermove', 80, 80);
        pointer(port(to), 'pointerup', 80, 80);
    };
    drag('in_A.out', `${gate.id}.in0`);
    drag('in_B.out', `${gate.id}.in1`);
    drag(`${gate.id}.out`, 'out_X.in0');
    c = inst.getContent();
    assert.equal(c.wires.length, 3);
    assert.deepEqual(c.wires[2].to, { component: 'out_X', pin: 'in0' });

    // Rückwärts ziehen (vom Eingang zum Ausgang) wird gedreht, belegter Pin wird ersetzt
    drag(`${gate.id}.in0`, 'in_B.out');
    c = inst.getContent();
    assert.equal(c.wires.length, 3);
    assert.equal(c.wires.find((w) => w.to.pin === 'in0' && w.to.component === gate.id).from.component, 'in_B');

    // Eingang schalten: Tabelle zeigt die Zeile als aktuell
    const toggle = host.find((e) => e.getAttribute('data-action') === 'toggle')[0];
    click(toggle);
    const rows = host.find((e) => e.localName === 'tr' && e.className.includes('is-current'));
    assert.equal(rows.length, 1);

    // feste Komponenten lassen sich nicht löschen
    click(byId(host, 'in_A'));
    key(svg, 'Delete');
    assert.ok(inst.getContent().components.some((x) => x.id === 'in_A'));
    click(byId(host, gate.id));
    key(svg, 'Delete');
    c = inst.getContent();
    assert.equal(c.components.length, 3);
    assert.equal(c.wires.length, 0, 'Leitungen am gelöschten Gatter verschwinden');
    inst.destroy();
});

test('Logik: Zyklus wird gemeldet', () => {
    const config = { inputs: ['A'], outputs: ['X'] };
    const content = {
        components: [
            { id: 'g1', type: 'NOT', label: '', x: 100, y: 0 }, { id: 'g2', type: 'NOT', label: '', x: 200, y: 0 },
        ],
        wires: [
            { id: 'w1', from: { component: 'g1', pin: 'out' }, to: { component: 'g2', pin: 'in0' } },
            { id: 'w2', from: { component: 'g2', pin: 'out' }, to: { component: 'g1', pin: 'in0' } },
        ],
    };
    const { host, inst } = mount('logic', { config, content });
    const lines = host.find((e) => e.className.includes('dg-hint-line'));
    assert.ok(lines.some((l) => l.textContent.includes('Rückkopplung')));
    inst.destroy();
});

test('Automat: Zustände, Übergänge, Schleife, Simulation Schritt für Schritt', () => {
    const config = { kind: 'dfa', alphabet: ['a', 'b'], allow_simulation: true };
    const { host, inst } = mount('automaton', { config });
    button(host, '＋ Zustand').click();
    button(host, '＋ Zustand').click();
    let c = inst.getContent();
    assert.equal(c.states.length, 2);
    assert.equal(c.states[0].initial, true);
    assert.equal(c.states[1].initial, false);
    assert.deepEqual(c.states.map((x) => x.name), ['q0', 'q1']);

    button(host, '→ Übergang').click();
    click(byId(host, 'q1'));
    click(byId(host, 'q2'));
    click(byId(host, 'q2'));
    click(byId(host, 'q1'));
    click(byId(host, 'q1'));
    click(byId(host, 'q1'));
    c = inst.getContent();
    assert.equal(c.transitions.length, 3);
    assert.deepEqual(c.transitions.map((t) => t.symbols), [['a'], ['a'], ['b']], 'die Schleife nimmt das nächste freie Symbol');

    // q2 als Endzustand: Inspektor-Kontrollkästchen
    button(host, 'Auswahl')?.click?.();
    inst.board.setTool(null);
    click(byId(host, 'q2'));
    const accepting = host.find((e) => e.localName === 'input' && e.getAttribute('type') === 'checkbox' && e.parentNode?.textContent.includes('Endzustand'))[0];
    accepting.checked = true;
    accepting.dispatch('change', {});
    assert.equal(inst.getContent().states[1].accepting, true);

    // Simulation: Wort "a" führt vom Startzustand q0 (id q1) in den Endzustand q1 (id q2)
    const word = host.find((e) => e.className.includes('dg-sim-word'))[0];
    word.value = 'a';
    word.dispatch('input', {});
    const simOut = () => host.find((e) => e.className.includes('dg-sim-out'))[0].textContent;
    button(host, 'Schritt ▶').click();
    assert.ok(simOut().includes('Aktuell in: q0'), simOut());
    button(host, 'Schritt ▶').click();
    assert.ok(simOut().includes('Aktuell in: q1') && simOut().includes('akzeptiert'), simOut());
    button(host, '◀ Zurück').click();
    assert.ok(simOut().includes('Aktuell in: q0'));
    word.value = 'x';
    word.dispatch('input', {});
    button(host, 'Start').click();
    assert.ok(host.find((e) => e.className.includes('dg-sim-out'))[0].textContent.includes('nicht zum Alphabet'));

    // DEA-Hinweis (unvollständig)
    assert.ok(host.find((e) => e.className.includes('dg-hint-line')).some((l) => l.textContent.includes('nicht vollständig')));
    inst.destroy();
});

test('Automat ohne Simulation zeigt kein Eingabefeld', () => {
    const { host, inst } = mount('automaton', { config: { kind: 'nfa', alphabet: ['a'], allow_simulation: false } });
    assert.equal(host.find((e) => e.className.includes('dg-sim-word')).length, 0);
    inst.destroy();
});

test('ER: Entität, Beziehung, Attribut, Verbindung mit Kardinalität', () => {
    const { host, inst } = mount('er');
    button(host, '＋ Entität').click();
    button(host, '＋ Entität').click();
    button(host, '＋ Beziehung').click();
    let c = inst.getContent();
    assert.equal(c.entities.length, 2);
    assert.equal(c.relationships.length, 1);
    button(host, '— Verbinden').click();
    click(byId(host, 'r1'));
    click(byId(host, 'e1'));
    click(byId(host, 'e2'));
    click(byId(host, 'r1'));
    c = inst.getContent();
    assert.deepEqual(c.relationships[0].ends.map((e) => e.entity), ['e1', 'e2']);
    inst.board.setTool(null);
    click(byId(host, 'e1'));
    button(host, '＋ Attribut').click();
    c = inst.getContent();
    assert.equal(c.attributes.length, 1);
    assert.equal(c.attributes[0].owner, 'e1');
    // Attribut ohne Auswahl wird abgelehnt
    inst.board.select([]);
    button(host, '＋ Attribut').click();
    assert.equal(inst.getContent().attributes.length, 1);
    // Entität löschen entfernt Attribute und Verbindungen
    click(byId(host, 'e1'));
    key(svgOf(host), 'Delete');
    c = inst.getContent();
    assert.equal(c.attributes.length, 0);
    assert.equal(c.relationships[0].ends.length, 1);
    inst.destroy();
});

test('Struktogramm: Baustein wählen, Einfügepunkt anklicken, verschachteln, anordnen', () => {
    const { host, inst } = mount('structogram');
    button(host, 'Anweisung').click();
    const slot = () => host.find((e) => e.getAttribute('data-action') === 'slot');
    assert.ok(slot().length >= 1);
    click(slot()[0]);
    let c = inst.getContent();
    assert.equal(c.root.children.length, 1);
    assert.equal(c.root.children[0].type, 'statement');
    // zweiter Baustein: Verzweigung hinter dem ersten
    button(host, 'Verzweigung').click();
    const afterFirst = slot().find((e) => e.getAttribute('data-index') === '1' && e.getAttribute('data-key') === 'root');
    click(afterFirst);
    c = inst.getContent();
    assert.deepEqual(c.root.children.map((b) => b.type), ['statement', 'if']);
    // Anweisung in den Ja-Zweig der Verzweigung
    button(host, 'Anweisung').click();
    const thenSlot = slot().find((e) => e.getAttribute('data-key') === `${c.root.children[1].id}/then`);
    assert.ok(thenSlot);
    click(thenSlot);
    c = inst.getContent();
    assert.equal(c.root.children[1].then.children.length, 1);
    // Text ändern über den Eigenschaften-Bereich
    const area = host.find((e) => e.localName === 'textarea')[0];
    area.value = 'x := 1';
    area.dispatch('input', {});
    assert.equal(inst.getContent().root.children[1].then.children[0].text, 'x := 1');
    // Alt+Pfeil verschiebt, Entf löscht
    const svg = svgOf(host);
    inst.board.select([c.root.children[1].id]);
    key(svg, 'ArrowUp', { altKey: true });
    assert.deepEqual(inst.getContent().root.children.map((b) => b.type), ['if', 'statement']);
    key(svg, 'Delete');
    assert.deepEqual(inst.getContent().root.children.map((b) => b.type), ['statement']);
    inst.destroy();
});

test('Wahrheitstabelle: Zellen per Klick und Tastatur, vorgegebene Zellen fest, Rückgängig', async () => {
    const config = { inputs: ['A', 'B'], outputs: ['X', 'Y'], prefilled: { 11: { X: '1' } } };
    const { host, inst, changes } = mount('truth_table', { config });
    const cells = host.find((e) => e.className.includes('w-truth-cell'));
    assert.equal(cells.length, 8);
    assert.equal(cells[6].textContent, '1', 'vorgegeben');
    cells[0].click();
    cells[0].click();
    assert.equal(inst.getContent().rows['00'].X, '1');
    key(cells[1], '0');
    key(cells[2], '1');
    key(cells[6], '0'); // vorgegeben: keine Änderung
    assert.deepEqual(inst.getContent().rows, { '00': { X: '1', Y: '0' }, '01': { X: '1' }, 11: { X: '1' } });
    key(cells[0], 'Delete');
    assert.equal(inst.getContent().rows['00'].X, undefined);
    host.find((e) => e.localName === 'button' && e.textContent.includes('Rückgängig'))[0].click();
    assert.equal(inst.getContent().rows['00'].X, '1');
    await sleep(350);
    assert.ok(changes.length >= 1);
    inst.setReadOnly(true);
    cells[3].click();
    assert.equal(inst.getContent().rows['01']?.Y, undefined);
    inst.destroy();
});

test('Relationenschema: Tabellen, Spalten, PK, FK-Auswahl, Umbenennen zieht Verweise nach', () => {
    const { host, inst } = mount('relational');
    const addTable = () => button(host, '+ Tabelle').click();
    addTable();
    addTable();
    let c = inst.getContent();
    assert.equal(c.tables.length, 2);
    const names = host.find((e) => e.className.includes('rel-name'));
    names[0].value = 'Kurs';
    names[0].dispatch('input', {});
    names[1].value = 'Schueler';
    names[1].dispatch('input', {});
    const colInputs = () => host.find((e) => e.localName === 'input' && e.getAttribute('placeholder') === 'Spaltenname');
    colInputs()[0].value = 'id';
    colInputs()[0].dispatch('input', {});
    colInputs()[1].value = 'id';
    colInputs()[1].dispatch('input', {});
    host.find((e) => e.localName === 'button' && e.textContent.trim() === '+ Spalte')[1].click();
    const cols = colInputs();
    assert.equal(cols.length, 3);
    cols[2].value = 'kurs_id';
    cols[2].dispatch('input', {});
    const selects = host.find((e) => e.localName === 'select');
    const fk = selects[selects.length - 1];
    assert.ok(fk.children.some((o) => o.getAttribute('value') === 'Kurs.id'));
    fk.value = 'Kurs.id';
    fk.dispatch('change', {});
    c = inst.getContent();
    assert.equal(c.tables[1].columns[1].fk, 'Kurs.id');
    assert.equal(c.tables[0].columns[0].pk, true);
    // Tabelle umbenennen
    const names2 = host.find((e) => e.className.includes('rel-name'));
    names2[0].value = 'Lehrgang';
    names2[0].dispatch('input', {});
    assert.equal(inst.getContent().tables[1].columns[1].fk, 'Lehrgang.id');
    const preview = host.find((e) => e.className.includes('rel-preview'))[0];
    assert.ok(preview.textContent.includes('↑kurs_id'));
    assert.ok(host.find((e) => e.localName === 'u').length >= 2, 'Primärschlüssel unterstrichen');
    inst.destroy();
});

test('Zeichnung: Strich mit Stift, Vereinfachung, Radierer, Rückgängig, readOnly', async () => {
    const { host, inst, changes } = mount('drawing', { config: { width: 800, height: 500 } });
    const svg = host.find((e) => e.localName === 'svg')[0];
    pointer(svg, 'pointerdown', 100, 100);
    for (let x = 101; x <= 300; x += 2) {
        pointer(svg, 'pointermove', x, 100 + (x - 100) / 2);
    }
    pointer(svg, 'pointerup', 300, 200);
    let c = inst.getContent();
    assert.equal(c.strokes.length, 1);
    assert.ok(c.strokes[0].points.length <= 3, 'gerade Linie wird stark vereinfacht');
    assert.equal(c.strokes[0].color, '#000000');
    assert.equal(c.strokes[0].width, 2);

    // Farbe wechseln und zweiten Strich zeichnen
    host.find((e) => e.getAttribute('aria-label') === 'Farbe Rot')[0].click();
    pointer(svg, 'pointerdown', 400, 400);
    pointer(svg, 'pointermove', 420, 420);
    pointer(svg, 'pointerup', 420, 420);
    c = inst.getContent();
    assert.equal(c.strokes.length, 2);
    assert.equal(c.strokes[1].color, '#dc2626');

    // Radierer löscht den getroffenen Strich
    button(host, '⌫ Radierer').click();
    pointer(svg, 'pointerdown', 200, 150);
    pointer(svg, 'pointerup', 200, 150);
    c = inst.getContent();
    assert.equal(c.strokes.length, 1);
    assert.equal(c.strokes[0].color, '#dc2626');
    host.find((e) => e.localName === 'button' && e.textContent.includes('Rückgängig'))[0].click();
    assert.equal(inst.getContent().strokes.length, 2);

    await sleep(350);
    assert.ok(changes.length >= 1);
    inst.setReadOnly(true);
    pointer(svg, 'pointerdown', 10, 10);
    pointer(svg, 'pointermove', 50, 50);
    pointer(svg, 'pointerup', 50, 50);
    assert.equal(inst.getContent().strokes.length, 2);
    inst.destroy();
});

test('Zeichnung: Text setzen, per Tastatur verschieben und löschen; Upload nur mit Berechtigung sichtbar', () => {
    const { host, inst } = mount('drawing', { config: { width: 800, height: 500, allow_upload: true } });
    const svg = host.find((e) => e.localName === 'svg')[0];
    button(host, 'T Text').click();
    pointer(svg, 'pointerdown', 120, 80);
    const area = host.find((e) => e.localName === 'textarea')[0];
    area.value = 'Wurzel';
    key(area, 'Enter');
    let c = inst.getContent();
    assert.equal(c.texts.length, 1);
    assert.deepEqual([c.texts[0].x, c.texts[0].y, c.texts[0].text], [120, 80, 'Wurzel']);
    const item = host.find((e) => e.className.includes('dg-text-item'))[0];
    key(item, 'ArrowRight');
    key(item, 'ArrowDown', { shiftKey: true });
    c = inst.getContent();
    assert.deepEqual([c.texts[0].x, c.texts[0].y], [125, 100]);
    key(host.find((e) => e.className.includes('dg-text-item'))[0], 'Delete');
    assert.equal(inst.getContent().texts.length, 0);
    assert.equal(host.find((e) => e.getAttribute('type') === 'file').length, 1);
    inst.destroy();

    const noUpload = mount('drawing', { config: { width: 800, height: 500 } });
    const box = noUpload.host.find((e) => e.className.includes('dg-upload'))[0];
    assert.equal(box.hidden, true);
    noUpload.inst.destroy();
});

test('onChange kommt entprellt und enthält den vollständigen Inhalt', async () => {
    const { host, inst, changes } = mount('flowchart');
    for (let i = 0; i < 5; i++) {
        button(host, 'Operation').click();
    }
    assert.equal(changes.length, 0, 'noch nichts gemeldet');
    await sleep(350);
    assert.equal(changes.length, 1);
    assert.equal(changes[0].c.nodes.length, 5);
    inst.destroy();
});

test('destroy meldet noch ausstehende Änderungen', () => {
    const { host, inst, changes } = mount('er');
    button(host, '＋ Entität').click();
    assert.equal(changes.length, 0);
    inst.destroy();
    assert.equal(changes.length, 1);
    assert.equal(changes[0].c.entities.length, 1);
});
