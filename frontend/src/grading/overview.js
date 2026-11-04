/**
 * Korrektur-Übersicht: Filter für die Matrix und die automatische Vorbewertung als Stapellauf.
 *
 * Der Lauf ruft POST /api/korrektur/{id}/vorbewertung wiederholt auf. Jeder Aufruf verarbeitet einen
 * kleinen Stapel (Server: höchstens 10 Paare oder 20 Sekunden) und meldet den Rest. Bei Fehlern
 * (z. B. Runner nicht erreichbar) hält der Lauf an; Fehlgeschlagene lassen sich später wiederholen.
 */
import { post } from '../lib/api.js';
import { toast } from '../lib/dom.js';

export function mountOverview(root) {
    let boot = {};
    try {
        boot = JSON.parse(root.dataset.boot ?? '{}');
    } catch {
        boot = {};
    }

    // ---------- Filter ----------
    const text = root.querySelector('[data-filter-text]');
    const status = root.querySelector('[data-filter-status]');
    const rows = [...root.querySelectorAll('[data-matrix-body] tr')];
    function applyFilter() {
        const q = (text?.value ?? '').trim().toLowerCase();
        const flag = status?.value ?? '';
        for (const tr of rows) {
            const okName = q === '' || (tr.dataset.name ?? '').includes(q);
            const okFlag = flag === '' || (tr.dataset.flags ?? '').split(' ').includes(flag);
            tr.hidden = !(okName && okFlag);
        }
    }
    text?.addEventListener('input', applyFilter);
    status?.addEventListener('change', applyFilter);

    // ---------- Vorbewertung ----------
    const message = root.querySelector('[data-auto-message]');
    const bar = root.querySelector('[data-auto-progress]');
    const buttons = [...root.querySelectorAll('[data-auto-run], [data-apply]')];
    let running = false;

    function say(textContent, kind = '') {
        if (message) {
            message.textContent = textContent;
            message.className = `text-sm mt-2 ${kind === 'error' ? 'text-danger' : kind === 'ok' ? 'text-success' : ''}`;
        }
    }

    function setBar(done, total) {
        if (!bar) return;
        bar.hidden = false;
        const pct = total > 0 ? Math.round((done / total) * 100) : 100;
        bar.setAttribute('aria-valuenow', String(pct));
        bar.firstElementChild.style.width = `${pct}%`;
    }

    async function run(reset) {
        if (running) return;
        running = true;
        for (const b of buttons) b.disabled = true;
        let body = reset ? { reset } : {};
        let processed = 0;
        let errors = 0;
        let lastRemaining = null;
        let stalls = 0;
        say('Die Vorbewertung läuft ...');
        try {
            for (;;) {
                const res = await post(`${boot.api_base}/vorbewertung`, body, { timeoutMs: 120000 });
                body = {};
                if (!res.ok) {
                    say(`Abgebrochen: ${res.error}`, 'error');
                    break;
                }
                const d = res.data;
                processed += d.processed;
                errors += d.errors;
                setBar(d.total - d.remaining, d.total);
                say(`${d.total - d.remaining} von ${d.total} geprüft ...`);
                if (d.aborted) {
                    say(`Angehalten: ${d.message ?? 'Die Ausführungsumgebung ist nicht erreichbar.'} Du kannst später „Fehlgeschlagene wiederholen“ wählen.`, 'error');
                    break;
                }
                if (d.remaining === 0) {
                    say(errors > 0
                        ? `Fertig. ${processed} geprüft, ${errors} fehlgeschlagen (siehe Matrix, „Fehlgeschlagene wiederholen“).`
                        : `Fertig. ${processed} Aufgaben geprüft.`, errors > 0 ? 'error' : 'ok');
                    break;
                }
                // Schutz vor Endlosschleife, falls der Server nichts mehr verarbeitet
                stalls = d.remaining === lastRemaining && d.processed === 0 ? stalls + 1 : 0;
                lastRemaining = d.remaining;
                if (stalls >= 3) {
                    say('Es geht nicht weiter. Bitte lade die Seite neu.', 'error');
                    break;
                }
            }
        } finally {
            running = false;
            for (const b of buttons) b.disabled = false;
        }
        // Matrix und Zähler neu laden, damit Vorschläge sichtbar werden
        setTimeout(() => window.location.reload(), 1200);
    }

    for (const b of root.querySelectorAll('[data-auto-run]')) {
        b.addEventListener('click', () => {
            const reset = b.dataset.autoRun || '';
            const confirmText = b.dataset.confirmText;
            if (confirmText && !window.confirm(confirmText)) return;
            run(reset);
        });
    }

    for (const b of root.querySelectorAll('[data-apply]')) {
        b.addEventListener('click', async () => {
            if (running) return;
            const scope = b.dataset.apply;
            if (!window.confirm(scope === 'all'
                ? 'Alle Vorschläge (auch aus Code-Tests) als deine Bewertung übernehmen, wo du noch nichts bewertet hast?'
                : 'Vorschläge aus Auswahl-, Zuordnungs- und Lückenaufgaben als deine Bewertung übernehmen, wo du noch nichts bewertet hast?')) return;
            running = true;
            for (const x of buttons) x.disabled = true;
            const res = await post(`${boot.api_base}/vorschlaege`, { scope }, { timeoutMs: 60000 });
            running = false;
            for (const x of buttons) x.disabled = false;
            if (!res.ok) {
                toast(res.error, 'error');
                return;
            }
            toast(`${res.data.applied} Bewertungen übernommen.`, 'success', 3000);
            setTimeout(() => window.location.reload(), 800);
        });
    }
}
