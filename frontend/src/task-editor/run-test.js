/**
 * Knopf "Testen": Musterlösung gegen die sichtbaren Beispiele laufen lassen.
 * Nutzt createRuntime (frontend/src/runtime/index.js) im Vorschaumodus. Das Laufzeitmodul
 * kann noch fehlen oder canRun() verneinen; dann kommt nur ein Hinweis, nie ein Fehler.
 */
import { createRuntime } from '../runtime/index.js';

const TIMEOUT_MS = 15000;

function compare(mode, actual, expected) {
    const norm = (s) => s.replace(/\r\n/g, '\n');
    const a = norm(actual);
    const e = norm(expected);
    switch (mode) {
        case 'exact': return a === e;
        case 'ignore_whitespace': return a.replace(/\s+/g, '') === e.replace(/\s+/g, '');
        case 'contains': return a.includes(e.trim());
        case 'regex':
            try {
                return new RegExp(e).test(a);
            } catch {
                return false;
            }
        default: return a.trim() === e.trim();
    }
}

/** Führt eine Sitzung bis zum Ende aus (oder bis zum Zeitlimit) und sammelt die Ausgabe. */
function runOnce(runtime, request, input) {
    return new Promise((resolve) => {
        let out = '';
        let finished = false;
        let session;
        const done = (result) => {
            if (!finished) {
                finished = true;
                clearTimeout(timer);
                resolve({ output: out, result });
            }
        };
        const timer = setTimeout(() => {
            try {
                session?.stop?.();
            } catch { /* egal */ }
            done({ timeout: true });
        }, TIMEOUT_MS);
        try {
            session = runtime.run(request);
            Promise.resolve(session).then((s) => {
                session = s ?? session;
                session.onOutput?.((text) => { out += text; });
                session.onExit?.((result) => done(result));
                if (input) {
                    session.sendInput?.(input.endsWith('\n') ? input : `${input}\n`);
                }
            }).catch((e) => done({ error: String(e?.message ?? e) }));
        } catch (e) {
            done({ error: String(e?.message ?? e) });
        }
    });
}

/**
 * @param {{config: any, files: any[], model_answer: string}} payload Stand des Editors
 * @param {{examId: number, settings: any, assets: any}} env
 * @returns {Promise<{ok: boolean, lines: string[]}>}
 */
export async function testModelAnswer(payload, env) {
    const config = payload.config ?? {};
    const model = payload.model_answer ?? '';
    if (model.trim() === '') {
        return { ok: false, lines: ['Trage zuerst eine Musterlösung ein.'] };
    }

    let runtime = null;
    try {
        runtime = createRuntime({ preview: true, examId: env.examId, settings: env.settings, assets: env.assets });
    } catch {
        runtime = null;
    }
    const task = { id: 0, answer_type: 'code', config };
    let can = false;
    try {
        can = !!runtime && typeof runtime.canRun === 'function' && runtime.canRun(task);
    } catch {
        can = false;
    }
    if (!can) {
        return { ok: false, lines: ['Die Ausführung ist in dieser Umgebung nicht verfügbar (Laufzeit nicht geladen oder für diese Aufgabe abgeschaltet).'] };
    }

    const main = config.main_file || 'Main.java';
    const files = [
        ...(payload.files ?? []).filter((f) => f.filename !== main).map((f) => ({ name: f.filename, content: f.content })),
        { name: main, content: model },
    ];
    const base = { language: config.language, files, mainFile: main, stdinMode: 'interactive', javaVersion: config.java_version ?? env.settings?.java_version, task };

    const cases = config.sample_tests?.length ? config.sample_tests : [{ name: 'Lauf ohne Beispiel', input: config.stdin ?? '', expected_output: null, compare: 'trim' }];
    const lines = [];
    let allOk = true;
    for (const c of cases) {
        const { output, result } = await runOnce(runtime, base, c.input ?? '');
        if (result?.timeout) {
            allOk = false;
            lines.push(`${c.name}: Zeitüberschreitung nach ${TIMEOUT_MS / 1000} s.`);
        } else if (result?.error) {
            allOk = false;
            lines.push(`${c.name}: Fehler: ${result.error}`);
        } else if (c.expected_output === null) {
            lines.push(`${c.name}: Ausgabe\n${output}`);
        } else if (compare(c.compare ?? 'trim', output, c.expected_output ?? '')) {
            lines.push(`${c.name}: bestanden`);
        } else {
            allOk = false;
            lines.push(`${c.name}: FEHLER\n  erwartet: ${JSON.stringify(c.expected_output)}\n  erhalten: ${JSON.stringify(output)}`);
        }
    }

    return { ok: allOk, lines };
}
