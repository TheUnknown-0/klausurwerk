/** Aufgaben-Editor für den Antworttyp "matching" (Zuordnung). */
import { h } from '../../lib/dom.js';
import { registerTaskEditor } from '../../lib/widgets.js';
import { asObject, checkbox, itemsEditor, select } from '../../task-editor/ui.js';

registerTaskEditor('matching', {
    mount(container, ctx) {
        const locked = !!ctx.locked;
        const left = (ctx.config?.left ?? [{ id: 'l1', text_md: '' }, { id: 'l2', text_md: '' }]).map((o) => ({ id: o.id, text_md: o.text_md }));
        const right = (ctx.config?.right ?? [{ id: 'r1', text_md: '' }, { id: 'r2', text_md: '' }]).map((o) => ({ id: o.id, text_md: o.text_md }));
        const pairs = { ...asObject(ctx.solution?.pairs) };
        let shuffle = ctx.config?.shuffle !== false;
        const changed = () => ctx.onChange();

        const pairBox = h('div', { class: 'te-items' });
        const label = (items, id) => {
            const idx = items.findIndex((i) => i.id === id);
            const text = items[idx]?.text_md.trim() ?? '';
            return `${idx + 1}. ${text.slice(0, 50) || '(leer)'}`;
        };
        function renderPairs() {
            pairBox.replaceChildren();
            for (const l of left) {
                if (!right.some((r) => r.id === pairs[l.id])) {
                    delete pairs[l.id];
                }
                const sel = select([['', '(keine Zuordnung)'], ...right.map((r) => [r.id, label(right, r.id)])], pairs[l.id] ?? '', (v) => {
                    if (v === '') {
                        delete pairs[l.id];
                    } else {
                        pairs[l.id] = v;
                    }
                    changed();
                }, { 'aria-label': `Richtige Zuordnung für ${label(left, l.id)}` });
                pairBox.append(h('div', { class: 'te-item', style: { gridTemplateColumns: '1fr 1fr' } }, h('span', {}, label(left, l.id)), sel));
            }
        }

        const leftEd = itemsEditor({ items: left, prefix: 'l', label: 'Begriff links', locked, min: 1, max: 20, onChange: () => { renderPairs(); changed(); } });
        const rightEd = itemsEditor({ items: right, prefix: 'r', label: 'Gegenstück rechts', locked, min: 1, max: 20, onChange: () => { renderPairs(); changed(); } });
        renderPairs();

        const root = h('div', { class: 'te-type-box' },
            h('div', { class: 'grid-2' },
                h('div', {}, h('h4', {}, 'Links'), leftEd.el),
                h('div', {}, h('h4', {}, 'Rechts'), rightEd.el),
            ),
            h('h4', {}, 'Richtige Zuordnung'),
            pairBox,
            checkbox('Reihenfolge je Person mischen', shuffle, (v) => { shuffle = v; changed(); }, { disabled: locked }),
        );
        container.append(root);

        return {
            getValue: () => ({
                config: {
                    left: left.map((o) => ({ id: o.id, text_md: o.text_md.trim() })),
                    right: right.map((o) => ({ id: o.id, text_md: o.text_md.trim() })),
                    shuffle,
                },
                solution: { pairs: { ...pairs } },
            }),
            validate() {
                const errors = [];
                if (left.some((o) => o.text_md.trim() === '') || right.some((o) => o.text_md.trim() === '')) {
                    errors.push('Alle Einträge links und rechts brauchen einen Text.');
                }
                if (Object.keys(pairs).length === 0) {
                    errors.push('Lege mindestens eine richtige Zuordnung fest.');
                }
                return errors;
            },
            destroy: () => root.remove(),
        };
    },
});
