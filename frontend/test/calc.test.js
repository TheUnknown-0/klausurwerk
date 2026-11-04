import test from 'node:test';
import assert from 'node:assert/strict';
import { evaluate, formatNumber, CalcError } from '../src/exam/calc.js';

const close = (a, b, eps = 1e-9) => assert.ok(Math.abs(a - b) < eps, `${a} !~ ${b}`);

test('Grundrechenarten und Punkt vor Strich', () => {
    assert.equal(evaluate('1+2*3'), 7);
    assert.equal(evaluate('(1+2)*3'), 9);
    assert.equal(evaluate('10/4'), 2.5);
    assert.equal(evaluate('10-4-3'), 3);
    assert.equal(evaluate('8/2/2'), 2);
});

test('Potenz ist rechtsassoziativ, Minus bindet schwächer', () => {
    assert.equal(evaluate('2^3^2'), 512);
    assert.equal(evaluate('-2^2'), -4);
    assert.equal(evaluate('2^-1'), 0.5);
    assert.equal(evaluate('(-2)^2'), 4);
});

test('Dezimalkomma und Dezimalpunkt', () => {
    close(evaluate('1,5+2.25'), 3.75);
    close(evaluate('.5*4'), 2);
});

test('Funktionen und Konstanten', () => {
    close(evaluate('sqrt(16)'), 4);
    close(evaluate('sin(90)'), 1);
    close(evaluate('cos(60)'), 0.5);
    close(evaluate('tan(45)'), 1);
    close(evaluate('sin(pi/2)', { angle: 'rad' }), 1);
    close(evaluate('log(1000)'), 3);
    close(evaluate('ln(e)'), 1);
    close(evaluate('2pi'), 2 * Math.PI);
    assert.equal(evaluate('sin(180)'), 0);
});

test('implizites Malnehmen und ans', () => {
    assert.equal(evaluate('3(4+1)'), 15);
    assert.equal(evaluate('ans*2', { ans: 21 }), 42);
    assert.throws(() => evaluate('ans'), CalcError);
});

test('Fehler werden als CalcError gemeldet', () => {
    for (const bad of ['', '1+', '(1+2', '1+2)', '1/0', 'sqrt(-1)', 'log(0)', 'foo', '2 $ 3', 'sin', 'tan(90)']) {
        assert.throws(() => evaluate(bad), CalcError, bad);
    }
});

test('kein eval: Code wird nicht ausgeführt', () => {
    assert.throws(() => evaluate('process.exit(1)'), CalcError);
    assert.throws(() => evaluate('constructor'), CalcError);
    assert.throws(() => evaluate('__proto__'), CalcError);
});

test('Anzeige: 12 gültige Stellen, Dezimalkomma', () => {
    assert.equal(formatNumber(0.1 + 0.2), '0,3');
    assert.equal(formatNumber(1 / 3), '0,333333333333');
    assert.equal(formatNumber(42), '42');
    assert.equal(formatNumber(-2.5), '-2,5');
});
