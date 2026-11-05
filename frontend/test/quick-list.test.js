import test from 'node:test';
import assert from 'node:assert/strict';

import { installDom } from './support/dom-stub.js';

installDom();
const { parseTypeValue, plainExcerpt, previewOf, typeOptions, typeValue } = await import('../src/task-editor/quick-list.js');

const TYPES = [
    { name: 'code', label: 'Programmcode', group: 'Programmieren' },
    { name: 'choice', label: 'Multiple Choice', group: 'Text & Auswahl' },
    { name: 'ordering', label: 'Reihenfolge', group: 'Text & Auswahl' },
    { name: 'uml', label: 'UML-Klassendiagramm', group: 'Diagramme' },
];
const byName = new Map(TYPES.map((t) => [t.name, t]));

test('typeValue und parseTypeValue: Code je Sprache, Überschrift leer', () => {
    assert.equal(typeValue({ answer_type: null }), '');
    assert.equal(typeValue({ answer_type: 'choice' }), 'choice');
    assert.equal(typeValue({ answer_type: 'code', config: { language: 'python' } }), 'code:python');
    assert.equal(typeValue({ answer_type: 'code', config: {} }), 'code:java');
    assert.equal(typeValue({ answer_type: 'code', config: { language: 'cobol' } }), 'code:java');
    assert.deepEqual(parseTypeValue('code:sql'), { type: 'code', language: 'sql' });
    assert.deepEqual(parseTypeValue(''), { type: '', language: null });
    assert.deepEqual(parseTypeValue('uml'), { type: 'uml', language: null });
});

test('typeOptions: Programmcode dreifach, Überschrift nur auf oberster Ebene', () => {
    const groups = typeOptions(TYPES);
    assert.deepEqual(groups[0].options.map((o) => o[0]), ['code:java', 'code:python', 'code:sql']);
    assert.equal(groups.at(-1).options[0][0], '');
    assert.ok(!typeOptions(TYPES, { allowContainer: false }).some((g) => g.options.some((o) => o[0] === '')));
});

test('plainExcerpt: Markdown wird lesbarer Klartext und gekürzt', () => {
    assert.equal(plainExcerpt('## Ausgangslage\n\nGegeben ist **ein** `int`-Array.'), 'Ausgangslage Gegeben ist ein int-Array.');
    assert.equal(plainExcerpt('Siehe ![Plan](material:4) und\n```java\nx++;\n```'), 'Siehe [Bild: Plan] und [Code]');
    assert.equal(plainExcerpt('Variable `max_wert` und __fett__'), 'Variable max_wert und fett');
    const gaps = previewOf({ answer_type: 'gaps', config: { template: 'for (int i = 0; i {{g1}} 10; i++)', gaps: [{ id: 'g1' }] } }, byName);
    assert.equal(gaps.summary, 'for (int i = 0; i [ … ] 10; i++) (1 Lücke)');
    const long = plainExcerpt('a'.repeat(300), 50);
    assert.equal(long.length, 50);
    assert.ok(long.endsWith('…'));
});

test('previewOf: Auswahl zeigt Optionen mit richtiger Antwort', () => {
    const p = previewOf({
        answer_type: 'choice', body_md: 'Welcher Typ?',
        config: { multiple: false, options: [{ id: 'a', text_md: '`int`' }, { id: 'b', text_md: '`String`' }] },
        solution: { correct: ['b'] },
    }, byName);
    assert.equal(p.label, 'Multiple Choice');
    assert.equal(p.excerpt, 'Welcher Typ?');
    assert.deepEqual(p.items.map((i) => [i.mark, i.text, i.correct]), [['○', 'int', false], ['◉', 'String', true]]);
});

test('previewOf: Reihenfolge in Lösungsreihenfolge, Code mit Sprache, Überschrift mit Teilaufgaben', () => {
    const ord = previewOf({
        answer_type: 'ordering', config: { items: [{ id: 's1', text_md: 'A' }, { id: 's2', text_md: 'B' }] }, solution: { order: ['s2', 's1'] },
    }, byName);
    assert.equal(ord.ordered, true);
    assert.deepEqual(ord.items.map((i) => i.text), ['B', 'A']);

    const code = previewOf({ answer_type: 'code', config: { language: 'python', main_file: '' }, tests: [{}, {}] }, byName);
    assert.equal(code.label, 'Programmcode (Python)');
    assert.equal(code.summary, 'Startdatei main.py, 2 Tests');

    const head = previewOf({ answer_type: null, body_md: '' }, byName, 3);
    assert.equal(head.label, 'Überschrift');
    assert.equal(head.summary, '3 Teilaufgaben');
});
