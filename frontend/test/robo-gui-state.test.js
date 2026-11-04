import { test } from 'node:test';
import assert from 'node:assert/strict';
import { applyFrame, cleanCommand, createGuiState, MAX_COMPONENTS, MAX_DRAW_COMMANDS } from '../src/gui/state.js';

const frame = (...ops) => ({ ops });

test('Fenster und Bausteine anlegen, danach nur Änderungen anwenden', () => {
    const s = createGuiState();
    const c1 = applyFrame(s, frame(
        { op: 'fenster', titel: 'Zähler', breite: 300, hoehe: 160, farbe: '#ffffff' },
        { op: 'set', id: 'b1', typ: 'beschriftung', x: 20, y: 20, b: 300, h: 24, text: '0' },
        { op: 'set', id: 'k2', typ: 'knopf', x: 20, y: 60, b: 100, h: 36, text: '+1' },
    ));
    assert.equal(c1.window, true);
    assert.deepEqual([...c1.set], ['b1', 'k2']);
    assert.equal(s.window.titel, 'Zähler');
    const c2 = applyFrame(s, frame({ op: 'set', id: 'b1', typ: 'beschriftung', text: '1' }));
    assert.deepEqual([...c2.set], ['b1']);
    assert.equal(s.comps.get('b1').text, '1');
    assert.equal(s.comps.get('b1').x, 20, 'nicht gesendete Felder bleiben');
    assert.equal(s.comps.get('k2').text, '+1');
});

test('Baustein entfernen und wieder anlegen', () => {
    const s = createGuiState();
    applyFrame(s, frame({ op: 'set', id: 'k1', typ: 'knopf', text: 'a' }));
    const c = applyFrame(s, frame({ op: 'del', id: 'k1' }, { op: 'del', id: 'gibtsnicht' }));
    assert.deepEqual([...c.del], ['k1']);
    assert.equal(s.comps.size, 0);
    const again = applyFrame(s, frame({ op: 'set', id: 'k1', typ: 'knopf', text: 'neu' }));
    assert.deepEqual([...again.set], ['k1']);
});

test('Zeichenbefehle sammeln, Löschen leert, Anzeige bekommt nur das Neue', () => {
    const s = createGuiState();
    applyFrame(s, frame({ op: 'set', id: 'z3', typ: 'flaeche', b: 100, h: 100 }));
    const c1 = applyFrame(s, frame({ op: 'zeichne', id: 'z3', befehle: [['l', 0, 0, 10, 10, '#000000', 2], ['k', 5, 5, 3, '#d62828', 2, 1]] }));
    assert.equal(c1.draw.get('z3').clear, false);
    assert.equal(c1.draw.get('z3').cmds.length, 2);
    assert.equal(s.draws.get('z3').length, 2);
    const c2 = applyFrame(s, frame({ op: 'zeichne', id: 'z3', befehle: [['c'], ['t', 1, 2, 'Hallo', '#000000', 16]] }));
    assert.equal(c2.draw.get('z3').clear, true);
    assert.deepEqual(c2.draw.get('z3').cmds, [['t', 1, 2, 'Hallo', '#000000', 16]]);
    assert.equal(s.draws.get('z3').length, 1);
});

test('Zeichnen auf Nicht-Flächen und unbekannte Befehle werden ignoriert', () => {
    const s = createGuiState();
    applyFrame(s, frame({ op: 'set', id: 'k1', typ: 'knopf' }, { op: 'set', id: 'z1', typ: 'flaeche' }));
    const c = applyFrame(s, frame(
        { op: 'zeichne', id: 'k1', befehle: [['l', 0, 0, 1, 1, '#000', 1]] },
        { op: 'zeichne', id: 'z1', befehle: [['x', 1], 'quatsch', null, ['l', 0, 0, 1, 1, 'javascript:alert(1)', 1]] },
    ));
    assert.equal(c.draw.has('k1'), false);
    assert.equal(c.draw.get('z1').cmds.length, 1);
    assert.equal(c.draw.get('z1').cmds[0][5], '#000000', 'ungültige Farbe fällt auf Schwarz zurück');
});

test('Eingaben werden bereinigt: Farben, Zahlen, Typen, Anzahl', () => {
    const s = createGuiState();
    applyFrame(s, frame(
        { op: 'set', id: 'a b', typ: 'knopf' },
        { op: 'set', id: 'x1', typ: 'script' },
        { op: 'set', id: 'k1', typ: 'knopf', x: 'abc', b: 99999, farbe: 'red; background:url(x)', hintergrund: '#abc', schrift: 5 },
    ));
    assert.equal(s.comps.size, 1);
    const k = s.comps.get('k1');
    assert.equal(k.x, 0);
    assert.equal(k.b, 3000);
    assert.equal(k.farbe, '');
    assert.equal(k.hintergrund, '#abc');
    assert.equal(k.schrift, 8);
    const many = createGuiState();
    for (let i = 0; i < MAX_COMPONENTS + 10; i++) {
        applyFrame(many, frame({ op: 'set', id: `k${i}`, typ: 'knopf' }));
    }
    assert.equal(many.comps.size, MAX_COMPONENTS);
});

test('Zeichenliste wird begrenzt', () => {
    const s = createGuiState();
    applyFrame(s, frame({ op: 'set', id: 'z1', typ: 'flaeche' }));
    const cmds = Array.from({ length: MAX_DRAW_COMMANDS + 50 }, (_, i) => ['r', i, 0, 1, 1, '#000000', 1, 1]);
    const c = applyFrame(s, frame({ op: 'zeichne', id: 'z1', befehle: cmds }));
    assert.equal(s.draws.get('z1').length, MAX_DRAW_COMMANDS);
    assert.equal(c.draw.get('z1').clear, true);
    assert.equal(c.draw.get('z1').cmds.length, MAX_DRAW_COMMANDS);
    assert.equal(s.draws.get('z1')[0][1], 50, 'älteste Befehle fallen weg');
});

test('Fenster schließen und kaputte Frames', () => {
    const s = createGuiState();
    assert.equal(applyFrame(s, frame({ op: 'schliessen' })).closed, true);
    assert.equal(s.closed, true);
    assert.doesNotThrow(() => applyFrame(s, null));
    assert.doesNotThrow(() => applyFrame(s, { ops: 'x' }));
    assert.doesNotThrow(() => applyFrame(s, frame(null, 5, { op: 'unbekannt' })));
});

test('Zeichenbefehle einzeln bereinigen', () => {
    assert.deepEqual(cleanCommand(['c']), ['c']);
    assert.equal(cleanCommand(['q']), null);
    assert.deepEqual(cleanCommand(['t', 1.6, 2, 'x'.repeat(5000), '#fff', 500]).slice(1, 3), [2, 2]);
    assert.equal(cleanCommand(['t', 1, 2, 'x'.repeat(5000), '#fff', 500])[3].length, 2000);
    assert.equal(cleanCommand(['t', 1, 2, 'x', '#fff', 500])[5], 96);
    assert.deepEqual(cleanCommand(['r', 1, 2, 3, 4, '#fff', 2, true]), ['r', 1, 2, 3, 4, '#fff', 2, 1]);
});
