/**
 * Ausgabebereich des Code-Widgets: Reiter für Konsole, SQL-Ergebnisse,
 * Datenbank-Schema, Zeichnung (Turtle), Bilder (matplotlib), Probleme und
 * Beispieltests. Reiter erscheinen erst, wenn es etwas zu zeigen gibt.
 */
import { h } from '../lib/dom.js';
import { createConsole } from './console.js';
import { createTurtleView } from './turtle-view.js';
import { formatCell, shortSql } from './sql-results.js';
import { visibleWhitespace } from './compare.js';

const TABS = [
    ['console', 'Konsole'],
    ['tables', 'Ergebnis'],
    ['schema', 'Datenbank'],
    ['turtle', 'Zeichnung'],
    ['images', 'Bilder'],
    ['problems', 'Probleme'],
    ['tests', 'Beispieltests'],
];

let panelCounter = 0;

/**
 * @param {HTMLElement} parent
 * @param {{ onSubmitInput?: (text: string) => void, onJump?: (file: string|null, line: number) => void }} opts
 */
export function createOutputPanel(parent, opts = {}) {
    const uid = `cwp${++panelCounter}`;
    const tabBar = h('div', { class: 'cw-out-tabs', role: 'tablist', 'aria-label': 'Ausgabe' });
    const body = h('div', { class: 'cw-out-body' });
    const root = h('div', { class: 'cw-output' }, tabBar, body);
    parent.append(root);

    const tabs = new Map();
    const panes = new Map();
    let active = 'console';
    let turtleView = null;
    let imageCount = 0;

    for (const [key, label] of TABS) {
        const count = h('span', { class: 'cw-out-count', hidden: true });
        const btn = h('button', {
            class: 'cw-out-tab', type: 'button', role: 'tab', id: `${uid}-tab-${key}`,
            'aria-controls': `${uid}-pane-${key}`, 'aria-selected': 'false', tabindex: '-1', hidden: key !== 'console',
            onclick: () => show(key),
        }, label, count);
        const pane = h('div', {
            class: `cw-out-pane cw-pane-${key}`, role: 'tabpanel', id: `${uid}-pane-${key}`,
            'aria-labelledby': `${uid}-tab-${key}`, hidden: true,
        });
        tabs.set(key, { btn, count });
        panes.set(key, pane);
        tabBar.append(btn);
        body.append(pane);
    }

    tabBar.addEventListener('keydown', (ev) => {
        if (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft') {
            return;
        }
        const visible = [...tabs.entries()].filter(([, t]) => !t.btn.hidden).map(([k]) => k);
        const idx = visible.indexOf(active);
        const next = visible[(idx + (ev.key === 'ArrowRight' ? 1 : -1) + visible.length) % visible.length];
        show(next);
        tabs.get(next).btn.focus();
        ev.preventDefault();
    });

    const consoleApi = createConsole(panes.get('console'), { onSubmit: (text) => opts.onSubmitInput?.(text) });

    function show(key) {
        if (!panes.has(key)) {
            return;
        }
        active = key;
        for (const [k, t] of tabs) {
            const on = k === key;
            t.btn.setAttribute('aria-selected', on ? 'true' : 'false');
            t.btn.tabIndex = on ? 0 : -1;
            t.btn.classList.toggle('is-active', on);
            panes.get(k).hidden = !on;
        }
        if (key === 'console') {
            consoleApi.flush();
        }
    }

    function reveal(key, flag = true) {
        tabs.get(key).btn.hidden = !flag;
        if (!flag && active === key) {
            show('console');
        }
    }

    function badge(key, n) {
        const c = tabs.get(key).count;
        c.hidden = !(n > 0);
        c.textContent = n > 0 ? String(n) : '';
    }

    // ---------- SQL ----------
    function renderSqlResult(r) {
        const head = h('div', { class: 'cw-res-head' },
            h('span', { class: 'cw-res-no' }, `Anweisung ${r.index}`),
            h('code', { class: 'cw-res-sql' }, shortSql(r.sql, 90)));
        if (r.kind === 'error') {
            return h('section', { class: 'cw-res cw-res-error' }, head,
                h('div', { class: 'alert alert-danger', role: 'alert' },
                    h('strong', {}, r.message), r.hint ? h('div', { class: 'cw-res-hint' }, r.hint) : null));
        }
        if (r.kind !== 'rows') {
            return h('section', { class: 'cw-res' }, head, h('p', { class: 'cw-res-msg' }, r.message));
        }
        const table = h('table', { class: 'data-table cw-res-table' },
            h('thead', {}, h('tr', {}, r.columns.map((c) => h('th', { scope: 'col' }, c)))),
            h('tbody', {}, r.rows.map((row) => h('tr', {}, row.map((v) => {
                const cell = formatCell(v);
                return h('td', { class: cell.isNull ? 'is-null' : (typeof v === 'number' ? 'is-num' : '') }, cell.text);
            })))));
        return h('section', { class: 'cw-res' }, head,
            r.rows.length > 0 ? h('div', { class: 'table-wrap cw-res-wrap', tabindex: '0', role: 'region', 'aria-label': `Ergebnis von Anweisung ${r.index}` }, table) : null,
            h('p', { class: ['cw-res-msg', r.truncated && 'is-warn'] }, r.message));
    }

    show('console');

    // ---------- öffentliche Schnittstelle ----------
    return {
        element: root,
        console: consoleApi,
        show,

        setTables(results) {
            const pane = panes.get('tables');
            pane.replaceChildren();
            const list = Array.isArray(results) ? results : [];
            reveal('tables', list.length > 0);
            list.forEach((r) => pane.append(renderSqlResult(r)));
            if (list.length > 0) {
                show('tables');
            }
        },

        setSchema(tables) {
            const pane = panes.get('schema');
            pane.replaceChildren();
            if (!tables) {
                reveal('schema', false);
                return;
            }
            reveal('schema', true);
            if (tables.length === 0) {
                pane.append(h('p', { class: 'cw-res-msg' }, 'Die Datenbank enthält noch keine Tabellen.'));
            }
            for (const t of tables) {
                pane.append(h('section', { class: 'cw-schema-table' },
                    h('h4', {}, t.name, ' ', h('span', { class: 'badge badge-accent' }, t.type === 'view' ? 'Sicht' : 'Tabelle')),
                    h('ul', { class: 'cw-schema-cols' }, t.columns.map((c) => h('li', {},
                        h('code', {}, c.name), ' ', h('span', { class: 'cw-col-type' }, c.type || 'ohne Typ'),
                        c.pk ? h('span', { class: 'badge badge-primary', title: 'Primärschlüssel' }, 'PK') : null,
                        c.notnull && !c.pk ? h('span', { class: 'badge', title: 'darf nicht leer sein' }, 'NOT NULL') : null,
                        c.fk ? h('span', { class: 'badge badge-info', title: 'Fremdschlüssel' }, `→ ${c.fk}`) : null)))));
            }
            show('schema');
        },

        /** Zeichenfläche für Turtle (wird beim ersten Zugriff angelegt). */
        turtle() {
            if (!turtleView) {
                turtleView = createTurtleView(panes.get('turtle'));
            }
            reveal('turtle', true);
            return turtleView;
        },
        hasTurtle: () => turtleView !== null,
        resetTurtle() {
            if (turtleView) {
                turtleView.reset();
            }
            reveal('turtle', false);
        },

        addImage(dataUrl) {
            imageCount++;
            const pane = panes.get('images');
            pane.append(h('figure', { class: 'cw-figure' },
                h('img', { src: dataUrl, alt: `Diagramm ${imageCount} aus dem Programm` }),
                h('figcaption', {}, `Bild ${imageCount}`)));
            reveal('images', true);
            badge('images', imageCount);
            show('images');
        },
        clearImages() {
            imageCount = 0;
            panes.get('images').replaceChildren();
            reveal('images', false);
            badge('images', 0);
        },

        setProblems(list) {
            const pane = panes.get('problems');
            pane.replaceChildren();
            reveal('problems', list.length > 0);
            badge('problems', list.length);
            if (list.length === 0) {
                return;
            }
            pane.append(h('ul', { class: 'cw-problems' }, list.map((d) => h('li', {},
                h('button', {
                    class: `cw-problem is-${d.severity}`, type: 'button',
                    onclick: () => opts.onJump?.(d.file, d.line),
                },
                h('span', { class: 'cw-problem-loc' }, `${d.file ? `${d.file}, ` : ''}Zeile ${d.line}`),
                h('span', { class: 'cw-problem-msg' }, d.message))))));
        },
        showProblems() {
            show('problems');
        },

        setTests(results) {
            const pane = panes.get('tests');
            pane.replaceChildren();
            reveal('tests', !!results);
            if (!results) {
                badge('tests', 0);
                return;
            }
            const passed = results.filter((r) => r.ok).length;
            badge('tests', results.length - passed);
            pane.append(h('p', { class: ['cw-tests-sum', passed === results.length ? 'is-ok' : 'is-fail'], role: 'status' },
                `${passed} von ${results.length} Beispieltests bestanden`));
            for (const r of results) {
                const item = h('section', { class: ['cw-test', r.ok ? 'is-ok' : 'is-fail'] },
                    h('div', { class: 'cw-test-head' },
                        h('span', { class: ['badge', r.ok ? 'badge-success' : 'badge-danger'] }, r.ok ? 'Richtig' : 'Falsch'),
                        h('strong', {}, r.name)));
                if (!r.ok) {
                    item.append(h('p', { class: 'cw-res-msg' }, r.message));
                    if (r.expected !== undefined) {
                        item.append(
                            h('div', { class: 'cw-test-diff' },
                                h('div', {}, h('span', { class: 'cw-test-label' }, 'Erwartet'), h('pre', {}, visibleWhitespace(r.expected))),
                                h('div', {}, h('span', { class: 'cw-test-label' }, 'Erhalten'), h('pre', {}, visibleWhitespace(r.actual)))));
                    }
                }
                pane.append(item);
            }
            show('tests');
        },

        /** Alles aus dem letzten Lauf entfernen (Tests und Schema bleiben). */
        resetForRun() {
            consoleApi.clear();
            this.setTables(null);
            this.setProblems([]);
            this.clearImages();
            this.resetTurtle();
            show('console');
        },

        destroy() {
            consoleApi.destroy();
            turtleView?.destroy();
            root.remove();
        },
    };
}
