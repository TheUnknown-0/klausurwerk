/**
 * Replay-Abspieler (Stufe 4): spielt den Verlauf einer Antwort aus den gespeicherten Schritten ab.
 *
 *   const r = mountReplay(container, { examId, participationId, taskId, apiBase });
 *   r.destroy();
 *
 * Lädt GET {apiBase}/replay/{participationId}?task_id=&after_seq=&limit= seitenweise (has_more), baut den
 * Verlauf je Datei aus snapshot- und ops-Schritten (CodeMirror ChangeSet) bzw. value-Schritten
 * (strukturierte Typen, angezeigt mit dem schreibgeschützten Antwort-Widget) wieder auf und zeigt eine
 * Zeitleiste mit Abspielen (1x, 4x, 16x), Sprungmarken für Einfügungen (rot) und Überziehung (gelb);
 * Pausen über einer Minute werden verkürzt. Logik: reconstruct.js, timeline.js.
 */
import { get } from '../lib/api.js';
import { h, icon } from '../lib/dom.js';
import { createCodeEditor } from '../lib/code-editor.js';
import { answerWidget } from '../lib/widgets.js';
import { Reconstructor, normalizeSteps } from './reconstruct.js';
import { buildTimeline, formatGap, indexAt, nextMarker } from './timeline.js';

const PAGE_LIMIT = 2000;
const MAX_PAGES = 200;
const TICK_MS = 100;
const SPEEDS = [1, 4, 16];

const fileLanguage = (name) => {
    const n = String(name ?? '').toLowerCase();
    return n.endsWith('.java') ? 'java' : n.endsWith('.py') ? 'python' : n.endsWith('.sql') ? 'sql' : '';
};

const clock = (ms) => (Number.isFinite(ms) && ms > 0
    ? new Date(ms).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
    : '–');

function findTask(tasks, id) {
    for (const t of Array.isArray(tasks) ? tasks : []) {
        if (Number(t.id) === Number(id)) {
            return t;
        }
        const inner = findTask(t.children, id);
        if (inner) {
            return inner;
        }
    }
    return null;
}

export function mountReplay(container, { examId, participationId, taskId, apiBase } = {}) {
    const base = (apiBase ?? `/api/pult/${examId}`).replace(/\/$/, '');
    let destroyed = false;
    let steps = [];
    let recon = null;
    let timeline = null;
    let task = null;
    let mode = 'code'; // code | value
    let current = -1;
    let position = 0;
    let speed = 1;
    let playing = false;
    let ticker = null;
    let pinnedFile = null;
    let editor = null;
    let editorFile = null;
    let widget = null;
    let widgetTimer = null;
    let noteTimer = null;

    // ---------- Aufbau ----------
    const info = h('p', { class: 'rp-info', role: 'status', 'aria-live': 'off' });
    const loading = h('p', { class: 'rp-loading', role: 'status' }, 'Replay wird geladen …');
    const tabs = h('div', { class: 'rp-tabs', role: 'tablist', 'aria-label': 'Dateien' });
    const view = h('div', { class: 'rp-view' });
    const note = h('p', { class: 'rp-note', role: 'status', hidden: true });
    const warn = h('p', { class: 'rp-warn', hidden: true });
    const playBtn = h('button', { type: 'button', class: 'btn btn-sm btn-primary rp-play', onclick: () => togglePlay() });
    const setPlayLabel = (iconName, text) => playBtn.replaceChildren(icon(iconName), ` ${text}`);
    setPlayLabel('play', 'Abspielen');
    const prevBtn = h('button', { type: 'button', class: 'btn btn-sm', 'aria-label': 'Ein Schritt zurück', onclick: () => gotoIndex(current - 1) }, icon('chevron-left'));
    const nextBtn = h('button', { type: 'button', class: 'btn btn-sm', 'aria-label': 'Ein Schritt vor', onclick: () => gotoIndex(current + 1) }, icon('chevron-right'));
    const speedBtns = SPEEDS.map((s) => h('button', {
        type: 'button', class: 'btn btn-sm rp-speed', 'aria-pressed': s === speed ? 'true' : 'false', onclick: () => setSpeed(s),
    }, `${s}x`));
    const slider = h('input', {
        type: 'range', class: 'rp-slider', min: '0', max: '0', step: '1', value: '0', 'aria-label': 'Zeitleiste', disabled: true,
    });
    const track = h('div', { class: 'rp-track' });
    const jumpPaste = [
        h('button', { type: 'button', class: 'btn btn-sm rp-jump rp-jump-paste', onclick: () => jump('paste', -1) }, icon('chevron-left'), ' Einfügung'),
        h('button', { type: 'button', class: 'btn btn-sm rp-jump rp-jump-paste', onclick: () => jump('paste', 1) }, 'Einfügung ', icon('chevron-right')),
    ];
    const jumpOver = [
        h('button', { type: 'button', class: 'btn btn-sm rp-jump rp-jump-over', onclick: () => jump('overtime', -1) }, icon('chevron-left'), ' Überziehung'),
        h('button', { type: 'button', class: 'btn btn-sm rp-jump rp-jump-over', onclick: () => jump('overtime', 1) }, 'Überziehung ', icon('chevron-right')),
    ];
    const controls = h('div', { class: 'rp-controls', hidden: true },
        h('div', { class: 'rp-row' }, playBtn, prevBtn, nextBtn,
            h('span', { class: 'rp-speeds', role: 'group', 'aria-label': 'Geschwindigkeit' }, ...speedBtns),
            h('span', { class: 'rp-spacer' }), ...jumpPaste, ...jumpOver),
        h('div', { class: 'rp-timeline' }, slider, track),
        h('p', { class: 'rp-legend' },
            h('span', { class: 'rp-dot rp-dot-paste' }), ' Einfügen ',
            h('span', { class: 'rp-dot rp-dot-over' }), ' nach Zeitende (Überziehung) · Pausen über einer Minute werden verkürzt.'));
    const root = h('div', { class: 'rp' }, info, loading, tabs, view, note, warn, controls);
    container.append(root);

    // ---------- Laden ----------
    async function loadAll() {
        const raw = [];
        let after = 0;
        for (let page = 0; page < MAX_PAGES && !destroyed; page++) {
            const res = await get(`${base}/replay/${participationId}?task_id=${encodeURIComponent(taskId)}&after_seq=${after}&limit=${PAGE_LIMIT}`, { timeoutMs: 30000 });
            if (destroyed) {
                return null;
            }
            if (!res.ok) {
                throw new Error(res.error || 'Der Verlauf konnte nicht geladen werden.');
            }
            const ops = Array.isArray(res.data.ops) ? res.data.ops : [];
            raw.push(...ops);
            loading.textContent = `Replay wird geladen … (${raw.length} Schritte)`;
            if (!res.data.has_more || ops.length === 0) {
                break;
            }
            after = Math.max(...ops.map((o) => Number(o.seq) || 0));
        }
        return raw;
    }

    async function loadTask() {
        const res = await get(`${base}/antworten/${participationId}`, { timeoutMs: 15000 });
        return res.ok ? findTask(res.data.tasks, taskId) : null;
    }

    // ---------- Anzeige ----------
    function ensureEditor() {
        if (!editor) {
            view.replaceChildren();
            editor = createCodeEditor({ parent: view, doc: '', readOnly: true, language: '', fontSize: 14 });
        }
        return editor;
    }

    function renderTabs(files, active) {
        tabs.replaceChildren();
        tabs.hidden = files.length < 2;
        for (const name of files) {
            tabs.append(h('button', {
                type: 'button', role: 'tab', class: ['rp-tab', name === active && 'is-active'], 'aria-selected': name === active ? 'true' : 'false',
                onclick: () => {
                    pinnedFile = name;
                    render();
                },
            }, name));
        }
    }

    function renderValue(value) {
        const type = task?.answer_type;
        const factory = type ? answerWidget(type) : null;
        widget?.destroy?.();
        widget = null;
        view.replaceChildren();
        if (value === null || value === undefined) {
            view.append(h('p', { class: 'rp-empty' }, 'Noch keine Antwort.'));
            return;
        }
        if (!factory || !task) {
            view.append(h('pre', { class: 'rp-json' }, JSON.stringify(value, null, 2)));
            return;
        }
        try {
            widget = factory.mount(view, {
                task, content: value, readOnly: true, mode: 'monitor', onChange() {},
                env: { examId, settings: {}, runtime: null },
            });
            widget?.setReadOnly?.(true);
        } catch (e) {
            console.error('Replay: Widget konnte nicht angezeigt werden', e);
            view.replaceChildren(h('pre', { class: 'rp-json' }, JSON.stringify(value, null, 2)));
        }
    }

    function render() {
        if (!recon) {
            return;
        }
        const st = recon.stateAt(current);
        const step = steps[current];
        const total = steps.length;
        const where = current < 0 ? 'vor dem ersten Schritt' : `Schritt ${current + 1} von ${total}`;
        const fileName = mode === 'code' ? (pinnedFile && st.files.has(pinnedFile) ? pinnedFile : (st.file ?? [...st.files.keys()][0] ?? null)) : null;
        info.replaceChildren(...[
            h('strong', {}, clock(current < 0 ? timeline.real[0] : timeline.real[current])),
            ` · ${where}`,
            fileName ? ` · Datei ${fileName}` : '',
            step?.isPaste ? h('span', { class: 'badge badge-danger rp-badge' }, 'Einfügung') : null,
            step?.isOvertime ? h('span', { class: 'badge badge-warning rp-badge' }, 'nach Zeitende') : null,
        ].filter((node) => node !== null && node !== undefined));
        slider.setAttribute('aria-valuetext', `${where}, ${clock(timeline.real[Math.max(0, current)])}`);
        warn.hidden = !st.hasGap;
        warn.textContent = st.hasGap
            ? (st.unsure ? 'Im Verlauf fehlen Schritte (Verbindungsabbruch). Die Anzeige kann bis zum nächsten Schnappschuss ungenau sein.' : 'Im Verlauf fehlten Schritte; der Text wurde durch einen Schnappschuss wieder aufgesetzt.')
            : '';
        if (mode === 'code') {
            const ed = ensureEditor();
            renderTabs([...st.files.keys()], fileName);
            const text = fileName ? st.files.get(fileName).text : '';
            if (editorFile !== fileName) {
                ed.setLanguage(fileLanguage(fileName));
                editorFile = fileName;
            }
            if (ed.getValue() !== text) {
                ed.setValue(text);
            }
            const lc = st.lastChange;
            const markers = [];
            if (lc && lc.file === fileName) {
                for (let line = lc.fromLine; line <= Math.min(lc.toLine, lc.fromLine + 40); line++) {
                    markers.push({ line, className: step?.isPaste ? 'cm-line-error' : 'cm-line-marked' });
                }
            }
            ed.addLineMarkers(markers);
        } else {
            clearTimeout(widgetTimer);
            widgetTimer = setTimeout(() => {
                if (!destroyed) {
                    renderValue(recon.stateAt(current).value);
                }
            }, playing ? 150 : 0);
        }
        const skip = timeline.skipped.find((s) => s.index === current);
        if (skip) {
            showNote(`Pause von ${formatGap(skip.ms)} übersprungen.`);
        }
        if (playing) setPlayLabel('pause', 'Pause');
        else if (position >= timeline.total && total > 0) setPlayLabel('refresh-cw', 'Von vorn');
        else setPlayLabel('play', 'Abspielen');
        prevBtn.disabled = current < 0;
        nextBtn.disabled = current >= total - 1;
        for (const b of jumpPaste) b.disabled = !timeline.markers.some((m) => m.type === 'paste');
        for (const b of jumpOver) b.disabled = !timeline.markers.some((m) => m.type === 'overtime');
    }

    function showNote(text) {
        note.textContent = text;
        note.hidden = false;
        clearTimeout(noteTimer);
        noteTimer = setTimeout(() => { note.hidden = true; }, 2500);
    }

    function setPosition(value, { renderNow = true } = {}) {
        position = Math.max(0, Math.min(timeline.total, value));
        slider.value = String(Math.round(position));
        const idx = indexAt(timeline, position);
        if (idx !== current) {
            current = idx;
            if (renderNow) {
                render();
            }
        }
    }

    function gotoIndex(i) {
        if (!timeline) return;
        const target = Math.max(-1, Math.min(steps.length - 1, i));
        position = target < 0 ? 0 : timeline.virtual[target];
        slider.value = String(Math.round(position));
        current = target;
        render();
    }

    function jump(type, dir) {
        const m = nextMarker(timeline, type, current, dir);
        if (m) {
            pause();
            gotoIndex(m.index);
        } else {
            showNote(dir > 0 ? 'Keine weitere Markierung dieser Art.' : 'Keine frühere Markierung dieser Art.');
        }
    }

    function setSpeed(s) {
        speed = s;
        speedBtns.forEach((b, i) => b.setAttribute('aria-pressed', SPEEDS[i] === s ? 'true' : 'false'));
    }

    function pause() {
        playing = false;
        clearInterval(ticker);
        ticker = null;
        if (timeline) render();
    }

    function play() {
        if (!timeline || steps.length === 0) return;
        if (position >= timeline.total) {
            position = 0;
            current = -1;
            slider.value = '0';
        }
        playing = true;
        clearInterval(ticker);
        ticker = setInterval(() => {
            setPosition(position + TICK_MS * speed);
            if (position >= timeline.total) {
                pause();
            }
        }, TICK_MS);
        render();
    }

    function togglePlay() {
        if (playing) pause();
        else play();
    }

    slider.addEventListener('input', () => {
        if (playing) {
            pause();
        }
        setPosition(Number(slider.value));
    });
    slider.addEventListener('keydown', (ev) => {
        const delta = { ArrowLeft: -1, ArrowDown: -1, ArrowRight: 1, ArrowUp: 1, PageUp: 10, PageDown: -10 }[ev.key];
        if (delta !== undefined) {
            ev.preventDefault();
            pause();
            gotoIndex(current + delta);
        } else if (ev.key === 'Home' || ev.key === 'End') {
            ev.preventDefault();
            pause();
            gotoIndex(ev.key === 'Home' ? -1 : steps.length - 1);
        }
    });

    function buildTrack() {
        track.replaceChildren();
        const total = Math.max(1, timeline.total);
        for (const m of timeline.markers) {
            const label = m.type === 'paste' ? 'Einfügung' : 'nach Zeitende';
            const btn = h('button', {
                type: 'button', class: `rp-mark rp-mark-${m.type}`, title: `${label} um ${clock(timeline.real[m.index])}`,
                'aria-label': `${label} um ${clock(timeline.real[m.index])}, Schritt ${m.index + 1}`,
                onclick: () => { pause(); gotoIndex(m.index); },
            });
            btn.style.left = `${(m.at / total) * 100}%`;
            track.append(btn);
        }
    }

    // ---------- Start ----------
    (async () => {
        try {
            const [raw, taskInfo] = await Promise.all([loadAll(), loadTask().catch(() => null)]);
            if (destroyed || raw === null) {
                return;
            }
            task = taskInfo;
            steps = normalizeSteps(raw);
            loading.hidden = true;
            if (steps.length === 0) {
                root.append(h('p', { class: 'rp-empty' }, 'Für diese Aufgabe gibt es keine aufgezeichneten Schritte.'));
                return;
            }
            mode = (task && !['code', 'robo'].includes(task.answer_type) && steps.every((s) => s.kind === 'value'))
                || (!task && steps.every((s) => s.kind === 'value' && !Array.isArray(s.payload?.files)))
                ? 'value' : 'code';
            recon = new Reconstructor(steps);
            timeline = buildTimeline(steps);
            slider.max = String(Math.max(1, Math.round(timeline.total)));
            slider.disabled = false;
            controls.hidden = false;
            buildTrack();
            current = 0;
            position = 0;
            render();
        } catch (e) {
            if (!destroyed) {
                loading.hidden = true;
                root.append(h('div', { class: 'alert alert-danger', role: 'alert' }, e.message || 'Der Verlauf konnte nicht geladen werden.'));
            }
        }
    })();

    return {
        destroy() {
            destroyed = true;
            clearInterval(ticker);
            clearTimeout(widgetTimer);
            clearTimeout(noteTimer);
            widget?.destroy?.();
            editor?.destroy();
            root.remove();
        },
    };
}
