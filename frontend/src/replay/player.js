/**
 * Platzhalter: Replay-Abspieler (Stufe 4).
 *   mountReplay(container, { examId, participationId, taskId, apiBase }) → { destroy() }
 */
export function mountReplay(container) {
    container.textContent = 'Replay ist noch nicht verfügbar.';
    return { destroy: () => container.replaceChildren() };
}
