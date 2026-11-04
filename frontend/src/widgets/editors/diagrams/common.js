/**
 * Gemeinsame Bausteine der Aufgaben-Editoren für Diagramm-Aufgaben.
 * Die Musterlösung wird mit demselben Zeichen-Widget bearbeitet, das auch die Schüler:innen nutzen.
 */
import { h } from '../../../lib/dom.js';
import { answerWidget } from '../../../lib/widgets.js';
import { labeled, textInput } from '../../../task-editor/ui.js';

/** Bootstrap-Daten der Aufgaben-Seite (Materialien, Klausur-ID) oder null. */
export function taskBoot() {
    try {
        const el = document.getElementById('task-editor-boot');

        return el ? JSON.parse(el.textContent ?? 'null') : null;
    } catch {
        return null;
    }
}

/**
 * Hängt das Antwort-Widget eines Typs als Musterlösung ein.
 *
 * @param {HTMLElement} host
 * @param {string} type answer_type
 * @param {{config: any, content: any, onChange: () => void, locked?: boolean}} o
 * @returns {{getContent: () => any, setConfig: (c: any) => void, destroy: () => void, instance: any}}
 */
export function embedWidget(host, type, o) {
    const widget = answerWidget(type);
    let config = o.config;
    let instance = null;
    const boot = taskBoot();
    const mount = (content) => {
        const box = h('div', { class: 'te-diagram-embed' });
        host.replaceChildren(box);
        instance = widget.mount(box, {
            task: { id: 0, answer_type: type, config },
            content,
            readOnly: false,
            mode: 'preview',
            env: { examId: boot?.exam_id ?? null, settings: boot?.settings ?? {}, runtime: null },
            onChange: () => o.onChange(),
        });
    };
    if (!widget) {
        host.replaceChildren(h('div', { class: 'alert alert-error' }, 'Das Zeichen-Widget für diesen Typ fehlt.'));

        return { getContent: () => o.content ?? {}, setConfig() {}, destroy() {}, instance: null };
    }
    mount(o.content);

    return {
        getContent: () => instance.getContent(),
        setConfig(next) {
            config = next;
            if (typeof instance.setConfig === 'function') {
                instance.setConfig(next);
            } else {
                const content = instance.getContent();
                instance.destroy();
                mount(content);
            }
        },
        destroy: () => instance?.destroy(),
        get instance() {
            return instance;
        },
    };
}

/** Abschnitt mit Überschrift und Erklärung. */
export function section(title, intro, ...children) {
    return h('div', { class: 'te-subsection' },
        h('h4', { class: 'te-subtitle' }, title),
        intro ? h('p', { class: 'text-soft te-intro' }, intro) : null,
        ...children);
}

/** Namensliste ("A, B") als Textfeld; liefert { field, get() }. */
export function namesField(label, names, onChange, { locked = false, hint = null, id = null } = {}) {
    const input = textInput((names ?? []).join(', '), () => onChange(), { disabled: locked, maxlength: 120, ...(id ? { id } : {}) });

    return { field: labeled(label, input, hint), input, get: () => parseNames(input.value) };
}

/** "A, B C" => ['A','B','C'] */
export function parseNames(raw) {
    return String(raw ?? '').split(/[\s,;]+/).map((x) => x.trim()).filter(Boolean);
}

const NAME_RE = /^[A-Za-z][A-Za-z0-9_]{0,9}$/;

/** Prüft Namen für Ein- und Ausgänge; liefert Fehlermeldungen. */
export function checkNames(names, what, { min = 1, max = 6 } = {}) {
    const errors = [];
    if (names.length < min) {
        errors.push(`Gib mindestens ${min === 1 ? 'einen' : min} ${what} an.`);
    }
    if (names.length > max) {
        errors.push(`Höchstens ${max} ${what} sind erlaubt.`);
    }
    const bad = names.filter((n) => !NAME_RE.test(n));
    if (bad.length > 0) {
        errors.push(`Ungültiger Name bei ${what}: ${bad.join(', ')} (Buchstabe, dann Buchstaben, Ziffern oder _, höchstens 10 Zeichen).`);
    }
    if (new Set(names).size !== names.length) {
        errors.push(`Die Namen bei ${what} müssen verschieden sein.`);
    }

    return errors;
}
