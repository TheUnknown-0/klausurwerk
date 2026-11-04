/**
 * Reine Geometrie für die Diagramm-Widgets (ohne DOM, direkt in Node testbar).
 * Punkte sind {x, y}; Rechtecke {x, y, w, h} (x, y = linke obere Ecke).
 */

export const GRID = 10;

export const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
export const snap = (v, grid = GRID) => (grid > 0 ? Math.round(v / grid) * grid + 0 : v);
export const round1 = (v) => Math.round(v * 10) / 10;

export const center = (r) => ({ x: r.x + r.w / 2, y: r.y + r.h / 2 });
export const right = (r) => r.x + r.w;
export const bottom = (r) => r.y + r.h;

/** Ungefähre Textbreite in Pixeln (deterministisch, ohne Messung im Browser). */
export function textWidth(text, size = 13) {
    let w = 0;
    for (const ch of String(text ?? '')) {
        if ('il.,;:\'|!jI'.includes(ch)) {
            w += 0.32;
        } else if ('mwMW@%'.includes(ch)) {
            w += 0.88;
        } else if (/[A-ZÄÖÜ]/.test(ch)) {
            w += 0.68;
        } else if (ch === ' ') {
            w += 0.3;
        } else {
            w += 0.55;
        }
    }
    return w * size;
}

/** Größe eines mehrzeiligen Textes. */
export function textBlock(text, size = 13, lineHeight = 1.35) {
    const lines = String(text ?? '').split('\n');
    return {
        lines,
        w: Math.max(0, ...lines.map((l) => textWidth(l, size))),
        h: lines.length * size * lineHeight,
        lineHeight: size * lineHeight,
    };
}

/** Kleinste unbenutzte Kennung prefix1, prefix2, ... */
export function newId(prefix, used) {
    const set = used instanceof Set ? used : new Set(used);
    let n = 1;
    while (set.has(`${prefix}${n}`)) {
        n += 1;
    }
    return `${prefix}${n}`;
}

// ---------------------------------------------------------------------------
// Freihandlinien
// ---------------------------------------------------------------------------

/** Abstand eines Punktes von der Strecke a-b. */
export function distToSegment(p, a, b) {
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const len2 = dx * dx + dy * dy;
    if (len2 === 0) {
        return Math.hypot(p.x - a.x, p.y - a.y);
    }
    const t = clamp(((p.x - a.x) * dx + (p.y - a.y) * dy) / len2, 0, 1);
    return Math.hypot(p.x - (a.x + t * dx), p.y - (a.y + t * dy));
}

/**
 * Douglas-Peucker (iterativ, kein Rekursionslimit) für Punkte [[x, y], ...].
 * Erster und letzter Punkt bleiben erhalten; aufeinanderfolgende Duplikate entfallen.
 */
export function simplifyPoints(points, epsilon = 1.5) {
    const pts = [];
    for (const p of points) {
        const last = pts[pts.length - 1];
        if (!last || last[0] !== p[0] || last[1] !== p[1]) {
            pts.push(p);
        }
    }
    if (pts.length <= 2) {
        return pts;
    }
    const keep = new Uint8Array(pts.length);
    keep[0] = 1;
    keep[pts.length - 1] = 1;
    const stack = [[0, pts.length - 1]];
    while (stack.length > 0) {
        const [lo, hi] = stack.pop();
        let maxD = 0;
        let idx = -1;
        const a = { x: pts[lo][0], y: pts[lo][1] };
        const b = { x: pts[hi][0], y: pts[hi][1] };
        for (let i = lo + 1; i < hi; i++) {
            const d = distToSegment({ x: pts[i][0], y: pts[i][1] }, a, b);
            if (d > maxD) {
                maxD = d;
                idx = i;
            }
        }
        if (idx !== -1 && maxD > epsilon) {
            keep[idx] = 1;
            stack.push([lo, idx], [idx, hi]);
        }
    }
    return pts.filter((_, i) => keep[i] === 1);
}

/** Rundet Punkte auf eine Nachkommastelle und begrenzt sie auf die Fläche. */
export function clampPoints(points, width, height) {
    return points.map(([x, y]) => [round1(clamp(x, 0, width)), round1(clamp(y, 0, height))]);
}

/** SVG-Pfad (Linienzug) aus Punkten [[x, y], ...]; ein einzelner Punkt wird zum Punktchen. */
export function pointsToPath(points) {
    if (points.length === 0) {
        return '';
    }
    if (points.length === 1) {
        const [x, y] = points[0];
        return `M${x} ${y}l0.01 0`;
    }
    // Glättung über Mittelpunkte (quadratische Kurven), wirkt wie echte Tinte.
    let d = `M${points[0][0]} ${points[0][1]}`;
    for (let i = 1; i < points.length - 1; i++) {
        const mx = round1((points[i][0] + points[i + 1][0]) / 2);
        const my = round1((points[i][1] + points[i + 1][1]) / 2);
        d += `Q${points[i][0]} ${points[i][1]} ${mx} ${my}`;
    }
    const last = points[points.length - 1];
    d += `L${last[0]} ${last[1]}`;
    return d;
}

/** Kürzester Abstand eines Punktes zu einem Linienzug [[x, y], ...]. */
export function distToPolyline(p, points) {
    if (points.length === 0) {
        return Infinity;
    }
    if (points.length === 1) {
        return Math.hypot(p.x - points[0][0], p.y - points[0][1]);
    }
    let best = Infinity;
    for (let i = 1; i < points.length; i++) {
        best = Math.min(best, distToSegment(p, { x: points[i - 1][0], y: points[i - 1][1] }, { x: points[i][0], y: points[i][1] }));
    }
    return best;
}

// ---------------------------------------------------------------------------
// Flächen und Ränder
// ---------------------------------------------------------------------------

/** Umgebendes Rechteck mehrerer Rechtecke; null bei leerer Liste. */
export function bbox(rects) {
    const list = rects.filter(Boolean);
    if (list.length === 0) {
        return null;
    }
    const x1 = Math.min(...list.map((r) => r.x));
    const y1 = Math.min(...list.map((r) => r.y));
    const x2 = Math.max(...list.map((r) => r.x + r.w));
    const y2 = Math.max(...list.map((r) => r.y + r.h));
    return { x: x1, y: y1, w: x2 - x1, h: y2 - y1 };
}

export const rectsOverlap = (a, b, pad = 0) =>
    a.x < b.x + b.w + pad && b.x < a.x + a.w + pad && a.y < b.y + b.h + pad && b.y < a.y + a.h + pad;

/** Erste freie Stelle (Raster) für ein Element der Größe size nahe start. */
export function freePosition(rects, size, start = { x: 40, y: 40 }, step = 30, pad = 12) {
    for (let ring = 0; ring < 60; ring++) {
        for (let i = 0; i <= ring; i++) {
            for (const [dx, dy] of ring === 0 ? [[0, 0]] : [[i, ring], [ring, i], [-i, ring], [ring, -i]]) {
                const cand = { x: snap(start.x + dx * step), y: snap(start.y + dy * step), w: size.w, h: size.h };
                if (!rects.some((r) => rectsOverlap(cand, r, pad))) {
                    return { x: cand.x, y: cand.y };
                }
            }
        }
    }
    return { x: snap(start.x), y: snap(start.y) };
}

/** Schnittpunkt der Geraden Mitte -> p mit dem Rechteckrand. */
export function rectBorderPoint(r, p) {
    const c = center(r);
    const dx = p.x - c.x;
    const dy = p.y - c.y;
    if (dx === 0 && dy === 0) {
        return { x: c.x, y: r.y };
    }
    const sx = dx !== 0 ? r.w / 2 / Math.abs(dx) : Infinity;
    const sy = dy !== 0 ? r.h / 2 / Math.abs(dy) : Infinity;
    const s = Math.min(sx, sy);
    return { x: c.x + dx * s, y: c.y + dy * s };
}

/** Schnittpunkt der Geraden Mitte -> p mit einer Ellipse (Mitte c, Halbachsen rx, ry). */
export function ellipseBorderPoint(c, rx, ry, p) {
    const dx = p.x - c.x;
    const dy = p.y - c.y;
    if (dx === 0 && dy === 0) {
        return { x: c.x, y: c.y - ry };
    }
    const s = 1 / Math.sqrt((dx * dx) / (rx * rx) + (dy * dy) / (ry * ry));
    return { x: c.x + dx * s, y: c.y + dy * s };
}

/** Schnittpunkt mit einer Raute (Mitte c, halbe Breite hw, halbe Höhe hh). */
export function diamondBorderPoint(c, hw, hh, p) {
    const dx = p.x - c.x;
    const dy = p.y - c.y;
    if (dx === 0 && dy === 0) {
        return { x: c.x, y: c.y - hh };
    }
    const s = 1 / (Math.abs(dx) / hw + Math.abs(dy) / hh);
    return { x: c.x + dx * s, y: c.y + dy * s };
}

// ---------------------------------------------------------------------------
// Kantenführung
// ---------------------------------------------------------------------------

/**
 * Rechtwinklige Verbindung zwischen zwei Rechtecken, andockend an den Seitenmitten
 * (bzw. an einer gemeinsamen Linie, wenn sich die Rechtecke überlappen).
 *
 * @param {{x:number,y:number,w:number,h:number}} a
 * @param {{x:number,y:number,w:number,h:number}} b
 * @param {{up?: 'direct' | 'around', gap?: number}} [opts]
 *   up = 'around': Rücksprünge nach oben laufen seitlich um die Kästen herum (Schleifen im PAP).
 * @returns {{x:number,y:number}[]}
 */
export function routeOrthogonal(a, b, opts = {}) {
    const gap = opts.gap ?? 28;
    const ac = center(a);
    const bc = center(b);
    const xLo = Math.max(a.x, b.x);
    const xHi = Math.min(right(a), right(b));
    const yLo = Math.max(a.y, b.y);
    const yHi = Math.min(bottom(a), bottom(b));
    const hOverlap = xHi - xLo > 8;
    const vOverlap = yHi - yLo > 8;

    if (b.y >= bottom(a)) {
        // b liegt unterhalb von a
        if (hOverlap) {
            const x = (xLo + xHi) / 2;
            return [{ x, y: bottom(a) }, { x, y: b.y }];
        }
        const midY = (bottom(a) + b.y) / 2;
        return [{ x: ac.x, y: bottom(a) }, { x: ac.x, y: midY }, { x: bc.x, y: midY }, { x: bc.x, y: b.y }];
    }
    if (bottom(b) <= a.y) {
        // b liegt oberhalb von a
        if (opts.up === 'around' && hOverlap) {
            const x = Math.max(right(a), right(b)) + gap;
            return [{ x: right(a), y: ac.y }, { x, y: ac.y }, { x, y: bc.y }, { x: right(b), y: bc.y }];
        }
        if (hOverlap) {
            const x = (xLo + xHi) / 2;
            return [{ x, y: a.y }, { x, y: bottom(b) }];
        }
        const midY = (a.y + bottom(b)) / 2;
        return [{ x: ac.x, y: a.y }, { x: ac.x, y: midY }, { x: bc.x, y: midY }, { x: bc.x, y: bottom(b) }];
    }
    if (b.x >= right(a)) {
        if (vOverlap) {
            const y = (yLo + yHi) / 2;
            return [{ x: right(a), y }, { x: b.x, y }];
        }
        const midX = (right(a) + b.x) / 2;
        return [{ x: right(a), y: ac.y }, { x: midX, y: ac.y }, { x: midX, y: bc.y }, { x: b.x, y: bc.y }];
    }
    if (right(b) <= a.x) {
        if (vOverlap) {
            const y = (yLo + yHi) / 2;
            return [{ x: a.x, y }, { x: right(b), y }];
        }
        const midX = (a.x + right(b)) / 2;
        return [{ x: a.x, y: ac.y }, { x: midX, y: ac.y }, { x: midX, y: bc.y }, { x: right(b), y: bc.y }];
    }
    // Die Rechtecke überlappen sich: gerade Linie zwischen den Rändern.
    return [rectBorderPoint(a, bc), rectBorderPoint(b, ac)];
}

/** Gerade Verbindung zwischen zwei Rechtecken (Rand zu Rand auf der Mittelpunktsgeraden). */
export function routeStraight(a, b) {
    return [rectBorderPoint(a, center(b)), rectBorderPoint(b, center(a))];
}

/** Schleife einer Kante auf dasselbe Rechteck (oben rechts herum). */
export function routeSelfLoop(r, gap = 28) {
    const x1 = right(r) - Math.min(30, r.w / 3);
    const x2 = right(r) + gap;
    const y1 = r.y;
    const y2 = r.y - gap;
    return [{ x: x1, y: y1 }, { x: x1, y: y2 }, { x: x2, y: y2 }, { x: x2, y: r.y + Math.min(24, r.h / 3) }, { x: right(r), y: r.y + Math.min(24, r.h / 3) }];
}

/** Länge eines Linienzugs. */
export function polylineLength(points) {
    let len = 0;
    for (let i = 1; i < points.length; i++) {
        len += Math.hypot(points[i].x - points[i - 1].x, points[i].y - points[i - 1].y);
    }
    return len;
}

/** Punkt (und Winkel der Strecke) in Entfernung dist vom Anfang des Linienzugs. */
export function pointAlong(points, dist) {
    let rest = Math.max(0, dist);
    for (let i = 1; i < points.length; i++) {
        const a = points[i - 1];
        const b = points[i];
        const len = Math.hypot(b.x - a.x, b.y - a.y);
        if (rest <= len || i === points.length - 1) {
            const t = len === 0 ? 0 : Math.min(1, rest / len);
            return { x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t, angle: Math.atan2(b.y - a.y, b.x - a.x) };
        }
        rest -= len;
    }
    return { x: points[0]?.x ?? 0, y: points[0]?.y ?? 0, angle: 0 };
}

/** Mitte eines Linienzugs. */
export const polylineMid = (points) => pointAlong(points, polylineLength(points) / 2);

/** Winkel am Ende (Richtung der letzten Strecke) bzw. am Anfang (rückwärts). */
export function endAngle(points) {
    const n = points.length;
    return Math.atan2(points[n - 1].y - points[n - 2].y, points[n - 1].x - points[n - 2].x);
}

export function startAngle(points) {
    return Math.atan2(points[0].y - points[1].y, points[0].x - points[1].x);
}

/** SVG-Pfad aus Punkten {x, y}. */
export function polylinePath(points) {
    return points.map((p, i) => `${i === 0 ? 'M' : 'L'}${round1(p.x)} ${round1(p.y)}`).join('');
}

/** Rotiert Punkte (relativ zu 0,0) um angle und verschiebt sie nach tip. */
export function placeShape(tip, angle, local) {
    const cos = Math.cos(angle);
    const sin = Math.sin(angle);
    return local.map(([lx, ly]) => ({ x: round1(tip.x + lx * cos - ly * sin), y: round1(tip.y + lx * sin + ly * cos) }));
}

/**
 * Pfeilspitzen: Punkte relativ zur Spitze in Richtung "angle" (Linie kommt aus -x).
 * kinds: open (V), filled, triangle (leeres Dreieck), diamond (Raute)
 */
export function arrowShape(kind, tip, angle, size = 11) {
    const w = size * 0.55;
    switch (kind) {
        case 'open':
            return placeShape(tip, angle, [[-size, -w], [0, 0], [-size, w]]);
        case 'filled':
            return placeShape(tip, angle, [[0, 0], [-size, -w], [-size, w]]);
        case 'triangle':
            return placeShape(tip, angle, [[0, 0], [-size * 1.15, -w * 1.1], [-size * 1.15, w * 1.1]]);
        case 'diamond':
            return placeShape(tip, angle, [[0, 0], [-size * 0.8, -w], [-size * 1.6, 0], [-size * 0.8, w]]);
        default:
            return [];
    }
}

/** Länge, um die eine Linie vor der Spitze enden soll, damit sie nicht in die Spitze ragt. */
export const arrowInset = (kind, size = 11) => ({ triangle: size * 1.15, diamond: size * 1.6, filled: size * 0.9, open: 0 }[kind] ?? 0);

/** Verkürzt den Linienzug am Ende um dist. */
export function trimEnd(points, dist) {
    if (dist <= 0 || points.length < 2) {
        return points;
    }
    const out = points.slice();
    const n = out.length;
    const a = out[n - 2];
    const b = out[n - 1];
    const len = Math.hypot(b.x - a.x, b.y - a.y);
    if (len <= dist) {
        return out;
    }
    const t = (len - dist) / len;
    out[n - 1] = { x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t };
    return out;
}

/** Verkürzt den Linienzug am Anfang um dist. */
export function trimStart(points, dist) {
    return trimEnd(points.slice().reverse(), dist).reverse();
}

// ---------------------------------------------------------------------------
// Kreis-Kanten (Automaten)
// ---------------------------------------------------------------------------

/**
 * Kante zwischen zwei Kreisen (Mittelpunkte ca, cb, Radius r) als quadratische Kurve.
 * bend = 0: gerade; sonst seitlicher Versatz der Kontrollstelle (Pixel).
 * @returns {{d: string, start: {x,y}, end: {x,y}, angle: number, label: {x,y}}}
 */
export function circleEdge(ca, cb, r, bend = 0) {
    const mid = { x: (ca.x + cb.x) / 2, y: (ca.y + cb.y) / 2 };
    const dx = cb.x - ca.x;
    const dy = cb.y - ca.y;
    const len = Math.hypot(dx, dy) || 1;
    const normal = { x: -dy / len, y: dx / len };
    const ctrl = { x: mid.x + normal.x * bend * 2, y: mid.y + normal.y * bend * 2 };
    const aim = (c, other) => {
        const ddx = other.x - c.x;
        const ddy = other.y - c.y;
        const l = Math.hypot(ddx, ddy) || 1;
        return { x: c.x + (ddx / l) * r, y: c.y + (ddy / l) * r };
    };
    const target = bend === 0 ? cb : ctrl;
    const source = bend === 0 ? ca : ctrl;
    const start = aim(ca, target);
    const end = aim(cb, source);
    const angle = Math.atan2(end.y - (bend === 0 ? start.y : ctrl.y), end.x - (bend === 0 ? start.x : ctrl.x));
    const d = bend === 0
        ? `M${round1(start.x)} ${round1(start.y)}L${round1(end.x)} ${round1(end.y)}`
        : `M${round1(start.x)} ${round1(start.y)}Q${round1(ctrl.x)} ${round1(ctrl.y)} ${round1(end.x)} ${round1(end.y)}`;
    // Beschriftung: Mitte der Kurve (t = 0,5), seitlich verschoben
    const curveMid = bend === 0
        ? { x: (start.x + end.x) / 2, y: (start.y + end.y) / 2 }
        : { x: 0.25 * start.x + 0.5 * ctrl.x + 0.25 * end.x, y: 0.25 * start.y + 0.5 * ctrl.y + 0.25 * end.y };
    const side = bend === 0 ? 12 : (bend > 0 ? 12 : -12);
    const label = { x: curveMid.x + normal.x * side, y: curveMid.y + normal.y * side };

    return { d, start, end, angle, label };
}

/** Schleife auf einem Kreis (oberhalb). */
export function circleLoop(c, r) {
    const a = { x: c.x - r * 0.72, y: c.y - r * 0.7 };
    const b = { x: c.x + r * 0.72, y: c.y - r * 0.7 };
    const c1 = { x: c.x - r * 1.5, y: c.y - r * 2.7 };
    const c2 = { x: c.x + r * 1.5, y: c.y - r * 2.7 };
    return {
        d: `M${round1(a.x)} ${round1(a.y)}C${round1(c1.x)} ${round1(c1.y)} ${round1(c2.x)} ${round1(c2.y)} ${round1(b.x)} ${round1(b.y)}`,
        start: a,
        end: b,
        angle: Math.atan2(b.y - c2.y, b.x - c2.x),
        label: { x: c.x, y: c.y - r * 2.15 - 4 },
    };
}
