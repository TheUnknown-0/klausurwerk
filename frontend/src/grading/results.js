/**
 * Ergebnisseite: Rückmeldung je Person speichern und Personen abschließen (JSON-API, kein Neuladen).
 */
import { post } from '../lib/api.js';
import { debounce, toast } from '../lib/dom.js';

export function mountResults(root) {
    let boot = {};
    try {
        boot = JSON.parse(root.dataset.boot ?? '{}');
    } catch {
        boot = {};
    }
    const gradedStatus = boot.status === 'grading';

    async function send(row, payload, stateEl) {
        const pid = row.dataset.pid;
        if (stateEl) stateEl.textContent = 'Speichert ...';
        const res = await post(`${boot.person_api}${pid}`, payload);
        if (!res.ok) {
            if (stateEl) {
                stateEl.textContent = 'Nicht gespeichert';
                stateEl.classList.add('text-danger');
            }
            toast(res.error, 'error', 6000);
            return null;
        }
        if (stateEl) {
            stateEl.textContent = '✓ gespeichert';
            stateEl.classList.remove('text-danger');
        }
        const d = res.data;
        const grade = row.querySelector('[data-grade]');
        if (grade) grade.textContent = d.grade_label ?? '–';
        return d;
    }

    for (const row of root.querySelectorAll('tr[data-pid]')) {
        const area = row.querySelector('[data-person-comment]');
        const stateEl = row.querySelector('[data-save-state]');
        const box = row.querySelector('[data-finalize]');
        if (area && gradedStatus) {
            const save = debounce(() => send(row, { general_comment: area.value }, stateEl), 900);
            area.addEventListener('input', () => {
                if (stateEl) stateEl.textContent = 'Änderung ...';
                save();
            });
            area.addEventListener('blur', () => save.flush());
        }
        if (box && gradedStatus) {
            box.addEventListener('change', async () => {
                if (box.checked && box.dataset.incomplete === '1'
                    && !window.confirm('Bei dieser Person sind noch nicht alle Aufgaben bewertet. Trotzdem abschließen?')) {
                    box.checked = false;
                    return;
                }
                box.disabled = true;
                const d = await send(row, { finalized: box.checked }, stateEl);
                box.disabled = false;
                if (d === null) {
                    box.checked = !box.checked;
                }
            });
        }
    }
}
