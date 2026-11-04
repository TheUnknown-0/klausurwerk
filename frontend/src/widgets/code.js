/**
 * Antwort-Widget "code": Editor mit Dateitabs, Werkzeugleiste und Ausgabebereich
 * für Java, Python und SQL (Format: docs/DATENFORMATE.md, Abschnitt 2.1).
 *
 * Inhalt:  { files: [{ name, content }] }   (nur bearbeitbare Dateien)
 * Änderung: ctx.onChange(content, { kind:'ops', payload:{file, changes}, file, isPaste })
 *           bei Dateioperationen: { kind:'value' }
 */
import { Prec } from '@codemirror/state';
import { keymap } from '@codemirror/view';
import { lintGutter } from '@codemirror/lint';
import { registerAnswerWidget } from '../lib/widgets.js';
import { createCodeEditor } from '../lib/code-editor.js';
import { h } from '../lib/dom.js';
import { createOutputPanel } from '../runtime/output-panel.js';
import { buildFileList, pickMainFile, suggestFileName, toContent, validateFileName } from '../runtime/files.js';
import { groupByFile, normalizeDiagnostics, toEditorDiagnostics } from '../runtime/diagnostics.js';
import { collectSession, runSampleTests } from '../runtime/sample-tests.js';

const HEIGHT_KEY = 'kw.codeOutputHeight';

const storage = {
    get(key) {
        try {
            return window.localStorage.getItem(key);
        } catch {
            return null;
        }
    },
    set(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch {
            // Speichern ist nur eine Annehmlichkeit
        }
    },
};

/** Sprache für den Editor je Dateiendung. */
function editorLanguage(name, fallback) {
    const n = name.toLowerCase();
    if (n.endsWith('.java')) return 'java';
    if (n.endsWith('.py')) return 'python';
    if (n.endsWith('.sql')) return 'sql';
    if (n.endsWith('.md')) return 'markdown';
    if (n.endsWith('.txt') || n.endsWith('.csv') || n.endsWith('.json')) return '';
    return fallback;
}

const LANGUAGE_LABEL = { java: 'Java', python: 'Python', sql: 'SQL' };

const CodeWidget = {
    layout: 'split',

    mount(container, ctx) {
        const task = ctx.task ?? {};
        const config = task.config ?? {};
        const language = ['java', 'python', 'sql'].includes(config.language) ? config.language : 'java';
        const env = ctx.env ?? {};
        const settings = env.settings ?? {};
        const runtime = env.runtime ?? null;
        const editorSettings = settings.editor ?? {};
        const mode = ctx.mode ?? 'exam';
        const examMode = env.examMode ?? env.exam?.mode ?? null;

        let readOnly = !!ctx.readOnly;
        let destroyed = false;
        let session = null;
        let running = false;
        let testing = false;
        let cancelTests = false;

        /** @type {{name:string, content:string, readonly:boolean}[]} */
        const files = buildFileList(task.files, ctx.content, { ...config, language });
        const taskFileNames = new Set((task.files ?? []).map((f) => f.name));
        let activeName = (files.find((f) => !f.readonly) ?? files[0]).name;
        /** @type {Map<string, {editor: any, pane: HTMLElement}>} */
        const editors = new Map();
        /** Diagnosen, die noch keinem Editor zugewiesen werden konnten (Editor wird lazy erzeugt). */
        const pendingDiagnostics = new Map();

        // ---------- Aufbau ----------
        const runBtn = h('button', { class: 'btn btn-sm btn-accent cw-run', type: 'button', onclick: () => startRun() });
        const stopBtn = h('button', { class: 'btn btn-sm btn-danger-ghost', type: 'button', disabled: true, onclick: () => stopRun() }, 'Stopp');
        const clearBtn = h('button', { class: 'btn btn-sm', type: 'button', onclick: () => panel.console.clear() }, 'Konsole leeren');
        const testBtn = h('button', { class: 'btn btn-sm', type: 'button', onclick: () => startSampleTests() }, 'Beispieltests');
        const schemaBtn = h('button', { class: 'btn btn-sm', type: 'button', onclick: () => showSchema() }, 'Datenbank anzeigen');
        const statusEl = h('span', { class: 'cw-status', role: 'status', 'aria-live': 'polite' });
        const langBadge = h('span', { class: 'cw-lang badge badge-accent' }, LANGUAGE_LABEL[language]);
        const toolbar = h('div', { class: 'cw-toolbar', role: 'toolbar', 'aria-label': 'Programm ausführen' },
            runBtn, stopBtn, clearBtn, testBtn, schemaBtn, h('span', { class: 'cw-spacer' }), statusEl, langBadge);

        const stdinField = h('textarea', {
            class: 'input cw-stdin-field', rows: '3', id: `cw-stdin-${task.id ?? 'x'}`, spellcheck: 'false',
            placeholder: 'Eine Zeile je input()', 'aria-describedby': `cw-stdin-hint-${task.id ?? 'x'}`,
        });
        stdinField.value = typeof config.stdin === 'string' ? config.stdin : '';
        const stdinBox = h('div', { class: 'cw-stdin', hidden: true },
            h('label', { for: stdinField.id }, 'Eingabe vorab'),
            stdinField,
            h('p', { class: 'hint', id: `cw-stdin-hint-${task.id ?? 'x'}` },
                'In diesem Browser-Fenster kann das Programm nicht mitten im Lauf nach Eingaben fragen. Trage deine Eingaben vorab ein, eine Zeile für jeden Aufruf von input().'));

        const tabBar = h('div', { class: 'cw-filetabs', role: 'tablist', 'aria-label': 'Dateien' });
        const fileTools = h('div', { class: 'cw-filetools' });
        const fileForm = h('div', { class: 'cw-fileform', hidden: true });
        const filebar = h('div', { class: 'cw-filebar' }, tabBar, fileTools);
        const editorHost = h('div', { class: 'cw-editors' });
        const resizer = h('div', {
            class: 'cw-resizer', role: 'separator', 'aria-orientation': 'horizontal', tabindex: '0',
            'aria-label': 'Höhe der Ausgabe ändern (Pfeiltasten hoch und runter)',
        });
        const root = h('div', { class: 'cw', 'data-language': language });
        const panel = createOutputPanel(root, {
            onSubmitInput: (text) => session?.sendInput(`${text}\n`),
            onJump: (file, line) => jumpTo(file, line),
        });
        root.prepend(toolbar, stdinBox, filebar, fileForm, editorHost, resizer);
        container.append(root);

        // ---------- Höhe des Ausgabebereichs ----------
        const savedHeight = parseInt(storage.get(HEIGHT_KEY) ?? '', 10);
        let outputHeight = Number.isFinite(savedHeight) ? savedHeight : 0;

        function clampHeight(px) {
            const total = root.clientHeight || 600;
            return Math.max(90, Math.min(px, Math.max(90, total - 170)));
        }

        function applyHeight() {
            const total = root.clientHeight || 600;
            const px = clampHeight(outputHeight > 0 ? outputHeight : Math.round(total * 0.38));
            panel.element.style.height = `${px}px`;
            resizer.setAttribute('aria-valuenow', String(px));
            return px;
        }

        resizer.addEventListener('pointerdown', (ev) => {
            ev.preventDefault();
            resizer.setPointerCapture(ev.pointerId);
            resizer.classList.add('is-dragging');
            const move = (e) => {
                const rect = root.getBoundingClientRect();
                outputHeight = clampHeight(rect.bottom - e.clientY);
                applyHeight();
            };
            const up = () => {
                resizer.releasePointerCapture?.(ev.pointerId);
                resizer.classList.remove('is-dragging');
                resizer.removeEventListener('pointermove', move);
                resizer.removeEventListener('pointerup', up);
                resizer.removeEventListener('pointercancel', up);
                storage.set(HEIGHT_KEY, String(outputHeight));
            };
            resizer.addEventListener('pointermove', move);
            resizer.addEventListener('pointerup', up);
            resizer.addEventListener('pointercancel', up);
        });
        resizer.addEventListener('keydown', (ev) => {
            if (ev.key !== 'ArrowUp' && ev.key !== 'ArrowDown') {
                return;
            }
            ev.preventDefault();
            outputHeight = clampHeight(applyHeight() + (ev.key === 'ArrowUp' ? 30 : -30));
            applyHeight();
            storage.set(HEIGHT_KEY, String(outputHeight));
        });
        const resizeObserver = typeof ResizeObserver === 'function' ? new ResizeObserver(() => applyHeight()) : null;
        resizeObserver?.observe(root);
        applyHeight();

        // ---------- Rechte und Sichtbarkeit ----------
        const runModeOf = () => (runtime?.runMode ? runtime.runMode(task) : (config.run_mode ?? settings.run_mode ?? 'run'));
        const runCapable = () => !!runtime && runtime.canRun(task) && runModeOf() !== 'none';
        const teacherMode = mode === 'grading' || mode === 'review' || mode === 'preview';
        const mayRun = () => runCapable() && (!readOnly || mode === 'grading' || mode === 'review');
        const showSamples = () => (mode === 'preview' || examMode === 'practice')
            && Array.isArray(config.sample_tests) && config.sample_tests.length > 0 && mayRun();
        const allowFileOps = () => !!config.allow_new_files && !readOnly;
        const needsStdinField = () => language === 'python' && !!runtime && runtime.interactiveInput === false;
        const hasInteractiveInput = () => (language === 'java' ? true : language === 'python' ? runtime?.interactiveInput !== false : false);

        function refreshUi() {
            const syntaxOnly = runModeOf() === 'syntax';
            const canRun = mayRun();
            runBtn.hidden = !canRun;
            runBtn.disabled = running || testing;
            runBtn.textContent = running ? 'Läuft …' : syntaxOnly ? 'Syntax prüfen' : 'Ausführen';
            runBtn.title = `${syntaxOnly ? 'Syntax prüfen' : 'Ausführen'} (Strg+Enter)`;
            stopBtn.hidden = !canRun;
            stopBtn.disabled = !(running || testing);
            clearBtn.hidden = !canRun;
            testBtn.hidden = !showSamples() || syntaxOnly;
            testBtn.disabled = running || testing;
            schemaBtn.hidden = !(language === 'sql' && canRun && typeof runtime?.sqlSchema === 'function');
            stdinBox.hidden = !(needsStdinField() && canRun && !syntaxOnly);
            toolbar.hidden = !canRun;
            panel.console.setInputVisible(running && !syntaxOnly && hasInteractiveInput());
            renderFileTabs();
        }

        function setStatus(text, kind = '') {
            statusEl.textContent = text;
            statusEl.dataset.kind = kind;
        }

        // ---------- Editoren und Dateien ----------
        const comfort = {
            autocomplete: !!editorSettings.autocomplete,
            lint: !!editorSettings.lint,
            closeBrackets: editorSettings.close_brackets !== false,
        };

        function fileByName(name) {
            return files.find((f) => f.name === name) ?? null;
        }

        function notify(meta) {
            ctx.onChange?.(toContent(files), meta);
        }

        function ensureEditor(file) {
            let entry = editors.get(file.name);
            if (entry) {
                return entry;
            }
            const pane = h('div', {
                class: 'cw-editor-pane', role: 'tabpanel', hidden: true,
                'aria-label': `Editor ${file.name}`,
            });
            editorHost.append(pane);
            const runShortcut = () => {
                if (mayRun() && !running && !testing) {
                    startRun();
                }
                return true;
            };
            const editor = createCodeEditor({
                parent: pane,
                doc: file.content,
                language: editorLanguage(file.name, language),
                readOnly: readOnly || file.readonly,
                comfort,
                fontSize: editorSettings.font_size ?? 15,
                onChange: (value, meta) => {
                    file.content = value;
                    notify({ kind: 'ops', payload: { file: file.name, changes: meta.changes }, file: file.name, isPaste: meta.isPaste });
                },
                extensions: [
                    ...(comfort.lint ? [] : [lintGutter()]),
                    Prec.highest(keymap.of([
                        { key: 'Mod-Enter', run: runShortcut },
                        { key: 'Ctrl-Enter', run: runShortcut },
                    ])),
                ],
            });
            entry = { editor, pane };
            editors.set(file.name, entry);
            const pend = pendingDiagnostics.get(file.name);
            if (pend) {
                pendingDiagnostics.delete(file.name);
                editor.setDiagnostics(toEditorDiagnostics(file.content, pend));
            }
            return entry;
        }

        function activate(name, { focus = false } = {}) {
            const file = fileByName(name);
            if (!file) {
                return;
            }
            activeName = name;
            for (const [n, e] of editors) {
                e.pane.hidden = n !== name;
            }
            const entry = ensureEditor(file);
            entry.pane.hidden = false;
            entry.editor.view.requestMeasure();
            renderFileTabs();
            if (focus) {
                entry.editor.focus();
            }
        }

        function renderFileTabs() {
            tabBar.replaceChildren();
            files.forEach((f) => {
                const selected = f.name === activeName;
                const label = [f.name];
                const tab = h('button', {
                    class: ['cw-filetab', selected && 'is-active', f.readonly && 'is-readonly'],
                    type: 'button', role: 'tab', 'aria-selected': selected ? 'true' : 'false', tabindex: selected ? '0' : '-1',
                    title: f.readonly ? `${f.name} (schreibgeschützt)` : f.name,
                    dataset: { name: f.name },
                    onclick: () => activate(f.name, { focus: true }),
                },
                f.readonly ? h('span', { class: 'cw-lock', 'aria-hidden': 'true' }, '🔒') : null,
                f.readonly ? h('span', { class: 'sr-only' }, 'schreibgeschützt: ') : null,
                ...label);
                tabBar.append(tab);
            });
            fileTools.replaceChildren();
            if (allowFileOps()) {
                fileTools.append(h('button', {
                    class: 'btn btn-sm cw-filebtn', type: 'button', title: 'Neue Datei anlegen', onclick: () => openNewFile(),
                }, '＋ Datei'));
                const active = fileByName(activeName);
                if (active && !taskFileNames.has(active.name)) {
                    fileTools.append(
                        h('button', { class: 'btn btn-sm cw-filebtn', type: 'button', onclick: () => openRename() }, 'Umbenennen'),
                        h('button', { class: 'btn btn-sm btn-danger-ghost cw-filebtn', type: 'button', onclick: () => openDelete() }, 'Löschen'),
                    );
                }
            }
        }

        tabBar.addEventListener('keydown', (ev) => {
            if (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft') {
                return;
            }
            ev.preventDefault();
            const idx = files.findIndex((f) => f.name === activeName);
            const next = files[(idx + (ev.key === 'ArrowRight' ? 1 : -1) + files.length) % files.length];
            activate(next.name);
            tabBar.querySelector('[aria-selected="true"]')?.focus();
        });

        // ---------- Dateioperationen (nur mit allow_new_files) ----------
        function closeForm() {
            fileForm.hidden = true;
            fileForm.replaceChildren();
        }

        function openNameForm({ label, initial, submitLabel, onSubmit, excludeName = null }) {
            const input = h('input', {
                class: 'input', type: 'text', id: 'cw-name-input', value: initial, autocomplete: 'off', spellcheck: 'false', maxlength: '60',
            });
            const error = h('span', { class: 'cw-form-error text-danger', role: 'alert' });
            const form = h('form', { class: 'cw-form-row' },
                h('label', { for: 'cw-name-input' }, label), input,
                h('button', { class: 'btn btn-sm btn-accent', type: 'submit' }, submitLabel),
                h('button', { class: 'btn btn-sm', type: 'button', onclick: () => { closeForm(); activate(activeName, { focus: true }); } }, 'Abbrechen'),
                error);
            form.addEventListener('submit', (ev) => {
                ev.preventDefault();
                const others = files.map((f) => f.name).filter((n) => n !== excludeName);
                const problem = validateFileName(input.value, others, language);
                if (problem) {
                    error.textContent = problem;
                    input.focus();
                    return;
                }
                onSubmit(input.value.trim());
                closeForm();
            });
            form.addEventListener('keydown', (ev) => {
                if (ev.key === 'Escape') {
                    ev.preventDefault();
                    closeForm();
                    activate(activeName, { focus: true });
                }
            });
            fileForm.replaceChildren(form);
            fileForm.hidden = false;
            input.focus();
            input.select();
        }

        function openNewFile() {
            openNameForm({
                label: 'Name der neuen Datei', initial: suggestFileName(language, files.map((f) => f.name)), submitLabel: 'Anlegen',
                onSubmit(name) {
                    files.push({ name, content: '', readonly: false });
                    activate(name, { focus: true });
                    notify({ kind: 'value' });
                },
            });
        }

        function openRename() {
            const file = fileByName(activeName);
            if (!file) {
                return;
            }
            openNameForm({
                label: 'Neuer Name', initial: file.name, submitLabel: 'Umbenennen', excludeName: file.name,
                onSubmit(name) {
                    const entry = editors.get(file.name);
                    editors.delete(file.name);
                    if (entry) {
                        editors.set(name, entry);
                        entry.editor.setLanguage?.(editorLanguage(name, language));
                    }
                    file.name = name;
                    activeName = name;
                    activate(name, { focus: true });
                    notify({ kind: 'value' });
                },
            });
        }

        function openDelete() {
            const file = fileByName(activeName);
            if (!file) {
                return;
            }
            const form = h('div', { class: 'cw-form-row', role: 'alertdialog', 'aria-label': 'Datei löschen' },
                h('span', {}, `Datei „${file.name}“ wirklich löschen?`),
                h('button', {
                    class: 'btn btn-sm btn-danger', type: 'button',
                    onclick: () => {
                        const idx = files.indexOf(file);
                        files.splice(idx, 1);
                        const entry = editors.get(file.name);
                        entry?.editor.destroy();
                        entry?.pane.remove();
                        editors.delete(file.name);
                        closeForm();
                        activate((files[Math.max(0, idx - 1)] ?? files[0]).name, { focus: true });
                        notify({ kind: 'value' });
                    },
                }, 'Löschen'),
                h('button', { class: 'btn btn-sm', type: 'button', onclick: () => { closeForm(); activate(activeName, { focus: true }); } }, 'Abbrechen'));
            fileForm.replaceChildren(form);
            fileForm.hidden = false;
            form.querySelector('button.btn-danger')?.focus();
        }

        // ---------- Diagnosen ----------
        function applyDiagnostics(list) {
            pendingDiagnostics.clear();
            for (const entry of editors.values()) {
                entry.editor.setDiagnostics([]);
            }
            const grouped = groupByFile(list);
            for (const [name, items] of grouped) {
                const file = fileByName(name);
                if (!file) {
                    continue;
                }
                const entry = editors.get(file.name);
                if (entry) {
                    entry.editor.setDiagnostics(toEditorDiagnostics(file.content, items));
                } else {
                    pendingDiagnostics.set(file.name, items);
                }
            }
        }

        function clearDiagnostics() {
            applyDiagnostics([]);
        }

        function jumpTo(file, line) {
            const f = (file && fileByName(file)) ?? fileByName(activeName);
            if (!f) {
                return;
            }
            activate(f.name, { focus: true });
            const view = ensureEditor(f).editor.view;
            const ln = Math.min(Math.max(1, line), view.state.doc.lines);
            const pos = view.state.doc.line(ln).from;
            view.dispatch({ selection: { anchor: pos }, scrollIntoView: true });
        }

        // ---------- Ausführen ----------
        const stdinText = () => {
            if (needsStdinField() && stdinField.value !== '') {
                return stdinField.value;
            }
            return typeof config.stdin === 'string' && config.stdin !== '' ? config.stdin : null;
        };

        function sessionArgs(extra = {}) {
            const mainFile = pickMainFile(files, { ...config, language });
            return {
                language,
                files: files.map((f) => ({ name: f.name, content: f.content, readonly: f.readonly })),
                mainFile,
                task,
                sqlSetup: config.sql_setup ?? '',
                ...extra,
            };
        }

        function wireSession(s, { quiet = false } = {}) {
            s.onStatus?.((text) => setStatus(text));
            if (!quiet) {
                s.onOutput((text, stream) => panel.console.write(text, stream));
                s.onImage?.((url) => panel.addImage(url));
                s.onTurtle?.((cmds) => {
                    const first = !panel.hasTurtle();
                    panel.turtle().handle(cmds);
                    if (first) {
                        panel.show('turtle');
                    }
                });
                s.onInputRequest?.((waiting) => panel.console.setWaiting(waiting));
            }
        }

        function startRun() {
            if (!mayRun() || running || testing) {
                return;
            }
            const syntaxOnly = runModeOf() === 'syntax';
            const args = sessionArgs({ mode: syntaxOnly ? 'syntax' : 'run', stdin: stdinText() });
            if (!args.mainFile) {
                panel.console.write('Es gibt keine Datei zum Ausführen.\n', 'system');
                return;
            }
            clearDiagnostics();
            panel.resetForRun();
            running = true;
            setStatus(syntaxOnly ? 'Syntax wird geprüft …' : 'Läuft …', 'busy');
            refreshUi();
            let s;
            try {
                s = runtime.run(args);
            } catch (e) {
                running = false;
                panel.console.write(`${e?.message ?? e}\n`, 'stderr');
                setStatus('Fehler', 'error');
                refreshUi();
                return;
            }
            session = s;
            wireSession(s);
            s.onExit((result) => {
                if (session !== s) {
                    return;
                }
                session = null;
                running = false;
                finishRun(result, args, syntaxOnly);
            });
            if (language === 'java') {
                // Java meldet nicht, wann es auf Eingaben wartet: Eingabezeile gleich bereit halten
                panel.console.focusInput();
            }
        }

        function finishRun(result, args, syntaxOnly) {
            if (destroyed) {
                return;
            }
            panel.console.setWaiting(false);
            if (panel.hasTurtle()) {
                if (result.stopped) {
                    panel.turtle().finish();
                }
            }
            const diagnostics = normalizeDiagnostics([...(result.compileErrors ?? []), ...(result.diagnostics ?? [])], args.mainFile);
            applyDiagnostics(diagnostics);
            panel.setProblems(diagnostics);
            if (language === 'sql') {
                panel.setTables(result.sqlResults ?? []);
            }
            const errors = diagnostics.filter((d) => d.severity === 'error');
            if ((result.compileErrors ?? []).length > 0) {
                panel.showProblems();
            }
            let status;
            let kind;
            if (result.stopped) {
                status = 'Gestoppt';
                kind = 'warn';
            } else if (result.timedOut) {
                status = 'Zeitlimit erreicht';
                kind = 'error';
            } else if (syntaxOnly) {
                if (errors.length === 0 && !result.error) {
                    status = 'Keine Syntaxfehler gefunden';
                    kind = 'ok';
                    panel.console.write('Keine Syntaxfehler gefunden.\n', 'system');
                } else {
                    status = `${errors.length} Fehler gefunden`;
                    kind = 'error';
                }
            } else if (result.error || (result.exitCode ?? 0) !== 0) {
                status = result.exitCode !== null && result.exitCode !== undefined ? `Beendet mit Fehler (Code ${result.exitCode})` : 'Fehler';
                kind = 'error';
            } else {
                status = 'Fertig';
                kind = 'ok';
                if (language !== 'sql') {
                    panel.console.write('\nProgramm beendet.\n', 'system');
                }
            }
            setStatus(status, kind);
            refreshUi();
        }

        function stopRun() {
            if (testing) {
                cancelTests = true;
            }
            session?.stop();
        }

        // ---------- Beispieltests ----------
        async function startSampleTests() {
            if (!showSamples() || running || testing) {
                return;
            }
            testing = true;
            cancelTests = false;
            clearDiagnostics();
            panel.setTests(null);
            setStatus('Beispieltests laufen …', 'busy');
            refreshUi();
            const tests = config.sample_tests;
            const results = await runSampleTests({
                tests,
                language,
                isCancelled: () => cancelTests || destroyed,
                onProgress: (done, total) => setStatus(`Beispieltest ${done} von ${total} …`, 'busy'),
                async runOne(test) {
                    const s = runtime.run(sessionArgs({ mode: 'run', stdin: test.input ?? '', batch: true }));
                    session = s;
                    wireSession(s, { quiet: true });
                    const collected = await collectSession(s, { timeoutMs: 15000 });
                    session = null;
                    return collected;
                },
            });
            testing = false;
            if (destroyed) {
                return;
            }
            if (cancelTests) {
                setStatus('Beispieltests abgebrochen', 'warn');
            } else {
                const ok = results.filter((r) => r.ok).length;
                setStatus(`${ok} von ${results.length} Beispieltests bestanden`, ok === results.length ? 'ok' : 'error');
                panel.setTests(results);
            }
            refreshUi();
        }

        // ---------- Datenbank anzeigen (SQL) ----------
        async function showSchema() {
            if (!runtime?.sqlSchema) {
                return;
            }
            setStatus('Datenbank wird gelesen …', 'busy');
            try {
                const tables = await runtime.sqlSchema(config.sql_setup ?? '');
                if (!destroyed) {
                    panel.setSchema(tables);
                    setStatus('');
                }
            } catch (e) {
                if (!destroyed) {
                    panel.console.write(`${e.message}\n`, 'stderr');
                    panel.show('console');
                    setStatus('Fehler', 'error');
                }
            }
        }

        // ---------- Start ----------
        activate(activeName);
        refreshUi();
        if (mayRun() && (language === 'python' || language === 'sql') && !readOnly) {
            // Laufzeit im Hintergrund vorladen, damit der erste Lauf schnell startet
            setTimeout(() => {
                if (!destroyed) {
                    runtime.warmup?.(language);
                }
            }, 400);
        }
        if (teacherMode && !runCapable()) {
            setStatus('');
        }

        // ---------- Instanz ----------
        return {
            layout: 'split',
            getContent: () => toContent(files),
            setReadOnly(flag) {
                readOnly = !!flag;
                for (const [name, entry] of editors) {
                    entry.editor.setReadOnly(readOnly || !!fileByName(name)?.readonly);
                }
                if (readOnly && mode === 'exam') {
                    cancelTests = true;
                    session?.stop();
                    closeForm();
                }
                refreshUi();
            },
            focus() {
                editors.get(activeName)?.editor.focus();
            },
            destroy() {
                destroyed = true;
                cancelTests = true;
                session?.stop();
                resizeObserver?.disconnect();
                for (const entry of editors.values()) {
                    entry.editor.destroy();
                }
                editors.clear();
                panel.destroy();
                root.remove();
            },
        };
    },
};

registerAnswerWidget('code', CodeWidget);

export default CodeWidget;
