/**
 * Vorschau einer einzelnen Aufgabe: Der Aufgaben-Editor öffnet die Schüleransicht der Klausur
 * (/klausuren/{id}/vorschau) mit ?aufgabe={taskId}, die Vorschau springt dann zu dieser Aufgabe.
 */

/** Adresse der Vorschau, die bei einer Aufgabe beginnt. examUrl: /klausuren/{id} samt BASE_URL. */
export function previewUrl(examUrl, taskId) {
    return `${String(examUrl).replace(/\/+$/, '')}/vorschau?aufgabe=${encodeURIComponent(String(taskId))}`;
}

/** Aufgaben-Kennung aus dem Query-String der Vorschau, sonst null. */
export function previewTarget(search) {
    const raw = new URLSearchParams(String(search ?? '')).get('aufgabe');
    if (raw === null || !/^[1-9][0-9]{0,9}$/.test(raw)) {
        return null;
    }
    return Number(raw);
}

/** Fensternamen je Klausur: weitere Klicks verwenden denselben Vorschau-Tab. */
export const previewWindowName = (examId) => `kw-vorschau-${String(examId).replace(/[^0-9]/g, '')}`;

/**
 * Wohin "Vorschau beenden" führt. Hat der Aufgaben-Editor den Tab geöffnet, wird er geschlossen
 * (das Editor-Fenster steht noch dahinter). Sonst zurück in den Editor, wenn die Vorschau von dort
 * kam (?aufgabe=), oder zur Klausurseite.
 *
 * @param {{ windowName: string, hasOpener: boolean, search: string, examUrl: string, editorUrl: string }} o
 * @returns {{ close: boolean, url: string }}
 */
export function previewExit({ windowName, hasOpener, search, examUrl, editorUrl }) {
    const fromEditor = previewTarget(search) !== null;
    return {
        close: hasOpener && String(windowName ?? '').startsWith('kw-vorschau-'),
        url: fromEditor && editorUrl ? editorUrl : examUrl,
    };
}
