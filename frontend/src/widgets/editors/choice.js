/** Aufgaben-Editor für den Antworttyp "choice" (Single/Multiple Choice). */
import { h } from '../../lib/dom.js';
import { registerTaskEditor } from '../../lib/widgets.js';
import { checkbox, itemsEditor, labeled, select } from '../../task-editor/ui.js';

registerTaskEditor('choice', {
    mount(container, ctx) {
        const locked = !!ctx.locked;
        const correct = new Set(ctx.solution?.correct ?? []);
        const state = {
            multiple: !!ctx.config?.multiple,
            shuffle: ctx.config?.shuffle !== false,
            scoring: ctx.solution?.scoring ?? 'all_or_nothing',
            options: (ctx.config?.options ?? [{ id: 'a', text_md: '' }, { id: 'b', text_md: '' }]).map((o) => ({ id: o.id, text_md: o.text_md })),
        };
        const changed = () => ctx.onChange();

        const editor = itemsEditor({
            items: state.options,
            prefix: 'o',
            label: 'Antwortoption',
            locked,
            min: 2,
            max: 20,
            onChange: () => {
                for (const id of [...correct]) {
                    if (!state.options.some((o) => o.id === id)) {
                        correct.delete(id);
                    }
                }
                changed();
            },
            lead: (item) => {
                const input = h('input', {
                    type: state.multiple ? 'checkbox' : 'radio',
                    name: 'choice-correct',
                    'aria-label': 'Ist richtig',
                    title: 'Richtige Antwort',
                });
                input.checked = correct.has(item.id);
                input.addEventListener('change', () => {
                    if (!state.multiple) {
                        correct.clear();
                    }
                    if (input.checked) {
                        correct.add(item.id);
                    } else {
                        correct.delete(item.id);
                    }
                    changed();
                });
                return h('label', { class: 'checkbox-row' }, input, ' richtig');
            },
        });

        const multiple = checkbox('Mehrere Antworten möglich', state.multiple, (v) => {
            state.multiple = v;
            if (!v && correct.size > 1) {
                const first = [...correct][0];
                correct.clear();
                correct.add(first);
            }
            editor.render();
            changed();
        }, { disabled: locked });
        const shuffle = checkbox('Optionen je Person mischen', state.shuffle, (v) => { state.shuffle = v; changed(); }, { disabled: locked });
        const scoring = labeled('Bewertung',
            select([['all_or_nothing', 'alles oder nichts'], ['partial', 'anteilig (richtig gewählte minus falsch gewählte)']], state.scoring, (v) => { state.scoring = v; changed(); }));

        const root = h('div', { class: 'te-type-box' }, h('div', { class: 'cluster' }, multiple, shuffle), editor.el, scoring);
        container.append(root);

        return {
            getValue: () => ({
                config: { multiple: state.multiple, options: state.options.map((o) => ({ id: o.id, text_md: o.text_md.trim() })), shuffle: state.shuffle },
                solution: { correct: state.options.map((o) => o.id).filter((id) => correct.has(id)), scoring: state.scoring },
            }),
            validate() {
                const errors = [];
                if (state.options.some((o) => o.text_md.trim() === '')) {
                    errors.push('Jede Antwortoption braucht einen Text.');
                }
                if (correct.size === 0) {
                    errors.push('Markiere mindestens eine richtige Antwort.');
                }
                return errors;
            },
            destroy: () => root.remove(),
        };
    },
});
