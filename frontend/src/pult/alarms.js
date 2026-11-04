/**
 * Alarmmeldungen oben im Pult: Name und Grund, mit Sprung zur Detailansicht.
 */
import { h, icon } from '../lib/dom.js';
import { alarmReason } from './util.js';

const MAX_VISIBLE = 4;
const AUTO_HIDE_MS = 60000;

export function createAlarmList(store, handlers) {
    const el = h('div', { class: 'pult-alarms', role: 'alert', 'aria-live': 'assertive' });
    let items = [];
    const originalTitle = document.title;

    function render() {
        el.replaceChildren(...items.slice(0, MAX_VISIBLE).map((a) => h('div', { class: 'pult-alarm' },
            h('span', { class: 'pult-alarm-icon', 'aria-hidden': 'true' }, icon('triangle-alert')),
            h('span', { class: 'pult-alarm-text' }, h('strong', {}, a.name ?? 'Person'), ` ${alarmReason(a.event)}`),
            h('button', { type: 'button', class: 'btn btn-sm', onclick: () => handlers.onDetail(a.event.participation_id) }, 'Details'),
            h('button', { type: 'button', class: 'btn btn-sm btn-ghost', 'aria-label': 'Meldung ausblenden', onclick: () => dismiss(a.event.id) }, icon('x')))));
        if (items.length > MAX_VISIBLE) {
            el.append(h('div', { class: 'pult-alarm-more text-sm' }, `und ${items.length - MAX_VISIBLE} weitere Meldungen`));
        }
        el.hidden = items.length === 0;
        document.title = items.length > 0 ? `Alarm: ${originalTitle}` : originalTitle;
    }

    function dismiss(eventId) {
        items = items.filter((a) => a.event.id !== eventId);
        render();
    }

    function add(events) {
        const now = Date.now();
        for (const event of events) {
            items.unshift({ event, name: event.name, at: now });
        }
        items = items.slice(0, 30);
        render();
    }

    // Alte Meldungen blenden sich selbst aus.
    setInterval(() => {
        const before = items.length;
        items = items.filter((a) => Date.now() - a.at < AUTO_HIDE_MS);
        if (items.length !== before) render();
    }, 5000);

    render();

    return { el, add, clear() { items = []; render(); } };
}
