/**
 * Alarmton des Pults: EIN einheitlicher kurzer Ton über WebAudio.
 * Browser geben Ton erst nach einer Nutzeraktion frei; bis dahin zeigt das Pult den Hinweis "Ton aktivieren".
 */
import { storage } from './util.js';

const MUTE_KEY = 'kw-pult-muted';

export function createAlarmSound() {
    let context = null;
    let unlocked = false;
    let muted = storage.get(MUTE_KEY) === '1';
    const listeners = new Set();
    const notify = () => listeners.forEach((fn) => fn());

    function ensureContext() {
        if (context) {
            return context;
        }
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) {
            return null;
        }
        context = new Ctx();
        return context;
    }

    /** Muss aus einer Nutzeraktion heraus aufgerufen werden. */
    async function unlock() {
        const ctx = ensureContext();
        if (!ctx) {
            return false;
        }
        try {
            await ctx.resume();
        } catch {
            return false;
        }
        unlocked = ctx.state === 'running';
        notify();
        return unlocked;
    }

    /** Spielt den Ton (nichts, wenn stumm oder noch nicht freigeschaltet). */
    function play() {
        if (muted || !unlocked || !context || context.state !== 'running') {
            return false;
        }
        const now = context.currentTime;
        const osc = context.createOscillator();
        const gain = context.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(880, now);
        gain.gain.setValueAtTime(0.0001, now);
        gain.gain.exponentialRampToValueAtTime(0.35, now + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.5);
        osc.connect(gain).connect(context.destination);
        osc.start(now);
        osc.stop(now + 0.55);
        return true;
    }

    // Jede erste Nutzeraktion schaltet den Ton frei.
    const onGesture = () => {
        unlock().then((ok) => {
            if (ok) {
                document.removeEventListener('click', onGesture, true);
                document.removeEventListener('keydown', onGesture, true);
            }
        });
    };
    // 'click' statt 'pointerdown': Der Hinweis verschwindet erst, wenn der Klick
    // sein Ziel schon erreicht hat. Sonst rutscht die Seite unter dem Mauszeiger
    // weg und der erste Klick im Pult geht ins Leere.
    document.addEventListener('click', onGesture, true);
    document.addEventListener('keydown', onGesture, true);

    return {
        unlock,
        play,
        get unlocked() {
            return unlocked;
        },
        get muted() {
            return muted;
        },
        setMuted(value) {
            muted = Boolean(value);
            storage.set(MUTE_KEY, muted ? '1' : '0');
            notify();
        },
        onChange(fn) {
            listeners.add(fn);
            return () => listeners.delete(fn);
        },
    };
}
