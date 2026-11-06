/**
 * Freigaben für den eingebauten Browser (Detail-Editor). Default deny: Ohne Eintrag ist jede
 * Webseite gesperrt. Freigegeben ist alles, was mit der Adresse beginnt, oder mit Häkchen nur
 * genau diese Seite. Teilaufgaben erben die Freigaben ihrer Überschrift.
 */
import { h, icon } from '../lib/dom.js';
import { uid } from './ui.js';

const MAX = 20;

/** Zeilen ohne Adresse fallen weg, Leerzeichen am Rand auch. */
export function cleanSites(rows) {
    return rows
        .map((r) => ({ url: String(r.url ?? '').trim(), label: String(r.label ?? '').trim(), exact: !!r.exact }))
        .filter((r) => r.url !== '');
}

/**
 * @param {{ sites: {url: string, label: string, exact: boolean}[], locked: boolean, onChange: () => void, inherited?: number }} o
 */
export function createWebSitesField({ sites, locked, onChange, inherited = 0 }) {
    let rows = (sites ?? []).map((s) => ({ ...s }));
    const list = h('div', { class: 'te-web-list' });
    const summary = h('span', { class: 'te-web-count' });
    const addBtn = h('button', { type: 'button', class: 'btn btn-sm', disabled: locked }, icon('plus'), 'Webseite freigeben');

    function updateSummary() {
        const n = cleanSites(rows).length;
        summary.textContent = n === 0
            ? (inherited > 0 ? `${inherited} von der Überschrift geerbt` : 'keine, alles gesperrt')
            : `${n} freigegeben${inherited > 0 ? `, dazu ${inherited} geerbt` : ''}`;
        addBtn.disabled = locked || rows.length >= MAX;
    }

    function render() {
        list.replaceChildren();
        rows.forEach((row, i) => {
            const urlId = uid('web-url');
            const url = h('input', { class: 'input', type: 'text', id: urlId, value: row.url, placeholder: 'https://docs.python.org/3/library/', disabled: locked, spellcheck: 'false', 'aria-label': `Adresse ${i + 1}` });
            url.value = row.url;
            url.addEventListener('input', () => { row.url = url.value; updateSummary(); onChange(); });
            const label = h('input', { class: 'input', type: 'text', value: row.label, placeholder: 'Bezeichnung, z. B. Python-Doku', maxlength: 80, disabled: locked, 'aria-label': `Bezeichnung ${i + 1}` });
            label.value = row.label;
            label.addEventListener('input', () => { row.label = label.value; onChange(); });
            const exact = h('input', { type: 'checkbox', disabled: locked });
            exact.checked = !!row.exact;
            exact.addEventListener('change', () => { row.exact = exact.checked; onChange(); });
            const del = h('button', { type: 'button', class: 'btn btn-sm btn-icon btn-danger-ghost', 'aria-label': `Webseite ${i + 1} entfernen`, title: 'Entfernen', disabled: locked }, icon('trash-2'));
            del.addEventListener('click', () => { rows.splice(i, 1); render(); updateSummary(); onChange(); });
            list.append(h('div', { class: 'te-web-row' },
                url, label,
                h('label', { class: 'checkbox-row te-web-exact', title: 'Nur genau diese Seite, keine Unterseiten' }, exact, h('span', {}, 'nur diese Seite')),
                del));
        });
    }

    addBtn.addEventListener('click', () => {
        rows.push({ url: '', label: '', exact: false });
        render();
        updateSummary();
        list.lastElementChild?.querySelector('input')?.focus();
    });

    render();
    updateSummary();

    const el = h('details', { class: 'te-web', open: rows.length > 0 },
        h('summary', {}, icon('link'), ' Webseiten im Klausur-Browser: ', summary),
        h('p', { class: 'hint' },
            'Grundsätzlich ist jede Webseite gesperrt. Hier freigegebene Seiten öffnen Schüler:innen bei dieser Aufgabe in einem eingebauten Browser, ohne den Klausur-Tab zu verlassen. ',
            'Freigegeben ist alles, was mit der Adresse beginnt (z. B. die ganze Bibliotheksdoku unter docs.python.org/3/library/). ',
            'Die Seiten laufen ohne JavaScript; Nachschlagewerke und Dokumentationen gehen, Web-Anwendungen nicht. Klicks auf gesperrte Links erscheinen im Pult.'),
        list,
        h('div', { class: 'te-web-actions' }, addBtn));

    return {
        el,
        getValue: () => cleanSites(rows),
    };
}
