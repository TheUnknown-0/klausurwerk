/**
 * Struktogramm nach Nassi-Shneiderman, DIN 66261 (Antworttyp "structogram").
 * Format: docs/DATENFORMATE.md, 2.9. Bausteine werden über die Palette und Einfügepunkte
 * eingefügt; Breiten und Höhen ergeben sich automatisch (core/structogram-layout.js).
 */
import { h } from '../../lib/dom.js';
import { registerAnswerWidget } from '../../lib/widgets.js';
import { boardWidget } from './core/widget.js';
import { multiline, line } from './core/model-util.js';
import { field, hintText, panel, smallButton, textArea, textInput } from './core/form.js';
import {
    BLOCK_TYPES, collectIds, emptySeq, findBlock, flatOrder, indentBlock, insertBlock, moveBlock, newBlock, normalizeStructogram,
    outdentBlock, removeBlock, textField,
} from './core/structogram-model.js';
import { layoutStructogram } from './core/structogram-layout.js';
import { INK, SOFT, line as svgLine, s, text } from './core/svg.js';

const accent = '#4f46e5';

function drawLayout(model, rc, layout) {
    const g = s('g', { class: 'dg-structo' });
    for (const p of layout.prims) {
        if (p.t === 'rect') {
            g.append(s('rect', { x: p.x, y: p.y, width: p.w, height: p.h, fill: '#ffffff', stroke: INK, 'stroke-width': 1.5 }));
        }
    }
    for (const p of layout.prims) {
        if (p.t === 'line') {
            g.append(svgLine(p.x1, p.y1, p.x2, p.y2));
        } else if (p.t === 'text') {
            if (p.placeholder && rc.exportMode) {
                continue;
            }
            g.append(text(p.text, p.x, p.y - 4.5, {
                anchor: p.anchor, size: p.small ? 11 : 13, fill: p.placeholder ? SOFT : INK, italic: p.placeholder, lineHeight: 1,
            }));
        }
    }
    rc.layer.append(g);

    // Trefferflächen je Block (Eltern vor Kindern, damit innere Blöcke zuerst getroffen werden)
    for (const [id, r] of layout.blocks) {
        const block = findBlock(model.root, id)?.block;
        const hitRect = s('rect', { x: r.x, y: r.y, width: r.w, height: r.h, fill: 'transparent', stroke: 'none', class: 'dg-block' });
        rc.mark(hitRect, id, `${BLOCK_TYPES[block?.type]?.label ?? 'Block'}: ${(block?.[textField(block ?? { type: 'statement' })] ?? '').replace(/\n/g, ' ') || 'leer'}`);
        rc.layer.append(hitRect);
    }

    // Einfügepunkte, nur wenn ein Baustein gewählt ist
    if (rc.tool?.insert && !rc.readOnly) {
        for (const slot of layout.slots) {
            const empty = slot.label === 'leer';
            const el = s('rect', {
                x: slot.x, y: slot.y, width: slot.w, height: slot.h, class: `dg-slot${empty ? ' is-empty' : ''}`,
                fill: 'rgba(79,70,229,.14)', stroke: accent, 'stroke-width': 1.5, 'stroke-dasharray': '4 3',
            });
            const where = slot.key === 'root' ? 'oberste Ebene' : `in ${slot.key.split('/').join(' ')}`;
            rc.action(el, 'slot', { key: slot.key, index: slot.index }, `Einfügepunkt ${slot.label}, ${where}`);
            rc.layer.append(el);
        }
    }
    if (model.root.children.length === 0 && !rc.exportMode) {
        rc.layer.append(text(rc.readOnly ? 'Leeres Struktogramm' : 'Wähle links einen Baustein und klicke auf den Einfügepunkt.', layout.width / 2, layout.height / 2, { size: 12, fill: SOFT }));
    }
}

function insertAt(board, key, index) {
    const type = board.tool?.insert;
    if (!type) {
        return;
    }
    let newId = null;
    board.mutate((m) => {
        const block = newBlock(type, collectIds(m.root));
        if (insertBlock(m.root, key, index, block)) {
            newId = block.id;
        }
    }, { inspector: true });
    if (newId === null) {
        board.setHint('Das Struktogramm ist zu groß (höchstens 300 Blöcke).', true);
        return;
    }
    board.setTool(null);
    board.select([newId]);
    board.focusInspector();
}

const selectedBlock = (board) => (board.sel.size === 1 ? findBlock(board.model.root, [...board.sel][0]) : null);

function moveSelected(board, kind) {
    const hit = selectedBlock(board);
    if (!hit) {
        return false;
    }
    const id = hit.block.id;
    let ok = false;
    board.mutate((m) => {
        ok = kind === 'up' ? moveBlock(m.root, id, -1) : kind === 'down' ? moveBlock(m.root, id, 1) : kind === 'in' ? indentBlock(m.root, id) : outdentBlock(m.root, id);
    }, { inspector: true });
    if (!ok) {
        board.announce('Das geht an dieser Stelle nicht.');
    }

    return ok;
}

export const structogramPlugin = {
    name: 'structogram',
    label: 'Struktogramm',
    freeLayout: false,
    normalize: (content) => normalizeStructogram(content),
    ids: (m) => flatOrder(m.root),
    bounds(m) {
        const l = layoutStructogram(m.root);

        return { x: 0, y: 0, w: l.width, h: l.height };
    },
    centerOf: () => null,
    render(model, rc) {
        drawLayout(model, rc, layoutStructogram(model.root));
    },
    tools(board) {
        const list = Object.entries(BLOCK_TYPES).map(([type, def]) => ({
            id: `ins-${type}`,
            group: 'Bausteine',
            label: def.label,
            title: `${def.label} einfügen: erst wählen, dann auf einen Einfügepunkt klicken`,
            toggle: true,
            insert: type,
            hint: `${def.label}: Klicke auf einen markierten Einfügepunkt (Linie oder leere Zelle). Mit der Tastatur: Tab zu einem Einfügepunkt, Enter.`,
        }));
        const sel = (b) => selectedBlock(b);
        list.push(
            { id: 'move-up', group: 'Anordnen', label: '↑ Hoch', title: 'Block nach oben schieben (Alt+Pfeil hoch)', run: (b) => moveSelected(b, 'up'), disabled: (b) => !sel(b) || sel(b).index === 0 },
            { id: 'move-down', group: 'Anordnen', label: '↓ Runter', title: 'Block nach unten schieben (Alt+Pfeil runter)', run: (b) => moveSelected(b, 'down'), disabled: (b) => !sel(b) || sel(b).index >= sel(b).seq.children.length - 1 },
            { id: 'indent', group: 'Anordnen', label: '⇥ Hinein', title: 'In den vorherigen Block aufnehmen (Alt+Pfeil rechts)', run: (b) => moveSelected(b, 'in'), disabled: (b) => !sel(b) || sel(b).index === 0 },
            { id: 'outdent', group: 'Anordnen', label: '⇤ Heraus', title: 'Aus dem umgebenden Block herausnehmen (Alt+Pfeil links)', run: (b) => moveSelected(b, 'out'), disabled: (b) => !sel(b) || sel(b).key === 'root' },
        );
        void board;

        return list;
    },
    useToolShortcut: true,
    onAction(name, data, board) {
        if (name === 'slot') {
            insertAt(board, data.key, Number(data.index));
        }
    },
    onKey(e, board) {
        const order = flatOrder(board.model.root);
        const current = [...board.sel][0];
        if (e.altKey && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
            return moveSelected(board, { ArrowUp: 'up', ArrowDown: 'down', ArrowRight: 'in', ArrowLeft: 'out' }[e.key]);
        }
        if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && !e.altKey && order.length > 0) {
            const i = current === undefined ? -1 : order.indexOf(current);
            const next = order[Math.max(0, Math.min(order.length - 1, i + (e.key === 'ArrowDown' ? 1 : -1)))];
            board.select([next]);
            return true;
        }

        return false;
    },
    remove(model, ids) {
        let removed = false;
        for (const id of ids) {
            removed = removeBlock(model.root, id) !== null || removed;
        }

        return removed;
    },
    describe(model, id) {
        const hit = findBlock(model.root, id);

        return hit ? `${BLOCK_TYPES[hit.block.type].label}: ${(hit.block[textField(hit.block)] || 'leer').replace(/\n/g, ' ')}` : '';
    },
    inspector(board) {
        const hit = selectedBlock(board);
        if (!hit) {
            return panel('Struktogramm', hintText(board.sel.size > 1
                ? 'Wähle einen einzelnen Block, um seinen Text zu bearbeiten.'
                : 'Wähle einen Baustein aus der Werkzeugleiste und klicke auf einen Einfügepunkt. Mit Klick oder Pfeiltasten wählst du einen Block aus, um seinen Text zu ändern. Entf löscht den Block samt Inhalt.'));
        }
        const { block } = hit;
        const id = block.id;
        const def = BLOCK_TYPES[block.type];
        const labels = { text: 'Text', cond: 'Bedingung', expr: 'Ausdruck (Fallunterscheidung)', head: 'Schleifenkopf (z. B. i von 1 bis n)' };
        const set = (fn, key, inspector = false) => board.mutate((m) => {
            const x = findBlock(m.root, id)?.block;
            if (x) {
                fn(x);
            }
        }, { key, inspector });
        const parts = [
            field(labels[def.field], textArea(block[def.field], (v) => set((x) => { x[def.field] = multiline(v, 300); }, `t:${id}`), { rows: 3, maxlength: 300 })),
        ];
        if (block.type === 'switch') {
            const wrap = h('div', { class: 'dg-members' }, h('h4', { class: 'dg-sub' }, 'Fälle'));
            block.cases.forEach((c, i) => wrap.append(h('div', { class: 'dg-member' },
                textInput(c.label, (v) => set((x) => { x.cases[i].label = line(v, 80); }, `c:${id}:${i}`), { 'aria-label': `Bezeichnung Fall ${i + 1}`, placeholder: 'Fall' }),
                smallButton('✕', () => set((x) => { x.cases.splice(i, 1); }, null, true), { 'aria-label': `Fall ${i + 1} samt Inhalt entfernen`, disabled: block.cases.length <= 1 }))));
            wrap.append(smallButton('+ Fall', () => set((x) => {
                if (x.cases.length < 12) {
                    x.cases.splice(Math.max(0, x.cases.length - 1), 0, { label: String(x.cases.length), body: emptySeq() });
                }
            }, null, true), { disabled: block.cases.length >= 12 }));
            parts.push(wrap);
        }

        return panel(`${def.label} bearbeiten`, h('div', { class: 'dg-fields' }, ...parts),
            h('div', { class: 'dg-actions' },
                smallButton('↑', () => moveSelected(board, 'up'), { 'aria-label': 'Nach oben', disabled: hit.index === 0 }),
                smallButton('↓', () => moveSelected(board, 'down'), { 'aria-label': 'Nach unten', disabled: hit.index >= hit.seq.children.length - 1 }),
                smallButton('⇥', () => moveSelected(board, 'in'), { 'aria-label': 'In den vorherigen Block aufnehmen', disabled: hit.index === 0 }),
                smallButton('⇤', () => moveSelected(board, 'out'), { 'aria-label': 'Aus dem umgebenden Block herausnehmen', disabled: hit.key === 'root' }),
                smallButton('🗑 Löschen', () => board.removeSelection())));
    },
};

export const StructogramWidget = boardWidget(structogramPlugin);
registerAnswerWidget('structogram', StructogramWidget);
