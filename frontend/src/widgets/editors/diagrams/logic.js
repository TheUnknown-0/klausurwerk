/**
 * Aufgaben-Editor für den Antworttyp "logic": Ein- und Ausgänge, erlaubte Gatter, Symbolart,
 * Musterlösung als Wahrheitstabelle (direkt oder aus einer gezeichneten Musterschaltung berechnet).
 */
import { h } from '../../../lib/dom.js';
import { registerTaskEditor } from '../../../lib/widgets.js';
import { button, checkbox, labeled, select } from '../../../task-editor/ui.js';
import { GATES, compareTables, rowKeys, truthTable } from '../../diagrams/core/logic-sim.js';
import { checkNames, embedWidget, namesField, section } from './common.js';

const GATE_NAMES = { AND: 'AND', OR: 'OR', NOT: 'NOT', NAND: 'NAND', NOR: 'NOR', XOR: 'XOR', XNOR: 'XNOR' };

registerTaskEditor('logic', {
    mount(container, ctx) {
        const locked = !!ctx.locked;
        const c = ctx.config ?? {};
        const state = {
            inputs: Array.isArray(c.inputs) ? c.inputs.slice() : ['A', 'B'],
            outputs: Array.isArray(c.outputs) ? c.outputs.slice() : ['X'],
            allowed: Array.isArray(c.allowed_gates) ? c.allowed_gates.slice() : GATES.slice(),
            symbols: c.symbols === 'ansi' ? 'ansi' : 'din',
            show_table: !!c.show_table,
        };
        const sol = ctx.solution ?? {};
        let table = {};
        for (const [k, row] of Object.entries(sol.truth_table ?? {})) {
            table[k] = { ...row };
        }
        const configOf = () => ({
            inputs: state.inputs, outputs: state.outputs, allowed_gates: state.allowed, symbols: state.symbols, show_table: state.show_table,
        });
        const widgetConfig = () => ({ ...configOf(), show_table: true });
        const changed = () => ctx.onChange();

        // ---------- Konfiguration ----------
        const inputsField = namesField('Eingänge (durch Komma getrennt)', state.inputs, () => onNames(), { locked, hint: 'z. B. A, B, C (höchstens 6). Erster Eingang = höchstwertiges Bit in der Tabelle.' });
        const outputsField = namesField('Ausgänge', state.outputs, () => onNames(), { locked, hint: 'z. B. X (höchstens 4)' });
        const gateBoxes = GATES.map((g) => checkbox(GATE_NAMES[g], state.allowed.includes(g), (v) => {
            state.allowed = GATES.filter((x) => (x === g ? v : state.allowed.includes(x)));
            embed.setConfig(widgetConfig());
            changed();
        }, { disabled: locked }));
        const symbolSel = labeled('Symbole', select([['din', 'DIN EN 60617 (Rechtecke mit &, ≥1, =1)'], ['ansi', 'US-Symbole (ANSI)']], state.symbols, (v) => {
            state.symbols = v;
            embed.setConfig(widgetConfig());
            changed();
        }, { disabled: locked }));
        const showTable = checkbox('Schüler:innen sehen die Wahrheitstabelle ihrer eigenen Schaltung', state.show_table, (v) => { state.show_table = v; changed(); }, { disabled: locked });

        // ---------- Musterschaltung ----------
        const host = h('div', { class: 'te-diagram-solution' });
        const embed = embedWidget(host, 'logic', { config: widgetConfig(), content: sol.circuit ?? {}, onChange: () => { updateStatus(); changed(); } });
        const status = h('div', { class: 'text-soft te-status-line', role: 'status', 'aria-live': 'polite' });
        const tableHost = h('div', { class: 'dg-table-wrap' });

        const keyList = () => rowKeys(state.inputs.length);
        const normalizeTable = () => {
            const next = {};
            for (const k of keyList()) {
                next[k] = {};
                for (const o of state.outputs) {
                    next[k][o] = Number(table[k]?.[o] ?? 0) === 1 ? 1 : 0;
                }
            }
            table = next;
        };
        normalizeTable();

        function renderTable() {
            tableHost.replaceChildren();
            if (state.inputs.length === 0 || state.outputs.length === 0) {
                return;
            }
            const head = h('tr', {}, ...state.inputs.map((n) => h('th', { scope: 'col' }, n)), ...state.outputs.map((n) => h('th', { scope: 'col', class: 'is-out' }, n)));
            const rows = keyList().map((k) => h('tr', {}, ...[...k].map((b) => h('td', {}, b)), ...state.outputs.map((o) => {
                const btn = h('button', {
                    type: 'button', class: 'w-truth-cell', 'aria-label': `Zeile ${k}, Ausgang ${o}: ${table[k][o]}`,
                }, String(table[k][o]));
                btn.addEventListener('click', () => {
                    table[k][o] = table[k][o] === 1 ? 0 : 1;
                    btn.textContent = String(table[k][o]);
                    btn.setAttribute('aria-label', `Zeile ${k}, Ausgang ${o}: ${table[k][o]}`);
                    updateStatus();
                    changed();
                });
                return h('td', { class: 'is-out' }, btn);
            })));
            tableHost.append(h('table', { class: 'dg-table', 'aria-label': 'Wahrheitstabelle der Musterlösung' }, h('thead', {}, head), h('tbody', {}, ...rows)));
        }

        const circuit = () => embed.getContent();
        const hasCircuit = () => {
            const cc = circuit();

            return cc.wires.length > 0 || cc.components.some((x) => x.type !== 'INPUT' && x.type !== 'OUTPUT');
        };
        const computed = () => truthTable(circuit(), state.inputs, state.outputs);

        function updateStatus() {
            if (!hasCircuit()) {
                status.textContent = 'Keine Musterschaltung gezeichnet: Die Wahrheitstabelle gilt direkt.';
                return;
            }
            const calc = computed();
            if (calc.errors.length > 0) {
                status.textContent = calc.errors[0];
                return;
            }
            const same = compareTables(table, calc.table);
            status.textContent = same === 1
                ? 'Die Musterschaltung passt zur Wahrheitstabelle.'
                : `Die Musterschaltung stimmt nur in ${Math.round(same * 100)} % der Zellen mit der Tabelle überein. Mit „Tabelle aus Schaltung berechnen“ übernimmst du die Schaltung.`;
        }

        const fromCircuit = button('Tabelle aus Schaltung berechnen', () => {
            if (!hasCircuit()) {
                status.textContent = 'Zeichne zuerst eine Musterschaltung.';
                return;
            }
            const calc = computed();
            if (calc.errors.length > 0) {
                status.textContent = calc.errors[0];
                return;
            }
            table = calc.table;
            normalizeTable();
            renderTable();
            updateStatus();
            changed();
        });

        function onNames() {
            state.inputs = inputsField.get();
            state.outputs = outputsField.get();
            const ok = checkNames(state.inputs, 'Eingänge').length === 0 && checkNames(state.outputs, 'Ausgänge', { max: 4 }).length === 0
                && !state.inputs.some((n) => state.outputs.includes(n));
            if (ok) {
                normalizeTable();
                embed.setConfig(widgetConfig());
                renderTable();
                updateStatus();
            }
            changed();
        }

        renderTable();
        updateStatus();
        const root = h('div', { class: 'te-type-box' },
            h('div', { class: 'te-grid' }, inputsField.field, outputsField.field),
            h('fieldset', { class: 'field', disabled: locked }, h('legend', { class: 'label-like' }, 'Erlaubte Gatter'), h('div', { class: 'cluster' }, ...gateBoxes)),
            h('div', { class: 'te-grid' }, symbolSel),
            showTable,
            section('Musterlösung: Wahrheitstabelle', 'Die Antwort wird geprüft, indem die Schaltung der Schüler:innen für alle Eingangskombinationen simuliert und mit dieser Tabelle verglichen wird. Klicke auf die Zellen, um 0 und 1 zu wechseln.',
                tableHost),
            section('Musterschaltung (optional)', 'Zeichne die Schaltung, wenn du sie bei der Korrektur anzeigen oder die Tabelle daraus berechnen möchtest.',
                h('div', { class: 'cluster' }, fromCircuit), status, host));
        container.append(root);

        return {
            getValue() {
                normalizeTable();
                const solution = { truth_table: table };
                if (hasCircuit()) {
                    solution.circuit = circuit();
                }

                return { config: configOf(), solution };
            },
            validate() {
                const errors = [...checkNames(state.inputs, 'Eingänge'), ...checkNames(state.outputs, 'Ausgänge', { max: 4 })];
                if (state.inputs.some((n) => state.outputs.includes(n))) {
                    errors.push('Ein Name darf nicht gleichzeitig Eingang und Ausgang sein.');
                }
                if (state.allowed.length === 0) {
                    errors.push('Erlaube mindestens ein Gatter.');
                }
                if (hasCircuit()) {
                    const calc = computed();
                    if (calc.errors.length > 0) {
                        errors.push(`Musterschaltung: ${calc.errors[0]}`);
                    }
                }

                return errors;
            },
            destroy() {
                embed.destroy();
                root.remove();
            },
        };
    },
});
