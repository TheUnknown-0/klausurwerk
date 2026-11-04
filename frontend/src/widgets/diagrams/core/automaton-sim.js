/**
 * Simulation endlicher Automaten im Browser (Gegenstück zu AutomatonSimulator.php).
 * Rein, ohne DOM. Automat: { states: [{id, name, initial, accepting}], transitions: [{id, from, to, symbols}] }
 */

export const EPS = 'ε';

/** Zustand -> Symbol -> Liste der Ziele. */
export function transitionTable(automaton) {
    const table = new Map();
    for (const t of automaton.transitions ?? []) {
        for (const symbol of t.symbols ?? []) {
            if (!table.has(t.from)) {
                table.set(t.from, new Map());
            }
            const row = table.get(t.from);
            if (!row.has(symbol)) {
                row.set(symbol, []);
            }
            if (!row.get(symbol).includes(t.to)) {
                row.get(symbol).push(t.to);
            }
        }
    }

    return table;
}

export const initialState = (automaton) => (automaton.states ?? []).find((s) => s.initial)?.id ?? null;

/** ε-Hülle (sortierte Liste von Zustands-IDs). */
export function closure(ids, table) {
    const seen = new Set(ids);
    const queue = [...ids];
    while (queue.length > 0) {
        const s = queue.pop();
        for (const next of table.get(s)?.get(EPS) ?? []) {
            if (!seen.has(next)) {
                seen.add(next);
                queue.push(next);
            }
        }
    }

    return [...seen].sort();
}

/** Menge der aktuellen Zustände zu Beginn; null ohne Startzustand. */
export function startSet(automaton, table = transitionTable(automaton)) {
    const start = initialState(automaton);

    return start === null ? null : closure([start], table);
}

/** Ein Schritt: Zeichen lesen. */
export function step(set, symbol, table) {
    const next = new Set();
    for (const s of set) {
        for (const target of table.get(s)?.get(symbol) ?? []) {
            next.add(target);
        }
    }

    return closure([...next], table);
}

export function isAccepting(set, automaton) {
    const accepting = new Set((automaton.states ?? []).filter((s) => s.accepting).map((s) => s.id));

    return set.some((id) => accepting.has(id));
}

/**
 * Lauf über ein Wort.
 * @returns {{sets: string[][], accepted: boolean, stuckAt: number|null} | null} sets[0] = Startmenge;
 *   stuckAt = Index des Zeichens, nach dem keine Zustände mehr aktiv sind.
 */
export function run(automaton, word) {
    const table = transitionTable(automaton);
    const start = startSet(automaton, table);
    if (start === null) {
        return null;
    }
    const sets = [start];
    let stuckAt = null;
    let current = start;
    [...word].forEach((ch, i) => {
        current = step(current, ch, table);
        sets.push(current);
        if (current.length === 0 && stuckAt === null) {
            stuckAt = i;
        }
    });

    return { sets, accepted: isAccepting(current, automaton), stuckAt };
}

export const accepts = (automaton, word) => run(automaton, word)?.accepted ?? false;

/** Hinweise zu einem DEA (nicht deterministisch, nicht vollständig, kein Start). */
export function dfaIssues(automaton, alphabet) {
    const issues = [];
    if (initialState(automaton) === null) {
        issues.push('Es gibt keinen Startzustand.');
    }
    const table = transitionTable(automaton);
    const nondet = [];
    const incomplete = [];
    for (const s of automaton.states ?? []) {
        const name = s.name || s.id;
        if (table.get(s.id)?.has(EPS)) {
            nondet.push(`${name} (ε-Übergang)`);
        }
        for (const a of alphabet) {
            const targets = table.get(s.id)?.get(a) ?? [];
            if (targets.length > 1) {
                nondet.push(`${name} bei "${a}"`);
            } else if (targets.length === 0) {
                incomplete.push(`${name} bei "${a}"`);
            }
        }
    }
    if (nondet.length > 0) {
        issues.push(`Der Automat ist nicht deterministisch: ${nondet.join(', ')}.`);
    }
    if (incomplete.length > 0) {
        const more = incomplete.length > 8 ? ' ...' : '';
        issues.push(`Der Automat ist nicht vollständig, es fehlen Übergänge: ${incomplete.slice(0, 8).join(', ')}${more}.`);
    }

    return issues;
}

/**
 * Erzeugt Testwörter aus einem Automaten: kurze akzeptierte und verworfene Wörter
 * (Breitensuche über Wörter nach Länge, dann alphabetisch).
 */
export function generateWords(automaton, alphabet, { maxLength = 6, perKind = 8 } = {}) {
    const accept = [];
    const reject = [];
    if (initialState(automaton) === null || alphabet.length === 0) {
        return { accept, reject };
    }
    let level = [''];
    for (let len = 0; len <= maxLength && (accept.length < perKind || reject.length < perKind); len++) {
        for (const word of level) {
            const ok = accepts(automaton, word);
            if (ok && accept.length < perKind) {
                accept.push(word);
            } else if (!ok && reject.length < perKind) {
                reject.push(word);
            }
        }
        if (level.length * alphabet.length > 400) {
            // zu viele Wörter: nur noch eine Auswahl weiter verlängern
            level = level.filter((_, i) => i % Math.ceil((level.length * alphabet.length) / 400) === 0);
        }
        level = level.flatMap((w) => alphabet.map((a) => w + a));
    }

    return { accept, reject };
}
