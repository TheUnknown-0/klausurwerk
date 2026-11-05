/**
 * Relationenschema (Antworttyp "relational"). Format: docs/DATENFORMATE.md, 2.15.
 * Tabellen-Editor mit Spalten, Primärschlüssel und Fremdschlüssel (Auswahl der Zielspalte).
 * Darstellung: Tabelle(_id_, name, ↑kurs_id), Primärschlüssel unterstrichen, Fremdschlüssel mit Pfeil.
 */
import { h, icon } from '../../lib/dom.js';
import { registerAnswerWidget } from '../../lib/widgets.js';
import { asObject, clone } from './core/model-util.js';
import {
    fkTargets, freeId, normalizeSchema, renameReferences, renameTableReferences, schemaSegments,
} from './core/tables-model.js';
import { bindUndoKeys, createTracker, textLinesPng } from './core/simple.js';
import { smallButton, withIcon } from './core/form.js';
import { focusLost, isControlTarget, isDeleteKey, isTypingTarget } from '../delete-keys.js';

let counter = 0;

/** Zeilen für die PNG-Vorschau. */
export function schemaLines(model) {
    return model.tables.map((t) => ({
        segments: schemaSegments(t).map((seg) => ({ text: seg.text, underline: !!seg.pk, bold: !!seg.bold })),
    }));
}

/** Darstellung einer Tabelle als DOM (Primärschlüssel unterstrichen). */
export function renderSchema(table) {
    const el = h('span', { class: 'rel-schema' });
    for (const seg of schemaSegments(table)) {
        if (seg.pk) {
            el.append(h('u', { class: 'rel-pk' }, seg.text));
        } else if (seg.fk) {
            el.append(h('span', { class: 'rel-fk', 'aria-label': 'Fremdschlüssel' }, seg.text));
        } else if (seg.bold) {
            el.append(h('strong', {}, seg.text));
        } else {
            el.append(seg.text);
        }
    }

    return el;
}

export const RelationalWidget = {
    mount(container, ctx) {
        let readOnly = !!ctx.readOnly;
        let model = normalizeSchema(ctx.content);
        const tracker = createTracker(ctx, () => clone(model), model, () => textLinesPng(schemaLines(model), { width: 640, size: 16, title: null }));
        const uid = ++counter;
        const root = h('div', { class: 'w-diagram w-relational', dataset: { mode: ctx.mode ?? 'exam', type: 'relational' } });
        const status = h('div', { class: 'sr-only', role: 'status', 'aria-live': 'polite' });
        const list = h('div', { class: 'rel-tables' });
        const preview = h('div', { class: 'rel-preview', 'aria-label': 'Darstellung als Relationenschema' });
        const previewTitle = h('h3', { class: 'dg-panel-title' }, 'Darstellung');
        const empty = h('p', { class: 'dg-panel-hint' }, 'Noch keine Tabelle. Lege mit „+ Tabelle“ die erste an.');
        let fkSelects = []; // {tableId, index, select}
        // Auswahl: eine Tabelle oder eine Spalte (Klick auf die Zeile, Entf/Rücktaste oder "Löschen" wirkt darauf)
        let selection = null; // {kind: 't', id} | {kind: 'c', id, index}
        const rowEls = new Map(); // Schlüssel -> Element
        let nameEls = []; // Namensfelder der Tabellen
        const selKey = (sel) => (sel.kind === 't' ? `th:${sel.id}` : `cr:${sel.id}:${sel.index}`);

        const tableById = (m, id) => m.tables.find((t) => t.id === id);

        /** Ändert das Modell, hält es fest und aktualisiert abgeleitete Teile. */
        const mutate = (fn, { key = null, rebuild = false } = {}) => {
            if (readOnly) {
                return;
            }
            const next = clone(model);
            fn(next);
            model = next;
            tracker.record(model, key);
            if (rebuild) {
                build();
            } else {
                derive();
            }
        };

        const derive = () => {
            preview.replaceChildren(...model.tables.map((t) => h('div', { class: 'rel-line' }, renderSchema(t))));
            previewTitle.hidden = model.tables.length === 0;
            empty.hidden = model.tables.length > 0;
            for (const { tableId, index, select } of fkSelects) {
                const t = tableById(model, tableId);
                const col = t?.columns[index];
                if (!t || !col) {
                    continue;
                }
                const targets = fkTargets(model, tableId);
                if (col.fk && !targets.includes(col.fk)) {
                    targets.push(col.fk);
                }
                select.replaceChildren(
                    h('option', { value: '' }, 'kein Fremdschlüssel'),
                    ...targets.map((tg) => h('option', { value: tg }, `↑ ${tg}`)),
                );
                select.value = col.fk ?? '';
            }
            undoBtn.disabled = readOnly || !tracker.history.canUndo;
            redoBtn.disabled = readOnly || !tracker.history.canRedo;
        };

        const paintSelection = () => {
            if (selection && !rowEls.has(selKey(selection))) {
                selection = null;
            }
            const current = selection ? selKey(selection) : null;
            for (const [k, el] of rowEls) {
                el.classList.toggle('is-selected', k === current);
                if (k === current) {
                    el.setAttribute('aria-current', 'true');
                } else {
                    el.removeAttribute('aria-current');
                }
            }
            deleteBtn.disabled = readOnly || selection === null;
        };

        const selectRow = (sel, { focus = false } = {}) => {
            selection = sel;
            paintSelection();
            if (focus && sel) {
                rowEls.get(selKey(sel))?.focus?.();
            }
        };

        /** Griff: ein Klick darauf wählt die Zeile aus (die Felder daneben bleiben Eingabefelder). */
        const handle = () => (readOnly
            ? h('span', { class: 'rel-handle is-off', 'aria-hidden': 'true' })
            : h('span', { class: 'rel-handle', title: 'Zeile auswählen, dann Entf, Rücktaste oder Löschen' }, icon('move')));

        /** Macht Tabellenkopf oder Spalte zur auswählbaren, fokussierbaren Zeile. */
        const makeRow = (el, sel, name) => {
            rowEls.set(selKey(sel), el);
            if (readOnly) {
                return;
            }
            el.setAttribute('tabindex', '0');
            el.setAttribute('role', 'group');
            el.setAttribute('aria-label', name);
            el.dataset.rel = selKey(sel);
            el.addEventListener('click', (e) => {
                if (!isControlTarget(e.target, el)) {
                    selectRow(sel, { focus: true });
                }
            });
            el.addEventListener('focusin', () => {
                if (!selection || selKey(selection) !== selKey(sel)) {
                    selectRow(sel);
                }
            });
        };

        /** Löscht die Auswahl (Tabelle oder Spalte) mit Rückgängig und wählt den Nachbarn aus. */
        const removeSelected = (viaKey = false) => {
            const sel = selection;
            if (readOnly || !sel) {
                return false;
            }
            const ti = model.tables.findIndex((t) => t.id === sel.id);
            if (ti < 0) {
                return false;
            }
            let next = null;
            if (sel.kind === 't') {
                const rest = model.tables.filter((t) => t.id !== sel.id);
                const neighbour = rest[Math.min(ti, rest.length - 1)];
                next = neighbour ? { kind: 't', id: neighbour.id } : null;
                selection = next;
                mutate((m) => { m.tables.splice(ti, 1); }, { rebuild: true });
            } else {
                const count = model.tables[ti].columns.length;
                if (sel.index >= count) {
                    return false;
                }
                next = count > 1 ? { kind: 'c', id: sel.id, index: Math.min(sel.index, count - 2) } : { kind: 't', id: sel.id };
                selection = next;
                mutate((m) => { m.tables[ti].columns.splice(sel.index, 1); }, { rebuild: true });
            }
            selectRow(next, { focus: viaKey || focusLost() });
            if (!viaKey && next === null) {
                addBtn.focus?.();
            }
            status.textContent = sel.kind === 't' ? 'Tabelle gelöscht.' : 'Spalte gelöscht.';

            return true;
        };

        const build = () => {
            fkSelects = [];
            rowEls.clear();
            nameEls = [];
            const focusId = document.activeElement?.dataset?.rel ?? null;
            list.replaceChildren();
            model.tables.forEach((t, ti) => {
                const nameId = `rel-${uid}-t${ti}`;
                const nameInput = h('input', {
                    id: nameId, class: 'input dg-input rel-name', type: 'text', maxlength: 60, value: t.name, placeholder: 'Tabellenname',
                    'data-rel': `t:${t.id}`, disabled: readOnly,
                });
                nameEls.push(nameInput);
                const head = h('div', { class: 'rel-head' },
                    handle(),
                    h('label', { for: nameId, class: 'rel-label' }, 'Tabelle'),
                    nameInput,
                    readOnly ? null : h('div', { class: 'rel-actions' },
                        smallButton('↑', () => mutate((m) => { [m.tables[ti - 1], m.tables[ti]] = [m.tables[ti], m.tables[ti - 1]]; }, { rebuild: true }), { 'aria-label': `Tabelle ${t.name || ti + 1} nach oben`, disabled: ti === 0 }),
                        smallButton('↓', () => mutate((m) => { [m.tables[ti + 1], m.tables[ti]] = [m.tables[ti], m.tables[ti + 1]]; }, { rebuild: true }), { 'aria-label': `Tabelle ${t.name || ti + 1} nach unten`, disabled: ti === model.tables.length - 1 }),
                        smallButton(withIcon('x', 'Tabelle'), () => mutate((m) => { m.tables.splice(ti, 1); }, { rebuild: true }), { 'aria-label': `Tabelle ${t.name || ti + 1} löschen` })));
                makeRow(head, { kind: 't', id: t.id }, `Tabelle ${t.name || ti + 1}`);
                nameInput.addEventListener('input', () => {
                    const old = tableById(model, t.id)?.name ?? '';
                    mutate((m) => {
                        const tbl = tableById(m, t.id);
                        const next = nameInput.value.replace(/\s+/g, ' ').slice(0, 60);
                        renameTableReferences(m, old, next.trim());
                        tbl.name = next.trim();
                    }, { key: `tn:${t.id}` });
                });

                const cols = h('div', { class: 'rel-cols' });
                t.columns.forEach((c, ci) => {
                    const cid = `rel-${uid}-t${ti}c${ci}`;
                    const input = h('input', {
                        id: cid, class: 'input dg-input', type: 'text', maxlength: 60, value: c.name, placeholder: 'Spaltenname', 'aria-label': `Spalte ${ci + 1} von ${t.name || 'Tabelle'}`,
                        'data-rel': `c:${t.id}:${ci}`, disabled: readOnly,
                    });
                    input.addEventListener('input', () => {
                        const tbl = tableById(model, t.id);
                        const old = `${tbl?.name}.${tbl?.columns[ci]?.name}`;
                        mutate((m) => {
                            const mt = tableById(m, t.id);
                            const next = input.value.replace(/\s+/g, ' ').slice(0, 60).trim();
                            if (mt.name && mt.columns[ci].name) {
                                renameReferences(m, old, `${mt.name}.${next}`);
                            }
                            mt.columns[ci].name = next;
                        }, { key: `cn:${t.id}:${ci}` });
                    });
                    const pk = h('input', { type: 'checkbox', 'aria-label': `${c.name || `Spalte ${ci + 1}`} ist Primärschlüssel`, disabled: readOnly });
                    pk.checked = c.pk;
                    pk.addEventListener('change', () => mutate((m) => { tableById(m, t.id).columns[ci].pk = pk.checked; }));
                    const fk = h('select', { class: 'input dg-input', 'aria-label': `Fremdschlüssel für ${c.name || `Spalte ${ci + 1}`}`, disabled: readOnly });
                    fk.addEventListener('change', () => mutate((m) => { tableById(m, t.id).columns[ci].fk = fk.value === '' ? null : fk.value; }));
                    fkSelects.push({ tableId: t.id, index: ci, select: fk });
                    const colRow = h('div', { class: 'rel-col' },
                        handle(),
                        input,
                        h('label', { class: 'dg-check', title: 'Primärschlüssel (wird unterstrichen)' }, pk, ' PK'),
                        fk,
                        readOnly ? null : h('div', { class: 'rel-actions' },
                            smallButton('↑', () => mutate((m) => { const cs = tableById(m, t.id).columns; [cs[ci - 1], cs[ci]] = [cs[ci], cs[ci - 1]]; }, { rebuild: true }), { 'aria-label': 'Spalte nach oben', disabled: ci === 0 }),
                            smallButton('↓', () => mutate((m) => { const cs = tableById(m, t.id).columns; [cs[ci + 1], cs[ci]] = [cs[ci], cs[ci + 1]]; }, { rebuild: true }), { 'aria-label': 'Spalte nach unten', disabled: ci === t.columns.length - 1 }),
                            smallButton(icon('x'), () => mutate((m) => { tableById(m, t.id).columns.splice(ci, 1); }, { rebuild: true }), { 'aria-label': `Spalte ${c.name || ci + 1} entfernen` })));
                    makeRow(colRow, { kind: 'c', id: t.id, index: ci }, `Spalte ${c.name || ci + 1} von ${t.name || 'Tabelle'}`);
                    cols.append(colRow);
                });
                const card = h('section', { class: 'rel-card', 'aria-label': `Tabelle ${t.name || ti + 1}` }, head, cols,
                    readOnly ? null : smallButton('+ Spalte', () => mutate((m) => {
                        const cs = tableById(m, t.id).columns;
                        if (cs.length < 40) {
                            cs.push({ name: '', pk: false, fk: null });
                        }
                    }, { rebuild: true })));
                list.append(card);
            });
            derive();
            paintSelection();
            if (focusId) {
                for (const el of list.querySelectorAll?.('[data-rel]') ?? []) {
                    if (el.dataset.rel === focusId) {
                        el.focus();
                    }
                }
            }
        };

        const apply = (next) => {
            model = next;
            build();
        };
        const undo = () => {
            const m = readOnly ? null : tracker.history.undo();
            if (m) {
                apply(m);
                tracker.touch();
            }
        };
        const redo = () => {
            const m = readOnly ? null : tracker.history.redo();
            if (m) {
                apply(m);
                tracker.touch();
            }
        };
        const undoBtn = smallButton(withIcon('undo-2', 'Rückgängig'), undo, { title: 'Rückgängig (Strg+Z)' });
        const redoBtn = smallButton(withIcon('redo-2', 'Wiederholen'), redo, { title: 'Wiederholen (Strg+Y)' });
        const deleteBtn = smallButton(withIcon('trash-2', 'Löschen'), () => removeSelected(false), { title: 'Gewählte Tabelle oder Spalte löschen (Entf oder Rücktaste)', disabled: true, 'aria-label': 'Gewählte Tabelle oder Spalte löschen' });
        const addBtn = smallButton('+ Tabelle', () => {
            mutate((m) => {
                if (m.tables.length < 30) {
                    m.tables.push({ id: freeId('t', m.tables.map((t) => t.id)), name: '', columns: [{ name: '', pk: true, fk: null }] });
                }
            }, { rebuild: true });
            nameEls[nameEls.length - 1]?.focus?.();
        });
        const bar = h('div', { class: 'dg-toolbar w-rel-bar', role: 'toolbar', 'aria-label': 'Werkzeuge des Relationenschemas' }, addBtn, deleteBtn, undoBtn, redoBtn);
        bar.hidden = readOnly;
        const hint = h('p', { class: 'dg-hint' }, 'Lege Tabellen und Spalten an. Setze bei Primärschlüsseln PK (sie werden unterstrichen) und wähle bei Fremdschlüsseln die Zielspalte (sie bekommen einen Pfeil). Klicke auf eine Tabelle oder Spalte (Rand oder Kopf), dann löschen Entf, Rücktaste oder der Knopf „Löschen“ sie.');
        hint.hidden = readOnly;

        root.append(bar, hint, empty, list, previewTitle, preview, status);
        container.append(root);
        bindUndoKeys(root, undo, redo, { inInputs: false });
        root.addEventListener('keydown', (e) => {
            // Entf/Rücktaste nur auf der gewählten Zeile selbst, nie in Feldern (dort löschen sie Zeichen)
            if (readOnly || !isDeleteKey(e) || isTypingTarget(e.target) || !selection) {
                return;
            }
            if (e.target?.dataset?.rel === selKey(selection)) {
                e.preventDefault();
                removeSelected(true);
            }
        });
        build();

        return {
            layout: 'stack',
            getContent: () => clone(model),
            setContent(content) {
                model = normalizeSchema(content);
                tracker.history.reset(model);
                build();
            },
            setReadOnly(flag) {
                readOnly = !!flag;
                tracker.setReadOnly(readOnly);
                bar.hidden = readOnly;
                hint.hidden = readOnly;
                root.dataset.readonly = readOnly ? '1' : '0';
                build();
            },
            getPreviewPng: () => (model.tables.length === 0 ? Promise.resolve(null) : textLinesPng(schemaLines(model), { width: 640, size: 16 })),
            focus: () => (nameEls[0] ? nameEls[0].focus() : addBtn.focus()),
            destroy() {
                tracker.destroy();
                root.remove();
            },
        };
    },
};

registerAnswerWidget('relational', RelationalWidget);

void asObject;
