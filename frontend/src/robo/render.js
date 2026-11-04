/**
 * Darstellung der Robo-Welt als SVG (eigene schlichte Grafik): Robo als Kreis mit Richtungspfeil,
 * Wände dunkel, Kisten braun, Schrauben grau mit Zahl, Ziele als Flagge.
 *
 *   const view = createWorldView(container, world);   // world: RoboWorld
 *   view.show(world);                                  // sofort darstellen
 *   view.animate(stepInfo, world, ms);                 // einen Zug animieren (stepInfo aus dem Player)
 *   view.destroy();
 *
 * Bewegungen werden mit requestAnimationFrame berechnet (kein CSS nötig, funktioniert mit der CSP).
 * Bei prefers-reduced-motion springt Robo ohne Zwischenbilder.
 */
import { DIR_NAMES, describeWorld } from './world.js';

const NS = 'http://www.w3.org/2000/svg';
export const CELL = 40;
const ANGLE = { N: 0, E: 90, S: 180, W: 270 };

function svg(tag, attrs = {}, ...children) {
    const el = document.createElementNS(NS, tag);
    for (const [key, value] of Object.entries(attrs)) {
        if (value !== undefined && value !== null && value !== false) {
            el.setAttribute(key, String(value));
        }
    }
    for (const child of children.flat()) {
        if (child) {
            el.append(child);
        }
    }
    return el;
}

const reducedMotion = () => typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;

export function createWorldView(container, world, { label = null } = {}) {
    const cols = world.cols;
    const rows = world.rows;
    const root = svg('svg', {
        class: 'robo-svg', viewBox: `0 0 ${cols * CELL} ${rows * CELL}`, role: 'img',
        'aria-label': label ?? describeWorld(world), preserveAspectRatio: 'xMidYMid meet',
    });
    const background = svg('g', { class: 'robo-bg' });
    const targetLayer = svg('g', { class: 'robo-targets' });
    const screwLayer = svg('g', { class: 'robo-screws' });
    const wallLayer = svg('g', { class: 'robo-walls' });
    const boxLayer = svg('g', { class: 'robo-boxes' });
    const roboLayer = svg('g', { class: 'robo-robot-layer' });
    root.append(background, targetLayer, wallLayer, screwLayer, boxLayer, roboLayer);
    container.append(root);

    // Hintergrund: Felder im Schachbrettmuster, Rand
    background.append(svg('rect', { x: 0, y: 0, width: cols * CELL, height: rows * CELL, class: 'robo-floor' }));
    for (let y = 0; y < rows; y++) {
        for (let x = 0; x < cols; x++) {
            background.append(svg('rect', {
                x: x * CELL, y: y * CELL, width: CELL, height: CELL,
                class: (x + y) % 2 === 0 ? 'robo-cell robo-cell-a' : 'robo-cell robo-cell-b',
            }));
        }
    }
    // Koordinatenbeschriftung in den Randfeldern (klein, nur bei größeren Zellen sinnvoll)
    const robot = svg('g', { class: 'robo-robot' },
        svg('circle', { r: CELL * 0.36, class: 'robo-body' }),
        svg('polygon', { points: `0,${-CELL * 0.28} ${CELL * 0.17},${CELL * 0.08} ${-CELL * 0.17},${CELL * 0.08}`, class: 'robo-arrow' }),
        svg('circle', { r: CELL * 0.07, cy: CELL * 0.16, class: 'robo-eye' }));
    roboLayer.append(robot);

    let pose = { x: world.x, y: world.y, angle: ANGLE[world.dir] };
    let raf = 0;
    let moving = null; // { el, from, to } bewegte Kiste
    let destroyed = false;

    function placeRobot(p) {
        robot.setAttribute('transform', `translate(${(p.x + 0.5) * CELL} ${(p.y + 0.5) * CELL}) rotate(${p.angle})`);
    }

    function flag(x, y) {
        const px = x * CELL;
        const py = y * CELL;
        return svg('g', { class: 'robo-target' },
            svg('rect', { x: px + 3, y: py + 3, width: CELL - 6, height: CELL - 6, rx: 4, class: 'robo-target-field' }),
            svg('line', { x1: px + CELL * 0.38, y1: py + CELL * 0.18, x2: px + CELL * 0.38, y2: py + CELL * 0.84, class: 'robo-flag-pole' }),
            svg('polygon', {
                points: `${px + CELL * 0.38},${py + CELL * 0.18} ${px + CELL * 0.8},${py + CELL * 0.34} ${px + CELL * 0.38},${py + CELL * 0.5}`,
                class: 'robo-flag',
            }));
    }

    function drawStatic(w) {
        targetLayer.replaceChildren(...[...w.targets].map((k) => flag(...w.cellOf(k))));
        wallLayer.replaceChildren(...[...w.walls].map((k) => {
            const [x, y] = w.cellOf(k);
            return svg('rect', { x: x * CELL, y: y * CELL, width: CELL, height: CELL, class: 'robo-wall' });
        }));
    }

    function drawDynamic(w, hideBoxAt = null) {
        screwLayer.replaceChildren(...[...w.screws.entries()].map(([k, count]) => {
            const [x, y] = w.cellOf(k);
            return svg('g', { class: 'robo-screw' },
                svg('circle', { cx: (x + 0.5) * CELL, cy: (y + 0.5) * CELL, r: CELL * 0.27, class: 'robo-screw-disc' }),
                svg('text', { x: (x + 0.5) * CELL, y: (y + 0.5) * CELL + 5, 'text-anchor': 'middle', class: 'robo-screw-count' }, String(count)));
        }));
        boxLayer.replaceChildren(...[...w.boxes].map((k) => {
            const [x, y] = w.cellOf(k);
            const el = svg('g', { class: 'robo-box', 'data-key': k },
                svg('rect', { x: 5, y: 5, width: CELL - 10, height: CELL - 10, rx: 3, class: 'robo-box-body' }),
                svg('line', { x1: 5, y1: 5, x2: CELL - 5, y2: CELL - 5, class: 'robo-box-cross' }),
                svg('line', { x1: CELL - 5, y1: 5, x2: 5, y2: CELL - 5, class: 'robo-box-cross' }));
            el.setAttribute('transform', `translate(${x * CELL} ${y * CELL})`);
            return el;
        }));
    }

    function applyCrash(w) {
        robot.classList.toggle('is-crashed', !!w.crashed);
    }

    function show(w) {
        cancelAnimationFrame(raf);
        moving = null;
        drawStatic(w);
        drawDynamic(w);
        // Winkel stetig halten, damit die nächste Drehung kurz bleibt
        const target = ANGLE[w.dir];
        let angle = target;
        while (angle - pose.angle > 180) angle -= 360;
        while (angle - pose.angle < -180) angle += 360;
        pose = { x: w.x, y: w.y, angle };
        placeRobot(pose);
        applyCrash(w);
        root.setAttribute('aria-label', describeWorld(w));
    }

    /**
     * Animiert einen Zug. stepInfo = { code, result, crashed }, w = Welt NACH dem Zug.
     * Bei Fehlern bleibt Robo stehen und wird rot.
     */
    function animate(stepInfo, w, ms) {
        const r = stepInfo.result;
        if (!r.ok) {
            show(w);
            return;
        }
        drawDynamic(w);
        const instant = ms <= 0 || reducedMotion();
        const from = { ...pose };
        const to = { x: w.x, y: w.y, angle: pose.angle };
        if (r.code === 'l' || r.code === 'r') {
            to.angle = pose.angle + (r.code === 'r' ? 90 : -90);
        }
        let boxFrom = null;
        let boxTo = null;
        if (r.box) {
            // Die geschobene Kiste steht schon an der neuen Stelle; für die Animation zurücksetzen.
            const key = w.key(r.box.to[0], r.box.to[1]);
            const el = boxLayer.querySelector(`[data-key="${key}"]`);
            if (el) {
                moving = el;
                boxFrom = r.box.from;
                boxTo = r.box.to;
            }
        }
        cancelAnimationFrame(raf);
        if (instant) {
            pose = to;
            placeRobot(pose);
            applyCrash(w);
            root.setAttribute('aria-label', describeWorld(w));
            return;
        }
        const start = performance.now();
        // Die Bewegung braucht höchstens 70 % der Schrittzeit, den Rest steht Robo still.
        const duration = Math.max(40, ms * 0.7);
        const frame = (now) => {
            if (destroyed) {
                return;
            }
            const t = Math.min(1, (now - start) / duration);
            const e = t < 0.5 ? 2 * t * t : 1 - ((-2 * t + 2) ** 2) / 2;
            pose = {
                x: from.x + (to.x - from.x) * e,
                y: from.y + (to.y - from.y) * e,
                angle: from.angle + (to.angle - from.angle) * e,
            };
            placeRobot(pose);
            if (moving && boxFrom) {
                const bx = boxFrom[0] + (boxTo[0] - boxFrom[0]) * e;
                const by = boxFrom[1] + (boxTo[1] - boxFrom[1]) * e;
                moving.setAttribute('transform', `translate(${bx * CELL} ${by * CELL})`);
            }
            if (t < 1) {
                raf = requestAnimationFrame(frame);
            } else {
                moving = null;
                root.setAttribute('aria-label', describeWorld(w));
            }
        };
        if (moving && boxFrom) {
            moving.setAttribute('transform', `translate(${boxFrom[0] * CELL} ${boxFrom[1] * CELL})`);
        }
        raf = requestAnimationFrame(frame);
    }

    show(world);

    return {
        element: root,
        show,
        animate,
        destroy() {
            destroyed = true;
            cancelAnimationFrame(raf);
            root.remove();
        },
    };
}

/** Kurzer Text für die Statuszeile unter der Welt. */
export function statusText(w) {
    const parts = [
        `Robo steht auf Feld ${w.x}|${w.y}`,
        `schaut nach ${DIR_NAMES[w.dir]}`,
        `${w.basket} ${w.basket === 1 ? 'Schraube' : 'Schrauben'} im Korb`,
    ];
    const here = w.screwsAt(w.x, w.y);
    if (here > 0) {
        parts.push(`${here} ${here === 1 ? 'Schraube liegt' : 'Schrauben liegen'} hier`);
    }
    if (w.isTarget(w.x, w.y)) {
        parts.push('auf einem Ziel');
    }
    return `${parts.join(', ')}.`;
}
