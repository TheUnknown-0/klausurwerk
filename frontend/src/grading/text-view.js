/**
 * Freitext in der Korrektur und in der Rückgabe: Text mit hervorgehobenen Kommentarstellen.
 * Korrektur: Textstelle mit der Maus markieren, dann "Auswahl kommentieren".
 *
 *   const view = mountTextView(container, { text, comments, canComment, onComment(anchor), onMark(ids) });
 *   view.setComments(list); view.focusComment(comment); view.destroy();
 */
import { h } from '../lib/dom.js';
import { textSegments } from './logic.js';

export function mountTextView(container, options) {
    const text = String(options.text ?? '');
    let comments = options.comments ?? [];
    let pending = null; // { from, to, quote }

    const body = h('div', { class: 'g-textview', tabindex: '0', role: 'document', 'aria-label': 'Antwort der Person' });
    const button = h('button', {
        type: 'button', class: 'btn btn-sm btn-primary', disabled: true,
        onclick: () => {
            if (pending) {
                options.onComment?.({ kind: 'text', from: pending.from, to: pending.to, quote: pending.quote });
            }
        },
    }, '💬 Auswahl kommentieren');
    const toolbar = options.canComment
        ? h('div', { class: 'g-code-toolbar' }, button, h('span', { class: 'text-faint text-sm' }, 'Markiere eine Textstelle mit der Maus.'))
        : null;
    const words = text.trim() === '' ? 0 : text.trim().split(/\s+/).length;
    const meta = h('div', { class: 'text-faint text-sm' }, `${words} ${words === 1 ? 'Wort' : 'Wörter'}, ${text.length} Zeichen`);
    const root = h('div', { class: 'g-textwrap' }, toolbar, body, meta);
    container.append(root);

    function render() {
        body.replaceChildren();
        if (text === '') {
            body.append(h('span', { class: 'text-faint' }, '(leer)'));
            return;
        }
        for (const seg of textSegments(text, comments)) {
            if (seg.ids.length === 0) {
                body.append(document.createTextNode(seg.text));
            } else {
                body.append(h('mark', {
                    class: 'g-mark', dataset: { ids: seg.ids.join(',') }, tabindex: '0',
                    title: seg.ids.length > 1 ? `${seg.ids.length} Kommentare` : 'Kommentar',
                    onclick: () => options.onMark?.(seg.ids),
                    onkeydown: (ev) => {
                        if (ev.key === 'Enter') {
                            options.onMark?.(seg.ids);
                        }
                    },
                }, seg.text));
            }
        }
    }

    /** Zeichenposition eines Knotens/Offsets relativ zum Textanfang. */
    function offsetOf(node, offset) {
        const range = document.createRange();
        range.selectNodeContents(body);
        range.setEnd(node, offset);
        return range.toString().length;
    }

    function readSelection() {
        if (!options.canComment) {
            return;
        }
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0 || sel.isCollapsed || !body.contains(sel.anchorNode) || !body.contains(sel.focusNode)) {
            return;
        }
        const range = sel.getRangeAt(0);
        let from = offsetOf(range.startContainer, range.startOffset);
        let to = offsetOf(range.endContainer, range.endOffset);
        // Leerraum am Rand der Auswahl nicht mitmarkieren
        while (from < to && /\s/.test(text[from])) from++;
        while (to > from && /\s/.test(text[to - 1])) to--;
        if (to <= from) {
            return;
        }
        pending = { from, to, quote: text.slice(from, to).replace(/\s+/g, ' ').slice(0, 300) };
        button.disabled = false;
        button.textContent = `💬 „${pending.quote.length > 30 ? `${pending.quote.slice(0, 30)}…` : pending.quote}“ kommentieren`;
    }

    body.addEventListener('mouseup', () => setTimeout(readSelection, 0));
    body.addEventListener('keyup', (ev) => {
        if (ev.shiftKey || ev.key.startsWith('Arrow')) {
            readSelection();
        }
    });

    render();

    return {
        setComments(next) {
            comments = next ?? [];
            render();
        },
        focusComment(comment) {
            const mark = [...body.querySelectorAll('mark')].find((m) => (m.dataset.ids ?? '').split(',').includes(String(comment.id)));
            if (mark) {
                mark.scrollIntoView({ block: 'center', behavior: 'smooth' });
                mark.classList.add('is-flash');
                setTimeout(() => mark.classList.remove('is-flash'), 1200);
            }
        },
        destroy() {
            root.remove();
        },
    };
}
