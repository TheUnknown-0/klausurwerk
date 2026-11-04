/**
 * Hilfen rund um die Aufgabenliste (rein, ohne DOM).
 */

/** Hat die Aufgabe ein Antwortfeld? (Container haben answer_type null.) */
export const isAnswerable = (task) => !!task?.answer_type;

/** Flache Liste aller Aufgaben mit Tiefe, in Anzeigereihenfolge. */
export function flattenTasks(tasks, depth = 0, parent = null, out = []) {
    for (const t of tasks ?? []) {
        out.push({ task: t, depth, parent });
        flattenTasks(t.children ?? [], depth + 1, t, out);
    }
    return out;
}

/** Alle Aufgaben mit Antwortfeld. */
export const answerableTasks = (tasks) => flattenTasks(tasks).map((x) => x.task).filter(isAnswerable);

/** "1a Titel" für Listen und Dialoge. */
export function taskLabel(task) {
    const num = task.number ? String(task.number) : '';
    const title = task.title ? String(task.title) : '';
    return [num, title].filter(Boolean).join(' ') || `Aufgabe ${task.id}`;
}

const isEmptyJson = (v) => {
    if (v === null || v === undefined) {
        return true;
    }
    if (Array.isArray(v)) {
        return v.length === 0;
    }
    if (typeof v === 'object') {
        return Object.keys(v).length === 0;
    }
    return false;
};

/** Ist zu dieser Aufgabe etwas beantwortet? */
export function isAnswered(type, content) {
    if (!content || typeof content !== 'object') {
        return false;
    }
    switch (type) {
        case 'text':
            return typeof content.text === 'string' && content.text.trim() !== '';
        case 'choice':
            return Array.isArray(content.selected) && content.selected.length > 0;
        case 'matching':
            return !isEmptyJson(content.pairs) && Object.values(content.pairs).some((v) => v !== '' && v !== null);
        case 'ordering':
            return Array.isArray(content.order) && content.order.length > 0;
        case 'gaps':
            return !isEmptyJson(content.values) && Object.values(content.values).some((v) => String(v ?? '').trim() !== '');
        case 'code':
        case 'robo':
            return Array.isArray(content.files) && content.files.some((f) => String(f?.content ?? '').trim() !== '');
        default:
            return !isEmptyJson(content);
    }
}

/**
 * Status je Aufgabe: 'empty' | 'done' | 'flagged' (Markierung geht vor).
 */
export function taskStatus(task, answer, touched = false) {
    if (answer?.flagged) {
        return 'flagged';
    }
    if (touched || isAnswered(task.answer_type, answer?.content)) {
        return 'done';
    }
    return 'empty';
}

/** Zusammenfassung für Fortschritt und Abgabedialog. */
export function summarize(tasks, answers, touchedIds = new Set()) {
    const list = answerableTasks(tasks);
    const unanswered = [];
    const flagged = [];
    let answered = 0;
    for (const t of list) {
        const a = answers[String(t.id)];
        const done = touchedIds.has(String(t.id)) || isAnswered(t.answer_type, a?.content);
        if (done) {
            answered++;
        } else {
            unanswered.push(t);
        }
        if (a?.flagged) {
            flagged.push(t);
        }
    }
    return { total: list.length, answered, unanswered, flagged };
}
