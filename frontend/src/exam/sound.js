/**
 * Ton über WebAudio. Browser erlauben Ton erst nach einer Nutzergeste;
 * unlock() muss deshalb aus einem Klick heraus aufgerufen werden ("Bereit").
 * Alarm, Durchsage und Zeitende nutzen denselben Zweiklang (880 Hz).
 */
export class Sound {
    constructor() {
        this.ctx = null;
        this.unlocked = false;
        /** true, sobald unlock() aus einer Geste heraus versucht wurde (auch wenn der Browser ablehnt). */
        this.attempted = false;
    }

    /** Legt den AudioContext an bzw. setzt ihn fort; spielt optional einen leisen Testton. */
    async unlock(playTest = true) {
        this.attempted = true;
        try {
            const Ctor = window.AudioContext || window.webkitAudioContext;
            if (!Ctor) {
                return false;
            }
            if (!this.ctx) {
                this.ctx = new Ctor();
            }
            if (this.ctx.state === 'suspended') {
                // resume() kann ohne Geste unbegrenzt offen bleiben: nie darauf warten.
                await Promise.race([this.ctx.resume(), new Promise((r) => setTimeout(r, 1200))]);
            }
            this.unlocked = this.ctx.state === 'running';
            if (playTest && this.unlocked) {
                this.beeps(1, 0.06, 660, 0.12);
            }
            return this.unlocked;
        } catch {
            return false;
        }
    }

    /** Versucht bei jeder späteren Geste, einen unterbrochenen Context fortzusetzen. */
    keepAlive() {
        const resume = () => {
            if (this.ctx && this.ctx.state === 'suspended') {
                this.ctx.resume().catch(() => {});
            }
        };
        document.addEventListener('pointerdown', resume, true);
        document.addEventListener('keydown', resume, true);
    }

    /** Spielt n Pieptöne. */
    beeps(count, gain, freq = 880, dur = 0.18) {
        const ctx = this.ctx;
        if (!ctx || ctx.state !== 'running') {
            return false;
        }
        try {
            const t0 = ctx.currentTime + 0.02;
            for (let i = 0; i < count; i++) {
                const start = t0 + i * (dur + 0.1);
                const osc = ctx.createOscillator();
                const g = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                g.gain.setValueAtTime(0.0001, start);
                g.gain.exponentialRampToValueAtTime(gain, start + 0.015);
                g.gain.setValueAtTime(gain, start + dur - 0.03);
                g.gain.exponentialRampToValueAtTime(0.0001, start + dur);
                osc.connect(g).connect(ctx.destination);
                osc.start(start);
                osc.stop(start + dur + 0.02);
            }
            return true;
        } catch {
            return false;
        }
    }

    /** Alarmton bei Verstoß: zwei deutliche Pieptöne. */
    alarm() {
        return this.beeps(2, 0.5);
    }

    /** Durchsage: gleicher Ton, leiser. */
    announce() {
        return this.beeps(2, 0.18);
    }

    /** Zeitende: ein Zweiklang in mittlerer Lautstärke. */
    timeUp() {
        return this.beeps(2, 0.3);
    }
}
