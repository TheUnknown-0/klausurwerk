/**
 * Sammelt den Live-Zustand aus der Klausuroberfläche (DOM): aktuelle Aufgabe, Datei,
 * Cursorzeile, Code-Ausschnitt und Konsolenende des sichtbaren Editors.
 * Liest nur, verändert nichts.
 */
import { EditorView } from '@codemirror/view';
import { excerptAround, tailLines } from './live-logic.js';

const VISIBLE_EDITOR = '.cw-editor-pane:not([hidden]) .cm-editor';

function findEditorEl(view) {
    const active = document.activeElement?.closest?.('.cm-editor');
    if (active && view.main.contains(active)) {
        return active;
    }
    const block = view.blocks.get(view.currentTaskId)?.block;
    const page = view.pages.get(view.currentTop);

    return block?.querySelector(VISIBLE_EDITOR) ?? page?.querySelector(VISIBLE_EDITOR) ?? null;
}

/**
 * @param {import('./view-exam.js').ExamView} view
 * @returns {object} Nutzlast für POST /live (ohne client_ms)
 */
export function collectLive(view) {
    const out = {
        task_id: view.currentTaskId ?? null,
        view: view.layout?.classList.contains('aids-open') ? 'aids' : 'task',
        file: null,
        cursor_line: null,
        code_excerpt: '',
        excerpt_start_line: 0,
        console_tail: '',
    };
    const el = findEditorEl(view);
    if (!el) {
        return out;
    }
    const owner = Number(el.closest('.task-block')?.dataset?.task);
    if (Number.isFinite(owner) && owner > 0) {
        out.task_id = owner;
    }
    const root = el.closest('.cw');
    out.file = root?.querySelector('.cw-filetab.is-active')?.dataset?.name ?? null;
    const cm = EditorView.findFromDOM(el);
    if (cm) {
        const line = cm.state.doc.lineAt(cm.state.selection.main.head).number;
        const { excerpt, startLine } = excerptAround(cm.state.doc.toString(), line);
        out.cursor_line = line;
        out.code_excerpt = excerpt;
        out.excerpt_start_line = startLine;
    }
    const lines = root?.querySelectorAll('.cw-console-lines .cw-line');
    if (lines && lines.length > 0) {
        out.console_tail = tailLines([...lines].map((l) => l.textContent).join('\n'));
    }

    return out;
}
