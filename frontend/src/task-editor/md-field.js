/**
 * Markdown-Feld mit CodeMirror und Live-Vorschau (POST /api/markdown/vorschau, gleiches
 * Rendering wie in der Klausur). Mit language 'java' | 'python' | 'sql' dient es als reiner
 * Code-Editor ohne Vorschau.
 */
import { h, debounce, setTrustedHtml } from '../lib/dom.js';
import { post } from '../lib/api.js';
import { createCodeEditor } from '../lib/code-editor.js';
import { enhanceRichText } from '../lib/rich-text.js';
import { uid } from './ui.js';

/**
 * @param {{label: string, value: string, examId: number, previewUrl: string, onChange: () => void,
 *          language?: string, size?: '' | 'short' | 'tall', hint?: string}} opts
 */
export function createMarkdownField(opts) {
    const language = opts.language ?? 'markdown';
    const withPreview = language === 'markdown';
    const id = uid('md');
    const host = h('div', { class: `te-cm ${opts.size ?? ''}`, id, role: 'group', 'aria-labelledby': `${id}-label` });
    const preview = withPreview ? h('div', { class: 'te-preview rich-text', 'aria-label': `Vorschau: ${opts.label}`, 'aria-live': 'polite' }) : null;
    let seq = 0;

    const render = debounce(async (text) => {
        if (!preview) {
            return;
        }
        const mine = ++seq;
        if (text.trim() === '') {
            preview.replaceChildren();
            return;
        }
        const res = await post(opts.previewUrl, { markdown: text, exam_id: opts.examId });
        if (mine !== seq) {
            return;
        }
        if (res.ok) {
            setTrustedHtml(preview, res.data.html ?? '');
            enhanceRichText(preview);
        } else {
            preview.textContent = `Vorschau nicht verfügbar: ${res.error}`;
        }
    }, 400);

    const el = h('div', { class: 'field' },
        h('label', { id: `${id}-label` }, opts.label),
        withPreview ? h('div', { class: 'te-md' }, host, preview) : host,
        opts.hint ? h('div', { class: 'hint' }, opts.hint) : null,
        h('div', { class: 'te-error', role: 'alert', 'data-error-for': id }),
    );

    const editor = createCodeEditor({
        parent: host,
        doc: opts.value ?? '',
        language,
        lineWrapping: withPreview,
        comfort: { closeBrackets: !withPreview },
        onChange(value) {
            opts.onChange?.(value);
            render(value);
        },
    });
    render(opts.value ?? '');

    return {
        el,
        id,
        getValue: () => editor.getValue(),
        setValue(value) {
            editor.setValue(value);
        },
        setLanguage: (lang) => editor.setLanguage(lang),
        setReadOnly: (flag) => editor.setReadOnly(flag),
        /** Fügt Text an der Cursorposition ein (z. B. Materialverweis). */
        insertAtCursor(text) {
            const { from, to } = editor.view.state.selection.main;
            editor.view.dispatch({ changes: { from, to, insert: text }, selection: { anchor: from + text.length } });
            editor.focus();
        },
        focus: () => editor.focus(),
        destroy: () => editor.destroy(),
    };
}
