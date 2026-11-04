/**
 * Sitzplan: Kacheln im Raster des Raums (exams.room_id), per Drag and Drop (oder Alt+Pfeiltasten) verschiebbar.
 * Ohne Raum ordnet das Pult die Kacheln automatisch nach Namen an. Nicht zugeordnete Personen
 * stehen in einer Leiste unter dem Raster.
 */
import { h, toast } from '../lib/dom.js';
import { post } from '../lib/api.js';
import { createGhost, createTile, updateTile, updateTileClock } from './tile.js';

export function createSeatPlan(store, handlers) {
    const { state } = store;
    const tiles = new Map(); // participation id -> Element
    const ghosts = new Map(); // user id -> Element
    let cells = new Map(); // "col:row" -> Element
    let gridKey = '';
    let receivedAt = Date.now();

    const hint = h('div', { class: 'pult-seat-hint text-sm text-soft' });
    const grid = h('div', { class: 'pult-grid', role: 'group', 'aria-label': 'Sitzplan' });
    const stripTitle = h('h3', { class: 'pult-strip-title' });
    const strip = h('div', { class: 'pult-strip-items' });
    const unassigned = h('section', { class: 'pult-unassigned', 'aria-label': 'Nicht zugeordnete Personen' }, stripTitle, strip);
    const el = h('section', { class: 'pult-seat card' }, hint, grid, unassigned);

    const tileHandlers = {
        onSelect: handlers.onSelect,
        onDetail: handlers.onDetail,
        onMove: (pid, key) => moveByKey({ pid }, key),
        onMoveGhost: (userId, key) => moveByKey({ userId }, key),
    };

    // ---- Drag and Drop ----
    function dragData(e) {
        const raw = e.dataTransfer?.getData('text/plain') ?? '';
        const [kind, id] = raw.split(':');
        if ((kind !== 'p' && kind !== 'u') || !/^\d+$/.test(id ?? '')) {
            return null;
        }
        return kind === 'p' ? { pid: Number(id) } : { userId: Number(id) };
    }

    function wireDropTarget(node, target) {
        node.addEventListener('dragover', (e) => {
            if (!state.exam?.room) return;
            e.preventDefault();
            node.classList.add('is-drop');
        });
        node.addEventListener('dragleave', () => node.classList.remove('is-drop'));
        node.addEventListener('drop', (e) => {
            node.classList.remove('is-drop');
            if (!state.exam?.room) return;
            e.preventDefault();
            const who = dragData(e);
            if (who) {
                moveTo(who, target());
            }
        });
    }
    wireDropTarget(unassigned, () => null);

    function userIdOf(who) {
        if (who.userId !== undefined) return who.userId;
        return store.participant(who.pid)?.user_id ?? null;
    }

    function everyone() {
        return [
            ...state.participants.map((p) => ({ userId: p.user_id, position: p.position, ref: p })),
            ...state.notJoined.map((n) => ({ userId: n.user_id, position: n.position, ref: n })),
        ];
    }

    /** Verschiebt eine Person auf ein Feld (oder in die Leiste: target = null); Belegte tauschen. */
    async function moveTo(who, target) {
        const room = state.exam?.room;
        const userId = userIdOf(who);
        if (!room || userId === null) return;
        const all = everyone();
        const mover = all.find((x) => x.userId === userId);
        if (!mover) return;
        const same = (a, b) => (a === null && b === null) || (a && b && a.col === b.col && a.row === b.row);
        if (same(mover.position, target)) return;

        const changes = [{ user_id: userId, col: target?.col ?? null, row: target?.row ?? null }];
        if (target) {
            const occupant = all.find((x) => x.userId !== userId && x.position && x.position.col === target.col && x.position.row === target.row);
            if (occupant) {
                changes.push({ user_id: occupant.userId, col: mover.position?.col ?? null, row: mover.position?.row ?? null });
            }
        }
        // Sofort anzeigen, der nächste Abruf bestätigt oder korrigiert.
        for (const change of changes) {
            const person = all.find((x) => x.userId === change.user_id);
            if (person) person.ref.position = change.col === null ? null : { col: change.col, row: change.row };
        }
        update(false);

        const res = await post(`${state.boot.api_base}/sitzplan`, { positions: changes });
        if (!res.ok) {
            toast(`Sitzplan nicht gespeichert: ${res.error}`, 'error');
            handlers.refresh();
        }
    }

    function moveByKey(who, key) {
        const room = state.exam?.room;
        if (!room) return;
        const userId = userIdOf(who);
        const me = everyone().find((x) => x.userId === userId);
        if (!me) return;
        let target;
        if (!me.position) {
            target = firstFreeCell();
        } else {
            const d = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[key];
            if (!d) return;
            target = { col: me.position.col + d[0], row: me.position.row + d[1] };
        }
        if (!target || target.col < 0 || target.row < 0 || target.col >= room.cols || target.row >= room.rows) return;
        moveTo({ userId }, target).then(() => {
            const node = tiles.get(store.state.participants.find((p) => p.user_id === userId)?.id) ?? ghosts.get(userId);
            node?.focus();
        });
    }

    function firstFreeCell() {
        const room = state.exam.room;
        const taken = new Set(everyone().filter((x) => x.position).map((x) => `${x.position.col}:${x.position.row}`));
        for (let row = 0; row < room.rows; row++) {
            for (let col = 0; col < room.cols; col++) {
                if (!taken.has(`${col}:${row}`)) return { col, row };
            }
        }
        return null;
    }

    // ---- Aufbau ----
    function rebuildGrid(room) {
        const key = room ? `${room.id}:${room.cols}x${room.rows}` : 'auto';
        if (key === gridKey) return;
        gridKey = key;
        cells = new Map();
        grid.replaceChildren();
        if (!room) {
            grid.className = 'pult-grid is-auto';
            grid.style.gridTemplateColumns = '';
            return;
        }
        grid.className = 'pult-grid is-room';
        grid.style.gridTemplateColumns = `repeat(${room.cols}, minmax(150px, 1fr))`;
        for (let row = 0; row < room.rows; row++) {
            for (let col = 0; col < room.cols; col++) {
                const cell = h('div', { class: 'pult-cell', dataset: { col, row }, 'aria-label': `Platz Spalte ${col + 1}, Reihe ${row + 1}` });
                wireDropTarget(cell, () => ({ col, row }));
                cells.set(`${col}:${row}`, cell);
                grid.append(cell);
            }
        }
    }

    /** Hängt Knoten in gewünschter Reihenfolge an, ohne unveränderte neu einzufügen (erhält den Fokus). */
    function place(parent, nodes) {
        const current = [...parent.children];
        if (current.length === nodes.length && current.every((n, i) => n === nodes[i])) return;
        let cursor = parent.firstChild;
        for (const node of nodes) {
            if (node === cursor) {
                cursor = cursor.nextSibling;
            } else {
                parent.insertBefore(node, cursor);
            }
        }
        while (cursor) {
            const next = cursor.nextSibling;
            cursor.remove();
            cursor = next;
        }
    }

    function update(fresh = true) {
        if (fresh) receivedAt = Date.now();
        const exam = state.exam;
        if (!exam) return;
        const room = exam.room;
        rebuildGrid(room);

        // Kacheln anlegen/aktualisieren
        const alive = new Set();
        const total = state.participants[0]?.progress.total ?? 0;
        for (const p of state.participants) {
            alive.add(p.id);
            let tile = tiles.get(p.id);
            if (!tile) {
                tile = createTile(tileHandlers);
                tiles.set(p.id, tile);
            }
            tile.draggable = Boolean(room);
            updateTile(tile, p, {
                selected: store.isSelected(p.id),
                alarm: (state.alarmUntil.get(p.id) ?? 0) > Date.now(),
                nowMs: store.now() - (Date.now() - receivedAt),
                receivedAt,
                total,
            });
        }
        for (const [id, tile] of tiles) {
            if (!alive.has(id)) {
                tile.remove();
                tiles.delete(id);
            }
        }
        const aliveGhosts = new Set();
        for (const n of state.notJoined) {
            aliveGhosts.add(n.user_id);
            if (!ghosts.has(n.user_id)) {
                ghosts.set(n.user_id, createGhost(n, tileHandlers));
            }
            ghosts.get(n.user_id).draggable = Boolean(room);
        }
        for (const [id, ghost] of ghosts) {
            if (!aliveGhosts.has(id)) {
                ghost.remove();
                ghosts.delete(id);
            }
        }

        // Anordnung
        const inStrip = [];
        if (room) {
            const byCell = new Map([...cells.keys()].map((k) => [k, []]));
            const put = (node, pos) => {
                const key = pos ? `${pos.col}:${pos.row}` : null;
                if (key && byCell.has(key) && byCell.get(key).length === 0) {
                    byCell.get(key).push(node);
                } else {
                    inStrip.push(node);
                }
            };
            state.participants.forEach((p) => put(tiles.get(p.id), p.position));
            state.notJoined.forEach((n) => put(ghosts.get(n.user_id), n.position));
            for (const [key, nodes] of byCell) {
                place(cells.get(key), nodes);
            }
            hint.textContent = `Raum ${room.name}: Kacheln auf freie oder belegte Plätze ziehen (oder mit Alt und Pfeiltasten verschieben).`;
        } else {
            place(grid, state.participants.map((p) => tiles.get(p.id)));
            state.notJoined.forEach((n) => inStrip.push(ghosts.get(n.user_id)));
            hint.textContent = 'Dieser Klausur ist kein Raum zugeordnet. Die Kacheln stehen alphabetisch; für einen Sitzplan wähle in den Klausur-Einstellungen einen Raum.';
        }
        place(strip, inStrip);
        const unassignedCount = inStrip.length;
        unassigned.hidden = unassignedCount === 0;
        stripTitle.textContent = room
            ? `Nicht zugeordnet (${unassignedCount})`
            : `Noch nicht beigetreten (${unassignedCount})`;
        el.classList.toggle('is-empty', state.participants.length === 0 && state.notJoined.length === 0);
    }

    /** Jede Sekunde: Restzeiten und "gespeichert vor …" weiterzählen. */
    function tick() {
        for (const tile of tiles.values()) {
            updateTileClock(tile);
        }
    }

    return { el, update, tick, tiles };
}
