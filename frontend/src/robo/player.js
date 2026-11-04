/**
 * Abspielsteuerung für Robo: spielt das Zugprotokoll eines Laufs Schritt für Schritt auf einer
 * Welt ab (Geschwindigkeit, Pause, Einzelschritt, Zurücksetzen). Reine Logik, die Darstellung
 * übernimmt render.js über die Rückrufe.
 *
 *   const player = createPlayer({ world, onStep, onReset, onState });
 *   player.begin();             // neuer Lauf: Protokoll leeren, Welt auf Anfang, abspielen
 *   player.push(['v', 'r']);    // Züge kommen (auch während der Wiedergabe) an
 *   player.programEnded();      // das Programm ist zu Ende (mit oder ohne Fehler)
 *   player.pause(); player.play(); player.step(); player.reset(); player.setSpeed(6);
 *
 * Zustände (state.status):
 *   idle     noch nichts gelaufen
 *   playing  spielt ab (wartet ggf. auf weitere Züge)
 *   paused   angehalten
 *   done     Protokoll vollständig abgespielt, Programm ohne Fehler beendet
 *   crashed  Robo ist gegen etwas gelaufen (Fehlermeldung in state.message)
 */
import { RoboWorld } from './world.js';

/** Schritte pro Sekunde je Stufe; 0 = sofort. */
export const SPEEDS = [1.5, 3, 6, 15, 60, 0];
export const DEFAULT_SPEED_LEVEL = 3;
/** Im Sofort-Modus werden höchstens so viele Züge am Stück ausgeführt, dann gibt der Player ab. */
const INSTANT_BATCH = 2000;

export function createPlayer({
    world, onStep = () => {}, onReset = () => {}, onState = () => {},
    scheduler = { setTimeout: (fn, ms) => setTimeout(fn, ms), clearTimeout: (id) => clearTimeout(id) },
} = {}) {
    const initial = new RoboWorld(world);
    let current = initial.clone();
    let trace = [];
    let pos = 0;
    let ended = false;
    let status = 'idle';
    let message = null;
    let level = DEFAULT_SPEED_LEVEL;
    let timer = null;
    let stepsDone = 0;

    const snapshot = () => ({
        status, message, speedLevel: level, position: pos, total: trace.length, ended, steps: stepsDone,
    });
    const emitState = () => onState(snapshot());

    function stopTimer() {
        if (timer !== null) {
            scheduler.clearTimeout(timer);
            timer = null;
        }
    }

    function delay() {
        const perSecond = SPEEDS[level] ?? SPEEDS[DEFAULT_SPEED_LEVEL];
        return perSecond === 0 ? 0 : Math.round(1000 / perSecond);
    }

    /** Wendet den nächsten Zug an. Rückgabe: false, wenn nichts mehr zu tun ist. */
    function applyNext() {
        if (pos >= trace.length || status === 'crashed') {
            return false;
        }
        const code = trace[pos];
        const index = pos;
        pos++;
        if (code.startsWith('!')) {
            status = 'crashed';
            message = code.slice(1);
            current.crashed = true;
            onStep({ code, index, result: { ok: false, code, message }, world: current, crashed: true });
            return true;
        }
        const result = current.step(code);
        stepsDone++;
        if (!result.ok) {
            status = 'crashed';
            message = result.message;
            onStep({ code, index, result, world: current, crashed: true });
            return true;
        }
        onStep({ code, index, result, world: current, crashed: false });
        return true;
    }

    function settle() {
        if (status === 'playing' && pos >= trace.length && ended) {
            status = 'done';
        }
    }

    function tick() {
        timer = null;
        if (status !== 'playing') {
            return;
        }
        const instant = delay() === 0;
        let count = 0;
        do {
            if (!applyNext()) {
                break;
            }
            count++;
        } while (instant && status === 'playing' && count < INSTANT_BATCH);
        settle();
        emitState();
        if (status === 'playing' && pos < trace.length) {
            timer = scheduler.setTimeout(tick, delay());
        }
    }

    function schedule() {
        if (status === 'playing' && timer === null && pos < trace.length) {
            timer = scheduler.setTimeout(tick, 0);
        }
    }

    const api = {
        get world() {
            return current;
        },
        get initialWorld() {
            return initial;
        },
        get state() {
            return snapshot();
        },

        /** Neuer Lauf: leert das Protokoll, setzt die Welt zurück und spielt ab. */
        begin() {
            stopTimer();
            trace = [];
            pos = 0;
            ended = false;
            stepsDone = 0;
            message = null;
            current = initial.clone();
            status = 'playing';
            onReset(current);
            emitState();
        },

        push(codes) {
            for (const code of codes) {
                if (trace.length < 20050) {
                    trace.push(code);
                }
            }
            if (status === 'idle') {
                status = 'playing';
            }
            schedule();
            emitState();
        },

        programEnded() {
            ended = true;
            settle();
            emitState();
            schedule();
        },

        play() {
            if (status === 'crashed' || status === 'idle' && trace.length === 0) {
                return;
            }
            if (status === 'done') {
                return;
            }
            status = 'playing';
            settle();
            emitState();
            schedule();
        },

        pause() {
            if (status === 'playing') {
                stopTimer();
                status = 'paused';
                emitState();
            }
        },

        /** Genau einen Zug anwenden (hält vorher an). */
        step() {
            stopTimer();
            if (status === 'playing' || status === 'idle') {
                status = 'paused';
            }
            if (status === 'paused') {
                applyNext();
                if (status === 'paused' && pos >= trace.length && ended) {
                    status = 'done';
                }
            }
            emitState();
        },

        /** Welt auf den Anfang, Protokoll bleibt erhalten (erneutes Abspielen möglich). */
        reset() {
            stopTimer();
            pos = 0;
            stepsDone = 0;
            message = null;
            current = initial.clone();
            status = trace.length > 0 ? 'paused' : 'idle';
            onReset(current);
            emitState();
        },

        setSpeed(newLevel) {
            level = Math.max(0, Math.min(SPEEDS.length - 1, Math.round(newLevel)));
            emitState();
        },

        destroy() {
            stopTimer();
            status = 'paused';
        },
    };
    return api;
}
