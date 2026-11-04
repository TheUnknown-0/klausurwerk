/**
 * Kleine Dialoge auf Basis des nativen <dialog class="modal"> (kein Inline-JS, Fokusfalle durch den Browser).
 */
import { h } from '../lib/dom.js';

function open(title, bodyChildren, footerButtons, onClose) {
    const dialog = h('dialog', { class: 'modal pult-dialog', 'aria-label': title });
    const close = h('button', { type: 'button', class: 'modal-close', 'aria-label': 'Schließen', onclick: () => dialog.close() }, '×');
    dialog.append(
        h('div', { class: 'modal-header' }, h('h3', {}, title), close),
        h('div', { class: 'modal-body' }, bodyChildren),
        h('div', { class: 'modal-footer' }, footerButtons),
    );
    dialog.addEventListener('close', () => {
        onClose?.();
        dialog.remove();
    });
    document.body.append(dialog);
    dialog.showModal();
    return dialog;
}

/** Bestätigung. Löst mit true/false auf. */
export function confirmDialog({ title, message, confirmLabel = 'OK', danger = false }) {
    return new Promise((resolve) => {
        let result = false;
        const ok = h('button', {
            type: 'button',
            class: `btn ${danger ? 'btn-danger' : 'btn-primary'}`,
            onclick: () => {
                result = true;
                dialog.close();
            },
        }, confirmLabel);
        const cancel = h('button', { type: 'button', class: 'btn', onclick: () => dialog.close() }, 'Abbrechen');
        const dialog = open(title, h('p', { style: { margin: 0, whiteSpace: 'pre-line' } }, message), [cancel, ok], () => resolve(result));
        ok.focus();
    });
}

/**
 * Eingabedialog mit einem Feld. Löst mit dem Text (bzw. der Zahl als Text) oder null auf.
 *
 * @param {{title: string, label: string, type?: 'text'|'textarea'|'number', value?: string, confirmLabel?: string,
 *          required?: boolean, min?: number, max?: number, help?: string, maxLength?: number}} options
 */
export function promptDialog(options) {
    return new Promise((resolve) => {
        let result = null;
        const id = `pult-prompt-${Math.random().toString(36).slice(2, 8)}`;
        const type = options.type ?? 'text';
        const input = type === 'textarea'
            ? h('textarea', { id, class: 'input', rows: 4, maxlength: options.maxLength ?? 500 }, options.value ?? '')
            : h('input', {
                id, class: 'input', type, value: options.value ?? '', min: options.min, max: options.max,
                maxlength: type === 'number' ? undefined : (options.maxLength ?? 200), autocomplete: 'off',
            });
        const error = h('div', { class: 'text-danger text-sm', role: 'alert', hidden: true });
        const submit = () => {
            const value = input.value.trim();
            if (options.required !== false && value === '') {
                error.textContent = 'Bitte fülle das Feld aus.';
                error.hidden = false;
                input.focus();
                return;
            }
            result = value;
            dialog.close();
        };
        const form = h('form', { class: 'stack', onsubmit: (e) => { e.preventDefault(); submit(); } },
            h('div', { class: 'field' }, h('label', { for: id }, options.label), input),
            options.help ? h('div', { class: 'text-faint text-sm' }, options.help) : null,
            error,
        );
        const ok = h('button', { type: 'button', class: 'btn btn-primary', onclick: submit }, options.confirmLabel ?? 'OK');
        const cancel = h('button', { type: 'button', class: 'btn', onclick: () => dialog.close() }, 'Abbrechen');
        const dialog = open(options.title, form, [cancel, ok], () => resolve(result));
        input.focus();
        input.select?.();
    });
}

/** Großes Fenster für Replay u. Ä. Rückgabe: { dialog, body, close }. */
export function panelDialog(title, onClose) {
    const body = h('div', { class: 'pult-panel-body' });
    const dialog = open(title, body, [h('button', { type: 'button', class: 'btn', onclick: () => dialog.close() }, 'Schließen')], onClose);
    dialog.classList.add('pult-dialog-wide');
    return { dialog, body, close: () => dialog.close() };
}
