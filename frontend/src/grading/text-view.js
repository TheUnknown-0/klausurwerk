/**
 * Freitext in der Korrektur und in der Rückgabe: Text mit hervorgehobenen Kommentarstellen und
 * Korrekturzeichen am Rand.
 * Korrektur: Textstelle mit der Maus markieren, dann "Auswahl kommentieren" oder ein Korrekturzeichen
 * (Knopf, Alt+Ziffer oder Ziffer bei fokussiertem Text), optional mit Kommentar.
 *
 *   const view = mountTextView(container, {
 *       text, comments, canComment, marks,        // marks: [{code, name, counts, weight}] des aktiven Satzes
 *       onComment(anchor), onSetMark({ anchor, body }), onMark(ids),
 *   });
 *   view.setComments(list); view.focusComment(comment); view.destroy();
 */
import { h, icon } from '../lib/dom.js';
import { countWords, marginLines, textSegments } from './logic.js';

export function mountTextView(container, options) {
    const text = String(options.text ?? '');
    const marks = options.marks ?? [];
    const markByCode = new Map(marks.map((m) => [m.code, m]));
    let comments = options.comments ?? [];
    let pending = null; // { from, to, quote }
    let lines = marginLines(text, comments);

    const body = h('div', { class: 'g-textview', tabindex: '0', role: 'document', 'aria-label': 'Antwort der Person' });
    const button = h('button', {
        type: 'button', class: 'btn btn-sm btn-primary', disabled: true,
        onclick: () => {
            if (pending) {
                options.onComment?.({ kind: 'text', from: pending.from, to: pending.to, quote: pending.quote });
            }
        },
    }, icon('message-square'), ' Auswahl kommentieren');

    const markNote = h('input', {
        type: 'text', class: 'input g-marknote', maxlength: 200, placeholder: 'Kommentar zum Zeichen (optional)',
        'aria-label': 'Kommentar zum Korrekturzeichen (optional)',
    });
    const markButtons = marks.map((m, i) => {
        const key = i < 10 ? String((i + 1) % 10) : null;
        return h('button', {
            type: 'button', class: 'btn btn-sm g-markbtn', disabled: true, dataset: { code: m.code },
            title: `${m.name}${m.weight !== 1 ? ` (zählt ${String(m.weight).replace('.', ',')}-fach)` : ''}${key ? `, Alt+${key}` : ''}`,
            'aria-label': `Korrekturzeichen ${m.code}: ${m.name}`,
            onclick: () => setMark(m),
        }, m.code);
    });
    const markBar = options.canComment && marks.length > 0
        ? h('div', { class: 'g-markbar', role: 'group', 'aria-label': 'Korrekturzeichen' }, ...markButtons, markNote)
        : null;

    const toolbar = options.canComment
        ? h('div', { class: 'g-code-toolbar' }, button, h('span', { class: 'text-faint text-sm' },
            marks.length > 0 ? 'Markiere eine Textstelle, dann Kommentar oder Korrekturzeichen (Alt+Ziffer).' : 'Markiere eine Textstelle mit der Maus.'))
        : null;
    const words = countWords(text);
    const meta = h('div', { class: 'text-faint text-sm' }, `${words} ${words === 1 ? 'Wort' : 'Wörter'}, ${text.length} Zeichen`);
    const root = h('div', { class: 'g-textwrap' }, toolbar, markBar, body, meta);
    container.append(root);

    function commentById(id) {
        return comments.find((c) => c.id === id);
    }

    function segmentElement(seg) {
        if (seg.ids.length === 0) {
            return document.createTextNode(seg.text);
        }
        const signs = seg.ids.map((id) => commentById(id)?.anchor?.mark).filter((code) => code && markByCode.has(code));
        const label = signs.length > 0
            ? signs.map((code) => `${code} (${markByCode.get(code).name})`).join(', ')
            : (seg.ids.length > 1 ? `${seg.ids.length} Kommentare` : 'Kommentar');
        return h('mark', {
            class: signs.length > 0 ? 'g-mark g-mark-sign' : 'g-mark', dataset: { ids: seg.ids.join(',') }, tabindex: '0',
            title: label,
            onclick: () => options.onMark?.(seg.ids),
            onkeydown: (ev) => {
                if (ev.key === 'Enter') {
                    options.onMark?.(seg.ids);
                }
            },
        }, seg.text);
    }

    function marginElement(line) {
        const cell = h('div', { class: 'g-margin' });
        for (const m of line.marks) {
            const def = markByCode.get(m.code);
            if (!def) {
                continue;
            }
            const c = commentById(m.id);
            const note = c?.body ? `: ${c.body}` : '';
            cell.append(h('button', {
                type: 'button', class: 'g-margin-mark', dataset: { id: m.id },
                title: `${def.name}${note}`, 'aria-label': `Korrekturzeichen ${m.code}, ${def.name}${note}`,
                onclick: () => options.onMark?.([m.id]),
            }, m.code));
        }
        return cell;
    }

    function render() {
        body.replaceChildren();
        if (text === '') {
            body.append(h('span', { class: 'text-faint' }, '(leer)'));
            return;
        }
        lines = marginLines(text, comments);
        const rows = lines.map((line, i) => h('div', { class: 'g-line', dataset: { line: i } },
            h('div', { class: 'g-line-text' }), marginElement(line)));
        let li = 0;
        for (const seg of textSegments(text, comments)) {
            seg.text.split('\n').forEach((piece, k) => {
                if (k > 0) {
                    li += 1; // der Zeilenumbruch selbst gehört zu keiner Zeile
                }
                if (piece !== '') {
                    rows[li].firstChild.append(segmentElement({ text: piece, ids: seg.ids }));
                }
            });
        }
        body.append(...rows);
    }

    /** Zeichenposition eines Knotens/Offsets relativ zum Textanfang; null, wenn außerhalb des Textes. */
    function offsetOf(node, offset) {
        if (node === body) {
            const row = body.children[offset];
            return row ? lines[Number(row.dataset.line)].from : text.length;
        }
        const el = node.nodeType === 3 ? node.parentElement : node;
        const row = el?.closest?.('.g-line');
        if (!row || !body.contains(row)) {
            return null;
        }
        const line = lines[Number(row.dataset.line)];
        const textEl = row.firstChild;
        if (!textEl.contains(node)) {
            return node === row && offset === 0 ? line.from : line.to;
        }
        const range = document.createRange();
        range.selectNodeContents(textEl);
        range.setEnd(node, offset);
        return line.from + range.toString().length;
    }

    function setPending(next) {
        pending = next;
        button.disabled = pending === null;
        for (const b of markButtons) {
            b.disabled = pending === null;
        }
        if (pending === null) {
            button.replaceChildren(icon('message-square'), ' Auswahl kommentieren');
        } else {
            button.replaceChildren(icon('message-square'), ` „${pending.quote.length > 30 ? `${pending.quote.slice(0, 30)}…` : pending.quote}“ kommentieren`);
        }
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
        if (from === null || to === null) {
            return;
        }
        // Leerraum am Rand der Auswahl nicht mitmarkieren
        while (from < to && /\s/.test(text[from])) from++;
        while (to > from && /\s/.test(text[to - 1])) to--;
        if (to <= from) {
            return;
        }
        setPending({ from, to, quote: text.slice(from, to).replace(/\s+/g, ' ').slice(0, 300) });
    }

    async function setMark(def) {
        if (!pending || !def) {
            return;
        }
        const anchor = { kind: 'text', from: pending.from, to: pending.to, quote: pending.quote, mark: def.code };
        const note = markNote.value.trim();
        setPending(null);
        window.getSelection()?.removeAllRanges();
        const saved = await options.onSetMark?.({ anchor, body: note });
        if (saved !== false) {
            markNote.value = '';
        }
    }

    body.addEventListener('mouseup', () => setTimeout(readSelection, 0));
    body.addEventListener('keyup', (ev) => {
        if (ev.shiftKey || ev.key.startsWith('Arrow')) {
            readSelection();
        }
    });
    if (options.canComment && marks.length > 0) {
        // Alt+Ziffer überall im Textbereich, die Ziffer allein nur bei fokussiertem Text (nicht im Kommentarfeld).
        root.addEventListener('keydown', (ev) => {
            const m = /^Digit(\d)$/.exec(ev.code);
            if (!m || ev.ctrlKey || ev.metaKey || (!ev.altKey && ev.target !== body)) {
                return;
            }
            const index = (Number(m[1]) + 9) % 10;
            if (index < marks.length && pending) {
                ev.preventDefault();
                setMark(marks[index]);
            }
        });
    }

    render();

    return {
        setComments(next) {
            comments = next ?? [];
            render();
        },
        focusComment(comment) {
            const target = [...body.querySelectorAll('mark, .g-margin-mark')].find((m) => (m.dataset.ids ?? m.dataset.id ?? '').split(',').includes(String(comment.id)));
            if (target) {
                target.scrollIntoView({ block: 'center', behavior: 'smooth' });
                target.classList.add('is-flash');
                setTimeout(() => target.classList.remove('is-flash'), 1200);
            }
        },
        destroy() {
            root.remove();
        },
    };
}
