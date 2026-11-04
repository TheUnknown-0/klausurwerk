/**
 * Aufgaben-Editor für den Antworttyp "automaton": DEA/NEA, Alphabet, Simulation für Schüler:innen
 * und Musterlösung als Testwörter (akzeptiert/verworfen), optional mit gezeichnetem Musterautomaten.
 */
import { h } from '../../../lib/dom.js';
import { registerTaskEditor } from '../../../lib/widgets.js';
import { button, checkbox, labeled, select, textarea } from '../../../task-editor/ui.js';
import { accepts, generateWords } from '../../diagrams/core/automaton-sim.js';
import { embedWidget, namesField, section } from './common.js';

const EMPTY = 'ε';
const wordsToText = (list) => (list ?? []).map((w) => (w === '' ? EMPTY : w)).join('\n');
const textToWords = (raw) => String(raw ?? '').split('\n').map((l) => l.trim()).filter((l) => l !== '').map((l) => (l === EMPTY ? '' : l));

registerTaskEditor('automaton', {
    mount(container, ctx) {
        const locked = !!ctx.locked;
        const c = ctx.config ?? {};
        const state = {
            kind: c.kind === 'nfa' ? 'nfa' : 'dfa',
            alphabet: Array.isArray(c.alphabet) ? c.alphabet.slice() : ['a', 'b'],
            allow_simulation: c.allow_simulation !== false,
            allow_epsilon: !!c.allow_epsilon,
        };
        const sol = ctx.solution ?? {};
        const changed = () => ctx.onChange();
        const configOf = () => ({
            kind: state.kind,
            alphabet: state.alphabet,
            allow_simulation: state.allow_simulation,
            allow_epsilon: state.kind === 'nfa' && state.allow_epsilon,
        });

        const kindSel = labeled('Art des Automaten', select([['dfa', 'DEA (deterministisch, endlich)'], ['nfa', 'NEA (nichtdeterministisch)']], state.kind, (v) => {
            state.kind = v;
            epsilonBox.hidden = v !== 'nfa';
            embed.setConfig(configOf());
            changed();
        }, { disabled: locked }));
        const alphabetField = namesField('Alphabet (durch Komma getrennt)', state.alphabet, () => {
            state.alphabet = alphabetField.get();
            if (alphabetOk()) {
                embed.setConfig(configOf());
            }
            changed();
        }, { locked, hint: 'Einzelne Zeichen, z. B. a, b oder 0, 1 (höchstens 10).' });
        const sim = checkbox('Schüler:innen dürfen Wörter schrittweise ausprobieren', state.allow_simulation, (v) => { state.allow_simulation = v; embed.setConfig(configOf()); changed(); }, { disabled: locked });
        const epsilonBox = checkbox('ε-Übergänge (leeres Wort) erlauben', state.allow_epsilon, (v) => { state.allow_epsilon = v; embed.setConfig(configOf()); changed(); }, { disabled: locked });
        epsilonBox.hidden = state.kind !== 'nfa';

        const alphabetOk = () => state.alphabet.length >= 1 && state.alphabet.length <= 10 && state.alphabet.every((a) => [...a].length === 1 && a !== EMPTY)
            && new Set(state.alphabet).size === state.alphabet.length;

        // ---------- Musterautomat ----------
        const host = h('div', { class: 'te-diagram-solution' });
        const embed = embedWidget(host, 'automaton', { config: configOf(), content: sol.automaton ?? {}, onChange: () => { check(); changed(); } });
        const status = h('div', { class: 'text-soft te-status-line', role: 'status', 'aria-live': 'polite' });
        const accept = textarea(wordsToText(sol.accept), () => { check(); changed(); }, { rows: 6, class: 'input mono', spellcheck: 'false', 'aria-label': 'Wörter, die akzeptiert werden' });
        const reject = textarea(wordsToText(sol.reject), () => { check(); changed(); }, { rows: 6, class: 'input mono', spellcheck: 'false', 'aria-label': 'Wörter, die verworfen werden' });

        const hasAutomaton = () => embed.getContent().states.length > 0;
        function check() {
            if (!hasAutomaton()) {
                status.textContent = 'Kein Musterautomat gezeichnet: Es gelten allein die Testwörter.';
                return;
            }
            const automaton = embed.getContent();
            const wrong = [
                ...textToWords(accept.value).filter((w) => !accepts(automaton, w)).map((w) => `${w === '' ? EMPTY : w} (soll akzeptiert werden)`),
                ...textToWords(reject.value).filter((w) => accepts(automaton, w)).map((w) => `${w === '' ? EMPTY : w} (soll verworfen werden)`),
            ];
            status.textContent = wrong.length === 0
                ? 'Der Musterautomat verhält sich bei allen Testwörtern wie erwartet.'
                : `Der Musterautomat widerspricht den Testwörtern: ${wrong.slice(0, 6).join(', ')}${wrong.length > 6 ? ' ...' : ''}`;
        }
        const generate = button('Testwörter aus Musterautomat erzeugen', () => {
            if (!hasAutomaton() || !alphabetOk()) {
                status.textContent = 'Zeichne zuerst einen Musterautomaten und lege das Alphabet fest.';
                return;
            }
            const words = generateWords(embed.getContent(), state.alphabet, { maxLength: 6, perKind: 8 });
            accept.value = wordsToText(words.accept);
            reject.value = wordsToText(words.reject);
            check();
            changed();
        });

        check();
        const root = h('div', { class: 'te-type-box' },
            h('div', { class: 'te-grid' }, kindSel, alphabetField.field),
            h('div', { class: 'cluster' }, sim, epsilonBox),
            section('Musterlösung: Testwörter', 'Die Antwort wird geprüft, indem der gezeichnete Automat auf diese Wörter angewendet wird. Ein Wort pro Zeile; das leere Wort schreibst du als ε.',
                h('div', { class: 'te-grid' }, labeled('Werden akzeptiert', accept), labeled('Werden verworfen', reject))),
            section('Musterautomat (optional)', 'Zeichne den Automaten, wenn du ihn bei der Korrektur anzeigen oder daraus Testwörter erzeugen möchtest.',
                h('div', { class: 'cluster' }, generate), status, host));
        container.append(root);

        return {
            getValue() {
                const solution = { accept: textToWords(accept.value), reject: textToWords(reject.value) };
                if (hasAutomaton()) {
                    solution.automaton = embed.getContent();
                }

                return { config: configOf(), solution };
            },
            validate() {
                const errors = [];
                if (!alphabetOk()) {
                    errors.push('Das Alphabet braucht 1 bis 10 verschiedene einzelne Zeichen (ε ist nicht erlaubt).');
                } else {
                    const A = textToWords(accept.value);
                    const R = textToWords(reject.value);
                    for (const w of [...A, ...R]) {
                        const bad = [...w].find((ch) => !state.alphabet.includes(ch));
                        if (bad !== undefined) {
                            errors.push(`Das Testwort "${w}" enthält "${bad}", das nicht im Alphabet steht.`);
                            break;
                        }
                    }
                    const both = A.find((w) => R.includes(w));
                    if (both !== undefined) {
                        errors.push(`Das Wort "${both === '' ? EMPTY : both}" steht bei akzeptiert und bei verworfen.`);
                    }
                    if (A.length > 50 || R.length > 50 || [...A, ...R].some((w) => [...w].length > 30)) {
                        errors.push('Höchstens 50 Testwörter je Liste, jedes höchstens 30 Zeichen lang.');
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
