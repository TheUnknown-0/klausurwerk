/**
 * Aktionsleiste für die ausgewählten Kacheln (Mehrfachauswahl).
 */
import { h } from '../lib/dom.js';
import { PERSON_ACTIONS } from './actions.js';

export function createActionBar(store, actions) {
    const { state } = store;
    const count = h('span', { class: 'pult-actionbar-count', 'aria-live': 'polite' });
    const buttons = new Map();

    const bar = h('div', { class: 'pult-actionbar', role: 'toolbar', 'aria-label': 'Aktionen für die Auswahl' });
    const selectAll = h('button', { type: 'button', class: 'btn btn-sm', onclick: () => store.select(state.participants.map((p) => p.id)) }, 'Alle auswählen');
    const selectNone = h('button', { type: 'button', class: 'btn btn-sm btn-ghost', onclick: () => store.clearSelection() }, 'Auswahl aufheben');

    const group = h('div', { class: 'pult-actionbar-buttons' });
    for (const def of PERSON_ACTIONS) {
        const btn = h('button', {
            type: 'button',
            class: `btn btn-sm${def.danger ? ' btn-danger-ghost' : ''}`,
            onclick: () => actions.runPerson(def.key, [...state.selection]),
        }, def.icon ? `${def.icon} ${def.label}` : def.label);
        buttons.set(def.key, btn);
        group.append(btn);
    }
    bar.append(h('div', { class: 'pult-actionbar-head' }, count, selectAll, selectNone), group);

    function update() {
        const ids = [...state.selection];
        const selected = ids.map((id) => store.participant(id)).filter(Boolean);
        count.textContent = selected.length === 0
            ? 'Keine Auswahl: Kacheln anklicken (Strg+Klick für mehrere).'
            : `${selected.length} ausgewählt`;
        bar.classList.toggle('has-selection', selected.length > 0);
        selectNone.disabled = selected.length === 0;
        selectAll.disabled = state.participants.length === 0;
        const exam = state.exam;
        const examOpen = exam && ['lobby', 'running', 'paused'].includes(exam.status);
        for (const def of PERSON_ACTIONS) {
            const eligible = selected.filter((p) => def.states.includes(p.state)).length;
            const btn = buttons.get(def.key);
            btn.disabled = !examOpen || eligible === 0;
            btn.title = eligible === 0
                ? 'Passt zu keiner ausgewählten Person'
                : `${eligible} von ${selected.length} ausgewählten Personen`;
        }
    }

    return { el: bar, update };
}
