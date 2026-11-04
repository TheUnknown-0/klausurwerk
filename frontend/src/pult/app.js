/**
 * Zusammenbau des Pults: Kopf, Alarme, Aktionsleiste, Sitzplan, Anfragen, Zeitstrahl, Detailansicht.
 */
import { h, icon } from '../lib/dom.js';
import { createActionBar } from './actionbar.js';
import { createActions } from './actions.js';
import { createAlarmList } from './alarms.js';
import { createDetail } from './detail.js';
import { createHeader } from './header.js';
import { createLiveWall } from './livewall.js';
import { createRequestList } from './requests.js';
import { createSeatPlan } from './seatplan.js';
import { createAlarmSound } from './sound.js';
import { createStore, startPoller } from './store.js';
import { createTimeline } from './timeline.js';
import { storage } from './util.js';

export function mountPult(root, boot) {
    const store = createStore(boot);
    const { state } = store;
    const sound = createAlarmSound();
    let poller = null;
    const refresh = () => poller?.refresh();

    const actions = createActions(store, { refresh });
    const onDetail = (id) => store.openDetail(id);

    const header = createHeader(store, actions, sound);
    const alarms = createAlarmList(store, { onDetail });
    const actionBar = createActionBar(store, actions);
    const seat = createSeatPlan(store, {
        refresh,
        onDetail,
        onSelect(id, additive) {
            if (additive) {
                store.select([id], 'toggle');
            } else if (state.selection.size === 1 && state.selection.has(id)) {
                store.clearSelection();
            } else {
                store.select([id], 'replace');
            }
        },
    });
    const requests = createRequestList(store, actions, { onDetail });
    const timeline = createTimeline(store, { onDetail });
    const detail = createDetail(store, actions);
    const wall = createLiveWall(store, { onDetail });

    // ---- Hinweise ----
    const connection = h('div', { class: 'alert alert-error pult-banner', role: 'alert', hidden: true });
    const soundBanner = h('div', { class: 'alert alert-info pult-banner pult-soundbanner', hidden: true },
        h('span', {}, icon('bell'), ' Ton aktivieren: Browser erlauben Ton erst nach einem Klick. Klicke hier oder irgendwo auf die Seite, dann klingelt das Pult bei Alarmen.'),
        h('button', { type: 'button', class: 'btn btn-sm btn-primary', onclick: () => sound.unlock().then((ok) => { if (ok) sound.play(); }) }, 'Ton aktivieren'));
    const loading = h('p', { class: 'text-soft' }, 'Der Stand wird geladen …');

    const renderBanners = () => {
        if (sound.unlocked && !soundBanner.hidden) {
            // Erst nach dem laufenden Klick ausblenden (kein Verrutschen der Knöpfe).
            setTimeout(() => { soundBanner.hidden = true; }, 250);
        } else if (!sound.unlocked) {
            soundBanner.hidden = false;
        }
        const c = state.connection;
        if (c.fatal) {
            connection.hidden = false;
            connection.textContent = c.fatal;
        } else if (!c.ok) {
            connection.hidden = false;
            connection.textContent = 'Verbindung zum Server unterbrochen. Die Anzeige ist nicht mehr aktuell; es wird weiter versucht.';
        } else {
            connection.hidden = true;
        }
        root.classList.toggle('is-stale', !c.ok);
    };
    sound.onChange(renderBanners);

    // Umschalter Sitzplan / Live-Wand (gemerkt im Browser).
    let viewMode = storage.get('kw-pult-view') === 'wall' ? 'wall' : 'seat';
    const switchButtons = {};
    const switcher = h('div', { class: 'pult-viewswitch', role: 'group', 'aria-label': 'Ansicht des Pults' });
    for (const [key, iconName, label] of [['seat', 'armchair', 'Sitzplan'], ['wall', 'layout-grid', 'Live-Wand']]) {
        const btn = h('button', { type: 'button', class: 'pult-viewswitch-btn', onclick: () => setView(key) }, icon(iconName), ' ', label);
        switchButtons[key] = btn;
        switcher.append(btn);
    }
    function setView(mode, remember = true) {
        viewMode = mode;
        if (remember) storage.set('kw-pult-view', mode);
        for (const [key, btn] of Object.entries(switchButtons)) {
            btn.setAttribute('aria-pressed', key === mode ? 'true' : 'false');
            btn.classList.toggle('is-active', key === mode);
        }
        seat.el.hidden = mode !== 'seat';
        wall.setVisible(mode === 'wall');
    }
    setView(viewMode, false);

    const main = h('div', { class: 'pult-main' }, switcher, seat.el, wall.el);
    const side = h('div', { class: 'pult-side' }, requests.el, timeline.el);
    const layout = h('div', { class: 'pult-layout' }, main, side);
    // Große Räume (mehr als 4 Spalten) bekommen die volle Breite, die Seitenspalte rutscht darunter.
    const updateLayout = () => layout.classList.toggle('is-wide', (state.exam?.room?.cols ?? 0) > 4);
    const content = h('div', { class: 'pult-content', hidden: true }, header.el, alarms.el, actionBar.el, layout);
    root.replaceChildren(connection, soundBanner, loading, content, detail.el);
    renderBanners();

    store.subscribe((what) => {
        if (what === 'connection') {
            renderBanners();
            return;
        }
        if (what === 'stand') {
            loading.hidden = true;
            content.hidden = false;
            updateLayout();
            header.update();
            seat.update();
            wall.update();
            requests.update();
            timeline.update();
            actionBar.update();
            detail.update();
            renderBanners();
        } else if (what === 'selection') {
            seat.update(false);
            actionBar.update();
        } else if (what === 'detail') {
            detail.sync();
        }
    });

    poller = startPoller(store, {
        onAlarms(events) {
            alarms.add(events);
            sound.play();
        },
    });

    // Sekundentakt: Uhren und "vor x s" weiterzählen, ohne neuen Abruf.
    setInterval(() => {
        if (!state.loaded) return;
        header.tick();
        seat.tick();
        wall.tick();
        detail.tick();
    }, 1000);

    // Erst die Rückkehr zum Fenster setzt die Titelmarkierung zurück.
    window.addEventListener('focus', () => {
        if (alarms.el.children.length === 0) document.title = document.title.replace(/^Alarm: /, '');
    });

    return { store, refresh };
}
