/**
 * Antwort einer Person schreibgeschützt anzeigen (Korrektur und Rückgabe).
 * Code und Freitext nutzen eigene Ansichten mit Kommentaren, alle anderen Typen das Antwort-Widget
 * des Typs (mode 'grading' bzw. 'review', readOnly).
 *
 *   const view = mountAnswer(container, { task, content, mode, comments, canComment, onComment, onBubble });
 *   view.setComments(list); view.focusComment(c); view.destroy();
 */
import { answerWidget, FallbackWidget } from '../lib/widgets.js';
import { h } from '../lib/dom.js';
import { mountCodeView } from './code-view.js';
import { mountTextView } from './text-view.js';

export function mountAnswer(container, options) {
    const { task, content, mode } = options;
    const host = h('div', { class: 'g-answer-host' });
    container.append(host);

    if (content === null || content === undefined) {
        host.append(h('div', { class: 'g-no-answer' }, 'Keine Antwort abgegeben.'));
        return { setComments() {}, focusComment() {}, destroy: () => host.remove() };
    }

    if (task.answer_type === 'code') {
        const view = mountCodeView(host, {
            task, content, comments: options.comments, canComment: options.canComment,
            onComment: options.onComment, onBubble: options.onBubble,
        });
        return { setComments: view.setComments, focusComment: view.focusComment, destroy: () => { view.destroy(); host.remove(); } };
    }

    if (task.answer_type === 'text') {
        const view = mountTextView(host, {
            text: typeof content.text === 'string' ? content.text : '',
            comments: options.comments, canComment: options.canComment,
            onComment: options.onComment, onMark: options.onBubble,
        });
        return { setComments: view.setComments, focusComment: view.focusComment, destroy: () => { view.destroy(); host.remove(); } };
    }

    const widget = answerWidget(task.answer_type) ?? FallbackWidget;
    const ctx = {
        task,
        content,
        readOnly: true,
        mode: mode === 'review' ? 'review' : 'grading',
        onChange() {},
        env: { examId: options.examId ?? null, settings: {}, runtime: null },
    };
    let instance = null;
    try {
        instance = widget.mount(host, ctx);
        instance?.setReadOnly?.(true);
    } catch (err) {
        console.error('Antwort konnte nicht dargestellt werden', err);
        host.replaceChildren();
        instance = FallbackWidget.mount(host, ctx);
    }

    return {
        setComments() {},
        focusComment() {},
        destroy() {
            try {
                instance?.destroy?.();
            } catch {
                // egal
            }
            host.remove();
        },
    };
}
