/**
 * Formular der gewählten Aufgabe: Titel, Text, Punkte, Operator/AFB, Antworttyp mit
 * typspezifischem Editor, Musterlösung, Erwartungshorizont, Material und Testen.
 */
import { h } from '../lib/dom.js';
import { taskEditor } from '../lib/widgets.js';
import { createMarkdownField } from './md-field.js';
import { testModelAnswer } from './run-test.js';
import { button, labeled, numberInput, select, textInput, textarea } from './ui.js';

const clone = (v) => JSON.parse(JSON.stringify(v ?? {}));

/** Ersatz-Editor für Typen ohne registrierten TaskEditor: JSON-Felder für config und solution. */
function fallbackEditor(container, ctx) {
    let config = JSON.stringify(ctx.config ?? {}, null, 2);
    let solution = JSON.stringify(ctx.solution ?? {}, null, 2);
    const cfgArea = textarea(config, (v) => { config = v; ctx.onChange(); }, { rows: 8, class: 'input mono', disabled: !!ctx.locked, spellcheck: 'false' });
    const solArea = textarea(solution, (v) => { solution = v; ctx.onChange(); }, { rows: 6, class: 'input mono', spellcheck: 'false' });
    const root = h('div', { class: 'te-type-box' },
        h('p', { class: 'text-sm text-soft' }, 'Für diesen Antworttyp gibt es noch keinen eigenen Editor. Konfiguration und Lösung können als JSON bearbeitet werden (Format: docs/DATENFORMATE.md).'),
        labeled('Konfiguration (JSON)', cfgArea),
        labeled('Lösung (JSON)', solArea),
    );
    container.append(root);
    const parse = (text, what) => {
        try {
            const v = JSON.parse(text || '{}');
            if (v === null || typeof v !== 'object' || Array.isArray(v)) {
                throw new Error('kein Objekt');
            }
            return { value: v };
        } catch (e) {
            return { error: `${what} ist kein gültiges JSON-Objekt (${e.message}).` };
        }
    };
    return {
        getValue: () => ({ config: parse(config, 'Konfiguration').value ?? {}, solution: parse(solution, 'Lösung').value ?? {} }),
        validate: () => [parse(config, 'Konfiguration').error, parse(solution, 'Lösung').error].filter(Boolean),
        destroy: () => root.remove(),
    };
}

/**
 * @param {{host: HTMLElement, task: any, boot: any, hasChildren: boolean, onChange: () => void}} opts
 */
export function createTaskForm({ host, task, boot, hasChildren, onChange }) {
    const locked = !!boot.exam.locked;
    const s = {
        title: task.title ?? '',
        points: task.points ?? 0,
        operator_id: task.operator_id ?? '',
        afb: task.afb ?? '',
        answer_type: task.answer_type ?? '',
    };
    let typeInstance = null;
    let modelField = null;
    let expectationField = null;
    const errors = {};
    const errBox = (name) => (errors[name] ??= h('div', { class: 'te-error', role: 'alert' }));
    const typeHost = h('div', {});
    const modelHost = h('div', {});
    const testLog = h('pre', { class: 'te-run-log', hidden: true, 'aria-live': 'polite' });
    const changed = () => onChange();

    // ---------- Grunddaten ----------
    const titleInput = textInput(s.title, (v) => { s.title = v; changed(); }, { maxlength: 200, disabled: locked });
    const bodyField = createMarkdownField({
        label: 'Aufgabentext (Markdown, Formeln mit $...$)', value: task.body_md ?? '', examId: boot.exam_id,
        previewUrl: boot.urls.preview, onChange: changed,
    });
    if (locked) {
        bodyField.setReadOnly?.(true);
    }

    const materialSel = select([['', 'Material einfügen ...'], ...boot.materials.map((m) => [String(m.id), m.name])], '', () => {}, { 'aria-label': 'Material auswählen', disabled: locked });
    const materialBtn = button('Einfügen', () => {
        const m = boot.materials.find((x) => String(x.id) === materialSel.value);
        if (m) {
            bodyField.insertAtCursor(`![${m.name.replace(/[\]\[]/g, '')}](material:${m.id})`);
        }
    }, { disabled: locked });
    const materialRow = h('div', { class: 'te-mat' }, materialSel, materialBtn,
        h('a', { href: boot.urls.materials, class: 'text-sm' }, 'Material verwalten'));

    const operatorSel = select([['', '(kein Operator)'], ...boot.operators.map((o) => [String(o.id), `${o.name} (AFB ${o.afb})`])], String(s.operator_id ?? ''), (v) => {
        s.operator_id = v;
        const op = boot.operators.find((o) => String(o.id) === v);
        if (op && s.afb === '' && boot.afb.includes(op.afb)) {
            s.afb = op.afb;
            afbSel.value = op.afb;
        }
        changed();
    }, { disabled: locked });
    const afbSel = select([['', '(offen)'], ...boot.afb.map((a) => [a, `AFB ${a}`])], s.afb, (v) => { s.afb = v; changed(); }, { disabled: locked });

    const groups = new Map();
    for (const t of boot.types) {
        if (!groups.has(t.group)) {
            groups.set(t.group, []);
        }
        groups.get(t.group).push(t);
    }
    const typeSel = h('select', { disabled: locked || hasChildren },
        h('option', { value: '' }, 'Überschrift ohne Antwort (Container)'),
        [...groups].map(([group, types]) => h('optgroup', { label: group }, types.map((t) => h('option', { value: t.name }, t.label)))),
    );
    typeSel.value = s.answer_type;
    // Unbekannter Typ (Modul fehlt): trotzdem anzeigen.
    if (typeSel.value !== s.answer_type && s.answer_type) {
        typeSel.append(h('option', { value: s.answer_type }, `${s.answer_type} (unbekannt)`));
        typeSel.value = s.answer_type;
    }
    typeSel.addEventListener('change', () => {
        s.answer_type = typeSel.value;
        const def = boot.types.find((t) => t.name === s.answer_type);
        mountType({ config: clone(def?.defaultConfig), solution: clone(def?.defaultSolution), files: [], tests: [] });
        renderModel(true);
        updatePointsState();
        changed();
    });

    const pointsInput = numberInput(s.points, (v) => { s.points = v; changed(); }, { min: 0, max: 999, step: 0.5 });
    const pointsHint = h('div', { class: 'hint' });
    function updatePointsState() {
        const container = s.answer_type === '';
        pointsInput.disabled = locked || container || hasChildren;
        pointsHint.textContent = hasChildren
            ? 'Summe der Teilaufgaben (wird automatisch berechnet).'
            : container ? 'Überschriften haben keine eigenen Punkte.' : 'Punkte für diese Aufgabe, auch halbe Punkte sind möglich.';
    }

    // ---------- Typ-Editor ----------
    function mountType(initial) {
        typeInstance?.destroy();
        typeHost.replaceChildren();
        typeInstance = null;
        errBox('type').textContent = '';
        if (s.answer_type === '') {
            return;
        }
        const factory = taskEditor(s.answer_type);
        const ctx = {
            config: initial.config,
            solution: initial.solution,
            files: initial.files,
            tests: initial.tests,
            locked,
            onChange: () => {
                if (s.answer_type === 'code' && modelField && typeInstance?.getLanguage) {
                    modelField.setLanguage(typeInstance.getLanguage());
                }
                changed();
            },
        };
        typeInstance = (factory ?? { mount: fallbackEditor }).mount(typeHost, ctx) ?? null;
    }

    // ---------- Musterlösung, Erwartungshorizont ----------
    let modelValue = task.model_answer ?? '';
    function renderModel(reset = false) {
        if (modelField) {
            modelValue = modelField.getValue();
            modelField.destroy();
            modelField = null;
        }
        modelHost.replaceChildren();
        if (s.answer_type === '') {
            return;
        }
        if (reset) {
            modelValue = '';
        }
        const isCode = s.answer_type === 'code';
        modelField = createMarkdownField({
            label: isCode ? 'Musterlösung (Code)' : 'Musterlösung (Markdown)',
            value: modelValue, examId: boot.exam_id, previewUrl: boot.urls.preview, onChange: changed,
            language: isCode ? (typeInstance?.getLanguage?.() ?? 'java') : 'markdown',
            size: isCode ? 'tall' : '',
            hint: 'Nur für Lehrkräfte sichtbar. Bleibt auch nach dem Start bearbeitbar.',
        });
        modelHost.append(modelField.el);
        if (isCode) {
            const run = button('▶ Musterlösung testen', async () => {
                run.disabled = true;
                testLog.hidden = false;
                testLog.textContent = 'Läuft ...';
                const v = typeInstance?.getValue() ?? { config: {}, files: [] };
                const result = await testModelAnswer({ config: v.config, files: v.files, model_answer: modelField.getValue() }, {
                    examId: boot.exam_id, settings: boot.settings, assets: boot.assets,
                });
                testLog.textContent = `${result.ok ? 'Alles in Ordnung.' : 'Hinweis:'}\n${result.lines.join('\n')}`;
                run.disabled = false;
            });
            modelHost.append(h('div', { class: 'cluster' }, run), testLog);
        }
    }

    expectationField = createMarkdownField({
        label: 'Erwartungshorizont (Markdown)', value: task.expectation_md ?? '', examId: boot.exam_id,
        previewUrl: boot.urls.preview, onChange: changed, size: 'short',
        hint: 'Bewertungskriterien für die Korrektur. Bleibt auch nach dem Start bearbeitbar.',
    });

    // ---------- Zusammenbau ----------
    mountType({ config: clone(task.config), solution: clone(task.solution), files: clone(task.files ?? []), tests: clone(task.tests ?? []) });
    renderModel();
    updatePointsState();

    const root = h('div', {},
        locked ? h('div', { class: 'alert alert-info te-locked-note' },
            'Die Klausur ist gestartet oder abgeschlossen. Aufgabentext, Punkte und Konfiguration sind gesperrt. Musterlösung, Erwartungshorizont, Lösungen und Tests bleiben bearbeitbar.') : null,
        h('div', { class: 'te-grid' },
            h('div', {}, labeled('Titel', titleInput), errBox('title')),
        ),
        bodyField.el, materialRow,
        h('div', { class: 'te-section' },
            h('div', { class: 'te-grid' },
                h('div', {}, labeled('Antworttyp', typeSel), errBox('answer_type')),
                h('div', {}, labeled('Punkte', pointsInput), pointsHint, errBox('points')),
                labeled('Operator', operatorSel),
                h('div', {}, labeled('Anforderungsbereich', afbSel), errBox('afb')),
            ),
        ),
        h('div', { class: 'te-section' }, typeHost, errBox('type')),
        h('div', { class: 'te-section' }, modelHost, expectationField.el),
    );
    host.replaceChildren(root);

    return {
        /** Vollständiger Stand für die Speicher-API. */
        getPayload() {
            const v = typeInstance?.getValue() ?? { config: {}, solution: {} };
            return {
                title: s.title,
                body_md: bodyField.getValue(),
                points: s.answer_type === '' ? 0 : Number(s.points) || 0,
                operator_id: s.operator_id === '' ? null : Number(s.operator_id),
                afb: s.afb === '' ? null : s.afb,
                answer_type: s.answer_type === '' ? null : s.answer_type,
                config: v.config ?? {},
                solution: v.solution ?? {},
                model_answer: modelField ? modelField.getValue() : '',
                expectation_md: expectationField.getValue(),
                tests: v.tests ?? [],
                files: v.files ?? [],
            };
        },
        /** @returns {string[]} Fehlermeldungen des Typ-Editors (leer = in Ordnung) */
        validate() {
            const list = typeInstance?.validate?.() ?? [];
            errBox('type').textContent = list.join(' ');
            return list;
        },
        showServerError(field, message) {
            const key = ['config', 'solution', 'tests', 'files'].includes(field) ? 'type' : field;
            if (errors[key]) {
                errors[key].textContent = message;
                return true;
            }
            return false;
        },
        clearErrors() {
            Object.values(errors).forEach((el) => { el.textContent = ''; });
        },
        focus: () => titleInput.focus(),
        destroy() {
            typeInstance?.destroy();
            bodyField.destroy();
            modelField?.destroy();
            expectationField.destroy();
            host.replaceChildren();
        },
    };
}
