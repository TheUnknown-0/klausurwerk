/**
 * Registries für Antworttypen. Jeder Antworttyp (answer_type) bringt bis zu
 * zwei Bausteine mit; Module registrieren sie beim Import, z. B.
 *
 *   import { registerAnswerWidget, registerTaskEditor } from '../lib/widgets.js';
 *   registerAnswerWidget('choice', ChoiceWidget);
 *   registerTaskEditor('choice', ChoiceEditor);
 *
 * Die Bundles (exam.js, task-editor.js, grading.js, …) importieren die
 * Widget-Module, die sie brauchen; die Registry verbindet sie.
 *
 * ---------------------------------------------------------------------------
 * AnswerWidget: Eingabe in der Schüleransicht, Anzeige in Pult/Korrektur
 * ---------------------------------------------------------------------------
 *   mount(container, ctx) → instance
 *     ctx.task       Aufgabe wie in /start-data (id, answer_type, config, files, …)
 *     ctx.content    gespeicherter Inhalt (Objekt je Typ, siehe DATENFORMATE.md)
 *                    oder null → Widget erzeugt den Leerzustand selbst
 *     ctx.readOnly   true in Pult, Korrektur, Rückgabe, nach Abgabe
 *     ctx.mode       'exam' | 'preview' | 'monitor' | 'grading' | 'review'
 *     ctx.env        { examId, settings, runtime }  runtime: Code-Ausführung
 *                    (siehe runtime-Schnittstelle unten), nur in exam/preview
 *     ctx.onChange(content, meta)
 *                    bei JEDER inhaltlichen Änderung aufrufen; content ist der
 *                    vollständige neue Inhalt. meta (optional):
 *                      { kind: 'ops', payload: <ChangeSet-JSON>, file: 'Main.java', isPaste }
 *                      { kind: 'value' }        (strukturierte Typen, Standard)
 *
 *   instance:
 *     getContent()            aktueller Inhalt (gleiches Format wie ctx.content)
 *     setReadOnly(flag)       Sperren/Entsperren (Sperrbildschirm, Abgabe)
 *     destroy()
 *     focus?()
 *     getPreviewPng?()        Promise<string|null>, Data-URL; Zeichentypen
 *     layout?                 'split' (Aufgabe links, Editor rechts; Code-Typen)
 *                             oder 'stack' (unter dem Aufgabentext; Standard)
 *
 * ---------------------------------------------------------------------------
 * TaskEditor: Konfiguration + Lösung im Aufgaben-Editor der Lehrkraft
 * ---------------------------------------------------------------------------
 *   mount(container, ctx) → instance
 *     ctx.config, ctx.solution   aktuelle Werte (Objekte, ggf. {})
 *     ctx.files                  vorgegebene Dateien (nur Code-Typen)
 *     ctx.onChange()             nach jeder Änderung aufrufen
 *   instance:
 *     getValue() → { config, solution, files? }
 *     validate?() → list<string> Fehlermeldungen (leer = ok)
 *     destroy()
 *
 * ---------------------------------------------------------------------------
 * runtime (ctx.env.runtime), bereitgestellt von der Schüleransicht
 * ---------------------------------------------------------------------------
 *   runtime.run({ language, files, mainFile, stdinMode, javaVersion, task })
 *      → RunSession { onOutput(cb(text, stream)), onExit(cb(result)), sendInput(text), stop() }
 *   runtime.canRun(task) → boolean (Klausur-/Aufgabeneinstellung run_mode)
 */

const answerWidgets = new Map();
const taskEditors = new Map();

export function registerAnswerWidget(type, widget) {
    answerWidgets.set(type, widget);
}

export function registerTaskEditor(type, editor) {
    taskEditors.set(type, editor);
}

export function answerWidget(type) {
    return answerWidgets.get(type) ?? null;
}

export function taskEditor(type) {
    return taskEditors.get(type) ?? null;
}

export function registeredAnswerTypes() {
    return [...answerWidgets.keys()];
}

/** Ersatzanzeige, wenn ein Typ (noch) kein Widget hat. */
export const FallbackWidget = {
    mount(container, ctx) {
        const pre = document.createElement('pre');
        pre.className = 'answer-fallback';
        pre.textContent = ctx.content ? JSON.stringify(ctx.content, null, 2) : '(keine Antwort)';
        container.append(pre);
        return {
            getContent: () => ctx.content,
            setReadOnly() {},
            destroy: () => pre.remove(),
        };
    },
};
