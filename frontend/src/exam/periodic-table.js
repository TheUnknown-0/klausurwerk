/**
 * Hilfsmittel "Periodensystem": Tabelle der Elemente (Daten: /assets/aids/elements.json, erzeugt mit
 * tools/periodensystem.py aus der PubChem-Tabelle), Details zum gewählten Element und ein
 * Molmassen-Rechner ("H2SO4", "Ca(OH)2", "CuSO4·5H2O", auch mit Tiefzahlen H₂O).
 */
import { h } from '../lib/dom.js';

const SUB_DIGITS = { '₀': '0', '₁': '1', '₂': '2', '₃': '3', '₄': '4', '₅': '5', '₆': '6', '₇': '7', '₈': '8', '₉': '9' };
const CHARGE = /[⁰¹²³⁴⁵⁶⁷⁸⁹⁺⁻]+$|\^\d*[+-]$|[+-]$/;

/**
 * Zerlegt eine Summenformel in Elementanzahlen.
 * @param {string} formula
 * @param {Set<string>} [known] bekannte Symbole (für die Fehlermeldung bei Tippfehlern)
 * @returns {Map<string, number>}
 */
export function parseFormula(formula, known = null) {
    let src = String(formula ?? '').trim().replace(/[₀-₉]/g, (d) => SUB_DIGITS[d]).replace(/\s+/g, '');
    src = src.replace(CHARGE, '');
    if (src === '') {
        throw new Error('Gib eine Summenformel ein, z. B. H2SO4.');
    }
    const total = new Map();
    // Hydrate: CuSO4·5H2O, CuSO4*5H2O, CuSO4.5H2O
    for (const part of src.split(/[·•*.]/)) {
        if (part === '') {
            throw new Error('Die Formel ist unvollständig.');
        }
        const m = /^(\d+)(.*)$/.exec(part);
        const factor = m ? Number(m[1]) : 1;
        const body = m ? m[2] : part;
        for (const [el, n] of parseGroup(body, known)) {
            total.set(el, (total.get(el) ?? 0) + n * factor);
        }
    }
    return total;
}

function parseGroup(src, known) {
    let i = 0;
    const readNumber = () => {
        const m = /^\d+/.exec(src.slice(i));
        if (!m) {
            return 1;
        }
        i += m[0].length;
        return Number(m[0]);
    };
    const parse = (closing) => {
        const counts = new Map();
        while (i < src.length) {
            const ch = src[i];
            if (ch === '(' || ch === '[') {
                i += 1;
                const inner = parse(ch === '(' ? ')' : ']');
                const n = readNumber();
                for (const [el, c] of inner) {
                    counts.set(el, (counts.get(el) ?? 0) + c * n);
                }
                continue;
            }
            if (ch === ')' || ch === ']') {
                if (ch !== closing) {
                    throw new Error('Die Klammern passen nicht zusammen.');
                }
                i += 1;
                return counts;
            }
            const m = /^[A-Z][a-z]?/.exec(src.slice(i));
            if (!m) {
                throw new Error(`Unerwartetes Zeichen „${ch}“. Elementsymbole beginnen mit einem Großbuchstaben.`);
            }
            let sym = m[0];
            // "Co" vs. "CO": unbekanntes Zweierkürzel auf ein Zeichen zurückfallen lassen
            if (known && sym.length === 2 && !known.has(sym) && known.has(sym[0])) {
                sym = sym[0];
            }
            if (known && !known.has(sym)) {
                throw new Error(`Unbekanntes Element „${sym}“.`);
            }
            i += sym.length;
            const n = readNumber();
            counts.set(sym, (counts.get(sym) ?? 0) + n);
        }
        if (closing) {
            throw new Error('Eine Klammer wird nicht geschlossen.');
        }
        return counts;
    };
    return parse(null);
}

/**
 * Molmasse in g/mol und Anteile je Element.
 * @param {Map<string, number>} counts
 * @param {Map<string, {m: string}>} bySymbol
 */
export function molarMass(counts, bySymbol) {
    let sum = 0;
    const parts = [];
    for (const [sym, n] of counts) {
        const el = bySymbol.get(sym);
        if (!el) {
            throw new Error(`Unbekanntes Element „${sym}“.`);
        }
        const m = Number(el.m);
        sum += m * n;
        parts.push({ sym, n, mass: m, subtotal: m * n });
    }
    return { total: sum, parts };
}

const fmt = (x, digits = 2) => x.toLocaleString('de-DE', { minimumFractionDigits: digits, maximumFractionDigits: digits });
const fmtMass = (s) => String(s).replace('.', ',');

const CAT_CLASS = {
    Nichtmetall: 'pt-nonmetal', Edelgas: 'pt-noble', Alkalimetall: 'pt-alkali', Erdalkalimetall: 'pt-earth',
    Halbmetall: 'pt-metalloid', Halogen: 'pt-halogen', Metall: 'pt-metal', Übergangsmetall: 'pt-transition',
    Lanthanoid: 'pt-lanthanoid', Actinoid: 'pt-actinoid',
};

/** @param {{dataUrl: string}} o */
export function createPeriodicTable(o) {
    const el = h('div', { class: 'pt' }, h('p', { class: 'w-hint' }, 'Lade ...'));
    let elements = null;
    let bySymbol = null;
    let selected = null;

    const detail = h('div', { class: 'pt-detail', 'aria-live': 'polite' });
    const showDetail = (e) => {
        selected = e;
        detail.replaceChildren(
            h('div', { class: 'pt-detail-sym' }, e.s),
            h('dl', { class: 'pt-detail-list' },
                h('dt', {}, 'Name'), h('dd', {}, e.n),
                h('dt', {}, 'Ordnungszahl'), h('dd', {}, String(e.z)),
                h('dt', {}, 'Atommasse'), h('dd', {}, `${fmtMass(e.m)} u`),
                h('dt', {}, 'Elektronegativität'), h('dd', {}, e.en ? fmtMass(e.en) : 'keine Angabe'),
                h('dt', {}, 'Elektronenkonfiguration'), h('dd', { class: 'mono' }, e.ec),
                h('dt', {}, 'Gruppe'), h('dd', {}, e.cat),
                e.st ? h('dt', {}, 'Bei 25 °C') : null, e.st ? h('dd', {}, e.st) : null,
            ),
        );
        for (const b of el.querySelectorAll('.pt-cell[aria-pressed="true"]')) {
            b.setAttribute('aria-pressed', 'false');
        }
        el.querySelector(`.pt-cell[data-z="${e.z}"]`)?.setAttribute('aria-pressed', 'true');
    };

    const input = h('input', { class: 'input pt-input', type: 'text', placeholder: 'z. B. H2SO4, Ca(OH)2, CuSO4·5H2O', spellcheck: 'false', autocomplete: 'off', 'aria-label': 'Summenformel' });
    const result = h('div', { class: 'pt-result', 'aria-live': 'polite' });
    const calc = () => {
        result.replaceChildren();
        if (!bySymbol || input.value.trim() === '') {
            return;
        }
        try {
            const { total, parts } = molarMass(parseFormula(input.value, new Set(bySymbol.keys())), bySymbol);
            result.append(
                h('div', { class: 'pt-result-total' }, `M = ${fmt(total)} g/mol`),
                h('table', { class: 'pt-result-table' },
                    h('tbody', {}, parts.map((p) => h('tr', {},
                        h('td', {}, p.sym), h('td', {}, `${p.n} × ${fmtMass(p.mass)}`), h('td', {}, `${fmt(p.subtotal)} g/mol`),
                        h('td', { class: 'text-faint' }, `${fmt((p.subtotal / total) * 100, 1)} %`))))),
            );
        } catch (err) {
            result.append(h('p', { class: 'text-faint' }, err.message));
        }
    };
    input.addEventListener('input', calc);

    const render = () => {
        const grid = h('div', { class: 'pt-grid', role: 'grid', 'aria-label': 'Periodensystem' });
        for (const e of elements) {
            const cell = h('button', {
                type: 'button', class: `pt-cell ${CAT_CLASS[e.cat] ?? ''}`, dataset: { z: e.z }, 'aria-pressed': 'false',
                style: { gridRow: String(e.r), gridColumn: String(e.c) }, title: `${e.n}, ${fmtMass(e.m)} u`,
                'aria-label': `${e.n} (${e.s}), Ordnungszahl ${e.z}`,
            },
            h('span', { class: 'pt-z' }, String(e.z)),
            h('span', { class: 'pt-s' }, e.s),
            h('span', { class: 'pt-m' }, fmtMass(Number(e.m).toFixed(e.z > 92 ? 0 : 2))));
            cell.addEventListener('click', () => {
                showDetail(e);
                // Klick ergänzt die Formel im Rechner nicht automatisch; Doppelklick schon.
            });
            cell.addEventListener('dblclick', () => {
                input.value += e.s;
                calc();
            });
            grid.append(cell);
        }
        el.replaceChildren(
            grid,
            detail,
            h('section', { class: 'pt-calc' },
                h('h4', {}, 'Molmasse berechnen'),
                input,
                h('p', { class: 'text-faint text-sm' }, 'Doppelklick auf ein Element hängt sein Symbol an.'),
                result),
        );
        showDetail(selected ?? elements[5]);
    };

    fetch(o.dataUrl, { credentials: 'same-origin' })
        .then((res) => {
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }
            return res.json();
        })
        .then((data) => {
            elements = data;
            bySymbol = new Map(data.map((e) => [e.s, e]));
            render();
        })
        .catch(() => {
            el.replaceChildren(h('p', { class: 'alert alert-error' }, 'Das Periodensystem konnte nicht geladen werden.'));
        });

    return { el, focus: () => input.focus() };
}
