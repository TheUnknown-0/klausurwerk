/**
 * Anfragen der Schüler:innen (Hand, Freigabe, Abgabe, Gerätewechsel) mit Annehmen/Ablehnen.
 */
import { h } from '../lib/dom.js';
import { ago, REQUEST_LABELS } from './util.js';

const ACCEPT_LABEL = { hand: 'Quittieren', release: 'Freigeben', submit: 'Bestätigen', device: 'Freigeben' };
const REJECT_LABEL = { release: 'Ablehnen', submit: 'Ablehnen', device: 'Ablehnen' };

/** Sperrt den Knopf, bis die Aktion durch ist (bei Fehler wieder bedienbar). */
function busy(button, promise) {
    button.disabled = true;
    promise.finally(() => { button.disabled = false; });
}

export function createRequestList(store, actions, handlers) {
    const { state } = store;
    const count = h('span', { class: 'badge badge-accent', hidden: true });
    const list = h('ul', { class: 'pult-requests-list', 'aria-live': 'polite' });
    const empty = h('p', { class: 'text-soft text-sm pult-empty' }, 'Keine offenen Anfragen.');
    const el = h('section', { class: 'card card-pad pult-requests', 'aria-labelledby': 'pult-requests-title' },
        h('h2', { id: 'pult-requests-title', class: 'pult-section-title' }, 'Anfragen ', count),
        empty, list);

    let renderedKey = '';
    function update() {
        const items = [];
        for (const p of state.participants) {
            for (const req of p.open_requests) {
                items.push({ p, req });
            }
            // Eine gehobene Hand ohne Anfragezeile (z. B. nach Neustart) trotzdem zeigen.
            if (p.hand_raised && !p.open_requests.some((r) => r.type === 'hand')) {
                items.push({ p, req: { id: `hand-${p.id}`, type: 'hand', created_at_ms: null } });
            }
        }
        items.sort((a, b) => (a.req.created_at_ms ?? 0) - (b.req.created_at_ms ?? 0));

        // Nur neu zeichnen, wenn sich etwas ändert (sonst verlieren Knöpfe den Fokus); die Zeitangabe alle 15 s.
        const key = JSON.stringify([items.map(({ p, req }) => [p.id, req.id, req.type, p.name]), Math.floor(store.now() / 15000)]);
        if (key === renderedKey) return;
        renderedKey = key;

        count.hidden = items.length === 0;
        count.textContent = String(items.length);
        empty.hidden = items.length > 0;

        const now = store.now();
        list.replaceChildren(...items.map(({ p, req }) => {
            const buttons = [h('button', {
                type: 'button',
                class: 'btn btn-sm btn-primary',
                onclick: (e) => busy(e.currentTarget, actions.resolveRequest(req, p, true)),
            }, ACCEPT_LABEL[req.type] ?? 'Annehmen')];
            if (REJECT_LABEL[req.type]) {
                buttons.push(h('button', {
                    type: 'button',
                    class: 'btn btn-sm',
                    onclick: (e) => busy(e.currentTarget, actions.resolveRequest(req, p, false)),
                }, REJECT_LABEL[req.type]));
            }

            return h('li', { class: `pult-request pult-request-${req.type}` },
                h('div', { class: 'pult-request-text' },
                    h('button', { type: 'button', class: 'pult-link', onclick: () => handlers.onDetail(p.id) }, p.name),
                    h('span', {}, ` ${REQUEST_LABELS[req.type] ?? req.type}`),
                    req.created_at_ms ? h('span', { class: 'text-faint text-sm' }, ` ${ago(now - req.created_at_ms)}`) : null),
                h('div', { class: 'pult-request-buttons' }, buttons));
        }));
    }

    return { el, update };
}
