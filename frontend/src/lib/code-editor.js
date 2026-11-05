/**
 * CodeMirror-6-Editor für alle Bereiche (Schüleransicht, Aufgaben-Editor,
 * Korrektur). Eine Stelle für Sprache, Komfort-Schalter und Theme.
 *
 *   const ed = createCodeEditor({
 *       parent, doc: 'print(1)', language: 'python',
 *       readOnly: false,
 *       comfort: { autocomplete: false, lint: false, closeBrackets: true },
 *       fontSize: 15,
 *       onChange(value, { changes, isPaste, userEvents }) { … },
 *   });
 *   ed.getValue(); ed.setValue('…'); ed.setReadOnly(true); ed.destroy();
 *   ed.setDiagnostics([{ from, to, severity: 'error', message }]);
 *   ed.addLineMarkers([{ line: 3, className: 'cm-graded' }]);   // Korrektur
 *
 * changes ist ChangeSet.toJSON() und eignet sich für das Replay
 * (siehe docs/DATENFORMATE.md, „answer_ops“).
 */
import { EditorState, EditorSelection, Compartment, StateEffect, StateField, RangeSetBuilder, Transaction } from '@codemirror/state';
import {
    EditorView, keymap, lineNumbers, highlightActiveLine, highlightActiveLineGutter,
    drawSelection, rectangularSelection, highlightSpecialChars, Decoration,
} from '@codemirror/view';
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands';
import {
    indentOnInput, bracketMatching, syntaxHighlighting, HighlightStyle, indentUnit, foldGutter,
} from '@codemirror/language';
import { closeBrackets, closeBracketsKeymap, autocompletion, completionKeymap } from '@codemirror/autocomplete';
import { lintGutter, setDiagnostics } from '@codemirror/lint';
import { searchKeymap, highlightSelectionMatches } from '@codemirror/search';
import { java } from '@codemirror/lang-java';
import { python } from '@codemirror/lang-python';
import { sql, SQLite } from '@codemirror/lang-sql';
import { markdown } from '@codemirror/lang-markdown';
import { tags as t, highlightCode, classHighlighter } from '@lezer/highlight';

/** Sprachunterstützung je Kürzel. */
export function languageSupport(language) {
    switch (language) {
        case 'java': return java();
        case 'python': return python();
        case 'sql': return sql({ dialect: SQLite, upperCaseKeywords: true });
        case 'markdown': return markdown();
        default: return [];
    }
}

/** Farben passend zu --editor-* in app.css (dunkler Editor). */
export const editorHighlight = HighlightStyle.define([
    { tag: [t.keyword, t.controlKeyword, t.moduleKeyword, t.operatorKeyword], color: '#c4b5fd', fontWeight: '600' },
    { tag: [t.typeName, t.className, t.namespace], color: '#7dd3fc' },
    { tag: [t.function(t.variableName), t.function(t.propertyName)], color: '#fcd34d' },
    { tag: [t.string, t.special(t.string)], color: '#86efac' },
    { tag: [t.number, t.bool, t.null, t.atom], color: '#fdba74' },
    { tag: [t.comment, t.lineComment, t.blockComment], color: '#8a8fb5', fontStyle: 'italic' },
    { tag: [t.definition(t.variableName), t.propertyName], color: '#e4e6f5' },
    { tag: [t.operator, t.punctuation, t.bracket], color: '#cbd5e1' },
    { tag: [t.annotation, t.meta], color: '#f9a8d4' },
    { tag: t.heading, color: '#c4b5fd', fontWeight: '700' },
    { tag: t.emphasis, fontStyle: 'italic' },
    { tag: t.strong, fontWeight: '700' },
    { tag: t.invalid, color: '#fca5a5' },
]);

export const editorTheme = (fontSize = 15) => EditorView.theme({
    '&': {
        backgroundColor: 'var(--editor-bg)',
        color: 'var(--editor-ink)',
        fontSize: `${fontSize}px`,
        height: '100%',
    },
    '.cm-scroller': { fontFamily: 'var(--font-mono)', lineHeight: '1.55' },
    '.cm-content': { caretColor: '#a5b4fc', padding: '8px 0' },
    '.cm-cursor, .cm-dropCursor': { borderLeftColor: '#a5b4fc', borderLeftWidth: '2px' },
    '&.cm-focused .cm-selectionBackground, .cm-selectionBackground, .cm-content ::selection': {
        backgroundColor: 'var(--editor-selection)',
    },
    '.cm-gutters': {
        backgroundColor: 'var(--editor-gutter)',
        color: 'var(--editor-faint)',
        border: 'none',
    },
    '.cm-activeLine': { backgroundColor: 'var(--editor-line)' },
    '.cm-activeLineGutter': { backgroundColor: 'rgba(255,255,255,.06)', color: 'var(--editor-ink)' },
    '.cm-matchingBracket': { backgroundColor: 'rgba(165,180,252,.25)', outline: '1px solid rgba(165,180,252,.5)' },
    '.cm-tooltip': { backgroundColor: '#24264a', color: 'var(--editor-ink)', border: '1px solid #3a3d6b' },
    '.cm-tooltip-autocomplete > ul > li[aria-selected]': { backgroundColor: '#3730a3', color: '#fff' },
    '.cm-panels': { backgroundColor: '#24264a', color: 'var(--editor-ink)' },
    '.cm-line-marked': { backgroundColor: 'rgba(250, 204, 21, .12)' },
    '.cm-line-error': { backgroundColor: 'rgba(239, 68, 68, .14)' },
}, { dark: true });

// ---------- Zeilenmarkierungen (Korrektur, Fehlerzeilen) ----------
const setLineMarkers = StateEffect.define();
const lineMarkerField = StateField.define({
    create: () => Decoration.none,
    update(deco, tr) {
        deco = deco.map(tr.changes);
        for (const e of tr.effects) {
            if (e.is(setLineMarkers)) {
                const builder = new RangeSetBuilder();
                const sorted = [...e.value].sort((a, b) => a.line - b.line);
                for (const m of sorted) {
                    if (m.line < 1 || m.line > tr.state.doc.lines) {
                        continue;
                    }
                    const line = tr.state.doc.line(m.line);
                    builder.add(line.from, line.from, Decoration.line({ class: m.className || 'cm-line-marked' }));
                }
                deco = builder.finish();
            }
        }
        return deco;
    },
    provide: (f) => EditorView.decorations.from(f),
});

/**
 * @param {{
 *   parent: HTMLElement, doc?: string, language?: string, readOnly?: boolean,
 *   comfort?: {autocomplete?: boolean, lint?: boolean, closeBrackets?: boolean},
 *   fontSize?: number, tabSize?: number, lineWrapping?: boolean, minHeight?: string,
 *   onChange?: (value: string, meta: {changes: any, isPaste: boolean, userEvents: string[]}) => void,
 *   extensions?: any[],
 * }} options
 */
export function createCodeEditor(options) {
    const comfort = { autocomplete: false, lint: false, closeBrackets: true, ...(options.comfort ?? {}) };
    const readOnly = new Compartment();
    const lang = new Compartment();
    const tabSize = options.tabSize ?? 4;

    const extensions = [
        lineNumbers(),
        highlightActiveLineGutter(),
        highlightSpecialChars(),
        history(),
        foldGutter(),
        drawSelection(),
        rectangularSelection(),
        indentOnInput(),
        bracketMatching(),
        highlightActiveLine(),
        highlightSelectionMatches(),
        syntaxHighlighting(editorHighlight),
        indentUnit.of(' '.repeat(tabSize)),
        EditorState.tabSize.of(tabSize),
        editorTheme(options.fontSize ?? 15),
        lineMarkerField,
        lang.of(languageSupport(options.language)),
        readOnly.of([EditorState.readOnly.of(!!options.readOnly), EditorView.editable.of(!options.readOnly)]),
        keymap.of([
            ...(comfort.closeBrackets ? closeBracketsKeymap : []),
            ...defaultKeymap,
            ...searchKeymap,
            ...historyKeymap,
            ...(comfort.autocomplete ? completionKeymap : []),
            indentWithTab,
        ]),
        // Browser-Rechtschreibprüfung und Autokorrektur aus (auch keine
        // Vorschläge des Betriebssystems).
        EditorView.contentAttributes.of({ spellcheck: 'false', autocorrect: 'off', autocapitalize: 'off' }),
    ];
    if (comfort.closeBrackets) {
        extensions.push(closeBrackets());
    }
    if (comfort.autocomplete) {
        extensions.push(autocompletion({ activateOnTyping: true }));
    }
    if (comfort.lint) {
        extensions.push(lintGutter());
    }
    if (options.lineWrapping) {
        extensions.push(EditorView.lineWrapping);
    }
    if (options.minHeight) {
        extensions.push(EditorView.theme({ '.cm-content, .cm-gutter': { minHeight: options.minHeight } }));
    }
    if (options.onChange) {
        extensions.push(EditorView.updateListener.of((update) => {
            if (!update.docChanged) {
                return;
            }
            const userEvents = update.transactions.map((tr) => tr.annotation(Transaction.userEvent)).filter(Boolean);
            options.onChange(update.state.doc.toString(), {
                changes: update.changes.toJSON(),
                isPaste: userEvents.some((ev) => ev.startsWith('input.paste') || ev.startsWith('input.drop')),
                userEvents,
            });
        }));
    }
    extensions.push(...(options.extensions ?? []));

    const view = new EditorView({
        parent: options.parent,
        state: EditorState.create({ doc: options.doc ?? '', extensions }),
    });

    return {
        view,
        getValue: () => view.state.doc.toString(),
        setValue(value) {
            view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: value ?? '' } });
        },
        setReadOnly(flag) {
            view.dispatch({ effects: readOnly.reconfigure([EditorState.readOnly.of(flag), EditorView.editable.of(!flag)]) });
        },
        setLanguage(language) {
            view.dispatch({ effects: lang.reconfigure(languageSupport(language)) });
        },
        setDiagnostics(diagnostics) {
            view.dispatch(setDiagnostics(view.state, diagnostics));
        },
        addLineMarkers(markers) {
            view.dispatch({ effects: setLineMarkers.of(markers) });
        },
        focus: () => view.focus(),
        destroy: () => view.destroy(),
    };
}

/** Auswahl des Code-Editors, zu dem el gehört (JSON), oder null. Für die Fokus-Rettung beim Neuzeichnen. */
export function editorSelectionOf(el) {
    const dom = el?.closest?.('.cm-editor');
    const view = dom ? EditorView.findFromDOM(dom) : null;

    return view ? view.state.selection.toJSON() : null;
}

/** Setzt eine mit editorSelectionOf gemerkte Auswahl, sofern sie in den Text passt. */
export function restoreEditorSelection(el, json) {
    const dom = el?.closest?.('.cm-editor');
    const view = dom ? EditorView.findFromDOM(dom) : null;
    if (!view || !json) {
        return;
    }
    try {
        const selection = EditorSelection.fromJSON(json);
        const length = view.state.doc.length;
        if (selection.ranges.every((r) => r.from <= length && r.to <= length)) {
            view.dispatch({ selection, scrollIntoView: true });
        }
    } catch {
        // Ungültige Auswahl: Cursor bleibt am Anfang.
    }
}

/**
 * Färbt <pre><code class="language-java|python|sql">-Blöcke in vom Server
 * gerendertem Markdown ein (Aufgabentexte, Erwartungshorizont). Nutzt die
 * Klassen tok-* (Farben in app.css unter „Code-Hervorhebung“).
 */
export function highlightCodeBlocks(root) {
    for (const code of root.querySelectorAll('pre > code[class*="language-"]')) {
        if (code.dataset.highlighted === '1') {
            continue;
        }
        const langName = [...code.classList].find((c) => c.startsWith('language-'))?.slice(9);
        const support = languageSupport(langName);
        const parser = support?.language?.parser;
        if (!parser) {
            continue;
        }
        const text = code.textContent ?? '';
        const out = document.createDocumentFragment();
        highlightCode(text, parser.parse(text), classHighlighter,
            (part, classes) => {
                if (classes) {
                    const span = document.createElement('span');
                    span.className = classes;
                    span.textContent = part;
                    out.append(span);
                } else {
                    out.append(document.createTextNode(part));
                }
            },
            () => out.append(document.createTextNode('\n')),
        );
        code.replaceChildren(out);
        code.dataset.highlighted = '1';
    }
}
