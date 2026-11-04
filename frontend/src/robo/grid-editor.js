/**
 * Welt-Editor für Robo: Rasterzellen anklicken, um Wände, Kisten, Schrauben, Ziele und die
 * Startposition zu setzen. Tastatur: Pfeiltasten wechseln das Feld, Enter oder Leertaste wendet
 * das gewählte Werkzeug an. Mit gedrückter Maustaste über mehrere Felder ziehen malt.
 *
 *   const ed = createGridEditor(container, { world, onChange, locked, idPrefix });
 *   ed.getWorld()  -> Weltdaten (docs/DATENFORMATE.md, 2.16, ohne basket)
 *   ed.setWorld(w) ed.destroy()
 *
 * Die Bearbeitungslogik (applyTool, resizeWorld) ist ohne DOM testbar.
 */
import { h, icon } from '../lib/dom.js';
import { MAX_SIZE, MIN_SIZE, MAX_SCREWS_PER_CELL, DIRS, normalizeWorldData } from './world.js';

export const TOOLS = [
    ['robo', 'Robo'],
    ['wall', 'Wand'],
    ['box', 'Kiste'],
    ['screw', 'Schraube'],
    ['target', 'Ziel'],
    ['erase', 'Radierer'],
];

export function emptyWorld(cols = 8, rows = 6) {
    return { cols, rows, robo: { x: 0, y: 0, dir: 'E' }, walls: [], boxes: [], screws: [], targets: [] };
}

const has = (list, x, y) => list.some((c) => c[0] === x && c[1] === y);
const without = (list, x, y) => list.filter((c) => !(c[0] === x && c[1] === y));

/** Was steht auf dem Feld? (für Beschriftung und Tests) */
export function cellInfo(world, x, y) {
    return {
        robo: world.robo.x === x && world.robo.y === y,
        wall: has(world.walls, x, y),
        box: has(world.boxes, x, y),
        target: has(world.targets, x, y),
        screws: world.screws.find((s) => s.x === x && s.y === y)?.count ?? 0,
    };
}

/**
 * Wendet ein Werkzeug auf ein Feld an. Rückgabe: neue Welt (die alte bleibt unverändert) und
 * `changed`; bei unzulässigen Aktionen `message` (z. B. Wand unter Robo).
 *
 * `mode` ('add' | 'remove') hält beim Ziehen die Richtung fest, die das erste Feld bestimmt hat.
 */
export function applyTool(world, tool, x, y, { count = 1, mode = null } = {}) {
    const w = JSON.parse(JSON.stringify(world));
    const info = cellInfo(w, x, y);
    const out = (extra = {}) => ({ world: w, changed: true, mode: extra.mode ?? mode, ...extra });
    const refuse = (message) => ({ world, changed: false, message, mode });
    switch (tool) {
        case 'robo':
            if (info.robo) {
                const i = DIRS.indexOf(w.robo.dir);
                w.robo.dir = DIRS[(i + 1) % 4];
                return out();
            }
            w.robo = { x, y, dir: w.robo.dir };
            w.walls = without(w.walls, x, y);
            w.boxes = without(w.boxes, x, y);
            return out();
        case 'wall': {
            const adding = mode ? mode === 'add' : !info.wall;
            if (adding) {
                if (info.robo) {
                    return refuse('Unter Robo kann keine Wand stehen.');
                }
                if (info.wall) {
                    return { world, changed: false, mode: 'add' };
                }
                w.walls.push([x, y]);
                w.boxes = without(w.boxes, x, y);
                w.targets = without(w.targets, x, y);
                w.screws = w.screws.filter((s) => !(s.x === x && s.y === y));
                return out({ mode: 'add' });
            }
            w.walls = without(w.walls, x, y);
            return { world: w, changed: info.wall, mode: 'remove' };
        }
        case 'box': {
            const adding = mode ? mode === 'add' : !info.box;
            if (adding) {
                if (info.robo) {
                    return refuse('Auf dem Feld von Robo kann keine Kiste stehen.');
                }
                if (info.box) {
                    return { world, changed: false, mode: 'add' };
                }
                w.boxes.push([x, y]);
                w.walls = without(w.walls, x, y);
                w.screws = w.screws.filter((s) => !(s.x === x && s.y === y));
                return out({ mode: 'add' });
            }
            w.boxes = without(w.boxes, x, y);
            return { world: w, changed: info.box, mode: 'remove' };
        }
        case 'screw': {
            const n = Math.max(1, Math.min(MAX_SCREWS_PER_CELL, Math.round(count) || 1));
            const adding = mode ? mode === 'add' : info.screws !== n;
            if (adding) {
                if (info.wall || info.box) {
                    return refuse('Auf einer Wand oder unter einer Kiste kann keine Schraube liegen.');
                }
                w.screws = w.screws.filter((s) => !(s.x === x && s.y === y));
                w.screws.push({ x, y, count: n });
                return out({ mode: 'add' });
            }
            w.screws = w.screws.filter((s) => !(s.x === x && s.y === y));
            return { world: w, changed: info.screws > 0, mode: 'remove' };
        }
        case 'target': {
            const adding = mode ? mode === 'add' : !info.target;
            if (adding) {
                if (info.wall) {
                    return refuse('Auf einer Wand kann kein Ziel liegen.');
                }
                if (info.target) {
                    return { world, changed: false, mode: 'add' };
                }
                w.targets.push([x, y]);
                return out({ mode: 'add' });
            }
            w.targets = without(w.targets, x, y);
            return { world: w, changed: info.target, mode: 'remove' };
        }
        case 'erase':
            w.walls = without(w.walls, x, y);
            w.boxes = without(w.boxes, x, y);
            w.targets = without(w.targets, x, y);
            w.screws = w.screws.filter((s) => !(s.x === x && s.y === y));
            return { world: w, changed: info.wall || info.box || info.target || info.screws > 0, mode: 'remove' };
        default:
            return refuse('Unbekanntes Werkzeug.');
    }
}

/** Ändert die Größe; was außerhalb liegt, fällt weg, Robo bleibt im Raster. */
export function resizeWorld(world, cols, rows) {
    const c = Math.max(MIN_SIZE, Math.min(MAX_SIZE, Math.round(cols) || MIN_SIZE));
    const r = Math.max(MIN_SIZE, Math.min(MAX_SIZE, Math.round(rows) || MIN_SIZE));
    const inside = (x, y) => x >= 0 && y >= 0 && x < c && y < r;
    const w = JSON.parse(JSON.stringify(world));
    w.cols = c;
    w.rows = r;
    w.walls = w.walls.filter(([x, y]) => inside(x, y));
    w.boxes = w.boxes.filter(([x, y]) => inside(x, y));
    w.targets = w.targets.filter(([x, y]) => inside(x, y));
    w.screws = w.screws.filter((s) => inside(s.x, s.y));
    w.robo.x = Math.min(w.robo.x, c - 1);
    w.robo.y = Math.min(w.robo.y, r - 1);
    w.walls = without(w.walls, w.robo.x, w.robo.y);
    w.boxes = without(w.boxes, w.robo.x, w.robo.y);
    return w;
}

const ARROW = { N: 'arrow-up', E: 'arrow-right', S: 'arrow-down', W: 'arrow-left' };

function describeCell(world, x, y) {
    const info = cellInfo(world, x, y);
    const parts = [];
    if (info.robo) parts.push(`Robo, schaut nach ${{ N: 'Norden', E: 'Osten', S: 'Süden', W: 'Westen' }[world.robo.dir]}`);
    if (info.wall) parts.push('Wand');
    if (info.box) parts.push('Kiste');
    if (info.screws) parts.push(`${info.screws} ${info.screws === 1 ? 'Schraube' : 'Schrauben'}`);
    if (info.target) parts.push('Ziel');
    return `Feld ${x}|${y}: ${parts.length ? parts.join(', ') : 'leer'}`;
}

export function createGridEditor(container, { world: initial, onChange = () => {}, locked = false, idPrefix = 'grid' } = {}) {
    let world = normalizeWorldData(initial ?? emptyWorld());
    delete world.basket;
    let tool = 'wall';
    let count = 1;
    let painting = null; // { mode }
    let focus = [world.robo.x, world.robo.y];
    const cells = new Map();

    const message = h('div', { class: 're-message', role: 'status', 'aria-live': 'polite' });
    const colsInput = h('input', { class: 'input re-size', type: 'number', min: String(MIN_SIZE), max: String(MAX_SIZE), id: `${idPrefix}-cols`, value: String(world.cols), disabled: locked });
    const rowsInput = h('input', { class: 'input re-size', type: 'number', min: String(MIN_SIZE), max: String(MAX_SIZE), id: `${idPrefix}-rows`, value: String(world.rows), disabled: locked });
    const countInput = h('input', { class: 'input re-count', type: 'number', min: '1', max: String(MAX_SCREWS_PER_CELL), id: `${idPrefix}-count`, value: '1', disabled: locked });
    const toolButtons = new Map();
    const palette = h('div', { class: 're-tools', role: 'radiogroup', 'aria-label': 'Werkzeug' });
    for (const [key, label] of TOOLS) {
        const b = h('button', {
            type: 'button', class: 'btn btn-sm re-tool', role: 'radio', 'aria-checked': key === tool ? 'true' : 'false',
            disabled: locked, onclick: () => selectTool(key),
        }, label);
        toolButtons.set(key, b);
        palette.append(b);
    }
    const grid = h('div', { class: 're-grid', role: 'grid', 'aria-label': 'Welt, Felder anklicken' });
    const root = h('div', { class: 're' },
        h('div', { class: 're-bar' },
            palette,
            h('label', { for: countInput.id, class: 're-label' }, 'Schrauben je Feld'), countInput,
            h('label', { for: colsInput.id, class: 're-label' }, 'Spalten'), colsInput,
            h('label', { for: rowsInput.id, class: 're-label' }, 'Zeilen'), rowsInput),
        message, grid,
        h('p', { class: 're-hint' }, 'Robo: Feld wählen setzt die Startposition, nochmal anklicken dreht ihn. Mit gedrückter Maustaste über mehrere Felder ziehen malt. Koordinaten zählen ab 0, (0|0) ist links oben.'));
    container.append(root);

    function selectTool(key) {
        tool = key;
        for (const [k, b] of toolButtons) {
            b.setAttribute('aria-checked', k === key ? 'true' : 'false');
            b.classList.toggle('is-active', k === key);
        }
    }
    selectTool(tool);

    countInput.addEventListener('input', () => {
        count = Math.max(1, Math.min(MAX_SCREWS_PER_CELL, parseInt(countInput.value, 10) || 1));
    });

    function render() {
        grid.replaceChildren();
        cells.clear();
        grid.style.setProperty('--re-cols', String(world.cols));
        for (let y = 0; y < world.rows; y++) {
            const row = h('div', { class: 're-row', role: 'row' });
            for (let x = 0; x < world.cols; x++) {
                const btn = h('button', {
                    type: 'button', role: 'gridcell', class: 're-cell', tabindex: '-1', disabled: locked,
                    dataset: { x, y },
                });
                cells.set(`${x},${y}`, btn);
                row.append(btn);
            }
            grid.append(row);
        }
        refreshCells();
    }

    function refreshCells() {
        for (let y = 0; y < world.rows; y++) {
            for (let x = 0; x < world.cols; x++) {
                const btn = cells.get(`${x},${y}`);
                if (!btn) continue;
                const info = cellInfo(world, x, y);
                btn.className = ['re-cell', info.wall && 're-wall', info.box && 're-box', info.target && 're-target', info.screws && 're-screw', info.robo && 're-robo']
                    .filter(Boolean).join(' ');
                const parts = [];
                if (info.robo) parts.push(icon(ARROW[world.robo.dir]));
                else if (info.target && !info.wall && !info.box && !info.screws) parts.push(icon('flag'));
                if (!info.wall && !info.box && info.screws) parts.push(String(info.screws));
                btn.replaceChildren(...parts);
                btn.setAttribute('aria-label', describeCell(world, x, y));
                btn.tabIndex = x === focus[0] && y === focus[1] ? 0 : -1;
            }
        }
    }

    function apply(x, y) {
        const result = applyTool(world, tool, x, y, { count, mode: painting?.mode ?? null });
        if (painting && !painting.mode && result.mode) {
            painting.mode = result.mode;
        }
        message.textContent = result.message ?? '';
        if (result.changed) {
            world = result.world;
            refreshCells();
            onChange(getWorld());
        }
    }

    function getWorld() {
        return JSON.parse(JSON.stringify(world));
    }

    grid.addEventListener('pointerdown', (ev) => {
        const btn = ev.target.closest('.re-cell');
        if (!btn || locked || ev.button !== 0) return;
        ev.preventDefault();
        focus = [Number(btn.dataset.x), Number(btn.dataset.y)];
        painting = { mode: null };
        apply(focus[0], focus[1]);
        btn.focus();
    });
    grid.addEventListener('pointerover', (ev) => {
        if (!painting || ev.buttons !== 1 || tool === 'robo') return;
        const btn = ev.target.closest('.re-cell');
        if (btn) apply(Number(btn.dataset.x), Number(btn.dataset.y));
    });
    const stopPainting = () => { painting = null; };
    window.addEventListener('pointerup', stopPainting);
    window.addEventListener('pointercancel', stopPainting);
    grid.addEventListener('keydown', (ev) => {
        const btn = ev.target.closest('.re-cell');
        if (!btn || locked) return;
        let [x, y] = [Number(btn.dataset.x), Number(btn.dataset.y)];
        const moves = { ArrowRight: [1, 0], ArrowLeft: [-1, 0], ArrowDown: [0, 1], ArrowUp: [0, -1] };
        if (moves[ev.key]) {
            ev.preventDefault();
            x = Math.max(0, Math.min(world.cols - 1, x + moves[ev.key][0]));
            y = Math.max(0, Math.min(world.rows - 1, y + moves[ev.key][1]));
            focus = [x, y];
            refreshCells();
            cells.get(`${x},${y}`)?.focus();
        } else if (ev.key === 'Enter' || ev.key === ' ') {
            ev.preventDefault();
            painting = null;
            apply(x, y);
        }
    });

    function resizeFromInputs() {
        const next = resizeWorld(world, Number(colsInput.value), Number(rowsInput.value));
        colsInput.value = String(next.cols);
        rowsInput.value = String(next.rows);
        if (next.cols === world.cols && next.rows === world.rows) return;
        world = next;
        focus = [Math.min(focus[0], world.cols - 1), Math.min(focus[1], world.rows - 1)];
        render();
        onChange(getWorld());
    }
    colsInput.addEventListener('change', resizeFromInputs);
    rowsInput.addEventListener('change', resizeFromInputs);

    render();

    return {
        getWorld,
        setWorld(next) {
            world = normalizeWorldData(next);
            delete world.basket;
            colsInput.value = String(world.cols);
            rowsInput.value = String(world.rows);
            focus = [world.robo.x, world.robo.y];
            render();
        },
        destroy() {
            window.removeEventListener('pointerup', stopPainting);
            window.removeEventListener('pointercancel', stopPainting);
            root.remove();
        },
    };
}
