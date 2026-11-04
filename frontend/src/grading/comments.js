/**
 * Kommentarliste mit Eingabe: Anmerkungen an Codezeilen, Textstellen oder der ganzen Aufgabe.
 * Wird von der Korrektur (schreibend) und von der Rückgabe (nur lesend) benutzt.
 *
 *   const panel = mountComments(container, {
 *       comments, canWrite, canDeleteAny,
 *       onSave({ id?, anchor, body }) → Promise<comment|null>,
 *       onDelete(id) → Promise<boolean>,
 *       onFocus(comment),                    // Klick auf die Stellenangabe
 *       showAuthor: false,
 *   });
 *   panel.setComments(list); panel.compose(anchor); panel.destroy();
 */
import { h } from '../lib/dom.js';
import { anchorLabel } from './logic.js';

export function mountComments(container, options) {
    let comments = options.comments ?? [];
    let editing = null; // { id|null, anchor, body }
    let busy = false;

    const list = h('ul', { class: 'g-comments', 'aria-label': 'Kommentare' });
    const composerHost = h('div', { class: 'g-composer-host' });
    const addTask = h('button', {
        type: 'button', class: 'btn btn-sm', onclick: () => compose({ kind: 'task' }),
    }, '💬 Kommentar zur ganzen Aufgabe');
    const root = h('div', { class: 'g-comment-panel' }, list, composerHost, options.canWrite ? h('div', { class: 'g-comment-actions' }, addTask) : null);
    container.append(root);

    function render() {
        list.replaceChildren();
        if (comments.length === 0) {
            list.append(h('li', { class: 'g-comment-empty text-faint text-sm' },
                options.canWrite ? 'Noch keine Kommentare. Markiere Code oder Text, um eine Stelle zu kommentieren.' : 'Keine Kommentare.'));
            return;
        }
        for (const c of comments) {
            const canEdit = options.canWrite && (c.mine || options.canDeleteAny);
            const label = h('button', {
                type: 'button', class: 'g-comment-anchor', title: 'Zur Stelle springen',
                onclick: () => options.onFocus?.(c),
            }, anchorLabel(c.anchor));
            const item = h('li', { class: `g-comment g-kind-${c.anchor?.kind ?? 'task'}`, dataset: { id: c.id } },
                h('div', { class: 'g-comment-head' }, label,
                    options.showAuthor && c.grader_name ? h('span', { class: 'text-faint text-sm' }, c.mine ? 'von dir' : `von ${c.grader_name}`) : null),
                h('p', { class: 'g-comment-body' }, c.body),
                canEdit ? h('div', { class: 'g-comment-tools' },
                    c.mine ? h('button', { type: 'button', class: 'btn btn-sm btn-ghost', onclick: () => startEdit(c) }, 'Bearbeiten') : null,
                    h('button', { type: 'button', class: 'btn btn-sm btn-danger-ghost', onclick: () => remove(c) }, 'Löschen')) : null);
            list.append(item);
        }
    }

    function closeComposer() {
        editing = null;
        composerHost.replaceChildren();
    }

    function openComposer() {
        composerHost.replaceChildren();
        if (!editing) {
            return;
        }
        const area = h('textarea', {
            class: 'input', rows: 3, maxlength: 2000, 'aria-label': 'Kommentar', placeholder: 'Dein Kommentar ...',
        });
        area.value = editing.body ?? '';
        const error = h('span', { class: 'text-danger text-sm', role: 'alert' });
        const save = h('button', { type: 'submit', class: 'btn btn-sm btn-primary' }, 'Speichern');
        const form = h('form', { class: 'g-composer' },
            h('div', { class: 'g-composer-anchor' }, 'Kommentar zu: ', h('strong', {}, anchorLabel(editing.anchor))),
            area,
            h('div', { class: 'cluster' }, save,
                h('button', { type: 'button', class: 'btn btn-sm', onclick: closeComposer }, 'Abbrechen'), error));
        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const body = area.value.trim();
            if (body === '') {
                error.textContent = 'Der Kommentar ist leer.';
                area.focus();
                return;
            }
            if (busy) {
                return;
            }
            busy = true;
            save.disabled = true;
            const saved = await options.onSave({ id: editing.id, anchor: editing.anchor, body });
            busy = false;
            save.disabled = false;
            if (saved) {
                closeComposer();
            } else {
                error.textContent = 'Der Kommentar konnte nicht gespeichert werden.';
            }
        });
        form.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape') {
                ev.preventDefault();
                closeComposer();
            } else if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) {
                ev.preventDefault();
                form.requestSubmit();
            }
        });
        composerHost.append(form);
        area.focus();
    }

    function compose(anchor) {
        if (!options.canWrite) {
            return;
        }
        editing = { id: null, anchor, body: '' };
        openComposer();
    }

    function startEdit(comment) {
        editing = { id: comment.id, anchor: comment.anchor, body: comment.body };
        openComposer();
    }

    async function remove(comment) {
        if (!window.confirm('Diesen Kommentar löschen?')) {
            return;
        }
        await options.onDelete?.(comment.id);
    }

    render();

    return {
        setComments(next) {
            comments = next ?? [];
            render();
        },
        compose,
        closeComposer,
        destroy() {
            root.remove();
        },
    };
}
