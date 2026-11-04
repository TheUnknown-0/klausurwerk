/**
 * Verbindet ein Diagramm-Plugin mit der Antwort-Widget-Schnittstelle (lib/widgets.js).
 *
 * Alle Diagramm-Widgets
 *  - melden Änderungen entprellt (300 ms) mit onChange(inhalt, {kind: 'value', getPreviewPng}),
 *    wobei getPreviewPng() ein Promise mit der PNG-Data-URL (höchstens 2 MB) oder null liefert,
 *  - bieten getPreviewPng() auch direkt an der Instanz,
 *  - unterstützen readOnly (ohne Werkzeugleisten), setContent() für Live-Mitlesen und destroy().
 */
import { h } from '../../../lib/dom.js';
import { DiagramBoard } from './board.js';
import { asObject } from './model-util.js';

export function boardWidget(plugin) {
    return {
        mount(container, ctx) {
            const config = asObject(ctx.task?.config);
            const host = h('div', { class: 'w-diagram', dataset: { mode: ctx.mode ?? 'exam', type: plugin.name } });
            container.append(host);
            let readOnly = !!ctx.readOnly;
            let board = null;
            board = new DiagramBoard(host, plugin, ctx.content, {
                readOnly,
                config,
                mode: ctx.mode,
                onChange: () => {
                    if (readOnly || typeof ctx.onChange !== 'function') {
                        return;
                    }
                    ctx.onChange(board.getContent(), { kind: 'value', getPreviewPng: () => board.getPreviewPng() });
                },
            });

            return {
                layout: 'stack',
                board,
                getContent: () => board.getContent(),
                setContent: (content) => board.setContent(content),
                setConfig: (next) => board.setConfig(asObject(next)),
                setReadOnly(flag) {
                    readOnly = !!flag;
                    board.setReadOnly(readOnly);
                },
                focus: () => board.svg.focus?.(),
                getPreviewPng: () => board.getPreviewPng(),
                destroy: () => {
                    board.destroy();
                    host.remove();
                },
            };
        },
    };
}
