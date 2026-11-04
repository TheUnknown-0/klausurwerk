/**
 * Richtige Lösung für Lehrkräfte in lesbarer Form (nur Korrekturansicht, nie Schüleransicht):
 * Auswahl, Zuordnung, Reihenfolge und Lücken. Texte der Optionen kommen als bereits bereinigtes HTML vom Server.
 */
import { h, setTrustedHtml } from '../lib/dom.js';

const itemText = (item) => {
    const span = h('span', { class: 'g-sol-text' });
    if (typeof item?.text_html === 'string' && item.text_html !== '') {
        setTrustedHtml(span, item.text_html);
    } else {
        span.textContent = String(item?.text_md ?? item?.id ?? '');
    }
    return span;
};

/** @returns {HTMLElement|null} */
export function solutionView(task) {
    const solution = task.solution ?? {};
    const config = task.config ?? {};
    const byId = (list) => new Map((Array.isArray(list) ? list : []).map((i) => [String(i.id), i]));

    switch (task.answer_type) {
        case 'choice': {
            const options = byId(config.options);
            const correct = (solution.correct ?? []).map(String);
            if (correct.length === 0) return null;
            return h('div', { class: 'g-solution' },
                h('p', { class: 'text-sm text-soft' }, `Richtig ${correct.length === 1 ? 'ist' : 'sind'}${solution.scoring === 'partial' ? ' (Teilpunkte)' : ''}:`),
                h('ul', {}, correct.map((id) => h('li', {}, itemText(options.get(id) ?? { id })))));
        }
        case 'matching': {
            const left = byId(config.left);
            const right = byId(config.right);
            const pairs = Object.entries(solution.pairs ?? {});
            if (pairs.length === 0) return null;
            return h('div', { class: 'g-solution' },
                h('ul', {}, pairs.map(([l, r]) => h('li', {}, itemText(left.get(l) ?? { id: l }), ' → ', itemText(right.get(String(r)) ?? { id: r })))));
        }
        case 'ordering': {
            const items = byId(config.items);
            const order = (solution.order ?? []).map(String);
            if (order.length === 0) return null;
            return h('div', { class: 'g-solution' },
                h('ol', {}, order.map((id) => h('li', {}, itemText(items.get(id) ?? { id })))));
        }
        case 'gaps': {
            const gaps = Object.entries(solution.gaps ?? {});
            if (gaps.length === 0) return null;
            return h('div', { class: 'g-solution' },
                h('ul', {}, gaps.map(([id, g]) => h('li', {},
                    h('strong', {}, `${id}: `),
                    (g.accepted ?? []).map((a) => String(a)).join('  oder  '),
                    g.regex ? h('span', { class: 'text-faint text-sm' }, `  (Muster ${g.regex})`) : null))));
        }
        default:
            return null;
    }
}
