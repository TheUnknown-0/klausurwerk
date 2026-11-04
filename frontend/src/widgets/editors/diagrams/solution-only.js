/**
 * Aufgaben-Editoren für Diagramm-Typen mit Musterdiagramm und höchstens einer Option:
 * uml, structogram, flowchart, er, relational.
 * Die Musterlösung wird mit demselben Zeichen-Widget gezeichnet wie die Antwort der Schüler:innen.
 */
import { h } from '../../../lib/dom.js';
import { registerTaskEditor } from '../../../lib/widgets.js';
import { checkbox } from '../../../task-editor/ui.js';
import { embedWidget, section } from './common.js';

const INTRO = {
    uml: 'Zeichne das Musterdiagramm. Beim Prüfen schlägt Klausurwerk eine Punktzahl vor, indem es Klassen, Attribute, Methoden und Beziehungen der Antwort mit diesem Diagramm vergleicht. Die Bewertung bleibt bei dir.',
    er: 'Zeichne die Musterlösung. Beim Prüfen werden Entitäten, Schlüsselattribute, Beziehungen und Kardinalitäten grob verglichen. Das Ergebnis ist nur ein Vorschlag.',
    relational: 'Lege das Musterschema an. Beim Prüfen werden Tabellen, Spalten, Primär- und Fremdschlüssel verglichen. Das Ergebnis ist nur ein Vorschlag.',
    structogram: 'Optional: Zeichne eine Musterlösung. Sie wird bei der Korrektur neben der Antwort angezeigt. Struktogramme werden nicht automatisch geprüft.',
    flowchart: 'Optional: Zeichne eine Musterlösung. Sie wird bei der Korrektur neben der Antwort angezeigt. Programmablaufpläne werden nicht automatisch geprüft.',
};

function register(type, options = null) {
    registerTaskEditor(type, {
        mount(container, ctx) {
            const locked = !!ctx.locked;
            const state = { ...(options?.initial?.(ctx.config) ?? {}) };
            const changed = () => ctx.onChange();
            const host = h('div', { class: 'te-diagram-solution' });
            const configConfig = () => options?.toConfig?.(state) ?? {};
            const embed = embedWidget(host, type, { config: configConfig(), content: ctx.solution ?? {}, onChange: changed });
            const extra = options?.build?.(state, locked, () => {
                embed.setConfig(configConfig());
                changed();
            });
            const root = h('div', { class: 'te-type-box' }, extra ?? null,
                section('Musterlösung', INTRO[type], host));
            container.append(root);

            return {
                getValue: () => ({ config: configConfig(), solution: embed.getContent() }),
                validate() {
                    const errors = [];
                    const content = embed.getContent();
                    if (type === 'uml' && content.classes?.some((c) => c.name.trim() === '')) {
                        errors.push('Im Musterdiagramm hat eine Klasse keinen Namen.');
                    }
                    if (type === 'er' && content.entities?.some((e) => e.name.trim() === '')) {
                        errors.push('In der Musterlösung hat eine Entität keinen Namen.');
                    }
                    if (type === 'relational') {
                        if (content.tables?.some((t) => t.name.trim() === '' || t.columns.some((c) => c.name.trim() === ''))) {
                            errors.push('Im Musterschema brauchen alle Tabellen und Spalten einen Namen.');
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
}

register('uml', {
    initial: (config) => ({ allow_interfaces: config?.allow_interfaces !== false }),
    toConfig: (state) => ({ diagram: 'class', allow_interfaces: state.allow_interfaces }),
    build: (state, locked, update) => h('div', { class: 'cluster' },
        checkbox('Interfaces erlauben', state.allow_interfaces, (v) => { state.allow_interfaces = v; update(); }, { disabled: locked })),
});
register('structogram');
register('flowchart');
register('er');
register('relational');
