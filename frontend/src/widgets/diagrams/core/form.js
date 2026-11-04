/**
 * Formularbausteine für den Eigenschaften-Bereich der Diagramme (DOM per h(), nie innerHTML).
 */
import { h } from '../../../lib/dom.js';

let counter = 0;
export const uid = (prefix = 'dgf') => `${prefix}-${++counter}`;

/** Beschriftetes Feld. */
export function field(labelText, control, { inline = false, hint = null } = {}) {
    if (!control.id) {
        control.id = uid();
    }

    return h('div', { class: `dg-field${inline ? ' is-inline' : ''}` },
        h('label', { for: control.id }, labelText),
        control,
        hint ? h('div', { class: 'dg-field-hint' }, hint) : null);
}

export function textInput(value, onInput, attrs = {}) {
    const el = h('input', { type: 'text', class: 'input dg-input', autocomplete: 'off', spellcheck: 'false', ...attrs });
    el.value = value ?? '';
    el.addEventListener('input', () => onInput(el.value));

    return el;
}

export function textArea(value, onInput, attrs = {}) {
    const el = h('textarea', { class: 'input dg-input', rows: 2, spellcheck: 'false', ...attrs });
    el.value = value ?? '';
    el.addEventListener('input', () => onInput(el.value));

    return el;
}

/** options: [[wert, text], ...] */
export function selectInput(options, value, onChange, attrs = {}) {
    const el = h('select', { class: 'input dg-input', ...attrs }, options.map(([v, label]) => h('option', { value: v }, label)));
    el.value = value ?? options[0]?.[0] ?? '';
    el.addEventListener('change', () => onChange(el.value));

    return el;
}

export function checkInput(label, checked, onChange, attrs = {}) {
    const input = h('input', { type: 'checkbox', ...attrs });
    input.checked = !!checked;
    input.addEventListener('change', () => onChange(input.checked));

    return h('label', { class: 'dg-check' }, input, ' ', label);
}

export function smallButton(label, onClick, attrs = {}) {
    return h('button', { type: 'button', class: 'btn btn-sm', onclick: onClick, ...attrs }, label);
}

/** Überschrift + Inhalt eines Eigenschaften-Bereichs. */
export function panel(title, ...children) {
    return h('div', { class: 'dg-panel' }, h('h3', { class: 'dg-panel-title' }, title), ...children);
}

export const hintText = (text) => h('p', { class: 'dg-panel-hint' }, text);
