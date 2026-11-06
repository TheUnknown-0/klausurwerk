/**
 * Fragenliste im Stil von Google Forms: alle Aufgaben untereinander als Karten.
 *
 * Die aktive Karte ist aufgeklappt (Titel, Aufgabentext, Antworttyp, Punkte, bei einfachen Typen
 * gleich die Optionen), die anderen zeigen eine Vorschau. Feinheiten wie Musterlösung, Tests,
 * Erwartungshorizont, Operator oder Diagramm-Vorgaben stehen im Detail-Editor ("Details").
 * Gespeichert wird automatisch kurz nach der letzten Eingabe und vor jedem Wechsel.
 */
import { h, icon, toast } from '../lib/dom.js';
import { taskEditor } from '../lib/widgets.js';
import { typePicker } from './type-picker.js';
import { previewButton } from './ui.js';

/** Typen, deren Editor klein genug für die Karte ist. */
const QUICK_TYPES = new Set(['choice', 'gaps', 'matching', 'ordering']);
const LANGS = { java: 'Java', python: 'Python', sql: 'SQL' };
const DEFAULT_MAIN = ['Main.java', 'main.py', 'abfrage.sql'];
const AUTOSAVE_MS = 1200;

const clone = (v) => (v === undefined || v === null ? v : JSON.parse(JSON.stringify(v)));
const same = (a, b) => JSON.stringify(a ?? {}) === JSON.stringify(b ?? {});
const fmt = (n) => String(Math.round(Number(n) * 100) / 100).replace('.', ',');

// ---------- reine Hilfen (getestet) ----------

/** Wert der Typauswahl einer Aufgabe: '' (Überschrift), 'code:python' oder der Typname. */
export function typeValue(task) {
    if (!task.answer_type) {
        return '';
    }
    if (task.answer_type === 'code') {
        const lang = task.config?.language;
        return `code:${LANGS[lang] ? lang : 'java'}`;
    }
    return task.answer_type;
}

/** Gegenstück zu typeValue. */
export function parseTypeValue(value) {
    if (value === '') {
        return { type: '', language: null };
    }
    if (value.startsWith('code:')) {
        return { type: 'code', language: value.slice(5) };
    }
    return { type: value, language: null };
}

/** Optionen der Typauswahl, gruppiert wie die Kacheln; Programmcode je Sprache. */
export function typeOptions(types, { allowContainer = true } = {}) {
    const groups = new Map();
    for (const t of types) {
        if (!groups.has(t.group)) {
            groups.set(t.group, []);
        }
        if (t.name === 'code') {
            for (const [lang, label] of Object.entries(LANGS)) {
                groups.get(t.group).push([`code:${lang}`, `Programmcode (${label})`]);
            }
        } else {
            groups.get(t.group).push([t.name, t.label]);
        }
    }
    const out = [...groups].map(([group, options]) => ({ group, options }));
    if (allowContainer) {
        out.push({ group: 'Gliederung', options: [['', 'Überschrift mit Teilaufgaben']] });
    }
    return out;
}

/** Markdown grob zu Klartext für die Vorschau (Formeln und Code bleiben lesbar). */
export function plainExcerpt(md, max = 220) {
    const text = String(md ?? '')
        .replace(/```[\s\S]*?```/g, ' [Code] ')
        .replace(/!\[([^\]]*)\]\([^)]*\)/g, '[Bild: $1]')
        .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
        .replace(/^#{1,6}\s+/gm, '')
        .replace(/^>\s?/gm, '')
        .replace(/(\*\*|__)(.+?)\1/g, '$2')
        .replace(/[*`]/g, '')
        .replace(/\s+/g, ' ')
        .trim();
    return text.length > max ? `${text.slice(0, max - 1).trimEnd()}…` : text;
}

/**
 * Was die zugeklappte Karte zeigt.
 * @returns {{label: string, excerpt: string, items: {text: string, mark: string, correct: boolean}[]|null, ordered: boolean, summary: string}}
 */
export function previewOf(task, typesByName, childCount = 0) {
    const cfg = task.config ?? {};
    const sol = task.solution ?? {};
    const t = task.answer_type;
    const out = { label: '', excerpt: plainExcerpt(task.body_md), items: null, ordered: false, summary: '' };
    if (!t) {
        out.label = 'Überschrift';
        out.summary = childCount > 0 ? `${childCount} Teilaufgabe${childCount === 1 ? '' : 'n'}` : 'noch keine Teilaufgaben';
        return out;
    }
    out.label = t === 'code' ? `Programmcode (${LANGS[cfg.language] ?? 'Java'})` : (typesByName.get(t)?.label ?? t);
    if (t === 'choice') {
        const correct = new Set(sol.correct ?? []);
        out.items = (cfg.options ?? []).map((o) => ({
            text: plainExcerpt(o.text_md, 120) || '(leer)',
            mark: cfg.multiple ? (correct.has(o.id) ? '☑' : '☐') : (correct.has(o.id) ? '◉' : '○'),
            correct: correct.has(o.id),
        }));
    } else if (t === 'ordering') {
        const byId = new Map((cfg.items ?? []).map((i) => [i.id, i]));
        const order = (sol.order ?? []).filter((id) => byId.has(id));
        const items = order.length === byId.size ? order.map((id) => byId.get(id)) : (cfg.items ?? []);
        out.items = items.map((i) => ({ text: plainExcerpt(i.text_md, 120) || '(leer)', mark: '', correct: false }));
        out.ordered = true;
    } else if (t === 'matching') {
        const right = new Map((cfg.right ?? []).map((r) => [r.id, r]));
        out.items = (cfg.left ?? []).map((l) => ({
            text: `${plainExcerpt(l.text_md, 60) || '(leer)'} gehört zu ${plainExcerpt(right.get(sol.pairs?.[l.id])?.text_md, 60) || '?'}`,
            mark: '',
            correct: false,
        }));
    } else if (t === 'gaps') {
        const n = (cfg.gaps ?? []).length;
        out.summary = `${plainExcerpt(String(cfg.template ?? '').replace(/\{\{[^}]+\}\}/g, '[ … ]'), 160)} (${n} Lücke${n === 1 ? '' : 'n'})`;
    } else if (t === 'code') {
        const tests = (task.tests ?? []).length;
        out.summary = `Startdatei ${cfg.main_file || DEFAULT_MAIN[Object.keys(LANGS).indexOf(cfg.language)] || 'Main.java'}${tests ? `, ${tests} Test${tests === 1 ? '' : 's'}` : ''}`;
    }
    return out;
}

// ---------- Komponente ----------

/**
 * @param {{
 *   host: HTMLElement, boot: any, locked: boolean,
 *   getTasks: () => any[], setTasks: (list: any[]) => void,
 *   call: (path: string, body: object, errorPrefix: string) => Promise<any>,
 *   openDetail: (taskId: number|null) => void,
 *   openPreview: (taskId: number, flush: () => Promise<boolean>) => void,
 * }} opts
 */
export function createQuickList({ host, boot, locked, getTasks, setTasks, call, openDetail, openPreview }) {
    const typesByName = new Map(boot.types.map((t) => [t.name, t]));
    let activeId = null;
    let edit = null; // aktive Karte: { id, task, s, typeInstance, typeTouched, dirty, ... }
    let timer = null;
    let saving = null;
    let editedWhileSaving = false;

    const statusEl = h('span', { class: 'te-status', role: 'status' }, '');
    const summaryEl = h('span', { class: 'qf-count' });
    const listEl = h('div', { class: 'qf-list' });
    const footEl = h('div', { class: 'qf-foot' });
    host.replaceChildren(...[
        h('div', { class: 'qf-head' }, summaryEl, statusEl),
        locked ? h('div', { class: 'alert alert-info' }, 'Die Klausur ist gestartet oder abgeschlossen. Die Liste zeigt die Aufgaben; Musterlösung, Erwartungshorizont und Tests bearbeitest du über „Details“.') : null,
        listEl,
        footEl,
    ].filter(Boolean));

    const tasks = () => getTasks();
    const byId = (id) => tasks().find((t) => t.id === id) ?? null;
    const childrenOf = (id) => tasks().filter((t) => t.parent_id === id);

    function showStatus(text, kind = '') {
        statusEl.textContent = text;
        statusEl.classList.toggle('dirty', kind === 'dirty');
        statusEl.classList.toggle('error', kind === 'error');
    }

    function updateSummary() {
        const questions = tasks().filter((t) => t.answer_type);
        const sum = questions.reduce((a, t) => a + Number(t.points || 0), 0);
        summaryEl.textContent = `${questions.length} Frage${questions.length === 1 ? '' : 'n'}, ${fmt(sum)} Punkte`;
    }

    function changed() {
        if (!edit) {
            return;
        }
        edit.dirty = true;
        if (saving) {
            editedWhileSaving = true;
        }
        showStatus('Ungespeicherte Änderungen ...', 'dirty');
        clearTimeout(timer);
        timer = setTimeout(() => { save(); }, AUTOSAVE_MS);
    }

    // ---------- Vorschau-Karte ----------
    function previewCard(t) {
        const p = previewOf(t, typesByName, childrenOf(t.id).length);
        const open = () => (locked ? openDetail(t.id) : activate(t.id));
        // Der Vorschau-Knopf steht neben der Karte (nicht darin): Die Karte ist selbst ein Knopf.
        return h('div', { class: 'qf-card-wrap' }, cardOf(t, p, open), previewButton(() => openPreview(t.id, flush), { compact: true, number: t.number }));
    }

    function cardOf(t, p, open) {
        return h('div', {
            class: `qf-card is-preview depth-${t.depth}${t.answer_type ? '' : ' is-section'}`,
            tabindex: '0', role: 'button', dataset: { id: String(t.id) },
            'aria-label': `${t.answer_type ? 'Aufgabe' : 'Überschrift'} ${t.number}${t.title ? `: ${t.title}` : ''} bearbeiten`,
            onclick: open,
            onkeydown: (ev) => {
                if (ev.target === ev.currentTarget && (ev.key === 'Enter' || ev.key === ' ')) {
                    ev.preventDefault();
                    open();
                }
            },
        },
        h('div', { class: 'qf-card-head' },
            h('span', { class: 'qf-num' }, t.number),
            h('span', { class: 'qf-title' }, t.title || '(ohne Titel)'),
            h('span', { class: 'badge' }, p.label),
            t.answer_type ? h('span', { class: 'qf-pts' }, `${fmt(t.points)} P.`) : null),
        p.excerpt ? h('p', { class: 'qf-excerpt' }, p.excerpt) : null,
        p.items && p.items.length ? h(p.ordered ? 'ol' : 'ul', { class: `qf-items${p.ordered ? ' is-ordered' : ''}` }, p.items.map((i) => h('li', { class: i.correct ? 'is-correct' : '' },
            i.mark ? h('span', { class: 'qf-mark', 'aria-hidden': 'true' }, i.mark) : null,
            i.text,
            i.correct ? h('span', { class: 'sr-only' }, ' (richtig)') : null))) : null,
        p.summary ? h('p', { class: 'qf-line' }, p.summary) : null);
    }

    // ---------- aktive Karte ----------
    function activeCard(t) {
        const kids = childrenOf(t.id);
        const hasChildren = kids.length > 0;
        const s = {
            title: t.title ?? '',
            body_md: t.body_md ?? '',
            points: t.points ?? 0,
            operator_id: t.operator_id ?? null,
            afb: t.afb ?? null,
            answer_type: t.answer_type ?? '',
            config: clone(t.config) ?? {},
            solution: clone(t.solution) ?? {},
            files: clone(t.files) ?? [],
            tests: clone(t.tests) ?? [],
            model_answer: t.model_answer ?? '',
            expectation_md: t.expectation_md ?? '',
        };

        const titleInput = h('input', { class: 'input qf-title-input', type: 'text', maxlength: 200, 'aria-label': 'Titel der Aufgabe' });
        titleInput.value = s.title;
        titleInput.addEventListener('input', () => { s.title = titleInput.value; changed(); });

        const bodyArea = h('textarea', { class: 'input qf-body', rows: 2, 'aria-label': 'Aufgabentext', placeholder: 'Aufgabentext (Markdown, Formeln mit $…$)' });
        bodyArea.value = s.body_md;
        const grow = () => { bodyArea.style.height = 'auto'; bodyArea.style.height = `${Math.min(bodyArea.scrollHeight + 2, 480)}px`; };
        bodyArea.addEventListener('input', () => { s.body_md = bodyArea.value; grow(); changed(); });

        const typeSel = h('select', { class: 'qf-type', 'aria-label': 'Antworttyp', disabled: hasChildren, title: hasChildren ? 'Eine Überschrift mit Teilaufgaben hat keinen eigenen Antworttyp.' : '' },
            typeOptions(boot.types, { allowContainer: t.parent_id === null })
                .map(({ group, options }) => h('optgroup', { label: group }, options.map(([v, label]) => h('option', { value: v }, label)))));
        typeSel.value = typeValue(t);
        typeSel.addEventListener('change', () => changeType(typeSel.value));

        const pointsInput = h('input', { class: 'input qf-points', type: 'number', min: 0, max: 999, step: 0.5, 'aria-label': 'Punkte' });
        pointsInput.value = s.points;
        pointsInput.addEventListener('input', () => { s.points = pointsInput.value; changed(); });

        const typeHost = h('div', { class: 'qf-type-area' });
        const errEl = h('div', { class: 'te-error', role: 'alert' });

        const iconBtn = (name, label, onClick, extra = {}) => h('button', { type: 'button', class: 'btn btn-sm btn-icon', 'aria-label': label, title: label, onclick: onClick, ...extra }, icon(name));
        const canAddChild = t.parent_id === null && !t.answer_type;
        const foot = h('div', { class: 'qf-card-foot' },
            h('label', { class: 'qf-points-label' }, 'Punkte ', pointsInput),
            h('span', { class: 'qf-spacer' }),
            h('button', { type: 'button', class: 'btn btn-sm', onclick: () => openDetail(t.id), title: 'Musterlösung, Erwartungshorizont, Tests, Operator und alle Einstellungen des Typs' }, icon('pencil'), 'Details'),
            previewButton(() => openPreview(t.id, flush)),
            canAddChild ? h('button', { type: 'button', class: 'btn btn-sm', onclick: () => addChild(t) }, icon('plus'), 'Teilaufgabe') : null,
            h('button', { type: 'button', class: 'btn btn-sm', onclick: () => addAfter(t), title: t.parent_id === null ? 'Neue Aufgabe direkt darunter' : 'Neue Teilaufgabe direkt darunter' }, icon('plus'), 'Frage darunter'),
            iconBtn('copy', 'Duplizieren', () => duplicate(t)),
            iconBtn('arrow-up', 'Nach oben', () => move(t, 'up'), { dataset: { move: 'up' } }),
            iconBtn('arrow-down', 'Nach unten', () => move(t, 'down'), { dataset: { move: 'down' } }),
            iconBtn('trash-2', 'Löschen', () => remove(t), { class: 'btn btn-sm btn-icon btn-danger-ghost' }),
        );

        const card = h('div', { class: `qf-card is-active depth-${t.depth}${t.answer_type ? '' : ' is-section'}`, dataset: { id: String(t.id) } },
            h('div', { class: 'qf-card-row' }, h('span', { class: 'qf-num' }, t.number), titleInput, typeSel),
            bodyArea, typeHost, errEl, foot);

        edit = { id: t.id, task: t, s, card, titleInput, bodyArea, typeSel, pointsInput, typeHost, errEl, hasChildren, typeInstance: null, typeTouched: false, dirty: false, grow };
        applyTypeUi();
        mountType();
        return card;
    }

    function applyTypeUi() {
        const { s } = edit;
        const container = s.answer_type === '';
        edit.titleInput.placeholder = container ? 'Überschrift, z. B. Ausgangslage' : 'Frage';
        edit.pointsInput.disabled = container || edit.hasChildren;
        if (container) {
            edit.pointsInput.value = edit.hasChildren ? fmt(edit.task.points) : '';
        }
    }

    function mountType() {
        const { s, typeHost } = edit;
        edit.typeInstance?.destroy?.();
        edit.typeInstance = null;
        typeHost.replaceChildren();
        const factory = QUICK_TYPES.has(s.answer_type) ? taskEditor(s.answer_type) : null;
        if (factory) {
            edit.typeInstance = factory.mount(typeHost, {
                config: s.config, solution: s.solution, files: s.files, tests: s.tests, locked: false,
                onChange: () => { edit.typeTouched = true; changed(); },
            }) ?? null;
            return;
        }
        let hint;
        if (s.answer_type === '') {
            hint = edit.hasChildren
                ? 'Überschrift mit Teilaufgaben: Der Text ist die gemeinsame Ausgangslage, die Punkte ergeben sich aus den Teilaufgaben.'
                : 'Überschrift für Teilaufgaben: Lege mit „Teilaufgabe“ die einzelnen Fragen darunter an.';
        } else if (s.answer_type === 'text') {
            hint = 'Antwort als freier Text. Mindestwortzahl, Höchstlänge und Erwartungshorizont stehen in den Details.';
        } else if (s.answer_type === 'code') {
            hint = `Programmcode in ${LANGS[s.config.language] ?? 'Java'}. Startdateien, Tests und Musterlösung legst du in den Details an.`;
        } else {
            hint = `${typesByName.get(s.answer_type)?.label ?? s.answer_type}: Vorgabe und Musterlösung legst du in den Details an.`;
        }
        typeHost.append(h('p', { class: 'qf-hint' }, hint));
    }

    function hasTypeContent() {
        const { s, task } = edit;
        if (!s.answer_type) {
            return false;
        }
        const def = typesByName.get(s.answer_type);
        const storedSameType = task.answer_type === s.answer_type;
        return edit.typeTouched || s.model_answer !== '' || s.tests.length > 0 || s.files.length > 0
            || (storedSameType && !same(task.config, def?.defaultConfig));
    }

    function changeType(value) {
        const { s } = edit;
        const { type, language } = parseTypeValue(value);
        if (type === 'code' && s.answer_type === 'code') {
            // Nur die Sprache wechselt: Tests und Dateien bleiben, Standard-Startdatei passt sich an.
            s.config.language = language;
            if (!s.config.main_file || DEFAULT_MAIN.includes(s.config.main_file)) {
                s.config.main_file = '';
            }
            mountType();
            changed();
            return;
        }
        if (type === s.answer_type) {
            return;
        }
        if (hasTypeContent() && !window.confirm('Antworttyp wechseln? Optionen, Lösung und Tests des bisherigen Typs gehen dabei verloren.')) {
            edit.typeSel.value = typeValue({ answer_type: s.answer_type, config: s.config });
            return;
        }
        const def = typesByName.get(type);
        s.answer_type = type;
        s.config = clone(def?.defaultConfig) ?? {};
        s.solution = clone(def?.defaultSolution) ?? {};
        s.files = [];
        s.tests = [];
        s.model_answer = '';
        if (type === 'code') {
            s.config.language = language;
            s.config.main_file = '';
        }
        edit.typeTouched = false;
        applyTypeUi();
        mountType();
        changed();
    }

    function payloadOf(e) {
        const { s } = e;
        const v = e.typeInstance?.getValue?.() ?? null;
        return {
            title: s.title,
            body_md: s.body_md,
            points: s.answer_type === '' ? 0 : Number(s.points) || 0,
            operator_id: s.operator_id === null || s.operator_id === '' ? null : Number(s.operator_id),
            afb: s.afb === '' ? null : s.afb,
            answer_type: s.answer_type === '' ? null : s.answer_type,
            config: v?.config ?? s.config,
            solution: v?.solution ?? s.solution,
            model_answer: s.model_answer,
            expectation_md: s.expectation_md,
            tests: v?.tests ?? s.tests,
            files: v?.files ?? s.files,
        };
    }

    /** @returns {Promise<boolean>} ob danach alles gespeichert ist */
    async function save() {
        clearTimeout(timer);
        if (saving) {
            await saving;
        }
        const target = edit;
        if (!target || !target.dirty) {
            return true;
        }
        const problems = target.typeInstance?.validate?.() ?? [];
        target.errEl.textContent = problems.join(' ');
        if (problems.length > 0) {
            showStatus('Nicht gespeichert: Angaben auf der Karte prüfen', 'error');
            return false;
        }
        editedWhileSaving = false;
        showStatus('Speichert ...');
        const run = call(`/${target.id}`, payloadOf(target), 'Speichern fehlgeschlagen: ');
        saving = run;
        const res = await run;
        saving = null;
        if (res === null) {
            // Eine andere Aktion läuft gerade: gleich noch einmal.
            clearTimeout(timer);
            timer = setTimeout(() => { save(); }, AUTOSAVE_MS);
            return false;
        }
        if (!res.ok) {
            showStatus('Nicht gespeichert', 'error');
            target.errEl.textContent = res.error ?? '';
            return false;
        }
        setTasks(res.data.tasks);
        // Die Karte bleibt, wie sie ist (Fokus und Cursor). Nummer, Titel und Punkte stimmen ohnehin.
        target.task = byId(target.id) ?? target.task;
        updateSummary();
        if (edit === target && editedWhileSaving) {
            clearTimeout(timer);
            timer = setTimeout(() => { save(); }, AUTOSAVE_MS);
            return false;
        }
        target.dirty = false;
        target.errEl.textContent = '';
        showStatus('Alles gespeichert');
        return true;
    }

    /** Offene Änderungen speichern; gelingt das nicht, entscheidet die Lehrkraft. */
    async function flush() {
        clearTimeout(timer);
        for (let i = 0; i < 3 && edit && (edit.dirty || saving); i += 1) {
            if (saving) {
                await saving;
                continue;
            }
            if (!(await save())) {
                break;
            }
        }
        if (edit?.dirty && !window.confirm('Die Änderungen an dieser Frage ließen sich nicht speichern (Hinweis auf der Karte). Trotzdem weiter und die Änderungen verwerfen?')) {
            return false;
        }
        return true;
    }

    // ---------- Aufbau ----------
    function render({ focus = null } = {}) {
        clearTimeout(timer);
        edit?.typeInstance?.destroy?.();
        edit = null;
        listEl.replaceChildren();
        footEl.replaceChildren();
        updateSummary();
        const list = tasks();
        if (activeId !== null && !byId(activeId)) {
            activeId = null;
        }
        if (list.length === 0) {
            showStatus('');
            if (locked) {
                listEl.append(h('p', { class: 'text-soft' }, 'Diese Klausur hat keine Aufgaben.'));
                return;
            }
            const picker = typePicker({
                types: boot.types,
                heading: 'Erste Frage: Welcher Antworttyp?',
                onPick: (tile) => create({ parent_id: null, answer_type: tile.type || null }, tile.config?.language && tile.config.language !== 'java' ? tile.config : null),
            });
            listEl.append(h('div', { class: 'card card-pad' }, picker.el));
            picker.focus();
            return;
        }
        for (const t of list) {
            listEl.append(t.id === activeId && !locked ? activeCard(t) : previewCard(t));
        }
        if (!locked) {
            footEl.append(
                h('button', { type: 'button', class: 'btn btn-primary', onclick: () => addEnd() }, icon('plus'), 'Frage hinzufügen'),
                h('button', { type: 'button', class: 'btn', onclick: () => create({ parent_id: null, answer_type: null }) }, icon('list-ordered'), 'Überschrift mit Teilaufgaben'),
            );
        }
        showStatus(activeId !== null ? 'Alles gespeichert' : '');
        if (edit) {
            edit.grow();
            if (focus === 'title') {
                edit.titleInput.focus({ preventScroll: true });
            } else if (focus === 'up' || focus === 'down') {
                edit.card.querySelector(`[data-move="${focus}"]`)?.focus?.();
            }
            edit.card.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' });
        }
    }

    async function activate(id, { focus = 'title' } = {}) {
        if (id === activeId || !(await flush())) {
            return;
        }
        activeId = id;
        render({ focus });
    }

    // ---------- Struktur ----------
    async function create(body, config = null) {
        if (!(await flush())) {
            return;
        }
        const res = await call('', body, 'Frage konnte nicht angelegt werden: ');
        if (!res?.ok) {
            return;
        }
        setTasks(res.data.tasks);
        activeId = res.data.task_id;
        render({ focus: 'title' });
        if (config && edit && edit.s.answer_type === 'code') {
            edit.s.config = { ...edit.s.config, ...clone(config) };
            mountType();
            changed();
        }
    }

    const langOf = (t) => (t?.answer_type === 'code' && t.config?.language && t.config.language !== 'java' ? { language: t.config.language, main_file: '' } : null);

    function addAfter(t) {
        const type = t.answer_type || 'text';
        create({ parent_id: t.parent_id, answer_type: type, after_id: t.id }, t.answer_type ? langOf(t) : null);
    }

    function addChild(container) {
        const kids = childrenOf(container.id);
        const last = kids[kids.length - 1];
        create({ parent_id: container.id, answer_type: last?.answer_type || 'text' }, langOf(last));
    }

    function addEnd() {
        const ref = byId(activeId) ?? [...tasks()].reverse().find((t) => t.answer_type) ?? null;
        create({ parent_id: null, answer_type: ref?.answer_type || 'text' }, langOf(ref));
    }

    async function duplicate(t) {
        if (!(await flush())) {
            return;
        }
        const res = await call(`/${t.id}/duplizieren`, {}, 'Duplizieren nicht möglich: ');
        if (res?.ok) {
            setTasks(res.data.tasks);
            activeId = res.data.task_id;
            render({ focus: 'title' });
            toast('Frage dupliziert.', 'success', 2000);
        }
    }

    async function move(t, direction) {
        if (!(await flush())) {
            return;
        }
        const res = await call(`/${t.id}/verschieben`, { direction }, 'Verschieben nicht möglich: ');
        if (res?.ok) {
            setTasks(res.data.tasks);
            activeId = t.id;
            render({ focus: direction });
        }
    }

    async function remove(t) {
        const kids = childrenOf(t.id).length;
        const msg = `${t.answer_type ? 'Aufgabe' : 'Überschrift'} ${t.number} "${t.title || 'ohne Titel'}" wirklich löschen?${kids ? ` Ihre ${kids} Teilaufgabe(n) werden ebenfalls gelöscht.` : ''} Das lässt sich nicht rückgängig machen.`;
        if (!window.confirm(msg)) {
            return;
        }
        clearTimeout(timer);
        if (saving) {
            await saving;
        }
        const list = tasks();
        const idx = list.findIndex((x) => x.id === t.id);
        const res = await call(`/${t.id}/loeschen`, {}, 'Löschen nicht möglich: ');
        if (res?.ok) {
            if (edit?.id === t.id) {
                edit.dirty = false;
            }
            setTasks(res.data.tasks);
            const next = list.slice(idx + 1).find((x) => x.parent_id !== t.id) ?? list[idx - 1] ?? null;
            activeId = next && res.data.tasks.some((x) => x.id === next.id) ? next.id : null;
            render({ focus: 'title' });
        }
    }

    return {
        render,
        flush,
        activate,
        /** Sofort speichern (Strg+S). */
        async saveNow() {
            const ok = await save();
            if (ok) {
                toast('Alles gespeichert.', 'success', 2000);
            }
            return ok;
        },
        setActive(id) { activeId = id; },
        get activeId() { return activeId; },
        get pending() { return !!edit?.dirty || !!saving; },
        destroy() {
            clearTimeout(timer);
            edit?.typeInstance?.destroy?.();
            edit = null;
            // Karten ganz abbauen: Optionsfelder der Liste und des Detail-Editors dürfen sich nicht begegnen.
            listEl.replaceChildren();
            footEl.replaceChildren();
        },
    };
}
