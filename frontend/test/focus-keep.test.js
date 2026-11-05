import test from 'node:test';
import assert from 'node:assert/strict';

import { installDom } from './support/dom-stub.js';

const body = installDom();
const { captureFocus, focusablesIn, restoreFocus } = await import('../src/lib/focus-keep.js');

/** Baut ein Formular wie nach jedem Neuzeichnen: neue Elemente, gleiche Struktur. */
function buildForm(labels = ['Titel', 'Punkte', 'Antwortoption 1', 'Antwortoption 2']) {
    const host = document.createElement('div');
    const section = document.createElement('section');
    host.append(section);
    for (const label of labels) {
        const field = document.createElement(label.startsWith('Antwort') ? 'textarea' : 'input');
        field.setAttribute('aria-label', label);
        const wrap = document.createElement('div');
        wrap.append(field);
        section.append(wrap);
    }
    const editor = document.createElement('div');
    const content = document.createElement('div');
    content.setAttribute('contenteditable', 'true');
    editor.append(content);
    section.append(editor);
    section.append(document.createElement('button'));

    return host;
}

test('focusablesIn: Felder, contenteditable und Knöpfe in Dokumentreihenfolge', () => {
    const host = buildForm();
    const list = focusablesIn(host);
    assert.deepEqual(list.map((e) => e.localName), ['input', 'input', 'textarea', 'textarea', 'div', 'button']);
});

test('Fokus und Cursor überstehen das Neuzeichnen', () => {
    const slot = document.createElement('div');
    body.append(slot);
    let form = buildForm();
    slot.replaceChildren(form);
    const area = focusablesIn(form)[3];
    area.focus();
    area.selectionStart = 4;
    area.selectionEnd = 6;
    area.selectionDirection = 'forward';
    const saved = captureFocus(slot, { extra: () => 'auswahl' });
    assert.equal(saved.index, 3);
    assert.equal(saved.start, 4);

    form = buildForm();
    slot.replaceChildren(form);
    const fresh = focusablesIn(form)[3];
    let range = null;
    fresh.setSelectionRange = (a, b, dir) => { range = [a, b, dir]; };
    let extra = null;
    assert.equal(restoreFocus(slot, saved, { extra: (el, data) => { extra = [el, data]; } }), true);
    assert.equal(document.activeElement, fresh);
    assert.deepEqual(range, [4, 6, 'forward']);
    assert.deepEqual(extra, [fresh, 'auswahl']);
});

test('Anderes Element an derselben Stelle: Fokus wird nicht falsch gesetzt', () => {
    const slot = document.createElement('div');
    body.append(slot);
    slot.replaceChildren(buildForm());
    focusablesIn(slot)[2].focus();
    const saved = captureFocus(slot);
    slot.replaceChildren(buildForm(['Titel', 'Punkte', 'Lücke 1']));
    const before = document.activeElement;
    assert.equal(restoreFocus(slot, saved), false);
    assert.equal(document.activeElement, before);
});

test('Fokus außerhalb des Bereichs wird nicht gemerkt', () => {
    const slot = document.createElement('div');
    body.append(slot);
    slot.replaceChildren(buildForm());
    const outside = document.createElement('input');
    body.append(outside);
    outside.focus();
    assert.equal(captureFocus(slot), null);
    assert.equal(restoreFocus(slot, null), false);
});
