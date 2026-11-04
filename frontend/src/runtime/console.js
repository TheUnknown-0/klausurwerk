/**
 * Konsole: zeilenbegrenzter Ausgabepuffer (reine Logik) und die Darstellung
 * mit gebündeltem Zeichnen (requestAnimationFrame) und Eingabezeile.
 */
import { h } from '../lib/dom.js';

export const MAX_LINES = 2000;
export const MAX_LINE_LENGTH = 20000;

/**
 * Puffer aus Zeilen. Jede Zeile besteht aus Abschnitten gleicher Herkunft
 * (stdout, stderr, stdin, system). Die letzte Zeile ist die offene Zeile
 * (z. B. eine Eingabeaufforderung ohne Zeilenumbruch).
 */
export class LineBuffer {
    constructor({ maxLines = MAX_LINES, maxLineLength = MAX_LINE_LENGTH } = {}) {
        this.maxLines = maxLines;
        this.maxLineLength = maxLineLength;
        this.clear();
    }

    clear() {
        this.lines = [this._newLine()];
        this.dropped = 0;
    }

    _newLine() {
        return { segments: [], length: 0, clipped: false };
    }

    /**
     * Hängt Text an. Gibt zurück, wie viele Zeilen am Anfang entfernt wurden
     * (damit eine Darstellung ihre Knoten abgleichen kann).
     */
    append(text, stream = 'stdout') {
        const parts = String(text ?? '').split(/(\r\n|\n|\r)/);
        let removed = 0;
        for (const part of parts) {
            if (part === '') {
                continue;
            }
            const line = this.lines[this.lines.length - 1];
            if (part === '\n' || part === '\r\n') {
                this.lines.push(this._newLine());
                if (this.lines.length > this.maxLines) {
                    this.lines.shift();
                    removed++;
                    this.dropped++;
                }
            } else if (part === '\r') {
                // Wagenrücklauf: Zeile wird überschrieben
                line.segments = [];
                line.length = 0;
                line.clipped = false;
            } else {
                this._add(line, part, stream);
            }
        }
        return removed;
    }

    _add(line, text, stream) {
        const room = this.maxLineLength - line.length;
        if (room <= 0) {
            line.clipped = true;
            return;
        }
        let chunk = text;
        if (chunk.length > room) {
            chunk = chunk.slice(0, room);
            line.clipped = true;
        }
        const last = line.segments[line.segments.length - 1];
        if (last && last.stream === stream) {
            last.text += chunk;
        } else {
            line.segments.push({ text: chunk, stream });
        }
        line.length += chunk.length;
    }

    /** Text der offenen Zeile (Eingabeaufforderung). */
    currentLineText() {
        return this.lines[this.lines.length - 1].segments.map((s) => s.text).join('');
    }

    toText() {
        return this.lines.map((l) => l.segments.map((s) => s.text).join('')).join('\n');
    }
}

/**
 * @param {HTMLElement} parent
 * @param {{ onSubmit?: (text: string) => void, maxLines?: number }} [opts]
 */
export function createConsole(parent, opts = {}) {
    const buffer = new LineBuffer({ maxLines: opts.maxLines ?? MAX_LINES });
    const linesEl = h('div', { class: 'cw-console-lines' });
    const noteEl = h('div', { class: 'cw-console-note', hidden: true });
    const scroll = h('div', {
        class: 'cw-console-scroll', role: 'log', 'aria-label': 'Konsole', 'aria-live': 'off', tabindex: '0',
    }, noteEl, linesEl);
    const input = h('input', {
        class: 'cw-console-field', type: 'text', id: `cw-in-${Math.random().toString(36).slice(2, 8)}`,
        autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false', enterkeyhint: 'send',
        placeholder: 'Eingabe, dann Enter',
    });
    const sendBtn = h('button', { class: 'btn btn-sm btn-accent', type: 'submit' }, 'Senden');
    const form = h('form', { class: 'cw-console-input', hidden: true },
        h('label', { class: 'cw-console-label', for: input.id }, 'Eingabe'),
        input, sendBtn);
    const root = h('div', { class: 'cw-console' }, scroll, form);
    parent.append(root);

    let pending = [];
    let scheduled = false;
    let domCount = 0;
    let destroyed = false;
    const history = [];
    let historyPos = -1;

    function renderLine(index) {
        const line = buffer.lines[index];
        const el = h('div', { class: 'cw-line' });
        for (const seg of line.segments) {
            el.append(h('span', { class: `cw-s cw-s-${seg.stream}` }, seg.text));
        }
        if (line.clipped) {
            el.append(h('span', { class: 'cw-s cw-s-system' }, ' [Zeile gekürzt]'));
        }
        return el;
    }

    function flush() {
        scheduled = false;
        if (destroyed || pending.length === 0) {
            return;
        }
        const nearBottom = scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 40;
        let removed = 0;
        for (const { text, stream } of pending) {
            removed += buffer.append(text, stream);
        }
        pending = [];
        // Knoten am Anfang entfernen, die der Puffer verworfen hat
        for (let i = 0; i < removed && linesEl.firstChild; i++) {
            linesEl.firstChild.remove();
            domCount--;
        }
        domCount = Math.max(0, Math.min(domCount, linesEl.childNodes.length));
        // die zuletzt gezeichnete Zeile kann sich verlängert haben
        const start = Math.max(0, domCount - 1);
        while (linesEl.childNodes.length > start) {
            linesEl.lastChild.remove();
        }
        for (let i = start; i < buffer.lines.length; i++) {
            linesEl.append(renderLine(i));
        }
        domCount = buffer.lines.length;
        if (buffer.dropped > 0) {
            noteEl.hidden = false;
            noteEl.textContent = `Ältere Ausgabe wurde gekürzt (${buffer.dropped} Zeilen). Die Konsole zeigt die letzten ${buffer.maxLines} Zeilen.`;
        }
        if (nearBottom) {
            scroll.scrollTop = scroll.scrollHeight;
        }
    }

    function schedule() {
        if (scheduled || destroyed) {
            return;
        }
        scheduled = true;
        if (typeof requestAnimationFrame === 'function') {
            requestAnimationFrame(flush);
        }
        // Reserve für Tabs im Hintergrund, in denen requestAnimationFrame pausiert
        setTimeout(flush, 150);
    }

    form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const text = input.value;
        input.value = '';
        historyPos = -1;
        if (text !== '') {
            history.unshift(text);
            history.length = Math.min(history.length, 50);
        }
        api.write(`${text}\n`, 'stdin');
        opts.onSubmit?.(text);
        input.focus();
    });
    input.addEventListener('keydown', (ev) => {
        if (ev.key === 'ArrowUp' && history.length > 0) {
            ev.preventDefault();
            historyPos = Math.min(historyPos + 1, history.length - 1);
            input.value = history[historyPos];
        } else if (ev.key === 'ArrowDown') {
            ev.preventDefault();
            historyPos = Math.max(historyPos - 1, -1);
            input.value = historyPos >= 0 ? history[historyPos] : '';
        }
    });

    const api = {
        element: root,
        buffer,
        /** Text anhängen; wird gebündelt gezeichnet. */
        write(text, stream = 'stdout') {
            if (text) {
                pending.push({ text, stream });
                schedule();
            }
        },
        /** Sofort zeichnen (z. B. vor dem Lesen des Puffers). */
        flush,
        clear() {
            pending = [];
            buffer.clear();
            linesEl.replaceChildren();
            domCount = 0;
            noteEl.hidden = true;
            noteEl.textContent = '';
        },
        /** Eingabezeile ein- oder ausblenden. */
        setInputVisible(flag) {
            form.hidden = !flag;
            if (!flag) {
                root.classList.remove('is-waiting');
            }
        },
        /** Das Programm wartet gerade auf eine Eingabe (hebt die Zeile hervor). */
        setWaiting(flag) {
            root.classList.toggle('is-waiting', !!flag);
            if (flag) {
                flush();
                if (!form.hidden) {
                    input.focus();
                }
            }
        },
        setInputDisabled(flag) {
            input.disabled = !!flag;
            sendBtn.disabled = !!flag;
        },
        focusInput() {
            input.focus();
        },
        isEmpty: () => buffer.lines.length === 1 && buffer.lines[0].segments.length === 0 && pending.length === 0,
        destroy() {
            destroyed = true;
            root.remove();
        },
    };
    return api;
}
