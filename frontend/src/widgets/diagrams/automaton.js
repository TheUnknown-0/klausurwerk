/**
 * Endlicher Automat, DEA/NEA (Antworttyp "automaton"). Format: docs/DATENFORMATE.md, 2.13.
 * x/y sind die Mittelpunkte der Zustände. Schüler:innen können Wörter schrittweise
 * ausprobieren (config.allow_simulation, Standard an).
 */
import { h, icon } from '../../lib/dom.js';
import { registerAnswerWidget } from '../../lib/widgets.js';
import { arrowShape, bbox, circleEdge, circleLoop, endAngle, freePosition, newId } from './core/geometry.js';
import { boardWidget } from './core/widget.js';
import { bool, line, num, rows, uniqueId } from './core/model-util.js';
import { checkInput, field, hintText, panel, smallButton, textInput, withIcon, withIconAfter } from './core/form.js';
import { EPS, dfaIssues, run } from './core/automaton-sim.js';
import { INK, PAPER, SOFT, path, polygon, s, text } from './core/svg.js';

export const R = 28;
const SIM_FILL = '#fde68a';

/** Alphabet aus der Konfiguration (Liste einzelner Zeichen) oder null = beliebig. */
const alphabetOf = (config) => (Array.isArray(config?.alphabet) && config.alphabet.length > 0 ? config.alphabet.map(String) : null);

export function normalizeAutomaton(content, config = {}) {
    const alphabet = alphabetOf(config);
    const ids = new Set();
    let hasInitial = false;
    const states = rows(content?.states, 40).map((st) => {
        const initial = bool(st.initial) && !hasInitial;
        hasInitial = hasInitial || initial;

        return { id: uniqueId(st.id, ids, 'q'), name: line(st.name, 12), x: num(st.x), y: num(st.y), initial, accepting: bool(st.accepting) };
    });
    const stateIds = new Set(states.map((st) => st.id));
    const tIds = new Set();
    const transitions = [];
    for (const t of rows(content?.transitions, 200)) {
        if (!stateIds.has(t.from) || !stateIds.has(t.to)) {
            continue;
        }
        const symbols = [];
        for (const sym of Array.isArray(t.symbols) ? t.symbols : []) {
            const v = typeof sym === 'string' ? sym : '';
            const ok = v === EPS ? (alphabet === null || config.allow_epsilon === true) : ([...v].length === 1 && (alphabet === null || alphabet.includes(v)));
            if (ok && !symbols.includes(v)) {
                symbols.push(v);
            }
        }
        if (symbols.length > 0) {
            transitions.push({ id: uniqueId(t.id, tIds, 't'), from: t.from, to: t.to, symbols });
        }
    }

    return { states, transitions };
}

const nameOf = (model, id) => {
    const st = model.states.find((x) => x.id === id);

    return st ? st.name || st.id : '?';
};

/** Geometrie einer Kante inkl. Bogen, wenn es die Gegenrichtung gibt. */
export function transitionGeometry(model, t) {
    const a = model.states.find((x) => x.id === t.from);
    const b = model.states.find((x) => x.id === t.to);
    if (!a || !b) {
        return null;
    }
    if (a.id === b.id) {
        return circleLoop({ x: a.x, y: a.y }, R);
    }
    const reverse = model.transitions.some((o) => o.from === t.to && o.to === t.from);

    return circleEdge({ x: a.x, y: a.y }, { x: b.x, y: b.y }, R, reverse ? 24 : 0);
}

function drawTransition(model, t, rc) {
    const geo = transitionGeometry(model, t);
    if (!geo) {
        return;
    }
    const g = s('g', { class: 'dg-edge' });
    g.append(s('path', { d: geo.d, fill: 'none', stroke: 'transparent', 'stroke-width': 14, class: 'dg-hit' }));
    g.append(path(geo.d, { class: 'dg-shape' }));
    g.append(polygon(arrowShape('filled', geo.end, geo.angle, 10), { class: 'dg-shape', fill: INK }));
    const label = text(t.symbols.join(', '), geo.label.x, geo.label.y, { size: 14, weight: 'bold' });
    label.setAttribute('stroke', '#ffffff');
    label.setAttribute('stroke-width', '3');
    label.setAttribute('paint-order', 'stroke');
    g.append(label);
    rc.mark(g, t.id, `Übergang von ${nameOf(model, t.from)} nach ${nameOf(model, t.to)} bei ${t.symbols.join(', ')}`);
    rc.layer.append(g);
}

function drawState(model, st, rc) {
    const g = s('g', { class: 'dg-node' });
    const active = rc.board?.simSet?.has(st.id);
    if (st.initial) {
        const tip = { x: st.x - R, y: st.y };
        g.append(path(`M${tip.x - 36} ${tip.y}L${tip.x - 9} ${tip.y}`));
        g.append(polygon(arrowShape('filled', tip, 0, 10), { fill: INK }));
    }
    g.append(s('circle', { cx: st.x, cy: st.y, r: R, fill: active ? SIM_FILL : PAPER, stroke: INK, 'stroke-width': 1.6, class: 'dg-shape' }));
    if (st.accepting) {
        g.append(s('circle', { cx: st.x, cy: st.y, r: R - 5, fill: 'none', stroke: INK, 'stroke-width': 1.4 }));
    }
    g.append(text(st.name || '?', st.x, st.y, { size: 14, weight: 'bold', fill: st.name ? INK : SOFT }));
    rc.mark(g, st.id, `Zustand ${st.name || st.id}${st.initial ? ', Startzustand' : ''}${st.accepting ? ', Endzustand' : ''}`);
    rc.layer.append(g);
}

const stateRect = (st) => ({ x: st.x - R - 44, y: st.y - R * 2.9, w: R * 2 + 52, h: R * 3.9 + 8 });

function addState(board) {
    board.mutate((m) => {
        const id = newId('q', m.states.map((x) => x.id));
        const at = board.viewCenter();
        const pos = freePosition(m.states.map((st) => ({ x: st.x - R, y: st.y - R, w: 2 * R, h: 2 * R })), { w: 2 * R, h: 2 * R }, { x: at.x - R, y: at.y - R }, 30, 40);
        let n = m.states.length;
        while (m.states.some((x) => x.name === `q${n}`)) {
            n += 1;
        }
        m.states.push({ id, name: `q${n}`, x: pos.x + R, y: pos.y + R, initial: m.states.length === 0, accepting: false });
        board.sel = new Set([id]);
    }, { inspector: true });
    board.refreshInspector();
    board.focusInspector();
}

export const automatonPlugin = {
    name: 'automaton',
    label: 'Endlicher Automat',
    normalize: (content, config) => normalizeAutomaton(content, config),
    ids: (m) => [...m.states.map((x) => x.id), ...m.transitions.map((t) => t.id)],
    movable: (m, id) => m.states.find((x) => x.id === id) ?? null,
    centerOf: (m, id) => {
        const st = m.states.find((x) => x.id === id);

        return st ? { x: st.x, y: st.y } : null;
    },
    bounds: (m) => bbox(m.states.map(stateRect)),
    render(model, rc) {
        model.transitions.forEach((t) => drawTransition(model, t, rc));
        model.states.forEach((st) => drawState(model, st, rc));
    },
    tools() {
        return [
            { id: 'add-state', group: 'Automat', label: '＋ Zustand', title: 'Neuen Zustand einfügen', run: addState },
            {
                id: 'transition', group: 'Automat', label: '→ Übergang', toggle: true, connect: true,
                title: 'Übergang zwischen zwei Zuständen (oder von einem Zustand auf sich selbst)',
                hint: 'Übergang: Klicke den Ausgangszustand, dann den Zielzustand. Für eine Schleife klicke denselben Zustand noch einmal. Tastatur: Zustand wählen, Enter, Ziel wählen, Enter.',
                hintTarget: 'Wähle jetzt den Zielzustand (derselbe Zustand ergibt eine Schleife).',
            },
        ];
    },
    connect(board, from, to) {
        const m = board.model;
        if (!m.states.some((x) => x.id === from) || !m.states.some((x) => x.id === to)) {
            return null;
        }
        const existing = m.transitions.find((t) => t.from === from && t.to === to);
        if (existing) {
            return { error: 'Diesen Übergang gibt es schon. Du kannst seine Symbole ändern.', select: existing.id };
        }
        const alphabet = alphabetOf(board.config) ?? ['a'];
        const used = new Set(m.transitions.filter((t) => t.from === from).flatMap((t) => t.symbols));
        const symbol = alphabet.find((a) => !used.has(a)) ?? alphabet[0];
        const id = newId('t', m.transitions.map((t) => t.id));
        m.transitions.push({ id, from, to, symbols: [symbol] });

        return { ok: true, select: id, message: `Übergang von ${nameOf(m, from)} nach ${nameOf(m, to)} angelegt. Prüfe die Symbole.` };
    },
    remove(model, ids) {
        const set = new Set(ids);
        const before = model.states.length + model.transitions.length;
        model.states = model.states.filter((x) => !set.has(x.id));
        const alive = new Set(model.states.map((x) => x.id));
        model.transitions = model.transitions.filter((t) => !set.has(t.id) && alive.has(t.from) && alive.has(t.to));

        return model.states.length + model.transitions.length < before;
    },
    describe(model, id) {
        const st = model.states.find((x) => x.id === id);
        if (st) {
            return `Zustand ${st.name}${st.initial ? ', Startzustand' : ''}${st.accepting ? ', Endzustand' : ''}`;
        }
        const t = model.transitions.find((x) => x.id === id);

        return t ? `Übergang von ${nameOf(model, t.from)} nach ${nameOf(model, t.to)} bei ${t.symbols.join(', ')}` : '';
    },
    inspector(board) {
        const ids = [...board.sel];
        if (ids.length !== 1) {
            return panel('Eigenschaften', hintText(ids.length > 1
                ? `${ids.length} Elemente ausgewählt. Entf löscht sie, Pfeiltasten verschieben sie.`
                : 'Wähle einen Zustand oder Übergang aus (Klick oder Tab). Der erste Zustand ist automatisch der Startzustand.'));
        }
        const id = ids[0];
        const m = board.model;
        const st = m.states.find((x) => x.id === id);
        if (st) {
            const set = (fn, key, inspector = false) => board.mutate((model) => {
                const x = model.states.find((q) => q.id === id);
                if (x) {
                    fn(x, model);
                }
            }, { key, inspector });

            return panel('Zustand bearbeiten', h('div', { class: 'dg-fields' },
                field('Name', textInput(st.name, (v) => set((x) => { x.name = line(v, 12); }, `n:${id}`), { maxlength: 12 })),
                checkInput('Startzustand', st.initial, (v) => set((x, model) => {
                    for (const o of model.states) {
                        o.initial = false;
                    }
                    x.initial = v;
                }, null, true)),
                checkInput('Endzustand (doppelter Kreis)', st.accepting, (v) => set((x) => { x.accepting = v; }))));
        }
        const t = m.transitions.find((x) => x.id === id);
        if (!t) {
            return null;
        }
        const alphabet = alphabetOf(board.config) ?? [...new Set([...t.symbols.filter((x) => x !== EPS), 'a', 'b'])];
        const symbols = board.config.allow_epsilon === true || t.symbols.includes(EPS) ? [...alphabet, EPS] : alphabet;
        const box = h('div', { class: 'dg-checks', role: 'group', 'aria-label': 'Symbole des Übergangs' });
        for (const sym of symbols) {
            box.append(checkInput(sym === EPS ? 'ε (leeres Wort)' : sym, t.symbols.includes(sym), (on) => {
                board.mutate((model) => {
                    const x = model.transitions.find((q) => q.id === id);
                    if (!x) {
                        return;
                    }
                    const next = symbols.filter((q) => (q === sym ? on : x.symbols.includes(q)));
                    x.symbols = next.length > 0 ? next : x.symbols;
                }, { inspector: true });
            }));
        }

        return panel(`Übergang ${nameOf(m, t.from)} → ${nameOf(m, t.to)}`, box,
            hintText('Mindestens ein Symbol muss gewählt bleiben. Zum Entfernen den Übergang löschen (Entf).'),
            h('div', { class: 'dg-actions' }, smallButton('⇄ Richtung umkehren', () => board.mutate((model) => {
                const x = model.transitions.find((q) => q.id === id);
                if (x && !model.transitions.some((o) => o.from === x.to && o.to === x.from)) {
                    [x.from, x.to] = [x.to, x.from];
                }
            }, { inspector: true }))));
    },
    mountExtras(board, host) {
        return mountSimulation(board, host);
    },
};

/** Schrittweise Simulation eines Eingabeworts. */
function mountSimulation(board, host) {
    const cfg = board.config;
    const simulate = cfg.allow_simulation !== false;
    const root = h('div', { class: 'dg-sim' });
    host.append(root);
    const word = h('input', { type: 'text', class: 'input dg-input dg-sim-word', 'aria-label': 'Eingabewort', placeholder: 'Wort, z. B. abba', autocomplete: 'off', spellcheck: 'false', maxlength: 40 });
    const out = h('div', { class: 'dg-sim-out', 'aria-live': 'polite' });
    const issues = h('div', { class: 'dg-sim-issues' });
    let pos = null; // Anzahl gelesener Zeichen; null = keine Simulation
    let result = null;

    const chars = () => [...word.value];
    const clearHighlight = () => {
        board.simSet = null;
        board.render();
    };
    const show = () => {
        if (pos === null || result === null) {
            out.replaceChildren();
            clearHighlight();
            return;
        }
        const cs = chars();
        const set = result.sets[pos];
        board.simSet = new Set(set);
        board.render();
        const done = pos >= cs.length;
        const names = set.map((id) => nameOf(board.model, id));
        const accepted = done && result.accepted;
        out.replaceChildren(
            h('div', {}, 'Gelesen: ', h('strong', {}, cs.slice(0, pos).join('') || '(noch nichts)'), ' | Rest: ', h('span', {}, cs.slice(pos).join('') || '(nichts)')),
            h('div', {}, set.length > 0 ? `Aktuell in: ${names.join(', ')}` : 'Kein Zustand mehr aktiv: der Automat bleibt stecken.'),
            done ? h('div', { class: accepted ? 'dg-sim-ok' : 'dg-sim-no' }, accepted ? 'Das Wort wird akzeptiert.' : 'Das Wort wird verworfen.') : null,
        );
    };
    const prepare = () => {
        const alphabet = alphabetOf(cfg);
        const bad = chars().find((c) => alphabet !== null && !alphabet.includes(c));
        if (bad !== undefined) {
            out.replaceChildren(h('div', { class: 'dg-sim-no' }, `Das Zeichen "${bad}" gehört nicht zum Alphabet {${alphabet.join(', ')}}.`));
            pos = null;
            clearHighlight();
            return false;
        }
        result = run(board.model, word.value);
        if (result === null) {
            out.replaceChildren(h('div', { class: 'dg-sim-no' }, 'Es gibt noch keinen Startzustand.'));
            pos = null;
            clearHighlight();
            return false;
        }
        return true;
    };
    const start = () => {
        if (prepare()) {
            pos = 0;
            show();
        }
    };
    const stepFwd = () => {
        if (pos === null && !prepare()) {
            return;
        }
        if (pos === null) {
            pos = 0;
        } else {
            result = run(board.model, word.value);
            pos = Math.min(pos + 1, chars().length);
        }
        show();
    };
    const stepBack = () => {
        if (pos !== null && pos > 0) {
            pos -= 1;
            show();
        }
    };
    const all = () => {
        if (prepare()) {
            pos = chars().length;
            show();
        }
    };
    const reset = () => {
        pos = null;
        result = null;
        out.replaceChildren();
        clearHighlight();
    };
    word.addEventListener('input', reset);
    word.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            start();
        }
    });

    if (simulate) {
        root.append(h('h3', { class: 'dg-panel-title' }, 'Wort ausprobieren'),
            h('div', { class: 'dg-sim-controls' }, word,
                smallButton('Start', start), smallButton(withIcon('chevron-left', 'Zurück'), stepBack), smallButton(withIconAfter('chevron-right', 'Schritt'), stepFwd), smallButton('Ganzes Wort', all), smallButton('Zurücksetzen', reset)),
            out);
    }
    root.append(issues);

    return {
        update() {
            if (pos !== null) {
                result = run(board.model, word.value);
                if (result === null) {
                    reset();
                } else {
                    pos = Math.min(pos, chars().length);
                    show();
                }
            }
            const alphabet = alphabetOf(cfg);
            const list = cfg.kind === 'dfa' && alphabet !== null && board.model.states.length > 0 && !board.readOnly ? dfaIssues(board.model, alphabet) : [];
            issues.replaceChildren(...list.map((text2) => h('div', { class: 'dg-hint-line' }, `Hinweis: ${text2}`)));
        },
        destroy() {
            board.simSet = null;
            root.remove();
        },
    };
}

export const AutomatonWidget = boardWidget(automatonPlugin);
registerAnswerWidget('automaton', AutomatonWidget);

void endAngle;
