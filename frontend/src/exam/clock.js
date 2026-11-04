/**
 * Uhr-Logik der Schüleransicht (rein, ohne DOM).
 *
 * Der Server liefert pro Herzschlag `clock` und `server_time_ms`. Daraus entsteht
 * der Offset (server_time_ms - Date.now()), mit dem die Restzeit lokal weiterläuft.
 */

const pad = (n) => String(n).padStart(2, '0');

/** mm:ss bzw. h:mm:ss */
export function formatClock(ms) {
    const total = Math.max(0, Math.floor(ms / 1000));
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
}

/**
 * Berechnet die Anzeige.
 * @param {{ends_at_ms?: number, remaining_ms?: number, paused?: boolean, unlimited?: boolean, started?: boolean}|null} clock
 * @param {number} offset  server_time_ms - Date.now()
 * @param {number} nowLocal Date.now()
 * @param {{receivedLocal?: number}} [extra]  lokaler Empfangszeitpunkt (Fallback ohne ends_at_ms)
 * @returns {{kind: 'none'|'unlimited'|'paused'|'running'|'overtime', ms: number, text: string, label: string}}
 */
export function clockView(clock, offset, nowLocal, extra = {}) {
    if (!clock || clock.started === false) {
        return { kind: 'none', ms: 0, text: '--:--', label: 'Noch nicht gestartet' };
    }
    if (clock.unlimited) {
        return { kind: 'unlimited', ms: 0, text: '∞', label: 'Ohne Zeitlimit' };
    }
    if (clock.paused) {
        const ms = Math.max(0, clock.remaining_ms ?? 0);
        return { kind: 'paused', ms, text: formatClock(ms), label: 'pausiert' };
    }
    let remaining;
    if (typeof clock.ends_at_ms === 'number') {
        remaining = clock.ends_at_ms - (nowLocal + offset);
    } else {
        remaining = (clock.remaining_ms ?? 0) - (nowLocal - (extra.receivedLocal ?? nowLocal));
    }
    if (remaining <= 0) {
        return { kind: 'overtime', ms: -remaining, text: `+${formatClock(-remaining)}`, label: 'Überziehung' };
    }
    return { kind: 'running', ms: remaining, text: formatClock(remaining), label: 'Restzeit' };
}

/** Speicherstatus als Text. status = {kind}, ageMs = Alter des letzten erfolgreichen Speicherns. */
export function saveStatusText(status, ageMs) {
    switch (status.kind) {
        case 'saving': return 'speichert ...';
        case 'offline': return 'offline, wird nachgereicht';
        case 'blocked': return 'Speichern pausiert';
        case 'error': return 'Speichern fehlgeschlagen';
        case 'idle': return 'noch nichts zu speichern';
        default: {
            const s = Math.max(0, Math.round(ageMs / 1000));
            if (s < 60) {
                return `gespeichert vor ${s} s`;
            }
            return `gespeichert vor ${Math.floor(s / 60)} min`;
        }
    }
}
