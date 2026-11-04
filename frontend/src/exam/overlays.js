/**
 * Vollflächige Hinweise der Schüleransicht: Sperrbildschirme je Zustand,
 * "Klicke, um zu beginnen", "Weiter im Vollbild", zweiter Tab.
 * Jede Funktion liefert { el, focusEl } ohne selbst etwas anzuhängen.
 */
import { h } from '../lib/dom.js';
import { url, csrfToken } from '../lib/api.js';

let counter = 0;

function frame(kind, icon, title, bodyNodes, actions = []) {
    const titleId = `ov-title-${++counter}`;
    const card = h('div', { class: `exam-overlay-card ov-${kind}` },
        h('div', { class: 'ov-icon', 'aria-hidden': 'true' }, icon),
        h('h2', { id: titleId, class: 'ov-title' }, title),
        h('div', { class: 'ov-body' }, ...bodyNodes),
        actions.length > 0 ? h('div', { class: 'ov-actions' }, ...actions) : null);
    const el = h('div', { class: 'exam-overlay', role: 'alertdialog', 'aria-modal': 'true', 'aria-labelledby': titleId, dataset: { kind } }, card);
    const focusEl = actions.find((a) => a.tagName === 'BUTTON') ?? null;
    return { el, focusEl };
}

const button = (label, handler, cls = 'btn btn-primary btn-lg', disabled = false) => {
    const b = h('button', { type: 'button', class: cls }, label);
    b.disabled = disabled;
    b.addEventListener('click', handler);
    return b;
}

const clockTime = (ms) => new Date(ms).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });

export const overlays = {
    /** Verstoß (lokal sofort) bzw. vom Server gemeldete Sperre. */
    locked({ message, reason, requested, onRequest }) {
        const body = [];
        if (message) {
            body.push(h('p', { class: 'ov-strong' }, message));
        }
        if (reason) {
            body.push(h('p', {}, 'Grund: ', h('strong', {}, reason)));
        }
        body.push(h('p', {}, 'Warte, bis die Lehrkraft dich freigibt. Deine Antworten sind gesichert.'));
        const btn = button(requested ? 'Anfrage gesendet' : 'Freigabe anfragen', onRequest, 'btn btn-primary btn-lg', requested);
        return frame('locked', '🔒', 'Die Klausur ist gesperrt', body, [btn]);
    },

    absent({ requested, onRequest }) {
        const btn = button(requested ? 'Anfrage gesendet' : 'Ich bin zurück, Freigabe anfragen', onRequest, 'btn btn-primary btn-lg', requested);
        return frame('absent', '🚶', 'Du bist abgemeldet (Toilette)',
            [h('p', {}, 'Wenn du zurück bist, bitte die Lehrkraft um Freigabe.')], [btn]);
    },

    paused() {
        return frame('paused', '⏸️', 'Die Klausur ist pausiert',
            [h('p', {}, 'Die Uhr steht. Es geht weiter, sobald die Lehrkraft die Pause beendet.')]);
    },

    submitRequested({ onWithdraw }) {
        const btn = button('Abgabe zurückziehen', onWithdraw, 'btn btn-lg');
        return frame('submit', '📤', 'Abgabe wird von der Lehrkraft bestätigt',
            [h('p', {}, 'Bleib bitte sitzen. Du kannst nicht mehr schreiben, bis die Lehrkraft entschieden hat.')], [btn]);
    },

    devicePending() {
        return frame('device', '💻', 'Warte auf Freigabe für dieses Gerät',
            [h('p', {}, 'Du hast dich an einem anderen Gerät angemeldet. Die Lehrkraft muss den Wechsel bestätigen.')]);
    },

    submitted({ at, seb }) {
        const actions = [];
        if (seb) {
            actions.push(h('a', { class: 'btn btn-primary btn-lg', href: url('/seb-beenden') }, 'Safe Exam Browser beenden'));
        }
        const form = h('form', { method: 'post', action: url('/logout'), class: 'ov-logout' },
            h('input', { type: 'hidden', name: '_csrf', value: csrfToken() }),
            h('button', { type: 'submit', class: seb ? 'btn btn-lg' : 'btn btn-primary btn-lg' }, 'Abmelden'));
        actions.push(form);
        const body = [h('p', { class: 'ov-strong' }, at ? `Deine Abgabe wurde um ${clockTime(at)} Uhr bestätigt.` : 'Deine Abgabe wurde bestätigt.'),
            h('p', {}, 'Du kannst dich jetzt abmelden und den Raum nach Anweisung der Lehrkraft verlassen.')];
        const r = frame('submitted', '✅', 'Abgegeben', body, []);
        r.el.querySelector('.exam-overlay-card').append(h('div', { class: 'ov-actions' }, ...actions));
        r.focusEl = r.el.querySelector('a, button');
        return r;
    },

    /** Geste nötig (Vollbild, Ton): erster Start oder nach Freigabe. */
    resume({ first, fullscreen, onClick }) {
        const btn = button(first ? 'Klicke, um zu beginnen' : 'Weiter im Vollbild', onClick, 'btn btn-primary btn-lg');
        const text = first
            ? 'Die Klausur läuft. Mit einem Klick startest du im Vollbild und schaltest den Ton frei.'
            : 'Du bist freigegeben. Mit einem Klick geht es im Vollbild weiter.';
        return frame('resume', first ? '▶️' : '🖥️', first ? 'Es geht los' : 'Weiter im Vollbild',
            [h('p', {}, fullscreen ? text : 'Mit einem Klick geht es weiter.')], [btn]);
    },

    secondTab({ onRetry }) {
        const btn = button('Erneut prüfen', onRetry, 'btn btn-lg');
        return frame('second', '🗂️', 'Die Klausur ist schon in einem anderen Tab geöffnet',
            [h('p', {}, 'Schließe diesen Tab und arbeite im ersten Tab weiter. Die Lehrkraft wurde benachrichtigt.')], [btn]);
    },
};
