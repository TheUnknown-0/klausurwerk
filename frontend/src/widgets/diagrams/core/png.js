/**
 * PNG-Vorschau aus einem SVG (für Korrektur und PDF). Ergebnis: Data-URL, höchstens 2 MB.
 */

export const MAX_PNG_CHARS = 2 * 1024 * 1024;

const loadImage = (src) =>
    new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('Bild konnte nicht gerendert werden.'));
        img.src = src;
    });

/**
 * Rendert ein eigenständiges SVG-Element (mit width/height) in ein PNG.
 * @param {SVGSVGElement} svg
 * @param {number} width
 * @param {number} height
 * @param {{background?: string, scales?: number[], before?: (ctx: CanvasRenderingContext2D, scale: number) => Promise<void>}} [opts]
 *   before: zeichnet vorab etwas auf die Leinwand (z. B. ein Hintergrundbild)
 * @returns {Promise<string|null>}
 */
export async function svgToPng(svg, width, height, opts = {}) {
    try {
        const clone = svg.cloneNode(true);
        clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        clone.setAttribute('width', String(width));
        clone.setAttribute('height', String(height));
        const xml = new XMLSerializer().serializeToString(clone);
        const img = await loadImage(`data:image/svg+xml;charset=utf-8,${encodeURIComponent(xml)}`);
        for (const scale of opts.scales ?? [2, 1.5, 1, 0.75, 0.5]) {
            const w = Math.max(1, Math.round(width * scale));
            const h = Math.max(1, Math.round(height * scale));
            if (w > 4096 || h > 4096) {
                continue;
            }
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = opts.background ?? '#ffffff';
            ctx.fillRect(0, 0, w, h);
            if (opts.before) {
                await opts.before(ctx, scale);
            }
            ctx.drawImage(img, 0, 0, w, h);
            const url = canvas.toDataURL('image/png');
            if (url.length <= MAX_PNG_CHARS) {
                return url;
            }
        }
    } catch {
        // Vorschau ist nur eine Zugabe; ohne sie bleibt die Antwort gültig.
    }

    return null;
}
