import test from 'node:test';
import assert from 'node:assert/strict';

import { installDom } from './support/dom-stub.js';

installDom();
const { scriptChar, charGroups, CHAR_SETS, resolveCharset, resolveLanguage, CHARSET_OPTIONS } = await import('../src/lib/char-bar.js');
const { addMark, removeMarks } = await import('../src/exam/highlighter.js');
const { parseFormula, molarMass } = await import('../src/exam/periodic-table.js');
const { pickerGroups } = await import('../src/task-editor/type-picker.js');
const { playsLeftText } = await import('../src/exam/media-player.js');

test('Zeichenleiste: Sätze, Hoch- und Tiefstellen, Auflösung über das Fach', () => {
    assert.equal(scriptChar('2', 'sub'), '₂');
    assert.equal(scriptChar('3', 'sup'), '³');
    assert.equal(scriptChar('+', 'sup'), '⁺');
    assert.equal(scriptChar('-', 'sup'), '⁻');
    assert.equal(scriptChar('a', 'sub'), null);
    assert.equal(scriptChar('2', null), null);
    assert.ok(charGroups('fr').flat().includes('ç'));
    assert.ok(charGroups('es').flat().includes('¿'));
    assert.ok(charGroups('la').flat().includes('ā'));
    assert.deepEqual(charGroups('gibtsnicht'), []);
    // Keine Gedankenstriche (U+2014) in der Leiste
    assert.ok(Object.values(CHAR_SETS).every(([, chars]) => !chars.includes('—')));

    const env = { subject: { language: 'fr', charset: 'fr' } };
    assert.equal(resolveCharset({ config: {} }, env), 'fr');
    assert.equal(resolveCharset({ config: { chars: 'none' } }, env), '');
    assert.equal(resolveCharset({ config: { chars: 'chem' } }, env), 'chem');
    assert.equal(resolveCharset({ config: {} }, {}), '', 'ohne Fach keine Leiste');
    assert.equal(resolveLanguage({ config: { lang: 'la' } }, env), 'la');
    assert.equal(resolveLanguage({ config: {} }, null), 'de');
    assert.deepEqual(CHARSET_OPTIONS.slice(0, 2).map((o) => o[0]), ['', 'none']);
});

test('Textmarker: Zusammenlegen, Farben überschreiben, Entfernen', () => {
    let m = addMark([], 5, 10, 1);
    m = addMark(m, 8, 15, 1);
    assert.deepEqual(m, [[5, 15, 1]], 'gleiche Farbe verschmilzt');
    m = addMark(m, 10, 12, 2);
    assert.deepEqual(m, [[5, 10, 1], [10, 12, 2], [12, 15, 1]], 'andere Farbe schneidet aus');
    assert.deepEqual(removeMarks(m, 11, 11), [[5, 10, 1], [12, 15, 1]], 'Klick entfernt die Markierung an der Stelle');
    assert.deepEqual(removeMarks(m, 0, 100), []);
    assert.deepEqual(addMark(m, 4, 4, 1), m, 'leerer Bereich ändert nichts');
});

test('Molmassen-Rechner: Klammern, Hydrate, Tiefzahlen, Fehler', () => {
    const data = new Map([
        ['H', { m: '1.0080' }], ['O', { m: '15.999' }], ['S', { m: '32.06' }], ['Ca', { m: '40.078' }],
        ['Cu', { m: '63.546' }], ['C', { m: '12.011' }], ['Co', { m: '58.933194' }],
    ]);
    const known = new Set(data.keys());
    const counts = (f) => Object.fromEntries(parseFormula(f, known));
    assert.deepEqual(counts('H2SO4'), { H: 2, S: 1, O: 4 });
    assert.deepEqual(counts('Ca(OH)2'), { Ca: 1, O: 2, H: 2 });
    assert.deepEqual(counts('CuSO4·5H2O'), { Cu: 1, S: 1, O: 9, H: 10 });
    assert.deepEqual(counts('H₂O'), { H: 2, O: 1 });
    assert.deepEqual(counts('SO₄²⁻'), { S: 1, O: 4 }, 'Ladung wird ignoriert');
    assert.deepEqual(counts('CO2'), { C: 1, O: 2 });
    assert.deepEqual(counts('Co'), { Co: 1 });
    assert.throws(() => parseFormula('Ca(OH2', known), /Klammer/);
    assert.throws(() => parseFormula('Xy', known), /Unbekanntes Element/);
    assert.throws(() => parseFormula('h2o', known), /Großbuchstaben/);
    assert.throws(() => parseFormula('', known), /Summenformel/);

    const water = molarMass(parseFormula('H2O', known), data);
    assert.equal(water.total.toFixed(3), '18.015');
    assert.equal(water.parts.length, 2);
});

test('Typ-Kacheln: andere Fächer bekommen Text vorne, Informatik eingeklappt', () => {
    const types = [
        { name: 'text', label: 'Freitext', group: 'Text & Auswahl' },
        { name: 'code', label: 'Programmcode', group: 'Programmieren' },
        { name: 'uml', label: 'UML', group: 'Diagramme' },
        { name: 'structure', label: 'Strukturformel', group: 'Diagramme' },
        { name: 'drawing', label: 'Zeichnung', group: 'Diagramme' },
    ];
    const inf = pickerGroups(types);
    assert.equal(inf[0].group, 'Programmieren');
    assert.ok(inf.every((g) => !g.collapsed));

    const chem = pickerGroups(types, 'chemie');
    assert.deepEqual(chem.map((g) => g.group), ['Text & Auswahl', 'Diagramme', 'Gliederung', 'Informatik']);
    const it = chem.at(-1);
    assert.equal(it.collapsed, true);
    assert.deepEqual(it.tiles.map((t) => t.label), ['Java', 'Python', 'SQL', 'UML']);
    assert.deepEqual(chem[1].tiles.map((t) => t.type), ['structure', 'drawing']);
    assert.match(chem[0].tiles[0].desc, /H₂O/);

    const en = pickerGroups(types, 'fremdsprache');
    assert.match(en[0].tiles[0].desc, /Mediation/);
});

test('Abspielzähler-Text', () => {
    assert.equal(playsLeftText(0, null), '');
    assert.match(playsLeftText(1, 2), /Noch 1 von 2/);
    assert.match(playsLeftText(2, 2), /kein weiterer Start/);
});
