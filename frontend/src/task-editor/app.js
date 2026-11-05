/**
 * Aufgaben-Editor: Baum links, Formular der gewählten Aufgabe rechts.
 * Zustand kommt als JSON-Block (#task-editor-boot), gespeichert wird über die JSON-API
 * unter boot.urls.api (siehe TasksController).
 *
 * Zwei Ansichten: die Fragenliste im Stil von Google Forms (quick-list.js, Standard) zum schnellen
 * Anlegen und der Detail-Editor (Baum und vollständiges Formular) für alle Feinheiten.
 *
 * Gespeichert wird automatisch kurz nach der letzten Änderung und vor jedem Wechsel, Verschieben
 * oder Anlegen. Strg+S und der Knopf speichern sofort. Das Formular wird nach dem Speichern
 * nicht neu aufgebaut, damit Fokus und Cursor bleiben, wo sie sind.
 */
import { h, icon, toast } from '../lib/dom.js';
import { bootData, post } from '../lib/api.js';
import { editorSelectionOf, restoreEditorSelection } from '../lib/code-editor.js';
import { captureFocus, restoreFocus } from '../lib/focus-keep.js';
import { createTaskForm } from './form.js';
import { createQuickList } from './quick-list.js';
import { typePicker } from './type-picker.js';
import { button, isDeleteKey } from './ui.js';

/** Wartezeit nach der letzten Eingabe bis zum automatischen Speichern. */
export const AUTOSAVE_MS = 1200;

const VIEW_KEY = 'kw-task-editor-view';
const readView = () => {
    try {
        return localStorage.getItem(VIEW_KEY) === 'detail' ? 'detail' : 'list';
    } catch {
        return 'list';
    }
};
const storeView = (v) => {
    try {
        localStorage.setItem(VIEW_KEY, v);
    } catch {
        // ohne Speicher: beim nächsten Mal wieder die Fragenliste
    }
};

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
    let saving = null; // laufende Speicherung (Promise)
    let editedWhileSaving = false;
    let timer = null;
    let picking = false;

    const treeList = h('ul', { class: 'te-tree-list', role: 'listbox', 'aria-label': 'Aufgaben' });
    const total = h('div', { class: 'te-total' });
    const treeActions = h('div', { class: 'te-tree-actions' });
    const tree = h('aside', { class: 'card te-tree', 'aria-label': 'Aufgabenübersicht' }, h('h2', {}, 'Aufgaben'), treeList, total, treeActions);
    const statusEl = h('span', { class: 'te-status', role: 'status' }, '');
    const saveBtn = h('button', { type: 'button', class: 'btn', onclick: () => save({ auto: false }), title: 'Speichert sofort (Strg+S). Sonst speichert der Editor automatisch.' }, icon('save'), 'Speichern');
    const toolbar = h('div', { class: 'te-toolbar' }, saveBtn, statusEl);
    const formHost = h('div', {});
    const main = h('section', { class: 'card te-main', 'aria-label': 'Aufgabe bearbeiten' }, toolbar, formHost);
    const listHost = h('section', { class: 'qf', 'aria-label': 'Fragenliste' });
    const viewBtn = (v, label, iconName, hint) => h('button', {
        type: 'button', class: 'btn btn-sm', 'aria-pressed': 'false', dataset: { view: v }, title: hint,
        onclick: () => switchView(v),
    }, icon(iconName), label);
    const viewBar = h('div', { class: 'te-viewbar', role: 'group', 'aria-label': 'Ansicht' },
        viewBtn('list', 'Fragenliste', 'list-ordered', 'Alle Fragen untereinander, schnell anlegen und ändern'),
        viewBtn('detail', 'Detail-Editor', 'pencil', 'Eine Aufgabe mit allen Feldern: Musterlösung, Erwartungshorizont, Tests, Operator'));
    root.replaceChildren(viewBar, tree, main, listHost);
    let view = readView();
    let quick = null;

    const byId = (id) => tasks.find((t) => t.id === id) ?? null;
    const childrenOf = (id) => tasks.filter((t) => t.parent_id === id);

    function showStatus(text, kind = '') {
        statusEl.textContent = text;
        statusEl.classList.toggle('dirty', kind === 'dirty');
        statusEl.classList.toggle('error', kind === 'error');
    }

    function setDirty(flag) {
        dirty = flag;
        if (flag) {
            showStatus('Ungespeicherte Änderungen ...', 'dirty');
        } else {
            showStatus(selectedId && !picking ? 'Alles gespeichert' : '');
        }
    }

    function scheduleAutosave() {
        clearTimeout(timer);
        timer = setTimeout(() => { save({ auto: true }); }, AUTOSAVE_MS);
    }

    window.addEventListener('beforeunload', (ev) => {
        if (quick?.pending) {
            quick.flush();
            ev.preventDefault();
            ev.returnValue = '';
            return;
        }
        if (dirty || saving) {
            save({ auto: true });
            ev.preventDefault();
            ev.returnValue = '';
        }
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden' && dirty) {
            save({ auto: true });
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
                class: `te-node depth-${t.depth}${t.id === selectedId && !picking ? ' active' : ''}`,
                role: 'option', tabindex: '0', 'aria-selected': t.id === selectedId ? 'true' : 'false',
                onclick: () => select(t.id),
                onkeydown: (ev) => {
                    if (ev.key === 'Enter' || ev.key === ' ') {
                        ev.preventDefault();
                        select(t.id);
                    } else if (isDeleteKey(ev) && !locked) {
                        // Entf oder Rücktaste: die Aufgabe in der Liste löschen (Rückfrage bleibt).
                        ev.preventDefault();
                        select(t.id).then(() => {
                            if (selectedId === t.id) {
                                remove();
                            }
                        });
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
            // Während der Typauswahl gibt es keine gewählte Aufgabe, auf die sich die Knöpfe beziehen.
            const sel = picking ? null : byId(selectedId);
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

    function renderForm({ preset = null } = {}) {
        // Wird dieselbe Aufgabe neu aufgebaut (etwa nach dem Verschieben), bleibt der Fokus im Feld.
        const keep = form && !picking ? captureFocus(formHost, { extra: editorSelectionOf }) : null;
        clearTimeout(timer);
        picking = false;
        form?.destroy();
        form = null;
        setDirty(false);
        const task = byId(selectedId);
        saveBtn.disabled = !task;
        if (!task) {
            formHost.replaceChildren(h('div', { class: 'te-empty' },
                h('p', {}, 'Wähle links eine Aufgabe oder lege eine neue an.'),
                locked ? null : button([icon('plus'), 'Erste Aufgabe anlegen'], () => addTask(null), { class: 'btn btn-primary' })));
            return;
        }
        form = createTaskForm({
            host: formHost, task, boot, hasChildren: childrenOf(task.id).length > 0, preset,
            onChange: () => {
                setDirty(true);
                if (saving) {
                    editedWhileSaving = true;
                }
                scheduleAutosave();
            },
        });
        restoreFocus(formHost, keep, { extra: restoreEditorSelection });
    }

    function applyTasks(list, newSelected = selectedId) {
        tasks = list;
        selectedId = tasks.some((t) => t.id === newSelected) ? newSelected : (tasks[0]?.id ?? null);
    }

    /** Speichert offene Änderungen. Gelingt das nicht, entscheidet die Lehrkraft, ob sie verworfen werden. */
    async function flushOrConfirm() {
        clearTimeout(timer);
        for (let i = 0; i < 3 && (dirty || saving); i += 1) {
            if (saving) {
                await saving;
                continue;
            }
            if (!(await save({ auto: true }))) {
                break;
            }
        }
        return !dirty || window.confirm('Die Änderungen an dieser Aufgabe ließen sich nicht speichern (Hinweis im Formular). Trotzdem weiter und die Änderungen verwerfen?');
    }

    async function select(id) {
        if ((id === selectedId && !picking) || !(await flushOrConfirm())) {
            return;
        }
        selectedId = id;
        picking = false;
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

    /** Neue Aufgabe: erst den Typ als Kachel wählen, dann wird sie angelegt. */
    async function addTask(parentId) {
        if (!(await flushOrConfirm())) {
            return;
        }
        openPicker(parentId);
    }

    function openPicker(parentId) {
        clearTimeout(timer);
        form?.destroy();
        form = null;
        picking = true;
        dirty = false;
        saveBtn.disabled = true;
        showStatus('');
        const parent = parentId !== null ? byId(parentId) : null;
        const picker = typePicker({
            types: boot.types,
            heading: parent ? `Neue Teilaufgabe zu ${parent.number}: Welcher Antworttyp?` : (tasks.length === 0 ? 'Erste Aufgabe: Welcher Antworttyp?' : 'Neue Aufgabe: Welcher Antworttyp?'),
            onPick: (tile) => createTask(parentId, tile),
            onCancel: tasks.length > 0 ? () => { picking = false; renderTree(); renderForm(); } : null,
        });
        formHost.replaceChildren(picker.el);
        renderTree();
        picker.focus();
    }

    async function createTask(parentId, tile) {
        const res = await call('', { parent_id: parentId, answer_type: tile.type || null }, 'Aufgabe konnte nicht angelegt werden: ');
        if (res?.ok) {
            applyTasks(res.data.tasks, res.data.task_id);
            picking = false;
            renderTree();
            renderForm({ preset: { type: tile.type, config: tile.config } });
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
        clearTimeout(timer);
        if (saving) {
            await saving;
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
        if (!t || !(await flushOrConfirm())) {
            return;
        }
        const res = await call(`/${t.id}/verschieben`, { direction }, 'Verschieben nicht möglich: ');
        if (res?.ok) {
            applyTasks(res.data.tasks, t.id);
            renderTree();
            renderForm();
        }
    }

    /**
     * Speichert die gewählte Aufgabe. auto: still (ohne Erfolgsmeldung), sonst mit Rückmeldung.
     * @returns {Promise<boolean>} ob danach alles gespeichert ist
     */
    async function save({ auto }) {
        clearTimeout(timer);
        if (saving) {
            await saving;
        }
        const t = byId(selectedId);
        if (!t || !form || picking) {
            return true;
        }
        if (!dirty) {
            if (!auto) {
                toast('Alles gespeichert.', 'success', 2000);
            }
            return true;
        }
        form.clearErrors();
        const problems = form.validate();
        if (problems.length > 0) {
            showStatus('Nicht gespeichert: Eingaben im Antworttyp prüfen', 'error');
            if (!auto) {
                toast('Bitte prüfe die Eingaben im Antworttyp.', 'error');
            }
            return false;
        }
        if (busy) {
            // Anlegen, Verschieben oder Löschen läuft gerade: gleich noch einmal versuchen.
            scheduleAutosave();
            return false;
        }
        editedWhileSaving = false;
        showStatus('Speichert ...');
        const run = call(`/${t.id}`, form.getPayload(), 'Speichern fehlgeschlagen: ');
        saving = run;
        const res = await run;
        saving = null;
        if (!res?.ok) {
            showStatus('Nicht gespeichert', 'error');
            if (res && res.data?.field) {
                form?.showServerError(res.data.field, res.error);
            }
            return false;
        }
        applyTasks(res.data.tasks, t.id);
        renderTree();
        if (editedWhileSaving) {
            // Was seit dem Absenden getippt wurde, geht mit der nächsten Speicherung raus.
            setDirty(true);
            scheduleAutosave();
            return false;
        }
        setDirty(false);
        if (!auto) {
            toast('Aufgabe gespeichert.', 'success', 2000);
        }
        return true;
    }

    document.addEventListener('keydown', (ev) => {
        if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's' && root.contains(document.activeElement)) {
            ev.preventDefault();
            if (view === 'list') {
                quick?.saveNow();
            } else {
                save({ auto: false });
            }
        }
    });

    // ---------- Ansichten ----------
    function paintViewBar() {
        for (const b of viewBar.querySelectorAll('button')) {
            const on = b.dataset.view === view;
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            b.classList.toggle('btn-primary', on);
        }
        tree.hidden = view !== 'detail';
        main.hidden = view !== 'detail';
        listHost.hidden = view !== 'list';
        root.classList.toggle('is-list', view === 'list');
    }

    function showDetail() {
        renderTree();
        if (tasks.length === 0 && !locked) {
            // Neue Klausur: gleich mit der ersten Aufgabe beginnen.
            openPicker(null);
        } else {
            renderForm();
        }
    }

    function showList(activeId = null) {
        // Das Formular der Detailansicht abbauen: Auswahl-Editoren in beiden Ansichten teilten
        // sich sonst die Namen ihrer Optionsfelder.
        clearTimeout(timer);
        form?.destroy();
        form = null;
        picking = false;
        dirty = false;
        quick ??= createQuickList({
            host: listHost, boot, locked,
            getTasks: () => tasks,
            setTasks: (list) => { tasks = list; },
            call,
            openDetail: (id) => switchView('detail', id),
        });
        quick.setActive(activeId);
        quick.render({ focus: activeId !== null ? 'title' : null });
    }

    async function switchView(next, taskId = null) {
        if (next === view && taskId === null) {
            return;
        }
        if (view === 'list' && quick && !(await quick.flush())) {
            return;
        }
        if (view === 'detail' && !(await flushOrConfirm())) {
            return;
        }
        if (next === 'detail') {
            const target = taskId ?? quick?.activeId ?? selectedId;
            if (target !== null && tasks.some((t) => t.id === target)) {
                selectedId = target;
            } else if (!tasks.some((t) => t.id === selectedId)) {
                selectedId = tasks[0]?.id ?? null;
            }
            quick?.destroy();
            view = 'detail';
            paintViewBar();
            showDetail();
        } else {
            view = 'list';
            paintViewBar();
            showList(picking ? null : selectedId);
        }
        storeView(view);
    }

    paintViewBar();
    if (view === 'list') {
        showList(null);
    } else {
        showDetail();
    }
}
