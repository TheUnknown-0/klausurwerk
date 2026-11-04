/**
 * Aufgaben-Editor für den Antworttyp "drawing": Größe der Zeichenfläche, Hintergrundbild aus dem
 * Klausurmaterial und Datei-Upload. Eine Musterlösung gibt es nicht (Bewertung von Hand).
 */
import { h } from '../../../lib/dom.js';
import { registerTaskEditor } from '../../../lib/widgets.js';
import { checkbox, labeled, numberInput, select } from '../../../task-editor/ui.js';
import { embedWidget, section, taskBoot } from './common.js';

registerTaskEditor('drawing', {
    mount(container, ctx) {
        const locked = !!ctx.locked;
        const c = ctx.config ?? {};
        const state = {
            width: Number(c.width) > 0 ? Number(c.width) : 900,
            height: Number(c.height) > 0 ? Number(c.height) : 600,
            background_material_id: c.background_material_id ?? null,
            allow_upload: !!c.allow_upload,
        };
        const boot = taskBoot();
        const images = (boot?.materials ?? []).filter((m) => String(m.mime ?? '').startsWith('image/'));
        const configOf = () => ({
            width: Math.round(state.width),
            height: Math.round(state.height),
            background_material_id: state.background_material_id ? Number(state.background_material_id) : null,
            allow_upload: state.allow_upload,
        });
        const previewHost = h('div', { class: 'te-diagram-solution' });
        let preview = embedWidget(previewHost, 'drawing', { config: configOf(), content: {}, onChange: () => {} });
        const refreshPreview = () => {
            preview.destroy();
            preview = embedWidget(previewHost, 'drawing', { config: configOf(), content: {}, onChange: () => {} });
        };
        const changed = () => {
            ctx.onChange();
        };

        const width = numberInput(state.width, (v) => { state.width = Number(v); changed(); }, { min: 200, max: 3000, step: 50, disabled: locked });
        const height = numberInput(state.height, (v) => { state.height = Number(v); changed(); }, { min: 200, max: 3000, step: 50, disabled: locked });
        width.addEventListener('change', refreshPreview);
        height.addEventListener('change', refreshPreview);
        const options = [['', '(keines)'], ...images.map((m) => [String(m.id), m.name])];
        const bg = select(options, state.background_material_id ? String(state.background_material_id) : '', (v) => {
            state.background_material_id = v === '' ? null : Number(v);
            changed();
            refreshPreview();
        }, { disabled: locked });
        const upload = checkbox('Schüler:innen dürfen zusätzlich eine Datei hochladen (PNG, JPEG, PDF, höchstens 10 MB)', state.allow_upload, (v) => {
            state.allow_upload = v;
            changed();
            refreshPreview();
        }, { disabled: locked });

        const root = h('div', { class: 'te-type-box' },
            h('div', { class: 'te-grid' },
                labeled('Breite der Zeichenfläche (Pixel)', width, '200 bis 3000'),
                labeled('Höhe der Zeichenfläche (Pixel)', height, '200 bis 3000')),
            labeled('Hintergrundbild aus dem Klausurmaterial', bg,
                images.length === 0 ? 'Lade zuerst ein Bild unter „Material verwalten“ hoch (PNG, JPEG, SVG).' : 'Zum Beispiel ein Koordinatensystem oder ein Plan zum Ergänzen.'),
            upload,
            section('Vorschau', 'So sieht die Zeichenfläche für Schüler:innen aus (Zeichnungen hier werden nicht gespeichert).', previewHost));
        container.append(root);

        return {
            getValue: () => ({ config: configOf(), solution: {} }),
            validate() {
                const errors = [];
                for (const [label, v] of [['Breite', state.width], ['Höhe', state.height]]) {
                    if (!Number.isFinite(v) || v < 200 || v > 3000) {
                        errors.push(`${label} der Zeichenfläche: Werte von 200 bis 3000 Pixel.`);
                    }
                }

                return errors;
            },
            destroy() {
                preview.destroy();
                root.remove();
            },
        };
    },
});
