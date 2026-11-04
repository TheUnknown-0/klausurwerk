/**
 * Live-Wand: je Teilnahme eine Karte mit dem Bildschirmbild (wenn freigegeben) und/oder der Live-Ansicht
 * der Klausurseite (Aufgabe, Code um die Cursorzeile, Konsole). Klick auf eine Karte öffnet die Großansicht
 * und setzt den Fokus, damit der Rechner der Person etwa jede Sekunde ein großes Bild schickt.
 *
 * Abfrage nur, solange die Wand sichtbar ist und der Tab im Vordergrund steht. Bilder werden als normale
 * <img> mit ?t=ts_ms geladen (keine Object-URLs, nichts zum Freigeben) und nur bei neuem ts_ms ersetzt.
 */
import { get, post } from '../lib/api.js';
import { h, icon } from '../lib/dom.js';
import { panelDialog } from './dialogs.js';
import {
    ageLevel, ageNow, ageText, CARD_CODE_LINES, CARD_CONSOLE_LINES, chooseView, consoleRows, excerptRows,
    FOCUS_CODE_LINES, FOCUS_CONSOLE_LINES, FOCUS_IMAGE_MS, FOCUS_RENEW_MS, POLL_MS, screenChanged, screenUrl,
    surfaceWarning, viewTitle,
} from './livewall-logic.js';
import { STATE_LABELS } from './util.js';

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

/** Code-Ausschnitt mit Zeilennummern und markierter Cursorzeile. */
function codeBlock(rows, className = '') {
    const pre = h('div', { class: `pult-wall-code ${className}`.trim(), role: 'group', 'aria-label': 'Code-Ausschnitt' });
    for (const row of rows) {
        pre.append(h('div', { class: `pult-wall-line${row.cursor ? ' is-cursor' : ''}` },
            h('span', { class: 'pult-wall-no', 'aria-hidden': 'true' }, String(row.no)),
            h('span', { class: 'pult-wall-text' }, row.text === '' ? ' ' : row.text)));
    }
    return pre;
}

function consoleBlock(lines, className = '') {
    return h('pre', { class: `pult-wall-console ${className}`.trim(), 'aria-label': 'Letzte Konsolenzeilen' }, lines.join('\n'));
}

export function createLiveWall(store, handlers = {}) {
    const { state } = store;
    const { boot } = state;

    let visible = false;
    let timer = null;
    let running = false;
    let generation = 0;
    let data = new Map(); // participation id -> { live, live_age_ms, screen, screen_age_ms }
    let settings = null; // { live_view, screen_share }
    let receivedAt = Date.now();
    let failed = false;
    let taskNames = new Map();
    let taskNamesState = 'idle'; // idle | loading | done
    let focusPid = null;
    let focusView = null;
    let focusTimer = null;

    const cards = new Map(); // participation id -> Element

    // ---- Gerüst ----
    const notice = h('div', { class: 'alert alert-info pult-wall-notice', role: 'status', hidden: true });
    const error = h('div', { class: 'alert alert-warning pult-wall-notice', role: 'alert', hidden: true });
    const grid = h('div', { class: 'pult-wall-grid' });
    const empty = h('p', { class: 'text-soft pult-wall-empty', hidden: true }, 'Noch niemand beigetreten.');
    const el = h('section', { class: 'pult-wall card', 'aria-label': 'Live-Wand', hidden: true }, notice, error, grid, empty);

    // ---- Karten ----
    function createCard(pid) {
        const refs = {};
        const card = h('div', { class: 'pult-tile pult-wall-card', role: 'button', tabindex: 0 });
        refs.name = h('span', { class: 'pult-tile-name' });
        refs.alarm = h('span', { class: 'pult-tile-alarm', hidden: true }, icon('triangle-alert'), 'Alarm');
        refs.stateIcon = h('span', { class: 'pult-tile-state-icon', 'aria-hidden': 'true' });
        refs.stateText = h('span', { class: 'pult-tile-state-text' });
        refs.state = h('span', { class: 'pult-tile-state' }, refs.stateIcon, refs.stateText);
        refs.share = h('span', { class: 'pult-wall-share', hidden: true });
        refs.media = h('div', { class: 'pult-wall-media', hidden: true });
        refs.img = h('img', { class: 'pult-wall-img', alt: '', loading: 'lazy', decoding: 'async', draggable: 'false' });
        refs.img.addEventListener('error', () => {
            refs.img.dataset.broken = refs.img.dataset.ts ?? '';
            refs.img.hidden = true;
        });
        refs.img.addEventListener('load', () => { refs.img.hidden = false; });
        refs.media.append(refs.img);
        refs.code = h('div', { class: 'pult-wall-codewrap' });
        refs.title = h('span', { class: 'pult-wall-viewtitle' });
        refs.age = h('span', { class: 'pult-wall-age' });
        refs.stale = h('span', { class: 'pult-wall-stale', hidden: true }, icon('triangle-alert'), 'veraltet');
        refs.foot = h('div', { class: 'pult-tile-row pult-wall-foot' }, refs.title, refs.age);

        card.append(
            h('div', { class: 'pult-tile-head' }, refs.name, refs.alarm),
            h('div', { class: 'pult-tile-row' }, refs.state, refs.share),
            refs.media, refs.code, refs.foot, refs.stale,
        );
        card.addEventListener('click', () => openFocus(pid));
        card.addEventListener('keydown', (e) => {
            if (e.target !== card) return;
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openFocus(pid);
            }
        });
        card._refs = refs;
        card._pid = pid;
        return card;
    }

    function setText(node, text) {
        if (node.textContent !== text) node.textContent = text;
    }

    /** Setzt das Bild nur bei neuem ts_ms. */
    function applyImage(img, pid, screen) {
        const ts = screen ? String(screen.ts_ms) : '';
        if (img.dataset.ts === ts) return;
        if (!screenChanged(img.dataset.ts ? Number(img.dataset.ts) : null, screen)) return;
        img.dataset.ts = ts;
        if (!screen) {
            img.removeAttribute('src');
            img.hidden = true;
            return;
        }
        img.removeAttribute('data-broken');
        img.src = screenUrl(boot.api_base, pid, screen.ts_ms);
    }

    function entryOf(pid) {
        return data.get(pid) ?? { live: null, live_age_ms: null, screen: null, screen_age_ms: null };
    }

    function ageOf(ms) {
        return ageNow(ms, receivedAt, Date.now());
    }

    function updateCard(card, p) {
        const r = card._refs;
        const entry = entryOf(p.id);
        const mode = settings ? chooseView(entry, settings) : 'empty';
        const alarm = state.alarmUntil.get(p.id) > Date.now();
        const className = `pult-tile pult-wall-card state-${p.state}${alarm ? ' is-alarm' : ''}${!p.online && p.state !== 'submitted' && p.last_seen_ms_ago !== null ? ' is-offline' : ''}`;
        if (card.className !== className) card.className = className;
        card.setAttribute('aria-label', `${p.name}, ${STATE_LABELS[p.state] ?? p.state}, Großansicht öffnen`);
        setText(r.name, p.name);
        r.name.title = p.name;
        r.alarm.hidden = !alarm;
        const stateIcon = STATE_ICONS[p.state] ?? 'info';
        if (r.stateIcon._key !== stateIcon) {
            r.stateIcon._key = stateIcon;
            r.stateIcon.replaceChildren(icon(stateIcon));
        }
        setText(r.stateText, STATE_LABELS[p.state] ?? p.state);

        // Bildschirmfreigabe: nur kennzeichnen, wenn sie verlangt wird.
        const warn = surfaceWarning(entry.screen);
        const shareText = !settings?.screen_share ? '' : (entry.screen ? (warn || 'Bildschirm') : 'kein Bild');
        const shareBad = settings?.screen_share && (!entry.screen || warn !== '');
        setText(r.share, shareText);
        r.share.hidden = shareText === '';
        r.share.className = `pult-wall-share${shareBad ? ' is-bad' : ''}`;
        r.share.title = shareBad ? 'Keine gültige Bildschirmfreigabe (ganzer Bildschirm)' : 'Standbild des ganzen Bildschirms';

        // Bild
        const showImage = mode === 'screen' || mode === 'both';
        r.media.hidden = !showImage;
        applyImage(r.img, p.id, showImage ? entry.screen : null);
        r.media.classList.toggle('is-stale', showImage && ageLevel(ageOf(entry.screen_age_ms)) === 'stale');

        // Code und Konsole (kleiner, wenn ein Bild da ist)
        const live = mode === 'code' || mode === 'both' ? entry.live : null;
        const codeKey = live ? `${mode}:${live.received_ms}` : `${mode}:`;
        if (r.code._key !== codeKey) {
            r.code._key = codeKey;
            r.code.replaceChildren();
            if (live) {
                const compact = mode === 'both';
                const rows = excerptRows(live, compact ? 3 : CARD_CODE_LINES);
                const lines = consoleRows(live, compact ? 1 : CARD_CONSOLE_LINES);
                if (rows.length > 0) r.code.append(codeBlock(rows, compact ? 'is-compact' : ''));
                if (lines.length > 0) r.code.append(consoleBlock(lines, compact ? 'is-compact' : ''));
                if (rows.length === 0 && lines.length === 0) {
                    r.code.append(h('p', { class: 'pult-wall-none' }, 'Kein Code in dieser Ansicht.'));
                }
            } else if (mode === 'disabled') {
                r.code.append(h('p', { class: 'pult-wall-none' }, 'Live-Ansicht ausgeschaltet.'));
            } else if (mode === 'empty') {
                r.code.append(h('p', { class: 'pult-wall-none' }, p.state === 'waiting' || p.state === 'absent' ? 'Noch keine Daten.' : 'Wartet auf Daten …'));
            }
        }
        r.code.hidden = r.code.childElementCount === 0;

        setText(r.title, entry.live && (mode === 'code' || mode === 'both') ? viewTitle(entry.live, taskNames) : '');
        r.title.title = r.title.textContent;
        updateCardAge(card);
    }

    /** Alter und Kennzeichnung "veraltet" (jede Sekunde ohne neuen Abruf). */
    function updateCardAge(card) {
        const r = card._refs;
        const entry = entryOf(card._pid);
        const ms = ageOf(entry.live_age_ms ?? entry.screen_age_ms);
        // Das jüngere von beiden zählt.
        const both = [ageOf(entry.live_age_ms), ageOf(entry.screen_age_ms)].filter((v) => v !== null);
        const youngest = both.length > 0 ? Math.min(...both) : ms;
        const level = ageLevel(youngest);
        setText(r.age, youngest === null ? '' : ageText(youngest));
        r.age.hidden = youngest === null;
        r.stale.hidden = level !== 'stale';
        card.classList.toggle('is-wall-stale', level === 'stale');
    }

    // ---- Abfrage ----
    function active() {
        return visible && document.visibilityState === 'visible';
    }

    function schedule(delay) {
        clearTimeout(timer);
        timer = null;
        if (!active()) return;
        timer = setTimeout(poll, delay);
    }

    async function poll() {
        timer = null;
        if (!active() || running) return;
        running = true;
        const gen = generation;
        try {
            const res = await get(`${boot.api_base}/live`, { timeoutMs: 6000 });
            if (gen !== generation) return;
            if (res.ok && res.data?.data) {
                const body = res.data.data;
                data = new Map((body.participants ?? []).map((e) => [e.participation_id, e]));
                settings = body.settings ?? { live_view: true, screen_share: false };
                receivedAt = Date.now();
                failed = false;
                render();
                updateFocus();
            } else {
                failed = true;
                error.textContent = res.status === 403
                    ? 'Für die Live-Wand fehlt die Berechtigung zum Überwachen.'
                    : `Die Live-Daten konnten nicht geladen werden (${res.error ?? 'Fehler'}). Es wird weiter versucht.`;
                error.hidden = false;
            }
        } finally {
            running = false;
            if (gen === generation) schedule(focusPid !== null ? FOCUS_IMAGE_MS : POLL_MS);
        }
    }

    async function loadTaskNames() {
        if (taskNamesState !== 'idle' || state.participants.length === 0) return;
        taskNamesState = 'loading';
        const res = await get(`${boot.api_base}/antworten/${state.participants[0].id}`, { timeoutMs: 12000 });
        if (res.ok && Array.isArray(res.data?.tasks)) {
            const map = new Map();
            const walk = (list) => list.forEach((t) => {
                map.set(t.id, `${t.number ?? ''} ${t.title ?? ''}`.trim() || `Aufgabe ${t.id}`);
                walk(t.children ?? []);
            });
            walk(res.data.tasks);
            taskNames = map;
            taskNamesState = 'done';
            render();
        } else {
            taskNamesState = 'idle';
        }
    }

    // ---- Darstellung ----
    function render() {
        if (!visible) return;
        const people = [...state.participants].sort((a, b) => a.name.localeCompare(b.name, 'de'));
        const ids = new Set(people.map((p) => p.id));
        for (const [pid, card] of cards) {
            if (!ids.has(pid)) {
                card.remove();
                cards.delete(pid);
            }
        }
        let previous = null;
        for (const p of people) {
            let card = cards.get(p.id);
            if (!card) {
                card = createCard(p.id);
                cards.set(p.id, card);
            }
            // Reihenfolge nur anfassen, wenn nötig (Fokus und Bilder bleiben erhalten).
            const expected = previous ? previous.nextElementSibling : grid.firstElementChild;
            if (expected !== card) {
                previous ? previous.after(card) : grid.prepend(card);
            }
            updateCard(card, p);
            previous = card;
        }
        empty.hidden = people.length > 0 || !state.loaded;
        error.hidden = !failed;
        if (settings && !settings.live_view) {
            notice.textContent = settings.screen_share
                ? 'Die Live-Ansicht der Klausurseite ist in dieser Klausur ausgeschaltet, siehe Einstellungen. Es erscheinen nur Bildschirmbilder.'
                : 'Die Live-Ansicht ist in dieser Klausur ausgeschaltet, siehe Einstellungen.';
            notice.append(' ', h('a', { href: `${boot.urls.exam}/einstellungen` }, 'Zu den Einstellungen'));
            notice.hidden = false;
        } else {
            notice.hidden = true;
        }
        if (taskNamesState === 'idle') loadTaskNames();
    }

    // ---- Großansicht ----
    function openFocus(pid) {
        if (focusPid !== null) return;
        const p = store.participant(pid);
        if (!p) return;
        focusPid = pid;
        const r = {};
        r.state = h('span', { class: 'badge' });
        r.share = h('span', { class: 'badge', hidden: true });
        r.age = h('span', { class: 'text-sm text-soft' });
        r.title = h('span', { class: 'pult-wall-focus-title' });
        r.img = h('img', { class: 'pult-wall-focus-img', alt: `Bildschirm von ${p.name}` });
        r.img.addEventListener('error', () => { r.img.hidden = true; });
        r.img.addEventListener('load', () => { r.img.hidden = false; });
        r.imgNote = h('p', { class: 'pult-wall-none' });
        r.code = h('div', { class: 'pult-wall-focus-code' });
        r.console = h('div', { class: 'pult-wall-focus-console' });
        const details = h('button', { type: 'button', class: 'btn btn-sm', onclick: () => { panel.close(); handlers.onDetail?.(pid); } }, icon('search'), ' Details und Antworten');
        const body = h('div', { class: 'pult-wall-focus' },
            h('div', { class: 'pult-wall-focus-head' }, r.state, r.share, r.title, r.age, details),
            h('div', { class: 'pult-wall-focus-media' }, r.img, r.imgNote),
            h('div', { class: 'pult-wall-focus-cols' }, r.code, r.console));
        const panel = panelDialog(`Live: ${p.name}`, () => {
            clearInterval(focusTimer);
            focusTimer = null;
            focusPid = null;
            focusView = null;
            post(`${boot.api_base}/fokus`, { participation_id: null }, { timeoutMs: 5000 });
            schedule(POLL_MS);
        });
        panel.dialog.classList.add('pult-wall-dialog');
        panel.body.append(body);
        focusView = { r, p, pid };
        post(`${boot.api_base}/fokus`, { participation_id: pid }, { timeoutMs: 5000 });
        focusTimer = setInterval(() => {
            // Bei verstecktem Tab nicht erneuern: der Fokus läuft nach 30 s aus, der Schülerrechner sendet dann wieder klein.
            if (!active()) return;
            post(`${boot.api_base}/fokus`, { participation_id: pid }, { timeoutMs: 5000 });
        }, FOCUS_RENEW_MS);
        updateFocus();
        schedule(0);
    }

    function updateFocus() {
        if (!focusView) return;
        const { r, pid } = focusView;
        const p = store.participant(pid);
        if (!p) return;
        const entry = entryOf(pid);
        r.state.textContent = STATE_LABELS[p.state] ?? p.state;
        r.state.className = `badge ${{ active: 'badge-success', locked: 'badge-danger', paused: 'badge-warning', submit_requested: 'badge-accent', device_pending: 'badge-warning' }[p.state] ?? ''}`;
        const warn = surfaceWarning(entry.screen);
        r.share.hidden = !settings?.screen_share;
        r.share.textContent = entry.screen ? (warn || 'Ganzer Bildschirm') : 'Kein Bild';
        r.share.className = `badge ${entry.screen && !warn ? 'badge-success' : 'badge-danger'}`;
        const mode = settings ? chooseView(entry, settings) : 'empty';
        const showImage = mode === 'screen' || mode === 'both';
        applyImage(r.img, pid, showImage ? entry.screen : null);
        r.img.parentElement.classList.toggle('is-empty', !showImage);
        r.imgNote.hidden = showImage;
        r.imgNote.textContent = settings?.screen_share
            ? 'Kein Bildschirmbild, die Person hat die Freigabe nicht erteilt oder beendet.'
            : 'In dieser Klausur ist keine Bildschirmfreigabe verlangt. Es gibt nur die Live-Ansicht der Klausurseite.';
        const live = settings?.live_view ? entry.live : null;
        const key = live ? String(live.received_ms) : '';
        if (r.code._key !== key) {
            r.code._key = key;
            r.code.replaceChildren(h('h4', {}, 'Code', live?.file ? h('span', { class: 'text-faint text-sm' }, ` ${live.file}`) : null));
            r.console.replaceChildren(h('h4', {}, 'Konsole'));
            if (live) {
                const rows = excerptRows(live, FOCUS_CODE_LINES);
                const lines = consoleRows(live, FOCUS_CONSOLE_LINES);
                r.code.append(rows.length ? codeBlock(rows, 'is-large') : h('p', { class: 'pult-wall-none' }, 'Kein Code in dieser Ansicht.'));
                r.console.append(lines.length ? consoleBlock(lines, 'is-large') : h('p', { class: 'pult-wall-none' }, 'Keine Ausgabe.'));
            } else {
                r.code.append(h('p', { class: 'pult-wall-none' }, settings?.live_view ? 'Noch keine Daten.' : 'Live-Ansicht ausgeschaltet.'));
            }
            r.title.textContent = live ? viewTitle(live, taskNames) : '';
        }
        const ages = [ageOf(entry.live_age_ms), ageOf(entry.screen_age_ms)].filter((v) => v !== null);
        const youngest = ages.length ? Math.min(...ages) : null;
        r.age.textContent = youngest === null ? '' : `${ageText(youngest)}${ageLevel(youngest) === 'stale' ? ' (veraltet)' : ''}`;
        r.age.className = `text-sm ${ageLevel(youngest) === 'stale' ? 'text-danger' : 'text-soft'}`;
    }

    // ---- Steuerung von außen ----
    function setVisible(value) {
        if (visible === value) return;
        visible = value;
        el.hidden = !value;
        generation += 1;
        running = false;
        clearTimeout(timer);
        if (value) {
            render();
            schedule(0);
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (active() && focusPid !== null) {
            post(`${boot.api_base}/fokus`, { participation_id: focusPid }, { timeoutMs: 5000 });
        }
        if (active()) schedule(0);
        else clearTimeout(timer);
    });
    // Beim Verlassen der Seite den Fokus aufheben (Server lässt ihn sonst 30 s stehen).
    window.addEventListener('pagehide', () => {
        if (focusPid !== null) {
            post(`${boot.api_base}/fokus`, { participation_id: null }, { keepalive: true, timeoutMs: 3000 });
        }
    });

    return {
        el,
        setVisible,
        /** Neuer Stand vom Server (Zustände, Alarme). */
        update() {
            if (visible) {
                render();
                updateFocus();
            }
        },
        /** Sekundentakt: Altersanzeige. */
        tick() {
            if (!visible) return;
            for (const card of cards.values()) updateCardAge(card);
            updateFocus();
        },
    };
}
