/**
 * Wahrheitstabelle ausfüllen (Antworttyp "truth_table"). Format: docs/DATENFORMATE.md, 2.12.
 * Die Eingangsspalten ergeben sich aus config.inputs; die Ausgangszellen sind per Maus
 * (Klick schaltet leer, 0, 1) oder Tastatur (0, 1, Entf, Pfeiltasten) bedienbar.
 * Vorgegebene Zellen (config.prefilled) sind fest.
 */
import { h } from '../../lib/dom.js';
import { registerAnswerWidget } from '../../lib/widgets.js';
import { asObject, clone } from './core/model-util.js';
import { isPrefilled, nextCell, normalizeRows, rowKeys } from './core/tables-model.js';
import { bindUndoKeys, createTracker } from './core/simple.js';
import { svgToPng } from './core/png.js';
import { s, text } from './core/svg.js';
import { smallButton } from './core/form.js';

const SHOW = { '': '·', 0: '0', 1: '1', '0': '0', '1': '1' };

export function truthTableCells(config) {
    const inputs = Array.isArray(config?.inputs) ? config.inputs : [];
    const outputs = Array.isArray(config?.outputs) ? config.outputs : [];

    return { inputs, outputs, keys: rowKeys(inputs.length) };
}

/** PNG-Vorschau: gezeichnetes Raster mit allen Zellen. */
export function tablePng(config, content) {
    const { inputs, outputs, keys } = truthTableCells(config);
    const names = [...inputs, ...outputs];
    const cw = 48;
    const ch = 30;
    const width = names.length * cw + 4;
    const height = (keys.length + 1) * ch + 4;
    const svg = s('svg', { xmlns: 'http://www.w3.org/2000/svg', width, height, viewBox: `0 0 ${width} ${height}` }, s('rect', { x: 0, y: 0, width, height, fill: '#ffffff' }));
    const cell = (col, row, str, { bold = false, fill = '#ffffff' } = {}) => {
        svg.append(s('rect', { x: 2 + col * cw, y: 2 + row * ch, width: cw, height: ch, fill, stroke: '#111827', 'stroke-width': 1.2 }));
        svg.append(text(str, 2 + col * cw + cw / 2, 2 + row * ch + ch / 2, { size: 14, weight: bold ? 'bold' : undefined }));
    };
    names.forEach((n, i) => cell(i, 0, n, { bold: true, fill: i < inputs.length ? '#f3f4f6' : '#e0e7ff' }));
    keys.forEach((key, r) => {
        [...key].forEach((bit, c) => cell(c, r + 1, bit, { fill: '#f3f4f6' }));
        outputs.forEach((o, c) => cell(inputs.length + c, r + 1, content.rows?.[key]?.[o] ?? ''));
    });

    return svgToPng(svg, width, height);
}

export const TruthTableWidget = {
    mount(container, ctx) {
        const config = asObject(ctx.task?.config);
        const { inputs, outputs, keys } = truthTableCells(config);
        let readOnly = !!ctx.readOnly;
        let model = normalizeRows(ctx.content, config);
        const tracker = createTracker(ctx, () => clone(model), model, () => tablePng(config, model));
        const cells = new Map(); // "key|out" -> Button
        const rowEls = [];

        const root = h('div', { class: 'w-diagram w-truth', dataset: { mode: ctx.mode ?? 'exam', type: 'truth_table' } });
        const status = h('div', { class: 'sr-only', role: 'status', 'aria-live': 'polite' });
        const label = (key, out) => `Zeile ${inputs.map((n, i) => `${n}=${key[i]}`).join(', ')}, Ausgang ${out}`;
        const valueText = (v) => (v === '0' ? 'null' : v === '1' ? 'eins' : 'leer');

        const refresh = () => {
            for (const [id, btn] of cells) {
                const [key, out] = id.split('|');
                const v = model.rows[key]?.[out] ?? '';
                const fixed = isPrefilled(config, key, out);
                btn.textContent = SHOW[v] ?? '·';
                btn.classList.toggle('is-fixed', fixed);
                btn.classList.toggle('is-empty', v === '');
                btn.setAttribute('aria-label', `${label(key, out)}: ${valueText(v)}${fixed ? ', vorgegeben' : ''}`);
                btn.setAttribute('aria-readonly', fixed || readOnly ? 'true' : 'false');
            }
            undoBtn.disabled = readOnly || !tracker.history.canUndo;
            redoBtn.disabled = readOnly || !tracker.history.canRedo;
            clearBtn.disabled = readOnly;
        };

        const setCell = (key, out, value, historyKey = null) => {
            if (readOnly || isPrefilled(config, key, out)) {
                return;
            }
            const next = clone(model);
            next.rows[key] ??= {};
            if (value === '') {
                delete next.rows[key][out];
                if (Object.keys(next.rows[key]).length === 0) {
                    delete next.rows[key];
                }
            } else {
                next.rows[key][out] = value;
            }
            model = next;
            tracker.record(model, historyKey);
            refresh();
        };

        const move = (key, out, dRow, dCol) => {
            const r = keys.indexOf(key) + dRow;
            const c = outputs.indexOf(out) + dCol;
            if (r < 0 || r >= keys.length || c < 0 || c >= outputs.length) {
                return;
            }
            cells.get(`${keys[r]}|${outputs[c]}`)?.focus();
        };

        const table = h('table', { class: 'dg-table w-truth-table', 'aria-label': 'Wahrheitstabelle. Mit den Tasten 0 und 1 füllst du die Ausgangsspalten aus, Pfeiltasten wechseln die Zelle.' },
            h('thead', {}, h('tr', {},
                ...inputs.map((n) => h('th', { scope: 'col', class: 'is-in' }, n)),
                ...outputs.map((n) => h('th', { scope: 'col', class: 'is-out' }, n)))),
            h('tbody', {}, ...keys.map((key) => {
                const tr = h('tr', {}, ...[...key].map((bit) => h('td', { class: 'is-in' }, bit)),
                    ...outputs.map((out) => {
                        const btn = h('button', { type: 'button', class: 'w-truth-cell', tabindex: '0' });
                        cells.set(`${key}|${out}`, btn);
                        btn.addEventListener('click', () => {
                            const cur = model.rows[key]?.[out] ?? '';
                            setCell(key, out, nextCell(cur));
                        });
                        btn.addEventListener('keydown', (e) => {
                            const k = e.key;
                            if (k === '0' || k === '1') {
                                e.preventDefault();
                                setCell(key, out, k);
                            } else if (k === 'Delete' || k === 'Backspace') {
                                e.preventDefault();
                                setCell(key, out, '');
                            } else if (k === 'ArrowDown') {
                                e.preventDefault();
                                move(key, out, 1, 0);
                            } else if (k === 'ArrowUp') {
                                e.preventDefault();
                                move(key, out, -1, 0);
                            } else if (k === 'ArrowLeft') {
                                e.preventDefault();
                                move(key, out, 0, -1);
                            } else if (k === 'ArrowRight') {
                                e.preventDefault();
                                move(key, out, 0, 1);
                            }
                        });
                        return h('td', { class: 'is-out' }, btn);
                    }));
                rowEls.push(tr);

                return tr;
            })));

        const applyModel = (next) => {
            model = next;
            refresh();
        };
        const undo = () => {
            const m = readOnly ? null : tracker.history.undo();
            if (m) {
                applyModel(m);
                tracker.touch();
            }
        };
        const redo = () => {
            const m = readOnly ? null : tracker.history.redo();
            if (m) {
                applyModel(m);
                tracker.touch();
            }
        };
        const undoBtn = smallButton('↶ Rückgängig', undo, { title: 'Rückgängig (Strg+Z)' });
        const redoBtn = smallButton('↷ Wiederholen', redo, { title: 'Wiederholen (Strg+Y)' });
        const clearBtn = smallButton('Alle Zellen leeren', () => {
            if (readOnly) {
                return;
            }
            const next = normalizeRows({ rows: {} }, config);
            model = next;
            tracker.record(model);
            refresh();
            status.textContent = 'Alle Zellen geleert.';
        });
        const bar = h('div', { class: 'dg-toolbar w-truth-bar', role: 'toolbar', 'aria-label': 'Werkzeuge der Wahrheitstabelle' }, undoBtn, redoBtn, clearBtn);
        bar.hidden = readOnly;
        const hint = h('p', { class: 'dg-hint' }, 'Klicke eine Zelle, um zwischen leer, 0 und 1 zu wechseln. Mit der Tastatur: 0 oder 1 tippen, Entf leert, Pfeiltasten wechseln die Zelle.');
        hint.hidden = readOnly;

        root.append(bar, hint, h('div', { class: 'dg-table-wrap' }, table), status);
        container.append(root);
        bindUndoKeys(root, undo, redo);
        refresh();

        return {
            layout: 'stack',
            getContent: () => clone(model),
            setContent(content) {
                applyModel(normalizeRows(content, config));
                tracker.history.reset(model);
            },
            setReadOnly(flag) {
                readOnly = !!flag;
                tracker.setReadOnly(readOnly);
                bar.hidden = readOnly;
                hint.hidden = readOnly;
                root.dataset.readonly = readOnly ? '1' : '0';
                refresh();
            },
            getPreviewPng: () => tablePng(config, model),
            focus: () => cells.values().next().value?.focus(),
            destroy() {
                tracker.destroy();
                root.remove();
            },
        };
    },
};

registerAnswerWidget('truth_table', TruthTableWidget);
