/**
 * Einfache Antwort-Widgets: text, choice, matching, ordering, gaps.
 * Formate: docs/DATENFORMATE.md, Abschnitte 2.2 bis 2.6.
 *
 * Alle Widgets
 *  - unterstützen readOnly (Pult, Korrektur, Sperre, Abgabe) und setContent()
 *    (Live-Mitlesen im Pult),
 *  - melden jede Änderung mit onChange(vollerInhalt, {kind: 'value'}),
 *  - setzen Texte aus Konfigurationen NIE per innerHTML ein. Nur das vom Server
 *    bereinigte text_html der Optionen geht über setTrustedHtml.
 */
import { registerAnswerWidget } from '../lib/widgets.js';
import { h, setTrustedHtml, icon } from '../lib/dom.js';
import { enhanceRichText } from '../lib/rich-text.js';

let uid = 0;
const nextId = (prefix) => `${prefix}-${++uid}`;

/** Reiner Text aus bereinigtem HTML (ohne Skripte/Bilder zu laden). */
export function plainText(html) {
    try {
        return (new DOMParser().parseFromString(String(html ?? ''), 'text/html').body.textContent ?? '').trim();
    } catch {
        return '';
    }
}

/** Text einer Option: vom Server gerendertes HTML, sonst Markdown-Rohtext als Text. */
function fillItem(el, item) {
    if (typeof item.text_html === 'string' && item.text_html !== '') {
        setTrustedHtml(el, item.text_html);
        enhanceRichText(el);
    } else {
        el.textContent = String(item.text_md ?? item.text ?? '');
    }
}

const itemPlain = (item) => (typeof item.text_html === 'string' && item.text_html !== '' ? plainText(item.text_html) : String(item.text_md ?? item.text ?? ''));

/** Gemeinsame Hülle: readOnly-Verwaltung, destroy. */
function shell(container, ctx, className) {
    const root = h('div', { class: `w-basic ${className}`, dataset: { mode: ctx.mode ?? 'exam' } });
    container.append(root);
    const state = { readOnly: !!ctx.readOnly, controls: [] };
    const emit = (content) => {
        if (!state.readOnly && typeof ctx.onChange === 'function') {
            ctx.onChange(content, { kind: 'value' });
        }
    };
    return { root, state, emit };
}

// ---------------------------------------------------------------------------
// text
// ---------------------------------------------------------------------------

const TextWidget = {
    mount(container, ctx) {
        const cfg = ctx.task?.config ?? {};
        const { root, state, emit } = shell(container, ctx, 'w-text');
        const id = nextId('w-text');
        const maxChars = Number(cfg.max_chars) > 0 ? Number(cfg.max_chars) : 20000;
        const minWords = Number(cfg.min_words) > 0 ? Number(cfg.min_words) : 0;

        const area = h('textarea', {
            id,
            class: 'w-text-area',
            rows: 12,
            spellcheck: 'false',
            autocomplete: 'off',
            autocapitalize: 'off',
            autocorrect: 'off',
            maxlength: maxChars,
            placeholder: cfg.placeholder || 'Schreibe deine Antwort hier ...',
            'aria-label': 'Deine Antwort',
        });
        area.value = typeof ctx.content?.text === 'string' ? ctx.content.text : '';
        const counter = h('div', { class: 'w-text-meta', 'aria-live': 'off' });
        const words = h('span', { class: 'w-text-words' });
        const chars = h('span', { class: 'w-text-chars' });
        counter.append(words, chars);

        const countWords = (t) => (t.trim() === '' ? 0 : t.trim().split(/\s+/).length);
        const update = () => {
            const w = countWords(area.value);
            words.textContent = minWords > 0 ? `${w} Wörter (mindestens ${minWords})` : `${w} ${w === 1 ? 'Wort' : 'Wörter'}`;
            words.classList.toggle('is-ok', minWords > 0 && w >= minWords);
            words.classList.toggle('is-short', minWords > 0 && w < minWords);
            chars.textContent = `${area.value.length} / ${maxChars} Zeichen`;
            chars.classList.toggle('is-near', area.value.length > maxChars * 0.9);
        };
        area.addEventListener('input', () => {
            update();
            emit({ text: area.value });
        });
        area.readOnly = state.readOnly;
        update();
        root.append(area, counter);

        return {
            layout: 'stack',
            getContent: () => ({ text: area.value }),
            setContent(content) {
                const t = typeof content?.text === 'string' ? content.text : '';
                if (t !== area.value) {
                    area.value = t;
                    update();
                }
            },
            setReadOnly(flag) {
                state.readOnly = !!flag;
                area.readOnly = state.readOnly;
                root.classList.toggle('is-readonly', state.readOnly);
            },
            focus: () => area.focus(),
            destroy: () => root.remove(),
        };
    },
};

// ---------------------------------------------------------------------------
// choice
// ---------------------------------------------------------------------------

const ChoiceWidget = {
    mount(container, ctx) {
        const cfg = ctx.task?.config ?? {};
        const options = Array.isArray(cfg.options) ? cfg.options : [];
        const multiple = !!cfg.multiple;
        const { root, state, emit } = shell(container, ctx, 'w-choice');
        const group = nextId('w-choice');
        let selected = new Set((Array.isArray(ctx.content?.selected) ? ctx.content.selected : []).map(String));
        const inputs = new Map();

        const fieldset = h('fieldset', { class: 'w-choice-set' });
        fieldset.append(h('legend', { class: 'sr-only' }, multiple ? 'Mehrere Antworten möglich' : 'Wähle eine Antwort'));
        if (multiple) {
            fieldset.append(h('p', { class: 'w-hint' }, 'Mehrere Antworten möglich.'));
        }
        for (const opt of options) {
            const id = String(opt.id);
            const input = h('input', { type: multiple ? 'checkbox' : 'radio', name: group, value: id });
            input.checked = selected.has(id);
            input.disabled = state.readOnly;
            const text = h('span', { class: 'w-choice-text' });
            fillItem(text, opt);
            const label = h('label', { class: 'w-choice-option' }, input, text);
            input.addEventListener('change', () => {
                if (multiple) {
                    if (input.checked) {
                        selected.add(id);
                    } else {
                        selected.delete(id);
                    }
                } else {
                    selected = new Set(input.checked ? [id] : []);
                }
                label.classList.toggle('is-selected', input.checked);
                emit(content());
            });
            label.classList.toggle('is-selected', input.checked);
            inputs.set(id, { input, label });
            fieldset.append(label);
        }
        root.append(fieldset);

        let reset = null;
        if (!multiple) {
            reset = h('button', { type: 'button', class: 'btn btn-sm btn-ghost w-reset' }, 'Auswahl zurücksetzen');
            reset.addEventListener('click', () => {
                selected = new Set();
                for (const { input, label } of inputs.values()) {
                    input.checked = false;
                    label.classList.remove('is-selected');
                }
                emit(content());
            });
            reset.disabled = state.readOnly;
            root.append(reset);
        }

        // Reihenfolge der Optionen folgt der Anzeige.
        const content = () => ({ selected: options.map((o) => String(o.id)).filter((id) => selected.has(id)) });

        const apply = () => {
            for (const [id, { input, label }] of inputs) {
                input.checked = selected.has(id);
                label.classList.toggle('is-selected', input.checked);
            }
        };

        return {
            layout: 'stack',
            getContent: content,
            setContent(c) {
                selected = new Set((Array.isArray(c?.selected) ? c.selected : []).map(String));
                apply();
            },
            setReadOnly(flag) {
                state.readOnly = !!flag;
                for (const { input } of inputs.values()) {
                    input.disabled = state.readOnly;
                }
                if (reset) {
                    reset.disabled = state.readOnly;
                }
                root.classList.toggle('is-readonly', state.readOnly);
            },
            focus: () => inputs.values().next().value?.input.focus(),
            destroy: () => root.remove(),
        };
    },
};

// ---------------------------------------------------------------------------
// matching
// ---------------------------------------------------------------------------

const MatchingWidget = {
    mount(container, ctx) {
        const cfg = ctx.task?.config ?? {};
        const left = Array.isArray(cfg.left) ? cfg.left : [];
        const right = Array.isArray(cfg.right) ? cfg.right : [];
        const rightIds = new Set(right.map((r) => String(r.id)));
        const { root, state, emit } = shell(container, ctx, 'w-matching');
        let pairs = {};
        const load = (c) => {
            pairs = {};
            const src = c?.pairs && typeof c.pairs === 'object' ? c.pairs : {};
            for (const l of left) {
                const v = src[String(l.id)];
                if (typeof v === 'string' && rightIds.has(v)) {
                    pairs[String(l.id)] = v;
                }
            }
        };
        load(ctx.content);

        root.append(h('p', { class: 'w-hint' }, 'Wähle zu jedem Eintrag links die passende Antwort aus der Liste. Du kannst die Antworten unten auch auf einen Eintrag ziehen.'));

        const selects = new Map();
        const rows = h('div', { class: 'w-matching-rows' });
        const show = (leftId) => {
            const sel = selects.get(leftId);
            if (sel) {
                sel.value = pairs[leftId] ?? '';
            }
        };
        const set = (leftId, rightId) => {
            if (state.readOnly) {
                return;
            }
            if (rightId) {
                pairs[leftId] = rightId;
            } else {
                delete pairs[leftId];
            }
            show(leftId);
            emit({ pairs: { ...pairs } });
        };

        for (const l of left) {
            const lid = String(l.id);
            const selId = nextId('w-match');
            const text = h('div', { class: 'w-matching-left-text' });
            fillItem(text, l);
            const select = h('select', { id: selId, class: 'w-matching-select', 'aria-label': `Zuordnung für: ${itemPlain(l)}` });
            select.append(h('option', { value: '' }, '(keine Zuordnung)'));
            for (const r of right) {
                select.append(h('option', { value: String(r.id) }, itemPlain(r) || String(r.id)));
            }
            select.value = pairs[lid] ?? '';
            select.disabled = state.readOnly;
            select.addEventListener('change', () => set(lid, select.value));
            selects.set(lid, select);
            const row = h('div', { class: 'w-matching-row' }, text, h('span', { class: 'w-matching-arrow', 'aria-hidden': 'true' }, '→'), select);
            row.addEventListener('dragover', (e) => {
                if (!state.readOnly && e.dataTransfer?.types?.includes('application/x-kw-match')) {
                    e.preventDefault();
                    row.classList.add('is-over');
                }
            });
            row.addEventListener('dragleave', () => row.classList.remove('is-over'));
            row.addEventListener('drop', (e) => {
                row.classList.remove('is-over');
                const rid = e.dataTransfer?.getData('application/x-kw-match');
                if (rid && rightIds.has(rid) && !state.readOnly) {
                    e.preventDefault();
                    set(lid, rid);
                }
            });
            rows.append(row);
        }
        root.append(rows);

        const pool = h('div', { class: 'w-matching-pool', role: 'group', 'aria-label': 'Antworten zum Ziehen' });
        for (const r of right) {
            const chip = h('div', { class: 'w-chip', draggable: 'true' });
            fillItem(chip, r);
            chip.addEventListener('dragstart', (e) => {
                if (state.readOnly) {
                    e.preventDefault();
                    return;
                }
                e.dataTransfer.setData('application/x-kw-match', String(r.id));
                e.dataTransfer.setData('text/plain', itemPlain(r));
                e.dataTransfer.effectAllowed = 'move';
            });
            pool.append(chip);
        }
        if (right.length > 0) {
            root.append(pool);
        }

        return {
            layout: 'stack',
            getContent: () => ({ pairs: { ...pairs } }),
            setContent(c) {
                load(c);
                left.forEach((l) => show(String(l.id)));
            },
            setReadOnly(flag) {
                state.readOnly = !!flag;
                for (const s of selects.values()) {
                    s.disabled = state.readOnly;
                }
                pool.classList.toggle('is-readonly', state.readOnly);
                root.classList.toggle('is-readonly', state.readOnly);
            },
            focus: () => selects.values().next().value?.focus(),
            destroy: () => root.remove(),
        };
    },
};

// ---------------------------------------------------------------------------
// ordering
// ---------------------------------------------------------------------------

/** Gültige Reihenfolge aus Inhalt und Konfiguration (unbekannte IDs raus, fehlende hinten dran). */
export function normalizeOrder(order, items) {
    const ids = items.map((i) => String(i.id));
    const known = new Set(ids);
    const out = [];
    for (const id of Array.isArray(order) ? order.map(String) : []) {
        if (known.has(id) && !out.includes(id)) {
            out.push(id);
        }
    }
    for (const id of ids) {
        if (!out.includes(id)) {
            out.push(id);
        }
    }
    return out;
}

const OrderingWidget = {
    mount(container, ctx) {
        const cfg = ctx.task?.config ?? {};
        const items = Array.isArray(cfg.items) ? cfg.items : [];
        const byId = new Map(items.map((i) => [String(i.id), i]));
        const { root, state, emit } = shell(container, ctx, 'w-ordering');
        let order = normalizeOrder(ctx.content?.order, items);
        let dragId = null;

        root.append(h('p', { class: 'w-hint' }, 'Bringe die Einträge in die richtige Reihenfolge: mit den Pfeilknöpfen oder durch Ziehen.'));
        const list = h('ol', { class: 'w-ordering-list' });
        root.append(list);
        const status = h('div', { class: 'sr-only', role: 'status', 'aria-live': 'polite' });
        root.append(status);

        const move = (from, to, focusId) => {
            if (state.readOnly || to < 0 || to >= order.length || from === to) {
                return;
            }
            const [id] = order.splice(from, 1);
            order.splice(to, 0, id);
            render(focusId);
            status.textContent = `Eintrag ${from + 1} nach Position ${to + 1} verschoben.`;
            emit({ order: order.slice() });
        };

        function render(focus = null) {
            list.replaceChildren();
            order.forEach((id, index) => {
                const item = byId.get(id);
                const text = h('div', { class: 'w-ordering-text' });
                fillItem(text, item);
                const up = h('button', { type: 'button', class: 'btn btn-sm w-move', 'aria-label': `Eintrag ${index + 1} nach oben` }, icon('arrow-up'));
                const down = h('button', { type: 'button', class: 'btn btn-sm w-move', 'aria-label': `Eintrag ${index + 1} nach unten` }, icon('arrow-down'));
                up.disabled = state.readOnly || index === 0;
                down.disabled = state.readOnly || index === order.length - 1;
                up.addEventListener('click', () => move(index, index - 1, `${id}:up`));
                down.addEventListener('click', () => move(index, index + 1, `${id}:down`));
                up.dataset.key = `${id}:up`;
                down.dataset.key = `${id}:down`;
                const li = h('li', { class: 'w-ordering-item', draggable: state.readOnly ? null : 'true', dataset: { id } },
                    h('span', { class: 'w-ordering-grip', 'aria-hidden': 'true' }, icon('move')),
                    text,
                    h('span', { class: 'w-ordering-buttons' }, up, down));
                li.addEventListener('dragstart', (e) => {
                    if (state.readOnly) {
                        e.preventDefault();
                        return;
                    }
                    dragId = id;
                    e.dataTransfer.setData('text/plain', itemPlain(item));
                    e.dataTransfer.effectAllowed = 'move';
                    li.classList.add('is-dragging');
                });
                li.addEventListener('dragend', () => {
                    dragId = null;
                    li.classList.remove('is-dragging');
                    list.querySelectorAll('.is-over').forEach((n) => n.classList.remove('is-over'));
                });
                li.addEventListener('dragover', (e) => {
                    if (dragId !== null && !state.readOnly) {
                        e.preventDefault();
                        li.classList.add('is-over');
                    }
                });
                li.addEventListener('dragleave', () => li.classList.remove('is-over'));
                li.addEventListener('drop', (e) => {
                    li.classList.remove('is-over');
                    if (dragId === null || state.readOnly) {
                        return;
                    }
                    e.preventDefault();
                    move(order.indexOf(dragId), index, null);
                });
                list.append(li);
            });
            if (focus) {
                list.querySelector(`[data-key="${CSS.escape(focus)}"]`)?.focus();
            }
        }
        render();

        return {
            layout: 'stack',
            getContent: () => ({ order: order.slice() }),
            setContent(c) {
                order = normalizeOrder(c?.order, items);
                render();
            },
            setReadOnly(flag) {
                state.readOnly = !!flag;
                render();
                root.classList.toggle('is-readonly', state.readOnly);
            },
            focus: () => list.querySelector('button:not(:disabled)')?.focus(),
            destroy: () => root.remove(),
        };
    },
};

// ---------------------------------------------------------------------------
// gaps
// ---------------------------------------------------------------------------

const GAP_PATTERN = /\{\{([A-Za-z0-9_-]{1,40})\}\}/g;

/** Zerlegt ein Template in Text- und Lückenteile: [{text}|{gap}]. */
export function splitTemplate(template) {
    const parts = [];
    let last = 0;
    const src = String(template ?? '');
    for (const m of src.matchAll(GAP_PATTERN)) {
        if (m.index > last) {
            parts.push({ text: src.slice(last, m.index) });
        }
        parts.push({ gap: m[1] });
        last = m.index + m[0].length;
    }
    if (last < src.length) {
        parts.push({ text: src.slice(last) });
    }
    return parts;
}

const GapsWidget = {
    mount(container, ctx) {
        const cfg = ctx.task?.config ?? {};
        const codeMode = cfg.mode === 'code';
        const gapCfg = new Map((Array.isArray(cfg.gaps) ? cfg.gaps : []).map((g) => [String(g.id), g]));
        const { root, state, emit } = shell(container, ctx, `w-gaps ${codeMode ? 'is-code' : 'is-text'}`);
        let values = {};
        const load = (c) => {
            values = {};
            const src = c?.values && typeof c.values === 'object' ? c.values : {};
            for (const [k, v] of Object.entries(src)) {
                if (typeof v === 'string') {
                    values[k] = v;
                }
            }
        };
        load(ctx.content);

        const body = h('div', { class: 'w-gaps-body' });
        const controls = new Map();
        const parts = splitTemplate(cfg.template);
        let n = 0;
        for (const part of parts) {
            if (part.text !== undefined) {
                body.append(document.createTextNode(part.text));
                continue;
            }
            n += 1;
            const id = part.gap;
            const g = gapCfg.get(id) ?? { id, width: 6, options: null };
            let control;
            if (Array.isArray(g.options) && g.options.length > 0) {
                control = h('select', { class: 'w-gap w-gap-select', 'aria-label': `Lücke ${n}` });
                control.append(h('option', { value: '' }, '...'));
                for (const o of g.options) {
                    control.append(h('option', { value: String(o) }, String(o)));
                }
                control.value = values[id] ?? '';
                control.addEventListener('change', () => {
                    values[id] = control.value;
                    emit({ values: { ...values } });
                });
            } else {
                const width = Math.max(2, Math.min(60, Number(g.width) || 6));
                control = h('input', {
                    type: 'text',
                    class: 'w-gap w-gap-input',
                    size: width,
                    style: { width: `${width + 1}ch` },
                    spellcheck: 'false',
                    autocomplete: 'off',
                    autocapitalize: 'off',
                    autocorrect: 'off',
                    'aria-label': `Lücke ${n}`,
                });
                control.value = values[id] ?? '';
                control.addEventListener('input', () => {
                    values[id] = control.value;
                    emit({ values: { ...values } });
                });
            }
            control.disabled = state.readOnly;
            controls.set(id, control);
            body.append(control);
        }
        root.append(body);

        return {
            layout: 'stack',
            getContent: () => ({ values: { ...values } }),
            setContent(c) {
                load(c);
                for (const [id, el] of controls) {
                    el.value = values[id] ?? '';
                }
            },
            setReadOnly(flag) {
                state.readOnly = !!flag;
                for (const el of controls.values()) {
                    el.disabled = state.readOnly;
                }
                root.classList.toggle('is-readonly', state.readOnly);
            },
            focus: () => controls.values().next().value?.focus(),
            destroy: () => root.remove(),
        };
    },
};

registerAnswerWidget('text', TextWidget);
registerAnswerWidget('choice', ChoiceWidget);
registerAnswerWidget('matching', MatchingWidget);
registerAnswerWidget('ordering', OrderingWidget);
registerAnswerWidget('gaps', GapsWidget);
