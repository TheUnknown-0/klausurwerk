/**
 * Antwort-Widget "robo": Gitterwelt mit Robo (Java oder Python).
 *
 * Links die Welt mit Steuerung (Geschwindigkeit, Pause, Schritt, Zurücksetzen), darunter der
 * Code-Editor des Widgets "code" samt Konsole. Das Programm läuft wie bei Code-Aufgaben
 * (Python im Browser, Java auf dem Server); die Züge kommen als Protokoll zurück und werden hier
 * animiert (frontend/src/robo/*). Inhalt und Änderungsschritte entsprechen dem Widget "code":
 *   Inhalt: { files: [{ name, content }] }   (docs/DATENFORMATE.md, 2.16)
 */
import { registerAnswerWidget } from '../lib/widgets.js';
import { h } from '../lib/dom.js';
import CodeWidget from './code.js';
import { RoboWorld, normalizeWorldData } from '../robo/world.js';
import { createWorldView, statusText } from '../robo/render.js';
import { createPlayer, DEFAULT_SPEED_LEVEL, SPEEDS } from '../robo/player.js';
import { wrapRuntime } from '../robo/session.js';

const SPEED_KEY = 'kw.roboSpeed';
const SPEED_NAMES = ['sehr langsam', 'langsam', 'normal', 'schnell', 'sehr schnell', 'sofort'];

const storage = {
    get(key) {
        try {
            return window.localStorage.getItem(key);
        } catch {
            return null;
        }
    },
    set(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch {
            // nur eine Annehmlichkeit
        }
    },
};

export const DEFAULT_START = {
    python: '# Schreibe hier dein Programm.\n# Befehle: vor(), links(), rechts(), aufheben(), ablegen(), schieben()\n# Fragen: vorneFrei(), vorneWand(), vorneKiste(), aufSchraube(), aufZiel()\nfrom robo import *\n\n',
    java: 'public class Main {\n    public static void main(String[] args) {\n        // Befehle: Robo.vor(), Robo.links(), Robo.rechts(), Robo.aufheben(), Robo.ablegen(), Robo.schieben()\n        // Fragen: Robo.vorneFrei(), Robo.vorneWand(), Robo.vorneKiste(), Robo.aufSchraube(), Robo.aufZiel()\n\n    }\n}\n',
};

/** Konfiguration für das eingebettete Code-Widget. */
export function codeTaskFor(task) {
    const c = task.config ?? {};
    const language = c.language === 'python' ? 'python' : 'java';
    const main = c.main_file || (language === 'python' ? 'main.py' : 'Main.java');
    return {
        ...task,
        answer_type: 'code',
        config: {
            language,
            java_version: c.java_version ?? null,
            run_mode: c.run_mode ?? null,
            main_file: main,
            allow_new_files: false,
            stdin: '',
            gui: false,
            sample_tests: [],
            sql_setup: '',
            python_packages: null,
        },
        files: [{ name: main, content: c.start_code || DEFAULT_START[language], readonly: false }],
    };
}

const RoboWidget = {
    layout: 'split',

    mount(container, ctx) {
        const task = ctx.task;
        const config = task.config ?? {};
        const language = config.language === 'python' ? 'python' : 'java';
        const env = ctx.env ?? {};
        let destroyed = false;

        let worldData;
        try {
            worldData = normalizeWorldData(config.world);
        } catch (e) {
            const box = h('div', { class: 'alert alert-danger', role: 'alert' }, `Die Welt dieser Aufgabe ist fehlerhaft: ${e.message}`);
            container.append(box);
            return { getContent: () => ctx.content ?? { files: [] }, setReadOnly() {}, destroy: () => box.remove() };
        }

        // ---------- Welt und Steuerung ----------
        const initial = new RoboWorld(worldData);
        const stage = h('div', { class: 'robo-stage' });
        const status = h('p', { class: 'robo-status', 'aria-live': 'polite' });
        const alertBox = h('div', { class: 'robo-alert alert alert-danger', role: 'alert', hidden: true });
        const doneBox = h('div', { class: 'robo-alert alert alert-success', role: 'status', hidden: true });
        let view = createWorldView(stage, initial);

        const savedLevel = parseInt(storage.get(SPEED_KEY) ?? '', 10);
        let level = Number.isFinite(savedLevel) ? Math.max(0, Math.min(SPEEDS.length - 1, savedLevel)) : DEFAULT_SPEED_LEVEL;

        const player = createPlayer({
            world: worldData,
            onReset(world) {
                view.show(world);
                alertBox.hidden = true;
                doneBox.hidden = true;
                status.textContent = statusText(world);
            },
            onStep(info) {
                const perSecond = SPEEDS[level];
                view.animate(info, info.world, perSecond === 0 ? 0 : Math.round(1000 / perSecond));
                status.textContent = statusText(info.world);
            },
            onState: (s) => refreshControls(s),
        });

        const btnReset = h('button', { class: 'btn btn-sm', type: 'button', onclick: () => player.reset() }, '⏮ Zum Start');
        const btnPlay = h('button', { class: 'btn btn-sm btn-accent', type: 'button', onclick: () => onPlayClick() }, '⏸ Pause');
        const btnStep = h('button', { class: 'btn btn-sm', type: 'button', onclick: () => player.step() }, '⏭ Ein Schritt');
        const speedInput = h('input', {
            type: 'range', min: '0', max: String(SPEEDS.length - 1), step: '1', value: String(level),
            id: `robo-speed-${task.id ?? 'x'}`, class: 'robo-speed',
        });
        const speedLabel = h('label', { for: speedInput.id, class: 'robo-speed-label' }, 'Tempo: ', h('span', { class: 'robo-speed-name' }, SPEED_NAMES[level]));
        speedInput.addEventListener('input', () => {
            level = Number(speedInput.value);
            storage.set(SPEED_KEY, String(level));
            player.setSpeed(level);
            speedLabel.querySelector('.robo-speed-name').textContent = SPEED_NAMES[level];
            speedInput.setAttribute('aria-valuetext', SPEED_NAMES[level]);
        });
        speedInput.setAttribute('aria-valuetext', SPEED_NAMES[level]);
        const controls = h('div', { class: 'robo-controls', role: 'group', 'aria-label': 'Steuerung der Animation', hidden: true },
            btnReset, btnPlay, btnStep, h('span', { class: 'robo-speed-wrap' }, speedLabel, speedInput));

        function onPlayClick() {
            const s = player.state;
            if (s.status === 'playing') {
                player.pause();
            } else if (s.status === 'done' || s.status === 'crashed') {
                player.reset();
                player.play();
            } else {
                player.play();
            }
        }

        function refreshControls(s) {
            const hasTrace = s.total > 0 || s.status === 'playing';
            controls.hidden = !hasTrace;
            const finished = s.status === 'done' || s.status === 'crashed';
            btnPlay.textContent = s.status === 'playing' ? '⏸ Pause' : finished ? '↻ Nochmal' : '▶ Weiter';
            btnPlay.disabled = s.total === 0 && s.status !== 'playing';
            btnStep.disabled = finished || (s.total === 0 && s.status !== 'playing') || s.position >= s.total && s.ended;
            btnReset.disabled = s.total === 0;
            status.setAttribute('aria-live', s.status === 'playing' ? 'off' : 'polite');
            if (s.status === 'crashed') {
                alertBox.textContent = `Robo ist kaputt: ${s.message}`;
                alertBox.hidden = false;
                doneBox.hidden = true;
            } else if (s.status === 'done') {
                doneBox.textContent = `Das Programm ist zu Ende (${s.steps} ${s.steps === 1 ? 'Befehl' : 'Befehle'} ausgeführt).`;
                doneBox.hidden = false;
                alertBox.hidden = true;
            } else {
                alertBox.hidden = true;
                doneBox.hidden = true;
            }
        }

        player.setSpeed(level);
        const goalText = typeof config.goal_md === 'string' ? config.goal_md.trim() : '';
        const goalEl = goalText ? h('div', { class: 'robo-goal' }, h('strong', {}, 'Ziel: '), h('span', { class: 'robo-goal-text' }, goalText)) : null;
        const legend = h('ul', { class: 'robo-legend', 'aria-label': 'Legende' },
            h('li', {}, h('span', { class: 'robo-key robo-key-robo' }), ' Robo (der Pfeil zeigt die Blickrichtung)'),
            h('li', {}, h('span', { class: 'robo-key robo-key-wall' }), ' Wand'),
            h('li', {}, h('span', { class: 'robo-key robo-key-box' }), ' Kiste (schiebbar)'),
            h('li', {}, h('span', { class: 'robo-key robo-key-screw' }), ' Schraube (Zahl = Anzahl)'),
            h('li', {}, h('span', { class: 'robo-key robo-key-target' }), ' Ziel'));
        const worldPanel = h('div', { class: 'robo-world' },
            goalEl, stage, status, controls, alertBox, doneBox,
            h('details', { class: 'robo-legend-box' }, h('summary', {}, 'Legende und Befehle'), legend,
                h('p', { class: 'robo-hint' }, language === 'python'
                    ? 'Mit „from robo import *“ stehen vor(), links(), rechts(), aufheben(), ablegen(), schieben() und die Fragen vorneFrei(), vorneWand(), vorneKiste(), aufSchraube(), aufZiel(), schraubenImKorb(), richtung() bereit.'
                    : 'Die Klasse Robo bietet Robo.vor(), Robo.links(), Robo.rechts(), Robo.aufheben(), Robo.ablegen(), Robo.schieben() und die Fragen Robo.vorneFrei(), Robo.vorneWand(), Robo.vorneKiste(), Robo.aufSchraube(), Robo.aufZiel(), Robo.schraubenImKorb(), Robo.richtung().')));

        // ---------- Code-Editor (Widget "code") ----------
        const runtime = env.runtime ?? null;
        const wrapped = runtime
            ? wrapRuntime(runtime, { language, getWorld: () => worldData, player })
            : null;
        const codeHost = h('div', { class: 'robo-code' });
        const root = h('div', { class: 'robo', dataset: { language } }, worldPanel, codeHost);
        container.append(root);
        status.textContent = statusText(initial);
        refreshControls(player.state);

        const codeTask = codeTaskFor(task);
        const codeInstance = CodeWidget.mount(codeHost, { ...ctx, task: codeTask, env: { ...env, runtime: wrapped } });

        return {
            layout: 'split',
            getContent: () => codeInstance.getContent(),
            setReadOnly: (flag) => codeInstance.setReadOnly?.(flag),
            focus: () => codeInstance.focus?.(),
            destroy() {
                if (destroyed) {
                    return;
                }
                destroyed = true;
                player.destroy();
                codeInstance.destroy?.();
                view.destroy();
                root.remove();
            },
        };
    },
};

registerAnswerWidget('robo', RoboWidget);

export default RoboWidget;
