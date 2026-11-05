/**
 * Auswahl des Antworttyps als Kacheln, wenn eine neue Aufgabe angelegt wird.
 *
 * Statt eines leeren Formulars mit Auswahlliste sieht die Lehrkraft sofort, was es gibt, und
 * startet mit passenden Voreinstellungen (zum Beispiel Programmcode gleich in Python).
 */
import { h, icon } from '../lib/dom.js';

/** Kurzbeschreibung und Symbol je Typ. Unbekannte Typen bekommen eine neutrale Kachel. */
const INFO = {
    text: ['Freie Antwort in Sätzen, auch mit Formeln.', 'file-text'],
    choice: ['Eine oder mehrere richtige Antworten ankreuzen.', 'circle-check'],
    gaps: ['Lücken im Text füllen, auch in Code.', 'type'],
    matching: ['Begriffe einander zuordnen.', 'link'],
    ordering: ['Schritte in die richtige Reihenfolge bringen.', 'list-ordered'],
    truth_table: ['Ausgänge einer Wahrheitstabelle ausfüllen.', 'grid-3x3'],
    uml: ['Klassen, Attribute, Methoden und Beziehungen zeichnen.', 'layout-grid'],
    er: ['Entitäten, Attribute und Beziehungen nach Chen.', 'database'],
    relational: ['Tabellen mit Schlüsseln und Fremdschlüsseln.', 'database'],
    structogram: ['Algorithmus als Nassi-Shneiderman-Diagramm.', 'layout-grid'],
    flowchart: ['Algorithmus als Programmablaufplan.', 'git-branch'],
    logic: ['Schaltnetz aus Gattern bauen und testen.', 'git-branch'],
    automaton: ['Zustände und Übergänge eines Automaten.', 'git-branch'],
    drawing: ['Freihand zeichnen und beschriften.', 'pencil'],
    robo: ['Robo in der Gitterwelt programmieren.', 'bot'],
};

/** Reihenfolge der Gruppen in der Auswahl. */
const GROUP_ORDER = ['Programmieren', 'Text & Auswahl', 'Diagramme', 'Mikrowelt', 'Gliederung'];

/** Programmcode erscheint als drei Kacheln, je Sprache vorbelegt. */
const CODE_TILES = [
    { label: 'Java', desc: 'Programm schreiben, kompilieren und ausführen.', config: { language: 'java', main_file: '' } },
    { label: 'Python', desc: 'Programm schreiben und im Browser ausführen.', config: { language: 'python', main_file: '' } },
    { label: 'SQL', desc: 'Abfragen gegen eine vorgegebene Datenbank.', config: { language: 'sql', main_file: '' } },
];

/**
 * Baut die Kacheln aus den registrierten Typen (boot.types).
 *
 * @param {{name: string, label: string, group: string}[]} types
 * @returns {{group: string, tiles: {key: string, type: string, label: string, desc: string, icon: string, config?: object}[]}[]}
 */
export function pickerGroups(types) {
    const groups = new Map();
    const add = (group, tile) => {
        if (!groups.has(group)) {
            groups.set(group, []);
        }
        groups.get(group).push(tile);
    };
    for (const t of types) {
        if (t.name === 'code') {
            for (const c of CODE_TILES) {
                add(t.group, { key: `code-${c.config.language}`, type: 'code', label: c.label, desc: c.desc, icon: 'code-xml', config: c.config });
            }
            continue;
        }
        const [desc, ico] = INFO[t.name] ?? ['', 'file-pen-line'];
        add(t.group, { key: t.name, type: t.name, label: t.label, desc, icon: ico });
    }
    add('Gliederung', {
        key: 'container', type: '', label: 'Überschrift mit Teilaufgaben',
        desc: 'Gemeinsamer Text (z. B. Ausgangslage), darunter Teilaufgaben 1a, 1b ...', icon: 'list-ordered',
    });

    // Häufiges zuerst; unbekannte Gruppen behalten ihre Reihenfolge davor, Gliederung bleibt am Ende.
    const rank = (g) => {
        const i = GROUP_ORDER.indexOf(g);
        return i < 0 ? GROUP_ORDER.length - 1 : i;
    };
    return [...groups].map(([group, tiles], i) => ({ group, tiles, i }))
        .sort((a, b) => rank(a.group) - rank(b.group) || a.i - b.i)
        .map(({ group, tiles }) => ({ group, tiles }));
}

/**
 * @param {{
 *   types: {name: string, label: string, group: string}[],
 *   heading: string,
 *   onPick: (tile: {type: string, config?: object, label: string}) => void,
 *   onCancel?: () => void,
 * }} opts
 */
export function typePicker({ types, heading, onPick, onCancel }) {
    const first = [];
    const sections = pickerGroups(types).map(({ group, tiles }) => h('section', { class: 'te-pick-group' },
        h('h3', {}, group),
        h('div', { class: 'te-pick-grid' }, tiles.map((tile) => {
            const el = h('button', {
                type: 'button', class: 'te-pick-tile', dataset: { type: tile.key },
                onclick: () => onPick(tile),
            },
            h('span', { class: 'te-pick-icon' }, icon(tile.icon)),
            h('span', { class: 'te-pick-label' }, tile.label),
            tile.desc ? h('span', { class: 'te-pick-desc' }, tile.desc) : null);
            if (first.length === 0) {
                first.push(el);
            }
            return el;
        }))));

    const el = h('div', {
        class: 'te-picker', role: 'group', 'aria-label': heading,
        onkeydown: (ev) => {
            if (ev.key === 'Escape' && onCancel) {
                ev.preventDefault();
                onCancel();
            }
        },
    },
    h('div', { class: 'te-picker-head' },
        h('h2', {}, heading),
        onCancel ? h('button', { type: 'button', class: 'btn btn-sm', onclick: () => onCancel() }, 'Abbrechen') : null),
    h('p', { class: 'text-soft text-sm' }, 'Wähle, wie die Schüler:innen antworten. Den Typ kannst du später im Formular noch ändern.'),
    sections);

    return { el, focus: () => first[0]?.focus?.() };
}
