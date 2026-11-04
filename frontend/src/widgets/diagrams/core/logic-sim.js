/**
 * Simulation von Schaltnetzen im Browser (Gegenstück zu src/Services/Diagram/LogicSimulator.php).
 * Rein, ohne DOM. Schaltung: { components: [{id, type, label, n?}], wires: [{id, from:{component,pin}, to:{component,pin}}] }
 */

export const GATES = ['AND', 'OR', 'NOT', 'NAND', 'NOR', 'XOR', 'XNOR'];

/** Anzahl der Eingangspins einer Komponente. */
export function pinCount(comp) {
    if (comp.type === 'NOT' || comp.type === 'OUTPUT') {
        return 1;
    }
    if (comp.type === 'INPUT') {
        return 0;
    }
    const n = Number(comp.n ?? 2);

    return Math.max(2, Math.min(4, Number.isFinite(n) ? Math.round(n) : 2));
}

/** Wert eines Gatters aus seinen Eingangswerten. */
export function gateValue(type, values) {
    const v = values.map((x) => (x ? 1 : 0));
    const any = v.includes(1);
    const all = v.length > 0 && !v.includes(0);
    const odd = v.reduce((a, b) => a + b, 0) % 2 === 1;
    switch (type) {
        case 'AND': return all ? 1 : 0;
        case 'NAND': return all ? 0 : 1;
        case 'OR': return any ? 1 : 0;
        case 'NOR': return any ? 0 : 1;
        case 'XOR': return odd ? 1 : 0;
        case 'XNOR': return odd ? 0 : 1;
        case 'NOT': return v[0] === 1 ? 0 : 1;
        default: return 0;
    }
}

/** Alle Zeilenschlüssel 00, 01, 10, 11, ... (erster Eingang = höchstwertiges Bit). */
export function rowKeys(n) {
    const keys = [];
    for (let i = 0; i < 2 ** n; i++) {
        keys.push(i.toString(2).padStart(n, '0'));
    }

    return keys;
}

/**
 * Wertet alle Komponenten aus.
 * @param {{components: any[], wires: any[]}} circuit
 * @param {Record<string, number>} inputs Eingangsname => 0|1
 * @returns {{values: Map<string, number>, outputs: Record<string, number>, cyclic: Set<string>,
 *            open: Set<string>, errors: string[]}}
 *   values: Wert am Ausgangspin jeder Komponente (bei Zyklen fehlt der Eintrag);
 *   open: Schlüssel "id.inK" der nicht verbundenen Eingangspins.
 */
export function evaluate(circuit, inputs) {
    const comps = new Map();
    for (const c of circuit.components ?? []) {
        comps.set(c.id, c);
    }
    const source = new Map();
    for (const w of circuit.wires ?? []) {
        const key = `${w.to?.component}.${w.to?.pin}`;
        if (comps.has(w.from?.component) && comps.has(w.to?.component) && !source.has(key)) {
            source.set(key, w.from.component);
        }
    }

    const values = new Map();
    const visiting = new Set();
    const cyclic = new Set();
    const open = new Set();

    const valueOf = (id) => {
        if (values.has(id)) {
            return values.get(id);
        }
        if (visiting.has(id)) {
            cyclic.add(id);
            return 0;
        }
        visiting.add(id);
        const c = comps.get(id);
        let result;
        if (c.type === 'INPUT') {
            result = inputs[c.label] ? 1 : 0;
        } else {
            const pins = [];
            for (let i = 0; i < pinCount(c); i++) {
                const from = source.get(`${id}.in${i}`);
                if (from === undefined) {
                    open.add(`${id}.in${i}`);
                    pins.push(0);
                } else {
                    pins.push(valueOf(from));
                }
            }
            result = c.type === 'OUTPUT' ? (pins[0] ?? 0) : gateValue(c.type, pins);
        }
        visiting.delete(id);
        if (!cyclic.size) {
            values.set(id, result);
        }

        return result;
    };

    for (const id of comps.keys()) {
        valueOf(id);
    }
    const errors = [];
    const outputs = {};
    if (cyclic.size > 0) {
        errors.push('Die Schaltung enthält eine Rückkopplung (Zyklus). Das ist in einem Schaltnetz nicht erlaubt.');
        values.clear();
    } else {
        for (const c of comps.values()) {
            if (c.type === 'OUTPUT') {
                outputs[c.label] = values.get(c.id) ?? 0;
            }
        }
    }

    return { values, outputs, cyclic, open, errors };
}

/**
 * Wahrheitstabelle: { "00": { X: 1 }, ... }; bei Zyklen leer, dann steht der Fehler in errors.
 */
export function truthTable(circuit, inputNames, outputNames) {
    const table = {};
    const errors = [];
    for (const key of rowKeys(inputNames.length)) {
        const inputs = {};
        inputNames.forEach((name, i) => { inputs[name] = Number(key[i]); });
        const result = evaluate(circuit, inputs);
        if (result.errors.length > 0) {
            return { table: {}, errors: result.errors };
        }
        table[key] = {};
        for (const out of outputNames) {
            table[key][out] = result.outputs[out] ?? 0;
        }
    }

    return { table, errors };
}

/** Vergleicht zwei Wahrheitstabellen zellenweise: Anteil gleicher Zellen von expected. */
export function compareTables(expected, actual) {
    let total = 0;
    let right = 0;
    for (const [key, row] of Object.entries(expected)) {
        for (const [out, value] of Object.entries(row)) {
            total += 1;
            if (actual?.[key]?.[out] !== undefined && Number(actual[key][out]) === Number(value)) {
                right += 1;
            }
        }
    }

    return total === 0 ? 0 : right / total;
}
