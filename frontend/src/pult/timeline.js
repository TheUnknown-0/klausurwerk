/**
 * Ereignis-Zeitstrahl mit Filter nach Schweregrad, Typ und Person.
 */
import { h } from '../lib/dom.js';
import { clockTime, describeEvent, EVENT_LABELS, SEVERITY_LABELS } from './util.js';

const MAX_SHOWN = 200;

export function createTimeline(store, handlers) {
    const { state } = store;
    const filter = { severity: 'all', type: 'all', person: 'all' };

    const select = (id, label, onChange) => {
        const s = h('select', { id, class: 'input', onchange: (e) => onChange(e.target.value) });
        return { el: h('div', { class: 'field pult-filter' }, h('label', { for: id }, label), s), select: s };
    };
    const severity = select('pult-f-severity', 'Schwere', (v) => { filter.severity = v; render(); });
    severity.select.append(
        h('option', { value: 'all' }, 'Alle'),
        h('option', { value: 'alarm' }, SEVERITY_LABELS.alarm),
        h('option', { value: 'warning' }, SEVERITY_LABELS.warning),
        h('option', { value: 'info' }, SEVERITY_LABELS.info),
    );
    const type = select('pult-f-type', 'Art', (v) => { filter.type = v; render(); });
    const person = select('pult-f-person', 'Person', (v) => { filter.person = v; render(); });

    const list = h('ol', { class: 'pult-timeline-list', 'aria-live': 'off' });
    const empty = h('p', { class: 'text-soft text-sm pult-empty' }, 'Noch keine Ereignisse.');
    const el = h('section', { class: 'card card-pad pult-timeline', 'aria-labelledby': 'pult-timeline-title' },
        h('h2', { id: 'pult-timeline-title', class: 'pult-section-title' }, 'Ereignisse'),
        h('div', { class: 'pult-filters' }, severity.el, type.el, person.el),
        empty, list);

    let optionsKey = '';
    function syncOptions() {
        const types = [...new Set(state.events.map((e) => e.type))].sort();
        const people = state.participants.map((p) => [String(p.id), p.name]);
        const key = JSON.stringify([types, people]);
        if (key === optionsKey) return;
        optionsKey = key;
        const typeValue = filter.type;
        const personValue = filter.person;
        type.select.replaceChildren(h('option', { value: 'all' }, 'Alle'), ...types.map((t) => h('option', { value: t }, EVENT_LABELS[t] ?? t)));
        person.select.replaceChildren(h('option', { value: 'all' }, 'Alle'), h('option', { value: 'exam' }, 'Klausur (ohne Person)'), ...people.map(([id, name]) => h('option', { value: id }, name)));
        type.select.value = types.includes(typeValue) ? typeValue : 'all';
        person.select.value = personValue;
        filter.type = type.select.value;
        filter.person = person.select.value;
    }

    let renderedKey = '';
    function render(force = true) {
        syncOptions();
        const key = JSON.stringify([state.events.length, state.events.at(-1)?.id ?? 0, filter, optionsKey.length]);
        if (!force && key === renderedKey) return;
        renderedKey = key;
        const events = state.events.filter((e) => {
            if (filter.severity !== 'all' && e.severity !== filter.severity) return false;
            if (filter.type !== 'all' && e.type !== filter.type) return false;
            if (filter.person === 'exam') return e.participation_id === null;
            if (filter.person !== 'all' && String(e.participation_id) !== filter.person) return false;
            return true;
        });
        const shown = events.slice(-MAX_SHOWN).reverse();
        empty.hidden = shown.length > 0;
        empty.textContent = state.events.length === 0 ? 'Noch keine Ereignisse.' : 'Keine Ereignisse für diesen Filter.';
        list.replaceChildren(...shown.map((e) => h('li', { class: `pult-event sev-${e.severity}` },
            h('time', { class: 'pult-event-time' }, clockTime(e.created_at_ms)),
            h('span', { class: 'pult-event-body' },
                e.participation_id !== null
                    ? h('button', { type: 'button', class: 'pult-link', onclick: () => handlers.onDetail(e.participation_id) }, e.name ?? 'Person')
                    : h('strong', {}, 'Klausur'),
                ' ',
                h('span', {}, describeEvent(e)),
                e.actor ? h('span', { class: 'text-faint text-sm' }, ` (${e.actor})`) : null),
            h('span', { class: `badge pult-event-sev ${e.severity === 'alarm' ? 'badge-danger' : e.severity === 'warning' ? 'badge-warning' : ''}` }, SEVERITY_LABELS[e.severity] ?? e.severity))));
    }

    return { el, update: () => render(false) };
}
