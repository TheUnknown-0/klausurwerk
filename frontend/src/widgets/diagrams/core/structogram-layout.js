/**
 * Layout eines Struktogramms (Breite und Höhe ergeben sich automatisch aus dem Text).
 * Rein, ohne DOM: liefert Zeichenanweisungen (prims), Blockrechtecke und Einfügepunkte.
 *
 *   const { width, height, prims, blocks, slots } = layoutStructogram(root, { minWidth: 260 });
 *
 * prims: {t:'rect', x, y, w, h} | {t:'line', x1, y1, x2, y2} | {t:'text', x, y, text, anchor, placeholder?}
 * blocks: Map id -> {x, y, w, h}; slots: [{key, index, x, y, w, h, label}]
 */
import { textBlock } from './geometry.js';
import { BLOCK_TYPES, sequencesOf } from './structogram-model.js';

export const FONT = 13;
export const LINE = 18;
export const PAD_X = 10;
export const PAD_Y = 7;
export const BAR = 24; // linker Balken der Schleifen
export const EMPTY_H = 28;
const EMPTY_W = 70;

const textOf = (block) => block[BLOCK_TYPES[block.type].field] ?? '';
const shown = (block) => {
    const t = textOf(block);

    return t.trim() === '' ? { text: BLOCK_TYPES[block.type].placeholder, placeholder: true } : { text: t, placeholder: false };
};
const tb = (block) => textBlock(shown(block).text, FONT, LINE / FONT);

/** Mindestmaße (w, h) einer Sequenz bzw. eines Blocks. */
export function measureSeq(seq) {
    if (seq.children.length === 0) {
        return { w: EMPTY_W, h: EMPTY_H };
    }
    let w = 0;
    let h = 0;
    for (const child of seq.children) {
        const m = measureBlock(child);
        w = Math.max(w, m.w);
        h += m.h;
    }

    return { w, h };
}

export function measureBlock(b) {
    const t = tb(b);
    switch (b.type) {
        case 'call':
            return { w: t.w + 2 * PAD_X + 24, h: Math.max(28, t.h + 2 * PAD_Y) };
        case 'break':
            return { w: t.w + 2 * PAD_X + 24, h: Math.max(28, t.h + 2 * PAD_Y) };
        case 'if': {
            const a = measureSeq(b.then);
            const e = measureSeq(b.else);

            return { w: Math.max(t.w + 90, a.w + e.w), h: ifHead(t) + Math.max(a.h, e.h), headH: ifHead(t) };
        }
        case 'switch': {
            const cols = b.cases.length > 0 ? b.cases : [{ label: '', body: { children: [] } }];
            const sizes = cols.map((c) => measureSeq(c.body));
            const widths = cols.map((c, i) => Math.max(sizes[i].w, textBlock(c.label, FONT).w + 20, 60));
            const head = switchHead(t);

            return {
                w: Math.max(t.w + 90, widths.reduce((a, c) => a + c, 0)),
                h: head + LABEL_H + Math.max(...sizes.map((s) => s.h)),
                headH: head,
                colMin: widths,
            };
        }
        case 'while':
        case 'for': {
            const body = measureSeq(b.body);
            const head = loopHead(t);

            return { w: Math.max(t.w + 2 * PAD_X, BAR + body.w), h: head + body.h, headH: head };
        }
        case 'do_while': {
            const body = measureSeq(b.body);
            const foot = loopHead(t);

            return { w: Math.max(t.w + 2 * PAD_X, BAR + body.w), h: body.h + foot, headH: foot };
        }
        default:
            return { w: t.w + 2 * PAD_X, h: Math.max(28, t.h + 2 * PAD_Y) };
    }
}

const LABEL_H = 24;
const ifHead = (t) => Math.max(48, t.h + 30);
const switchHead = (t) => Math.max(44, t.h + 26);
const loopHead = (t) => Math.max(30, t.h + 14);

/**
 * @param {{type: string, children: any[]}} root
 * @param {{minWidth?: number}} [opts]
 */
export function layoutStructogram(root, opts = {}) {
    const prims = [];
    const blocks = new Map();
    const slots = [];
    const m = measureSeq(root);
    const width = Math.max(opts.minWidth ?? 260, Math.ceil(m.w));

    const rect = (x, y, w, h) => prims.push({ t: 'rect', x, y, w, h });
    const line = (x1, y1, x2, y2) => prims.push({ t: 'line', x1, y1, x2, y2 });
    const text = (x, y, str, anchor = 'middle', extra = {}) => prims.push({ t: 'text', x, y, text: str, anchor, ...extra });

    /** Mehrzeiliger Text, vertikal in einem Bereich zentriert. */
    const textIn = (block, x, y, w, h, anchor = 'middle', pad = PAD_X) => {
        const { text: str, placeholder } = shown(block);
        const lines = str.split('\n');
        const total = lines.length * LINE;
        const top = y + (h - total) / 2;
        lines.forEach((l, i) => {
            const tx = anchor === 'middle' ? x + w / 2 : anchor === 'start' ? x + pad : x + w - pad;
            text(tx, top + i * LINE + LINE * 0.72, l, anchor, { placeholder });
        });
    };

    /** Sequenz in das Rechteck legen; extra = zusätzliche Höhe, die der letzte Block aufnimmt. */
    const seqLayout = (seq, key, x, y, w, h) => {
        if (seq.children.length === 0) {
            rect(x, y, w, h);
            slots.push({ key, index: 0, x, y, w, h, label: 'leer' });
            return;
        }
        let cy = y;
        const intrinsic = seq.children.reduce((a, c) => a + measureBlock(c).h, 0);
        const extra = Math.max(0, h - intrinsic);
        seq.children.forEach((child, i) => {
            slots.push({ key, index: i, x, y: cy - 4, w, h: 8, label: `vor Block ${i + 1}` });
            const isLast = i === seq.children.length - 1;
            const bh = measureBlock(child).h + (isLast ? extra : 0);
            blockLayout(child, x, cy, w, bh);
            cy += bh;
        });
        slots.push({ key, index: seq.children.length, x, y: cy - 4, w, h: 8, label: 'am Ende' });
    };

    const blockLayout = (b, x, y, w, h) => {
        blocks.set(b.id, { x, y, w, h });
        const t = tb(b);
        const mm = measureBlock(b);
        switch (b.type) {
            case 'call':
                rect(x, y, w, h);
                line(x + 10, y, x + 10, y + h);
                line(x + w - 10, y, x + w - 10, y + h);
                textIn(b, x + 10, y, w - 20, h);
                break;
            case 'break':
                rect(x, y, w, h);
                text(x + PAD_X, y + h / 2 + 5, '←', 'start');
                textIn(b, x + 22, y, w - 22, h);
                break;
            case 'if': {
                const head = mm.headH;
                const a = measureSeq(b.then);
                const e = measureSeq(b.else);
                const ratio = Math.max(0.28, Math.min(0.72, a.w / (a.w + e.w || 1)));
                const split = Math.round(w * ratio);
                rect(x, y, w, h);
                line(x, y, x + split, y + head);
                line(x + w, y, x + split, y + head);
                line(x, y + head, x + w, y + head);
                text(x + w / 2, y + 17, shown(b).text.split('\n')[0], 'middle', { placeholder: shown(b).placeholder });
                shown(b).text.split('\n').slice(1).forEach((l, i) => text(x + w / 2, y + 17 + (i + 1) * LINE, l, 'middle', { placeholder: shown(b).placeholder }));
                text(x + 8, y + head - 7, 'Ja', 'start', { small: true });
                text(x + w - 8, y + head - 7, 'Nein', 'end', { small: true });
                seqLayout(b.then, `${b.id}/then`, x, y + head, split, h - head);
                seqLayout(b.else, `${b.id}/else`, x + split, y + head, w - split, h - head);
                break;
            }
            case 'switch': {
                const head = mm.headH;
                const cases = b.cases.length > 0 ? b.cases : [null];
                const mins = mm.colMin;
                const sum = mins.reduce((acc, c) => acc + c, 0);
                const widths = mins.map((c) => Math.floor((c / sum) * w));
                widths[widths.length - 1] += w - widths.reduce((acc, c) => acc + c, 0);
                rect(x, y, w, h);
                line(x, y, x + w / 2, y + head);
                line(x + w, y, x + w / 2, y + head);
                line(x, y + head, x + w, y + head);
                text(x + w / 2, y + 17, shown(b).text.split('\n')[0], 'middle', { placeholder: shown(b).placeholder });
                line(x, y + head + LABEL_H, x + w, y + head + LABEL_H);
                let cx = x;
                cases.forEach((c, i) => {
                    if (i > 0) {
                        line(cx, y + head, cx, y + h);
                    }
                    if (c) {
                        text(cx + widths[i] / 2, y + head + 16, c.label === '' ? '?' : c.label, 'middle', { placeholder: c.label === '' });
                        seqLayout(c.body, `${b.id}/case/${i}`, cx, y + head + LABEL_H, widths[i], h - head - LABEL_H);
                    }
                    cx += widths[i];
                });
                break;
            }
            case 'while':
            case 'for': {
                const head = mm.headH;
                rect(x, y, w, h);
                line(x, y + head, x + w, y + head);
                textIn(b, x, y, w, head, 'start');
                seqLayout(b.body, `${b.id}/body`, x + BAR, y + head, w - BAR, h - head);
                line(x + BAR, y + head, x + BAR, y + h);
                break;
            }
            case 'do_while': {
                const foot = mm.headH;
                rect(x, y, w, h);
                line(x, y + h - foot, x + w, y + h - foot);
                seqLayout(b.body, `${b.id}/body`, x + BAR, y, w - BAR, h - foot);
                line(x + BAR, y, x + BAR, y + h - foot);
                textIn(b, x, y + h - foot, w, foot, 'start');
                break;
            }
            default:
                rect(x, y, w, h);
                textIn(b, x, y, w, h);
        }
        void t;
    };

    const height = Math.max(m.h, EMPTY_H);
    seqLayout(root, 'root', 0, 0, width, height);

    return { width, height, prims, blocks, slots };
}

/** Für Tests und PDF: Block mit Kinder-Kennungen, die Sequenzen sind vollständig enthalten. */
export function sequenceKeys(block) {
    return sequencesOf(block).map(([part]) => `${block.id}/${part}`);
}
