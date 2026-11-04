/**
 * Aufgaben-Editor: Baum links, Formular der gewählten Aufgabe rechts.
 * Zustand kommt als JSON-Block (#task-editor-boot), gespeichert wird über die JSON-API
 * unter boot.urls.api (siehe TasksController).
 */
import { h, icon, toast } from '../lib/dom.js';
import { bootData, post } from '../lib/api.js';
import { createTaskForm } from './form.js';
import { button, isDeleteKey } from './ui.js';

export function initTaskEditor() {
    const root = document.getElementById('task-editor-app');
    const boot = bootData('task-editor-boot');
    if (!root || !boot) {
        root?.replaceChildren(h('div', { class: 'alert alert-error' }, 'Der Editor konnte nicht geladen werden.'));
        return;
    }

    const locked = !!boot.exam.locked;
    let tasks = boot.tasks ?? [];
    let selectedId = tasks[0]?.id ?? null;
    let form = null;
    let dirty = false;
    let busy = false;

    const treeList = h('ul', { class: 'te-tree-list', role: 'listbox', 'aria-label': 'Aufgaben' });
    const total = h('div', { class: 'te-total' });
    const treeActions = h('div', { class: 'te-tree-actions' });
    const tree = h('aside', { class: 'card te-tree', 'aria-label': 'Aufgabenübersicht' }, h('h2', {}, 'Aufgaben'), treeList, total, treeActions);
    const statusEl = h('span', { class: 'te-status', role: 'status' }, '');
    const saveBtn = h('button', { type: 'button', class: 'btn btn-primary', onclick: () => save() }, 'Speichern');
    const toolbar = h('div', { class: 'te-toolbar' }, saveBtn, statusEl);
    const formHost = h('div', {});
    const main = h('section', { class: 'card te-main', 'aria-label': 'Aufgabe bearbeiten' }, toolbar, formHost);
    root.replaceChildren(tree, main);

    const byId = (id) => tasks.find((t) => t.id === id) ?? null;
    const childrenOf = (id) => tasks.filter((t) => t.parent_id === id);

    function setDirty(flag) {
        dirty = flag;
        statusEl.textContent = flag ? 'Ungespeicherte Änderungen' : (selectedId ? 'Gespeichert' : '');
        statusEl.classList.toggle('dirty', flag);
    }

    window.addEventListener('beforeunload', (ev) => {
        if (dirty) {
            ev.preventDefault();
            ev.returnValue = '';
        }
    });

    // ---------- Baum ----------
    function renderTree() {
        // Fokus über das Neuzeichnen retten (Klick und Tastatur sollen in der Liste bleiben).
        const active = document.activeElement;
        const listHadFocus = !!active && treeList.contains(active);
        const actionIndex = active && treeActions.contains(active) ? [...treeActions.children].indexOf(active.closest?.('button') ?? active) : -1;
        treeList.replaceChildren();
        for (const t of tasks) {
            const li = h('li', {
                class: `te-node depth-${t.depth}${t.id === selectedId ? ' active' : ''}`,
                role: 'option', tabindex: '0', 'aria-selected': t.id === selectedId ? 'true' : 'false',
                onclick: () => select(t.id),
                onkeydown: (ev) => {
                    if (ev.key === 'Enter' || ev.key === ' ') {
                        ev.preventDefault();
                        select(t.id);
                    } else if (isDeleteKey(ev) && !locked) {
                        // Entf oder Rücktaste: die Aufgabe in der Liste löschen (Rückfrage bleibt).
                        ev.preventDefault();
                        if (t.id !== selectedId) {
                            select(t.id);
                        }
                        if (selectedId === t.id) {
                            remove();
                        }
                    }
                },
            },
            h('span', { class: 'te-num' }, t.number),
            h('span', { class: 'te-name' }, t.title || '(ohne Titel)'),
            t.answer_type ? h('span', { class: 'badge' }, t.answer_type) : null,
            h('span', { class: 'te-pts' }, `${fmt(t.points)} P.`));
            treeList.append(li);
        }
        if (tasks.length === 0) {
            treeList.append(h('li', { class: 'text-soft' }, 'Noch keine Aufgabe.'));
        }
        const sum = tasks.filter((t) => t.answer_type).reduce((a, t) => a + Number(t.points || 0), 0);
        total.textContent = `Gesamt: ${fmt(sum)} Punkte`;

        treeActions.replaceChildren();
        if (!locked) {
            const sel = byId(selectedId);
            treeActions.append(
                button([icon('plus'), 'Aufgabe'], () => addTask(null)),
                button([icon('plus'), 'Teilaufgabe'], () => addTask(sel?.parent_id ?? sel?.id ?? null), { disabled: !sel }),
                button(icon('arrow-up'), () => move('up'), { disabled: !sel, 'aria-label': 'Nach oben' }),
                button(icon('arrow-down'), () => move('down'), { disabled: !sel, 'aria-label': 'Nach unten' }),
                button(icon('arrow-right'), () => move('indent'), { disabled: !sel, 'aria-label': 'Zur Teilaufgabe machen', title: 'Zur Teilaufgabe der vorherigen Aufgabe machen' }),
                button(icon('arrow-left'), () => move('outdent'), { disabled: !sel, 'aria-label': 'Auf oberste Ebene', title: 'Auf die oberste Ebene holen' }),
                button([icon('trash-2'), 'Löschen'], () => remove(), { disabled: !sel, class: 'btn btn-sm btn-danger-ghost', title: 'Gewählte Aufgabe löschen (Entf oder Rücktaste in der Liste)' }),
            );
        } else {
            treeActions.append(h('span', { class: 'text-sm text-soft' }, 'Struktur gesperrt (Klausur gestartet).'));
        }

        if (listHadFocus) {
            treeList.querySelector('.te-node.active')?.focus?.({ preventScroll: true });
        } else if (actionIndex >= 0) {
            const again = treeActions.children[actionIndex];
            if (again && !again.disabled) {
                again.focus?.();
            } else {
                treeList.querySelector('.te-node.active')?.focus?.({ preventScroll: true });
            }
        }
    }

    const fmt = (n) => String(Math.round(Number(n) * 100) / 100).replace('.', ',');

    function renderForm() {
        form?.destroy();
        form = null;
        const task = byId(selectedId);
        saveBtn.disabled = !task;
        if (!task) {
            formHost.replaceChildren(h('div', { class: 'te-empty' },
                h('p', {}, 'Wähle links eine Aufgabe oder lege eine neue an.'),
                locked ? null : button([icon('plus'), 'Erste Aufgabe anlegen'], () => addTask(null), { class: 'btn btn-primary' })));
            setDirty(false);
            return;
        }
        form = createTaskForm({
            host: formHost, task, boot, hasChildren: childrenOf(task.id).length > 0,
            onChange: () => setDirty(true),
        });
        setDirty(false);
    }

    function applyTasks(list, newSelected = selectedId) {
        tasks = list;
        selectedId = tasks.some((t) => t.id === newSelected) ? newSelected : (tasks[0]?.id ?? null);
    }

    function confirmLeave() {
        return !dirty || window.confirm('Es gibt ungespeicherte Änderungen an dieser Aufgabe. Wirklich wechseln und die Änderungen verwerfen?');
    }

    function select(id) {
        if (id === selectedId || !confirmLeave()) {
            return;
        }
        selectedId = id;
        renderTree();
        renderForm();
    }

    // ---------- Aktionen ----------
    async function call(path, body, errorPrefix) {
        if (busy) {
            return null;
        }
        busy = true;
        const res = await post(`${boot.urls.api}${path}`, body);
        busy = false;
        if (!res.ok) {
            toast(`${errorPrefix}${res.error}`, 'error', 8000);
            return res;
        }
        return res;
    }

    async function addTask(parentId) {
        if (!confirmLeave()) {
            return;
        }
        const res = await call('', { parent_id: parentId }, 'Aufgabe konnte nicht angelegt werden: ');
        if (res?.ok) {
            applyTasks(res.data.tasks, res.data.task_id);
            renderTree();
            renderForm();
            form?.focus();
        }
    }

    async function remove() {
        const t = byId(selectedId);
        if (!t) {
            return;
        }
        const kids = childrenOf(t.id).length;
        const msg = `Aufgabe ${t.number} "${t.title || 'ohne Titel'}" wirklich löschen?${kids ? ` Ihre ${kids} Teilaufgabe(n) werden ebenfalls gelöscht.` : ''} Das lässt sich nicht rückgängig machen.`;
        if (!window.confirm(msg)) {
            return;
        }
        const res = await call(`/${t.id}/loeschen`, {}, 'Löschen nicht möglich: ');
        if (res?.ok) {
            const idx = tasks.findIndex((x) => x.id === t.id);
            applyTasks(res.data.tasks, tasks[idx + 1]?.id ?? tasks[idx - 1]?.id ?? null);
            dirty = false;
            renderTree();
            renderForm();
        }
    }

    async function move(direction) {
        const t = byId(selectedId);
        if (!t) {
            return;
        }
        if (dirty) {
            toast('Speichere zuerst deine Änderungen, bevor du die Aufgabe verschiebst.', 'warning');
            return;
        }
        const res = await call(`/${t.id}/verschieben`, { direction }, 'Verschieben nicht möglich: ');
        if (res?.ok) {
            applyTasks(res.data.tasks, t.id);
            renderTree();
            renderForm();
        }
    }

    async function save() {
        const t = byId(selectedId);
        if (!t || !form || busy) {
            return;
        }
        form.clearErrors();
        const problems = form.validate();
        if (problems.length > 0) {
            toast('Bitte prüfe die Eingaben im Antworttyp.', 'error');
            return;
        }
        saveBtn.disabled = true;
        statusEl.textContent = 'Speichert ...';
        const res = await call(`/${t.id}`, form.getPayload(), 'Speichern fehlgeschlagen: ');
        saveBtn.disabled = false;
        if (res?.ok) {
            applyTasks(res.data.tasks, t.id);
            renderTree();
            renderForm();
            toast('Aufgabe gespeichert.', 'success', 2500);
        } else {
            statusEl.textContent = 'Nicht gespeichert';
            if (res && res.data?.field) {
                form.showServerError(res.data.field, res.error);
            }
        }
    }

    document.addEventListener('keydown', (ev) => {
        if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's' && root.contains(document.activeElement)) {
            ev.preventDefault();
            save();
        }
    });

    renderTree();
    renderForm();
}
