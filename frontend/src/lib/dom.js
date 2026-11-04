/**
 * Kleine DOM-Helfer ohne Framework.
 *
 *   h('div', {class: 'card', onclick: fn, dataset: {id: 3}}, 'Text', childNode)
 *
 * Texte werden immer als Textknoten eingefügt (kein innerHTML), damit keine
 * Inhalte von Schüler:innen als HTML interpretiert werden. Für vom Server
 * bereinigtes HTML (Aufgabentexte) gibt es setTrustedHtml().
 */

export function h(tag, attrs = {}, ...children) {
    const el = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs ?? {})) {
        if (value === undefined || value === null || value === false) {
            continue;
        }
        if (key === 'class' || key === 'className') {
            el.className = Array.isArray(value) ? value.filter(Boolean).join(' ') : value;
        } else if (key === 'dataset') {
            Object.assign(el.dataset, value);
        } else if (key === 'style' && typeof value === 'object') {
            Object.assign(el.style, value);
        } else if (key.startsWith('on') && typeof value === 'function') {
            el.addEventListener(key.slice(2).toLowerCase(), value);
        } else if (value === true) {
            el.setAttribute(key, '');
        } else {
            el.setAttribute(key, String(value));
        }
    }
    append(el, children);
    return el;
}

function append(el, children) {
    for (const child of children.flat(Infinity)) {
        if (child === null || child === undefined || child === false) {
            continue;
        }
        el.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
}

/** Ersetzt den Inhalt eines Elements. */
export function replace(el, ...children) {
    el.replaceChildren();
    append(el, children);
    return el;
}

/**
 * Setzt HTML, das der Server bereits bereinigt hat (Markdown-Rendering der
 * Aufgabentexte). NIE mit Eingaben von Schüler:innen aufrufen.
 */
export function setTrustedHtml(el, html) {
    el.innerHTML = html;
    return el;
}

export const qs = (sel, root = document) => root.querySelector(sel);
export const qsa = (sel, root = document) => [...root.querySelectorAll(sel)];

/** Entprellt eine Funktion (z. B. Autosave nach Tippen). */
export function debounce(fn, ms) {
    let t = null;
    const wrapped = (...a) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...a), ms);
    };
    wrapped.flush = (...a) => {
        clearTimeout(t);
        fn(...a);
    };
    wrapped.cancel = () => clearTimeout(t);
    return wrapped;
}

/** Formatiert Millisekunden als H:MM:SS bzw. MM:SS. */
export function formatDuration(ms) {
    const total = Math.max(0, Math.round(ms / 1000));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = total % 60;
    const pad = (n) => String(n).padStart(2, '0');
    return hours > 0 ? `${hours}:${pad(minutes)}:${pad(seconds)}` : `${pad(minutes)}:${pad(seconds)}`;
}

/** Kurzer Hinweis unten rechts (gleiches Aussehen wie Flash-Meldungen). */
export function toast(message, type = 'info', ms = 5000) {
    let stack = document.querySelector('.flash-stack');
    if (!stack) {
        stack = h('div', { class: 'flash-stack' });
        document.body.append(stack);
    }
    const el = h('div', { class: `alert alert-${type}`, role: 'status' }, message);
    stack.append(el);
    setTimeout(() => el.remove(), ms);
    return el;
}

/**
 * Inline-Icon aus dem Sprite (/assets/img/icons.svg). Statt Emoji verwenden.
 *   btn.prepend(icon('lock'))
 * Dekorativ (aria-hidden); Icon-Knöpfe brauchen ein aria-label.
 */
export function icon(name, className = '') {
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('class', `icon${className ? ` ${className}` : ''}`);
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    const use = document.createElementNS(ns, 'use');
    const base = (document.querySelector('meta[name="base-url"]')?.content ?? '').replace(/\/$/, '');
    use.setAttribute('href', `${base}/assets/img/icons.svg#i-${String(name).replace(/[^a-z0-9-]/g, '')}`);
    svg.append(use);
    return svg;
}
