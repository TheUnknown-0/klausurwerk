/**
 * Detailansicht einer Person (Seitenleiste): Live-Antworten (alle 2 s neu, nur lesen), Ereignisprotokoll,
 * Zeitdaten und Replay.
 */
import { get } from '../lib/api.js';
import { h, setTrustedHtml, toast } from '../lib/dom.js';
import { enhanceRichText } from '../lib/rich-text.js';
import { answerWidget, FallbackWidget } from '../lib/widgets.js';
import { mountReplay } from '../replay/player.js';
import { PERSON_ACTIONS } from './actions.js';
import { panelDialog } from './dialogs.js';
import { ago, clockTime, describeEvent, remaining, SEVERITY_LABELS, STATE_LABELS } from './util.js';

const ANSWER_POLL_MS = 2000;
const LOG_POLL_MS = 4000;

export function createDetail(store, actions) {
    const { state } = store;
    const { boot } = state;

    let activeId = null;
    let tab = 'answers';
    let generation = 0; // verwirft Antworten veralteter Abrufe
    let answerTimer = null;
    let logTimer = null;

    // ---- Gerüst ----
    const title = h('h2', { class: 'pult-detail-title', id: 'pult-detail-title' });
    const stateBadge = h('span', { class: 'badge' });
    const closeBtn = h('button', { type: 'button', class: 'btn btn-sm', 'aria-label': 'Details schließen', onclick: () => store.closeDetail() }, '✕ Schließen');
    const quick = h('div', { class: 'pult-detail-actions' });
    const tabs = h('div', { class: 'tabs', role: 'tablist' });
    const panels = {
        answers: h('div', { class: 'pult-detail-panel', role: 'tabpanel' }),
        log: h('div', { class: 'pult-detail-panel', role: 'tabpanel', hidden: true }),
        time: h('div', { class: 'pult-detail-panel', role: 'tabpanel', hidden: true }),
    };
    const tabButtons = {};
    for (const [key, label] of [['answers', 'Antworten'], ['log', 'Protokoll'], ['time', 'Zeit & Gerät']]) {
        const btn = h('button', { type: 'button', class: 'tab', role: 'tab', onclick: () => selectTab(key) }, label);
        tabButtons[key] = btn;
        tabs.append(btn);
    }
    const el = h('aside', { class: 'pult-detail', hidden: true, tabindex: -1, role: 'dialog', 'aria-labelledby': 'pult-detail-title' },
        h('div', { class: 'pult-detail-head' }, h('div', { class: 'pult-detail-headline' }, title, stateBadge), closeBtn),
        quick, tabs, panels.answers, panels.log, panels.time);
    el.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !document.querySelector('dialog[open]')) {
            store.closeDetail();
        }
    });

    function selectTab(key) {
        tab = key;
        for (const [k, node] of Object.entries(panels)) {
            node.hidden = k !== key;
            tabButtons[k].classList.toggle('active', k === key);
            tabButtons[k].setAttribute('aria-selected', k === key ? 'true' : 'false');
        }
        stopLog();
        if (key === 'log') loadLog(generation);
        if (key === 'time') renderTime();
    }

    // ---- Öffnen/Schließen ----
    function open(id) {
        if (activeId === id) {
            update();
            return;
        }
        close(false);
        activeId = id;
        generation += 1;
        el.hidden = false;
        answers.reset();
        panels.log.replaceChildren(h('p', { class: 'text-soft' }, 'Wird geladen …'));
        selectTab('answers');
        update();
        answers.load(generation, true);
        el.focus?.();
    }

    function close(hide = true) {
        generation += 1;
        clearTimeout(answerTimer);
        stopLog();
        answers.reset();
        activeId = null;
        if (hide) el.hidden = true;
    }

    function stopLog() {
        clearTimeout(logTimer);
        logTimer = null;
    }

    // ---- Kopf, Schnellaktionen ----
    function update() {
        if (activeId === null) return;
        const p = store.participant(activeId);
        if (!p) {
            store.closeDetail();
            return;
        }
        title.textContent = p.name;
        stateBadge.textContent = p.state === 'locked' && p.lock_reason ? `${STATE_LABELS.locked}: ${p.lock_reason}` : (STATE_LABELS[p.state] ?? p.state);
        stateBadge.className = `badge ${{ active: 'badge-success', locked: 'badge-danger', paused: 'badge-warning', submit_requested: 'badge-accent', device_pending: 'badge-warning' }[p.state] ?? ''}`;

        const examOpen = state.exam && ['lobby', 'running', 'paused'].includes(state.exam.status);
        const key = JSON.stringify([p.state, p.hand_raised, examOpen]);
        if (quick.dataset.key !== key) {
            quick.dataset.key = key;
            const buttons = [];
            if (examOpen) {
                if (p.hand_raised) {
                    buttons.push(h('button', { type: 'button', class: 'btn btn-sm', onclick: () => actions.resolveRequest({ type: 'hand' }, p, true) }, '✋ Hand quittieren'));
                }
                for (const def of PERSON_ACTIONS) {
                    if (def.states.includes(p.state)) {
                        buttons.push(h('button', {
                            type: 'button',
                            class: `btn btn-sm${def.danger ? ' btn-danger-ghost' : ''}`,
                            onclick: () => actions.runPerson(def.key, [p.id]),
                        }, def.icon ? `${def.icon} ${def.label}` : def.label));
                    }
                }
            }
            quick.replaceChildren(...buttons);
        }
        if (tab === 'time') renderTime();
    }

    // ---- Antworten ----
    const answers = createAnswers();

    function createAnswers() {
        let tasks = null; // flache Liste der Aufgaben mit Antworttyp
        let cards = new Map(); // task id -> { el, body, version, instance }
        let notice = null;

        function reset() {
            for (const card of cards.values()) {
                try { card.instance?.destroy?.(); } catch { /* egal */ }
            }
            cards = new Map();
            tasks = null;
            notice = null;
            panels.answers.replaceChildren(h('p', { class: 'text-soft' }, 'Antworten werden geladen …'));
        }

        async function load(gen, first = false) {
            if (gen !== generation || activeId === null) return;
            const pid = activeId;
            const res = await get(`${boot.api_base}/antworten/${pid}${first || tasks === null ? '' : '?tasks=0'}`, { timeoutMs: 10000 });
            if (gen !== generation) return;
            if (res.ok) {
                if (res.data.tasks && tasks === null) {
                    tasks = flatten(res.data.tasks).filter((t) => t.answer_type);
                    build(tasks);
                }
                if (tasks !== null) {
                    refreshCards(res.data.answers ?? {});
                }
                if (notice) {
                    notice.remove();
                    notice = null;
                }
            } else if (!notice) {
                notice = h('div', { class: 'alert alert-warning' }, `Antworten konnten nicht geladen werden: ${res.error}`);
                panels.answers.prepend(notice);
            }
            answerTimer = setTimeout(() => load(gen), ANSWER_POLL_MS);
        }

        function flatten(list, out = []) {
            for (const t of list) {
                out.push(t);
                flatten(t.children ?? [], out);
            }
            return out;
        }

        function build(list) {
            panels.answers.replaceChildren();
            if (list.length === 0) {
                panels.answers.append(h('p', { class: 'text-soft' }, 'Diese Klausur hat keine Aufgaben mit Antwort.'));
                return;
            }
            for (const task of list) {
                const body = h('div', { class: 'pult-answer-body' });
                const meta = h('span', { class: 'pult-answer-meta text-faint text-sm' });
                const replay = h('button', { type: 'button', class: 'btn btn-sm', onclick: () => openReplay(task) }, '▶ Replay');
                const text = h('div', { class: 'pult-task-text rich-text' });
                setTrustedHtml(text, task.body_html ?? '');
                enhanceRichText(text);
                const card = h('article', { class: 'pult-answer card', 'aria-label': `Aufgabe ${task.number}` },
                    h('header', { class: 'pult-answer-head' },
                        h('h3', {}, `${task.number} ${task.title || ''}`.trim(), ' ', h('span', { class: 'text-faint text-sm' }, `(${task.points} P.)`)),
                        meta, replay),
                    task.body_html ? h('details', { class: 'pult-task-details' }, h('summary', {}, 'Aufgabentext'), text) : null,
                    body);
                panels.answers.append(card);
                cards.set(task.id, { el: card, body, meta, task, version: null, instance: null });
            }
        }

        function refreshCards(map) {
            for (const [id, card] of cards) {
                const answer = map[id] ?? null;
                const version = answer ? answer.version : 0;
                if (card.version !== version) {
                    mount(card, answer);
                    card.version = version;
                }
                const parts = [];
                if (!answer) {
                    parts.push('noch keine Antwort');
                } else {
                    parts.push(`Version ${answer.version}`, `gespeichert ${ago(store.now() - answer.updated_at_ms)}`);
                    if (answer.flagged) parts.push('🚩 markiert');
                    if (answer.has_overtime) parts.push('⏰ Überziehung');
                }
                card.meta.textContent = parts.join(' · ');
            }
        }

        function mount(card, answer) {
            try { card.instance?.destroy?.(); } catch { /* egal */ }
            card.body.replaceChildren();
            const widget = answerWidget(card.task.answer_type) ?? FallbackWidget;
            const ctx = {
                task: card.task,
                content: answer ? answer.content : null,
                readOnly: true,
                mode: 'monitor',
                onChange() {},
                env: { examId: boot.exam_id, settings: state.exam?.settings ?? {}, runtime: null },
            };
            try {
                card.instance = widget.mount(card.body, ctx);
                card.instance?.setReadOnly?.(true);
            } catch (err) {
                console.error('Widget konnte nicht angezeigt werden', err);
                card.body.replaceChildren();
                card.instance = FallbackWidget.mount(card.body, ctx);
            }
        }

        return { reset, load };
    }

    function openReplay(task) {
        const p = store.participant(activeId);
        if (!p) return;
        let instance = null;
        const panel = panelDialog(`Replay: ${p.name}, Aufgabe ${task.number}`, () => {
            try { instance?.destroy?.(); } catch { /* egal */ }
        });
        try {
            instance = mountReplay(panel.body, {
                examId: boot.exam_id,
                participationId: p.id,
                taskId: task.id,
                apiBase: boot.api_base,
            });
        } catch (err) {
            console.error('Replay nicht verfügbar', err);
            panel.body.textContent = 'Das Replay konnte nicht gestartet werden.';
        }
    }

    // ---- Protokoll ----
    async function loadLog(gen) {
        if (gen !== generation || activeId === null || tab !== 'log') return;
        const res = await get(`${boot.api_base}/ereignisse/${activeId}`, { timeoutMs: 10000 });
        if (gen !== generation || tab !== 'log') return;
        if (res.ok) {
            const events = res.data.events;
            panels.log.replaceChildren(events.length === 0
                ? h('p', { class: 'text-soft' }, 'Noch keine Ereignisse.')
                : h('ol', { class: 'pult-timeline-list' }, ...[...events].reverse().map((e) => h('li', { class: `pult-event sev-${e.severity}` },
                    h('time', { class: 'pult-event-time' }, clockTime(e.created_at_ms)),
                    h('span', { class: 'pult-event-body' }, describeEvent(e), e.actor ? h('span', { class: 'text-faint text-sm' }, ` (${e.actor})`) : null),
                    h('span', { class: `badge pult-event-sev ${e.severity === 'alarm' ? 'badge-danger' : e.severity === 'warning' ? 'badge-warning' : ''}` }, SEVERITY_LABELS[e.severity])))));
        } else {
            panels.log.replaceChildren(h('div', { class: 'alert alert-warning' }, `Protokoll nicht verfügbar: ${res.error}`));
            toast('Das Protokoll konnte nicht geladen werden.', 'warning');
        }
        logTimer = setTimeout(() => loadLog(gen), LOG_POLL_MS);
    }

    // ---- Zeit & Gerät ----
    function renderTime() {
        const p = store.participant(activeId);
        if (!p) return;
        const c = p.clock;
        const cs = p.client_state ?? {};
        const now = store.now();
        const row = (label, value) => h('tr', {}, h('th', { scope: 'row' }, label), h('td', {}, value));
        const yesNo = (v) => (v === undefined ? 'unbekannt' : v ? 'ja' : 'nein');
        panels.time.replaceChildren(h('div', { class: 'table-wrap' }, h('table', { class: 'data-table pult-facts' }, h('tbody', {},
            row('Zustand', STATE_LABELS[p.state] ?? p.state),
            row('Seit', p.state_since_ms ? `${clockTime(p.state_since_ms)} (${ago(now - p.state_since_ms)})` : '–'),
            row('Individuelles Ende', c.unlimited ? 'ohne Limit' : clockTime(c.ends_at_ms)),
            row('Restzeit', c.unlimited ? 'ohne Limit' : (c.remaining_ms === null ? '–' : `${remaining(c.remaining_ms)}${c.paused ? ' (steht)' : ''}${c.time_up ? ' (Zeit abgelaufen)' : ''}`)),
            row('Nachteilsausgleich', `${c.accommodation_percent} %`),
            row('Zusatzzeit', `${c.extra_minutes} min`),
            row('Bearbeitet', `${p.progress.answered} von ${p.progress.total} Aufgaben, ${p.progress.flagged} markiert`),
            row('Zuletzt gespeichert', p.last_save_ms_ago === null ? 'noch nichts' : ago(p.last_save_ms_ago)),
            row('Zuletzt gesehen', p.last_seen_ms_ago === null ? 'noch nie' : `${ago(p.last_seen_ms_ago)}${p.online ? ' (online)' : ' (offline)'}`),
            row('Nicht gesendete Änderungen', cs.pending_saves !== undefined ? String(cs.pending_saves) : 'unbekannt'),
            row('Vollbild', yesNo(cs.fullscreen)),
            row('Fenster im Fokus', yesNo(cs.focused)),
            row('Safe Exam Browser', yesNo(cs.seb)),
            row('Bildschirm', cs.screen?.w ? `${cs.screen.w} x ${cs.screen.h}` : 'unbekannt'),
            row('Browser', cs.ua || 'unbekannt'),
        ))));
    }

    return {
        el,
        update,
        sync() {
            if (state.detailId === null) {
                if (activeId !== null) close();
            } else {
                open(state.detailId);
            }
        },
        tick() {
            if (activeId !== null && tab === 'time') renderTime();
        },
    };
}
