/**
 * Freihandzeichnung (Antworttyp "drawing"). Format: docs/DATENFORMATE.md, 2.7.
 * Stift (Farben, Stärken), Radierer (löscht ganze Striche), Text setzen und verschieben,
 * optionales Hintergrundbild aus dem Klausurmaterial, optional Datei-Upload (POST /api/klausur/{id}/datei).
 * Linienzüge werden mit Douglas-Peucker vereinfacht, die Größe ist begrenzt.
 */
import { h, icon } from '../../lib/dom.js';
import { request, url } from '../../lib/api.js';
import { registerAnswerWidget } from '../../lib/widgets.js';
import { clampPoints, distToPolyline, pointsToPath, simplifyPoints, textWidth } from './core/geometry.js';
import { asObject, bool, clone, isObj, multiline, num, rows, uniqueId } from './core/model-util.js';
import { bindUndoKeys, createTracker } from './core/simple.js';
import { svgToPng } from './core/png.js';
import { smallButton, withIcon } from './core/form.js';
import { s, text as svgText } from './core/svg.js';
import { isDeleteKey, isTypingTarget } from '../delete-keys.js';

export const MAX_STROKES = 1500;
export const MAX_POINTS_TOTAL = 40000;
export const MAX_POINTS_PER_STROKE = 4000;
export const MAX_TEXTS = 200;
export const MAX_UPLOADS = 10;

export const COLORS = [
    ['#000000', 'Schwarz'], ['#dc2626', 'Rot'], ['#2563eb', 'Blau'], ['#16a34a', 'Grün'], ['#ea580c', 'Orange'], ['#6b7280', 'Grau'],
];
export const WIDTHS = [[1, 'dünn'], [2, 'mittel'], [4, 'dick'], [8, 'sehr dick']];
const TEXT_SIZE = 16;
const HEX = /^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/;

export function normalizeDrawing(content, config = {}) {
    const width = Number(config.width) > 0 ? Number(config.width) : 900;
    const height = Number(config.height) > 0 ? Number(config.height) : 600;
    let total = 0;
    const strokes = [];
    for (const st of rows(content?.strokes, MAX_STROKES)) {
        const points = [];
        for (const p of Array.isArray(st.points) ? st.points : []) {
            if (Array.isArray(p) && p.length >= 2 && Number.isFinite(Number(p[0])) && Number.isFinite(Number(p[1]))) {
                points.push([Number(p[0]), Number(p[1])]);
                if (points.length >= MAX_POINTS_PER_STROKE) {
                    break;
                }
            }
        }
        if (points.length === 0) {
            continue;
        }
        total += points.length;
        if (total > MAX_POINTS_TOTAL) {
            break;
        }
        strokes.push({
            color: typeof st.color === 'string' && HEX.test(st.color) ? st.color.toLowerCase() : '#000000',
            width: Math.max(1, Math.min(40, Math.round(Number(st.width)) || 2)),
            points: clampPoints(points, width, height),
        });
    }
    const ids = new Set();
    const texts = [];
    for (const t of rows(content?.texts, MAX_TEXTS)) {
        const str = multiline(t.text, 500);
        if (str === '') {
            continue;
        }
        const entry = { id: uniqueId(t.id, ids, 't'), x: num(t.x, 0, width), y: num(t.y, 0, height), text: str };
        if (typeof t.color === 'string' && HEX.test(t.color)) {
            entry.color = t.color.toLowerCase();
        }
        texts.push(entry);
    }
    const uploads = [];
    for (const id of Array.isArray(content?.upload_ids) ? content.upload_ids : []) {
        const n = Number(id);
        if (Number.isInteger(n) && n > 0 && !uploads.includes(n) && uploads.length < MAX_UPLOADS) {
            uploads.push(n);
        }
    }

    return { strokes, texts, upload_ids: uploads };
}

const pointCount = (m) => m.strokes.reduce((a, st) => a + st.points.length, 0);

/** Geometrie eines Textes (für Auswahl und Radierer). */
export function textBox(t) {
    return { x: t.x, y: t.y - TEXT_SIZE, w: Math.max(20, ...t.text.split('\n').map((l) => textWidth(l, TEXT_SIZE))), h: TEXT_SIZE * 1.3 * t.text.split('\n').length + 4 };
}

export const DrawingWidget = {
    mount(container, ctx) {
        const config = asObject(ctx.task?.config);
        const W = Number(config.width) > 0 ? Number(config.width) : 900;
        const H = Number(config.height) > 0 ? Number(config.height) : 600;
        const examId = ctx.env?.examId ?? null;
        const taskId = ctx.task?.id ?? null;
        let readOnly = !!ctx.readOnly;
        let model = normalizeDrawing(ctx.content, config);
        const state = { tool: 'pen', color: COLORS[0][0], width: 2, selected: null, selectedStroke: null, gesture: null };
        const bgUrl = config.background_material_id && examId ? url(`/klausur/${examId}/material/${config.background_material_id}`) : null;
        const uploads = new Map(); // id -> {name, url}

        const exportPng = async () => {
            if (model.strokes.length === 0 && model.texts.length === 0) {
                return null;
            }
            const svg = s('svg', { xmlns: 'http://www.w3.org/2000/svg', width: W, height: H, viewBox: `0 0 ${W} ${H}` }, buildContent(model));
            const before = bgUrl ? async (c2d, scale) => {
                try {
                    const img = await new Promise((resolve, reject) => {
                        const im = new Image();
                        im.onload = () => resolve(im);
                        im.onerror = reject;
                        im.src = bgUrl;
                    });
                    const k = Math.min(W / img.width, H / img.height);
                    const w = img.width * k;
                    const hh = img.height * k;
                    c2d.drawImage(img, ((W - w) / 2) * scale, ((H - hh) / 2) * scale, w * scale, hh * scale);
                } catch {
                    // Hintergrund ist optional
                }
            } : undefined;

            return svgToPng(svg, W, H, { before });
        };
        const tracker = createTracker(ctx, () => clone(model), model, exportPng);

        // ---------- DOM ----------
        const root = h('div', { class: 'w-diagram w-drawing', dataset: { mode: ctx.mode ?? 'exam', type: 'drawing' } });
        const status = h('div', { class: 'sr-only', role: 'status', 'aria-live': 'polite' });
        const strokeLayer = s('g', { class: 'dg-strokes', fill: 'none', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' });
        const hitLayer = s('g', { class: 'dg-stroke-hits' });
        const selLayer = s('g', { class: 'dg-sel', 'pointer-events': 'none' });
        const textLayer = s('g', { class: 'dg-texts' });
        const liveLayer = s('g', { class: 'dg-live', 'pointer-events': 'none', fill: 'none', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' });
        const bgImage = bgUrl ? s('image', { href: bgUrl, x: 0, y: 0, width: W, height: H, preserveAspectRatio: 'xMidYMid meet', 'pointer-events': 'none' }) : null;
        const svg = s('svg', {
            class: 'dg-draw-svg', viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': 'Zeichenfläche', focusable: 'false', tabindex: readOnly ? null : '-1',
        }, s('rect', { x: 0, y: 0, width: W, height: H, fill: '#ffffff' }), bgImage, strokeLayer, hitLayer, selLayer, textLayer, liveLayer);
        svg.style.maxWidth = `${W}px`;
        const surface = h('div', { class: 'dg-draw-surface' }, svg);

        const toPoint = (e) => {
            const r = svg.getBoundingClientRect?.() ?? { left: 0, top: 0, width: W, height: H };

            return {
                x: Math.max(0, Math.min(W, ((e.clientX - r.left) * W) / (r.width || W))),
                y: Math.max(0, Math.min(H, ((e.clientY - r.top) * H) / (r.height || H))),
            };
        };

        const drawContent = () => {
            strokeLayer.replaceChildren();
            for (const st of model.strokes) {
                strokeLayer.append(s('path', { d: pointsToPath(st.points), stroke: st.color, 'stroke-width': st.width }));
            }
            hitLayer.replaceChildren();
            if (state.tool === 'select' && !readOnly) {
                // Unsichtbare, breite Treffer-Linien: Striche per Klick und per Tab auswählbar
                model.strokes.forEach((st, i) => {
                    const hit = s('path', {
                        d: pointsToPath(st.points), class: 'dg-stroke-hit', 'stroke-width': Math.max(14, st.width + 10), 'data-stroke': i,
                        tabindex: '0', role: 'button', 'aria-label': `Strich ${i + 1} von ${model.strokes.length}. Entf löscht ihn.`,
                    });
                    hit.addEventListener('focus', () => {
                        if (state.selectedStroke !== i) {
                            state.selected = null;
                            state.selectedStroke = i;
                            paintSelection();
                        }
                    });
                    hitLayer.append(hit);
                });
            }
            textLayer.replaceChildren();
            for (const t of model.texts) {
                const g = s('g', { class: 'dg-text-item', dataset: { id: t.id } }, svgText(t.text, t.x, t.y - TEXT_SIZE / 2 + 2, { anchor: 'start', size: TEXT_SIZE, fill: t.color ?? '#111827', events: true }));
                const box = textBox(t);
                g.prepend(s('rect', { x: box.x - 3, y: box.y - 1, width: box.w + 6, height: box.h, fill: 'transparent', stroke: state.selected === t.id ? '#4f46e5' : 'none', 'stroke-width': 1.5, 'stroke-dasharray': '4 3' }));
                if (!readOnly) {
                    g.setAttribute('tabindex', '0');
                    g.setAttribute('role', 'button');
                    g.setAttribute('aria-label', `Text: ${t.text.replace(/\n/g, ' ')}. Pfeiltasten verschieben, Entf löscht, Enter bearbeitet.`);
                    g.addEventListener('keydown', (e) => onTextKey(e, t.id));
                    g.addEventListener('dblclick', () => openTextEditor(t.id));
                    g.addEventListener('focus', () => {
                        state.selected = t.id;
                        state.selectedStroke = null;
                        paintSelection();
                    });
                } else {
                    g.setAttribute('role', 'img');
                    g.setAttribute('aria-label', `Text: ${t.text.replace(/\n/g, ' ')}`);
                }
                textLayer.append(g);
            }
            paintSelection();
        };

        /** Markierung der Auswahl (gestrichelt) und Zustand des Knopfs "Löschen". */
        function paintSelection() {
            selLayer.replaceChildren();
            const st = state.selectedStroke !== null ? model.strokes[state.selectedStroke] : null;
            if (state.selectedStroke !== null && !st) {
                state.selectedStroke = null;
            }
            if (st) {
                selLayer.append(s('path', { d: pointsToPath(st.points), class: 'dg-sel-stroke', 'stroke-width': st.width + 6 }));
            }
            if (state.selected !== null && !model.texts.some((t) => t.id === state.selected)) {
                state.selected = null;
            }
            for (const g of textLayer.children) {
                const rect = g.children?.[0];
                rect?.setAttribute?.('stroke', g.getAttribute('data-id') === state.selected ? '#4f46e5' : 'none');
            }
            deleteBtn.disabled = readOnly || (state.selected === null && state.selectedStroke === null);
        }

        /** Löscht den gewählten Strich oder Text (mit Rückgängig). */
        function deleteSelection() {
            if (readOnly) {
                return false;
            }
            if (state.selectedStroke !== null && model.strokes[state.selectedStroke]) {
                model.strokes.splice(state.selectedStroke, 1);
                state.selectedStroke = null;
                commit();
                status.textContent = 'Strich gelöscht.';
            } else if (state.selected !== null && model.texts.some((t) => t.id === state.selected)) {
                model.texts = model.texts.filter((t) => t.id !== state.selected);
                state.selected = null;
                commit();
                status.textContent = 'Text gelöscht.';
            } else {
                return false;
            }
            svg.focus?.({ preventScroll: true });

            return true;
        }

        const commit = (key = null) => {
            tracker.record(model, key);
            drawContent();
            refresh();
        };

        // ---------- Werkzeugleiste ----------
        const toolBtns = new Map();
        const mkTool = (id, label, title) => {
            const b = h('button', { type: 'button', class: 'btn btn-sm dg-tool', title, 'aria-pressed': 'false', onclick: () => setTool(id) }, label);
            toolBtns.set(id, b);

            return b;
        };
        const colorBtns = COLORS.map(([hex, name]) => {
            const b = h('button', {
                type: 'button', class: 'dg-swatch', title: name, 'aria-label': `Farbe ${name}`, 'aria-pressed': 'false', style: { background: hex },
                onclick: () => { state.color = hex; refresh(); },
            });
            b.dataset.color = hex;

            return b;
        });
        const widthSel = h('select', { class: 'input dg-input dg-width', 'aria-label': 'Strichstärke', onchange: () => { state.width = Number(widthSel.value); } },
            WIDTHS.map(([v, label]) => h('option', { value: v }, label)));
        widthSel.value = String(state.width);
        const undoBtn = smallButton(withIcon('undo-2', 'Rückgängig'), () => undo(), { title: 'Rückgängig (Strg+Z)' });
        const redoBtn = smallButton(withIcon('redo-2', 'Wiederholen'), () => redo(), { title: 'Wiederholen (Strg+Y)' });
        let clearArmed = false;
        const deleteBtn = smallButton(withIcon('trash-2', 'Löschen'), () => deleteSelection(), { title: 'Ausgewählten Strich oder Text löschen (Entf oder Rücktaste)', 'aria-label': 'Ausgewählten Strich oder Text löschen', disabled: true });
        const clearBtn = smallButton('Alles löschen', () => {
            if (!clearArmed) {
                clearArmed = true;
                clearBtn.textContent = 'Wirklich alles löschen?';
                setTimeout(() => { clearArmed = false; clearBtn.textContent = 'Alles löschen'; }, 4000);
                return;
            }
            clearArmed = false;
            clearBtn.textContent = 'Alles löschen';
            model = { strokes: [], texts: [], upload_ids: model.upload_ids };
            commit();
            status.textContent = 'Zeichnung gelöscht.';
        });
        const bar = h('div', { class: 'dg-toolbar', role: 'toolbar', 'aria-label': 'Zeichenwerkzeuge' },
            h('div', { class: 'dg-tool-group', role: 'group', 'aria-label': 'Werkzeug' },
                mkTool('pen', withIcon('pencil', 'Stift'), 'Freihand zeichnen'), mkTool('eraser', 'Radierer', 'Ganze Striche löschen'),
                mkTool('text', withIcon('type', 'Text'), 'Text setzen (Klick auf die Fläche)'), mkTool('select', withIcon('move', 'Auswählen'), 'Striche und Texte auswählen, Texte verschieben und bearbeiten')),
            h('div', { class: 'dg-tool-group', role: 'group', 'aria-label': 'Farbe' }, ...colorBtns),
            h('div', { class: 'dg-tool-group' }, h('label', { class: 'dg-tool-label', for: 'dg-w' }, 'Stärke'), widthSel),
            h('div', { class: 'dg-tool-group', role: 'group', 'aria-label': 'Bearbeiten' }, deleteBtn, undoBtn, redoBtn, clearBtn));
        widthSel.id = 'dg-w';
        const hint = h('p', { class: 'dg-hint' }, 'Zeichne mit Maus, Stift oder Finger. Texte setzt du mit dem Text-Werkzeug; sie lassen sich auch per Tastatur verschieben (Tab, Pfeiltasten). Mit „Auswählen“ markierst du einen Strich oder Text; Entf, Rücktaste oder „Löschen“ entfernt ihn (Strg+Z holt ihn zurück).');

        function setTool(id) {
            state.tool = id;
            if (id !== 'select') {
                state.selected = null;
                state.selectedStroke = null;
            }
            drawContent();
            refresh();
            surface.dataset.tool = id;
            status.textContent = { pen: 'Stift', eraser: 'Radierer', text: 'Text-Werkzeug', select: 'Auswählen' }[id];
        }

        function refresh() {
            for (const [id, b] of toolBtns) {
                b.setAttribute('aria-pressed', state.tool === id ? 'true' : 'false');
                b.classList.toggle('is-active', state.tool === id);
            }
            for (const b of colorBtns) {
                const on = b.dataset.color === state.color;
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
                b.classList.toggle('is-active', on);
            }
            undoBtn.disabled = readOnly || !tracker.history.canUndo;
            redoBtn.disabled = readOnly || !tracker.history.canRedo;
            clearBtn.disabled = readOnly || (model.strokes.length === 0 && model.texts.length === 0);
            deleteBtn.disabled = readOnly || (state.selected === null && state.selectedStroke === null);
            bar.hidden = readOnly;
            hint.hidden = readOnly;
            surface.classList.toggle('is-readonly', readOnly);
            uploadBox.hidden = !(config.allow_upload === true) && model.upload_ids.length === 0;
            renderUploads();
        }

        // ---------- Zeichnen ----------
        const eraseAt = (p) => {
            const radius = 8 + state.width;
            const before = model.strokes.length + model.texts.length;
            model.strokes = model.strokes.filter((st) => distToPolyline(p, st.points) > radius + st.width / 2);
            model.texts = model.texts.filter((t) => {
                const b = textBox(t);

                return !(p.x >= b.x - radius && p.x <= b.x + b.w + radius && p.y >= b.y - radius && p.y <= b.y + b.h + radius);
            });

            return before !== model.strokes.length + model.texts.length;
        };

        /** Index des obersten Strichs unter dem Punkt (oder null). */
        const strokeAt = (p) => {
            for (let i = model.strokes.length - 1; i >= 0; i -= 1) {
                const st = model.strokes[i];
                if (distToPolyline(p, st.points) <= 6 + st.width / 2) {
                    return i;
                }
            }

            return null;
        };

        svg.addEventListener('pointerdown', (e) => {
            if (readOnly || (e.pointerType === 'mouse' && e.button !== 0) || editor) {
                return;
            }
            const p = toPoint(e);
            if (state.tool === 'pen') {
                state.gesture = { type: 'pen', points: [[p.x, p.y]] };
                drawLive();
            } else if (state.tool === 'eraser') {
                state.gesture = { type: 'erase', changed: eraseAt(p) };
                if (state.gesture.changed) {
                    drawContent();
                }
            } else if (state.tool === 'text') {
                openTextEditor(null, p);
                e.preventDefault();
                return;
            } else {
                const item = ancestor(e.target, 'data-id');
                if (item) {
                    state.selected = item.getAttribute('data-id');
                    state.selectedStroke = null;
                    const t = model.texts.find((x) => x.id === state.selected);
                    state.gesture = t ? { type: 'move', id: t.id, start: p, origin: { x: t.x, y: t.y }, moved: false } : null;
                } else {
                    state.selected = null;
                    state.selectedStroke = strokeAt(p);
                }
                paintSelection();
                // Fokus auf die Fläche, damit Entf und Rücktaste sofort wirken (pointerdown ist abgebrochen).
                svg.focus?.({ preventScroll: true });
            }
            svg.setPointerCapture?.(e.pointerId);
            e.preventDefault();
        });
        svg.addEventListener('pointermove', (e) => {
            const g = state.gesture;
            if (!g) {
                return;
            }
            const p = toPoint(e);
            if (g.type === 'pen') {
                const last = g.points[g.points.length - 1];
                if (Math.hypot(p.x - last[0], p.y - last[1]) >= 1.2 && g.points.length < MAX_POINTS_PER_STROKE) {
                    g.points.push([p.x, p.y]);
                    drawLive();
                }
            } else if (g.type === 'erase') {
                if (eraseAt(p)) {
                    g.changed = true;
                    drawContent();
                }
            } else if (g.type === 'move') {
                const t = model.texts.find((x) => x.id === g.id);
                if (t) {
                    g.moved = true;
                    t.x = Math.round(Math.max(0, Math.min(W - 10, g.origin.x + p.x - g.start.x)));
                    t.y = Math.round(Math.max(0, Math.min(H, g.origin.y + p.y - g.start.y)));
                    drawContent();
                }
            }
        });
        const finish = (e, cancelled = false) => {
            const g = state.gesture;
            state.gesture = null;
            try {
                svg.releasePointerCapture?.(e.pointerId);
            } catch {
                // schon freigegeben
            }
            liveLayer.replaceChildren();
            if (!g) {
                return;
            }
            if (g.type === 'pen' && !cancelled) {
                const points = clampPoints(simplifyPoints(g.points, 1.1), W, H);
                if (model.strokes.length >= MAX_STROKES || pointCount(model) + points.length > MAX_POINTS_TOTAL) {
                    status.textContent = 'Die Zeichnung ist zu umfangreich. Lösche etwas, bevor du weiterzeichnest.';
                    return;
                }
                model.strokes.push({ color: state.color, width: state.width, points });
                commit();
            } else if (g.type === 'erase' && g.changed) {
                commit();
            } else if (g.type === 'move' && g.moved) {
                commit();
            }
        };
        svg.addEventListener('pointerup', (e) => finish(e));
        svg.addEventListener('pointercancel', (e) => finish(e, true));

        function drawLive() {
            const g = state.gesture;
            liveLayer.replaceChildren(s('path', { d: pointsToPath(g.points.map(([x, y]) => [Math.round(x * 10) / 10, Math.round(y * 10) / 10])), stroke: state.color, 'stroke-width': state.width }));
        }

        function ancestor(el, attr) {
            let cur = el;
            while (cur && cur !== svg) {
                if (typeof cur.getAttribute === 'function' && cur.getAttribute(attr) !== null) {
                    return cur;
                }
                cur = cur.parentNode;
            }

            return null;
        }

        // ---------- Texte ----------
        let editor = null;
        function openTextEditor(id, at = null) {
            closeTextEditor(true);
            const existing = id ? model.texts.find((t) => t.id === id) : null;
            const pos = existing ? { x: existing.x, y: existing.y } : at;
            const input = h('textarea', {
                class: 'dg-text-input', rows: 1, 'aria-label': 'Text eingeben, Enter übernimmt, Umschalt+Enter macht eine neue Zeile, Escape bricht ab', maxlength: 500,
                style: { left: `${(pos.x / W) * 100}%`, top: `${((pos.y - TEXT_SIZE) / H) * 100}%` },
            });
            input.value = existing?.text ?? '';
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    closeTextEditor(true);
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    closeTextEditor(false);
                }
                e.stopPropagation();
            });
            input.addEventListener('blur', () => closeTextEditor(true));
            surface.append(input);
            editor = { input, id, pos };
            input.focus();
        }
        function closeTextEditor(apply) {
            if (!editor) {
                return;
            }
            const { input, id, pos } = editor;
            editor = null;
            const value = multiline(input.value, 500);
            input.remove();
            if (!apply) {
                return;
            }
            if (id) {
                const t = model.texts.find((x) => x.id === id);
                if (t && value === '') {
                    model.texts = model.texts.filter((x) => x.id !== id);
                    commit();
                } else if (t && t.text !== value) {
                    t.text = value;
                    commit();
                }
            } else if (value !== '' && model.texts.length < MAX_TEXTS) {
                const used = new Set(model.texts.map((t) => t.id));
                const nid = uniqueId(null, used, 't');
                model.texts.push({ id: nid, x: Math.round(pos.x), y: Math.round(pos.y), text: value, ...(state.color !== '#000000' ? { color: state.color } : {}) });
                state.selected = nid;
                commit();
            }
        }
        function onTextKey(e, id) {
            const t = model.texts.find((x) => x.id === id);
            if (!t || readOnly) {
                return;
            }
            const step = e.shiftKey ? 20 : 5;
            const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
            if (moves[e.key]) {
                e.preventDefault();
                t.x = Math.max(0, Math.min(W - 10, t.x + moves[e.key][0]));
                t.y = Math.max(0, Math.min(H, t.y + moves[e.key][1]));
                commit(`move:${id}`);
                focusText(id);
            } else if (isDeleteKey(e)) {
                e.preventDefault();
                state.selected = id;
                state.selectedStroke = null;
                deleteSelection();
            } else if (e.key === 'Enter' || e.key === 'F2') {
                e.preventDefault();
                openTextEditor(id);
            }
        }
        function focusText(id) {
            for (const el of textLayer.children) {
                if (el.getAttribute('data-id') === id) {
                    el.focus?.();
                }
            }
        }

        // ---------- Undo ----------
        function applyModel(next) {
            model = next;
            state.selected = null;
            state.selectedStroke = null;
            drawContent();
            refresh();
        }
        function undo() {
            const m = readOnly ? null : tracker.history.undo();
            if (m) {
                applyModel(m);
                tracker.touch();
            }
        }
        function redo() {
            const m = readOnly ? null : tracker.history.redo();
            if (m) {
                applyModel(m);
                tracker.touch();
            }
        }
        bindUndoKeys(root, undo, redo);
        root.addEventListener('keydown', (e) => {
            // Entf/Rücktaste löscht die Auswahl, aber nie in Textfeldern (dort bleibt es Zeichenlöschen)
            if (e.defaultPrevented || readOnly || editor || !isDeleteKey(e) || isTypingTarget(e.target)) {
                return;
            }
            if (deleteSelection()) {
                e.preventDefault();
            }
        });

        // ---------- Upload ----------
        const uploadList = h('ul', { class: 'dg-uploads' });
        const uploadMsg = h('div', { class: 'dg-upload-msg', role: 'status', 'aria-live': 'polite' });
        const fileInput = h('input', { type: 'file', accept: 'image/png,image/jpeg,application/pdf', class: 'dg-file', 'aria-label': 'Datei hochladen (PNG, JPEG oder PDF, höchstens 10 MB)' });
        const previewMode = ctx.mode === 'preview';
        fileInput.addEventListener('change', async () => {
            const file = fileInput.files?.[0];
            fileInput.value = '';
            if (!file || readOnly) {
                return;
            }
            if (previewMode || examId === null || taskId === null) {
                uploadMsg.textContent = 'In der Vorschau kann nichts hochgeladen werden.';
                return;
            }
            if (file.size > 10 * 1024 * 1024) {
                uploadMsg.textContent = 'Die Datei ist größer als 10 MB.';
                return;
            }
            if (model.upload_ids.length >= MAX_UPLOADS) {
                uploadMsg.textContent = `Du kannst höchstens ${MAX_UPLOADS} Dateien hochladen.`;
                return;
            }
            uploadMsg.textContent = `„${file.name}“ wird hochgeladen ...`;
            const fd = new FormData();
            fd.append('task_id', String(taskId));
            fd.append('file', file);
            const res = await request(`/api/klausur/${examId}/datei`, { method: 'POST', body: fd, timeoutMs: 120000 });
            if (!res.ok || !res.data?.file?.id) {
                uploadMsg.textContent = res.error || 'Der Upload hat nicht geklappt. Versuche es noch einmal.';
                return;
            }
            const f = res.data.file;
            uploads.set(Number(f.id), { name: String(f.name ?? file.name), url: typeof f.url === 'string' ? f.url : null });
            model = { ...model, upload_ids: [...model.upload_ids, Number(f.id)] };
            commit();
            uploadMsg.textContent = `„${file.name}“ ist hochgeladen.`;
        });
        const uploadBox = h('div', { class: 'dg-upload' },
            h('h3', { class: 'dg-panel-title' }, 'Dateien'),
            readOnly ? null : h('p', { class: 'dg-panel-hint' }, 'Du kannst ein Foto oder Scan deiner Lösung hochladen (PNG, JPEG oder PDF, höchstens 10 MB).'),
            fileInput, uploadMsg, uploadList);
        function renderUploads() {
            fileInput.disabled = readOnly || previewMode;
            fileInput.hidden = readOnly;
            uploadList.replaceChildren(...model.upload_ids.map((id) => {
                const info = uploads.get(id);
                const href = info?.url ? url(info.url) : examId !== null ? url(`/klausur/${examId}/antwortdatei/${id}`) : null;
                const li = h('li', { class: 'dg-upload-item' });
                if (href) {
                    const img = h('img', { src: href, alt: info?.name ?? `Datei ${id}`, class: 'dg-upload-thumb', loading: 'lazy' });
                    img.addEventListener('error', () => img.remove());
                    li.append(img, h('a', { href, target: '_blank', rel: 'noopener' }, info?.name ?? `Datei ${id}`));
                } else {
                    li.append(info?.name ?? `Datei ${id}`);
                }
                if (!readOnly) {
                    li.append(smallButton('Entfernen', () => {
                        model = { ...model, upload_ids: model.upload_ids.filter((x) => x !== id) };
                        commit();
                    }, { 'aria-label': `${info?.name ?? `Datei ${id}`} aus der Antwort entfernen` }));
                }

                return li;
            }));
        }

        root.append(bar, hint, surface, uploadBox, status);
        container.append(root);
        setTool('pen');
        drawContent();
        refresh();

        return {
            layout: 'stack',
            getContent: () => clone(model),
            setContent(content) {
                applyModel(normalizeDrawing(content, config));
                tracker.history.reset(model);
                refresh();
            },
            setReadOnly(flag) {
                readOnly = !!flag;
                tracker.setReadOnly(readOnly);
                closeTextEditor(false);
                state.selected = null;
                state.selectedStroke = null;
                if (readOnly) {
                    svg.removeAttribute('tabindex');
                } else {
                    svg.setAttribute('tabindex', '-1');
                }
                root.dataset.readonly = readOnly ? '1' : '0';
                drawContent();
                refresh();
            },
            getPreviewPng: exportPng,
            focus: () => surface.focus?.(),
            destroy() {
                closeTextEditor(true);
                tracker.destroy();
                root.remove();
            },
        };
    },
};

/** Inhalt (Striche, Texte) als SVG-Gruppe, auch für den PNG-Export. */
function buildContent(model) {
    const g = s('g', {});
    const strokes = s('g', { fill: 'none', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' });
    for (const st of model.strokes) {
        strokes.append(s('path', { d: pointsToPath(st.points), stroke: st.color, 'stroke-width': st.width }));
    }
    g.append(strokes);
    for (const t of model.texts) {
        g.append(svgText(t.text, t.x, t.y - TEXT_SIZE / 2 + 2, { anchor: 'start', size: TEXT_SIZE, fill: t.color ?? '#111827' }));
    }

    return g;
}

registerAnswerWidget('drawing', DrawingWidget);

void bool;
void isObj;
