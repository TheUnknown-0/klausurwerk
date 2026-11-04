/**
 * SVG-Helfer für die Diagramm-Widgets. Alle Darstellungsattribute werden direkt am Element
 * gesetzt (nicht über CSS), damit der PNG-Export ohne Stylesheet genauso aussieht.
 * Die Diagrammfläche ist immer hell: weißes Papier, schwarze Linien (auch im dunklen Modus).
 */

const NS = 'http://www.w3.org/2000/svg';

export const INK = '#111827';
export const PAPER = '#ffffff';
export const SOFT = '#6b7280';
export const FONT_FAMILY = 'Arial, Helvetica, sans-serif';
export const FONT_SIZE = 13;

/**
 * Erzeugt ein SVG-Element.
 *   s('rect', {x: 1, class: 'a'}, child, 'Text')
 * Spezielle Attribute: class (String oder Liste), dataset ({a: 1} -> data-a), text (textContent).
 */
export function s(tag, attrs = {}, ...children) {
    const el = document.createElementNS(NS, tag);
    for (const [key, value] of Object.entries(attrs ?? {})) {
        if (value === undefined || value === null || value === false) {
            continue;
        }
        if (key === 'class') {
            el.setAttribute('class', Array.isArray(value) ? value.filter(Boolean).join(' ') : String(value));
        } else if (key === 'dataset') {
            for (const [k, v] of Object.entries(value)) {
                if (v !== undefined && v !== null) {
                    el.setAttribute(`data-${k.replace(/[A-Z]/g, (c) => `-${c.toLowerCase()}`)}`, String(v));
                }
            }
        } else if (key === 'text') {
            el.textContent = String(value);
        } else {
            el.setAttribute(key, value === true ? '' : String(value));
        }
    }
    for (const child of children.flat(Infinity)) {
        if (child === undefined || child === null || child === false) {
            continue;
        }
        el.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }

    return el;
}

/** Linie in Papierfarbe-Optik. */
export const line = (x1, y1, x2, y2, attrs = {}) =>
    s('line', { x1, y1, x2, y2, stroke: INK, 'stroke-width': 1.5, 'stroke-linecap': 'round', ...attrs });

/** Polygon aus Punkten {x, y}. */
export const polygon = (points, attrs = {}) =>
    s('polygon', { points: points.map((p) => `${p.x},${p.y}`).join(' '), fill: PAPER, stroke: INK, 'stroke-width': 1.5, 'stroke-linejoin': 'round', ...attrs });

export const path = (d, attrs = {}) => s('path', { d, fill: 'none', stroke: INK, 'stroke-width': 1.5, 'stroke-linejoin': 'round', 'stroke-linecap': 'round', ...attrs });

/**
 * (Mehrzeiliger) Text, vertikal um y zentriert.
 * opts: size, anchor (start|middle|end), weight, italic, underline, fill, lineHeight
 */
export function text(str, x, y, opts = {}) {
    const size = opts.size ?? FONT_SIZE;
    const lh = size * (opts.lineHeight ?? 1.3);
    const lines = String(str ?? '').split('\n');
    const first = y - ((lines.length - 1) * lh) / 2 + size * 0.35;
    const el = s('text', {
        x,
        y: first,
        'font-family': FONT_FAMILY,
        'font-size': size,
        'text-anchor': opts.anchor ?? 'middle',
        'font-weight': opts.weight,
        'font-style': opts.italic ? 'italic' : undefined,
        'text-decoration': opts.underline ? 'underline' : undefined,
        fill: opts.fill ?? INK,
        'pointer-events': opts.events ? undefined : 'none',
    });
    lines.forEach((l, i) => {
        el.append(s('tspan', { x, dy: i === 0 ? 0 : lh }, l === '' ? ' ' : l));
    });

    return el;
}

/** Unsichtbare, größere Trefferfläche (leichter zu treffen, vor allem auf Touchgeräten). */
export const hit = (x, y, w, h) => s('rect', { x, y, width: w, height: h, fill: 'transparent', stroke: 'none' });
