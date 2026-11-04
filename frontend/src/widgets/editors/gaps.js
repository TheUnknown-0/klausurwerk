/** Aufgaben-Editor für den Antworttyp "gaps" (Lückentext / Lückencode). */
import { h } from '../../lib/dom.js';
import { registerTaskEditor } from '../../lib/widgets.js';
import { asObject, button, checkbox, labeled, newId, numberInput, select, textInput, textarea } from '../../task-editor/ui.js';

const GAP_RE = /\{\{([A-Za-z0-9_-]{1,40})\}\}/g;
const idsIn = (template) => [...new Set([...template.matchAll(GAP_RE)].map((m) => m[1]))];
const lines = (text) => text.split('\n').map((l) => l.trim()).filter((l) => l !== '');

registerTaskEditor('gaps', {
    mount(container, ctx) {
        const locked = !!ctx.locked;
        const cfg = ctx.config ?? {};
        const sol = asObject(ctx.solution?.gaps);
        const state = { mode: cfg.mode ?? 'text', language: cfg.language ?? 'java', template: cfg.template ?? '' };
        /** id -> Eingabewerte je Lücke */
        const gaps = {};
        for (const g of cfg.gaps ?? []) {
            const s = asObject(sol[g.id]);
            gaps[g.id] = {
                width: g.width ?? 6,
                options: (g.options ?? []).join('\n'),
                accepted: (s.accepted ?? []).join('\n'),
                case_sensitive: s.case_sensitive !== false,
                trim: s.trim !== false,
                regex: s.regex ?? '',
            };
        }
        const changed = () => ctx.onChange();
        const gapBox = h('div', {});

        function ensureGap(id) {
            gaps[id] ??= { width: 6, options: '', accepted: '', case_sensitive: true, trim: true, regex: '' };
            return gaps[id];
        }

        function renderGaps() {
            gapBox.replaceChildren();
            const ids = idsIn(state.template);
            if (ids.length === 0) {
                gapBox.append(h('p', { class: 'text-soft' }, 'Noch keine Lücke. Markiere Lücken im Text mit {{kennung}} oder nutze "Lücke einfügen".'));
                return;
            }
            for (const id of ids) {
                const g = ensureGap(id);
                gapBox.append(h('div', { class: 'te-gap-row' },
                    h('strong', { class: 'mono' }, id),
                    h('div', {},
                        h('div', { class: 'te-grid' },
                            labeled('Richtige Antworten (eine je Zeile)', textarea(g.accepted, (v) => { g.accepted = v; changed(); }, { rows: 2 })),
                            labeled('Auswahlliste (optional, eine Option je Zeile)', textarea(g.options, (v) => { g.options = v; changed(); }, { rows: 2, disabled: locked }),
                                'Mindestens zwei Optionen ergeben ein Auswahlfeld statt einer Eingabe.'),
                        ),
                        h('div', { class: 'te-grid' },
                            labeled('Breite (Zeichen)', numberInput(g.width, (v) => { g.width = v; changed(); }, { min: 1, max: 60, disabled: locked })),
                            labeled('Regulärer Ausdruck (optional)', textInput(g.regex, (v) => { g.regex = v; changed(); }, { class: 'input mono' })),
                        ),
                        h('div', { class: 'cluster' },
                            checkbox('Groß-/Kleinschreibung beachten', g.case_sensitive, (v) => { g.case_sensitive = v; changed(); }),
                            checkbox('Leerzeichen am Rand ignorieren', g.trim, (v) => { g.trim = v; changed(); }),
                        ),
                    ),
                ));
            }
        }

        const tpl = textarea(state.template, (v) => {
            state.template = v;
            renderGaps();
            changed();
        }, { rows: 8, class: 'input mono', disabled: locked, spellcheck: 'false' });
        const insertBtn = button('Lücke einfügen', () => {
            const id = newId('g', idsIn(state.template));
            const start = tpl.selectionStart ?? tpl.value.length;
            const end = tpl.selectionEnd ?? start;
            tpl.setRangeText(`{{${id}}}`, start, end, 'end');
            tpl.dispatchEvent(new Event('input'));
            tpl.focus();
        }, { disabled: locked });

        const language = labeled('Sprache (Hervorhebung)', select([['java', 'Java'], ['python', 'Python'], ['sql', 'SQL']], state.language, (v) => { state.language = v; changed(); }, { disabled: locked }));
        const modeSel = labeled('Art', select([['text', 'Lückentext'], ['code', 'Lückencode']], state.mode, (v) => {
            state.mode = v;
            language.hidden = v !== 'code';
            changed();
        }, { disabled: locked }));
        language.hidden = state.mode !== 'code';

        const root = h('div', { class: 'te-type-box' },
            h('div', { class: 'te-grid' }, modeSel, language),
            labeled('Text mit Lücken', tpl, 'Beispiel: for (int i = 0; i {{g1}} 10; i++) { ... }'),
            h('div', { class: 'cluster' }, insertBtn),
            h('h4', {}, 'Lücken und richtige Antworten'),
            gapBox,
        );
        renderGaps();
        container.append(root);

        return {
            getValue() {
                const ids = idsIn(state.template);
                const gapCfg = [];
                const gapSol = {};
                for (const id of ids) {
                    const g = ensureGap(id);
                    const options = lines(g.options);
                    gapCfg.push({ id, width: Number(g.width) || 6, options: options.length >= 2 ? options : null });
                    gapSol[id] = { accepted: lines(g.accepted), case_sensitive: g.case_sensitive, trim: g.trim, regex: g.regex.trim() === '' ? null : g.regex.trim() };
                }
                return {
                    config: { mode: state.mode, language: state.mode === 'code' ? state.language : null, template: state.template, gaps: gapCfg },
                    solution: { gaps: gapSol },
                };
            },
            validate() {
                const errors = [];
                const ids = idsIn(state.template);
                if (ids.length === 0) {
                    errors.push('Der Text braucht mindestens eine Lücke ({{kennung}}).');
                }
                for (const id of ids) {
                    const g = ensureGap(id);
                    if (lines(g.accepted).length === 0 && g.regex.trim() === '') {
                        errors.push(`Lücke ${id}: Gib mindestens eine richtige Antwort an.`);
                    }
                }
                return errors;
            },
            destroy: () => root.remove(),
        };
    },
});
