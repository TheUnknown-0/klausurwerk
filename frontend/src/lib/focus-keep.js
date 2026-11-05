/**
 * Fokus über ein Neuzeichnen retten.
 *
 * Wird ein Bereich komplett neu aufgebaut (zum Beispiel das Aufgabenformular nach dem Speichern),
 * verschwindet das Feld, in dem jemand gerade tippt, und der Fokus fällt auf die Seite. captureFocus
 * merkt sich das fokussierte Element als Position unter den bedienbaren Elementen des Bereichs
 * (samt Cursor), restoreFocus setzt es im neuen Aufbau wieder, sofern dort an derselben Stelle ein
 * gleichartiges Element steht.
 */

const FOCUSABLE_TAGS = new Set(['input', 'textarea', 'select', 'button', 'a']);

function isFocusable(el) {
    const tag = el.tagName?.toLowerCase?.() ?? '';
    if (FOCUSABLE_TAGS.has(tag)) {
        return true;
    }
    const editable = el.getAttribute?.('contenteditable');
    if (editable === 'true' || editable === '') {
        return true;
    }
    const tabindex = el.getAttribute?.('tabindex');

    return tabindex !== null && tabindex !== undefined && tabindex !== '-1';
}

/** Alle bedienbaren Elemente unter root in Dokumentreihenfolge. */
export function focusablesIn(root) {
    const out = [];
    const walk = (node) => {
        for (const child of node.children ?? []) {
            if (isFocusable(child)) {
                out.push(child);
            }
            walk(child);
        }
    };
    walk(root);

    return out;
}

const labelOf = (el) => el.getAttribute?.('aria-label') ?? el.getAttribute?.('name') ?? '';

/**
 * Merkt sich das fokussierte Element unter root. Gibt null zurück, wenn der Fokus woanders liegt.
 *
 * @param {Element} root
 * @param {{ extra?: (el: Element) => unknown }} [opts] extra: zusätzlicher Zustand (z. B. Auswahl im Code-Editor)
 */
export function captureFocus(root, { extra } = {}) {
    const el = globalThis.document?.activeElement;
    if (!el || el === root || !root.contains(el)) {
        return null;
    }
    const index = focusablesIn(root).indexOf(el);
    if (index < 0) {
        return null;
    }

    return {
        index,
        tag: el.tagName,
        label: labelOf(el),
        start: typeof el.selectionStart === 'number' ? el.selectionStart : null,
        end: typeof el.selectionEnd === 'number' ? el.selectionEnd : null,
        direction: el.selectionDirection ?? undefined,
        scrollTop: el.scrollTop ?? 0,
        extra: extra ? extra(el) : undefined,
    };
}

/**
 * Setzt den gemerkten Fokus im neu aufgebauten root wieder. Steht an der Stelle ein anderes
 * Element (anderer Typ oder andere Beschriftung), bleibt der Fokus, wo er ist.
 *
 * @param {Element} root
 * @param {ReturnType<typeof captureFocus>} saved
 * @param {{ extra?: (el: Element, data: unknown) => void }} [opts]
 * @returns {boolean} ob der Fokus gesetzt wurde
 */
export function restoreFocus(root, saved, { extra } = {}) {
    if (!saved) {
        return false;
    }
    const el = focusablesIn(root)[saved.index];
    if (!el || el.tagName !== saved.tag || labelOf(el) !== saved.label || el.disabled === true) {
        return false;
    }
    el.focus?.({ preventScroll: true });
    if (saved.start !== null && typeof el.setSelectionRange === 'function') {
        try {
            el.setSelectionRange(saved.start, saved.end ?? saved.start, saved.direction);
        } catch {
            // Feldtypen ohne Cursor (z. B. number) werfen hier; der Fokus reicht dann.
        }
    }
    if (saved.scrollTop) {
        el.scrollTop = saved.scrollTop;
    }
    if (extra && saved.extra !== undefined && saved.extra !== null) {
        extra(el, saved.extra);
    }

    return true;
}
