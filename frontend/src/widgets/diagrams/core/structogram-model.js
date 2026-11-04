/**
 * Modell des Struktogramms (Nassi-Shneiderman, DIN 66261): reine Baumoperationen ohne DOM.
 * Format: docs/DATENFORMATE.md, 2.9.
 *
 * Eine Sequenz wird über einen Schlüssel adressiert:
 *   "root"          oberste Sequenz
 *   "<id>/then"     Ja-Zweig einer Verzweigung, "<id>/else" Nein-Zweig
 *   "<id>/body"     Rumpf einer Schleife
 *   "<id>/case/<i>" i-ter Fall einer Mehrfachauswahl
 */

export const BLOCK_TYPES = {
    statement: { label: 'Anweisung', field: 'text', placeholder: 'Anweisung' },
    call: { label: 'Aufruf', field: 'text', placeholder: 'Aufruf' },
    if: { label: 'Verzweigung', field: 'cond', placeholder: 'Bedingung' },
    switch: { label: 'Mehrfachauswahl', field: 'expr', placeholder: 'Ausdruck' },
    while: { label: 'Solange-Schleife', field: 'cond', placeholder: 'Bedingung' },
    do_while: { label: 'Wiederhole-bis', field: 'cond', placeholder: 'Bedingung' },
    for: { label: 'Zählschleife', field: 'head', placeholder: 'Schleifenkopf' },
    break: { label: 'Abbruch', field: 'text', placeholder: 'Abbruch' },
};

export const MAX_NODES = 300;
export const MAX_DEPTH = 14;

export const emptySeq = () => ({ type: 'sequence', children: [] });

/** Textfeld eines Blocks (text, cond, expr oder head). */
export const textField = (block) => BLOCK_TYPES[block.type]?.field ?? 'text';

/** Alle Sequenzen eines Blocks als [Schlüsselteil, Sequenz]. */
export function sequencesOf(block) {
    switch (block.type) {
        case 'if':
            return [['then', block.then], ['else', block.else]];
        case 'switch':
            return block.cases.map((c, i) => [`case/${i}`, c.body]);
        case 'while':
        case 'do_while':
        case 'for':
            return [['body', block.body]];
        default:
            return [];
    }
}

/** Neuer Block mit leeren Rümpfen und einer unbenutzten Kennung. */
export function newBlock(type, usedIds) {
    const used = usedIds instanceof Set ? usedIds : new Set(usedIds);
    let n = 1;
    while (used.has(`b${n}`)) {
        n += 1;
    }
    const id = `b${n}`;
    switch (type) {
        case 'if': return { id, type, cond: '', then: emptySeq(), else: emptySeq() };
        case 'switch': return { id, type, expr: '', cases: [{ label: '1', body: emptySeq() }, { label: 'sonst', body: emptySeq() }] };
        case 'while': return { id, type, cond: '', body: emptySeq() };
        case 'do_while': return { id, type, cond: '', body: emptySeq() };
        case 'for': return { id, type, head: '', body: emptySeq() };
        case 'call': return { id, type, text: '' };
        case 'break': return { id, type, text: '' };
        default: return { id, type: 'statement', text: '' };
    }
}

/** Geht alle Blöcke in Lesereihenfolge durch: fn(block, seqKey, index, depth). */
export function walk(root, fn) {
    const visit = (seq, key, depth) => {
        seq.children.forEach((block, index) => {
            fn(block, key, index, depth);
            for (const [part, child] of sequencesOf(block)) {
                visit(child, `${block.id}/${part}`, depth + 1);
            }
        });
    };
    visit(root, 'root', 0);
}

export function collectIds(root) {
    const ids = new Set();
    walk(root, (b) => ids.add(b.id));

    return ids;
}

/** IDs in Lesereihenfolge (für Pfeiltasten-Navigation). */
export function flatOrder(root) {
    const order = [];
    walk(root, (b) => order.push(b.id));

    return order;
}

/** Sequenz zu einem Schlüssel oder null. */
export function getSeq(root, key) {
    if (key === 'root') {
        return root;
    }
    const [id, part, idx] = key.split('/');
    let found = null;
    walk(root, (b) => {
        if (b.id === id) {
            found = b;
        }
    });
    if (!found) {
        return null;
    }
    if (part === 'case') {
        return found.cases?.[Number(idx)]?.body ?? null;
    }

    return found[part] ?? null;
}

/** Findet einen Block samt Elternsequenz. */
export function findBlock(root, id) {
    let result = null;
    walk(root, (block, key, index, depth) => {
        if (block.id === id) {
            result = { block, key, index, depth, seq: getSeq(root, key) };
        }
    });

    return result;
}

export function countBlocks(root) {
    let n = 0;
    walk(root, () => { n += 1; });

    return n;
}

/** Fügt einen Block ein. false, wenn Ziel fehlt oder das Limit erreicht ist. */
export function insertBlock(root, key, index, block) {
    const seq = getSeq(root, key);
    if (!seq || countBlocks(root) >= MAX_NODES) {
        return false;
    }
    seq.children.splice(Math.max(0, Math.min(index, seq.children.length)), 0, block);

    return true;
}

/** Entfernt einen Block (samt Inhalt); gibt ihn zurück. */
export function removeBlock(root, id) {
    const hit = findBlock(root, id);
    if (!hit) {
        return null;
    }
    hit.seq.children.splice(hit.index, 1);

    return hit.block;
}

/** Verschiebt einen Block innerhalb seiner Sequenz um delta Plätze. */
export function moveBlock(root, id, delta) {
    const hit = findBlock(root, id);
    if (!hit) {
        return false;
    }
    const to = hit.index + delta;
    if (to < 0 || to >= hit.seq.children.length) {
        return false;
    }
    const [block] = hit.seq.children.splice(hit.index, 1);
    hit.seq.children.splice(to, 0, block);

    return true;
}

/** Hebt einen Block aus seiner Sequenz heraus an die Stelle hinter seinem Elternblock. */
export function outdentBlock(root, id) {
    const hit = findBlock(root, id);
    if (!hit || hit.key === 'root') {
        return false;
    }
    const parentId = hit.key.split('/')[0];
    const parent = findBlock(root, parentId);
    if (!parent) {
        return false;
    }
    hit.seq.children.splice(hit.index, 1);
    parent.seq.children.splice(parent.index + 1, 0, hit.block);

    return true;
}

/** Nimmt einen Block in den vorherigen Block auf (falls dieser Schleife/Verzweigung ist). */
export function indentBlock(root, id) {
    const hit = findBlock(root, id);
    if (!hit || hit.index === 0) {
        return false;
    }
    const prev = hit.seq.children[hit.index - 1];
    const parts = sequencesOf(prev);
    if (parts.length === 0) {
        return false;
    }
    const [, target] = parts[parts.length - 1];
    hit.seq.children.splice(hit.index, 1);
    target.children.push(hit.block);

    return true;
}

/** Entfernt Duplikate, ergänzt fehlende Felder; tolerant gegenüber kaputten Daten. */
export function normalizeStructogram(content) {
    const ids = new Set();
    let budget = MAX_NODES;
    const str = (v, max = 300) => (typeof v === 'string' ? v.slice(0, max) : '');

    const seq = (raw, depth) => {
        const out = emptySeq();
        const list = Array.isArray(raw?.children) ? raw.children : [];
        if (depth > MAX_DEPTH) {
            return out;
        }
        for (const b of list) {
            if (budget <= 0) {
                break;
            }
            const block = blockOf(b, depth);
            if (block) {
                out.children.push(block);
            }
        }

        return out;
    };

    const blockOf = (b, depth) => {
        if (!b || typeof b !== 'object' || !BLOCK_TYPES[b.type]) {
            return null;
        }
        let id = typeof b.id === 'string' && /^[A-Za-z0-9_-]{1,40}$/.test(b.id) ? b.id : null;
        if (id === null || ids.has(id)) {
            id = newBlock('statement', ids).id;
        }
        ids.add(id);
        budget -= 1;
        switch (b.type) {
            case 'if':
                return { id, type: 'if', cond: str(b.cond), then: seq(b.then, depth + 1), else: seq(b.else, depth + 1) };
            case 'switch':
                return {
                    id,
                    type: 'switch',
                    expr: str(b.expr),
                    cases: (Array.isArray(b.cases) ? b.cases : []).slice(0, 12).map((c) => ({ label: str(c?.label, 80), body: seq(c?.body, depth + 1) })),
                };
            case 'for':
                return { id, type: 'for', head: str(b.head), body: seq(b.body, depth + 1) };
            case 'while':
            case 'do_while':
                return { id, type: b.type, cond: str(b.cond), body: seq(b.body, depth + 1) };
            default:
                return { id, type: b.type, text: str(b.text) };
        }
    };

    return { root: seq(content?.root, 0) };
}
