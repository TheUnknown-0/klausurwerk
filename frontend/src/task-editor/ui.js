/**
 * Kleine Formular-Bausteine für den Aufgaben-Editor und die typspezifischen Editoren.
 * Alles wird mit h() gebaut (Textknoten, kein innerHTML).
 */
import { h, icon } from '../lib/dom.js';

let counter = 0;
export const uid = (prefix = 'te') => `${prefix}-${++counter}`;

/** Beschriftetes Feld: <div class="field"><label/>control<hint/></div> */
export function labeled(labelText, control, hint = null) {
    if (!control.id) {
        control.id = uid();
    }
    const hintId = hint ? `${control.id}-hint` : null;
    if (hintId) {
        control.setAttribute('aria-describedby', hintId);
    }
    return h('div', { class: 'field' },
        h('label', { for: control.id }, labelText),
        control,
        hint ? h('div', { class: 'hint', id: hintId }, hint) : null,
        h('div', { class: 'te-error', role: 'alert', 'data-error-for': control.id }),
    );
}

export function textInput(value, onChange, attrs = {}) {
    const el = h('input', { class: 'input', type: 'text', value: value ?? '', ...attrs });
    el.value = value ?? '';
    el.addEventListener('input', () => onChange?.(el.value));
    return el;
}

export function numberInput(value, onChange, attrs = {}) {
    const el = h('input', { class: 'input', type: 'number', ...attrs });
    el.value = value ?? '';
    el.addEventListener('input', () => onChange?.(el.value));
    return el;
}

export function textarea(value, onChange, attrs = {}) {
    const el = h('textarea', { class: 'input', rows: 3, ...attrs });
    el.value = value ?? '';
    el.addEventListener('input', () => onChange?.(el.value));
    return el;
}

export function select(options, value, onChange, attrs = {}) {
    const el = h('select', { ...attrs },
        options.map(([val, label]) => h('option', { value: val }, label)));
    el.value = value ?? options[0]?.[0] ?? '';
    el.addEventListener('change', () => onChange?.(el.value));
    return el;
}

export function checkbox(label, checked, onChange, attrs = {}) {
    const input = h('input', { type: 'checkbox', ...attrs });
    input.checked = !!checked;
    input.addEventListener('change', () => onChange?.(input.checked));
    return h('label', { class: 'checkbox-row' }, input, ' ', label);
}

export function button(label, onClick, attrs = {}) {
    return h('button', { type: 'button', class: 'btn btn-sm', onclick: onClick, ...attrs }, label);
}

/** Kleinste unbenutzte Kennung prefix1, prefix2, ... */
export function newId(prefix, used) {
    let n = 1;
    while (used.includes(`${prefix}${n}`)) {
        n += 1;
    }
    return `${prefix}${n}`;
}

/** Zeigt eine Fehlermeldung unter dem Feld mit der Kennung (id) bzw. ersatzweise am Container. */
export function showError(container, message) {
    let box = container.querySelector(':scope > .te-errors');
    if (!box) {
        box = h('div', { class: 'te-errors te-error', role: 'alert' });
        container.prepend(box);
    }
    box.textContent = message ?? '';
}

/**
 * Liste aus Einträgen { id, text_md, ... } mit Hinzufügen, Löschen und Verschieben.
 *
 * @param {{
 *   items: Array<{id: string, text_md: string}>, prefix: string, label: string,
 *   locked?: boolean, min?: number, max?: number,
 *   onChange: () => void, extra?: (item: any, refresh: () => void) => Node | null,
 *   lead?: (item: any, index: number) => Node | null,
 * }} opts
 */
export function itemsEditor(opts) {
    const { items, prefix, label, locked = false, min = 1, max = 20, onChange, extra, lead } = opts;
    const list = h('div', { class: 'te-items' });
    const addBtn = button([icon('plus'), `${label} hinzufügen`], () => {
        items.push({ id: newId(prefix, items.map((i) => i.id)), text_md: '' });
        render();
        onChange();
    }, { disabled: locked });

    function move(index, delta) {
        const to = index + delta;
        if (to < 0 || to >= items.length) {
            return;
        }
        [items[index], items[to]] = [items[to], items[index]];
        render();
        onChange();
    }

    function render() {
        list.replaceChildren();
        items.forEach((item, index) => {
            const area = textarea(item.text_md, (v) => {
                item.text_md = v;
                onChange();
            }, { rows: 2, 'aria-label': `${label} ${index + 1}`, disabled: locked, placeholder: 'Text (Markdown möglich)' });
            const actions = h('div', { class: 'te-item-actions' },
                button(icon('arrow-up'), () => move(index, -1), { 'aria-label': 'Nach oben', disabled: locked || index === 0 }),
                button(icon('arrow-down'), () => move(index, 1), { 'aria-label': 'Nach unten', disabled: locked || index === items.length - 1 }),
                button(icon('x'), () => {
                    items.splice(index, 1);
                    render();
                    onChange();
                }, { 'aria-label': `${label} ${index + 1} entfernen`, disabled: locked || items.length <= min }),
            );
            list.append(h('div', { class: 'te-item' },
                lead?.(item, index) ?? h('span', { class: 'text-faint mono' }, item.id),
                h('div', {}, area, extra?.(item, render) ?? null),
                actions,
            ));
        });
        addBtn.disabled = locked || items.length >= max;
    }
    render();

    return { el: h('div', {}, list, addBtn), items, render };
}

/** Wandelt eine leere Liste aus PHP (statt {}) in ein Objekt. */
export const asObject = (value) => (value && typeof value === 'object' && !Array.isArray(value) ? value : {});
