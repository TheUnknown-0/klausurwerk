/**
 * Einheitliches Löschen per Taste und Knopf für Listen und Editoren.
 *
 * Bedienung überall gleich: Element anklicken = auswählen (sichtbar markiert), dann Entf oder
 * Rücktaste oder der Knopf "Löschen" der jeweiligen Werkzeugleiste. In Eingabefeldern,
 * Textbereichen, Auswahllisten und contenteditable-Elementen bleibt die Rücktaste ein
 * normales Zeichenlöschen: dort wird niemals ein Element gelöscht.
 */
import { h, icon } from '../lib/dom.js';

const TYPING_TAGS = new Set(['input', 'textarea', 'select']);
const CONTROL_TAGS = new Set(['input', 'textarea', 'select', 'button', 'a', 'label', 'summary', 'option']);

/** Ist das Ziel ein Element, in dem Tasten Text bearbeiten? (auch Code-Editor, CodeMirror) */
export function isTypingTarget(el) {
    if (!el) {
        return false;
    }
    const tag = el.tagName?.toLowerCase?.() ?? '';
    if (TYPING_TAGS.has(tag) || el.isContentEditable === true) {
        return true;
    }
    for (let cur = el; cur; cur = cur.parentNode) {
        if (typeof cur.getAttribute === 'function' && (cur.getAttribute('contenteditable') === 'true' || cur.getAttribute('contenteditable') === '')) {
            return true;
        }
        const cls = typeof cur.getAttribute === 'function' ? (cur.getAttribute('class') ?? '') : '';
        if (cls.split(/\s+/).includes('cm-editor')) {
            return true;
        }
    }

    return false;
}

/** Entf oder Rücktaste ohne Umschalttasten (Strg+Rücktaste usw. gehören dem Browser). */
export function isDeleteKey(ev) {
    return (ev.key === 'Delete' || ev.key === 'Backspace') && !ev.ctrlKey && !ev.metaKey && !ev.altKey;
}

/** Ist das Ziel ein bedienbares Element (Knopf, Feld, Link), das einen Klick selbst verwertet? */
export function isControlTarget(el, stop = null) {
    for (let cur = el; cur && cur !== stop; cur = cur.parentNode) {
        if (CONTROL_TAGS.has(cur.tagName?.toLowerCase?.() ?? '')) {
            return true;
        }
    }

    return false;
}

/**
 * Auswahl für eine Liste von Zeilen (Antwortoptionen, Paare, Tests ...).
 *
 * Ablauf je Zeichnen der Liste: begin(), dann bind(zeile, schlüssel, {canDelete, label}) je Zeile,
 * am Ende end(). Die Auswahl bleibt über das Neuzeichnen erhalten (Schlüssel).
 *
 * @param {{
 *   label: string,
 *   onDelete: (key: string, info: {viaKey: boolean}) => void,
 * }} opts
 */
export function rowSelection({ label, onDelete }) {
    const rows = new Map();
    let selected = null;

    const deleteBtn = h('button', {
        type: 'button', class: 'btn btn-sm btn-danger-ghost', disabled: true,
        title: 'Ausgewählte Zeile löschen (Entf oder Rücktaste)',
        'aria-label': `Ausgewählte Zeile löschen: ${label}`,
        onclick: () => remove(false),
    }, icon('trash-2'), 'Löschen');

    function paint() {
        for (const [key, entry] of rows) {
            const on = key === selected;
            entry.el.classList.toggle('is-selected', on);
            if (on) {
                entry.el.setAttribute('aria-current', 'true');
            } else {
                entry.el.removeAttribute('aria-current');
            }
        }
        deleteBtn.disabled = !(selected !== null && rows.get(selected)?.canDelete);
    }

    function select(key, { focus = false } = {}) {
        if (key !== null && !rows.has(key)) {
            key = null;
        }
        selected = key;
        paint();
        if (focus && key !== null) {
            rows.get(key).el.focus?.({ preventScroll: true });
        }
    }

    function remove(viaKey) {
        const entry = selected !== null ? rows.get(selected) : null;
        if (!entry || !entry.canDelete) {
            return false;
        }
        const key = selected;
        selected = null;
        onDelete(key, { viaKey });

        return true;
    }

    return {
        button: deleteBtn,
        get selected() { return selected; },
        select,
        remove,
        begin() {
            rows.clear();
        },
        /** Macht eine Zeile auswählbar, fokussierbar und mit Entf/Rücktaste löschbar. */
        bind(el, key, { canDelete = true, name = label } = {}) {
            rows.set(key, { el, canDelete });
            el.setAttribute('tabindex', '0');
            el.setAttribute('role', 'group');
            el.classList.add('te-selectable');
            el.setAttribute('aria-label', name);
            el.addEventListener('click', (ev) => {
                if (!isControlTarget(ev.target, el)) {
                    select(key, { focus: true });
                }
            });
            el.addEventListener('focusin', () => {
                if (selected !== key) {
                    select(key);
                }
            });
            el.addEventListener('keydown', (ev) => {
                if (ev.target !== el || !isDeleteKey(ev) || isTypingTarget(ev.target)) {
                    return;
                }
                ev.preventDefault();
                select(key);
                remove(true);
            });
        },
        end() {
            if (selected !== null && !rows.has(selected)) {
                selected = null;
            }
            paint();
        },
    };
}
