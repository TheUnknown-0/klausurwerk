/**
 * Aufgaben-Editor für den Antworttyp "code": Sprache und Ausführung, vorgegebene Dateien
 * (Tabs, schreibgeschützt, Startdatei), sichtbare Beispiele und versteckte Tests.
 * getValue() liefert zusätzlich zu config/solution die Felder files und tests.
 */
import { h, icon } from '../../lib/dom.js';
import { createCodeEditor } from '../../lib/code-editor.js';
import { registerTaskEditor } from '../../lib/widgets.js';
import { button, checkbox, labeled, numberInput, select, textInput, textarea } from '../../task-editor/ui.js';

const LANGS = [['java', 'Java'], ['python', 'Python'], ['sql', 'SQL']];
const COMPARE = [['trim', 'Leerraum am Rand ignorieren'], ['exact', 'exakt'], ['ignore_whitespace', 'Leerraum ignorieren'], ['contains', 'enthält'], ['regex', 'regulärer Ausdruck']];
const TEST_TYPES = {
    java: [['io', 'Ein-/Ausgabe'], ['java_main', 'Java-Testklasse']],
    python: [['io', 'Ein-/Ausgabe'], ['python_assert', 'Python-assert']],
    sql: [['sql_result', 'Abfrageergebnis']],
};
const DEFAULT_MAIN = { java: 'Main.java', python: 'main.py', sql: 'abfrage.sql' };
const FILENAME = /^[A-Za-z0-9_.-]{1,80}$/;

const fileLanguage = (name, fallback) => {
    const ext = name.split('.').pop()?.toLowerCase();
    return ext === 'java' ? 'java' : ext === 'py' ? 'python' : ext === 'sql' ? 'sql' : fallback;
};

registerTaskEditor('code', {
    mount(container, ctx) {
        const locked = !!ctx.locked;
        const c = ctx.config ?? {};
        const state = {
            language: c.language ?? 'java',
            java_version: c.java_version ?? '',
            run_mode: c.run_mode ?? '',
            main_file: c.main_file ?? '',
            allow_new_files: !!c.allow_new_files,
            stdin: c.stdin ?? '',
            gui: !!c.gui,
            sql_setup: c.sql_setup ?? '',
            python_packages: c.python_packages ?? null,
            sample_tests: (c.sample_tests ?? []).map((t) => ({ ...t })),
        };
        const files = (ctx.files ?? []).map((f) => ({ filename: f.filename, content: f.content, is_readonly: !!f.is_readonly }));
        const tests = (ctx.tests ?? []).map((t) => ({ ...t }));
        let current = 0;
        const changed = () => ctx.onChange();
        const cleanups = [];

        // ---------- Grundeinstellungen ----------
        const langSel = select(LANGS, state.language, (v) => {
            state.language = v;
            if (!state.main_file || Object.values(DEFAULT_MAIN).includes(state.main_file)) {
                state.main_file = DEFAULT_MAIN[v];
                mainInput.value = state.main_file;
            }
            syncLanguageUi();
            tests.forEach((t) => {
                if (!(TEST_TYPES[v] ?? []).some(([k]) => k === t.type)) {
                    t.type = TEST_TYPES[v][0][0];
                }
            });
            renderTests();
            fileEditor?.setLanguage(fileLanguage(files[current]?.filename ?? '', v));
            changed();
        }, { disabled: locked });
        const mainInput = textInput(state.main_file || DEFAULT_MAIN[state.language], (v) => { state.main_file = v; changed(); }, { list: 'te-main-files', disabled: locked, maxlength: 80 });
        state.main_file = mainInput.value;
        const datalist = h('datalist', { id: 'te-main-files' });
        const javaSel = labeled('Java-Version', select([['', 'wie Klausur'], ['21', 'Java 21'], ['25', 'Java 25']], state.java_version, (v) => { state.java_version = v; changed(); }, { disabled: locked }));
        const runSel = labeled('Ausführung', select([['', 'wie Klausur'], ['run', 'ausführen'], ['syntax', 'nur Syntax prüfen'], ['none', 'nicht ausführen']], state.run_mode, (v) => { state.run_mode = v; changed(); }, { disabled: locked }));
        const guiBox = checkbox('Programm hat eine grafische Oberfläche (Klausurwerk-GUI-Bibliothek)', state.gui, (v) => { state.gui = v; changed(); }, { disabled: locked });
        const newFiles = checkbox('Schüler:innen dürfen neue Dateien anlegen', state.allow_new_files, (v) => { state.allow_new_files = v; changed(); }, { disabled: locked });
        const stdin = labeled('Vorbelegte Eingabe (stdin)', textarea(state.stdin, (v) => { state.stdin = v; changed(); }, { rows: 2, class: 'input mono', disabled: locked }), 'Leer lassen für interaktive Eingabe.');
        const pyBox = h('fieldset', { class: 'field', disabled: locked }, h('legend', { class: 'label-like' }, 'Python-Pakete'),
            checkbox('wie Klausur-Einstellung', state.python_packages === null, (v) => {
                state.python_packages = v ? null : [];
                pkgRow.hidden = v;
                changed();
            }));
        const pkgRow = h('div', { hidden: state.python_packages === null }, ['numpy', 'matplotlib'].map((p) =>
            checkbox(p, (state.python_packages ?? []).includes(p), (v) => {
                const set = new Set(state.python_packages ?? []);
                v ? set.add(p) : set.delete(p);
                state.python_packages = [...set];
                changed();
            })));
        pyBox.append(pkgRow);
        const sqlBox = h('div', {});
        const sqlHost = h('div', { class: 'te-cm short' });
        sqlBox.append(labeled('SQL-Aufbauskript (CREATE und INSERT, läuft vor jeder Ausführung auf frischer Datenbank)', h('div', { class: 'field' }, sqlHost)));
        let sqlEditor = null;

        function syncLanguageUi() {
            javaSel.hidden = state.language !== 'java';
            guiBox.hidden = state.language !== 'java';
            pyBox.hidden = state.language !== 'python';
            sqlBox.hidden = state.language !== 'sql';
        }

        // ---------- Dateien ----------
        const tabs = h('div', { class: 'te-tabs', role: 'tablist', 'aria-label': 'Vorgegebene Dateien' });
        const fileHost = h('div', { class: 'te-cm tall' });
        const fileBar = h('div', { class: 'cluster' });
        let fileEditor = null;

        function renderFiles() {
            datalist.replaceChildren(...files.map((f) => h('option', { value: f.filename })));
            tabs.replaceChildren();
            files.forEach((f, i) => {
                tabs.append(h('button', {
                    type: 'button', class: 'btn btn-sm', role: 'tab', 'aria-selected': i === current ? 'true' : 'false',
                    onclick: () => { current = i; renderFiles(); },
                }, f.is_readonly ? [icon('lock'), f.filename] : f.filename));
            });
            if (!locked) {
                tabs.append(button([icon('plus'), 'Datei'], () => {
                    let n = 1;
                    let name = 'Neu.txt';
                    while (files.some((f) => f.filename === name)) {
                        n += 1;
                        name = `Neu${n}.txt`;
                    }
                    files.push({ filename: name, content: '', is_readonly: false });
                    current = files.length - 1;
                    renderFiles();
                    changed();
                }, { disabled: files.length >= 20 }));
            }
            const f = files[current];
            fileBar.replaceChildren();
            fileHost.hidden = !f;
            if (!f) {
                fileEditor?.destroy();
                fileEditor = null;
                fileHost.replaceChildren();
                fileBar.append(h('span', { class: 'text-soft' }, 'Keine vorgegebenen Dateien: Schüler:innen starten mit einer leeren Startdatei.'));
                return;
            }
            const nameInput = textInput(f.filename, (v) => { f.filename = v.trim(); changed(); }, { 'aria-label': 'Dateiname', disabled: locked, maxlength: 80 });
            nameInput.addEventListener('change', () => renderFiles());
            fileBar.append(
                nameInput,
                checkbox('schreibgeschützt', f.is_readonly, (v) => { f.is_readonly = v; renderFiles(); changed(); }, { disabled: locked }),
                button('Als Startdatei', () => { state.main_file = f.filename; mainInput.value = f.filename; changed(); }, { disabled: locked }),
                button('Datei löschen', () => {
                    files.splice(current, 1);
                    current = Math.max(0, current - 1);
                    renderFiles();
                    changed();
                }, { disabled: locked, class: 'btn btn-sm btn-danger-ghost' }),
            );
            if (!fileEditor) {
                fileEditor = createCodeEditor({
                    parent: fileHost,
                    doc: f.content,
                    language: fileLanguage(f.filename, state.language),
                    readOnly: locked,
                    onChange: (value) => {
                        const target = files[current];
                        if (target) {
                            target.content = value;
                        }
                        changed();
                    },
                });
            } else {
                fileEditor.setValue(f.content);
                fileEditor.setLanguage(fileLanguage(f.filename, state.language));
                fileEditor.setReadOnly(locked);
            }
        }

        // ---------- Sichtbare Beispiele ----------
        const sampleBox = h('div', {});
        function renderSamples() {
            sampleBox.replaceChildren();
            state.sample_tests.forEach((t, i) => {
                sampleBox.append(h('div', { class: 'te-test' },
                    h('div', { class: 'te-grid' },
                        labeled('Name', textInput(t.name, (v) => { t.name = v; changed(); }, { disabled: locked, maxlength: 100 })),
                        labeled('Vergleich', select(COMPARE, t.compare ?? 'trim', (v) => { t.compare = v; changed(); }, { disabled: locked })),
                    ),
                    h('div', { class: 'te-grid' },
                        labeled('Eingabe', textarea(t.input, (v) => { t.input = v; changed(); }, { rows: 2, disabled: locked })),
                        labeled('Erwartete Ausgabe', textarea(t.expected_output, (v) => { t.expected_output = v; changed(); }, { rows: 2, disabled: locked })),
                    ),
                    button('Beispiel entfernen', () => { state.sample_tests.splice(i, 1); renderSamples(); changed(); }, { disabled: locked, class: 'btn btn-sm btn-danger-ghost' }),
                ));
            });
            sampleBox.append(button([icon('plus'), 'Sichtbares Beispiel'], () => {
                state.sample_tests.push({ name: `Beispiel ${state.sample_tests.length + 1}`, input: '', expected_output: '', compare: 'trim' });
                renderSamples();
                changed();
            }, { disabled: locked || state.sample_tests.length >= 20 }));
        }

        // ---------- Versteckte Tests (immer bearbeitbar) ----------
        const testBox = h('div', {});
        function renderTests() {
            testBox.replaceChildren();
            tests.forEach((t, i) => {
                const typeOptions = TEST_TYPES[state.language] ?? TEST_TYPES.java;
                const fields = [];
                if (t.type === 'io') {
                    fields.push(h('div', { class: 'te-grid' },
                        labeled('Eingabe', textarea(t.input ?? '', (v) => { t.input = v; changed(); }, { rows: 2 })),
                        labeled('Erwartete Ausgabe', textarea(t.expected_output ?? '', (v) => { t.expected_output = v; changed(); }, { rows: 2 })),
                    ), labeled('Vergleich', select(COMPARE, t.compare ?? 'trim', (v) => { t.compare = v; changed(); })));
                } else if (t.type === 'sql_result') {
                    fields.push(
                        labeled('Erwartete Abfrage (Musterabfrage)', textarea(t.expected_query ?? '', (v) => { t.expected_query = v; changed(); }, { rows: 3 })),
                        checkbox('Reihenfolge der Zeilen zählt', !!t.order_sensitive, (v) => { t.order_sensitive = v; changed(); }),
                    );
                } else {
                    fields.push(labeled(t.type === 'java_main' ? 'Testklasse (Exit-Code 0 = bestanden)' : 'Python-Code (assert ...)',
                        textarea(t.code ?? '', (v) => { t.code = v; changed(); }, { rows: 6 })));
                }
                testBox.append(h('div', { class: 'te-test' },
                    h('div', { class: 'te-grid' },
                        labeled('Name', textInput(t.name ?? '', (v) => { t.name = v; changed(); }, { maxlength: 100 })),
                        labeled('Typ', select(typeOptions, t.type, (v) => { t.type = v; renderTests(); changed(); })),
                        labeled('Punkte', numberInput(t.points ?? 1, (v) => { t.points = v; changed(); }, { min: 0, max: 999, step: 0.5 })),
                    ),
                    ...fields,
                    button('Test entfernen', () => { tests.splice(i, 1); renderTests(); changed(); }, { class: 'btn btn-sm btn-danger-ghost' }),
                ));
            });
            testBox.append(button([icon('plus'), 'Versteckten Test hinzufügen'], () => {
                const type = (TEST_TYPES[state.language] ?? TEST_TYPES.java)[0][0];
                tests.push({ name: `Test ${tests.length + 1}`, type, points: 1, input: '', expected_output: '', compare: 'trim' });
                renderTests();
                changed();
            }, { disabled: tests.length >= 50 }));
        }

        const root = h('div', { class: 'te-type-box' },
            h('div', { class: 'te-grid' },
                labeled('Sprache', langSel),
                labeled('Startdatei', mainInput, 'Java: Klasse mit main, Python: Skript, SQL: Abfragedatei.'),
                javaSel,
                runSel,
            ),
            datalist,
            guiBox,
            newFiles,
            pyBox,
            sqlBox,
            stdin,
            h('h4', {}, 'Vorgegebene Dateien'),
            tabs, fileBar, fileHost,
            h('h4', {}, 'Sichtbare Beispiele'),
            h('p', { class: 'text-sm text-soft' }, 'In Übungen und Proben sehen Schüler:innen diese Beispiele samt Ergebnis. In Klausuren nur, wenn du sie in den Aufgabentext übernimmst.'),
            sampleBox,
            h('h4', {}, 'Versteckte Tests (Lehrkräfte, Korrektur)'),
            testBox,
        );
        container.append(root);

        syncLanguageUi();
        renderFiles();
        renderSamples();
        renderTests();
        sqlEditor = createCodeEditor({
            parent: sqlHost, doc: state.sql_setup, language: 'sql', readOnly: locked,
            onChange: (v) => { state.sql_setup = v; changed(); },
        });
        cleanups.push(() => sqlEditor?.destroy(), () => fileEditor?.destroy());

        return {
            getLanguage: () => state.language,
            getValue() {
                return {
                    config: {
                        language: state.language,
                        java_version: state.java_version || null,
                        run_mode: state.run_mode || null,
                        main_file: state.main_file.trim() || DEFAULT_MAIN[state.language],
                        allow_new_files: state.allow_new_files,
                        stdin: state.stdin,
                        gui: state.language === 'java' && state.gui,
                        sample_tests: state.sample_tests.map((t) => ({ name: t.name, input: t.input, expected_output: t.expected_output, compare: t.compare ?? 'trim' })),
                        sql_setup: state.language === 'sql' ? state.sql_setup : '',
                        python_packages: state.language === 'python' ? state.python_packages : null,
                    },
                    solution: {},
                    files: files.map((f) => ({ filename: f.filename, content: f.content, is_readonly: f.is_readonly })),
                    tests: tests.map((t) => {
                        const out = { name: t.name, type: t.type, points: Number(t.points) || 0 };
                        if (t.type === 'io') {
                            Object.assign(out, { input: t.input ?? '', expected_output: t.expected_output ?? '', compare: t.compare ?? 'trim' });
                        } else if (t.type === 'sql_result') {
                            Object.assign(out, { expected_query: t.expected_query ?? '', order_sensitive: !!t.order_sensitive });
                        } else {
                            out.code = t.code ?? '';
                        }
                        return out;
                    }),
                };
            },
            validate() {
                const errors = [];
                const names = files.map((f) => f.filename);
                if (names.some((n) => !FILENAME.test(n))) {
                    errors.push('Dateinamen dürfen nur Buchstaben, Ziffern, _ . - enthalten (höchstens 80 Zeichen).');
                }
                if (new Set(names).size !== names.length) {
                    errors.push('Zwei Dateien heißen gleich.');
                }
                if (!FILENAME.test(state.main_file.trim())) {
                    errors.push('Die Startdatei hat einen ungültigen Namen.');
                }
                for (const t of tests) {
                    if (t.type === 'sql_result' && !(t.expected_query ?? '').trim()) {
                        errors.push(`Test "${t.name}": Die erwartete Abfrage fehlt.`);
                    }
                    if ((t.type === 'java_main' || t.type === 'python_assert') && !(t.code ?? '').trim()) {
                        errors.push(`Test "${t.name}": Der Testcode fehlt.`);
                    }
                }
                return errors;
            },
            destroy() {
                cleanups.forEach((fn) => fn());
                root.remove();
            },
        };
    },
});
