/**
 * Zeitachse des Replays: echte Zeitstempel in "Wiedergabezeit" umrechnen. Pausen von mehr als
 * GAP_MS werden auf SHORT_GAP_MS verkürzt, damit das Abspielen nicht minutenlang wartet.
 * Reine Logik ohne DOM.
 */

export const GAP_MS = 60_000;
export const SHORT_GAP_MS = 3_000;
/** Ohne Zeitstempel oder bei gleichen Zeitstempeln bekommt jeder Schritt mindestens diese Wiedergabezeit. */
export const MIN_STEP_MS = 40;

/**
 * @param {{ ts: number|null, isPaste: boolean, isOvertime: boolean }[]} steps  nach seq sortiert
 * @returns {{
 *   real: number[], virtual: number[], total: number,
 *   markers: { index: number, type: 'paste'|'overtime', at: number }[],
 *   skipped: { index: number, ms: number }[]
 * }}
 */
export function buildTimeline(steps) {
    const real = [];
    const virtual = [];
    const skipped = [];
    let lastReal = null;
    let v = 0;
    steps.forEach((step, i) => {
        const ts = Number.isFinite(step.ts) ? step.ts : lastReal;
        const t = lastReal === null ? (ts ?? 0) : Math.max(lastReal, ts ?? lastReal);
        if (i === 0) {
            virtual.push(0);
        } else {
            const dt = t - lastReal;
            if (dt > GAP_MS) {
                skipped.push({ index: i, ms: dt });
                v += SHORT_GAP_MS;
            } else {
                v += Math.max(MIN_STEP_MS, dt);
            }
            virtual.push(v);
        }
        real.push(t);
        lastReal = t;
    });
    const markers = [];
    steps.forEach((step, i) => {
        if (step.isPaste) {
            markers.push({ index: i, type: 'paste', at: virtual[i] });
        }
        if (step.isOvertime) {
            markers.push({ index: i, type: 'overtime', at: virtual[i] });
        }
    });
    return { real, virtual, total: virtual.length > 0 ? virtual[virtual.length - 1] : 0, markers, skipped };
}

/** Index des letzten Schritts, dessen Wiedergabezeit höchstens `time` beträgt (-1 davor). */
export function indexAt(timeline, time) {
    const arr = timeline.virtual;
    let lo = 0;
    let hi = arr.length - 1;
    let best = -1;
    while (lo <= hi) {
        const mid = (lo + hi) >> 1;
        if (arr[mid] <= time) {
            best = mid;
            lo = mid + 1;
        } else {
            hi = mid - 1;
        }
    }
    return best;
}

/** Nächster (dir = 1) oder vorheriger (dir = -1) Marker der Art `type` relativ zu `index`; null wenn keiner. */
export function nextMarker(timeline, type, index, dir) {
    const list = timeline.markers.filter((m) => m.type === type);
    if (dir > 0) {
        return list.find((m) => m.index > index) ?? null;
    }
    for (let i = list.length - 1; i >= 0; i--) {
        if (list[i].index < index) {
            return list[i];
        }
    }
    return null;
}

/** "5 Minuten", "1 Stunde 3 Minuten" für verkürzte Pausen. */
export function formatGap(ms) {
    const minutes = Math.round(ms / 60000);
    if (minutes < 60) {
        return `${minutes} ${minutes === 1 ? 'Minute' : 'Minuten'}`;
    }
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    return `${hours} ${hours === 1 ? 'Stunde' : 'Stunden'}${rest ? ` ${rest} ${rest === 1 ? 'Minute' : 'Minuten'}` : ''}`;
}
