/**
 * Erkennung "interner" Einfügevorgänge (rein, ohne DOM).
 *
 * Die Überwachung merkt sich den Text der letzten Kopier-/Ausschneiden-Vorgänge
 * innerhalb der Klausur. Beim Einfügen ist nur erlaubt, was dort herkam.
 */

/** Vereinheitlicht Zeilenenden (Windows, Mac) auf \n. */
export function normalizeText(text) {
    return String(text ?? '').replace(/\r\n?/g, '\n');
}

export class PasteTracker {
    /** @param {number} [limit] Anzahl gemerkter Kopiervorgänge */
    constructor(limit = 20) {
        this.limit = limit;
        this.copies = [];
    }

    /** Merkt einen intern kopierten Text. */
    remember(text) {
        const t = normalizeText(text);
        if (t === '') {
            return;
        }
        this.copies = this.copies.filter((c) => c !== t);
        this.copies.push(t);
        if (this.copies.length > this.limit) {
            this.copies.shift();
        }
    }

    /** true, wenn der eingefügte Text aus einem internen Kopieren/Ausschneiden stammt. */
    isInternal(pasted) {
        const p = normalizeText(pasted);
        if (p === '') {
            return true;
        }
        const trimmed = p.trim();
        return this.copies.some((c) => c === p || c.trim() === trimmed || c.includes(p));
    }
}

/**
 * Entscheidung für einen Einfügevorgang.
 * @param {'internal'|'block'|'allow_log'} policy
 * @returns {{allow: boolean, event: null|{type: string, details: object}}}
 */
export function decidePaste(policy, tracker, pasted) {
    const length = normalizeText(pasted).length;
    if (policy === 'block') {
        return { allow: false, event: { type: 'paste_blocked', details: { length } } };
    }
    if (tracker.isInternal(pasted)) {
        return { allow: true, event: null };
    }
    if (policy === 'allow_log') {
        return { allow: true, event: { type: 'paste_external', details: { length } } };
    }
    return { allow: false, event: { type: 'paste_blocked', details: { length } } };
}
