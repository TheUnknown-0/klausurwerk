import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    RoboWorld, normalizeWorldData, worldToLine, isTraceCode, describeWorld, MAX_MOVES, MESSAGES, studentDir,
} from '../src/robo/world.js';
import { ROBO_PY } from '../src/robo/python-source.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const vectors = JSON.parse(fs.readFileSync(path.resolve(here, '../../runner/tests/robo_vectors.json'), 'utf8')).cases;

const empty = (cols = 5, rows = 3, extra = {}) => ({
    cols, rows, robo: { x: 0, y: 0, dir: 'E' }, walls: [], boxes: [], screws: [], targets: [], ...extra,
});

test('gemeinsame Testfälle (dieselben wie Python und Java)', () => {
    for (const c of vectors) {
        const w = new RoboWorld(c.world);
        let failed = null;
        [...c.codes].some((code, index) => {
            const r = w.step(code);
            if (!r.ok) {
                failed = { index, message: r.message };
                return true;
            }
            return false;
        });
        if (c.expect.ok) {
            assert.equal(failed, null, `${c.name}: unerwarteter Fehler ${JSON.stringify(failed)}`);
            assert.deepEqual(w.toJSON(), c.expect.world, c.name);
        } else {
            assert.deepEqual(failed, { index: c.expect.index, message: c.expect.message }, c.name);
        }
    }
});

test('Python-Bibliothek im Frontend ist eine Kopie von runner/libs/python/robo.py', () => {
    const file = fs.readFileSync(path.resolve(here, '../../runner/libs/python/robo.py'), 'utf8').replace(/\r\n/g, '\n');
    assert.equal(ROBO_PY, file, 'node scripts/sync-robo.mjs ausführen');
    assert.ok(!ROBO_PY.includes('`') && !ROBO_PY.includes('${'));
});

test('Sensoren', () => {
    const w = new RoboWorld(empty(4, 3, { walls: [[2, 0]], boxes: [[0, 1]], screws: [{ x: 0, y: 0, count: 2 }], targets: [[1, 0]] }));
    assert.equal(w.vorneFrei(), true);
    assert.equal(w.aufSchraube(), true);
    assert.equal(w.aufZiel(), false);
    w.step('v');
    assert.equal(w.aufZiel(), true);
    assert.equal(w.vorneWand(), true);
    assert.equal(w.vorneFrei(), false);
    w.step('l');
    w.step('l');
    w.step('l'); // schaut nach Süden: Feld (1,1) ist leer
    assert.equal(w.vorneKiste(), false);
    w.step('r');
    w.step('r'); // Westen
    w.step('v');
    w.step('r'); // Norden? Westen -> rechts = Norden
    assert.equal(w.dir, 'N');
    assert.equal(studentDir('E'), 'O');
});

test('Kiste direkt vor Robo erkennen und nach Fehler bleibt Robo kaputt', () => {
    const w = new RoboWorld(empty(5, 3, { boxes: [[1, 0]] }));
    assert.equal(w.vorneKiste(), true);
    assert.equal(w.vorneFrei(), false);
    assert.equal(w.step('v').message, MESSAGES.box);
    assert.equal(w.crashed, true);
    assert.equal(w.step('l').message, MESSAGES.crashed);
});

test('Schritt-Grenze', () => {
    const w = new RoboWorld(empty(3, 3));
    for (let i = 0; i < MAX_MOVES; i++) {
        assert.equal(w.step('l').ok, true);
    }
    assert.equal(w.step('l').message, MESSAGES.limit);
});

test('Weltprüfung: gültig und ungültig', () => {
    assert.equal(normalizeWorldData({ ...empty(), robo: { x: 1, y: 1, dir: 'O' } }).robo.dir, 'E');
    const bad = [
        null, [], empty(1, 3), empty(31, 3), empty(5, 3, { robo: { x: 5, y: 0, dir: 'E' } }),
        empty(5, 3, { robo: { x: 0, y: 0, dir: 'X' } }), empty(5, 3, { walls: [[0, 0]] }), empty(5, 3, { boxes: [[0, 0]] }),
        empty(5, 3, { walls: [[1, 1]], boxes: [[1, 1]] }), empty(5, 3, { walls: [[9, 0]] }), empty(5, 3, { walls: [[1.5, 0]] }),
        empty(5, 3, { screws: [{ x: 1, y: 1, count: 0 }] }), empty(5, 3, { screws: [{ x: 1, y: 1, count: 100 }] }),
        empty(5, 3, { screws: [{ x: 1, y: 1, count: 1 }, { x: 1, y: 1, count: 2 }] }),
        empty(5, 3, { walls: [[2, 2]], screws: [{ x: 2, y: 2, count: 1 }] }),
        empty(5, 3, { walls: [[2, 2]], targets: [[2, 2]] }),
    ];
    for (const b of bad) {
        assert.throws(() => normalizeWorldData(b), Error, JSON.stringify(b));
    }
});

test('doppelte Felder werden zusammengefasst, unbekannte Felder verworfen', () => {
    const d = normalizeWorldData({ ...empty(), walls: [[1, 1], [1, 1]], extra: 5 });
    assert.deepEqual(d.walls, [[1, 1]]);
    assert.equal('extra' in d, false);
});

test('Eingabezeile für Java und Beschreibung', () => {
    const line = worldToLine(empty(3, 3));
    assert.ok(line.startsWith('@KWROBO {'));
    assert.ok(line.endsWith('}\n'));
    assert.equal(JSON.parse(line.slice(8)).cols, 3);
    assert.match(describeWorld(empty(3, 3)), /3 Spalten und 3 Zeilen/);
});

test('Protokollzeichen', () => {
    for (const ok of ['v', 'l', 'r', 'a', 'b', 's', '!Fehler']) {
        assert.equal(isTraceCode(ok), true, ok);
    }
    for (const bad of ['', 'x', 'vv', '!', 5, null]) {
        assert.equal(isTraceCode(bad), false, String(bad));
    }
});

test('klonen und Zustand', () => {
    const a = new RoboWorld(empty(4, 3, { screws: [{ x: 1, y: 0, count: 2 }] }));
    a.step('v');
    const b = a.clone();
    b.step('a');
    assert.equal(a.basket, 0);
    assert.equal(b.basket, 1);
    assert.equal(a.moves, 1);
    assert.equal(b.moves, 2);
});
