/**
 * Darstellung der Java-GUI im Browser (Fensterbereich im Code-Widget).
 *
 *   const view = createGuiView(container, { onEvent(ev) { ... } });
 *   view.apply(frames);      // Frames aus gui_frames (Reihenfolge beibehalten)
 *   view.reset();            // neuer Lauf
 *   view.finish();           // Programm beendet: Fenster bleibt als Bild stehen, Eingaben aus
 *   view.destroy();
 *
 * Ereignisse (gehen als JSON-Zeile an die Standardeingabe des Programms):
 *   { e: 'klick', id }  { e: 'text', id, wert }  { e: 'enter', id, wert }
 *   { e: 'maus', id, x, y }  { e: 'taste', id, taste }  { e: 'zu' }
 */
import { h } from '../lib/dom.js';
import { applyFrame, createGuiState } from './state.js';

const TEXT_DEBOUNCE_MS = 150;

/** Zeichnet eine Befehlsliste (siehe state.js) auf einen 2D-Kontext. */
export function drawCommands(ctx, cmds) {
    for (const cmd of cmds) {
        switch (cmd[0]) {
            case 'l':
                ctx.beginPath();
                ctx.moveTo(cmd[1], cmd[2]);
                ctx.lineTo(cmd[3], cmd[4]);
                ctx.strokeStyle = cmd[5];
                ctx.lineWidth = cmd[6];
                ctx.stroke();
                break;
            case 'r':
                if (cmd[7]) {
                    ctx.fillStyle = cmd[5];
                    ctx.fillRect(cmd[1], cmd[2], cmd[3], cmd[4]);
                } else {
                    ctx.strokeStyle = cmd[5];
                    ctx.lineWidth = cmd[6];
                    ctx.strokeRect(cmd[1], cmd[2], cmd[3], cmd[4]);
                }
                break;
            case 'k':
                ctx.beginPath();
                ctx.arc(cmd[1], cmd[2], cmd[3], 0, Math.PI * 2);
                if (cmd[6]) {
                    ctx.fillStyle = cmd[4];
                    ctx.fill();
                } else {
                    ctx.strokeStyle = cmd[4];
                    ctx.lineWidth = cmd[5];
                    ctx.stroke();
                }
                break;
            case 't':
                ctx.fillStyle = cmd[4];
                ctx.font = `${cmd[5]}px sans-serif`;
                ctx.fillText(cmd[3], cmd[1], cmd[2]);
                break;
            default:
        }
    }
}

export function createGuiView(container, { onEvent = () => {} } = {}) {
    let state = createGuiState();
    let finished = false;
    const nodes = new Map(); // id -> { el, typ, canvas? }
    const timers = new Map(); // id -> Zeitgeber für Texteingaben

    const title = h('span', { class: 'kwgui-title' });
    const note = h('span', { class: 'kwgui-note', role: 'status' });
    const bar = h('div', { class: 'kwgui-bar' }, h('span', { class: 'kwgui-dots', 'aria-hidden': 'true' }, h('i'), h('i'), h('i')), title, note);
    const stage = h('div', { class: 'kwgui-window', role: 'group', 'aria-label': 'Fenster des Programms' });
    const scroller = h('div', { class: 'kwgui-scroller' }, stage);
    const empty = h('p', { class: 'kwgui-empty' }, 'Noch kein Fenster. Das Programm zeigt es an, sobald es läuft.');
    const root = h('div', { class: 'kwgui', hidden: false }, bar, scroller, empty);
    container.append(root);
    stage.hidden = true;
    bar.hidden = true;

    const send = (ev) => {
        if (!finished && !state.closed) {
            onEvent(ev);
        }
    };

    function styleOf(comp, el) {
        el.style.left = `${comp.x}px`;
        el.style.top = `${comp.y}px`;
        el.style.width = `${comp.b}px`;
        el.style.height = `${comp.h}px`;
        el.style.display = comp.sichtbar ? '' : 'none';
        el.style.color = comp.farbe || '';
        el.style.backgroundColor = comp.typ === 'flaeche' ? '' : (comp.hintergrund || '');
        el.style.fontSize = comp.schrift ? `${comp.schrift}px` : '';
    }

    function create(comp) {
        let el;
        const node = { typ: comp.typ };
        if (comp.typ === 'knopf') {
            el = h('button', { type: 'button', class: 'kwgui-c kwgui-knopf btn', onclick: () => send({ e: 'klick', id: comp.id }) });
        } else if (comp.typ === 'beschriftung') {
            el = h('div', { class: 'kwgui-c kwgui-beschriftung' });
        } else if (comp.typ === 'textfeld') {
            el = h('input', { type: 'text', class: 'kwgui-c kwgui-textfeld input', autocomplete: 'off', spellcheck: 'false', 'aria-label': 'Eingabefeld' });
            el.addEventListener('input', () => {
                clearTimeout(timers.get(comp.id));
                timers.set(comp.id, setTimeout(() => {
                    timers.delete(comp.id);
                    send({ e: 'text', id: comp.id, wert: el.value });
                }, TEXT_DEBOUNCE_MS));
            });
            el.addEventListener('keydown', (ev) => {
                if (ev.key === 'Enter') {
                    clearTimeout(timers.get(comp.id));
                    timers.delete(comp.id);
                    send({ e: 'text', id: comp.id, wert: el.value });
                    send({ e: 'enter', id: comp.id, wert: el.value });
                }
            });
        } else {
            const canvas = h('canvas', { class: 'kwgui-c kwgui-flaeche', width: comp.b, height: comp.h, tabindex: '0', role: 'img', 'aria-label': 'Zeichenfläche' });
            canvas.addEventListener('pointerdown', (ev) => {
                const cur = state.comps.get(comp.id);
                if (!cur?.maus) return;
                const r = canvas.getBoundingClientRect();
                const x = Math.round((ev.clientX - r.left) * (canvas.width / (r.width || 1)));
                const y = Math.round((ev.clientY - r.top) * (canvas.height / (r.height || 1)));
                canvas.focus();
                send({ e: 'maus', id: comp.id, x, y });
            });
            canvas.addEventListener('keydown', (ev) => {
                const cur = state.comps.get(comp.id);
                if (!cur?.tasten || ev.ctrlKey || ev.metaKey || ev.altKey) return;
                ev.preventDefault();
                send({ e: 'taste', id: comp.id, taste: ev.key });
            });
            el = canvas;
            node.canvas = canvas;
        }
        node.el = el;
        stage.append(el);
        nodes.set(comp.id, node);
        return node;
    }

    function redraw(comp, node) {
        const ctx = node.canvas.getContext('2d');
        ctx.clearRect(0, 0, node.canvas.width, node.canvas.height);
        drawCommands(ctx, state.draws.get(comp.id) ?? []);
    }

    function update(id) {
        const comp = state.comps.get(id);
        if (!comp) return;
        const node = nodes.get(id) ?? create(comp);
        const el = node.el;
        styleOf(comp, el);
        if (comp.typ === 'knopf' || comp.typ === 'beschriftung') {
            el.textContent = comp.text;
        }
        if (comp.typ === 'knopf' || comp.typ === 'textfeld') {
            el.disabled = !comp.aktiv || finished;
        }
        if (comp.typ === 'textfeld') {
            // Eigene Eingaben nicht überschreiben, solange eine Meldung an das Programm noch aussteht.
            if (el.value !== comp.text && !timers.has(id)) {
                el.value = comp.text;
            }
            el.placeholder = comp.hinweis;
        }
        if (comp.typ === 'flaeche') {
            if (node.canvas.width !== comp.b || node.canvas.height !== comp.h) {
                node.canvas.width = comp.b;
                node.canvas.height = comp.h;
                redraw(comp, node);
            }
            node.canvas.style.backgroundColor = comp.hintergrund || '#ffffff';
            node.canvas.setAttribute('aria-label', 'Zeichenfläche');
        }
    }

    function showWindow() {
        const w = state.window;
        if (!w) return;
        stage.hidden = false;
        bar.hidden = false;
        empty.hidden = true;
        title.textContent = w.titel;
        stage.style.width = `${w.breite}px`;
        stage.style.height = `${w.hoehe}px`;
        stage.style.backgroundColor = w.farbe;
    }

    function apply(frames) {
        for (const frame of Array.isArray(frames) ? frames : []) {
            const ch = applyFrame(state, frame);
            if (ch.window) showWindow();
            for (const id of ch.del) {
                nodes.get(id)?.el.remove();
                nodes.delete(id);
            }
            for (const id of ch.set) update(id);
            for (const [id, entry] of ch.draw) {
                const node = nodes.get(id);
                const comp = state.comps.get(id);
                if (!node?.canvas || !comp) continue;
                if (entry.clear) {
                    node.canvas.getContext('2d').clearRect(0, 0, node.canvas.width, node.canvas.height);
                }
                drawCommands(node.canvas.getContext('2d'), entry.cmds);
            }
            if (ch.closed) {
                note.textContent = 'Fenster geschlossen';
                setEnabled(false);
            }
        }
    }

    function setEnabled(flag) {
        for (const [id, node] of nodes) {
            const comp = state.comps.get(id);
            if ((node.typ === 'knopf' || node.typ === 'textfeld') && comp) {
                node.el.disabled = !flag || !comp.aktiv;
            }
        }
    }

    return {
        element: root,
        apply,
        get hasWindow() {
            return state.window !== null;
        },
        reset() {
            for (const t of timers.values()) clearTimeout(t);
            timers.clear();
            for (const node of nodes.values()) node.el.remove();
            nodes.clear();
            state = createGuiState();
            finished = false;
            stage.hidden = true;
            bar.hidden = true;
            empty.hidden = false;
            note.textContent = '';
        },
        finish() {
            if (finished) return;
            finished = true;
            if (state.window && !state.closed) {
                note.textContent = 'Programm beendet';
            }
            setEnabled(false);
        },
        destroy() {
            for (const t of timers.values()) clearTimeout(t);
            root.remove();
        },
    };
}
