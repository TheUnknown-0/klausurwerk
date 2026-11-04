/**
 * Integrationstest des Python-Läufers und des Turtle-Moduls mit dem echten
 * Pyodide (Node). Läuft nur, wenn das npm-Paket 'pyodide' vorhanden ist.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { PY_RUNNER } from '../src/runtime/python-runner.js';
import { TURTLE_PY } from '../src/runtime/turtle-source.js';
import { TurtleScene } from '../src/runtime/turtle-scene.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const vendor = path.resolve(here, '..', '..', 'public', 'assets', 'vendor', 'pyodide');

let py = null;
let skipReason = null;
try {
    const { loadPyodide } = await import('pyodide');
    py = await loadPyodide();
} catch (e) {
    skipReason = `Pyodide nicht verfügbar: ${e.message}`;
}

const out = [];
let inputs = [];
const bridge = {
    write: (stream, text) => out.push([stream, text]),
    echo: (text) => out.push(['stdin', text]),
    read_input: () => (inputs.length ? `T${inputs.shift()}` : 'C'),
    turtle: (json) => out.push(['turtle', JSON.parse(json)]),
    image: (b64) => out.push(['image', b64.slice(0, 8)]),
};
let runner = null;
if (py) {
    py.registerJsModule('_kw', bridge);
    py.FS.mkdirTree('/kwlib');
    py.FS.writeFile('/kwlib/turtle.py', TURTLE_PY);
    const scope = py.globals.get('dict')();
    py.runPython(PY_RUNNER, { globals: scope });
    runner = { prepare: scope.get('prepare'), run: scope.get('run'), check: scope.get('check'), install: scope.get('install_matplotlib') };
}

function go(files, { main = 'main.py', stdin = [], interactive = false } = {}) {
    out.length = 0;
    runner.prepare(JSON.stringify({
        files: Object.entries(files).map(([name, content]) => ({ name, content })),
        main, stdin_lines: stdin, interactive,
    }));
    const result = JSON.parse(runner.run(main));
    const text = (stream) => out.filter(([s]) => s === stream).map(([, t]) => t).join('');
    return { result, out: [...out], stdout: text('stdout'), stderr: text('stderr'), stdin: text('stdin') };
}

const t = (name, fn) => test(name, { skip: skipReason ?? false }, fn);

t('print und input mit vorgegebener Eingabe', () => {
    const r = go({ 'main.py': "name = input('Name? ')\nprint('Hallo', name)\n" }, { stdin: ['Mia'] });
    assert.equal(r.result.status, 'ok');
    assert.equal(r.stdout, 'Name? Hallo Mia\n');
    assert.equal(r.stdin, 'Mia\n');
});

t('interaktives input() über die Brücke', () => {
    inputs = ['42'];
    const r = go({ 'main.py': 'n = int(input())\nprint(n * 2)\n' }, { interactive: true });
    assert.equal(r.stdout, '84\n');
});

t('Abbruch beim Lesen löst KeyboardInterrupt aus', () => {
    inputs = [];
    const r = go({ 'main.py': 'input()\nprint("nie")\n' }, { interactive: true });
    assert.equal(r.result.status, 'stopped');
    assert.equal(r.stdout, '');
});

t('Eingabe fehlt ohne Interaktivität: EOFError mit Ort', () => {
    const r = go({ 'main.py': 'x = 1\nn = input()\n' });
    assert.equal(r.result.status, 'error');
    assert.equal(r.result.type, 'EOFError');
    assert.equal(r.result.line, 2);
    assert.equal(r.result.file, 'main.py');
});

t('Laufzeitfehler liefert Datei, Zeile und bereinigten Traceback', () => {
    const r = go({ 'main.py': 'x = 1\nprint(1 / 0)\n' });
    assert.equal(r.result.type, 'ZeroDivisionError');
    assert.equal(r.result.line, 2);
    assert.match(r.result.traceback, /File "main\.py", line 2/);
    assert.doesNotMatch(r.result.traceback, /runner|exec\(/);
});

t('SyntaxError mit Zeile und Spalte', () => {
    const r = go({ 'main.py': 'x = 1\ndef f(:\n  pass\n' });
    assert.equal(r.result.type, 'SyntaxError');
    assert.equal(r.result.line, 2);
    assert.ok(r.result.column >= 1);
});

t('Fehler in importierter Datei nennt diese Datei', () => {
    const r = go({ 'main.py': 'import helper\nhelper.g()\n', 'helper.py': 'def g():\n    return undefined_name\n' });
    assert.equal(r.result.type, 'NameError');
    assert.equal(r.result.file, 'helper.py');
    assert.equal(r.result.line, 2);
    assert.deepEqual(r.result.frames.map((f) => f.file), ['main.py', 'helper.py']);
});

t('Module werden zwischen Läufen neu geladen', () => {
    const a = go({ 'main.py': 'import helper\nprint(helper.V)\n', 'helper.py': 'V = 1\n' });
    const b = go({ 'main.py': 'import helper\nprint(helper.V)\n', 'helper.py': 'V = 2\n' });
    assert.equal(a.stdout, '1\n');
    assert.equal(b.stdout, '2\n');
});

t('Dateien liegen im Arbeitsverzeichnis', () => {
    const r = go({ 'main.py': "print(open('daten.txt').read().strip())\n", 'daten.txt': 'abc\n' });
    assert.equal(r.stdout, 'abc\n');
});

t('sys.exit liefert den Exit-Code', () => {
    const r = go({ 'main.py': 'import sys\nsys.exit(3)\n' });
    assert.deepEqual(r.result, { status: 'exit', exit_code: 3 });
});

t('Syntaxprüfung ohne Ausführung', () => {
    const errors = JSON.parse(runner.check(JSON.stringify({
        files: [{ name: 'a.py', content: 'x = (1,\n' }, { name: 'b.py', content: 'ok = 1\n' }, { name: 'c.txt', content: '((' }],
    })));
    assert.equal(errors.length, 1);
    assert.equal(errors[0].file, 'a.py');
    assert.equal(errors[0].line, 1);
});

t('Turtle: Befehle, Geometrie von circle und Schnittstelle zur Szene', () => {
    const r = go({
        'main.py': [
            'import turtle',
            't = turtle.Turtle()',
            't.speed(0)',
            't.circle(50, 90)',
            'print(round(t.xcor()), round(t.ycor()), round(t.heading()))',
            't.begin_fill()',
            'for i in range(4):',
            '    t.forward(10)',
            '    t.left(90)',
            't.end_fill()',
            'turtle.done()',
        ].join('\n'),
    });
    assert.equal(r.result.status, 'ok');
    assert.equal(r.stdout, '50 50 90\n');
    const cmds = r.out.filter(([s]) => s === 'turtle').flatMap(([, c]) => c);
    assert.equal(cmds[0].t, 'sprite');
    const scene = new TurtleScene();
    scene.push(cmds);
    scene.finishAll();
    const lines = scene.items.filter((i) => i.kind === 'line');
    assert.equal(lines.length, 5);
    const fill = scene.items.find((i) => i.kind === 'fill');
    assert.equal(fill.pts.length, 5);
    // Füllung liegt unter den Linien, die erst nach begin_fill entstanden
    assert.ok(scene.items.indexOf(fill) < scene.items.lastIndexOf(lines[lines.length - 1]));
});

t('Turtle: Farben und Standardschildkröte über Modulfunktionen', () => {
    const r = go({ 'main.py': "import turtle\nturtle.color('light blue', (1, 0, 0))\nprint(turtle.pencolor(), turtle.fillcolor())\nturtle.forward(5)\nprint(turtle.position())\n" });
    assert.equal(r.stdout, 'lightblue #ff0000\n(5.0, 0.0)\n');
});

const haveWheels = fs.existsSync(path.join(vendor, 'pyodide-lock.json'))
    && fs.readdirSync(vendor).some((f) => f.startsWith('matplotlib-'));

test('numpy und matplotlib: plt.show() liefert ein Bild', { skip: skipReason ?? (haveWheels ? false : 'Wheels nicht gebaut'), timeout: 120000 }, async () => {
    const { loadPyodide } = await import('pyodide');
    const p2 = await loadPyodide({ indexURL: vendor + path.sep, lockFileURL: path.join(vendor, 'pyodide-lock.json'), packageCacheDir: vendor + path.sep });
    await p2.loadPackage(['numpy', 'matplotlib']);
    p2.registerJsModule('_kw', bridge);
    const scope = p2.globals.get('dict')();
    p2.runPython(PY_RUNNER, { globals: scope });
    scope.get('install_matplotlib')();
    out.length = 0;
    scope.get('prepare')(JSON.stringify({
        files: [{ name: 'main.py', content: 'import numpy as np\nimport matplotlib.pyplot as plt\nplt.plot(np.arange(5))\nplt.show()\nprint(np.arange(3).sum())\n' }],
        main: 'main.py', stdin_lines: [], interactive: false,
    }));
    const result = JSON.parse(scope.get('run')('main.py'));
    assert.equal(result.status, 'ok');
    assert.ok(out.some(([s, v]) => s === 'image' && v === 'iVBORw0K'));
    assert.ok(out.some(([s, v]) => s === 'stdout' && v === '3'));
});
