/**
 * Kachel einer Person im Sitzplan. Elemente werden einmal erzeugt und bei jedem Abruf gezielt
 * aktualisiert, damit Fokus, Drag-Zustand und Auswahl erhalten bleiben.
 */
import { h, icon } from '../lib/dom.js';
import { ago, remaining, REQUEST_LABELS, STATE_LABELS } from './util.js';

/** Symbol je Zustand: Farbe allein trägt die Information nicht, dazu Icon und Wort. */
const STATE_ICONS = {
    waiting: 'hourglass',
    active: 'pencil',
    locked: 'lock',
    absent: 'door-open',
    paused: 'pause',
    submit_requested: 'send',
    submitted: 'circle-check',
    device_pending: 'monitor',
};

export function createTile(handlers) {
    const refs = {};
    const el = h('div', {
        class: 'pult-tile',
        role: 'button',
        tabindex: 0,
        draggable: 'true',
        'aria-pressed': 'false',
    });
    refs.name = h('span', { class: 'pult-tile-name' });
    refs.hand = h('span', { class: 'pult-tile-hand', title: 'Hand gehoben', hidden: true }, icon('hand'), h('span', { class: 'sr-only' }, 'Hand gehoben'));
    refs.alarm = h('span', { class: 'pult-tile-alarm', title: 'Alarm', hidden: true }, icon('triangle-alert'), 'Alarm');
    refs.detail = h('button', { type: 'button', class: 'pult-tile-detail', 'aria-label': 'Details öffnen', title: 'Details' }, icon('search'));
    refs.stateIcon = h('span', { class: 'pult-tile-state-icon', 'aria-hidden': 'true' });
    refs.stateText = h('span', { class: 'pult-tile-state-text' });
    refs.state = h('span', { class: 'pult-tile-state' }, refs.stateIcon, refs.stateText);
    refs.icons = h('span', { class: 'pult-tile-icons' });
    refs.fullscreen = h('span', { class: 'pult-icon', title: 'Vollbild' }, icon('maximize'));
    refs.focus = h('span', { class: 'pult-icon', title: 'Fokus im Fenster' }, icon('eye'));
    refs.conn = h('span', { class: 'pult-icon', title: 'Verbindung' }, icon('link'));
    refs.seb = h('span', { class: 'pult-icon', title: 'Safe Exam Browser', hidden: true }, 'SEB');
    refs.icons.append(refs.fullscreen, refs.focus, refs.conn, refs.seb);
    refs.progressText = h('span', { class: 'pult-tile-progress-text' });
    refs.bar = h('span');
    refs.progress = h('div', { class: 'progress pult-tile-progress', 'aria-hidden': 'true' }, refs.bar);
    refs.time = h('span', { class: 'pult-tile-time', title: 'Restzeit' });
    refs.saved = h('span', { class: 'pult-tile-saved', title: 'Zuletzt gespeichert' });
    refs.requests = h('div', { class: 'pult-tile-requests' });

    el.append(
        h('div', { class: 'pult-tile-head' }, refs.name, refs.hand, refs.detail),
        refs.alarm,
        h('div', { class: 'pult-tile-row' }, refs.state),
        h('div', { class: 'pult-tile-row' }, refs.progressText, refs.icons, refs.time),
        refs.progress,
        h('div', { class: 'pult-tile-row pult-tile-foot' }, refs.saved),
        refs.requests,
    );

    el.addEventListener('click', (e) => {
        if (e.target.closest('.pult-tile-detail')) {
            return;
        }
        handlers.onSelect(el._pid, e.ctrlKey || e.metaKey || e.shiftKey);
    });
    el.addEventListener('keydown', (e) => {
        if (e.target !== el) {
            return;
        }
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            handlers.onSelect(el._pid, e.ctrlKey || e.metaKey || e.shiftKey);
        } else if (e.key.toLowerCase() === 'd') {
            handlers.onDetail(el._pid);
        } else if (e.altKey && e.key.startsWith('Arrow')) {
            e.preventDefault();
            handlers.onMove?.(el._pid, e.key);
        }
    });
    el.addEventListener('dblclick', (e) => {
        e.preventDefault();
        handlers.onDetail(el._pid);
    });
    refs.detail.addEventListener('click', (e) => {
        e.stopPropagation();
        handlers.onDetail(el._pid);
    });
    el.addEventListener('dragstart', (e) => {
        e.dataTransfer?.setData('text/plain', `p:${el._pid}`);
        if (e.dataTransfer) {
            e.dataTransfer.effectAllowed = 'move';
        }
        el.classList.add('is-dragging');
    });
    el.addEventListener('dragend', () => el.classList.remove('is-dragging'));

    el._refs = refs;
    el._last = {};

    return el;
}

/** Setzt Inhalt (Icon plus Text) nur, wenn er sich geändert hat. */
function setRich(node, key, iconName, text) {
    if (node._key === key) {
        return;
    }
    node._key = key;
    node.replaceChildren(...(iconName ? [icon(iconName), ' '] : []), text);
}

/** Setzt Text nur, wenn er sich geändert hat. */
function setText(node, text) {
    if (node.textContent !== text) {
        node.textContent = text;
    }
}

/**
 * Aktualisiert eine Kachel.
 *
 * @param {HTMLElement} el
 * @param {object} p Teilnehmer aus dem Stand
 * @param {{selected: boolean, alarm: boolean, nowMs: number, receivedAt: number, total: number}} view
 */
export function updateTile(el, p, view) {
    const r = el._refs;
    el._pid = p.id;
    el.dataset.pid = String(p.id);
    el.dataset.userId = String(p.user_id);

    const cs = p.client_state ?? {};
    const offline = !p.online && p.state !== 'submitted' && p.last_seen_ms_ago !== null;
    const classes = ['pult-tile', `state-${p.state}`];
    if (offline) classes.push('is-offline');
    if (view.alarm) classes.push('is-alarm');
    if (view.selected) classes.push('is-selected');
    if (p.hand_raised) classes.push('has-hand');
    if (p.open_requests.length > 0) classes.push('has-requests');
    if (el.classList.contains('is-dragging')) classes.push('is-dragging');
    const className = classes.join(' ');
    if (el.className !== className) {
        el.className = className;
    }
    el.setAttribute('aria-pressed', view.selected ? 'true' : 'false');
    el.setAttribute('aria-label', `${p.name}, ${STATE_LABELS[p.state] ?? p.state}${offline ? ', offline' : ''}`);

    setText(r.name, p.name);
    r.name.title = p.name;
    r.hand.hidden = !p.hand_raised;
    r.alarm.hidden = !view.alarm;
    const stateIconName = STATE_ICONS[p.state] ?? 'info';
    if (r.stateIcon._key !== stateIconName) {
        r.stateIcon._key = stateIconName;
        r.stateIcon.replaceChildren(icon(stateIconName));
    }
    setText(r.stateText, p.state === 'locked' && p.lock_reason ? `${STATE_LABELS.locked}: ${p.lock_reason}` : (STATE_LABELS[p.state] ?? p.state));
    r.state.title = r.stateText.textContent;

    const fullscreenKnown = 'fullscreen' in cs && p.state !== 'waiting';
    r.fullscreen.className = `pult-icon ${fullscreenKnown ? (cs.fullscreen ? 'ok' : 'bad') : 'unknown'}`;
    r.fullscreen.title = fullscreenKnown ? (cs.fullscreen ? 'Vollbild aktiv' : 'Nicht im Vollbild') : 'Vollbild unbekannt';
    const focusKnown = 'focused' in cs && p.state !== 'waiting';
    const focusOk = cs.focused !== false && cs.visible !== false;
    r.focus.className = `pult-icon ${focusKnown ? (focusOk ? 'ok' : 'bad') : 'unknown'}`;
    r.focus.title = focusKnown ? (focusOk ? 'Fenster im Fokus' : 'Fenster nicht im Fokus') : 'Fokus unbekannt';
    r.conn.className = `pult-icon ${p.online ? 'ok' : (p.last_seen_ms_ago === null ? 'unknown' : 'bad')}`;
    r.conn.title = p.online ? 'Online' : (p.last_seen_ms_ago === null ? 'Noch kein Herzschlag' : `Offline (${ago(p.last_seen_ms_ago + (view.nowMs - view.receivedAt))})`);
    r.seb.hidden = !cs.seb;

    const { answered, flagged, total } = p.progress;
    const progressKey = `${answered}/${total || view.total}:${flagged}`;
    if (r.progressText._key !== progressKey) {
        r.progressText._key = progressKey;
        r.progressText.replaceChildren(`${answered}/${total || view.total}`,
            ...(flagged > 0 ? [' ', h('span', { class: 'pult-flagged' }, icon('flag'), String(flagged))] : []));
    }
    r.progressText.title = `${answered} von ${total || view.total} Aufgaben bearbeitet, ${flagged} markiert`;
    const percent = (total || view.total) > 0 ? Math.round((answered / (total || view.total)) * 100) : 0;
    r.bar.style.width = `${percent}%`;

    r.requests.replaceChildren(...p.open_requests
        .filter((req) => req.type !== 'hand')
        .map((req) => h('span', { class: `pult-chip pult-chip-${req.type}` }, REQUEST_LABELS[req.type] ?? req.type)));

    el._data = p;
    el._view = view;
    updateTileClock(el);
}

/** Restzeit und "zuletzt gespeichert" (läuft jede Sekunde ohne neuen Abruf). */
export function updateTileClock(el) {
    const p = el._data;
    const view = el._view;
    if (!p || !view) {
        return;
    }
    const r = el._refs;
    const sinceStand = Math.max(0, Date.now() - view.receivedAt);
    const clock = p.clock;

    let time = '';
    let timeIcon = null;
    let timeClass = 'pult-tile-time';
    if (p.state === 'submitted') {
        time = 'abgegeben';
    } else if (clock.unlimited) {
        time = 'ohne Limit';
    } else if (clock.remaining_ms === null || clock.ends_at_ms === null) {
        time = '';
    } else if (clock.paused) {
        time = remaining(clock.remaining_ms);
        timeIcon = 'pause';
    } else {
        const left = clock.ends_at_ms - view.nowMs - sinceStand;
        if (left <= 0) {
            time = 'Zeit um';
            timeClass += ' is-over';
        } else {
            time = remaining(left);
            if (left < 5 * 60000) timeClass += ' is-low';
        }
    }
    if (clock.extra_minutes !== 0 || clock.accommodation_percent > 0) {
        r.time.title = `Restzeit (Zusatzzeit ${clock.extra_minutes} min, Nachteilsausgleich ${clock.accommodation_percent} %)`;
    }
    setRich(r.time, `${timeIcon}:${time}`, timeIcon, time);
    if (r.time.className !== timeClass) {
        r.time.className = timeClass;
    }

    if (p.last_save_ms_ago === null) {
        setRich(r.saved, `none:${p.state}`, null, p.state === 'waiting' ? '' : 'noch nichts gespeichert');
    } else {
        const savedText = `gespeichert ${ago(p.last_save_ms_ago + sinceStand)}`;
        setRich(r.saved, `${p.pending}:${savedText}`, p.pending ? 'hourglass' : null, savedText);
    }
}

/** Platzhalter-Kachel für Personen, die noch nicht beigetreten sind. */
export function createGhost(person, handlers) {
    const el = h('div', {
        class: 'pult-tile pult-ghost',
        draggable: 'true',
        tabindex: 0,
        role: 'group',
        'aria-label': `${person.name}, noch nicht beigetreten`,
    },
    h('div', { class: 'pult-tile-head' }, h('span', { class: 'pult-tile-name', title: person.name }, person.name)),
    h('div', { class: 'pult-tile-row' }, h('span', { class: 'pult-tile-state' }, h('span', { class: 'pult-tile-state-icon', 'aria-hidden': 'true' }, icon('armchair')), h('span', { class: 'pult-tile-state-text' }, 'Nicht beigetreten'))));
    el.dataset.userId = String(person.user_id);
    el.addEventListener('dragstart', (e) => {
        e.dataTransfer?.setData('text/plain', `u:${person.user_id}`);
        if (e.dataTransfer) {
            e.dataTransfer.effectAllowed = 'move';
        }
        el.classList.add('is-dragging');
    });
    el.addEventListener('dragend', () => el.classList.remove('is-dragging'));
    el.addEventListener('keydown', (e) => {
        if (e.altKey && e.key.startsWith('Arrow')) {
            e.preventDefault();
            handlers.onMoveGhost?.(person.user_id, e.key);
        }
    });

    return el;
}
