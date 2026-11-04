/**
 * Zeilenfilter für die Ausgabe eines Java-Robo-Programms.
 *
 * Die Bibliothek Robo.java schreibt jeden Zug als Zeile "U+0001 KWR:<code>" auf die Standardausgabe.
 * Der Filter zieht diese Zeilen heraus und reicht den übrigen Text unverändert weiter. Zeilen können
 * in beliebigen Stücken ankommen (die Abfrage liefert Chunks); Text vor dem Präfix, auch mitten in einer
 * Zeile, wird sofort weitergereicht (Eingabeaufforderungen ohne Zeilenende bleiben sichtbar).
 */
import { isTraceCode } from './world.js';

export const TRACE_PREFIX = '\u0001KWR:';
const MAX_LINE = 400;

/**
 * @param {{ onCode: (code: string) => void, onText: (text: string, stream?: string) => void }} handlers
 */
export function createTraceFilter({ onCode, onText }) {
    let mode = 'text'; // text | head | trace
    let head = '';
    let buf = '';

    function write(text, stream = 'stdout') {
        let plain = '';
        let i = 0;
        const n = text.length;
        while (i < n) {
            if (mode === 'text') {
                // Das Präfix darf mitten in einer Zeile stehen (z. B. nach print("Eingabe: ") ohne Zeilenende).
                const j = text.indexOf(TRACE_PREFIX[0], i);
                if (j < 0) {
                    plain += text.slice(i);
                    i = n;
                } else {
                    plain += text.slice(i, j);
                    head = TRACE_PREFIX[0];
                    i = j + 1;
                    mode = 'head';
                }
            } else if (mode === 'head') {
                const candidate = head + text[i];
                if (TRACE_PREFIX.startsWith(candidate)) {
                    head = candidate;
                    i++;
                    if (head.length === TRACE_PREFIX.length) {
                        mode = 'trace';
                        head = '';
                        buf = '';
                    }
                } else {
                    plain += head; // war doch kein Präfix; das aktuelle Zeichen wird neu betrachtet
                    head = '';
                    mode = 'text';
                }
            } else {
                const j = text.indexOf('\n', i);
                const piece = j < 0 ? text.slice(i) : text.slice(i, j);
                i = j < 0 ? n : j + 1;
                buf = (buf + piece).slice(0, MAX_LINE);
                if (j >= 0) {
                    const code = buf.replace(/\r$/, '');
                    if (isTraceCode(code)) {
                        onCode(code);
                    }
                    buf = '';
                    mode = 'text';
                }
            }
        }
        if (plain) {
            onText(plain, stream);
        }
    }

    /** Am Ende des Laufs: halbfertige Zeilen ausgeben bzw. verwerfen. */
    function end() {
        if (mode === 'head' && head) {
            onText(head, 'stdout');
        } else if (mode === 'trace') {
            const code = buf.replace(/\r$/, '');
            if (isTraceCode(code)) {
                onCode(code);
            }
        }
        head = '';
        buf = '';
        mode = 'text';
    }

    return { write, end };
}
