/** Aufgaben-Editor für den Antworttyp "ordering" (Reihenfolge). Die Listenreihenfolge ist die Lösung. */
import { h } from '../../lib/dom.js';
import { registerTaskEditor } from '../../lib/widgets.js';
import { itemsEditor, labeled, select } from '../../task-editor/ui.js';

registerTaskEditor('ordering', {
    mount(container, ctx) {
        const locked = !!ctx.locked;
        const items = (ctx.config?.items ?? [{ id: 's1', text_md: '' }, { id: 's2', text_md: '' }]).map((o) => ({ id: o.id, text_md: o.text_md }));
        // Gespeicherte Lösung bestimmt die Anzeigereihenfolge.
        const order = ctx.solution?.order ?? [];
        if (order.length === items.length) {
            items.sort((a, b) => order.indexOf(a.id) - order.indexOf(b.id));
        }
        let scoring = ctx.solution?.scoring ?? 'all_or_nothing';
        const changed = () => ctx.onChange();

        const editor = itemsEditor({
            items, prefix: 's', label: 'Position', locked, min: 2, max: 20, onChange: changed,
            lead: (item, index) => h('span', { class: 'te-num', 'aria-label': `Position ${index + 1}` }, `${index + 1}.`),
        });
        const root = h('div', { class: 'te-type-box' },
            h('p', { class: 'text-sm text-soft' }, 'Trage die Positionen in der richtigen Reihenfolge ein. Schüler:innen sehen sie gemischt.'),
            editor.el,
            labeled('Bewertung', select([['all_or_nothing', 'alles oder nichts'], ['partial', 'anteilig (richtige Positionen)']], scoring, (v) => { scoring = v; changed(); })),
        );
        container.append(root);

        return {
            getValue: () => ({
                config: { items: items.map((o) => ({ id: o.id, text_md: o.text_md.trim() })) },
                solution: { order: items.map((o) => o.id), scoring },
            }),
            validate: () => (items.some((o) => o.text_md.trim() === '') ? ['Jede Position braucht einen Text.'] : []),
            destroy: () => root.remove(),
        };
    },
});
