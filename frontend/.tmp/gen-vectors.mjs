import fs from 'node:fs';
import { RoboWorld } from '../src/robo/world.js';
const empty = (cols, rows, extra = {}) => ({ cols, rows, robo: { x: 0, y: 0, dir: 'E' }, walls: [], boxes: [], screws: [], targets: [], ...extra });
const cases = [
  ['Geradeaus', empty(5, 3), 'vvv'],
  ['Drehen im Kreis', empty(5, 3), 'rrrr'],
  ['Links drehen', empty(5, 3), 'l'],
  ['Bogen', empty(5, 5), 'vrvrvlv'],
  ['Wand', empty(5, 3, { walls: [[2, 0]] }), 'vv'],
  ['Rand', empty(5, 3, { robo: { x: 0, y: 0, dir: 'N' } }), 'v'],
  ['Kiste im Weg', empty(5, 3, { boxes: [[1, 0]] }), 'v'],
  ['Kiste schieben', empty(5, 3, { boxes: [[1, 0]] }), 'ss'],
  ['Kiste blockiert durch Kiste', empty(5, 3, { boxes: [[1, 0], [2, 0]] }), 's'],
  ['Kiste vor Wand', empty(5, 3, { boxes: [[1, 0]], walls: [[2, 0]] }), 's'],
  ['Kiste am Rand', empty(2, 2, { boxes: [[1, 0]] }), 's'],
  ['Schieben ohne Kiste', empty(5, 3), 's'],
  ['Schrauben einsammeln', empty(5, 3, { screws: [{ x: 1, y: 0, count: 2 }] }), 'vaa'],
  ['Zu viele Schrauben', empty(5, 3, { screws: [{ x: 1, y: 0, count: 2 }] }), 'vaaa'],
  ['Ablegen', empty(5, 3, { screws: [{ x: 0, y: 0, count: 1 }] }), 'ab'],
  ['Ablegen ohne Korb', empty(5, 3), 'b'],
  ['Mehrfach ablegen', empty(5, 3, { screws: [{ x: 0, y: 0, count: 1 }] }), 'abb'],
  ['Schrauben unterwegs', empty(5, 3, { screws: [{ x: 1, y: 0, count: 1 }, { x: 2, y: 0, count: 3 }], targets: [[4, 0]] }), 'vavaavvb'],
  ['Korb vorgegeben', empty(3, 3, { basket: 2 }), 'bvb'],
  ['Limit', empty(3, 3), 'l'.repeat(10001)],
  ['Genau am Limit', empty(3, 3), 'l'.repeat(10000)],
];
const out = [];
for (const [name, world, codes] of cases) {
  const w = new RoboWorld(world);
  let failIndex = -1, message = null;
  [...codes].forEach((c, i) => { if (failIndex < 0) { const r = w.step(c); if (!r.ok) { failIndex = i; message = r.message; } } });
  const base = { name, world: w.constructor === RoboWorld ? new RoboWorld(world).toJSON() : world, codes };
  out.push(failIndex < 0 ? { ...base, expect: { ok: true, world: w.toJSON() } } : { ...base, expect: { ok: false, index: failIndex, message } });
}
fs.writeFileSync('../runner/tests/robo_vectors.json', JSON.stringify({ comment: 'Gemeinsame Testfälle für world.js, robo.py und Robo.java. Codes: v l r a b s. Erzeugt mit frontend/.tmp/gen-vectors.mjs', cases: out }, null, 1));
console.log(out.map(o => o.name + ': ' + JSON.stringify(o.expect).slice(0, 110)).join('\n'));
