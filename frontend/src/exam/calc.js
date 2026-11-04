/**
 * Rechenkern des Taschenrechners (rein, ohne DOM, ohne eval).
 *
 * Unterstützt + - * / ^ (rechtsassoziativ), Klammern, unäres Minus, Dezimalpunkt
 * und Dezimalkomma, Funktionen sqrt, sin, cos, tan, log (Basis 10), ln sowie die
 * Konstanten pi und e. "ans" ist das letzte Ergebnis. Implizites Malnehmen
 * (2pi, 3(4+1)) ist erlaubt. Winkelmodus: 'deg' oder 'rad'.
 */

export class CalcError extends Error {}

const FUNCTIONS = new Set(['sqrt', 'sin', 'cos', 'tan', 'log', 'ln']);
const CONSTANTS = { pi: Math.PI, e: Math.E };

/** Zerlegt den Ausdruck in Tokens. */
export function tokenize(src) {
    const text = String(src)
        .replace(/,/g, '.')
        .replace(/×/g, '*')
        .replace(/÷/g, '/')
        .replace(/−/g, '-')
        .replace(/π/g, 'pi')
        .replace(/√/g, 'sqrt');
    const tokens = [];
    let i = 0;
    while (i < text.length) {
        const c = text[i];
        if (/\s/.test(c)) {
            i++;
            continue;
        }
        if (/[0-9.]/.test(c)) {
            const m = /^(\d+\.?\d*|\.\d+)(e[+-]?\d+)?/i.exec(text.slice(i));
            if (!m) {
                throw new CalcError('Ungültige Zahl.');
            }
            tokens.push({ t: 'num', v: Number(m[0]) });
            i += m[0].length;
            continue;
        }
        if (/[a-z]/i.test(c)) {
            const m = /^[a-z]+/i.exec(text.slice(i));
            tokens.push({ t: 'id', v: m[0].toLowerCase() });
            i += m[0].length;
            continue;
        }
        if ('+-*/^()'.includes(c)) {
            tokens.push({ t: c });
            i++;
            continue;
        }
        throw new CalcError(`Unbekanntes Zeichen "${c}".`);
    }
    return tokens;
}

/**
 * Berechnet den Ausdruck.
 * @param {string} src
 * @param {{angle?: 'deg'|'rad', ans?: number}} [opts]
 * @returns {number}
 */
export function evaluate(src, opts = {}) {
    const angle = opts.angle === 'rad' ? 'rad' : 'deg';
    const tokens = tokenize(src);
    if (tokens.length === 0) {
        throw new CalcError('Bitte gib einen Ausdruck ein.');
    }
    let pos = 0;
    const peek = () => tokens[pos];
    const take = (type) => {
        if (peek()?.t === type) {
            return tokens[pos++];
        }
        return null;
    };
    const toRad = (x) => (angle === 'deg' ? (x * Math.PI) / 180 : x);
    const startsOperand = (tok) => tok && (tok.t === 'num' || tok.t === 'id' || tok.t === '(');

    function parseExpr() {
        let value = parseTerm();
        for (;;) {
            if (take('+')) {
                value += parseTerm();
            } else if (take('-')) {
                value -= parseTerm();
            } else {
                return value;
            }
        }
    }

    function parseTerm() {
        let value = parseUnary();
        for (;;) {
            if (take('*')) {
                value *= parseUnary();
            } else if (take('/')) {
                const d = parseUnary();
                if (d === 0) {
                    throw new CalcError('Division durch null.');
                }
                value /= d;
            } else if (startsOperand(peek())) {
                value *= parseUnary(); // implizites Malnehmen
            } else {
                return value;
            }
        }
    }

    // Unäres Vorzeichen bindet schwächer als ^ : -2^2 = -4
    function parseUnary() {
        if (take('-')) {
            return -parseUnary();
        }
        if (take('+')) {
            return parseUnary();
        }
        return parsePower();
    }

    function parsePower() {
        const base = parsePrimary();
        if (take('^')) {
            const exponent = parseUnary();
            const r = Math.pow(base, exponent);
            if (Number.isNaN(r)) {
                throw new CalcError('Potenz nicht definiert.');
            }
            return r;
        }
        return base;
    }

    function parsePrimary() {
        const tok = peek();
        if (!tok) {
            throw new CalcError('Der Ausdruck ist unvollständig.');
        }
        if (tok.t === 'num') {
            pos++;
            return tok.v;
        }
        if (tok.t === '(') {
            pos++;
            const v = parseExpr();
            if (!take(')')) {
                throw new CalcError('Es fehlt eine schließende Klammer.');
            }
            return v;
        }
        if (tok.t === 'id') {
            pos++;
            if (FUNCTIONS.has(tok.v)) {
                if (!take('(')) {
                    throw new CalcError(`Nach ${tok.v} fehlt eine Klammer.`);
                }
                const arg = parseExpr();
                if (!take(')')) {
                    throw new CalcError('Es fehlt eine schließende Klammer.');
                }
                return applyFunction(tok.v, arg);
            }
            if (tok.v === 'ans') {
                if (typeof opts.ans !== 'number') {
                    throw new CalcError('Es gibt noch kein vorheriges Ergebnis.');
                }
                return opts.ans;
            }
            if (Object.hasOwn(CONSTANTS, tok.v)) {
                return CONSTANTS[tok.v];
            }
            throw new CalcError(`Unbekannt: "${tok.v}".`);
        }
        throw new CalcError(`Unerwartetes Zeichen "${tok.t}".`);
    }

    function applyFunction(name, x) {
        switch (name) {
            case 'sqrt':
                if (x < 0) {
                    throw new CalcError('Wurzel aus einer negativen Zahl.');
                }
                return Math.sqrt(x);
            case 'sin': return cleanTrig(Math.sin(toRad(x)));
            case 'cos': return cleanTrig(Math.cos(toRad(x)));
            case 'tan': {
                const c = Math.cos(toRad(x));
                if (Math.abs(c) < 1e-12) {
                    throw new CalcError('Tangens ist hier nicht definiert.');
                }
                return cleanTrig(Math.sin(toRad(x)) / c);
            }
            case 'log':
                if (x <= 0) {
                    throw new CalcError('Logarithmus nur für positive Zahlen.');
                }
                return Math.log10(x);
            case 'ln':
                if (x <= 0) {
                    throw new CalcError('Logarithmus nur für positive Zahlen.');
                }
                return Math.log(x);
            default:
                throw new CalcError('Unbekannte Funktion.');
        }
    }

    const result = parseExpr();
    if (pos < tokens.length) {
        throw new CalcError(tokens[pos].t === ')' ? 'Zu viele schließende Klammern.' : 'Der Ausdruck ist nicht vollständig verständlich.');
    }
    if (!Number.isFinite(result)) {
        throw new CalcError('Das Ergebnis ist zu groß oder nicht definiert.');
    }
    return result;
}

/** Rundet Rauschen wie sin(180 Grad) = 1.2e-16 auf 0. */
function cleanTrig(x) {
    return Math.abs(x) < 1e-14 ? 0 : x;
}

/** Zahl für die Anzeige: höchstens 12 gültige Stellen, Dezimalkomma. */
export function formatNumber(n) {
    if (!Number.isFinite(n)) {
        return String(n);
    }
    let s = String(Number.parseFloat(n.toPrecision(12)));
    if (s.includes('e')) {
        s = s.replace('e+', 'e');
    }
    return s.replace('.', ',');
}
