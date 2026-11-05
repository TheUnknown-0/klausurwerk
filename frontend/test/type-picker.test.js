import test from 'node:test';
import assert from 'node:assert/strict';

import { installDom } from './support/dom-stub.js';

installDom();
const { pickerGroups, typePicker } = await import('../src/task-editor/type-picker.js');

// Reihenfolge wie aus der Registry: Diagramme zuerst, Programmieren zuletzt.
const TYPES = [
    { name: 'neu', label: 'Neuer Typ', group: 'Diagramme' },
    { name: 'text', label: 'Freitext', group: 'Text & Auswahl' },
    { name: 'choice', label: 'Multiple Choice', group: 'Text & Auswahl' },
    { name: 'code', label: 'Programmcode', group: 'Programmieren' },
    { name: 'robo', label: 'Robo', group: 'Sonderbares' },
];

test('pickerGroups: Programmcode als drei Sprach-Kacheln, Gruppen in Reihenfolge, Gliederung am Ende', () => {
    const groups = pickerGroups(TYPES);
    assert.deepEqual(groups.map((g) => g.group), ['Programmieren', 'Text & Auswahl', 'Diagramme', 'Sonderbares', 'Gliederung']);
    const code = groups[0].tiles;
    assert.deepEqual(code.map((t) => t.label), ['Java', 'Python', 'SQL']);
    assert.ok(code.every((t) => t.type === 'code'));
    assert.deepEqual(code.map((t) => t.config.language), ['java', 'python', 'sql']);
    assert.equal(groups[1].tiles[0].desc !== '', true, 'bekannte Typen haben eine Beschreibung');
    assert.equal(groups[2].tiles[0].label, 'Neuer Typ', 'unbekannte Typen erscheinen trotzdem');
    assert.equal(groups[4].tiles[0].type, '', 'Container ohne Antworttyp');
});

test('typePicker: Klick auf eine Kachel meldet Typ und Voreinstellung, Escape bricht ab', () => {
    let picked = null;
    let cancelled = 0;
    const p = typePicker({ types: TYPES, heading: 'Neue Aufgabe', onPick: (t) => { picked = t; }, onCancel: () => { cancelled += 1; } });
    const tile = p.el.find((e) => e.dataset?.type === 'code-python')[0];
    tile.click();
    assert.equal(picked.type, 'code');
    assert.equal(picked.config.language, 'python');
    p.el.dispatch('keydown', { key: 'Escape' });
    assert.equal(cancelled, 1);
    p.focus();
    assert.equal(document.activeElement.dataset.type, 'code-java', 'Fokus auf der ersten Kachel');
});
