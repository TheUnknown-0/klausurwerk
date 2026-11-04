/**
 * Nachbearbeitung von serverseitig gerendertem Markdown (Aufgabentexte,
 * Erwartungshorizont, Hilfsmittel):
 *   - Formeln: $…$ (inline) und $$…$$ (Block) werden mit KaTeX gesetzt.
 *     Der Server liefert sie als <span class="math-inline">…</span> bzw.
 *     <div class="math-block">…</div> mit dem Roh-TeX als Text.
 *   - Code-Blöcke werden eingefärbt.
 *
 *   enhanceRichText(element)
 *
 * Das KaTeX-Stylesheet liegt unter /assets/vendor/katex/katex.min.css und wird
 * bei Bedarf einmalig nachgeladen.
 */
import katex from 'katex';
import { highlightCodeBlocks } from './code-editor.js';
import { url } from './api.js';

let katexCssLoaded = false;

function ensureKatexCss() {
    if (katexCssLoaded) {
        return;
    }
    katexCssLoaded = true;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = url('/assets/vendor/katex/katex.min.css');
    document.head.append(link);
}

export function enhanceRichText(root) {
    if (!root) {
        return;
    }
    const maths = root.querySelectorAll('.math-inline, .math-block');
    if (maths.length > 0) {
        ensureKatexCss();
    }
    for (const el of maths) {
        if (el.dataset.rendered === '1') {
            continue;
        }
        try {
            katex.render(el.textContent ?? '', el, {
                displayMode: el.classList.contains('math-block'),
                throwOnError: false,
                trust: false,
                strict: 'ignore',
            });
            el.dataset.rendered = '1';
        } catch {
            // Fehlerhafte Formel bleibt als Text sichtbar.
        }
    }
    highlightCodeBlocks(root);
}
