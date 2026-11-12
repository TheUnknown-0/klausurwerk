/**
 * Audio/Video im Aufgabentext (Hörverstehen, Hör-Seh-Verstehen).
 *
 * Abspielregel je Datei (Services\MediaPlays):
 *  - 'teacher': nur ein Hinweis, die Lehrkraft spielt im Pult für alle ab (Vorschau: abspielbar);
 *  - 'free': normaler Spieler, beliebig oft;
 *  - 'limited': eigener Spieler ohne Vorspulen; jeder Start fragt den Server, der zählt und eine
 *    Einmal-Adresse liefert. Die Datei wird ganz geladen und aus dem Speicher abgespielt.
 *
 *   const p = createMediaPlayer({ material, plays, preview });
 *   host.append(p.el); p.setDisabled(true); p.destroy();
 */
import { h, icon } from '../lib/dom.js';
import { post } from '../lib/api.js';

const fmtTime = (s) => {
    if (!Number.isFinite(s) || s < 0) {
        return '0:00';
    }
    const m = Math.floor(s / 60);
    return `${m}:${String(Math.floor(s % 60)).padStart(2, '0')}`;
};

/** Text für die verbleibenden Starts. */
export function playsLeftText(plays, limit) {
    if (!limit) {
        return '';
    }
    const left = Math.max(0, limit - plays);
    if (left === 0) {
        return `Schon ${limit}-mal abgespielt, kein weiterer Start möglich.`;
    }
    return `Noch ${left} von ${limit} ${limit === 1 ? 'Start' : 'Starts'}. Vorspulen geht nicht, Pause schon.`;
}

/** @param {{material: object, plays?: number, preview?: boolean}} o */
export function createMediaPlayer(o) {
    const m = o.material;
    const media = m.media ?? { policy: 'free', limit: null, play_url: null };
    const isVideo = String(m.mime ?? '').startsWith('video/');
    const kind = isVideo ? 'Video' : 'Hörtext';
    const policy = o.preview ? 'free' : media.policy;
    let plays = Number(o.plays ?? 0);
    let disabled = false;
    let objectUrl = null;

    const title = h('div', { class: 'media-title' }, icon(isVideo ? 'film' : 'headphones'), h('strong', {}, m.name));
    const el = h('div', { class: `media-box is-${policy}`, role: 'group', 'aria-label': `${kind}: ${m.name}` }, title);

    if (policy === 'teacher') {
        el.append(h('p', { class: 'text-soft text-sm' }, `Diese Aufnahme spielt die Lehrkraft für alle ab. Hör gut zu.`));
        return { el, setDisabled() {}, destroy: () => el.remove() };
    }

    const player = h(isVideo ? 'video' : 'audio', { class: 'media-el', preload: 'none', playsinline: '' });
    const status = h('p', { class: 'text-soft text-sm media-status', 'aria-live': 'polite' });
    const startBtn = h('button', { type: 'button', class: 'btn btn-primary btn-sm' }, icon('play'), `${kind} abspielen`);
    const pauseBtn = h('button', { type: 'button', class: 'btn btn-sm', hidden: true }, icon('pause'), 'Pause');
    const bar = h('progress', { class: 'media-progress', max: 1, value: 0, hidden: true });
    const time = h('span', { class: 'media-time text-sm', hidden: true });
    const controls = h('div', { class: 'cluster media-controls' }, startBtn, pauseBtn, bar, time);
    el.append(controls, player, status);
    player.hidden = !isVideo;

    const limited = policy === 'limited';
    const refresh = () => {
        if (limited) {
            status.textContent = playsLeftText(plays, media.limit);
            startBtn.disabled = disabled || plays >= media.limit;
        } else {
            startBtn.disabled = disabled;
        }
    };

    const release = () => {
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        }
        player.removeAttribute('src');
        player.load();
    };

    if (limited) {
        // Eigene Steuerung, kein Vorspulen.
        player.controls = false;
        player.addEventListener('timeupdate', () => {
            bar.max = player.duration || 1;
            bar.value = player.currentTime;
            time.textContent = `${fmtTime(player.currentTime)} / ${fmtTime(player.duration)}`;
        });
        player.addEventListener('ended', () => {
            pauseBtn.hidden = true;
            bar.hidden = true;
            time.hidden = true;
            startBtn.hidden = false;
            release();
            refresh();
        });
        pauseBtn.addEventListener('click', () => {
            if (player.paused) {
                player.play();
                pauseBtn.replaceChildren(icon('pause'), 'Pause');
            } else {
                player.pause();
                pauseBtn.replaceChildren(icon('play'), 'Weiter');
            }
        });
    } else {
        player.controls = true;
    }

    startBtn.addEventListener('click', async () => {
        if (disabled) {
            return;
        }
        startBtn.disabled = true;
        status.textContent = 'Lade ...';
        const res = await post(media.play_url, {}, { timeoutMs: 20000 });
        if (!res.ok) {
            status.textContent = res.error;
            if (res.status === 409 && limited) {
                plays = media.limit;
            }
            refresh();
            return;
        }
        plays = Number(res.data.plays ?? plays);
        try {
            // Ganz laden und aus dem Speicher abspielen (Einmal-Adresse, kein Neuladen beim Spulen).
            const file = await fetch(res.data.url, { credentials: 'same-origin' });
            if (!file.ok) {
                throw new Error(`HTTP ${file.status}`);
            }
            release();
            objectUrl = URL.createObjectURL(await file.blob());
            player.src = objectUrl;
            player.hidden = !isVideo && limited;
            if (limited) {
                startBtn.hidden = true;
                pauseBtn.hidden = false;
                pauseBtn.replaceChildren(icon('pause'), 'Pause');
                bar.hidden = false;
                time.hidden = false;
            } else {
                player.hidden = false;
                startBtn.hidden = true;
            }
            status.textContent = '';
            refresh();
            try {
                await player.play();
            } catch {
                // Browser verweigert den automatischen Start: dann per Knopf weiter.
                pauseBtn.replaceChildren(icon('play'), 'Weiter');
            }
        } catch {
            status.textContent = 'Die Aufnahme konnte nicht geladen werden. Sag der Lehrkraft Bescheid.';
            startBtn.hidden = false;
            refresh();
        }
    });
    refresh();

    return {
        el,
        setDisabled(flag) {
            disabled = !!flag;
            if (disabled && !player.paused) {
                player.pause();
            }
            refresh();
        },
        destroy() {
            release();
            el.remove();
        },
    };
}
