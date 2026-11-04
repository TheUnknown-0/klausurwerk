/**
 * Code in der Korrektur und in der Rückgabe: schreibgeschützter Editor je Datei mit Zeilennummern.
 *
 * Korrektur: Zeilen markieren (oder den Cursor setzen) und "Kommentar" wählen. Zeilen mit Kommentar
 * sind hinterlegt und tragen eine Sprechblase in der Randspalte.
 * Rückgabe: dieselbe Ansicht ohne Eingabe.
 *
 *   const view = mountCodeView(container, { task, content, comments, canComment, onComment(anchor), onBubble(commentIds) });
 *   view.setComments(list); view.focusComment(comment); view.destroy();
 */
import { EditorView, gutter, GutterMarker } from '@codemirror/view';
import { EditorState, StateEffect } from '@codemirror/state';
import { createCodeEditor } from '../lib/code-editor.js';
import { h } from '../lib/dom.js';
import { buildFileList } from '../runtime/files.js';
import { lineCommentMap } from './logic.js';

const refreshBubbles = StateEffect.define();

class Bubble extends GutterMarker {
    constructor(count) {
        super();
        this.count = count;
    }

    eq(other) {
        return other.count === this.count;
    }

    toDOM() {
        const el = document.createElement('span');
        el.className = 'g-bubble';
        el.textContent = this.count > 0 ? '💬' : '';
        el.title = this.count > 1 ? `${this.count} Kommentare` : 'Kommentar';
        return el;
    }
}

function languageOf(name, fallback) {
    const n = name.toLowerCase();
    if (n.endsWith('.java')) return 'java';
    if (n.endsWith('.py')) return 'python';
    if (n.endsWith('.sql')) return 'sql';
    return n.endsWith('.txt') || n.endsWith('.csv') || n.endsWith('.json') ? '' : fallback;
}

export function mountCodeView(container, options) {
    const task = options.task ?? {};
    const config = task.config ?? {};
    const language = ['java', 'python', 'sql'].includes(config.language) ? config.language : 'java';
    const files = buildFileList(task.files, options.content, { ...config, language });
    let comments = options.comments ?? [];
    let activeName = (files.find((f) => !f.readonly) ?? files[0]).name;
    /** @type {Map<string, {editor: any, pane: HTMLElement, lineMap: Map<number, number[]>}>} */
    const editors = new Map();
    const selections = new Map(); // Datei -> { from, to } Zeilen

    const tabs = h('div', { class: 'cw-filetabs g-filetabs', role: 'tablist', 'aria-label': 'Dateien' });
    const hostEl = h('div', { class: 'g-editors' });
    const commentBtn = h('button', {
        type: 'button', class: 'btn btn-sm btn-primary', disabled: true,
        onclick: () => startComment(),
    }, '💬 Kommentar');
    const hint = h('span', { class: 'text-faint text-sm' }, options.canComment ? 'Markiere Zeilen oder setze den Cursor, dann „Kommentar“ wählen.' : '');
    const toolbar = options.canComment ? h('div', { class: 'g-code-toolbar' }, commentBtn, hint) : null;
    const root = h('div', { class: 'g-codeview' }, tabs, toolbar, hostEl);
    container.append(root);

    function lineMapFor(name) {
        return lineCommentMap(comments, name);
    }

    function markersFor(name) {
        return [...lineMapFor(name).keys()].map((line) => ({ line, className: 'cm-line-marked g-line-commented' }));
    }

    function ensureEditor(file) {
        let entry = editors.get(file.name);
        if (entry) {
            return entry;
        }
        const pane = h('div', { class: 'g-editor-pane', role: 'tabpanel', hidden: true, 'aria-label': `Datei ${file.name}` });
        hostEl.append(pane);
        entry = { editor: null, pane, lineMap: lineMapFor(file.name) };
        const bubbles = gutter({
            class: 'g-bubble-gutter',
            lineMarker(view, line) {
                const number = view.state.doc.lineAt(line.from).number;
                const ids = entry.lineMap.get(number);
                return ids ? new Bubble(ids.length) : null;
            },
            lineMarkerChange: (update) => update.transactions.some((tr) => tr.effects.some((e) => e.is(refreshBubbles))),
            initialSpacer: () => new Bubble(1),
            domEventHandlers: {
                mousedown(view, line) {
                    const ids = entry.lineMap.get(view.state.doc.lineAt(line.from).number);
                    if (ids) {
                        options.onBubble?.(ids);
                        return true;
                    }
                    return false;
                },
            },
        });
        const tracker = EditorView.updateListener.of((update) => {
            if (!update.selectionSet && !update.docChanged && !update.focusChanged) {
                return;
            }
            const main = update.state.selection.main;
            const from = update.state.doc.lineAt(main.from).number;
            let toPos = main.to;
            if (main.to > main.from && update.state.doc.lineAt(main.to).from === main.to) {
                toPos = main.to - 1; // Auswahl endet am Zeilenanfang: Folgezeile zählt nicht
            }
            const to = update.state.doc.lineAt(Math.max(main.from, toPos)).number;
            selections.set(file.name, { from, to });
            refreshToolbar();
        });
        entry.editor = createCodeEditor({
            parent: pane,
            doc: file.content,
            language: languageOf(file.name, language),
            // Nicht "editable: false": Nur so verfolgt CodeMirror die Auswahl für Kommentare.
            // Schreiben verhindert EditorState.readOnly, die Bildschirmtastatur bleibt aus.
            readOnly: false,
            comfort: { closeBrackets: false },
            fontSize: 14,
            extensions: [
                EditorState.readOnly.of(true),
                EditorView.contentAttributes.of({ inputmode: 'none' }),
                bubbles,
                tracker,
            ],
        });
        entry.editor.addLineMarkers(markersFor(file.name));
        editors.set(file.name, entry);
        return entry;
    }

    function refreshToolbar() {
        if (!options.canComment) {
            return;
        }
        const sel = selections.get(activeName);
        commentBtn.disabled = !sel;
        if (sel) {
            commentBtn.textContent = sel.from === sel.to ? `💬 Kommentar zu Zeile ${sel.from}` : `💬 Kommentar zu Zeilen ${sel.from} bis ${sel.to}`;
        } else {
            commentBtn.textContent = '💬 Kommentar';
        }
    }

    function startComment() {
        const sel = selections.get(activeName);
        if (!sel) {
            return;
        }
        options.onComment?.({ kind: 'code', file: activeName, from_line: sel.from, to_line: sel.to });
    }

    function renderTabs() {
        tabs.replaceChildren();
        for (const f of files) {
            const selected = f.name === activeName;
            const count = new Set([...lineMapFor(f.name).values()].flat()).size;
            tabs.append(h('button', {
                type: 'button', role: 'tab', 'aria-selected': selected ? 'true' : 'false', tabindex: selected ? '0' : '-1',
                class: ['cw-filetab', selected && 'is-active', f.readonly && 'is-readonly'],
                title: f.readonly ? `${f.name} (vorgegeben, schreibgeschützt)` : f.name,
                onclick: () => activate(f.name),
            },
            f.readonly ? h('span', { 'aria-hidden': 'true' }, '🔒 ') : null,
            f.name,
            count > 0 ? h('span', { class: 'g-tab-count', title: `${count} Kommentare` }, ` 💬${count}`) : null));
        }
    }

    function activate(name) {
        const file = files.find((f) => f.name === name);
        if (!file) {
            return;
        }
        activeName = name;
        for (const [n, e] of editors) {
            e.pane.hidden = n !== name;
        }
        const entry = ensureEditor(file);
        entry.pane.hidden = false;
        entry.editor.view.requestMeasure();
        renderTabs();
        refreshToolbar();
    }

    tabs.addEventListener('keydown', (ev) => {
        if (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft') {
            return;
        }
        ev.preventDefault();
        const idx = files.findIndex((f) => f.name === activeName);
        const next = files[(idx + (ev.key === 'ArrowRight' ? 1 : -1) + files.length) % files.length];
        activate(next.name);
        tabs.querySelector('[aria-selected="true"]')?.focus();
    });

    activate(activeName);

    return {
        setComments(next) {
            comments = next ?? [];
            for (const [name, entry] of editors) {
                entry.lineMap = lineMapFor(name);
                entry.editor.addLineMarkers(markersFor(name));
                entry.editor.view.dispatch({ effects: refreshBubbles.of(null) });
            }
            renderTabs();
        },
        /** Springt zur Stelle eines Kommentars. */
        focusComment(comment) {
            const a = comment?.anchor;
            if (a?.kind !== 'code') {
                return;
            }
            activate(a.file);
            const entry = editors.get(a.file);
            if (!entry) {
                return;
            }
            const doc = entry.editor.view.state.doc;
            const line = doc.line(Math.min(Math.max(1, a.from_line), doc.lines));
            entry.editor.view.dispatch({ effects: EditorView.scrollIntoView(line.from, { y: 'center' }) });
        },
        destroy() {
            for (const entry of editors.values()) {
                entry.editor.destroy();
            }
            root.remove();
        },
    };
}
