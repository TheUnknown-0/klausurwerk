/**
 * Modell der Strukturformel (Antworttyp "structure"), ohne DOM: Normalisierung, Moleküle,
 * Summenformel in Hill-Reihenfolge und Vorlagen. Gegenstück zu StructureType.php und
 * StructureComparer.php. Format: docs/DATENFORMATE.md, 2.15a.
 */
import { bool, isObj, line, num, pick, rows, uniqueId } from './model-util.js';

export const MAX_ATOMS = 150;
export const MAX_BONDS = 200;
export const MAX_TEXTS = 30;
export const MAX_ARROWS = 20;
export const SIDES = ['top', 'right', 'bottom', 'left'];
export const SIDE_LABEL = { top: 'oben', right: 'rechts', bottom: 'unten', left: 'links' };
export const STEREO = ['none', 'wedge', 'dash'];
export const ARROW_KINDS = ['forward', 'equilibrium'];

/** Palette der Werkzeugleiste. */
export const PALETTE = ['C', 'H', 'O', 'N', 'S', 'P', 'Cl', 'Br', 'I', 'F', 'Na', 'K', 'Mg', 'Ca', 'Fe', 'Cu'];

/** Alle gültigen Elementsymbole (Ordnungszahl 1 bis 118). */
export const ELEMENTS = [
    'H', 'He', 'Li', 'Be', 'B', 'C', 'N', 'O', 'F', 'Ne', 'Na', 'Mg', 'Al', 'Si', 'P', 'S', 'Cl', 'Ar', 'K', 'Ca',
    'Sc', 'Ti', 'V', 'Cr', 'Mn', 'Fe', 'Co', 'Ni', 'Cu', 'Zn', 'Ga', 'Ge', 'As', 'Se', 'Br', 'Kr', 'Rb', 'Sr', 'Y', 'Zr',
    'Nb', 'Mo', 'Tc', 'Ru', 'Rh', 'Pd', 'Ag', 'Cd', 'In', 'Sn', 'Sb', 'Te', 'I', 'Xe', 'Cs', 'Ba', 'La', 'Ce', 'Pr', 'Nd',
    'Pm', 'Sm', 'Eu', 'Gd', 'Tb', 'Dy', 'Ho', 'Er', 'Tm', 'Yb', 'Lu', 'Hf', 'Ta', 'W', 'Re', 'Os', 'Ir', 'Pt', 'Au', 'Hg',
    'Tl', 'Pb', 'Bi', 'Po', 'At', 'Rn', 'Fr', 'Ra', 'Ac', 'Th', 'Pa', 'U', 'Np', 'Pu', 'Am', 'Cm', 'Bk', 'Cf', 'Es', 'Fm',
    'Md', 'No', 'Lr', 'Rf', 'Db', 'Sg', 'Bh', 'Hs', 'Mt', 'Ds', 'Rg', 'Cn', 'Nh', 'Fl', 'Mc', 'Lv', 'Ts', 'Og',
];
const ELEMENT_SET = new Set(ELEMENTS);

/** Elementsymbol in der Schreibweise "Xy" oder null. */
export function symbolOf(value) {
    if (typeof value !== 'string') {
        return null;
    }
    const v = value.trim();
    if (!/^[A-Za-z]{1,2}$/.test(v)) {
        return null;
    }
    const s = v[0].toUpperCase() + v.slice(1).toLowerCase();

    return ELEMENT_SET.has(s) ? s : null;
}

const int = (v, min, max, fallback) => {
    const n = Number(v);

    return Number.isFinite(n) ? Math.max(min, Math.min(max, Math.round(n))) : fallback;
};

export const emptyStructure = () => ({ atoms: [], bonds: [], texts: [], arrows: [] });

/** Bereinigt einen Inhalt (tolerant). Ohne gespeicherte Antwort gilt die Startzeichnung der Konfiguration. */
export function normalizeStructure(content, config = null) {
    if (config?.start && isObj(config.start) && (!isObj(content) || !Object.hasOwn(content, 'atoms'))) {
        return normalizeStructure(config.start, null);
    }
    const c = isObj(content) ? content : {};
    const ids = new Set();
    const atoms = [];
    for (const a of rows(c.atoms, MAX_ATOMS)) {
        const el = symbolOf(a.el);
        if (el === null) {
            continue;
        }
        const pairs = [];
        for (const side of Array.isArray(a.pairs) ? a.pairs : []) {
            if (SIDES.includes(side) && !pairs.includes(side)) {
                pairs.push(side);
            }
        }
        atoms.push({
            id: uniqueId(a.id, ids, 'a'), el, x: num(a.x), y: num(a.y), charge: int(a.charge, -2, 3, 0), pairs, radicals: int(a.radicals, 0, 4, 0),
        });
    }
    const atomIds = new Set(atoms.map((a) => a.id));
    const bonds = [];
    const seen = new Set();
    for (const b of rows(c.bonds, MAX_BONDS)) {
        if (!atomIds.has(b.a) || !atomIds.has(b.b) || b.a === b.b) {
            continue;
        }
        const key = [b.a, b.b].sort().join('|');
        if (seen.has(key)) {
            continue;
        }
        seen.add(key);
        const order = int(b.order, 1, 3, 1);
        bonds.push({ id: uniqueId(b.id, ids, 'b'), a: b.a, b: b.b, order, stereo: order === 1 ? pick(b.stereo, STEREO, 'none') : 'none' });
    }
    const texts = [];
    for (const t of rows(c.texts, MAX_TEXTS)) {
        const text = line(t.text, 80);
        if (text !== '') {
            texts.push({ id: uniqueId(t.id, ids, 't'), x: num(t.x), y: num(t.y), text });
        }
    }
    const arrows = rows(c.arrows, MAX_ARROWS).map((r) => ({
        id: uniqueId(r.id, ids, 'r'), x: num(r.x), y: num(r.y), len: int(r.len, 40, 400, 100), kind: pick(r.kind, ARROW_KINDS, 'forward'),
    }));

    return { atoms, bonds, texts, arrows };
}

/** Normalisiert die Konfiguration der Aufgabe (Startzeichnung, Elektronenpaare bewerten). */
export function normalizeStructureConfig(config) {
    const c = isObj(config) ? config : {};
    let start = isObj(c.start) ? normalizeStructure(c.start, null) : null;
    if (start && start.atoms.length === 0 && start.texts.length === 0 && start.arrows.length === 0) {
        start = null;
    }

    return { score_pairs: bool(c.score_pairs), start };
}

// ---------------------------------------------------------------------------
// Moleküle und Summenformel
// ---------------------------------------------------------------------------

const SUB = '₀₁₂₃₄₅₆₇₈₉';

/** Zahl mit Unicode-Tiefzahlen (12 wird ₁₂). */
export const subscript = (n) => String(n).replace(/\d/g, (d) => SUB[Number(d)]);

/** Summenformel in Hill-Reihenfolge: mit Kohlenstoff C, H, dann alphabetisch; sonst alphabetisch. */
export function hillFormula(counts) {
    const symbols = Object.keys(counts).sort();
    let order = symbols;
    if (counts.C) {
        order = ['C', ...(counts.H ? ['H'] : []), ...symbols.filter((e) => e !== 'C' && e !== 'H')];
    }

    return order.map((e) => e + (counts[e] > 1 ? subscript(counts[e]) : '')).join('');
}

/** Ladung als Text: ungeladen, +, −, 2−, 3+ ... */
export function chargeText(q) {
    if (q === 0) {
        return 'ungeladen';
    }

    return `${Math.abs(q) === 1 ? '' : Math.abs(q)}${q > 0 ? '+' : '−'}`;
}

/**
 * Zusammenhängende Moleküle, von links nach rechts sortiert.
 * @returns {{atomIds: string[], bondIds: string[], counts: Record<string, number>, charge: number, formula: string}[]}
 */
export function molecules(model) {
    const parent = new Map(model.atoms.map((a) => [a.id, a.id]));
    const find = (x) => {
        let cur = x;
        while (parent.get(cur) !== cur) {
            parent.set(cur, parent.get(parent.get(cur)));
            cur = parent.get(cur);
        }

        return cur;
    };
    for (const b of model.bonds) {
        if (parent.has(b.a) && parent.has(b.b)) {
            parent.set(find(b.a), find(b.b));
        }
    }
    const groups = new Map();
    for (const a of model.atoms) {
        const root = find(a.id);
        if (!groups.has(root)) {
            groups.set(root, { atomIds: [], bondIds: [], counts: {}, charge: 0, minX: Infinity, minY: Infinity });
        }
        const g = groups.get(root);
        g.atomIds.push(a.id);
        g.counts[a.el] = (g.counts[a.el] ?? 0) + 1;
        g.charge += a.charge;
        g.minX = Math.min(g.minX, a.x);
        g.minY = Math.min(g.minY, a.y);
    }
    for (const b of model.bonds) {
        if (parent.has(b.a)) {
            groups.get(find(b.a))?.bondIds.push(b.id);
        }
    }

    return [...groups.values()]
        .sort((p, q) => Math.floor(p.minX / 40) - Math.floor(q.minX / 40) || p.minY - q.minY)
        .map((g) => ({ atomIds: g.atomIds, bondIds: g.bondIds, counts: g.counts, charge: g.charge, formula: hillFormula(g.counts) }));
}

/** Ziffern direkt nach einem Buchstaben oder einer Klammer werden in freien Beschriftungen tiefgestellt. */
export const chemText = (text) => String(text).replace(/(?<=[A-Za-z)\]])\d+/g, (d) => subscript(d));

// ---------------------------------------------------------------------------
// Vorlagen
// ---------------------------------------------------------------------------

const ring = (r, i, n = 6) => {
    const a = (-90 + (360 / n) * i) * (Math.PI / 180);

    return { x: Math.round(Math.cos(a) * r), y: Math.round(Math.sin(a) * r) };
};

/**
 * Vorlagen als Atome (relative Lage) und Bindungen. attach: Schlüssel des Atoms, das an ein gewähltes Atom
 * gebunden wird (null: Vorlage hat keinen Anschluss).
 */
export function template(name) {
    const atoms = [];
    const bonds = [];
    if (name === 'benzene' || name === 'cyclohexane') {
        for (let i = 0; i < 6; i += 1) {
            const c = ring(52, i);
            atoms.push({ key: `c${i}`, el: 'C', ...c });
            bonds.push([`c${i}`, `c${(i + 1) % 6}`, name === 'benzene' && i % 2 === 0 ? 2 : 1]);
            if (name === 'benzene') {
                atoms.push({ key: `h${i}`, el: 'H', ...ring(92, i) });
                bonds.push([`c${i}`, `h${i}`, 1]);
            } else {
                for (const [k, side] of [['a', -17], ['b', 17]]) {
                    const out = ring(88, i);
                    const t = { x: -Math.sin(((-90 + 60 * i) * Math.PI) / 180), y: Math.cos(((-90 + 60 * i) * Math.PI) / 180) };
                    atoms.push({ key: `h${k}${i}`, el: 'H', x: Math.round(out.x + t.x * side), y: Math.round(out.y + t.y * side) });
                    bonds.push([`c${i}`, `h${k}${i}`, 1]);
                }
            }
        }

        return { atoms, bonds, attach: null };
    }
    if (name === 'methyl') {
        return {
            atoms: [{ key: 'c', el: 'C', x: 0, y: 0 }, { key: 'h1', el: 'H', x: 0, y: -48 }, { key: 'h2', el: 'H', x: 0, y: 48 }, { key: 'h3', el: 'H', x: 48, y: 0 }],
            bonds: [['c', 'h1', 1], ['c', 'h2', 1], ['c', 'h3', 1]],
            attach: 'c',
        };
    }
    if (name === 'hydroxy') {
        return { atoms: [{ key: 'o', el: 'O', x: 0, y: 0 }, { key: 'h', el: 'H', x: 48, y: 0 }], bonds: [['o', 'h', 1]], attach: 'o' };
    }
    if (name === 'carboxy') {
        return {
            atoms: [
                { key: 'c', el: 'C', x: 0, y: 0 }, { key: 'o1', el: 'O', x: 0, y: -50 }, { key: 'o2', el: 'O', x: 0, y: 50 }, { key: 'h', el: 'H', x: 50, y: 50 },
            ],
            bonds: [['c', 'o1', 2], ['c', 'o2', 1], ['o2', 'h', 1]],
            attach: 'c',
        };
    }

    return null;
}

export const TEMPLATES = [
    ['benzene', 'Benzolring', 'Benzolring mit sechs Wasserstoffatomen (C₆H₆)'],
    ['cyclohexane', 'Cyclohexan', 'Cyclohexan mit zwölf Wasserstoffatomen (C₆H₁₂)'],
    ['methyl', 'CH₃', 'Methylgruppe (hängt an das gewählte Atom an)'],
    ['hydroxy', 'OH', 'Hydroxygruppe (hängt an das gewählte Atom an)'],
    ['carboxy', 'COOH', 'Carboxygruppe (hängt an das gewählte Atom an)'],
];
