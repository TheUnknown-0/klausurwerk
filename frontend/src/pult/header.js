/**
 * Kopf des Pults: Klausurstatus, Uhr, Steuerknöpfe je Status, Stummschalter, Zähler.
 */
import { h, icon } from '../lib/dom.js';
import { EXAM_STATUS_LABELS, plural, remaining } from './util.js';

const STATUS_BADGE = {
    draft: 'badge',
    ready: 'badge-info',
    lobby: 'badge-info',
    running: 'badge-success',
    paused: 'badge-warning',
    finished: 'badge',
};

export function createHeader(store, actions, sound) {
    const { state } = store;
    const { boot } = state;

    const title = h('h1', { class: 'pult-title' }, boot.title);
    const badge = h('span', { class: 'badge' });
    const clock = h('div', { class: 'pult-clock', role: 'timer', 'aria-label': 'Restzeit der Klausur' });
    const clockLabel = h('span', { class: 'pult-clock-label' });
    const clockValue = h('span', { class: 'pult-clock-value' });
    clock.append(clockLabel, clockValue);
    const controls = h('div', { class: 'pult-controls' });
    const muteBtn = h('button', { type: 'button', class: 'btn btn-sm', 'aria-pressed': 'false', onclick: () => sound.setMuted(!sound.muted) });
    const links = h('div', { class: 'pult-links' },
        h('a', { class: 'btn btn-sm btn-ghost', href: boot.urls.preview, target: '_blank', rel: 'noopener' }, icon('eye'), ' Vorschau'),
        h('a', { class: 'btn btn-sm btn-ghost', href: boot.urls.exam }, icon('settings'), ' Klausur'),
    );

    const counters = h('div', { class: 'pult-counters stat-grid' });
    const counterDefs = [
        ['online', 'Online', ''],
        ['active', 'Arbeiten', 'stat-success'],
        ['locked', 'Gesperrt', 'stat-danger'],
        ['submitted', 'Abgegeben', ''],
        ['hands', 'Hände', 'stat-accent'],
    ];
    const counterEls = {};
    for (const [key, label, extra] of counterDefs) {
        const value = h('div', { class: 'stat-value' }, '0');
        counterEls[key] = value;
        counters.append(h('div', { class: `stat-card ${extra}` }, value, h('div', { class: 'stat-label' }, label)));
    }

    const el = h('header', { class: 'pult-header card' },
        h('div', { class: 'pult-header-main' },
            h('div', { class: 'pult-header-title' },
                h('span', { class: 'pult-eyebrow' }, 'Pult'),
                title, badge),
            clock,
        ),
        h('div', { class: 'pult-header-bar' },
            controls,
            h('div', { class: 'pult-header-tools' }, muteBtn, links),
        ),
        counters,
    );

    let renderedStatus = null;
    function renderControls(status) {
        if (renderedStatus === status) return;
        renderedStatus = status;
        controls.replaceChildren();
        const btn = (iconName, label, key, cls = 'btn-primary') => h('button', { type: 'button', class: `btn ${cls}`, onclick: () => actions.examAction(key) }, icon(iconName), ' ', label);
        if (status === 'draft' || status === 'ready') {
            controls.append(btn('door-open', 'Warteraum öffnen', 'open_lobby'));
        } else if (status === 'lobby') {
            controls.append(btn('play', 'Starten', 'start'));
        } else if (status === 'running') {
            controls.append(
                btn('pause', 'Alle pausieren', 'pause_all', 'btn'),
                btn('flag', 'Klausur beenden', 'finish', 'btn-danger-ghost'),
            );
        } else if (status === 'paused') {
            controls.append(
                btn('play', 'Alle fortsetzen', 'resume_all'),
                btn('flag', 'Klausur beenden', 'finish', 'btn-danger-ghost'),
            );
        }
        if (['lobby', 'running', 'paused'].includes(status)) {
            controls.append(h('button', { type: 'button', class: 'btn', onclick: () => actions.announceAll() }, icon('megaphone'), ' Durchsage an alle'));
        }
    }

    function renderMute() {
        muteBtn.replaceChildren(icon(sound.muted ? 'bell-off' : 'bell'), sound.muted ? ' Ton aus' : ' Ton an');
        muteBtn.setAttribute('aria-pressed', sound.muted ? 'true' : 'false');
        muteBtn.title = sound.muted ? 'Alarmton einschalten' : 'Alarmton stummschalten';
    }
    sound.onChange(renderMute);
    renderMute();

    function update() {
        const exam = state.exam;
        if (!exam) return;
        badge.className = `badge ${STATUS_BADGE[exam.status] ?? 'badge'}`;
        badge.textContent = EXAM_STATUS_LABELS[exam.status] ?? exam.status;
        renderControls(exam.status);

        const live = state.participants.filter((p) => p.state !== 'submitted');
        counterEls.online.textContent = `${live.filter((p) => p.online).length}/${live.length}`;
        counterEls.active.textContent = String(state.participants.filter((p) => p.state === 'active').length);
        counterEls.locked.textContent = String(state.participants.filter((p) => p.state === 'locked').length);
        counterEls.submitted.textContent = `${state.participants.filter((p) => p.state === 'submitted').length}/${state.participants.length}`;
        counterEls.hands.textContent = String(state.participants.filter((p) => p.hand_raised).length);
        counters.title = state.notJoined.length > 0 ? plural(state.notJoined.length, 'Person ist', 'Personen sind') + ' noch nicht beigetreten' : '';
        tick();
    }

    /** Uhr der Klausur (läuft jede Sekunde mit der Serverzeit). */
    function tick() {
        const exam = state.exam;
        if (!exam) return;
        let label = '';
        let value = '';
        if (exam.status === 'running' && exam.base_ends_at_ms !== null && exam.base_ends_at_ms !== undefined) {
            const left = exam.base_ends_at_ms - store.now();
            label = left > 0 ? 'Restzeit (Grundzeit)' : 'Grundzeit abgelaufen seit';
            value = remaining(Math.abs(left));
            clock.classList.toggle('is-over', left <= 0);
        } else if (exam.status === 'running' && exam.started_at_ms) {
            label = 'Läuft seit';
            value = remaining(Math.max(0, store.now() - exam.started_at_ms));
            clock.classList.remove('is-over');
        } else if (exam.status === 'paused') {
            label = 'Pausiert, Restzeit';
            value = exam.base_remaining_ms === null || exam.base_remaining_ms === undefined ? '–' : remaining(exam.base_remaining_ms);
            clock.classList.remove('is-over');
        } else if (exam.status === 'lobby') {
            label = 'Warteraum';
            value = plural(state.participants.length, 'Person', 'Personen');
        } else {
            label = EXAM_STATUS_LABELS[exam.status] ?? '';
            value = '';
        }
        if (clockLabel.textContent !== label) clockLabel.textContent = label;
        if (clockValue.textContent !== value) clockValue.textContent = value;
    }

    return { el, update, tick };
}
