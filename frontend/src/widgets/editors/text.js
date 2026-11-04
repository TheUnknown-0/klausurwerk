/** Aufgaben-Editor für den Antworttyp "text" (Freitext). */
import { h } from '../../lib/dom.js';
import { registerTaskEditor } from '../../lib/widgets.js';
import { labeled, numberInput, textInput } from '../../task-editor/ui.js';

registerTaskEditor('text', {
    mount(container, ctx) {
        const cfg = {
            min_words: ctx.config?.min_words ?? '',
            max_chars: ctx.config?.max_chars ?? 20000,
            placeholder: ctx.config?.placeholder ?? '',
        };
        const changed = () => ctx.onChange();
        const root = h('fieldset', { class: 'te-type-box', disabled: !!ctx.locked },
            h('div', { class: 'te-grid' },
                labeled('Mindestwortzahl (Hinweis)', numberInput(cfg.min_words, (v) => { cfg.min_words = v; changed(); }, { min: 0, max: 10000 }),
                    'Leer lassen für keine Vorgabe. Schüler:innen sehen einen Wortzähler.'),
                labeled('Höchstlänge in Zeichen', numberInput(cfg.max_chars, (v) => { cfg.max_chars = v; changed(); }, { min: 100, max: 100000 })),
            ),
            labeled('Platzhaltertext im Antwortfeld', textInput(cfg.placeholder, (v) => { cfg.placeholder = v; changed(); }, { maxlength: 300 })),
        );
        container.append(root);

        return {
            getValue: () => ({
                config: {
                    min_words: cfg.min_words === '' ? null : Number(cfg.min_words),
                    max_chars: Number(cfg.max_chars) || 20000,
                    placeholder: cfg.placeholder,
                },
                solution: {},
            }),
            validate() {
                const errors = [];
                const max = Number(cfg.max_chars);
                if (!(max >= 100 && max <= 100000)) {
                    errors.push('Die Höchstlänge muss zwischen 100 und 100000 Zeichen liegen.');
                }
                return errors;
            },
            destroy: () => root.remove(),
        };
    },
});
