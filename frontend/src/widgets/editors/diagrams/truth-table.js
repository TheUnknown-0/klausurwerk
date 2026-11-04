/**
 * Aufgaben-Editor für den Antworttyp "truth_table": Eingänge/Ausgänge, Musterlösung (Zellen mit
 * Klick auf 0/1 setzen) und vorgegebene Zellen (Modus "Vorgaben markieren").
 */
import { h } from '../../../lib/dom.js';
import { registerTaskEditor } from '../../../lib/widgets.js';
import { labeled } from '../../../task-editor/ui.js';
import { rowKeys } from '../../diagrams/core/logic-sim.js';
import { checkNames, namesField, section } from './common.js';

registerTaskEditor('truth_table', {
    mount(container, ctx) {
        const locked = !!ctx.locked;
        const c = ctx.config ?? {};
        const state = {
            inputs: Array.isArray(c.inputs) ? c.inputs.slice() : ['A', 'B'],
            outputs: Array.isArray(c.outputs) ? c.outputs.slice() : ['X'],
            mode: 'solution',
        };
        // Lösung und Vorgaben als Zellwerte "0"/"1"; prefilled = Menge "key|out"
        const values = {};
        const prefilled = new Set();
        for (const [k, row] of Object.entries(ctx.solution?.rows ?? {})) {
            for (const [o, v] of Object.entries(row)) {
                values[`${k}|${o}`] = String(v);
            }
        }
        for (const [k, row] of Object.entries(c.prefilled ?? {})) {
            for (const [o, v] of Object.entries(row)) {
                prefilled.add(`${k}|${o}`);
                values[`${k}|${o}`] ??= String(v);
            }
        }
        const changed = () => ctx.onChange();
        const inputsField = namesField('Eingänge (durch Komma getrennt)', state.inputs, () => onNames(), { locked, hint: 'z. B. A, B (höchstens 6). Die Tabelle enthält alle Kombinationen.' });
        const outputsField = namesField('Ausgänge', state.outputs, () => onNames(), { locked, hint: 'z. B. X, Y (höchstens 8)' });
        const tableHost = h('div', { class: 'dg-table-wrap' });
        const hint = h('p', { class: 'text-soft te-intro', role: 'status', 'aria-live': 'polite' });
        const modeSel = h('div', { class: 'cluster', role: 'radiogroup', 'aria-label': 'Was ein Klick auf eine Zelle bewirkt' },
            ...[['solution', 'Lösungswert setzen (leer, 0, 1)'], ['prefill', 'Zelle als vorgegeben markieren']].map(([v, label]) => {
                const input = h('input', { type: 'radio', name: 'tt-mode', value: v, disabled: locked && v === 'prefill' });
                input.checked = state.mode === v;
                input.addEventListener('change', () => { state.mode = v; renderTable(); });
                return h('label', { class: 'checkbox-row' }, input, ' ', label);
            }));

        const valid = () => checkNames(state.inputs, 'Eingänge').length === 0 && checkNames(state.outputs, 'Ausgänge', { max: 8 }).length === 0
            && !state.inputs.some((n) => state.outputs.includes(n));

        function renderTable() {
            tableHost.replaceChildren();
            hint.textContent = state.mode === 'solution'
                ? 'Klicke eine Zelle, um zwischen leer, 0 und 1 zu wechseln. Alle Zellen ausfüllen, damit die Antworten automatisch geprüft werden können.'
                : 'Klicke eine Zelle, um sie als vorgegeben zu markieren. Vorgegebene Zellen zeigen den Schüler:innen den Wert der Lösung und sind fest.';
            if (!valid()) {
                tableHost.append(h('p', { class: 'te-error' }, 'Prüfe die Namen der Eingänge und Ausgänge.'));
                return;
            }
            const head = h('tr', {}, ...state.inputs.map((n) => h('th', { scope: 'col' }, n)), ...state.outputs.map((n) => h('th', { scope: 'col', class: 'is-out' }, n)));
            const rows = rowKeys(state.inputs.length).map((k) => h('tr', {}, ...[...k].map((b) => h('td', {}, b)), ...state.outputs.map((o) => {
                const id = `${k}|${o}`;
                const btn = h('button', { type: 'button', class: 'w-truth-cell' });
                const paint = () => {
                    const v = values[id] ?? '';
                    btn.textContent = v === '' ? '·' : v;
                    btn.classList.toggle('is-fixed', prefilled.has(id));
                    btn.classList.toggle('is-empty', v === '');
                    btn.setAttribute('aria-label', `Zeile ${k}, Ausgang ${o}: ${v === '' ? 'leer' : v}${prefilled.has(id) ? ', vorgegeben' : ''}`);
                };
                paint();
                btn.addEventListener('click', () => {
                    if (state.mode === 'prefill') {
                        if (prefilled.has(id)) {
                            prefilled.delete(id);
                        } else if ((values[id] ?? '') !== '') {
                            prefilled.add(id);
                        } else {
                            hint.textContent = 'Setze zuerst einen Lösungswert, dann kannst du die Zelle vorgeben.';
                            return;
                        }
                    } else {
                        const cur = values[id] ?? '';
                        const next = cur === '' ? '0' : cur === '0' ? '1' : '';
                        if (next === '') {
                            delete values[id];
                            prefilled.delete(id);
                        } else {
                            values[id] = next;
                        }
                    }
                    paint();
                    changed();
                });
                return h('td', { class: 'is-out' }, btn);
            })));
            tableHost.append(h('table', { class: 'dg-table', 'aria-label': 'Musterlösung der Wahrheitstabelle' }, h('thead', {}, head), h('tbody', {}, ...rows)));
        }

        function onNames() {
            state.inputs = inputsField.get();
            state.outputs = outputsField.get();
            // Werte zu verschwundenen Zeilen/Ausgängen verwerfen
            if (valid()) {
                const keys = new Set(rowKeys(state.inputs.length));
                for (const id of Object.keys(values)) {
                    const [k, o] = id.split('|');
                    if (!keys.has(k) || !state.outputs.includes(o)) {
                        delete values[id];
                        prefilled.delete(id);
                    }
                }
            }
            renderTable();
            changed();
        }

        renderTable();
        const root = h('div', { class: 'te-type-box' },
            h('div', { class: 'te-grid' }, inputsField.field, outputsField.field),
            section('Musterlösung und Vorgaben', null, labeled('Klick-Modus', modeSel), hint, tableHost));
        container.append(root);

        const total = () => rowKeys(state.inputs.length).length * state.outputs.length;
        const filled = () => Object.keys(values).length;
        const build = () => {
            const rows = {};
            const pre = {};
            for (const [id, v] of Object.entries(values)) {
                const [k, o] = id.split('|');
                (rows[k] ??= {})[o] = v;
                if (prefilled.has(id)) {
                    (pre[k] ??= {})[o] = v;
                }
            }

            return { rows, pre };
        };

        return {
            getValue() {
                const { rows, pre } = build();

                return {
                    config: { inputs: state.inputs, outputs: state.outputs, prefilled: pre },
                    solution: { rows: filled() === total() ? rows : {} },
                };
            },
            validate() {
                const errors = [...checkNames(state.inputs, 'Eingänge'), ...checkNames(state.outputs, 'Ausgänge', { max: 8 })];
                if (state.inputs.some((n) => state.outputs.includes(n))) {
                    errors.push('Ein Name darf nicht gleichzeitig Eingang und Ausgang sein.');
                }
                if (errors.length === 0 && filled() > 0 && filled() < total()) {
                    errors.push(`Die Musterlösung ist unvollständig (${filled()} von ${total()} Zellen). Fülle alle Zellen aus oder lass alle leer.`);
                }

                return errors;
            },
            destroy: () => root.remove(),
        };
    },
});
