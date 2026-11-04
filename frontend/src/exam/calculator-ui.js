/**
 * Oberfläche des Taschenrechners (Rechenkern: calc.js, ohne eval).
 */
import { h } from '../lib/dom.js';
import { evaluate, formatNumber, CalcError } from './calc.js';

const KEYS = [
    ['sin', 'sin('], ['cos', 'cos('], ['tan', 'tan('], ['√', 'sqrt('],
    ['log', 'log('], ['ln', 'ln('], ['π', 'pi'], ['e', 'e'],
    ['7', '7'], ['8', '8'], ['9', '9'], ['÷', '/'],
    ['4', '4'], ['5', '5'], ['6', '6'], ['×', '*'],
    ['1', '1'], ['2', '2'], ['3', '3'], ['−', '-'],
    ['0', '0'], [',', '.'], ['^', '^'], ['+', '+'],
    ['(', '('], [')', ')'], ['ans', 'ans'],
];

export function createCalculator() {
    let angle = 'deg';
    let last = null;
    const input = h('input', {
        type: 'text', class: 'calc-input input', autocomplete: 'off', spellcheck: 'false',
        autocapitalize: 'off', inputmode: 'text', 'aria-label': 'Ausdruck', placeholder: 'z. B. 2*(3+4)^2',
    });
    const out = h('output', { class: 'calc-output', 'aria-live': 'polite' }, '0');
    const error = h('div', { class: 'calc-error', role: 'alert' });
    const history = h('ol', { class: 'calc-history', 'aria-label': 'Bisherige Rechnungen' });

    const insert = (text) => {
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? input.value.length;
        input.setRangeText(text, start, end, 'end');
        input.focus();
    };

    const calculate = () => {
        error.textContent = '';
        const expr = input.value;
        if (expr.trim() === '') {
            return;
        }
        try {
            const value = evaluate(expr, { angle, ans: last ?? undefined });
            last = value;
            const text = formatNumber(value);
            out.textContent = text;
            history.prepend(h('li', {}, `${expr} = ${text}`));
            while (history.children.length > 6) {
                history.lastElementChild.remove();
            }
        } catch (e) {
            error.textContent = e instanceof CalcError ? e.message : 'Das konnte nicht berechnet werden.';
        }
    };

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            calculate();
        }
    });

    const pad = h('div', { class: 'calc-pad' });
    for (const [label, value] of KEYS) {
        const b = h('button', { type: 'button', class: 'btn btn-sm calc-key', 'aria-label': label === 'π' ? 'Pi' : label }, label);
        b.addEventListener('click', () => insert(value));
        pad.append(b);
    }
    const clear = h('button', { type: 'button', class: 'btn btn-sm calc-key' }, 'C');
    clear.addEventListener('click', () => {
        input.value = '';
        error.textContent = '';
        out.textContent = '0';
        input.focus();
    });
    const back = h('button', { type: 'button', class: 'btn btn-sm calc-key', 'aria-label': 'Letztes Zeichen löschen' }, '⌫');
    back.addEventListener('click', () => {
        input.value = input.value.slice(0, -1);
        input.focus();
    });
    const eq = h('button', { type: 'button', class: 'btn btn-sm btn-primary calc-key calc-eq' }, '=');
    eq.addEventListener('click', calculate);
    const mode = h('button', { type: 'button', class: 'btn btn-sm calc-key', 'aria-pressed': 'false', title: 'Winkelmodus umschalten' }, 'DEG');
    mode.addEventListener('click', () => {
        angle = angle === 'deg' ? 'rad' : 'deg';
        mode.textContent = angle.toUpperCase();
        mode.setAttribute('aria-pressed', angle === 'rad' ? 'true' : 'false');
    });
    pad.append(clear, back, mode, eq);

    const el = h('div', { class: 'calc' }, input, out, error, pad,
        h('p', { class: 'w-hint' }, 'Erlaubt: + − × ÷ ^ ( ) sqrt sin cos tan log ln pi e ans. Winkel in Grad (DEG) oder Bogenmaß (RAD).'),
        history);
    return { el, focus: () => input.focus() };
}
