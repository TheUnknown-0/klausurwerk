import test from 'node:test';
import assert from 'node:assert/strict';
import {
    TurtleScene, pathLength, pointAt, pointsUpTo, speedPxPerMs, speedDegPerMs, spriteTriangle, toCanvas, itemsBounds,
} from '../src/runtime/turtle-scene.js';

const close = (a, b, eps = 1e-6) => assert.ok(Math.abs(a - b) < eps, `${a} != ${b}`);

test('Linienzug: Länge, Punkt nach Strecke, Teilstück', () => {
    const pts = [[0, 0], [3, 4], [3, 10]];
    close(pathLength(pts), 11);
    assert.deepEqual(pointAt(pts, 0), { x: 0, y: 0 });
    assert.deepEqual(pointAt(pts, 5), { x: 3, y: 4 });
    const p = pointAt(pts, 8);
    close(p.x, 3);
    close(p.y, 7);
    assert.deepEqual(pointAt(pts, 99), { x: 3, y: 10 });
    assert.equal(pointsUpTo(pts, 2.5).length, 2);
    assert.deepEqual(pointsUpTo(pts, 5), [[0, 0], [3, 4], [3, 4]].slice(0, 2));
    const part = pointsUpTo(pts, 8);
    assert.equal(part.length, 3);
    close(part[2][1], 7);
});

test('Geschwindigkeit: 0 ist sofort, höhere Stufen sind schneller', () => {
    assert.equal(speedPxPerMs(0), Infinity);
    assert.equal(speedDegPerMs(0), Infinity);
    assert.ok(speedPxPerMs(10) > speedPxPerMs(5));
    assert.ok(speedPxPerMs(5) > speedPxPerMs(1));
    assert.equal(speedPxPerMs(99), speedPxPerMs(3));
});

test('Koordinaten: Ursprung in der Mitte, y nach oben', () => {
    assert.deepEqual(toCanvas(0, 0, 640, 480), { x: 320, y: 240 });
    assert.deepEqual(toCanvas(100, 50, 640, 480), { x: 420, y: 190 });
});

test('Schildkrötendreieck zeigt in Blickrichtung', () => {
    const east = spriteTriangle(0, 0, 0, 10);
    assert.ok(east[0][0] > 0);
    close(east[0][1], 0);
    const north = spriteTriangle(0, 0, 90, 10);
    close(north[0][0], 0);
    assert.ok(north[0][1] > 0);
});

test('sofort gezeichnete Linie (Geschwindigkeit 0)', () => {
    const s = new TurtleScene();
    s.push([
        { t: 'sprite', id: 1, shown: true, c: 'red', x: 0, y: 0, h: 0 },
        { t: 'path', id: 1, pts: [[0, 0], [100, 0]], pen: true, c: 'red', w: 2, h: 0, s: 0 },
    ]);
    s.advance(0);
    assert.equal(s.items.length, 1);
    assert.equal(s.items[0].kind, 'line');
    assert.equal(s.sprite(1).x, 100);
    assert.ok(s.isIdle());
});

test('Animation: Teilstück während der Bewegung, am Ende vollständige Linie', () => {
    const s = new TurtleScene();
    const v = speedPxPerMs(3);
    s.push({ t: 'path', id: 0, pts: [[0, 0], [100, 0]], pen: true, c: 'black', w: 1, h: 0, s: 3 });
    s.advance(50 / v);
    assert.equal(s.items.length, 0);
    close(s.sprite(0).x, 50, 1e-3);
    assert.ok(s.partial);
    close(s.partial.pts[1][0], 50, 1e-3);
    s.advance(60 / v);
    assert.equal(s.items.length, 1);
    assert.equal(s.partial, null);
    close(s.sprite(0).x, 100);
});

test('Stift oben zeichnet nichts, bewegt aber die Schildkröte', () => {
    const s = new TurtleScene();
    s.push({ t: 'path', id: 0, pts: [[0, 0], [30, 40]], pen: false, c: 'black', w: 1, h: 0, s: 0 });
    s.finishAll();
    assert.equal(s.items.length, 0);
    assert.equal(s.sprite(0).y, 40);
});

test('Drehung wird interpoliert', () => {
    const s = new TurtleScene();
    s.push({ t: 'turn', id: 0, from: 0, to: 90, s: 3 });
    s.advance(45 / speedDegPerMs(3));
    close(s.sprite(0).h, 45, 1e-3);
    s.finishAll();
    assert.equal(s.sprite(0).h, 90);
});

test('Füllung wird unter die Linien eingefügt, die danach begonnen wurden', () => {
    const s = new TurtleScene();
    s.push([
        { t: 'path', id: 0, pts: [[0, 0], [10, 0]], pen: true, c: 'a', w: 1, h: 0, s: 0 },
        { t: 'beginfill', id: 0 },
        { t: 'path', id: 0, pts: [[10, 0], [10, 10]], pen: true, c: 'b', w: 1, h: 90, s: 0 },
        { t: 'path', id: 0, pts: [[10, 10], [0, 0]], pen: true, c: 'c', w: 1, h: 225, s: 0 },
        { t: 'endfill', id: 0, pts: [[10, 0], [10, 10], [0, 0]], c: 'red' },
    ]);
    s.finishAll();
    assert.deepEqual(s.items.map((i) => i.kind), ['line', 'fill', 'line', 'line']);
    assert.ok(s.rebuild);
});

test('clear löscht nur die Zeichnung der eigenen Schildkröte, "all" alles', () => {
    const s = new TurtleScene();
    s.push([
        { t: 'path', id: 1, pts: [[0, 0], [1, 1]], pen: true, c: 'a', w: 1, h: 0, s: 0 },
        { t: 'path', id: 2, pts: [[0, 0], [2, 2]], pen: true, c: 'b', w: 1, h: 0, s: 0 },
        { t: 'clear', id: 1 },
    ]);
    s.finishAll();
    assert.deepEqual(s.items.map((i) => i.tid), [2]);
    s.push({ t: 'clear', id: 'all' });
    s.finishAll();
    assert.equal(s.items.length, 0);
});

test('Punkt, Text, Hintergrund und Sichtbarkeit', () => {
    const s = new TurtleScene();
    s.push([
        { t: 'bg', c: 'navy' },
        { t: 'dot', id: 0, x: 1, y: 2, d: 6, c: 'red' },
        { t: 'text', id: 0, x: 0, y: 0, s: 'Hallo', c: 'white', font: ['Arial', 12, 'bold'], align: 'center' },
        { t: 'sprite', id: 0, shown: false },
    ]);
    s.finishAll();
    assert.equal(s.bg, 'navy');
    assert.deepEqual(s.items.map((i) => i.kind), ['dot', 'text']);
    assert.equal(s.items[1].text, 'Hallo');
    assert.equal(s.sprite(0).shown, false);
});

test('Rückstau beschleunigt die Animation', () => {
    const slow = new TurtleScene();
    const fast = new TurtleScene();
    const cmd = (i) => ({ t: 'path', id: 0, pts: [[0, i], [200, i]], pen: true, c: 'k', w: 1, h: 0, s: 1 });
    slow.push([cmd(0)]);
    for (let i = 0; i < 3000; i++) {
        fast.push(cmd(i));
    }
    slow.advance(1000);
    fast.advance(1000);
    assert.ok(fast.items.length > slow.items.length * 5 || fast.items.length > 20);
});

test('zu viele Teile: älteste werden verworfen', () => {
    const s = new TurtleScene({ maxItems: 100 });
    for (let i = 0; i < 150; i++) {
        s.push({ t: 'dot', id: 0, x: i, y: 0, d: 2, c: 'k' });
    }
    s.finishAll();
    assert.ok(s.items.length <= 100);
    assert.equal(s.items[s.items.length - 1].x, 149);
});

test('Umschließendes Rechteck', () => {
    assert.equal(itemsBounds([]), null);
    assert.deepEqual(itemsBounds([{ pts: [[0, 0], [5, -3]] }, { x: 9, y: 2 }]), { minX: 0, minY: -3, maxX: 9, maxY: 2 });
});

test('unbekannte Befehle und Müll werden ignoriert', () => {
    const s = new TurtleScene();
    s.push([null, 5, { t: 'unbekannt' }, { t: 'path', id: 0, pts: [], pen: true }]);
    s.finishAll();
    assert.equal(s.items.length, 0);
});
