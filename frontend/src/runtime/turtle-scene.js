/**
 * Turtle-Szene: nimmt die Zeichenbefehle des Python-Moduls 'turtle' entgegen
 * und berechnet daraus Schritt für Schritt (animiert) den Zeichenstand.
 * Reine Logik ohne DOM; die Darstellung steht in turtle-view.js.
 *
 * Koordinaten wie beim echten turtle-Modul: Ursprung in der Mitte, y nach oben,
 * Richtung in Grad (0 = Osten, gegen den Uhrzeigersinn).
 *
 * Befehle (vom Python-Modul geliefert):
 *   { t:'path', id, pts:[[x,y],…], pen, c, w, h, s }   Bewegung entlang eines Linienzugs
 *   { t:'turn', id, from, to, s }                       Drehung
 *   { t:'beginfill', id }  { t:'endfill', id, pts, c }  Füllfläche
 *   { t:'dot', id, x, y, d, c }  { t:'text', id, x, y, s, c, font, align }
 *   { t:'clear', id }  { t:'bg', c }
 *   { t:'sprite', id, shown, c, x, y, h }               Zustand der Schildkröte setzen
 */

/** Zeichengeschwindigkeit in Pixeln je Millisekunde; 0 = sofort. */
export function speedPxPerMs(speed) {
    const s = Math.round(Number(speed));
    if (!Number.isFinite(s) || s <= 0 || s > 10) {
        return s === 0 ? Infinity : speedPxPerMs(3);
    }
    return 0.05 * 1.55 ** (s - 1);
}

/** Drehgeschwindigkeit in Grad je Millisekunde. */
export function speedDegPerMs(speed) {
    const v = speedPxPerMs(speed);
    return v === Infinity ? Infinity : v * 2;
}

export function pathLength(pts) {
    let sum = 0;
    for (let i = 1; i < pts.length; i++) {
        sum += Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1]);
    }
    return sum;
}

/** Punkt nach der Strecke dist entlang des Linienzugs. */
export function pointAt(pts, dist) {
    if (pts.length === 0) {
        return { x: 0, y: 0 };
    }
    let rest = Math.max(0, dist);
    for (let i = 1; i < pts.length; i++) {
        const [x0, y0] = pts[i - 1];
        const [x1, y1] = pts[i];
        const seg = Math.hypot(x1 - x0, y1 - y0);
        if (rest <= seg) {
            const f = seg === 0 ? 0 : rest / seg;
            return { x: x0 + (x1 - x0) * f, y: y0 + (y1 - y0) * f };
        }
        rest -= seg;
    }
    const last = pts[pts.length - 1];
    return { x: last[0], y: last[1] };
}

/** Der Linienzug bis zur Strecke dist (letzter Punkt interpoliert). */
export function pointsUpTo(pts, dist) {
    if (pts.length === 0) {
        return [];
    }
    const out = [pts[0].slice(0, 2)];
    let rest = Math.max(0, dist);
    for (let i = 1; i < pts.length; i++) {
        if (rest <= 0) {
            break;
        }
        const [x0, y0] = pts[i - 1];
        const [x1, y1] = pts[i];
        const seg = Math.hypot(x1 - x0, y1 - y0);
        if (rest >= seg) {
            out.push([x1, y1]);
            rest -= seg;
        } else {
            const f = seg === 0 ? 0 : rest / seg;
            out.push([x0 + (x1 - x0) * f, y0 + (y1 - y0) * f]);
            break;
        }
    }
    return out;
}

/** Turtle-Koordinaten in Canvas-Koordinaten (y nach unten). */
export function toCanvas(x, y, width, height) {
    return { x: width / 2 + x, y: height / 2 - y };
}

/** Dreieck der Schildkröte: Spitze in Blickrichtung. Liefert 3 Punkte in Turtle-Koordinaten. */
export function spriteTriangle(x, y, headingDeg, size = 10) {
    const a = (headingDeg * Math.PI) / 180;
    const rot = (dx, dy) => [x + dx * Math.cos(a) - dy * Math.sin(a), y + dx * Math.sin(a) + dy * Math.cos(a)];
    return [rot(size * 1.2, 0), rot(-size * 0.8, size * 0.6), rot(-size * 0.8, -size * 0.6)];
}

/** Umschließendes Rechteck aller Zeichnungsteile (für Hinweise "außerhalb"). */
export function itemsBounds(items) {
    let minX = Infinity;
    let minY = Infinity;
    let maxX = -Infinity;
    let maxY = -Infinity;
    const add = (x, y) => {
        minX = Math.min(minX, x);
        minY = Math.min(minY, y);
        maxX = Math.max(maxX, x);
        maxY = Math.max(maxY, y);
    };
    for (const it of items) {
        if (it.pts) {
            it.pts.forEach(([x, y]) => add(x, y));
        } else if (it.x !== undefined) {
            add(it.x, it.y);
        }
    }
    return minX === Infinity ? null : { minX, minY, maxX, maxY };
}

const DEFAULT_SPRITE = () => ({ x: 0, y: 0, h: 0, shown: false, c: '#000000' });

export class TurtleScene {
    constructor({ maxItems = 60000 } = {}) {
        this.maxItems = maxItems;
        this.reset();
    }

    reset() {
        this.items = [];
        this.sprites = new Map();
        this.bg = '#ffffff';
        this.partial = null;
        this.queue = [];
        this.current = null;
        this.fillStarts = new Map();
        /** true, wenn die Darstellung alles neu zeichnen muss (Löschen, Füllung eingefügt) */
        this.rebuild = true;
        this.used = false;
    }

    push(cmds) {
        for (const c of Array.isArray(cmds) ? cmds : [cmds]) {
            if (c && typeof c === 'object') {
                this.queue.push(c);
                this.used = true;
            }
        }
    }

    isIdle() {
        return this.current === null && this.queue.length === 0;
    }

    sprite(id) {
        const key = Number(id) || 0;
        if (!this.sprites.has(key)) {
            this.sprites.set(key, DEFAULT_SPRITE());
        }
        return this.sprites.get(key);
    }

    /** Alles sofort fertig zeichnen (Stopp, Programmende bei Geschwindigkeit 0). */
    finishAll() {
        let guard = 0;
        while (!this.isIdle() && guard++ < 10_000_000) {
            this.advance(1e9);
        }
    }

    /**
     * Schreitet um dtMs Millisekunden voran. Je größer der Rückstau, desto
     * schneller läuft die Animation, damit lange Programme nicht minutenlang zeichnen.
     * @returns {boolean} true, wenn sich etwas geändert hat
     */
    advance(dtMs) {
        let changed = false;
        const factor = Math.max(1, this.queue.length / 300);
        let remaining = Math.max(0, dtMs) * factor;
        let guard = 0;
        while (guard++ < 1_000_000) {
            if (!this.current) {
                const cmd = this.queue.shift();
                if (!cmd) {
                    break;
                }
                changed = true;
                this._start(cmd);
                if (!this.current) {
                    continue;
                }
            }
            const cur = this.current;
            const step = Math.min(remaining, cur.duration - cur.elapsed);
            cur.elapsed += step;
            remaining -= step;
            changed = true;
            this._animate(cur);
            if (cur.elapsed >= cur.duration) {
                this._complete(cur);
                this.current = null;
            } else {
                break;
            }
        }
        return changed;
    }

    _start(cmd) {
        const id = Number(cmd.id) || 0;
        switch (cmd.t) {
            case 'path': {
                const pts = (cmd.pts ?? []).filter((p) => Array.isArray(p) && p.length >= 2);
                if (pts.length === 0) {
                    return;
                }
                const length = pathLength(pts);
                const v = speedPxPerMs(cmd.s ?? 3);
                this.current = { kind: 'path', cmd: { ...cmd, pts }, id, length, elapsed: 0, duration: v === Infinity ? 0 : length / v };
                return;
            }
            case 'turn': {
                const from = Number(cmd.from) || 0;
                const to = Number(cmd.to) || 0;
                const v = speedDegPerMs(cmd.s ?? 3);
                this.current = { kind: 'turn', cmd, id, from, to, elapsed: 0, duration: v === Infinity ? 0 : Math.abs(to - from) / v };
                return;
            }
            case 'beginfill':
                this.fillStarts.set(id, this.items.length);
                return;
            case 'endfill': {
                const item = { kind: 'fill', tid: id, pts: cmd.pts ?? [], c: cmd.c ?? '#000000' };
                const at = this.fillStarts.get(id);
                this.fillStarts.delete(id);
                if (at !== undefined && at <= this.items.length) {
                    this.items.splice(at, 0, item);
                    this.rebuild = true;
                } else {
                    this._addItem(item);
                }
                return;
            }
            case 'dot':
                this._addItem({ kind: 'dot', tid: id, x: cmd.x, y: cmd.y, d: cmd.d ?? 5, c: cmd.c ?? '#000000' });
                return;
            case 'text':
                this._addItem({
                    kind: 'text', tid: id, x: cmd.x, y: cmd.y, text: String(cmd.s ?? ''), c: cmd.c ?? '#000000',
                    font: cmd.font ?? ['Arial', 8, 'normal'], align: cmd.align ?? 'left',
                });
                return;
            case 'clear':
                // id 'all' löscht die ganze Zeichnung (Screen.clear)
                this.items = cmd.id === 'all' ? [] : this.items.filter((it) => it.tid !== id);
                this.fillStarts.delete(id);
                this.rebuild = true;
                return;
            case 'bg':
                this.bg = cmd.c || '#ffffff';
                this.rebuild = true;
                return;
            case 'sprite': {
                const sp = this.sprite(id);
                for (const k of ['shown', 'c', 'x', 'y', 'h']) {
                    if (cmd[k] !== undefined) {
                        sp[k] = cmd[k];
                    }
                }
                return;
            }
            default:
                return;
        }
    }

    _animate(cur) {
        const sp = this.sprite(cur.id);
        const f = cur.duration === 0 ? 1 : Math.min(1, cur.elapsed / cur.duration);
        if (cur.kind === 'path') {
            const dist = cur.length * f;
            const p = pointAt(cur.cmd.pts, dist);
            sp.x = p.x;
            sp.y = p.y;
            this.partial = cur.cmd.pen && f < 1
                ? { pts: pointsUpTo(cur.cmd.pts, dist), c: cur.cmd.c, w: cur.cmd.w, tid: cur.id }
                : null;
        } else {
            sp.h = cur.from + (cur.to - cur.from) * f;
        }
    }

    _complete(cur) {
        const sp = this.sprite(cur.id);
        this.partial = null;
        if (cur.kind === 'path') {
            const pts = cur.cmd.pts;
            const last = pts[pts.length - 1];
            sp.x = last[0];
            sp.y = last[1];
            if (cur.cmd.h !== undefined) {
                sp.h = cur.cmd.h;
            }
            if (cur.cmd.pen && pts.length >= 2) {
                this._addItem({ kind: 'line', tid: cur.id, pts, c: cur.cmd.c ?? '#000000', w: cur.cmd.w ?? 1 });
            }
        } else {
            sp.h = cur.to;
        }
    }

    _addItem(item) {
        this.items.push(item);
        if (this.items.length > this.maxItems) {
            // älteste Teile verwerfen, damit das Neuzeichnen schnell bleibt
            const drop = Math.ceil(this.maxItems * 0.1);
            this.items.splice(0, drop);
            for (const [k, v] of this.fillStarts) {
                this.fillStarts.set(k, Math.max(0, v - drop));
            }
            this.rebuild = true;
        }
    }
}
