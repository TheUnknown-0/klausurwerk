import test from 'node:test';
import assert from 'node:assert/strict';

import { installDom, key, pointer } from './support/dom-stub.js';

const body = installDom();
await import('../src/widgets/diagrams/index.js');
const { answerWidget } = await import('../src/lib/widgets.js');
const { isDeleteKey, isTypingTarget, itemsEditor } = await import('../src/task-editor/ui.js');

const find = (host, pred) => host.find(pred);
const btn = (host, label) => find(host, (e) => e.localName === 'button' && (e.textContent.trim() === label || e.getAttribute('aria-label') === label))[0];
const mountWidget = (type, { config = {}, content = null } = {}) => {
    const host = document.createElement('div');
    body.append(host);
    const inst = answerWidget(type).mount(host, { task: { id: 1, config }, content, readOnly: false, mode: 'exam', env: { examId: 1 }, onChange: () => {} });

    return { host, inst };
};

test('isDeleteKey: Entf und Rücktaste, aber nicht mit Strg, Alt oder Meta', () => {
    assert.equal(isDeleteKey({ key: 'Delete' }), true);
    assert.equal(isDeleteKey({ key: 'Backspace' }), true);
    assert.equal(isDeleteKey({ key: 'Backspace', ctrlKey: true }), false);
    assert.equal(isDeleteKey({ key: 'Delete', altKey: true }), false);
    assert.equal(isDeleteKey({ key: 'a' }), false);
});

test('isTypingTarget: Felder, contenteditable und Code-Editor zählen als Texteingabe', () => {
    const div = document.createElement('div');
    assert.equal(isTypingTarget(div), false);
    assert.equal(isTypingTarget(document.createElement('textarea')), true);
    assert.equal(isTypingTarget(document.createElement('input')), true);
    assert.equal(isTypingTarget(document.createElement('select')), true);
    const editable = document.createElement('div');
    editable.setAttribute('contenteditable', 'true');
    const inner = document.createElement('span');
    editable.append(inner);
    assert.equal(isTypingTarget(inner), true);
    const cm = document.createElement('div');
    cm.className = 'cm-editor';
    const line = document.createElement('div');
    cm.append(line);
    assert.equal(isTypingTarget(line), true);
});

function listEditor({ min = 1, locked = false } = {}) {
    const items = [{ id: 'a', text_md: 'A' }, { id: 'b', text_md: 'B' }, { id: 'c', text_md: 'C' }];
    let changes = 0;
    const ed = itemsEditor({ items, prefix: 'o', label: 'Antwortoption', min, locked, onChange: () => { changes += 1; } });
    const host = document.createElement('div');
    body.append(host);
    host.append(ed.el);
    const rows = () => find(host, (e) => e.classList.contains('te-item'));

    return { items, ed, host, rows, changes: () => changes };
}

test('Listen im Aufgabenformular: Zeile anklicken, Entf löscht, Rücktaste im Textfeld nicht', () => {
    const { items, ed, host, rows, changes } = listEditor();
    const deleteBtn = ed.selection.button;
    assert.equal(deleteBtn.disabled, true, 'ohne Auswahl deaktiviert');
    assert.equal(rows().length, 3);

    // Klick auf die Zeile (nicht auf ein Feld) wählt sie aus und markiert sie
    rows()[1].click();
    assert.ok(rows()[1].classList.contains('is-selected'));
    assert.equal(rows()[1].getAttribute('aria-current'), 'true');
    assert.equal(deleteBtn.disabled, false);

    // Rücktaste in einem Textfeld löscht nie die Zeile
    const area = find(host, (e) => e.localName === 'textarea')[1];
    key(area, 'Backspace');
    assert.equal(items.length, 3);

    // Entf auf der Zeile selbst löscht sie, die Nachbarzeile wird gewählt und fokussiert
    const ev = key(rows()[1], 'Delete');
    assert.equal(ev.defaultPrevented, true);
    assert.deepEqual(items.map((i) => i.id), ['a', 'c']);
    assert.equal(rows().length, 2);
    assert.ok(rows()[1].classList.contains('is-selected'));
    assert.equal(document.activeElement, rows()[1]);
    assert.equal(changes(), 1);

    // Rücktaste auf der Zeile geht ebenfalls
    key(rows()[1], 'Backspace');
    assert.deepEqual(items.map((i) => i.id), ['a']);
    // Mindestanzahl (min 1): die letzte Zeile bleibt, der Knopf ist deaktiviert
    key(rows()[0], 'Delete');
    assert.equal(items.length, 1);
    assert.equal(ed.selection.button.disabled, true);
});

test('Listen im Aufgabenformular: Knopf "Löschen" wirkt auf die Auswahl, Fokus in der Zeile wählt sie', () => {
    const { items, ed, host, rows } = listEditor({ min: 2 });
    const area = find(host, (e) => e.localName === 'textarea')[2];
    area.focus(); // Fokus im Textfeld wählt die Zeile aus
    assert.ok(rows()[2].classList.contains('is-selected'));
    assert.equal(ed.selection.button.disabled, false);
    ed.selection.button.click();
    assert.deepEqual(items.map((i) => i.id), ['a', 'b']);
    // min = 2: nichts mehr löschbar
    assert.equal(ed.selection.button.disabled, true);
    key(rows()[0], 'Delete');
    assert.equal(items.length, 2);
});

test('Listen im Aufgabenformular: gesperrt löscht nichts', () => {
    const { items, ed, rows } = listEditor({ locked: true });
    rows()[0].click();
    key(rows()[0], 'Delete');
    assert.equal(items.length, 3);
    assert.equal(ed.selection.button.disabled, true);
});

test('Relationenschema: Tabelle und Spalte wählen, Entf löscht, Rückgängig holt zurück', () => {
    const { host, inst } = mountWidget('relational', {
        content: { tables: [{ id: 't1', name: 'Kurs', columns: [{ name: 'id', pk: true, fk: null }, { name: 'name', pk: false, fk: null }] }, { id: 't2', name: 'Schueler', columns: [{ name: 'id', pk: true, fk: null }] }] },
    });
    const del = btn(host, 'Gewählte Tabelle oder Spalte löschen');
    assert.equal(del.disabled, true);
    const colRows = () => find(host, (e) => e.classList.contains('rel-col'));
    colRows()[1].click();
    assert.ok(colRows()[1].classList.contains('is-selected'));
    assert.equal(del.disabled, false);

    // Rücktaste im Spaltenfeld ändert nur Text
    const input = find(host, (e) => e.localName === 'input' && e.getAttribute('placeholder') === 'Spaltenname')[1];
    key(input, 'Backspace');
    assert.equal(inst.getContent().tables[0].columns.length, 2);

    // Entf auf der gewählten Zeile
    key(colRows()[1], 'Delete');
    assert.deepEqual(inst.getContent().tables[0].columns.map((c) => c.name), ['id']);
    // Nachbar (die erste Spalte) ist nun gewählt; Knopf löscht sie
    assert.ok(colRows()[0].classList.contains('is-selected'));
    del.click();
    assert.equal(inst.getContent().tables[0].columns.length, 0);

    // Tabellenkopf wählen und per Knopf löschen
    const heads = () => find(host, (e) => e.classList.contains('rel-head'));
    heads()[1].click();
    assert.ok(heads()[1].classList.contains('is-selected'));
    key(heads()[1], 'Delete');
    assert.deepEqual(inst.getContent().tables.map((t) => t.name), ['Kurs']);

    // Strg+Z macht das Löschen schrittweise rückgängig
    const relRoot = find(host, (e) => e.classList.contains('w-relational'))[0];
    key(relRoot, 'z', { ctrlKey: true });
    assert.equal(inst.getContent().tables.length, 2);
    key(relRoot, 'z', { ctrlKey: true });
    assert.equal(inst.getContent().tables[0].columns.length, 1);
    inst.destroy();
});

test('Wahrheitstabelle: gewählte Zelle per Knopf leeren, Rücktaste leert ebenfalls', () => {
    const { host, inst } = mountWidget('truth_table', { config: { inputs: ['A'], outputs: ['X'] } });
    const cells = find(host, (e) => e.className.includes('w-truth-cell'));
    const del = btn(host, 'Gewählte Zelle leeren');
    assert.equal(del.disabled, true);
    cells[0].click(); // leer -> 0, Zelle ist gewählt
    assert.ok(cells[0].classList.contains('is-selected'));
    assert.equal(inst.getContent().rows['0'].X, '0');
    assert.equal(del.disabled, false);
    del.click();
    assert.equal(inst.getContent().rows['0']?.X, undefined);
    assert.equal(del.disabled, true);
    cells[1].click();
    assert.equal(inst.getContent().rows['1'].X, '0');
    key(cells[1], 'Backspace');
    assert.equal(inst.getContent().rows['1']?.X, undefined);
    inst.destroy();
});

function drawStroke(svg, x1, y1, x2, y2) {
    pointer(svg, 'pointerdown', x1, y1);
    pointer(svg, 'pointermove', (x1 + x2) / 2, (y1 + y2) / 2);
    pointer(svg, 'pointermove', x2, y2);
    pointer(svg, 'pointerup', x2, y2);
}

test('Zeichnung: Auswahlwerkzeug wählt Striche, Entf und Knopf löschen, Strg+Z holt zurück', () => {
    const { host, inst } = mountWidget('drawing', { config: { width: 800, height: 500 } });
    const svg = find(host, (e) => e.localName === 'svg' && String(e.className).includes('dg-draw-svg'))[0];
    drawStroke(svg, 100, 100, 300, 100);
    drawStroke(svg, 100, 300, 300, 300);
    assert.equal(inst.getContent().strokes.length, 2);
    const del = btn(host, 'Ausgewählten Strich oder Text löschen');
    assert.equal(del.disabled, true, 'ohne Auswahl deaktiviert');

    btn(host, 'Auswählen').click();
    assert.equal(find(host, (e) => e.getAttribute('data-stroke') !== null).length, 2, 'Striche sind für die Tastatur erreichbar');
    pointer(svg, 'pointerdown', 200, 101);
    pointer(svg, 'pointerup', 200, 101);
    assert.equal(find(host, (e) => String(e.className).includes('dg-sel-stroke')).length, 1, 'Auswahl sichtbar markiert');
    assert.equal(del.disabled, false);
    assert.equal(document.activeElement, svg, 'Fokus liegt auf der Fläche, damit die Taste wirkt');

    const ev = key(svg, 'Delete');
    assert.equal(ev.defaultPrevented, true);
    assert.equal(inst.getContent().strokes.length, 1);
    assert.equal(inst.getContent().strokes[0].points[0][1], 300);
    assert.equal(del.disabled, true);

    key(svg, 'z', { ctrlKey: true });
    assert.equal(inst.getContent().strokes.length, 2);

    // zweiter Strich über den Knopf
    pointer(svg, 'pointerdown', 200, 300);
    pointer(svg, 'pointerup', 200, 300);
    del.click();
    assert.equal(inst.getContent().strokes.length, 1);
    // Klick auf leere Fläche hebt die Auswahl auf, Entf tut dann nichts
    pointer(svg, 'pointerdown', 600, 450);
    pointer(svg, 'pointerup', 600, 450);
    key(svg, 'Backspace');
    assert.equal(inst.getContent().strokes.length, 1);
    inst.destroy();
});

test('Zeichnung: Rücktaste im Textfeld löscht keinen Strich, Texte lassen sich wählen und löschen', () => {
    const { host, inst } = mountWidget('drawing', { config: { width: 800, height: 500 } });
    const svg = find(host, (e) => e.localName === 'svg' && String(e.className).includes('dg-draw-svg'))[0];
    drawStroke(svg, 100, 100, 300, 100);
    btn(host, 'Auswählen').click();
    pointer(svg, 'pointerdown', 200, 100);
    pointer(svg, 'pointerup', 200, 100);
    // ein Eingabefeld im Widget (z. B. Datei- oder Textfeld) bekommt die Taste: nichts wird gelöscht
    const field = document.createElement('textarea');
    host.append(field);
    key(field, 'Backspace');
    assert.equal(inst.getContent().strokes.length, 1);

    // Text setzen, dann Auswählen und löschen
    btn(host, 'Text').click();
    pointer(svg, 'pointerdown', 400, 200);
    const editor = find(host, (e) => e.localName === 'textarea' && String(e.className).includes('dg-text-input'))[0];
    editor.value = 'Hallo';
    key(editor, 'Enter');
    assert.equal(inst.getContent().texts.length, 1);
    btn(host, 'Auswählen').click();
    const textItem = find(host, (e) => String(e.className).includes('dg-text-item'))[0];
    pointer(textItem, 'pointerdown', 405, 195);
    pointer(textItem, 'pointerup', 405, 195);
    key(svg, 'Delete');
    assert.equal(inst.getContent().texts.length, 0);
    assert.equal(inst.getContent().strokes.length, 1);
    key(svg, 'z', { ctrlKey: true });
    assert.equal(inst.getContent().texts.length, 1);
    inst.destroy();
});

test('Struktogramm: Block anklicken, Entf löscht ihn, Knopf "Löschen" wirkt auf die Auswahl, Strg+Z holt zurück', () => {
    const { host, inst } = mountWidget('structogram');
    const svg = find(host, (e) => e.localName === 'svg' && String(e.className).includes('dg-svg'))[0];
    const slot = () => find(host, (e) => e.getAttribute('data-action') === 'slot');
    const click = (el) => {
        pointer(el, 'pointerdown', 10, 10);
        pointer(el, 'pointerup', 10, 10);
    };
    for (let n = 0; n < 2; n += 1) {
        btn(host, 'Anweisung').click();
        click(slot()[0]);
    }
    assert.equal(inst.getContent().root.children.length, 2);
    const del = btn(host, 'Löschen');
    // Block anklicken = auswählen; Entf auf der Fläche löscht ihn
    const id = inst.getContent().root.children[0].id;
    click(find(host, (e) => e.getAttribute('data-id') === id)[0]);
    assert.equal(del.disabled, false);
    key(svg, 'Delete');
    assert.equal(inst.getContent().root.children.length, 1);
    assert.equal(del.disabled, true, 'ohne Auswahl deaktiviert');
    key(svg, 'z', { ctrlKey: true });
    assert.equal(inst.getContent().root.children.length, 2);
    // Knopf "Löschen" der Werkzeugleiste
    click(find(host, (e) => e.getAttribute('data-id') === id)[0]);
    del.click();
    assert.equal(inst.getContent().root.children.length, 1);
    inst.destroy();
});

test('Listen: Knopf "Löschen" sperrt sich, der Fokus geht auf die neu gewählte Zeile statt auf die Seite', () => {
    const { items, ed, rows } = listEditor({ min: 2 });
    rows()[2].click();
    ed.selection.button.focus();
    ed.selection.button.click();
    assert.deepEqual(items.map((i) => i.id), ['a', 'b']);
    assert.equal(ed.selection.button.disabled, true, 'min = 2 erreicht');
    assert.equal(document.activeElement, rows()[1], 'Fokus auf der Nachbarzeile');
});

test('rowSelection: Knopf in der gelöschten Zeile selbst, Fokus landet auf der Nachbarzeile', async () => {
    const { rowSelection } = await import('../src/widgets/delete-keys.js');
    const data = ['x', 'y', 'z'];
    const host = document.createElement('div');
    body.append(host);
    const sel = rowSelection({
        label: 'Eintrag',
        onDelete: (k) => {
            const i = data.indexOf(k);
            data.splice(i, 1);
            render();
            sel.select(data[Math.min(i, data.length - 1)] ?? null);
        },
    });
    function render() {
        host.replaceChildren();
        sel.begin();
        for (const k of data) {
            const removeBtn = document.createElement('button');
            removeBtn.addEventListener('click', () => { sel.select(k); sel.remove(false); });
            const row = document.createElement('div');
            row.append(removeBtn);
            sel.bind(row, k);
            host.append(row);
        }
        sel.end();
    }
    render();
    const removeBtn = host.children[1].children[0];
    removeBtn.focus();
    removeBtn.click();
    assert.deepEqual(data, ['x', 'z']);
    assert.equal(removeBtn.isConnected, false);
    assert.equal(document.activeElement, host.children[1], 'Fokus auf der Zeile, die nachgerückt ist');
});

test('rowSelection: Knopf bleibt bedienbar, dann bleibt der Fokus auf dem Knopf', () => {
    const { items, ed, rows } = listEditor({ min: 1 });
    rows()[0].click();
    ed.selection.button.focus();
    ed.selection.button.click();
    assert.equal(items.length, 2);
    assert.equal(ed.selection.button.disabled, false);
    assert.equal(document.activeElement, ed.selection.button);
});
