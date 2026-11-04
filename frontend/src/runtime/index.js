/**
 * Laufzeitumgebung für Code-Aufgaben (Schnittstelle: lib/widgets.js, "runtime").
 *
 *   const runtime = createRuntime({ examId, settings, assets: { pyodide, sqljs }, preview });
 *   runtime.canRun(task)            darf die Aufgabe laufen (run_mode != none)?
 *   runtime.runMode(task)           'run' | 'syntax' | 'none'
 *   runtime.run({ language, files, mainFile, stdin, javaVersion, task, mode, sqlSetup, pythonPackages })
 *        -> RunSession { onOutput(cb(text, stream)), onExit(cb(result)), onImage(cb(dataUrl)),
 *                        onTurtle(cb(commands)), onInputRequest(cb(waiting)), onStatus(cb(text)),
 *                        sendInput(text), stop() }
 *   runtime.warmup(language)        lädt Python bzw. SQL vorab im Hintergrund
 *   runtime.sqlSchema(sqlSetup)     Promise<Tabellen mit Spalten> (Hilfe "Datenbank anzeigen")
 *   runtime.interactiveInput        true, wenn input() in der Konsole beantwortet werden kann
 *   runtime.onGuiFrames             optional: Callback(frames) für Java-GUI (Stufe 4)
 *
 * result: { exitCode, timedOut, stopped, error, compileErrors: [{file,line,column,message,severity}],
 *           diagnostics: [...], sqlResults: [...]|null }
 */
import { BaseSession } from './session.js';
import { PythonHost, canUseSharedMemory } from './python.js';
import { SqlHost } from './sql.js';
import { runJava } from './java.js';

const LANGUAGES = ['python', 'sql', 'java'];

/** Wirksamer Ausführungsmodus: Aufgabe vor Klausur-Einstellung. */
export function effectiveRunMode(task, settings) {
    const mode = task?.config?.run_mode ?? settings?.run_mode ?? 'run';
    return ['run', 'syntax', 'none'].includes(mode) ? mode : 'run';
}

/** Wert aus Aufgabe, sonst Klausur-Einstellung (null/undefined = Einstellung). */
export function pick(taskValue, settingValue, fallback) {
    return taskValue ?? settingValue ?? fallback;
}

/** Sitzung, die sofort mit einer Fehlermeldung endet. */
function failedSession(message) {
    const s = new BaseSession();
    setTimeout(() => {
        s._output(`${message}\n`, 'stderr');
        s._finish({ exitCode: null, error: message });
    }, 0);
    return s;
}

/**
 * @param {{ examId?: number|null, settings?: any, assets?: {pyodide?: string, sqljs?: string}, preview?: boolean,
 *           hosts?: {python?: any, sql?: any} }} options
 */
export function createRuntime({ examId = null, settings = {}, assets = {}, preview = false, hosts = {} } = {}) {
    let python = hosts.python ?? null;
    let sql = hosts.sql ?? null;
    const pythonHost = () => (python ??= new PythonHost({ assets }));
    const sqlHost = () => (sql ??= new SqlHost({ assets }));

    const runtime = {
        examId,
        preview,
        settings,
        onGuiFrames: null,

        /** input() in der Konsole möglich (Cross-Origin-Isolation vorhanden)? */
        get interactiveInput() {
            return python ? python.interactive : canUseSharedMemory();
        },

        runMode: (task) => effectiveRunMode(task, settings),

        canRun(task) {
            return effectiveRunMode(task, settings) !== 'none' && LANGUAGES.includes(task?.config?.language ?? 'java');
        },

        warmup(language) {
            if (language === 'python') {
                return pythonHost().warmup();
            }
            if (language === 'sql') {
                return sqlHost().warmup();
            }
            return Promise.resolve();
        },

        sqlSchema: (setup) => sqlHost().schema(setup),

        run(opts) {
            const task = opts.task ?? null;
            const config = task?.config ?? {};
            const language = opts.language ?? config.language ?? 'java';
            const allowed = effectiveRunMode(task, settings);
            if (allowed === 'none') {
                return failedSession('Das Ausführen ist für diese Aufgabe nicht erlaubt.');
            }
            if (!LANGUAGES.includes(language)) {
                return failedSession(`Die Sprache „${language}“ wird nicht unterstützt.`);
            }
            // "syntax" in der Aufgabe oder Klausur erlaubt nur die Syntaxprüfung
            const mode = allowed === 'syntax' || opts.mode === 'syntax' ? 'syntax' : 'run';
            const common = {
                files: opts.files ?? [],
                mainFile: opts.mainFile,
                stdin: opts.stdin ?? null,
                mode,
                task,
                // Stapellauf (Beispieltests): keine interaktive Eingabe
                batch: !!opts.batch,
            };
            if (language === 'python') {
                const packages = pick(opts.pythonPackages, pick(config.python_packages, settings?.python_packages, []), []);
                return pythonHost().run({ ...common, pythonPackages: Array.isArray(packages) ? packages : [] });
            }
            if (language === 'sql') {
                return sqlHost().run({ ...common, sqlSetup: opts.sqlSetup ?? config.sql_setup ?? '' });
            }
            return runJava({
                ...common,
                examId,
                javaVersion: pick(opts.javaVersion, pick(config.java_version, settings?.java_version, '21'), '21'),
                gui: !!(opts.gui ?? config.gui),
                onGuiFrames: (frames) => runtime.onGuiFrames?.(frames),
            });
        },
    };
    return runtime;
}
