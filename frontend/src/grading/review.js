/**
 * Rückgabe an Schüler:innen (Seite /meine-ergebnisse/{id}): Aufgaben, eigene Antworten, Punkte und
 * Kommentare schreibgeschützt. Den Erwartungshorizont zeigt der Server nur, wenn die Lehrkraft ihn
 * freigegeben hat; Musterlösung und Tests gibt es hier nie.
 */
import { h, setTrustedHtml } from '../lib/dom.js';
import { enhanceRichText } from '../lib/rich-text.js';
import { mountAnswer } from './answer-view.js';
import { mountComments } from './comments.js';
import { formatPoints } from './logic.js';

export function mountReview(root, boot) {
    root.replaceChildren();
    const richBlock = (html, className) => {
        const el = h('div', { class: `rich-text ${className ?? ''}`.trim() });
        setTrustedHtml(el, html);
        enhanceRichText(el);
        return el;
    };

    for (const task of boot.tasks) {
        if (task.answer_type === null) {
            root.append(h('section', { class: 'g-review-group', style: { marginLeft: `${Math.min(task.depth, 3) * 12}px` } },
                h('h2', { class: 'g-task-title' }, `${task.number} ${task.title || ''}`.trim()),
                task.body_html ? richBlock(task.body_html) : null));
            continue;
        }

        const earned = task.earned;
        const scoreText = earned === null ? 'noch nicht bewertet' : `${formatPoints(earned)} von ${formatPoints(task.points)} P.`;
        const ratio = earned === null || task.points <= 0 ? null : earned / task.points;
        const scoreClass = ratio === null ? '' : ratio >= 1 ? 'badge-success' : ratio >= 0.5 ? 'badge-primary' : 'badge-warning';

        const answerHost = h('div', { class: 'g-answer card' });
        const commentHost = h('div', { class: 'g-review-comments' });
        const card = h('article', { class: 'card card-pad g-review-task', 'aria-label': `Aufgabe ${task.number}` },
            h('header', { class: 'g-review-head' },
                h('h2', { class: 'g-task-title' }, `${task.number} ${task.title || ''}`.trim()),
                h('span', { class: `badge ${scoreClass}` }, scoreText)),
            task.body_html ? richBlock(task.body_html, 'g-task-body') : null,
            h('h3', { class: 'g-h3' }, 'Deine Antwort'),
            answerHost);

        const comments = task.comments.map((c) => ({ ...c, mine: false }));
        let view = null;
        const panel = comments.length > 0 ? (() => {
            commentHost.append(h('h3', { class: 'g-h3' }, 'Anmerkungen deiner Lehrkraft'));
            return mountComments(commentHost, {
                comments, canWrite: false, showAuthor: false,
                onFocus: (c) => view?.focusComment(c),
            });
        })() : null;
        void panel;
        view = mountAnswer(answerHost, {
            task,
            content: task.answer,
            mode: 'review',
            examId: boot.exam_id,
            comments,
            canComment: false,
            onBubble: (ids) => {
                for (const id of ids) {
                    const li = commentHost.querySelector(`[data-id="${id}"]`);
                    if (li) {
                        li.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                        li.classList.add('is-flash');
                        setTimeout(() => li.classList.remove('is-flash'), 1200);
                    }
                }
            },
        });
        card.append(commentHost);

        if (task.expectation_html) {
            card.append(h('details', { class: 'g-sol-section' },
                h('summary', {}, 'Erwartungshorizont'), richBlock(task.expectation_html)));
        }
        root.append(card);
    }
    if (boot.tasks.length === 0) {
        root.append(h('p', { class: 'text-soft' }, 'Diese Klausur hat keine Aufgaben.'));
    }
}
