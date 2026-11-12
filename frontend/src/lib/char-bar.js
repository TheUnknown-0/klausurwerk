/**
 * Zeichenleiste für Antworten in Sprachen und Naturwissenschaften: Knöpfe für Sonderzeichen
 * (é, ñ, ā, →) und in Chemie/Mathematik ein Hoch-/Tiefstell-Modus, der Ziffern und Ladungen beim
 * Tippen in Unicode-Hoch- bzw. Tiefzeichen verwandelt (H₂O, SO₄²⁻). Die Antwort bleibt reiner Text;
 * Korrektur, Rückgabe und PDF brauchen nichts Besonderes.
 *
 *   const bar = createCharBar({ set: 'fr', getTarget: () => textarea });
 *   container.append(bar.el); bar.attach(textarea); bar.setDisabled(true); bar.destroy();
 *
 * Eingefügt wird mit setRangeText() und einem input-Ereignis, damit das Widget die Änderung wie
 * Tippen bemerkt (Autosave, Wortzähler). Das gilt nicht als Einfügen aus der Zwischenablage.
 */
import { h } from './dom.js';

/** Zeichensätze: Schlüssel => [Bezeichnung, Zeichen]. Gruppen durch '|' getrennt. */
export const CHAR_SETS = {
    de: ['Deutsch', 'ä ö ü Ä Ö Ü ß ẞ | „ “ ‚ ‘ » « | – …'],
    en: ['Englisch', '“ ” ‘ ’ | – … | é è ï | £ € $'],
    fr: ['Französisch', 'é è ê ë à â ç î ï ô û ù ü ÿ œ æ | É È Ê À Ç Î Ô Œ | « » ’ …'],
    es: ['Spanisch', 'á é í ó ú ñ ü | Á É Í Ó Ú Ñ Ü | ¿ ¡ | « » “ ” …'],
    it: ['Italienisch', 'à è é ì ò ù | À È É Ì Ò Ù | « » ’ …'],
    pl: ['Polnisch', 'ą ć ę ł ń ó ś ź ż | Ą Ć Ę Ł Ń Ó Ś Ź Ż | „ ” …'],
    la: ['Latein', 'ā ē ī ō ū ȳ | Ā Ē Ī Ō Ū | ă ĕ ĭ ŏ ŭ | – ⏑ ×'],
    chem: ['Chemie', '→ ⇌ ⇄ ← ↑ ↓ | Δ ° · | δ⁺ δ⁻ e⁻ | ≈ ≙ ± | α β γ λ π'],
    math: ['Mathematik', '² ³ √ π ∞ | ≈ ≠ ≤ ≥ ± · × ÷ | ° Δ α β γ λ μ ω Ω | → ⇒ ⇔ ∈ ∑ ∫'],
};

/** Zeichensätze, die den Hoch-/Tiefstell-Modus anbieten. */
const SCRIPT_SETS = new Set(['chem', 'math']);

const SUP = { 0: '⁰', 1: '¹', 2: '²', 3: '³', 4: '⁴', 5: '⁵', 6: '⁶', 7: '⁷', 8: '⁸', 9: '⁹', '+': '⁺', '-': '⁻', '=': '⁼', '(': '⁽', ')': '⁾', n: 'ⁿ', x: 'ˣ' };
const SUB = { 0: '₀', 1: '₁', 2: '₂', 3: '₃', 4: '₄', 5: '₅', 6: '₆', 7: '₇', 8: '₈', 9: '₉', '+': '₊', '-': '₋', '=': '₌', '(': '₍', ')': '₎', x: 'ₓ' };

/** Wandelt ein getipptes Zeichen im Modus 'sup' oder 'sub' um, sonst null. */
export function scriptChar(ch, mode) {
    const map = mode === 'sup' ? SUP : mode === 'sub' ? SUB : null;
    return map ? (map[ch] ?? null) : null;
}

/** Zeichen eines Satzes als Gruppen von Strings. */
export function charGroups(set) {
    const def = CHAR_SETS[set];
    if (!def) {
        return [];
    }
    return def[1].split('|').map((g) => g.trim().split(/\s+/).filter(Boolean)).filter((g) => g.length > 0);
}

/** Fügt Text an der Cursorposition eines Eingabefelds ein und meldet es wie Tippen. */
export function insertAtCursor(el, text) {
    if (!el || el.readOnly || el.disabled) {
        return;
    }
    const start = el.selectionStart ?? el.value.length;
    const end = el.selectionEnd ?? start;
    const max = Number(el.maxLength);
    if (max > 0 && el.value.length - (end - start) + text.length > max) {
        return;
    }
    el.setRangeText(text, start, end, 'end');
    el.dispatchEvent(new Event('input', { bubbles: true }));
}

/**
 * @param {{set: string, onInsert?: (text: string) => void}} o
 */
export function createCharBar(o) {
    const set = String(o.set ?? '');
    const groups = charGroups(set);
    const withScript = SCRIPT_SETS.has(set);
    if (groups.length === 0 && !withScript) {
        return null;
    }
    let target = null;
    let mode = null; // null | 'sup' | 'sub'
    let disabled = false;
    const targets = new Set();
    const buttons = [];

    const el = h('div', { class: 'char-bar', role: 'toolbar', 'aria-label': `Sonderzeichen ${CHAR_SETS[set]?.[0] ?? ''}`.trim() });

    // Knöpfe dürfen den Fokus nicht aus dem Textfeld ziehen.
    const keepFocus = (ev) => ev.preventDefault();

    let supBtn = null;
    let subBtn = null;
    const setMode = (next) => {
        mode = mode === next ? null : next;
        supBtn?.setAttribute('aria-pressed', String(mode === 'sup'));
        subBtn?.setAttribute('aria-pressed', String(mode === 'sub'));
        el.classList.toggle('is-script', mode !== null);
        target?.focus();
    };
    if (withScript) {
        supBtn = h('button', { type: 'button', class: 'char-btn char-mode', 'aria-pressed': 'false', title: 'Hochstellen (Strg+.): Ziffern und + − werden hochgestellt, z. B. Fe³⁺' }, 'x²');
        subBtn = h('button', { type: 'button', class: 'char-btn char-mode', 'aria-pressed': 'false', title: 'Tiefstellen (Strg+,): Ziffern werden tiefgestellt, z. B. H₂O' }, 'x₂');
        supBtn.addEventListener('mousedown', keepFocus);
        subBtn.addEventListener('mousedown', keepFocus);
        supBtn.addEventListener('click', () => setMode('sup'));
        subBtn.addEventListener('click', () => setMode('sub'));
        buttons.push(supBtn, subBtn);
        el.append(h('span', { class: 'char-group' }, supBtn, subBtn));
    }
    for (const g of groups) {
        const span = h('span', { class: 'char-group' });
        for (const ch of g) {
            const b = h('button', { type: 'button', class: 'char-btn', title: `${ch} einfügen` }, ch);
            b.addEventListener('mousedown', keepFocus);
            b.addEventListener('click', () => {
                if (disabled) {
                    return;
                }
                insertAtCursor(target, ch);
                target?.focus();
                o.onInsert?.(ch);
            });
            buttons.push(b);
            span.append(b);
        }
        el.append(span);
    }

    const onFocus = (ev) => {
        target = ev.currentTarget;
    };
    const onKey = (ev) => {
        if (disabled) {
            return;
        }
        if (withScript && (ev.ctrlKey || ev.metaKey) && !ev.altKey && (ev.key === '.' || ev.key === ',')) {
            ev.preventDefault();
            setMode(ev.key === '.' ? 'sup' : 'sub');
            return;
        }
        if (mode && !ev.ctrlKey && !ev.metaKey && !ev.altKey && ev.key.length === 1) {
            const mapped = scriptChar(ev.key, mode);
            if (mapped) {
                ev.preventDefault();
                insertAtCursor(ev.currentTarget, mapped);
            }
        }
        if (mode && ev.key === 'Escape') {
            setMode(mode);
        }
    };

    return {
        el,
        /** Ein Eingabefeld anschließen (Lückentext: jede Lücke). Das zuletzt fokussierte ist das Ziel. */
        attach(input) {
            if (!input || targets.has(input)) {
                return;
            }
            targets.add(input);
            target ??= input;
            input.addEventListener('focus', onFocus);
            if (withScript) {
                input.addEventListener('keydown', onKey);
            }
        },
        setDisabled(flag) {
            disabled = !!flag;
            el.hidden = disabled;
            if (disabled && mode) {
                setMode(mode);
            }
        },
        get mode() {
            return mode;
        },
        destroy() {
            for (const t of targets) {
                t.removeEventListener('focus', onFocus);
                t.removeEventListener('keydown', onKey);
            }
            targets.clear();
            el.remove();
        },
    };
}

/**
 * Sprache und Zeichenleiste einer Aufgabe: eigene Einstellung, sonst die des Fachs (env.subject).
 * '' heißt "wie das Fach", 'none' heißt "keine".
 */
export function resolveLanguage(task, env) {
    const own = String(task?.config?.lang ?? '');
    return own !== '' ? own : String(env?.subject?.language ?? 'de');
}

export function resolveCharset(task, env) {
    const own = String(task?.config?.chars ?? '');
    const value = own !== '' ? own : String(env?.subject?.charset ?? 'none');
    return value === 'none' ? '' : value;
}

/** Auswahllisten für den Aufgaben-Editor: [Wert, Bezeichnung]. */
export const LANGUAGE_OPTIONS = [
    ['', 'wie das Fach'], ['de', 'Deutsch'], ['en', 'Englisch'], ['fr', 'Französisch'], ['es', 'Spanisch'],
    ['it', 'Italienisch'], ['pl', 'Polnisch'], ['ru', 'Russisch'], ['la', 'Latein'], ['grc', 'Altgriechisch'],
];

export const CHARSET_OPTIONS = [
    ['', 'wie das Fach'], ['none', 'keine'],
    ...Object.entries(CHAR_SETS).map(([key, [label]]) => [key, label]),
];
