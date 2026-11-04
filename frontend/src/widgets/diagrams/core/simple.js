/**
 * Gemeinsame Bausteine der Widgets, die nicht auf dem SVG-Board beruhen
 * (Wahrheitstabelle, Relationenschema, Freihandzeichnung).
 */
import { debounce } from '../../../lib/dom.js';
import { History } from './history.js';
import { svgToPng } from './png.js';
import { s } from './svg.js';

/**
 * Entprellte Änderungsmeldung mit Rückgängig/Wiederholen.
 *
 * @param {{onChange?: Function}} ctx Widget-Kontext
 * @param {() => any} getContent liefert den aktuellen Inhalt (Kopie)
 * @param {any} initial Ausgangszustand für den Verlauf
 * @param {() => Promise<string|null>} [getPreviewPng]
 */
export function createTracker(ctx, getContent, initial, getPreviewPng) {
    const history = new History(initial);
    let dirty = false;
    let destroyed = false;
    let readOnly = !!ctx.readOnly;
    const fire = () => {
        if (destroyed || !dirty) {
            return;
        }
        dirty = false;
        if (!readOnly && typeof ctx.onChange === 'function') {
            ctx.onChange(getContent(), { kind: 'value', getPreviewPng });
        }
    };
    const debounced = debounce(fire, 300);

    return {
        history,
        setReadOnly(flag) {
            readOnly = !!flag;
        },
        /** Hält den neuen Zustand fest (falls geändert) und meldet entprellt. */
        record(model, key = null) {
            if (history.record(model, key)) {
                dirty = true;
                debounced();

                return true;
            }

            return false;
        },
        /** Meldet ohne Verlauf (nach Undo/Redo). */
        touch() {
            dirty = true;
            debounced();
        },
        destroy() {
            debounced.cancel?.();
            if (dirty && !readOnly) {
                dirty = false;
                if (typeof ctx.onChange === 'function') {
                    ctx.onChange(getContent(), { kind: 'value', getPreviewPng });
                }
            }
            destroyed = true;
        },
    };
}

/** Strg+Z / Strg+Y innerhalb eines Elements (nicht in Textfeldern). */
export function bindUndoKeys(el, undo, redo, { inInputs = false } = {}) {
    el.addEventListener('keydown', (e) => {
        const tag = e.target?.tagName?.toLowerCase?.();
        if (!inInputs && (tag === 'input' || tag === 'textarea' || tag === 'select')) {
            return;
        }
        const mod = e.ctrlKey || e.metaKey;
        if (!mod) {
            return;
        }
        const k = (e.key ?? '').toLowerCase();
        if (k === 'z' && !e.shiftKey) {
            e.preventDefault();
            undo();
        } else if (k === 'y' || (k === 'z' && e.shiftKey)) {
            e.preventDefault();
            redo();
        }
    });
}

/**
 * PNG aus Textzeilen mit Auszeichnung (für Tabellen und Schemata).
 * lines: [{segments: [{text, underline?, bold?}], gap?}]
 */
export async function textLinesPng(lines, { width = 640, size = 15, title = null } = {}) {
    const lh = size * 1.7;
    const height = Math.ceil((lines.length + (title ? 1 : 0)) * lh + 24);
    const svg = s('svg', { xmlns: 'http://www.w3.org/2000/svg', width, height, viewBox: `0 0 ${width} ${height}` }, s('rect', { x: 0, y: 0, width, height, fill: '#ffffff' }));
    let y = 12 + lh * 0.7;
    if (title) {
        svg.append(s('text', { x: 12, y, 'font-family': 'Arial, Helvetica, sans-serif', 'font-size': size, 'font-weight': 'bold', fill: '#111827' }, title));
        y += lh;
    }
    for (const l of lines) {
        const t = s('text', { x: 12, y, 'font-family': 'Arial, Helvetica, sans-serif', 'font-size': size, fill: '#111827', 'xml:space': 'preserve' });
        for (const seg of l.segments) {
            t.append(s('tspan', { 'text-decoration': seg.underline ? 'underline' : undefined, 'font-weight': seg.bold ? 'bold' : undefined }, seg.text));
        }
        svg.append(t);
        y += lh;
    }

    return svgToPng(svg, width, height);
}
