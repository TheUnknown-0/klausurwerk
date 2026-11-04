import { test } from 'node:test';
import assert from 'node:assert/strict';
import { applyTool, cellInfo, emptyWorld, resizeWorld } from '../src/robo/grid-editor.js';
import { normalizeWorldData } from '../src/robo/world.js';
import { buildGoal, parseCells } from '../src/widgets/editors/robo.js';
import { codeTaskFor } from '../src/widgets/robo.js';

test('Wand setzen und entfernen, Robo bleibt geschützt', () => {
    let w = emptyWorld(5, 4);
    let r = applyTool(w, 'wall', 2, 1);
    assert.equal(r.changed, true);
    w = r.world;
    assert.equal(cellInfo(w, 2, 1).wall, true);
    r = applyTool(w, 'wall', 2, 1);
    assert.equal(cellInfo(r.world, 2, 1).wall, false);
    r = applyTool(w, 'wall', 0, 0);
    assert.equal(r.changed, false);
    assert.match(r.message, /Robo/);
    assert.equal(w.walls.length, 1, 'Eingabe bleibt unverändert');
});

test('Wand verdrängt Kiste, Schraube und Ziel', () => {
    let w = emptyWorld(5, 4);
    w = applyTool(w, 'box', 1, 1).world;
    w = applyTool(w, 'wall', 1, 1).world;
    assert.deepEqual(cellInfo(w, 1, 1), { robo: false, wall: true, box: false, target: false, screws: 0 });
    w = applyTool(w, 'erase', 1, 1).world;
    w = applyTool(w, 'screw', 2, 2, { count: 3 }).world;
    w = applyTool(w, 'target', 2, 2).world;
    assert.equal(cellInfo(w, 2, 2).screws, 3);
    assert.equal(cellInfo(w, 2, 2).target, true);
    w = applyTool(w, 'wall', 2, 2).world;
    assert.deepEqual(cellInfo(w, 2, 2), { robo: false, wall: true, box: false, target: false, screws: 0 });
    normalizeWorldData(w);
});

test('Schrauben: gleiche Anzahl nochmal entfernt, andere Anzahl ersetzt', () => {
    let w = emptyWorld(4, 4);
    w = applyTool(w, 'screw', 1, 0, { count: 2 }).world;
    assert.equal(cellInfo(w, 1, 0).screws, 2);
    w = applyTool(w, 'screw', 1, 0, { count: 5 }).world;
    assert.equal(cellInfo(w, 1, 0).screws, 5);
    w = applyTool(w, 'screw', 1, 0, { count: 5 }).world;
    assert.equal(cellInfo(w, 1, 0).screws, 0);
    assert.equal(applyTool(w, 'screw', 1, 0, { count: 500 }).world.screws[0].count, 99);
});

test('Robo: setzen, drehen, Kiste unter Robo verboten', () => {
    let w = emptyWorld(4, 4);
    w = applyTool(w, 'box', 2, 2).world;
    w = applyTool(w, 'robo', 2, 2).world;
    assert.deepEqual(w.robo, { x: 2, y: 2, dir: 'E' });
    assert.equal(cellInfo(w, 2, 2).box, false, 'Kiste an der neuen Position fällt weg');
    w = applyTool(w, 'robo', 2, 2).world;
    assert.equal(w.robo.dir, 'S');
    const r = applyTool(w, 'box', 2, 2);
    assert.equal(r.changed, false);
});

test('Ziehen malt in einer Richtung (mode)', () => {
    let w = emptyWorld(5, 3);
    let r = applyTool(w, 'wall', 1, 1);
    w = r.world;
    assert.equal(r.mode, 'add');
    w = applyTool(w, 'wall', 2, 1, { mode: r.mode }).world;
    w = applyTool(w, 'wall', 1, 1, { mode: 'add' }).world; // schon Wand: bleibt Wand
    assert.equal(w.walls.length, 2);
    r = applyTool(w, 'wall', 1, 1);
    assert.equal(r.mode, 'remove');
    w = applyTool(r.world, 'wall', 2, 1, { mode: 'remove' }).world;
    assert.equal(w.walls.length, 0);
});

test('Größe ändern: Außenliegendes fällt weg, Robo bleibt im Raster', () => {
    let w = emptyWorld(8, 6);
    w = applyTool(w, 'wall', 7, 5).world;
    w = applyTool(w, 'screw', 6, 0, { count: 2 }).world;
    w = applyTool(w, 'robo', 7, 4).world;
    const small = resizeWorld(w, 4, 3);
    assert.equal(small.cols, 4);
    assert.equal(small.walls.length, 0);
    assert.equal(small.screws.length, 0);
    assert.deepEqual(small.robo, { x: 3, y: 2, dir: 'E' });
    normalizeWorldData(small);
    assert.equal(resizeWorld(w, 1, 99).cols, 2);
    assert.equal(resizeWorld(w, 1, 99).rows, 30);
});

test('Zielfelder für Kisten lesen', () => {
    assert.deepEqual(parseCells('3|4; 5,6'), [[3, 4], [5, 6]]);
    assert.deepEqual(parseCells(''), []);
    assert.equal(parseCells('abc'), null);
    assert.equal(parseCells('3|'), null);
});

test('Ziel aus dem Formular', () => {
    assert.deepEqual(buildGoal({ screws: '', dir: '', roboAtOn: false, boxesAt: '' }), {});
    assert.deepEqual(buildGoal({
        screws: 'all', roboAtOn: true, roboAtX: '4', roboAtY: 2, dir: 'N', onTarget: true, boxesAt: '1|1', boxesOnTargets: true,
    }), {
        screws_collected: 'all', robo_at: [4, 2], robo_dir: 'N', robo_on_target: true, boxes_at: [[1, 1]], boxes_on_targets: true,
    });
    assert.deepEqual(buildGoal({ screws: 'min', screwsMin: '3', boxesAt: '' }), { screws_collected: 3 });
});

test('eingebettetes Code-Widget bekommt Startdatei und Konfiguration', () => {
    const task = { id: 7, answer_type: 'robo', config: { language: 'python', main_file: 'main.py', start_code: 'print(1)\n', world: emptyWorld() } };
    const code = codeTaskFor(task);
    assert.equal(code.answer_type, 'code');
    assert.equal(code.id, 7);
    assert.deepEqual(code.files, [{ name: 'main.py', content: 'print(1)\n', readonly: false }]);
    assert.equal(code.config.language, 'python');
    assert.equal(code.config.allow_new_files, false);
    const java = codeTaskFor({ id: 1, config: { world: emptyWorld() } });
    assert.equal(java.config.language, 'java');
    assert.equal(java.files[0].name, 'Main.java');
    assert.match(java.files[0].content, /Robo\.vor\(\)/);
});
