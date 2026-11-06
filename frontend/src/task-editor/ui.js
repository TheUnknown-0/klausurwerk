/**
 * Kleine Formular-Bausteine für den Aufgaben-Editor und die typspezifischen Editoren.
 * Alles wird mit h() gebaut (Textknoten, kein innerHTML).
 */
import { h, icon, toast } from '../lib/dom.js';
import { previewUrl, previewWindowName } from '../lib/preview-link.js';
import { rowSelection } from '../widgets/delete-keys.js';

export { isControlTarget, isDeleteKey, isTypingTarget, rowSelection } from '../widgets/delete-keys.js';

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

    const sel = rowSelection({
        label,
        onDelete: (id, { viaKey }) => {
            const index = items.findIndex((i) => i.id === id);
            if (index < 0) {
                return;
            }
            items.splice(index, 1);
            render();
            const next = items[Math.min(index, items.length - 1)];
            if (next) {
                sel.select(next.id, { focus: viaKey });
            } else {
                addBtn.focus?.();
            }
            onChange();
        },
    });

    function move(index, delta) {
        const to = index + delta;
        if (to < 0 || to >= items.length) {
            return;
        }
        const movedId = items[index].id;
        [items[index], items[to]] = [items[to], items[index]];
        render();
        sel.select(movedId);
        onChange();
    }

    function render() {
        list.replaceChildren();
        sel.begin();
        items.forEach((item, index) => {
            const area = textarea(item.text_md, (v) => {
                item.text_md = v;
                onChange();
            }, { rows: 2, 'aria-label': `${label} ${index + 1}`, disabled: locked, placeholder: 'Text (Markdown möglich)' });
            const actions = h('div', { class: 'te-item-actions' },
                button(icon('arrow-up'), () => move(index, -1), { 'aria-label': 'Nach oben', disabled: locked || index === 0 }),
                button(icon('arrow-down'), () => move(index, 1), { 'aria-label': 'Nach unten', disabled: locked || index === items.length - 1 }),
                button(icon('x'), () => {
                    sel.select(item.id);
                    sel.remove(false);
                }, { 'aria-label': `${label} ${index + 1} entfernen`, disabled: locked || items.length <= min }),
            );
            const row = h('div', { class: 'te-item' },
                lead?.(item, index) ?? h('span', { class: 'text-faint mono' }, item.id),
                h('div', {}, area, extra?.(item, render) ?? null),
                actions,
            );
            sel.bind(row, item.id, { canDelete: !locked && items.length > min, name: `${label} ${index + 1}` });
            list.append(row);
        });
        sel.end();
        addBtn.disabled = locked || items.length >= max;
    }

    render();

    return { el: h('div', {}, list, h('div', { class: 'te-list-bar' }, addBtn, locked ? null : sel.button)), items, render, selection: sel };
}

/** Wandelt eine leere Liste aus PHP (statt {}) in ein Objekt. */
export const asObject = (value) => (value && typeof value === 'object' && !Array.isArray(value) ? value : {});

const isBlankWindow = (win) => {
    try {
        return win.location.href === 'about:blank';
    } catch {
        return false;
    }
};

/**
 * Öffnet die Schüleransicht (Vorschau) bei einer Aufgabe, in einem eigenen Tab je Klausur.
 * Das Fenster entsteht sofort beim Klick, sonst blockiert der Browser es nach dem Speichern als
 * Pop-up. Erst wenn flush() alles gespeichert hat, wird es auf die Aufgabe gelenkt.
 *
 * @param {{ examUrl: string, examId: number, taskId: number, flush: () => Promise<boolean> }} o
 * @returns {Promise<boolean>} ob die Vorschau geöffnet wurde
 */
export async function openTaskPreview({ examUrl, examId, taskId, flush }) {
    const win = window.open('', previewWindowName(examId));
    if (win && isBlankWindow(win)) {
        win.document.title = 'Vorschau';
        win.document.body.textContent = 'Speichert und öffnet die Vorschau ...';
    }
    const ok = await flush();
    if (!ok) {
        if (win && isBlankWindow(win)) {
            win.close();
        }
        return false;
    }
    if (!win) {
        toast('Der Browser hat das Vorschaufenster blockiert. Bitte Pop-ups für diese Seite erlauben.', 'error', 8000);
        return false;
    }
    win.location.href = previewUrl(examUrl, taskId);
    win.focus();
    return true;
}

/** Knopf "Vorschau" für eine Aufgabe. */
export function previewButton(onClick, { compact = false, number = '' } = {}) {
    const label = 'Vorschau: So sehen Schüler:innen diese Frage (neuer Tab, speichert vorher)';
    return compact
        ? h('button', { type: 'button', class: 'btn btn-sm btn-icon btn-ghost qf-preview-btn', 'aria-label': `Vorschau von Aufgabe ${number}`.trim(), title: label, onclick: onClick }, icon('eye'))
        : h('button', { type: 'button', class: 'btn btn-sm', title: label, onclick: onClick }, icon('eye'), 'Vorschau');
}
