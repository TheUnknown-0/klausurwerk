/**
 * Korrektur-Einzelansicht (Seite /klausuren/{id}/korrektur/{pid}).
 *
 * Links die Aufgabe mit Erwartungshorizont und Musterlösung, rechts die Antwort der Person, die
 * Punkteingabe (Autosave), Testergebnisse und Kommentare. Durchblättern entweder Person für Person
 * oder "eine Aufgabe für alle". Tastatur: Enter im Punktefeld = speichern und weiter,
 * Alt+Pfeil rechts/links = weiter/zurück in der Reihenfolge, Alt+Pfeil unten/oben = andere Achse,
 * Alt+O = nächste offene Zelle.
 */
import { get, post } from '../lib/api.js';
import { h, setTrustedHtml, toast, debounce } from '../lib/dom.js';
import { enhanceRichText } from '../lib/rich-text.js';
import { mountReplay } from '../replay/player.js';
import { mountAnswer } from './answer-view.js';
import { mountComments } from './comments.js';
import { solutionView } from './solution-view.js';
import { formatPoints, neighbour, nextOpen, parsePoints, stepAxis, traversal } from './logic.js';

const SAVE_DELAY_MS = 700;

export function mountGrading(root, boot) {
    const tasks = boot.tasks;
    const taskById = new Map(tasks.map((t) => [t.id, t]));
    const leafTasks = tasks.filter((t) => t.answer_type);
    const leafIds = leafTasks.map((t) => t.id);
    const participantIds = boot.participants.map((p) => p.id);
    const participantById = new Map(boot.participants.map((p) => [p.id, p]));
    const readOnly = boot.read_only;

    const state = {
        mode: boot.mode, // 'person' | 'task'
        pid: boot.current.participation_id,
        tid: boot.current.task_id,
        cell: null,
        comments: [],
        token: 0,
        lastSaved: undefined, // zuletzt gespeicherter Wert (null = leer)
        savePromise: null,
        pointsInput: null,
        answerView: null,
        commentsPanel: null,
        renderedTask: null,
    };

    // ---------- Gerüst ----------
    const personSelect = h('select', { class: 'input g-select', 'aria-label': 'Person wählen', onchange: (e) => go({ pid: Number(e.target.value), tid: state.tid }) });
    const taskSelect = h('select', { class: 'input g-select', 'aria-label': 'Aufgabe wählen', onchange: (e) => go({ pid: state.pid, tid: Number(e.target.value) }) });
    const progressEl = h('span', { class: 'g-progress-text text-sm text-soft', role: 'status' });
    const modeBtn = (value, label, title) => h('button', {
        type: 'button', class: 'btn btn-sm', 'aria-pressed': 'false', dataset: { mode: value }, title,
        onclick: () => setMode(value),
    }, label);
    const modeGroup = h('div', { class: 'g-segment', role: 'group', 'aria-label': 'Reihenfolge' },
        modeBtn('person', 'Person für Person', 'Alle Aufgaben einer Person, dann die nächste Person'),
        modeBtn('task', 'Eine Aufgabe für alle', 'Dieselbe Aufgabe bei allen, dann die nächste Aufgabe'));
    const navBtn = (label, title, fn) => h('button', { type: 'button', class: 'btn btn-sm', title, 'aria-label': title, onclick: fn }, label);
    const toolbar = h('div', { class: 'g-toolbar card card-pad' },
        h('a', { class: 'btn btn-sm', href: boot.urls.overview + queryString() }, '← Übersicht'),
        h('div', { class: 'g-navgroup' },
            navBtn('‹', 'Vorherige Person', () => goAxis('person', -1)), personSelect, navBtn('›', 'Nächste Person', () => goAxis('person', 1))),
        h('div', { class: 'g-navgroup' },
            navBtn('‹', 'Vorherige Aufgabe', () => goAxis('task', -1)), taskSelect, navBtn('›', 'Nächste Aufgabe', () => goAxis('task', 1))),
        modeGroup,
        progressEl,
        boot.is_owner ? null : h('a', {
            class: 'btn btn-sm', href: pageUrl({ fremde: !boot.sees_others }),
        }, boot.sees_others ? 'Fremde Bewertungen ausblenden' : 'Fremde Bewertungen zeigen'),
        boot.anonymous ? h('span', { class: 'badge badge-accent' }, 'anonym') : null);

    const leftPane = h('section', { class: 'g-pane g-left card card-pad', 'aria-label': 'Aufgabe' });
    const rightPane = h('section', { class: 'g-pane g-right', 'aria-label': 'Antwort und Bewertung' });
    root.replaceChildren(...[
        readOnly ? h('div', { class: 'alert alert-info' }, 'Die Klausur ist zurückgegeben oder archiviert. Du kannst die Bewertung nur ansehen. Nimm die Freigabe zurück, um etwas zu ändern.') : null,
        toolbar,
        h('div', { class: 'g-split' }, leftPane, rightPane),
    ].filter(Boolean));

    // ---------- Matrix-Stand ----------
    const matrix = new Map(boot.participants.map((p) => [p.id, new Map(Object.entries(p.cells).map(([tid, c]) => [Number(tid), { ...c }]))]));
    const statusOf = (cell) => matrix.get(cell.pid)?.get(cell.tid)?.status ?? 'open';

    function updateProgress() {
        let done = 0;
        let total = 0;
        for (const cells of matrix.values()) {
            for (const c of cells.values()) {
                total++;
                if (c.status === 'graded' || c.status === 'diff') done++;
            }
        }
        progressEl.textContent = `${done} von ${total} bewertet`;
        const mark = (s) => (s === 'graded' || s === 'diff' ? '● ' : s === 'suggested' ? '◐ ' : '○ ');
        for (const opt of personSelect.options) {
            const p = participantById.get(Number(opt.value));
            const cells = matrix.get(p.id);
            const open = [...cells.values()].filter((c) => c.status !== 'graded' && c.status !== 'diff').length;
            opt.textContent = `${p.label}${p.submitted ? '' : ' (ohne Abgabe)'}${open === 0 ? ' ✓' : ` (${open} offen)`}`;
        }
        for (const opt of taskSelect.options) {
            const tid = Number(opt.value);
            const t = taskById.get(tid);
            let openCount = 0;
            for (const cells of matrix.values()) {
                const c = cells.get(tid);
                if (c && c.status !== 'graded' && c.status !== 'diff') openCount++;
            }
            opt.textContent = `${mark(openCount === 0 ? 'graded' : 'open')}${t.number} ${t.title || ''}`.trim() + ` (${openCount} offen)`;
        }
        personSelect.value = String(state.pid);
        taskSelect.value = String(state.tid);
    }

    for (const p of boot.participants) {
        personSelect.append(h('option', { value: String(p.id) }, p.label));
    }
    for (const t of leafTasks) {
        taskSelect.append(h('option', { value: String(t.id) }, `${t.number} ${t.title || ''}`.trim()));
    }

    // ---------- Navigation ----------
    function queryString(extra = {}) {
        const q = new URLSearchParams();
        if (boot.anonymous) q.set('anonym', '1');
        if (boot.sees_others && !boot.is_owner) q.set('fremde', '1');
        for (const [k, v] of Object.entries(extra)) {
            if (v !== null && v !== undefined && v !== false) q.set(k, String(v === true ? '1' : v));
        }
        const s = q.toString();
        return s ? `?${s}` : '';
    }

    function pageUrl(opts = {}) {
        const q = new URLSearchParams();
        if (boot.anonymous) q.set('anonym', '1');
        const others = 'fremde' in opts ? opts.fremde : (boot.sees_others && !boot.is_owner);
        if (others) q.set('fremde', '1');
        q.set('aufgabe', String(state.tid));
        if (state.mode === 'task') q.set('modus', 'aufgabe');
        return `${boot.urls.page}${state.pid}?${q.toString()}`;
    }

    function syncUrl() {
        try {
            history.replaceState(null, '', pageUrl());
        } catch {
            // egal
        }
    }

    function cells() {
        return traversal(participantIds, leafIds, state.mode);
    }

    async function go(target) {
        if (!(await flush())) {
            return;
        }
        state.pid = target.pid;
        state.tid = target.tid;
        syncUrl();
        await load();
    }

    function goStep(dir) {
        const next = neighbour(cells(), { pid: state.pid, tid: state.tid }, dir);
        if (next) go(next);
        else toast(dir > 0 ? 'Das war die letzte Zelle.' : 'Das ist die erste Zelle.', 'info', 2500);
    }

    function goAxis(axis, dir) {
        const next = stepAxis(participantIds, leafIds, { pid: state.pid, tid: state.tid }, axis, dir);
        if (next) go(next);
        else toast(axis === 'person' ? (dir > 0 ? 'Das ist die letzte Person.' : 'Das ist die erste Person.') : (dir > 0 ? 'Das ist die letzte Aufgabe.' : 'Das ist die erste Aufgabe.'), 'info', 2500);
    }

    function goNextOpen() {
        const next = nextOpen(cells(), { pid: state.pid, tid: state.tid }, statusOf);
        if (next) go(next);
        else toast('Hinter dieser Stelle ist nichts mehr offen.', 'success', 3000);
    }

    function setMode(mode) {
        state.mode = mode;
        for (const b of modeGroup.querySelectorAll('button')) {
            b.setAttribute('aria-pressed', b.dataset.mode === mode ? 'true' : 'false');
            b.classList.toggle('btn-primary', b.dataset.mode === mode);
        }
        syncUrl();
        renderNav();
    }

    document.addEventListener('keydown', (ev) => {
        if (!ev.altKey || ev.ctrlKey || ev.metaKey || document.querySelector('dialog[open]')) {
            return;
        }
        const otherAxis = state.mode === 'task' ? 'task' : 'person';
        if (ev.key === 'ArrowRight') { ev.preventDefault(); goStep(1); }
        else if (ev.key === 'ArrowLeft') { ev.preventDefault(); goStep(-1); }
        else if (ev.key === 'ArrowDown') { ev.preventDefault(); goAxis(state.mode === 'task' ? 'task' : 'person', 1); }
        else if (ev.key === 'ArrowUp') { ev.preventDefault(); goAxis(otherAxis, -1); }
        else if (ev.key.toLowerCase() === 'o') { ev.preventDefault(); goNextOpen(); }
    });

    function renderNav() {
        updateProgress();
    }

    // ---------- Laden ----------
    async function load() {
        const token = ++state.token;
        rightPane.classList.add('is-loading');
        const url = `${boot.api_base}/zelle/${state.pid}/${state.tid}${boot.sees_others && !boot.is_owner ? '?fremde=1' : ''}`;
        const res = await get(url, { timeoutMs: 20000 });
        if (token !== state.token) {
            return;
        }
        rightPane.classList.remove('is-loading');
        if (!res.ok) {
            rightPane.replaceChildren(h('div', { class: 'alert alert-error' }, `Die Zelle konnte nicht geladen werden: ${res.error}`,
                ' ', h('button', { type: 'button', class: 'btn btn-sm', onclick: () => load() }, 'Erneut versuchen')));
            return;
        }
        state.cell = res.data;
        state.comments = res.data.comments;
        renderTask();
        renderRight();
        renderNav();
        document.title = `${taskById.get(state.tid).number} · ${participantById.get(state.pid).label} · Korrektur`;
    }

    // ---------- Aufgabe (links) ----------
    function renderTask() {
        if (state.renderedTask === state.tid) {
            return;
        }
        state.renderedTask = state.tid;
        const task = taskById.get(state.tid);
        leftPane.replaceChildren();

        // Texte übergeordneter Aufgaben als Zusammenhang
        const chain = [];
        for (let p = task.parent_id; p; p = taskById.get(p)?.parent_id) {
            chain.unshift(taskById.get(p));
        }
        for (const parent of chain.filter(Boolean)) {
            if (parent.body_html) {
                const box = h('details', { class: 'g-context' },
                    h('summary', {}, `${parent.number} ${parent.title || ''}`.trim()), h('div', { class: 'rich-text' }));
                setTrustedHtml(box.lastChild, parent.body_html);
                enhanceRichText(box.lastChild);
                leftPane.append(box);
            }
        }

        const body = h('div', { class: 'rich-text g-task-body' });
        setTrustedHtml(body, task.body_html || '');
        enhanceRichText(body);
        leftPane.append(
            h('h2', { class: 'g-task-title' }, `${task.number} ${task.title || ''}`.trim(),
                h('span', { class: 'badge' }, `${formatPoints(task.points)} P.`),
                task.afb ? h('span', { class: 'badge' }, `AFB ${task.afb}`) : null,
                task.operator ? h('span', { class: 'badge' }, task.operator) : null),
            body);

        const section = (title, html, open) => {
            if (!html) return null;
            const inner = h('div', { class: 'rich-text' });
            setTrustedHtml(inner, html);
            enhanceRichText(inner);
            return h('details', { class: 'g-sol-section', open }, h('summary', {}, title), inner);
        };
        const lists = solutionView(task);
        const blocks = [
            section('Erwartungshorizont', task.expectation_html, true),
            lists ? h('details', { class: 'g-sol-section', open: true }, h('summary', {}, 'Richtige Lösung'), lists) : null,
            section('Musterlösung', task.model_html, task.answer_type === 'code'),
            task.tests_count > 0 ? h('p', { class: 'text-faint text-sm' }, `${task.tests_count} versteckte Tests hinterlegt.`) : null,
            !task.expectation_html && !task.model_html && !lists
                ? h('p', { class: 'text-faint text-sm' }, 'Für diese Aufgabe sind kein Erwartungshorizont und keine Musterlösung hinterlegt.') : null,
        ];
        leftPane.append(...blocks.filter(Boolean));
    }

    // ---------- Antwort und Bewertung (rechts) ----------
    function renderRight() {
        const cell = state.cell;
        const task = taskById.get(state.tid);
        const person = participantById.get(state.pid);
        destroyRight();
        rightPane.replaceChildren();

        // Kopf: Person
        rightPane.append(h('div', { class: 'g-person-head' },
            h('strong', {}, person.label),
            !cell.participation.submitted ? h('span', { class: 'badge badge-warning', title: `Zustand: ${cell.participation.state}` }, 'ohne Abgabe') : null,
            cell.answer?.has_overtime ? h('span', { class: 'badge badge-warning', title: 'Änderungen nach Ablauf der Bearbeitungszeit' }, '⏰ Überziehung') : null,
            cell.pastes > 0 ? h('span', { class: 'badge badge-warning', title: 'Aus der Klausur heraus eingefügte Texte werden hier nicht gezählt, nur Einfügungen innerhalb der Klausur.' }, `📋 ${cell.pastes} ${cell.pastes === 1 ? 'Einfügung' : 'Einfügungen'}`) : null,
            boot.can.replay && cell.answer ? h('button', { type: 'button', class: 'btn btn-sm', onclick: () => openReplay() }, '▶ Replay') : null));

        rightPane.append(buildGradePanel(cell, task));

        const answerHost = h('div', { class: 'g-answer card' });
        rightPane.append(answerHost);
        const commentHost = h('section', { class: 'card card-pad g-comments-card', 'aria-label': 'Kommentare' },
            h('h3', { class: 'g-h3' }, 'Kommentare'));
        rightPane.append(commentHost);

        state.commentsPanel = mountComments(commentHost, {
            comments: state.comments,
            canWrite: !readOnly,
            canDeleteAny: boot.is_owner,
            showAuthor: boot.sees_others,
            onSave: saveComment,
            onDelete: deleteComment,
            onFocus: (c) => state.answerView?.focusComment(c),
        });
        state.answerView = mountAnswer(answerHost, {
            task,
            content: cell.answer ? cell.answer.content : null,
            mode: 'grading',
            examId: boot.exam_id,
            comments: state.comments,
            canComment: !readOnly,
            onComment: (anchor) => {
                state.commentsPanel.compose(anchor);
                commentHost.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            },
            onBubble: (ids) => highlightComments(commentHost, ids),
        });
    }

    function destroyRight() {
        state.answerView?.destroy();
        state.commentsPanel?.destroy();
        state.answerView = null;
        state.commentsPanel = null;
        state.pointsInput = null;
    }

    function highlightComments(host, ids) {
        for (const id of ids) {
            const li = host.querySelector(`[data-id="${id}"]`);
            if (li) {
                li.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                li.classList.add('is-flash');
                setTimeout(() => li.classList.remove('is-flash'), 1200);
            }
        }
    }

    // ---------- Punkte ----------
    function buildGradePanel(cell, task) {
        const max = task.points;
        const auto = cell.auto;
        const suggestion = auto?.suggested_points ?? null;
        const input = h('input', {
            type: 'text', inputmode: 'decimal', class: 'input g-points-input', id: 'g-points', autocomplete: 'off',
            'aria-describedby': 'g-points-state g-points-max', disabled: readOnly,
            placeholder: suggestion !== null ? `(${formatPoints(suggestion)})` : '',
        });
        input.value = cell.own_points !== null ? formatPoints(cell.own_points) : '';
        state.pointsInput = input;
        state.lastSaved = cell.own_points;
        const stateEl = h('span', { id: 'g-points-state', class: 'g-save-state text-sm', role: 'status', 'aria-live': 'polite' });
        state.stateEl = stateEl;

        const autosave = debounce(() => saveNow(), SAVE_DELAY_MS);
        input.addEventListener('input', () => {
            const parsed = parsePoints(input.value, max);
            input.classList.toggle('is-invalid', !parsed.ok);
            stateEl.textContent = parsed.ok ? 'Änderung ...' : parsed.error;
            stateEl.className = `g-save-state text-sm ${parsed.ok ? '' : 'text-danger'}`;
            if (parsed.ok) autosave();
        });
        input.addEventListener('blur', () => { autosave.cancel(); saveNow(); });
        input.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                autosave.cancel();
                goStep(1);
            }
        });
        state.autosave = autosave;

        const quick = (label, value, title) => h('button', {
            type: 'button', class: 'btn btn-sm', disabled: readOnly, title,
            onclick: () => { input.value = formatPoints(value); input.dispatchEvent(new Event('input')); input.focus(); },
        }, label);

        const panel = h('section', { class: 'card card-pad g-grade', 'aria-label': 'Bewertung' },
            h('div', { class: 'g-grade-row' },
                h('label', { for: 'g-points', class: 'g-grade-label' }, 'Punkte'),
                input,
                h('span', { id: 'g-points-max', class: 'g-points-max' }, `von ${formatPoints(max)}`),
                quick('0', 0, 'Null Punkte'),
                quick('Voll', max, 'Volle Punktzahl'),
                suggestion !== null ? h('button', {
                    type: 'button', class: 'btn btn-sm btn-accent', disabled: readOnly,
                    title: 'Setzt die Punkte aus der automatischen Prüfung',
                    onclick: () => { input.value = formatPoints(suggestion); input.dispatchEvent(new Event('input')); },
                }, `Vorschlag übernehmen (${formatPoints(suggestion)})`) : null,
                stateEl),
            finalNote(cell),
            auto ? autoBox(auto, task) : null,
            h('div', { class: 'g-grade-nav cluster' },
                h('button', { type: 'button', class: 'btn btn-sm', onclick: () => goStep(-1) }, '← Zurück'),
                h('button', { type: 'button', class: 'btn btn-sm btn-primary', onclick: () => { state.autosave?.cancel(); goStep(1); } }, 'Weiter →'),
                h('button', { type: 'button', class: 'btn btn-sm', onclick: () => goNextOpen(), title: 'Alt+O' }, 'Nächste offene Stelle'),
                h('span', { class: 'text-faint text-sm' }, 'Enter im Punktefeld: speichern und weiter')));
        return panel;
    }

    function finalNote(cell) {
        const box = h('div', { class: 'g-final-note' });
        if (cell.others.length > 0) {
            box.append(h('ul', { class: 'g-others' }, cell.others.map((o) => h('li', {},
                h('strong', {}, o.grader_name), ': ', o.points === null ? 'keine Punkte' : `${formatPoints(o.points)} P.`,
                o.is_final ? h('span', { class: 'badge badge-primary' }, 'zählt') : null))));
        }
        if (cell.final.differs) {
            box.append(h('div', { class: 'alert alert-warning' },
                boot.is_owner ? 'Die Zweitkorrektur weicht ab. Deine Bewertung zählt, sobald du Punkte einträgst.' : 'Die Bewertungen weichen ab. Die Hauptlehrkraft entscheidet.'));
        } else if (cell.final.points !== null && cell.own_points === null) {
            box.append(h('p', { class: 'text-sm text-soft' }, `Aktuell zählt: ${formatPoints(cell.final.points)} P.`));
        }
        return box;
    }

    function autoBox(auto, task) {
        const d = auto.details ?? {};
        const body = h('div', { class: 'g-auto-body' });
        if (d.source === 'tests') {
            body.append(h('p', {}, `${d.passed} von ${d.total} Tests bestanden, ${formatPoints(d.earned)} von ${formatPoints(d.max_points)} Testpunkten.`));
            body.append(h('ul', { class: 'g-tests' }, (d.results ?? []).map((r) => h('li', { class: r.ok ? 'is-ok' : 'is-fail' },
                h('span', { class: 'g-test-mark', 'aria-hidden': 'true' }, r.ok ? '✓' : '✗'),
                h('span', { class: 'sr-only' }, r.ok ? 'bestanden: ' : 'nicht bestanden: '),
                h('strong', {}, r.name), ` (${formatPoints(r.earned)} / ${formatPoints(r.points)} P.)`,
                r.message ? h('pre', { class: 'g-test-msg' }, r.message) : null,
                r.stderr ? h('details', {}, h('summary', {}, 'Fehlerausgabe'), h('pre', { class: 'g-test-msg' }, r.stderr)) : null,
                r.stdout ? h('details', {}, h('summary', {}, 'Ausgabe'), h('pre', { class: 'g-test-msg' }, r.stdout)) : null))));
        } else if (d.source === 'error') {
            body.append(h('div', { class: 'alert alert-warning' }, `Die automatische Prüfung ist fehlgeschlagen: ${d.error ?? 'unbekannter Fehler'}`));
        } else if (d.source === 'empty') {
            body.append(h('p', {}, 'Keine Antwort: 0 Punkte vorgeschlagen.'));
        } else {
            body.append(h('p', {}, `Automatisch geprüft: ${Math.round((d.ratio ?? 0) * 100)} % richtig.`));
        }
        const retest = readOnly ? null : h('button', {
            type: 'button', class: 'btn btn-sm', onclick: async (ev) => {
                const btn = ev.currentTarget;
                btn.disabled = true;
                btn.textContent = 'Wird getestet ...';
                const res = await post(`${boot.api_base}/neu-testen`, { participation_id: state.pid, task_id: state.tid }, { timeoutMs: 330000 });
                if (!res.ok) {
                    toast(res.error, 'error');
                } else {
                    toast('Neu geprüft.', 'success', 2500);
                    updateMatrixStatus(state.pid, state.tid, state.cell.own_points, res.data.auto.suggested_points);
                }
                await load();
            },
        }, task.answer_type === 'code' ? 'Neu testen' : 'Neu prüfen');
        const sum = d.source === 'tests' ? `Tests: ${d.passed}/${d.total}` : d.source === 'error' ? 'Prüfung fehlgeschlagen' : 'Automatische Prüfung';
        return h('details', { class: 'g-auto', open: d.source === 'error' || (d.source === 'tests' && d.passed < d.total) },
            h('summary', {}, sum, auto.suggested_points !== null ? ` · Vorschlag ${formatPoints(auto.suggested_points)} P.` : ''),
            body, retest);
    }

    async function saveNow() {
        const input = state.pointsInput;
        if (!input || readOnly) return true;
        const task = taskById.get(state.tid);
        const parsed = parsePoints(input.value, task.points);
        if (!parsed.ok) {
            return false;
        }
        if (parsed.value === state.lastSaved) {
            setSaveState('ok');
            return true;
        }
        const pid = state.pid;
        const tid = state.tid;
        setSaveState('saving');
        const run = (async () => {
            const res = await post(`${boot.api_base}/bewertung`, { participation_id: pid, task_id: tid, points: parsed.value });
            if (!res.ok) {
                if (state.pid === pid && state.tid === tid) setSaveState('error', res.error);
                toast(`Die Punkte wurden nicht gespeichert: ${res.error}`, 'error', 7000);
                return false;
            }
            if (state.pid === pid && state.tid === tid) {
                state.lastSaved = parsed.value;
                state.cell.own_points = parsed.value;
                setSaveState('ok');
            }
            updateMatrixStatus(pid, tid, parsed.value, state.cell?.auto?.suggested_points ?? null);
            if (res.data.unfinalized) {
                toast('Der Abschluss dieser Person wurde zurückgesetzt, weil sich die Punkte geändert haben.', 'info', 6000);
            }
            return true;
        })();
        state.savePromise = run;
        const ok = await run;
        if (state.savePromise === run) state.savePromise = null;
        return ok;
    }

    function setSaveState(kind, message = '') {
        const el = state.stateEl;
        if (!el) return;
        el.className = 'g-save-state text-sm';
        if (kind === 'saving') el.textContent = 'Speichert ...';
        else if (kind === 'ok') { el.textContent = '✓ gespeichert'; el.classList.add('text-success'); }
        else { el.textContent = `Nicht gespeichert: ${message}`; el.classList.add('text-danger'); }
    }

    function updateMatrixStatus(pid, tid, points, suggested) {
        const row = matrix.get(pid);
        if (!row) return;
        const c = row.get(tid) ?? { status: 'open', points: null };
        if (points !== null && points !== undefined) {
            c.status = 'graded';
            c.points = points;
        } else if (suggested !== null && suggested !== undefined) {
            c.status = 'suggested';
            c.points = suggested;
        } else {
            c.status = 'open';
            c.points = null;
        }
        row.set(tid, c);
        updateProgress();
    }

    /** Wartet auf laufende Speicherungen und speichert Änderungen im Feld. */
    async function flush() {
        state.autosave?.cancel();
        if (state.savePromise) {
            await state.savePromise;
        }
        const input = state.pointsInput;
        if (input && !readOnly) {
            const parsed = parsePoints(input.value, taskById.get(state.tid).points);
            if (!parsed.ok) {
                input.focus();
                toast(parsed.error, 'warning', 4000);
                return false;
            }
            if (parsed.value !== state.lastSaved) {
                return saveNow();
            }
        }
        return true;
    }

    window.addEventListener('beforeunload', (ev) => {
        const input = state.pointsInput;
        if (!input || readOnly) return;
        const parsed = parsePoints(input.value, taskById.get(state.tid).points);
        if (parsed.ok && parsed.value !== state.lastSaved) {
            ev.preventDefault();
        }
    });

    // ---------- Kommentare ----------
    async function saveComment({ id, anchor, body }) {
        const res = await post(`${boot.api_base}/kommentar`, {
            participation_id: state.pid, task_id: state.tid, id, anchor, body,
        });
        if (!res.ok) {
            toast(res.error, 'error');
            return null;
        }
        const saved = res.data.comment;
        const index = state.comments.findIndex((c) => c.id === saved.id);
        if (index >= 0) state.comments[index] = saved;
        else state.comments.push(saved);
        refreshComments();
        return saved;
    }

    async function deleteComment(id) {
        const res = await post(`${boot.api_base}/kommentar/${id}/loeschen`, {});
        if (!res.ok) {
            toast(res.error, 'error');
            return false;
        }
        state.comments = state.comments.filter((c) => c.id !== id);
        refreshComments();
        return true;
    }

    function refreshComments() {
        state.commentsPanel?.setComments(state.comments);
        state.answerView?.setComments(state.comments);
    }

    // ---------- Replay ----------
    function openReplay() {
        const task = taskById.get(state.tid);
        const dialog = h('dialog', { class: 'g-dialog', 'aria-label': 'Replay' });
        const body = h('div', { class: 'g-dialog-body' });
        const close = h('button', { type: 'button', class: 'btn btn-sm', onclick: () => dialog.close() }, 'Schließen');
        dialog.append(h('div', { class: 'g-dialog-head' }, h('strong', {}, `Replay: Aufgabe ${task.number}`), close), body);
        document.body.append(dialog);
        let instance = null;
        dialog.addEventListener('close', () => {
            try { instance?.destroy?.(); } catch { /* egal */ }
            dialog.remove();
        });
        try {
            instance = mountReplay(body, {
                examId: boot.exam_id, participationId: state.pid, taskId: state.tid, apiBase: boot.pult_api_base,
            });
        } catch (err) {
            console.error('Replay nicht verfügbar', err);
            body.textContent = 'Das Replay konnte nicht gestartet werden.';
        }
        dialog.showModal();
    }

    // ---------- Start ----------
    setMode(state.mode);
    load();
}
