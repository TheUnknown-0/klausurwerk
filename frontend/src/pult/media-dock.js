/**
 * Pult: Aufnahmen (Audio/Video) der Klausur zentral im Raum abspielen. Eine kleine Leiste am
 * unteren Rand, die offen bleibt, während die Lehrkraft im Pult weiterarbeitet. Video lässt sich
 * im Vollbild zeigen (Beamer). Abspielen hier zählt nicht bei den Schüler:innen.
 */
import { h, icon } from '../lib/dom.js';

const POLICY_HINT = {
    teacher: 'nur hier im Pult',
    free: 'Schüler:innen dürfen auch selbst abspielen',
    limited: 'Schüler:innen dürfen begrenzt selbst abspielen',
};

/** @param {{id: number, name: string, mime: string, url: string, policy: string}[]} media */
export function createMediaDock(media) {
    if (!Array.isArray(media) || media.length === 0) {
        return null;
    }
    const players = [];
    const list = h('div', { class: 'media-dock-list' }, media.map((m) => {
        const video = String(m.mime).startsWith('video/');
        const el = h(video ? 'video' : 'audio', { controls: '', preload: 'metadata', src: m.url, class: 'media-dock-el' });
        players.push(el);
        // Immer nur eine Aufnahme gleichzeitig
        el.addEventListener('play', () => players.forEach((p) => p !== el && p.pause()));
        return h('div', { class: 'media-dock-item' },
            h('div', { class: 'media-dock-name' }, icon(video ? 'film' : 'headphones'), ' ', h('strong', {}, m.name),
                h('span', { class: 'text-faint text-sm' }, ` (${POLICY_HINT[m.policy] ?? ''})`)),
            el);
    }));
    const closeBtn = h('button', { type: 'button', class: 'modal-close', 'aria-label': 'Aufnahmen schließen' }, '×');
    const panel = h('aside', { class: 'media-dock', hidden: true, 'aria-label': 'Aufnahmen' },
        h('div', { class: 'media-dock-head' }, h('strong', {}, 'Aufnahmen zum Abspielen im Raum'), closeBtn),
        list);
    document.body.append(panel);

    const button = h('button', { type: 'button', class: 'btn btn-sm btn-ghost', 'aria-expanded': 'false' }, icon('headphones'), ` Aufnahmen (${media.length})`);
    const toggle = (open = panel.hidden) => {
        panel.hidden = !open;
        button.setAttribute('aria-expanded', String(open));
        if (!open) {
            players.forEach((p) => p.pause());
        }
    };
    button.addEventListener('click', () => toggle());
    closeBtn.addEventListener('click', () => toggle(false));

    return { button, panel };
}
