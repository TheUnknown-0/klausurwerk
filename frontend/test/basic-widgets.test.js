import test from 'node:test';
import assert from 'node:assert/strict';

// basic.js zieht Editor-Module nach; ohne Browser nur testen, wenn der Import gelingt.
let mod = null;
try {
    mod = await import('../src/widgets/basic.js');
} catch {
    mod = null;
}

test('Reihenfolge wird bereinigt (nur mit importierbarem Modul)', { skip: mod === null }, () => {
    const items = [{ id: 's1' }, { id: 's2' }, { id: 's3' }];
    assert.deepEqual(mod.normalizeOrder(['s3', 'x', 's3'], items), ['s3', 's1', 's2']);
    assert.deepEqual(mod.normalizeOrder(null, items), ['s1', 's2', 's3']);
});

test('Template wird in Text und Luecken zerlegt', { skip: mod === null }, () => {
    const parts = mod.splitTemplate('a {{g1}} b {{g_2}}\nc');
    assert.deepEqual(parts, [{ text: 'a ' }, { gap: 'g1' }, { text: ' b ' }, { gap: 'g_2' }, { text: '\nc' }]);
    assert.deepEqual(mod.splitTemplate('{{x}}{{y}}'), [{ gap: 'x' }, { gap: 'y' }]);
    assert.deepEqual(mod.splitTemplate('ohne'), [{ text: 'ohne' }]);
});

test('plainText entfernt Markup', { skip: mod === null || typeof DOMParser === 'undefined' }, () => {
    assert.equal(mod.plainText('<p>a <b>b</b></p>'), 'a b');
});
