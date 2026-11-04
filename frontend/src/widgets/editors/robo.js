/**
 * Aufgaben-Editor für den Antworttyp "robo": Sprache, Größe und Inhalt der Welt (per Klick auf
 * Rasterzellen), Zieltext, Startcode und Testwelten mit Ziel (laufen in der Korrektur im Runner).
 *
 * getValue() liefert config (Welt, Zieltext, Startcode ...), tests (Testwelten, docs/DATENFORMATE.md 2.16)
 * und solution = { tests } als Rückfallebene: Speichert der Server die Tests nicht in task_solutions.tests
 * (nur für Typ "code" vorgesehen), bleiben sie so in task_solutions.solution erhalten.
 */
import { h } from '../../lib/dom.js';
import { createCodeEditor } from '../../lib/code-editor.js';
import { registerTaskEditor } from '../../lib/widgets.js';
import { button, checkbox, labeled, numberInput, rowSelection, select, textInput, textarea } from '../../task-editor/ui.js';
import { createGridEditor, emptyWorld } from '../../robo/grid-editor.js';
import { normalizeWorldData } from '../../robo/world.js';
import { DEFAULT_START } from '../robo.js';

const LANGS = [['java', 'Java'], ['python', 'Python']];
const DEFAULT_MAIN = { java: 'Main.java', python: 'main.py' };
const MAX_TESTS = 20;
const FILENAME = /^[A-Za-z0-9_.-]{1,80}$/;
const DIR_OPTIONS = [['', 'egal'], ['N', 'Norden'], ['E', 'Osten'], ['S', 'Süden'], ['W', 'Westen']];

const clone = (v) => JSON.parse(JSON.stringify(v));

/** "3|4; 5|6" oder "3,4 5,6" -> [[3,4],[5,6]] (null bei Formfehler) */
export function parseCells(text) {
    const out = [];
    for (const part of String(text ?? '').split(/[;\n]+|\s{2,}/).map((s) => s.trim()).filter(Boolean)) {
        const m = part.match(/^(\d+)\s*[|,]\s*(\d+)$/);
        if (!m) {
            return null;
        }
        out.push([Number(m[1]), Number(m[2])]);
    }
    return out;
}

const cellsToText = (cells) => (cells ?? []).map(([x, y]) => `${x}|${y}`).join('; ');

/** Ziel aus dem Formularzustand (nur gesetzte Bedingungen). */
export function buildGoal(g) {
    const goal = {};
    if (g.screws === 'all') {
        goal.screws_collected = 'all';
    } else if (g.screws === 'min') {
        goal.screws_collected = Math.max(0, Math.round(Number(g.screwsMin) || 0));
    }
    if (g.roboAtOn) {
        goal.robo_at = [Math.round(Number(g.roboAtX) || 0), Math.round(Number(g.roboAtY) || 0)];
    }
    if (g.dir) {
        goal.robo_dir = g.dir;
    }
    if (g.onTarget) {
        goal.robo_on_target = true;
    }
    const boxes = parseCells(g.boxesAt);
    if (boxes && boxes.length > 0) {
        goal.boxes_at = boxes;
    }
    if (g.boxesOnTargets) {
        goal.boxes_on_targets = true;
    }
    return goal;
}

function goalState(goal = {}) {
    const sc = goal.screws_collected;
    return {
        screws: sc === 'all' ? 'all' : Number.isInteger(sc) ? 'min' : '',
        screwsMin: Number.isInteger(sc) ? sc : 1,
        roboAtOn: Array.isArray(goal.robo_at),
        roboAtX: goal.robo_at?.[0] ?? 0,
        roboAtY: goal.robo_at?.[1] ?? 0,
        dir: goal.robo_dir ?? '',
        onTarget: !!goal.robo_on_target,
        boxesAt: cellsToText(goal.boxes_at),
        boxesOnTargets: !!goal.boxes_on_targets,
    };
}

registerTaskEditor('robo', {
    mount(container, ctx) {
        const locked = !!ctx.locked;
        const c = ctx.config ?? {};
        const changed = () => ctx.onChange();
        const cleanups = [];

        let initialWorld;
        try {
            initialWorld = normalizeWorldData(c.world);
            delete initialWorld.basket;
        } catch {
            initialWorld = emptyWorld(10, 8);
        }
        const state = {
            language: c.language === 'python' ? 'python' : 'java',
            java_version: c.java_version ?? '',
            run_mode: c.run_mode ?? '',
            main_file: c.main_file ?? '',
            start_code: c.start_code ?? '',
            goal_md: c.goal_md ?? '',
        };
        if (!state.main_file) {
            state.main_file = DEFAULT_MAIN[state.language];
        }
        const savedTests = (Array.isArray(ctx.tests) && ctx.tests.length > 0 ? ctx.tests : ctx.solution?.tests) ?? [];
        const tests = (Array.isArray(savedTests) ? savedTests : []).filter((t) => t && typeof t === 'object').map((t) => ({
            name: t.name ?? '',
            points: t.points ?? 1,
            world: t.world ?? clone(initialWorld),
            goal: goalState(t.goal),
        }));
        let mainGrid = null;
        const testGrids = [];

        // ---------- Grundeinstellungen ----------
        const mainInput = textInput(state.main_file, (v) => { state.main_file = v; changed(); }, { disabled: locked, maxlength: 80 });
        let startEditor = null;
        const langSel = select(LANGS, state.language, (v) => {
            const oldDefault = DEFAULT_START[state.language];
            const untouched = state.start_code.trim() === '' || state.start_code === oldDefault;
            if (!state.main_file || Object.values(DEFAULT_MAIN).includes(state.main_file)) {
                state.main_file = DEFAULT_MAIN[v];
                mainInput.value = state.main_file;
            }
            state.language = v;
            if (untouched) {
                state.start_code = '';
                startEditor?.setValue(DEFAULT_START[v]);
            }
            startEditor?.setLanguage(v);
            javaSel.hidden = v !== 'java';
            changed();
        }, { disabled: locked });
        const javaSel = labeled('Java-Version', select([['', 'wie Klausur'], ['21', 'Java 21'], ['25', 'Java 25']], state.java_version, (v) => { state.java_version = v; changed(); }, { disabled: locked }));
        javaSel.hidden = state.language !== 'java';
        const runSel = labeled('Ausführung', select([['', 'wie Klausur'], ['run', 'ausführen'], ['syntax', 'nur Syntax prüfen'], ['none', 'nicht ausführen']], state.run_mode, (v) => { state.run_mode = v; changed(); }, { disabled: locked }));
        const goalField = labeled('Ziel für die Schüler:innen (Klartext)', textarea(state.goal_md, (v) => { state.goal_md = v; changed(); }, { rows: 3, disabled: locked, maxlength: 2000 }),
            'Wird über der Welt angezeigt, z. B. „Sammle alle Schrauben ein und gehe zum Ziel.“');

        const startHost = h('div', { class: 'te-cm short' });
        const startBox = labeled('Startcode (leer = Vorlage mit Befehlsübersicht)', h('div', { class: 'field' }, startHost));

        // ---------- Welt ----------
        const worldHost = h('div', {});
        const worldDetails = h('div', { class: 're-section' }, h('h4', {}, 'Welt der Aufgabe (sichtbar für Schüler:innen)'), worldHost);

        // ---------- Testwelten ----------
        const testBox = h('div', { class: 're-tests' });
        const testSel = rowSelection({
            label: 'Testwelt',
            onDelete: (t, { viaKey }) => {
                const i = tests.indexOf(t);
                tests.splice(i, 1);
                renderTests();
                testSel.select(tests[Math.min(i, tests.length - 1)] ?? null, { focus: viaKey });
                changed();
            },
        });
        function renderTests() {
            testGrids.splice(0).forEach((g) => g.destroy());
            testBox.replaceChildren();
            testSel.begin();
            tests.forEach((t, i) => {
                const g = t.goal;
                const gridHost = h('div', {});
                const grid = createGridEditor(gridHost, {
                    world: t.world, locked, idPrefix: `re-test-${i}-${Math.random().toString(36).slice(2, 7)}`,
                    onChange: (w) => { t.world = w; changed(); },
                });
                testGrids.push(grid);
                const goalGrid = h('div', { class: 'te-grid' },
                    labeled('Schrauben', select([['', 'egal'], ['all', 'alle im Korb'], ['min', 'mindestens ... im Korb']], g.screws, (v) => { g.screws = v; minInput.disabled = locked || v !== 'min'; changed(); }, { disabled: locked })),
                    labeled('Anzahl', numberInput(g.screwsMin, (v) => { g.screwsMin = v; changed(); }, { min: 0, max: 9999, disabled: locked || g.screws !== 'min' })),
                    labeled('Blickrichtung am Ende', select(DIR_OPTIONS, g.dir, (v) => { g.dir = v; changed(); }, { disabled: locked })));
                const minInput = goalGrid.querySelector('input[type=number]');
                const posGrid = h('div', { class: 'te-grid' },
                    checkbox('Robo muss auf Feld stehen', g.roboAtOn, (v) => { g.roboAtOn = v; xIn.disabled = yIn.disabled = locked || !v; changed(); }, { disabled: locked }),
                    labeled('x', numberInput(g.roboAtX, (v) => { g.roboAtX = v; changed(); }, { min: 0, max: 29, disabled: locked || !g.roboAtOn })),
                    labeled('y', numberInput(g.roboAtY, (v) => { g.roboAtY = v; changed(); }, { min: 0, max: 29, disabled: locked || !g.roboAtOn })));
                const [xIn, yIn] = posGrid.querySelectorAll('input[type=number]');
                const card = h('details', { class: 'te-test re-test', open: tests.length <= 2 },
                    h('summary', {}, `Testwelt ${i + 1}: ${t.name || 'ohne Namen'}`),
                    h('div', { class: 'te-grid' },
                        labeled('Name', textInput(t.name, (v) => { t.name = v; changed(); }, { disabled: locked, maxlength: 100 })),
                        labeled('Punkte', numberInput(t.points, (v) => { t.points = v; changed(); }, { min: 0, max: 999, step: 0.5, disabled: locked }))),
                    gridHost,
                    h('div', { class: 'cluster' },
                        button('Startwelt hierher kopieren', () => {
                            t.world = mainGrid.getWorld();
                            grid.setWorld(t.world);
                            changed();
                        }, { disabled: locked })),
                    h('h5', {}, 'Bedingungen für „bestanden“'),
                    goalGrid, posGrid,
                    h('div', { class: 'te-grid' },
                        checkbox('Robo muss auf einem Zielfeld stehen', g.onTarget, (v) => { g.onTarget = v; changed(); }, { disabled: locked }),
                        checkbox('Alle Kisten müssen auf Zielfeldern stehen', g.boxesOnTargets, (v) => { g.boxesOnTargets = v; changed(); }, { disabled: locked })),
                    labeled('Kisten müssen auf diesen Feldern stehen', textInput(g.boxesAt, (v) => { g.boxesAt = v; changed(); }, { disabled: locked, placeholder: 'z. B. 4|2; 5|3' }), 'Felder als x|y, mit Semikolon getrennt.'),
                    button('Testwelt entfernen', () => { testSel.select(t); testSel.remove(false); }, { disabled: locked, class: 'btn btn-sm btn-danger-ghost' }),
                );
                testSel.bind(card, t, { canDelete: !locked, name: `Testwelt ${i + 1}` });
                testBox.append(card);
            });
            testSel.end();
            testBox.append(h('div', { class: 'te-list-bar' }, button('+ Testwelt hinzufügen', () => {
                tests.push({ name: `Welt ${tests.length + 1}`, points: 1, world: mainGrid.getWorld(), goal: goalState({ screws_collected: 'all' }) });
                renderTests();
                changed();
            }, { disabled: locked || tests.length >= MAX_TESTS }), locked ? null : testSel.button));
        }

        const root = h('div', { class: 'te-type-box' },
            h('div', { class: 'te-grid' },
                labeled('Sprache', langSel),
                labeled('Startdatei', mainInput, 'Nicht robo.py und nicht Robo.java nennen (so heißen die Bibliotheken).'),
                javaSel, runSel),
            goalField,
            worldDetails,
            startBox,
            h('h4', {}, 'Testwelten (Korrektur, nie sichtbar für Schüler:innen)'),
            h('p', { class: 'text-sm text-soft' }, 'Jede Testwelt führt das Programm der Schüler:innen auf einer anderen Welt aus. Punkte gibt es, wenn alle angekreuzten Bedingungen erfüllt sind.'),
            testBox);
        container.append(root);

        mainGrid = createGridEditor(worldHost, {
            world: initialWorld, locked, idPrefix: 're-main',
            onChange: (w) => { state.world = w; changed(); },
        });
        state.world = mainGrid.getWorld();
        startEditor = createCodeEditor({
            parent: startHost, doc: state.start_code || DEFAULT_START[state.language], language: state.language, readOnly: locked,
            onChange: (v) => { state.start_code = v === DEFAULT_START[state.language] ? '' : v; changed(); },
        });
        cleanups.push(() => startEditor?.destroy(), () => mainGrid?.destroy(), () => testGrids.splice(0).forEach((g) => g.destroy()));
        renderTests();

        const testsOut = () => tests.map((t) => ({
            name: String(t.name || '').trim() || 'Testwelt',
            type: 'robo_world',
            world: t.world,
            goal: buildGoal(t.goal),
            points: Number(t.points) || 0,
        }));

        return {
            getLanguage: () => state.language,
            getValue() {
                const out = testsOut();
                return {
                    config: {
                        language: state.language,
                        java_version: state.language === 'java' ? (state.java_version || null) : null,
                        run_mode: state.run_mode || null,
                        main_file: state.main_file.trim() || DEFAULT_MAIN[state.language],
                        start_code: state.start_code,
                        goal_md: state.goal_md,
                        world: state.world,
                    },
                    solution: { tests: out },
                    files: [],
                    tests: out,
                };
            },
            validate() {
                const errors = [];
                const main = state.main_file.trim() || DEFAULT_MAIN[state.language];
                if (!FILENAME.test(main)) {
                    errors.push('Die Startdatei hat einen ungültigen Namen.');
                }
                if (main === 'robo.py' || main === 'Robo.java' || main === 'RoboFehler.java') {
                    errors.push('Die Startdatei darf nicht robo.py oder Robo.java heißen.');
                }
                try {
                    normalizeWorldData(state.world);
                } catch (e) {
                    errors.push(`Welt: ${e.message}`);
                }
                tests.forEach((t, i) => {
                    try {
                        normalizeWorldData(t.world);
                    } catch (e) {
                        errors.push(`Testwelt ${i + 1}: ${e.message}`);
                    }
                    if (parseCells(t.goal.boxesAt) === null) {
                        errors.push(`Testwelt ${i + 1}: Die Kistenfelder haben nicht die Form x|y; x|y.`);
                    }
                    if (t.goal.roboAtOn && (t.goal.roboAtX < 0 || t.goal.roboAtX >= t.world.cols || t.goal.roboAtY < 0 || t.goal.roboAtY >= t.world.rows)) {
                        errors.push(`Testwelt ${i + 1}: Das Zielfeld für Robo liegt außerhalb der Welt.`);
                    }
                });
                return errors;
            },
            destroy() {
                cleanups.forEach((fn) => fn());
                root.remove();
            },
        };
    },
});
