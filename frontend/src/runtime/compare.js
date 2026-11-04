/**
 * Vergleich der Programmausgabe mit der erwarteten Ausgabe eines
 * Beispieltests (config.sample_tests[].compare, docs/DATENFORMATE.md 2.1).
 *
 *   exact              Zeichen für Zeichen (nur Zeilenenden werden vereinheitlicht)
 *   trim               Leerraum am Anfang und Ende der gesamten Ausgabe wird ignoriert
 *   ignore_whitespace  jede Folge von Leerraum zählt als ein Leerzeichen, Ränder ignoriert
 *   contains           erwartete Ausgabe kommt irgendwo in der Ausgabe vor
 *   regex              erwartete Ausgabe ist ein regulärer Ausdruck (auch als /muster/flags)
 */
export const COMPARE_MODES = ['exact', 'trim', 'ignore_whitespace', 'contains', 'regex'];

const normalizeNewlines = (s) => String(s ?? '').replace(/\r\n?/g, '\n');

/** Zerlegt "/muster/gi" in Muster und erlaubte Flags; sonst das ganze Muster. */
export function parseRegex(source) {
    const text = String(source ?? '');
    const m = /^\/([\s\S]*)\/([a-z]*)$/.exec(text);
    if (!m) {
        return { pattern: text, flags: '' };
    }
    const flags = [...new Set(m[2].split(''))].filter((f) => 'imsu'.includes(f)).join('');
    return { pattern: m[1], flags };
}

/**
 * @param {string} actual
 * @param {string} expected
 * @param {string} [mode]
 * @returns {{ok: boolean, message: string}}
 */
export function compareOutput(actual, expected, mode = 'trim') {
    const a = normalizeNewlines(actual);
    const e = normalizeNewlines(expected);
    switch (mode) {
        case 'exact':
            return a === e ? ok() : fail('Die Ausgabe stimmt nicht exakt überein.');
        case 'trim':
            return a.trim() === e.trim() ? ok() : fail('Die Ausgabe weicht von der erwarteten ab.');
        case 'ignore_whitespace': {
            const squash = (s) => s.replace(/\s+/g, ' ').trim();
            return squash(a) === squash(e) ? ok() : fail('Die Ausgabe weicht ab (Leerraum wird ignoriert).');
        }
        case 'contains':
            return a.includes(e.trim()) ? ok() : fail('Die erwartete Ausgabe kommt nicht vor.');
        case 'regex': {
            const { pattern, flags } = parseRegex(expected);
            try {
                return new RegExp(pattern, flags).test(a) ? ok() : fail('Die Ausgabe passt nicht zum erwarteten Muster.');
            } catch {
                return fail('Das erwartete Muster der Aufgabe ist ungültig.');
            }
        }
        default:
            return compareOutput(actual, expected, 'trim');
    }
}

const ok = () => ({ ok: true, message: 'Richtig' });
const fail = (message) => ({ ok: false, message });

/** Zeigt unsichtbare Zeichen, damit Unterschiede im Testbericht sichtbar werden. */
export function visibleWhitespace(text, max = 400) {
    const s = String(text ?? '').replace(/\t/g, '→').replace(/ (?= |$)/gm, '·').replace(/\r/g, '');
    return s.length > max ? `${s.slice(0, max)} …` : s;
}
