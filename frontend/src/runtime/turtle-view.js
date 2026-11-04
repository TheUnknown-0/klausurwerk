/**
 * Zeichenfläche für das Python-Modul 'turtle': animiert die Befehle einer
 * TurtleScene auf einem Canvas.
 */
import { h } from '../lib/dom.js';
import { TurtleScene, spriteTriangle, toCanvas } from './turtle-scene.js';

const WIDTH = 640;
const HEIGHT = 480;

/** @param {HTMLElement} parent */
export function createTurtleView(parent) {
    const scene = new TurtleScene();
    const canvas = h('canvas', {
        class: 'cw-turtle-canvas', width: WIDTH, height: HEIGHT,
        role: 'img', 'aria-label': 'Zeichnung der Schildkröte',
    });
    const root = h('div', { class: 'cw-turtle' }, canvas);
    parent.append(root);

    const dpr = Math.max(1, Math.min(2, globalThis.devicePixelRatio || 1));
    canvas.width = WIDTH * dpr;
    canvas.height = HEIGHT * dpr;
    const ctx = canvas.getContext('2d');
    const base = document.createElement('canvas');
    base.width = canvas.width;
    base.height = canvas.height;
    const bctx = base.getContext('2d');
    ctx.scale(dpr, dpr);
    bctx.scale(dpr, dpr);

    let drawn = 0;
    let raf = 0;
    let last = 0;
    let destroyed = false;

    const pt = (x, y) => toCanvas(x, y, WIDTH, HEIGHT);

    function strokePath(c, pts, color, width) {
        c.beginPath();
        pts.forEach(([x, y], i) => {
            const p = pt(x, y);
            if (i === 0) {
                c.moveTo(p.x, p.y);
            } else {
                c.lineTo(p.x, p.y);
            }
        });
        c.strokeStyle = color || '#000';
        c.lineWidth = width || 1;
        c.lineCap = 'round';
        c.lineJoin = 'round';
        c.stroke();
    }

    function drawItem(c, it) {
        switch (it.kind) {
            case 'line':
                strokePath(c, it.pts, it.c, it.w);
                break;
            case 'fill': {
                c.beginPath();
                it.pts.forEach(([x, y], i) => {
                    const p = pt(x, y);
                    if (i === 0) {
                        c.moveTo(p.x, p.y);
                    } else {
                        c.lineTo(p.x, p.y);
                    }
                });
                c.closePath();
                c.fillStyle = it.c || '#000';
                c.fill();
                break;
            }
            case 'dot': {
                const p = pt(it.x, it.y);
                c.beginPath();
                c.arc(p.x, p.y, Math.max(1, it.d / 2), 0, Math.PI * 2);
                c.fillStyle = it.c || '#000';
                c.fill();
                break;
            }
            case 'text': {
                const p = pt(it.x, it.y);
                const [family, size, style] = it.font;
                c.font = `${/bold/.test(style) ? 'bold ' : ''}${/italic/.test(style) ? 'italic ' : ''}${Number(size) || 8}px ${JSON.stringify(String(family))}, sans-serif`;
                c.textAlign = it.align === 'center' ? 'center' : it.align === 'right' ? 'right' : 'left';
                c.fillStyle = it.c || '#000';
                c.fillText(it.text, p.x, p.y);
                break;
            }
            default:
        }
    }

    function render() {
        if (scene.rebuild) {
            bctx.fillStyle = scene.bg;
            bctx.fillRect(0, 0, WIDTH, HEIGHT);
            scene.items.forEach((it) => drawItem(bctx, it));
            drawn = scene.items.length;
            scene.rebuild = false;
        } else {
            for (let i = drawn; i < scene.items.length; i++) {
                drawItem(bctx, scene.items[i]);
            }
            drawn = scene.items.length;
        }
        ctx.save();
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.drawImage(base, 0, 0);
        ctx.restore();
        if (scene.partial) {
            strokePath(ctx, scene.partial.pts, scene.partial.c, scene.partial.w);
        }
        for (const sp of scene.sprites.values()) {
            if (!sp.shown) {
                continue;
            }
            const tri = spriteTriangle(sp.x, sp.y, sp.h, 10).map(([x, y]) => pt(x, y));
            ctx.beginPath();
            tri.forEach((p, i) => (i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y)));
            ctx.closePath();
            ctx.fillStyle = sp.c || '#000';
            ctx.globalAlpha = 0.85;
            ctx.fill();
            ctx.globalAlpha = 1;
            ctx.strokeStyle = '#1b1d33';
            ctx.lineWidth = 1;
            ctx.stroke();
        }
    }

    function frame(now) {
        raf = 0;
        if (destroyed) {
            return;
        }
        const dt = last ? Math.min(100, now - last) : 16;
        last = now;
        scene.advance(dt);
        render();
        if (!scene.isIdle()) {
            raf = requestAnimationFrame(frame);
        } else {
            last = 0;
        }
    }

    function kick() {
        if (!raf && !destroyed) {
            raf = requestAnimationFrame(frame);
        }
    }

    render();

    return {
        element: root,
        scene,
        /** Befehle aus dem Python-Modul entgegennehmen. */
        handle(cmds) {
            scene.push(cmds);
            kick();
        },
        /** Alles sofort fertig zeichnen. */
        finish() {
            scene.finishAll();
            render();
        },
        reset() {
            scene.reset();
            drawn = 0;
            render();
        },
        hasContent: () => scene.used,
        toDataUrl() {
            render();
            return canvas.toDataURL('image/png');
        },
        destroy() {
            destroyed = true;
            if (raf) {
                cancelAnimationFrame(raf);
            }
            root.remove();
        },
    };
}
