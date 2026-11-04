/**
 * Beispieltests (config.sample_tests): jeden Test als eigenen Lauf mit
 * vorgegebener Eingabe starten und die Ausgabe nach `compare` prüfen.
 * Reine Logik; der Lauf selbst kommt als Funktion hinein (testbar).
 */
import { compareOutput } from './compare.js';
import { resultToText } from './sql-results.js';

/**
 * Sammelt Ausgabe und Ergebnis einer Sitzung.
 * @returns {Promise<{stdout: string, stderr: string, result: any}>}
 */
export function collectSession(session, { timeoutMs = 15000 } = {}) {
    return new Promise((resolve) => {
        let stdout = '';
        let stderr = '';
        const timer = setTimeout(() => {
            session.stop();
        }, timeoutMs);
        session.onOutput((text, stream) => {
            if (stream === 'stderr') {
                stderr += text;
            } else if (stream === 'stdout') {
                stdout += text;
            }
        });
        session.onExit((result) => {
            clearTimeout(timer);
            resolve({ stdout, stderr, result });
        });
    });
}

/**
 * Bewertet einen Lauf gegen einen Beispieltest.
 * @param {{name?: string, expected_output?: string, compare?: string}} test
 */
export function evaluateSample(test, language, collected, index = 0) {
    const name = test.name || `Beispiel ${index + 1}`;
    const expected = String(test.expected_output ?? '');
    const { result } = collected;
    if (result?.timedOut) {
        return { name, ok: false, message: 'Das Programm hat zu lange gebraucht.', expected, actual: collected.stdout };
    }
    if (language === 'sql') {
        const err = (result?.sqlResults ?? []).find((r) => r.kind === 'error');
        if (err) {
            return { name, ok: false, message: `SQL-Fehler: ${err.message}`, expected, actual: '' };
        }
        const actual = resultToText(result?.sqlResults ?? []);
        return { name, ...compareOutput(actual, expected, test.compare), expected, actual };
    }
    if ((result?.compileErrors ?? []).length > 0) {
        return { name, ok: false, message: 'Der Code lässt sich nicht übersetzen.', expected, actual: '' };
    }
    const cmp = compareOutput(collected.stdout, expected, test.compare);
    if (!cmp.ok && (result?.exitCode ?? 0) !== 0) {
        return { name, ok: false, message: 'Das Programm hat einen Fehler gemeldet.', expected, actual: collected.stdout + collected.stderr };
    }
    return { name, ...cmp, expected, actual: collected.stdout };
}

/**
 * @param {{ tests: any[], language: string, runOne: (test: any) => Promise<{stdout:string, stderr:string, result:any}>,
 *           onProgress?: (done: number, total: number) => void, isCancelled?: () => boolean }} p
 */
export async function runSampleTests({ tests, language, runOne, onProgress, isCancelled }) {
    const results = [];
    for (let i = 0; i < tests.length; i++) {
        if (isCancelled?.()) {
            break;
        }
        const collected = await runOne(tests[i]);
        results.push(evaluateSample(tests[i], language, collected, i));
        onProgress?.(i + 1, tests.length);
    }
    return results;
}
