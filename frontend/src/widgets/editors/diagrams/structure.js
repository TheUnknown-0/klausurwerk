/**
 * Aufgaben-Editor für den Antworttyp "structure" (Strukturformel, Chemie): Musterlösung und
 * optionale Vorgabe-Startzeichnung werden mit demselben Zeichenwerkzeug gezeichnet wie die Antwort.
 * Option: Elektronenpaare (Striche, Punkte, Atomladungen) in der Vorbewertung berücksichtigen.
 */
import { h } from '../../../lib/dom.js';
import { registerTaskEditor } from '../../../lib/widgets.js';
import { checkbox } from '../../../task-editor/ui.js';
import { embedWidget, section } from './common.js';

const isEmpty = (c) => !c || ((c.atoms ?? []).length === 0 && (c.texts ?? []).length === 0 && (c.arrows ?? []).length === 0);

registerTaskEditor('structure', {
    mount(container, ctx) {
        const locked = !!ctx.locked;
        const state = { score_pairs: ctx.config?.score_pairs === true };
        const changed = () => ctx.onChange();

        const startHost = h('div', { class: 'te-diagram-solution' });
        const start = embedWidget(startHost, 'structure', { config: {}, content: ctx.config?.start ?? {}, onChange: changed });
        const solutionHost = h('div', { class: 'te-diagram-solution' });
        const solution = embedWidget(solutionHost, 'structure', { config: {}, content: ctx.solution ?? {}, onChange: changed });

        const root = h('div', { class: 'te-type-box' },
            h('div', { class: 'cluster' },
                checkbox('Elektronenpaare bewerten (freie Paare, Radikale und Ladungen der Atome)', state.score_pairs, (v) => { state.score_pairs = v; changed(); }, { disabled: locked })),
            section('Vorgabe (optional)',
                'Eine Startzeichnung, z. B. ein Gerüst zum Ergänzen. Schüler:innen sehen sie zu Beginn und können sie verändern. Leer lassen für ein leeres Blatt.',
                startHost),
            section('Musterlösung',
                'Zeichne die Lösung. Beim Prüfen vergleicht Klausurwerk je Molekül Summenformel, Gesamtladung und Bindungsgerüst (Bindungsarten) mit deiner Zeichnung. Wasserstoffatome müssen gezeichnet sein, sonst stimmt die Summenformel nicht. Das Ergebnis ist nur ein Vorschlag, die Bewertung bleibt bei dir.',
                solutionHost));
        container.append(root);

        return {
            getValue() {
                const startContent = start.getContent();

                return {
                    config: { score_pairs: state.score_pairs, start: isEmpty(startContent) ? null : startContent },
                    solution: solution.getContent(),
                };
            },
            validate: () => [],
            destroy() {
                start.destroy();
                solution.destroy();
                root.remove();
            },
        };
    },
});
