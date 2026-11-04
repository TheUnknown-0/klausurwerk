/**
 * Reine Hilfslogik der Live-Wand (ohne DOM, mit Node-Tests prüfbar).
 */

/** Ab diesem Alter gelten Live-Daten als veraltet und werden deutlich gekennzeichnet. */
export const STALE_MS = 20000;
/** Abfrage der Live-Daten, solange die Wand sichtbar ist. */
export const POLL_MS = 2000;
/** Fokus erneuern (der Server hält ihn 30 s). */
export const FOCUS_RENEW_MS = 10000;
/** Bild in der Großansicht: etwa jede Sekunde nachsehen. */
export const FOCUS_IMAGE_MS = 1000;

/** Zeilen im Code-Ausschnitt: Karte klein, Großansicht groß. */
export const CARD_CODE_LINES = 7;
export const CARD_CONSOLE_LINES = 3;
export const FOCUS_CODE_LINES = 40;
export const FOCUS_CONSOLE_LINES = 20;

export const VIEW_LABELS = {
    task: 'Aufgabe',
    overview: 'Übersicht',
    aids: 'Hilfsmittel',
};

/** "vor 3 s", "vor 2 min", "vor 1 h"; null bei unbekannt. */
export function ageText(ms) {
    if (ms === null || ms === undefined || Number.isNaN(ms)) {
        return 'noch nichts';
    }
    const s = Math.max(0, Math.round(ms / 1000));
    if (s < 60) return `vor ${s} s`;
    if (s < 3600) return `vor ${Math.floor(s / 60)} min`;
    return `vor ${Math.floor(s / 3600)} h`;
}

/** 'none' (nichts da), 'fresh' oder 'stale' (älter als STALE_MS). */
export function ageLevel(ms) {
    if (ms === null || ms === undefined || Number.isNaN(ms)) return 'none';
    return ms > STALE_MS ? 'stale' : 'fresh';
}

/**
 * Alter seit dem letzten Abruf fortschreiben, damit "vor x s" zwischen den Abrufen weiterläuft.
 */
export function ageNow(ageMs, receivedAt, now) {
    if (ageMs === null || ageMs === undefined) return null;
    return ageMs + Math.max(0, now - receivedAt);
}

/**
 * Welche Inhalte zeigt eine Karte?
 *  - 'disabled': weder Live-Ansicht noch Bildschirmfreigabe eingeschaltet
 *  - 'both', 'screen', 'code': was vorhanden ist
 *  - 'empty': eingeschaltet, aber noch nichts angekommen
 *
 * @param {{live: object|null, screen: object|null}} entry
 * @param {{live_view: boolean, screen_share: boolean}} settings
 */
export function chooseView(entry, settings) {
    const liveOn = !!settings?.live_view;
    const screenOn = !!settings?.screen_share;
    if (!liveOn && !screenOn) return 'disabled';
    const hasScreen = !!entry?.screen;
    const hasCode = liveOn && !!entry?.live;
    if (hasScreen && hasCode) return 'both';
    if (hasScreen) return 'screen';
    if (hasCode) return 'code';
    return 'empty';
}

/** Stabile URL des Bildes: wechselt nur, wenn ts_ms sich ändert. */
export function screenUrl(apiBase, participationId, tsMs) {
    return `${apiBase}/bildschirm/${participationId}?t=${tsMs}`;
}

/** Muss das Bild neu geladen werden? */
export function screenChanged(previousTs, screen) {
    if (!screen) return previousTs !== null && previousTs !== undefined;
    return screen.ts_ms !== previousTs;
}

/**
 * Zeilen des Code-Ausschnitts mit Nummern und Markierung der Cursorzeile.
 * Bei max kleiner als der Ausschnitt wird ein Fenster um die Cursorzeile gewählt.
 *
 * @returns {{no: number, text: string, cursor: boolean}[]}
 */
export function excerptRows(live, max = CARD_CODE_LINES) {
    const code = typeof live?.code_excerpt === 'string' ? live.code_excerpt : '';
    if (code === '') return [];
    const lines = code.replace(/\r\n?/g, '\n').replace(/\n$/, '').split('\n');
    const start = Number.isInteger(live.excerpt_start_line) && live.excerpt_start_line > 0 ? live.excerpt_start_line : 1;
    const cursor = Number.isInteger(live.cursor_line) ? live.cursor_line : null;
    let from = 0;
    if (lines.length > max) {
        const idx = cursor === null ? 0 : Math.min(Math.max(0, cursor - start), lines.length - 1);
        from = Math.min(Math.max(0, idx - Math.floor(max / 2)), lines.length - max);
    }
    return lines.slice(from, from + max).map((text, i) => ({
        no: start + from + i,
        text,
        cursor: cursor !== null && start + from + i === cursor,
    }));
}

/** Letzte Konsolenzeilen (nicht leere Randzeilen entfernt). */
export function consoleRows(live, max = CARD_CONSOLE_LINES) {
    const text = typeof live?.console_tail === 'string' ? live.console_tail : '';
    if (text.trim() === '') return [];
    const lines = text.replace(/\r\n?/g, '\n').replace(/\s+$/, '').split('\n');
    return lines.slice(-max);
}

/** Beschriftung der Ansicht ("Aufgabe 2a: Titel", "Übersicht"). */
export function viewTitle(live, taskNames) {
    if (!live) return '';
    if (live.task_id !== null && live.task_id !== undefined && live.view === 'task') {
        return taskNames?.get?.(live.task_id) ?? `Aufgabe ${live.task_id}`;
    }
    const base = VIEW_LABELS[live.view] ?? (live.view ? String(live.view) : '');
    if (live.task_id !== null && live.task_id !== undefined && taskNames?.has?.(live.task_id)) {
        return `${base}: ${taskNames.get(live.task_id)}`;
    }
    return base;
}

/** Hinweistext zur Art der Bildschirmfläche, leer bei "monitor". */
export function surfaceWarning(screen) {
    if (!screen) return '';
    if (screen.surface === 'monitor') return '';
    if (screen.surface === 'window') return 'Nur ein Fenster freigegeben';
    if (screen.surface === 'browser') return 'Nur ein Tab freigegeben';
    return 'Fläche unbekannt';
}
