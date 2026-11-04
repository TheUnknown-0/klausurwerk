import test from 'node:test';
import assert from 'node:assert/strict';
import { testPointsWarning } from '../src/task-editor/warnings.js';

test('keine Tests: keine Warnung', () => {
    assert.equal(testPointsWarning(6, []), '');
});

test('Summe passt: keine Warnung', () => {
    assert.equal(testPointsWarning(6, [{ points: 2 }, { points: 4 }]), '');
});

test('ein Test mit 1 Punkt bei 6 Aufgabenpunkten warnt', () => {
    const w = testPointsWarning(6, [{ name: 'a' }]);
    assert.match(w, /1 Punkte/);
    assert.match(w, /6 Punkten/);
});
