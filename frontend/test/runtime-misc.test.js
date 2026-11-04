import test from 'node:test';
import assert from 'node:assert/strict';
import { formatCell, shapeSqlResults, resultToText, shapeSchema, sqlHint, shortSql } from '../src/runtime/sql-results.js';
import { lineColToRange, normalizeDiagnostics, groupByFile, toEditorDiagnostics, parseJavacOutput } from '../src/runtime/diagnostics.js';
import { buildFileList, toContent, validateFileName, suggestFileName, pickMainFile, defaultMainFile } from '../src/runtime/files.js';
import { formatPythonError, pythonHint, pythonDiagnostics, locationText } from '../src/runtime/python-errors.js';
import { effectiveRunMode, createRuntime } from '../src/runtime/index.js';

// ---------- SQL ----------
test('Zellwerte: NULL, Zahlen, Blobs', () => {
    assert.deepEqual(formatCell(null), { text: 'NULL', isNull: true });
    assert.equal(formatCell(3).text, '3');
    assert.equal(formatCell(0.1 + 0.2).text, '0.3');
    assert.equal(formatCell(new Uint8Array(4)).text, '<BLOB, 4 Byte>');
    assert.equal(formatCell('x').text, 'x');
});

test('Ergebnisse: Zeilen, Änderungen, Fehler, höchstens 500 Zeilen mit Hinweis', () => {
    const rows = Array.from({ length: 501 }, (_, i) => [i]);
    const shaped = shapeSqlResults([
        { sql: 'CREATE TABLE t(a)', columns: [], rows: [], rowsModified: 0 },
        { sql: 'INSERT INTO t VALUES (1)', columns: [], rows: [], rowsModified: 1 },
        { sql: 'UPDATE t SET a=2', columns: [], rows: [], rowsModified: 3 },
        { sql: 'SELECT a FROM t', columns: ['a'], rows, totalRows: 1200 },
        { sql: 'SELECT a FROM t WHERE 0', columns: ['a'], rows: [], totalRows: 0 },
        { sql: 'SELEKT', error: 'near "SELEKT": syntax error' },
    ]);
    assert.deepEqual(shaped.map((r) => r.kind), ['ok', 'modified', 'modified', 'rows', 'rows', 'error']);
    assert.equal(shaped[1].message, '1 Zeile geändert.');
    assert.equal(shaped[2].message, '3 Zeilen geändert.');
    assert.equal(shaped[3].rows.length, 500);
    assert.ok(shaped[3].truncated);
    assert.match(shaped[3].message, /ersten 500 von 1200/);
    assert.equal(shaped[4].message, 'Keine Zeilen gefunden.');
    assert.match(shaped[5].hint, /Aufbau/);
    assert.deepEqual(shaped.map((r) => r.index), [1, 2, 3, 4, 5, 6]);
});

test('Genau 500 Zeilen werden nicht als gekürzt markiert', () => {
    const rows = Array.from({ length: 500 }, (_, i) => [i]);
    const [r] = shapeSqlResults([{ sql: 'SELECT 1', columns: ['x'], rows, totalRows: 500 }]);
    assert.ok(!r.truncated);
});

test('Ergebnistext für Beispieltests', () => {
    const shaped = shapeSqlResults([
        { sql: 'SELECT', columns: ['n', 'v'], rows: [['a', 1], ['b', null]], totalRows: 2 },
    ]);
    assert.equal(resultToText(shaped), 'a|1\nb|NULL\n');
    assert.equal(resultToText([]), '');
});

test('Schema und Fehlerhinweise', () => {
    const s = shapeSchema([{ name: 'kunde', type: 'table', columns: [{ name: 'id', type: 'INTEGER', pk: 1, notnull: 0 }, { name: 'ort', type: '', fk: 'ort.id' }] }]);
    assert.equal(s[0].columns[0].pk, true);
    assert.equal(s[0].columns[1].fk, 'ort.id');
    assert.match(sqlHint('no such table: kunde'), /kunde/);
    assert.match(sqlHint('no such column: x'), /Spalte/);
    assert.equal(sqlHint('etwas Seltsames'), '');
    assert.equal(shortSql('SELECT   *\n FROM t', 10).length, 10);
});

// ---------- Diagnosen ----------
test('Zeile und Spalte werden zu Bereichen, Wort an der Stelle', () => {
    const text = 'int x = 5\nSystem.out.println(y);\n';
    const r = lineColToRange(text, 2, 12);
    assert.equal(text.slice(r.from, r.to), 'println');
    const whole = lineColToRange('   foo bar\nzwei', 1);
    assert.equal('   foo bar\nzwei'.slice(whole.from, whole.to), 'foo bar');
    const eol = lineColToRange('int x = 5\n', 1, 99);
    assert.equal('int x = 5\n'.slice(eol.from, eol.to), '5');
});

test('Zeile außerhalb des Textes wird auf die letzte Zeile begrenzt', () => {
    const r = lineColToRange('a\nbb', 50, 1);
    assert.equal(r.from, 2);
});

test('Diagnosen normalisieren, gruppieren, für den Editor aufbereiten', () => {
    const list = normalizeDiagnostics([
        { file: 'A.java', line: 2, column: 3, message: 'x' },
        { filename: 'B.py', lineno: 0, msg: 'y' },
        { message: 'ohne Datei', line: '4', severity: 'warning' },
        { line: 1 },
        null,
    ], 'Main.java');
    assert.equal(list.length, 3);
    assert.equal(list[1].line, 1);
    assert.equal(list[2].file, 'Main.java');
    assert.equal(list[2].severity, 'warning');
    const g = groupByFile(list);
    assert.deepEqual([...g.keys()], ['A.java', 'B.py', 'Main.java']);
    const ed = toEditorDiagnostics('abc\ndef', [{ line: 2, column: 1, message: 'm', severity: 'error' }]);
    assert.deepEqual(ed, [{ from: 4, to: 7, severity: 'error', message: 'm' }]);
});

test('javac-Ausgabe lesen', () => {
    const out = [
        "src/Main.java:3: error: ';' expected",
        '        int x = 5',
        '                 ^',
        'Main.java:7: warning: [deprecation] foo',
        '1 error',
    ].join('\n');
    const d = parseJavacOutput(out);
    assert.equal(d.length, 2);
    assert.deepEqual(d[0], { file: 'Main.java', line: 3, column: 18, message: "';' expected", severity: 'error' });
    assert.equal(d[1].severity, 'warning');
    assert.deepEqual(parseJavacOutput('nichts'), []);
});

// ---------- Dateien ----------
test('Dateiliste: schreibgeschützte aus der Aufgabe, bearbeitbare aus dem Inhalt', () => {
    const task = [
        { name: 'Main.java', content: 'alt', readonly: false },
        { name: 'Tools.java', content: 'fix', readonly: true },
        { name: 'Neu.java', content: 'neu vom Lehrer', readonly: false },
    ];
    const content = { files: [{ name: 'Main.java', content: 'meins' }, { name: 'Tools.java', content: 'manipuliert' }, { name: 'Eigen.java', content: 'e' }] };
    const files = buildFileList(task, content, { language: 'java' });
    assert.deepEqual(files.map((f) => [f.name, f.content, f.readonly]), [
        ['Main.java', 'meins', false],
        ['Tools.java', 'fix', true],
        ['Neu.java', 'neu vom Lehrer', false],
        ['Eigen.java', 'e', false],
    ]);
    assert.deepEqual(toContent(files), {
        files: [
            { name: 'Main.java', content: 'meins' }, { name: 'Neu.java', content: 'neu vom Lehrer' }, { name: 'Eigen.java', content: 'e' },
        ],
    });
});

test('Ohne Dateien entsteht eine leere Startdatei', () => {
    assert.deepEqual(buildFileList([], null, { language: 'python' }), [{ name: 'main.py', content: '', readonly: false }]);
    assert.equal(buildFileList([], null, { language: 'java', main_file: 'App.java' })[0].name, 'App.java');
    assert.equal(defaultMainFile('sql'), 'abfrage.sql');
});

test('Dateinamen prüfen', () => {
    assert.match(validateFileName('', [], 'java'), /Dateinamen/);
    assert.match(validateFileName('a/b.py', [], 'python'), /Erlaubt/);
    assert.match(validateFileName('x.txt', [], 'java'), /enden/);
    assert.match(validateFileName('1abc.java', [], 'java'), /Klasse/);
    assert.match(validateFileName('main.PY', ['Main.py'], 'python'), /schon/);
    assert.equal(validateFileName('daten.csv', ['main.py'], 'python'), null);
    assert.equal(validateFileName('Kunde.java', ['Main.java'], 'java'), null);
    assert.match(validateFileName(`${'a'.repeat(70)}.py`, [], 'python'), /lang/);
});

test('Namensvorschlag und Startdatei', () => {
    assert.equal(suggestFileName('java', ['Main.java']), 'Klasse1.java');
    assert.equal(suggestFileName('python', ['datei1.py']), 'datei2.py');
    const files = [{ name: 'util.py' }, { name: 'app.py' }];
    assert.equal(pickMainFile(files, { language: 'python', main_file: 'app.py' }), 'app.py');
    assert.equal(pickMainFile(files, { language: 'python', main_file: 'fehlt.py' }), 'util.py');
    assert.equal(pickMainFile([], { language: 'python' }), null);
});

// ---------- Python-Fehler ----------
test('Python-Fehlertext mit Ort, Hinweis und Traceback', () => {
    const err = { type: 'NameError', message: "name 'x' is not defined", file: 'main.py', line: 3, traceback: 'Traceback...\nNameError: ...\n' };
    const text = formatPythonError(err);
    assert.match(text, /^Fehler in main\.py, Zeile 3/);
    assert.match(text, /NameError: name 'x' is not defined/);
    assert.match(text, /Hinweis: Der Name „x“ ist nicht bekannt/);
    assert.match(text, /Traceback/);
    assert.equal(locationText({ line: 2 }), 'Zeile 2');
    assert.equal(locationText({}), '');
});

test('Python-Hinweise für häufige Fehler', () => {
    assert.match(pythonHint('TypeError', 'can only concatenate str (not "int") to str'), /str\(\)/);
    assert.match(pythonHint('ValueError', "invalid literal for int() with base 10: 'a'"), /Zahl/);
    assert.match(pythonHint('ZeroDivisionError', 'division by zero'), /null/);
    assert.match(pythonHint('IndexError', 'list index out of range'), /Index/);
    assert.match(pythonHint('SyntaxError', "expected ':'"), /Doppelpunkt/);
    assert.match(pythonHint('ModuleNotFoundError', "No module named 'pandas'"), /pandas/);
    assert.match(pythonHint('EOFError', ''), /Eingabe/);
    assert.equal(pythonHint('MeinFehler', 'x'), '');
});

test('Python-Diagnosen: Fehlerstelle und Aufrufstellen', () => {
    const d = pythonDiagnostics({
        type: 'ZeroDivisionError', message: 'division by zero', file: 'b.py', line: 2,
        frames: [{ file: 'main.py', line: 5 }, { file: 'b.py', line: 2 }],
    });
    assert.equal(d.length, 2);
    assert.equal(d[0].severity, 'error');
    assert.equal(d[0].file, 'b.py');
    assert.equal(d[1].severity, 'warning');
    assert.deepEqual(pythonDiagnostics(null), []);
});

// ---------- Laufzeit ----------
test('run_mode: Aufgabe vor Klausur-Einstellung', () => {
    assert.equal(effectiveRunMode({ config: { run_mode: 'syntax' } }, { run_mode: 'run' }), 'syntax');
    assert.equal(effectiveRunMode({ config: { run_mode: null } }, { run_mode: 'none' }), 'none');
    assert.equal(effectiveRunMode({ config: {} }, {}), 'run');
    assert.equal(effectiveRunMode({ config: { run_mode: 'quatsch' } }, {}), 'run');
});

test('canRun beachtet run_mode', () => {
    const rt = createRuntime({ settings: { run_mode: 'none' } });
    assert.equal(rt.canRun({ config: { language: 'python' } }), false);
    assert.equal(rt.canRun({ config: { language: 'python', run_mode: 'run' } }), true);
    assert.equal(rt.canRun({ config: { language: 'java', run_mode: 'syntax' } }), true);
    assert.equal(rt.runMode({ config: { run_mode: 'syntax' } }), 'syntax');
    assert.equal(rt.canRun({ config: { language: 'cobol', run_mode: 'run' } }), false);
});

test('run reicht Einstellungen an die passende Sprache weiter', () => {
    const calls = [];
    const fake = (name) => ({ run: (o) => { calls.push([name, o]); return { stop() {} }; } });
    const rt = createRuntime({
        settings: { run_mode: 'run', python_packages: ['numpy'] },
        hosts: { python: fake('python'), sql: fake('sql') },
    });
    rt.run({ files: [], mainFile: 'main.py', task: { config: { language: 'python', python_packages: null } } });
    assert.deepEqual(calls[0][1].pythonPackages, ['numpy']);
    rt.run({ files: [], mainFile: 'main.py', task: { config: { language: 'python', python_packages: [] } } });
    assert.deepEqual(calls[1][1].pythonPackages, []);
    rt.run({ files: [], mainFile: 'a.sql', task: { config: { language: 'sql', sql_setup: 'CREATE TABLE t(a);' } } });
    assert.equal(calls[2][0], 'sql');
    assert.equal(calls[2][1].sqlSetup, 'CREATE TABLE t(a);');
});

test('run_mode syntax erzwingt Syntaxprüfung, none liefert eine Fehlersitzung', async () => {
    const calls = [];
    const rt = createRuntime({
        settings: { run_mode: 'syntax' },
        hosts: { python: { run: (o) => { calls.push(o); return { stop() {} }; } } },
    });
    rt.run({ files: [], mainFile: 'main.py', mode: 'run', task: { config: { language: 'python' } } });
    assert.equal(calls[0].mode, 'syntax');

    const none = createRuntime({ settings: { run_mode: 'none' } });
    const s = none.run({ files: [], mainFile: 'main.py', task: { config: { language: 'python' } } });
    const result = await new Promise((resolve) => s.onExit(resolve));
    assert.match(result.error, /nicht erlaubt/);
});
