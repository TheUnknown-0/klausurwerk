/**
 * Minimale DOM-Attrappe für Rauchtests der Diagramm-Widgets ohne Browser.
 * Unterstützt nur, was die Widgets brauchen: Elemente, Attribute, Klassen, Ereignisse (mit Blasen),
 * Fokus. Layout gibt es nicht (getBoundingClientRect liefert eine feste Größe).
 */

class StubNode {
    constructor() {
        this.parentNode = null;
        this.childNodes = [];
    }

    get children() {
        return this.childNodes.filter((c) => c instanceof StubElement);
    }

    get firstChild() {
        return this.childNodes[0] ?? null;
    }

    append(...nodes) {
        for (const n of nodes.flat()) {
            if (n === null || n === undefined) {
                continue;
            }
            const node = typeof n === 'string' ? new StubText(n) : n;
            node.parentNode?.removeChild?.(node);
            node.parentNode = this;
            this.childNodes.push(node);
        }
    }

    prepend(...nodes) {
        const list = nodes.map((n) => (typeof n === 'string' ? new StubText(n) : n));
        for (const node of list) {
            node.parentNode?.removeChild?.(node);
            node.parentNode = this;
        }
        this.childNodes.unshift(...list);
    }

    removeChild(node) {
        const i = this.childNodes.indexOf(node);
        if (i >= 0) {
            this.childNodes.splice(i, 1);
            node.parentNode = null;
        }
    }

    replaceChildren(...nodes) {
        for (const c of this.childNodes) {
            c.parentNode = null;
        }
        this.childNodes = [];
        this.append(...nodes);
    }

    remove() {
        this.parentNode?.removeChild(this);
    }

    contains(node) {
        for (let cur = node; cur; cur = cur.parentNode) {
            if (cur === this) {
                return true;
            }
        }

        return false;
    }

    get textContent() {
        return this.childNodes.map((c) => c.textContent).join('');
    }

    set textContent(v) {
        this.replaceChildren(String(v));
    }
}

class StubText extends StubNode {
    constructor(text) {
        super();
        this.data = text;
    }

    get textContent() {
        return this.data;
    }

    set textContent(v) {
        this.data = String(v);
    }
}

class StubElement extends StubNode {
    constructor(tag) {
        super();
        this.tagName = tag.toUpperCase();
        this.localName = tag;
        this.attrs = new Map();
        this.listeners = new Map();
        this.style = {};
        this.dataset = {};
        this._classes = new Set();
        this.hidden = false;
        this.disabled = false;
        this.checked = false;
        this._value = undefined;
    }

    get classList() {
        const self = this;

        return {
            add: (...c) => c.forEach((x) => self._classes.add(x)),
            remove: (...c) => c.forEach((x) => self._classes.delete(x)),
            toggle: (c, force) => {
                const on = force ?? !self._classes.has(c);
                if (on) {
                    self._classes.add(c);
                } else {
                    self._classes.delete(c);
                }

                return on;
            },
            contains: (c) => self._classes.has(c),
        };
    }

    get className() {
        return [...this._classes].join(' ');
    }

    set className(v) {
        this._classes = new Set(String(v).split(/\s+/).filter(Boolean));
    }

    setAttribute(name, value) {
        if (name === 'class') {
            this.className = value;
        }
        this.attrs.set(name, String(value));
    }

    getAttribute(name) {
        if (name === 'class') {
            return this.className || null;
        }

        return this.attrs.has(name) ? this.attrs.get(name) : null;
    }

    removeAttribute(name) {
        this.attrs.delete(name);
    }

    get attributes() {
        return [...this.attrs].map(([name, value]) => ({ name, value }));
    }

    get value() {
        return this._value !== undefined ? this._value : (this.attrs.get('value') ?? '');
    }

    set value(v) {
        this._value = String(v);
    }

    addEventListener(type, fn) {
        if (!this.listeners.has(type)) {
            this.listeners.set(type, []);
        }
        this.listeners.get(type).push(fn);
    }

    focus() {
        document.activeElement = this;
        this.dispatch('focusin', {});
    }

    blur() {
        if (document.activeElement === this) {
            document.activeElement = document.body;
        }
    }

    click() {
        this.dispatch('click', {});
    }

    getBoundingClientRect() {
        return { left: 0, top: 0, width: 800, height: 500, right: 800, bottom: 500 };
    }

    setPointerCapture() {}

    releasePointerCapture() {}

    /** Löst ein Ereignis aus und lässt es nach oben blasen. */
    dispatch(type, props = {}) {
        const event = {
            type,
            target: this,
            defaultPrevented: false,
            clientX: 0,
            clientY: 0,
            preventDefault() {
                this.defaultPrevented = true;
            },
            stopPropagation() {
                this.stopped = true;
            },
            ...props,
        };
        for (let cur = this; cur && !event.stopped; cur = cur.parentNode) {
            for (const fn of cur.listeners?.get(type) ?? []) {
                fn(event);
            }
        }

        return event;
    }

    /** Alle Nachkommen (Tiefensuche). */
    find(predicate) {
        const out = [];
        const walk = (el) => {
            for (const c of el.children) {
                if (predicate(c)) {
                    out.push(c);
                }
                walk(c);
            }
        };
        walk(this);

        return out;
    }

    findByText(text, tag = null) {
        return this.find((e) => (tag === null || e.localName === tag) && e.textContent.trim() === text);
    }
}

export function installDom() {
    const body = new StubElement('body');
    globalThis.Node = StubNode;
    globalThis.document = {
        body,
        activeElement: body,
        createElement: (tag) => new StubElement(tag),
        createElementNS: (ns, tag) => new StubElement(tag),
        createTextNode: (t) => new StubText(t),
    };
    globalThis.window = globalThis;
    // meta-Tags für lib/api.js (nur falls benötigt)
    document.querySelector = () => null;
    document.getElementById = () => null;

    return body;
}

export const pointer = (el, type, x, y, extra = {}) => el.dispatch(type, { clientX: x, clientY: y, pointerId: 1, pointerType: 'mouse', button: 0, ...extra });

export const key = (el, k, extra = {}) => el.dispatch('keydown', { key: k, ...extra });

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
