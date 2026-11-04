/**
 * Rückgängig/Wiederholen über Momentaufnahmen (JSON-Strings). Reine Logik, ohne DOM.
 *
 *   const h = new History(model);
 *   h.record(model);                // neuer Zustand
 *   h.record(model, 'text:c1', now) // gleicher Schlüssel kurz hintereinander: zusammenfassen (Tippen)
 *   const previous = h.undo();      // Modell oder null
 */
export class History {
    /**
     * @param {any} initial Ausgangszustand
     * @param {{limit?: number, coalesceMs?: number}} [opts]
     */
    constructor(initial, opts = {}) {
        this.limit = opts.limit ?? 100;
        this.coalesceMs = opts.coalesceMs ?? 1200;
        this.stack = [JSON.stringify(initial)];
        this.index = 0;
        this.lastKey = null;
        this.lastAt = 0;
    }

    get canUndo() {
        return this.index > 0;
    }

    get canRedo() {
        return this.index < this.stack.length - 1;
    }

    /** Aktueller Zustand als Kopie. */
    current() {
        return JSON.parse(this.stack[this.index]);
    }

    /**
     * Hält einen neuen Zustand fest. Gibt false zurück, wenn sich nichts geändert hat.
     * @param {any} model
     * @param {string|null} [key] gleicher Schlüssel innerhalb von coalesceMs ersetzt den letzten Schritt
     * @param {number} [now]
     */
    record(model, key = null, now = Date.now()) {
        const snapshot = JSON.stringify(model);
        if (snapshot === this.stack[this.index]) {
            return false;
        }
        const merge = key !== null && key === this.lastKey && now - this.lastAt <= this.coalesceMs && this.index > 0
            && this.index === this.stack.length - 1;
        if (merge) {
            this.stack[this.index] = snapshot;
        } else {
            this.stack.length = this.index + 1;
            this.stack.push(snapshot);
            this.index += 1;
            if (this.stack.length > this.limit) {
                this.stack.shift();
                this.index -= 1;
            }
        }
        this.lastKey = key;
        this.lastAt = now;

        return true;
    }

    undo() {
        if (!this.canUndo) {
            return null;
        }
        this.index -= 1;
        this.lastKey = null;

        return this.current();
    }

    redo() {
        if (!this.canRedo) {
            return null;
        }
        this.index += 1;
        this.lastKey = null;

        return this.current();
    }

    /** Setzt den Verlauf zurück (z. B. nach setContent von außen). */
    reset(model) {
        this.stack = [JSON.stringify(model)];
        this.index = 0;
        this.lastKey = null;
    }
}
