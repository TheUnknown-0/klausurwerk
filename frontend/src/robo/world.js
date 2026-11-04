/**
 * Weltmodell von Robo (Gitterwelt). Reine Logik ohne DOM, dient
 *  - der Animation im Browser (Züge aus dem Protokoll nachspielen),
 *  - dem Aufgaben-Editor (Prüfung der Welt),
 *  - den Tests, die dieselben Fälle wie runner/libs/python/robo.py und
 *    runner/libs/java/Robo.java prüfen (runner/tests/robo_vectors.json).
 *
 * Koordinaten: x nach rechts, y nach unten, (0,0) oben links.
 * Richtungen in den Daten: 'N','E','S','W'. Für Schüler:innen heißt Osten 'O'.
 *
 * Züge (Protokollzeichen):
 *   v vor, l links drehen, r rechts drehen, a aufheben, b ablegen, s schieben
 *   !Text  Fehler (beendet das Programm)
 */

export const DIRS = ['N', 'E', 'S', 'W'];
export const DELTA = { N: [0, -1], E: [1, 0], S: [0, 1], W: [-1, 0] };
export const DIR_NAMES = { N: 'Norden', E: 'Osten', S: 'Süden', W: 'Westen' };
export const MIN_SIZE = 2;
export const MAX_SIZE = 30;
export const MAX_MOVES = 10000;
export const MAX_SCREWS_PER_CELL = 99;

export const MESSAGES = {
    wall: 'Robo ist gegen eine Wand gelaufen.',
    edge: 'Robo ist gegen den Rand der Welt gelaufen.',
    box: 'Robo ist gegen eine Kiste gelaufen. Mit schieben() kann er sie wegschieben.',
    noScrew: 'Hier liegt keine Schraube, die Robo aufheben kann.',
    emptyBasket: 'Der Korb ist leer, Robo hat nichts zum Ablegen.',
    noBox: 'Vor Robo steht keine Kiste, die er schieben könnte.',
    blocked: 'Die Kiste lässt sich nicht schieben, dahinter ist kein freies Feld.',
    limit: `Robo hat mehr als ${MAX_MOVES} Befehle ausgeführt. Läuft eine Endlosschleife?`,
    crashed: 'Robo ist kaputt und kann keine Befehle mehr ausführen.',
};

/** Normalisiert eine Richtung ('O' = Osten) oder wirft. */
export function normDir(value) {
    const d = String(value ?? '').toUpperCase();
    const mapped = d === 'O' ? 'E' : d;
    if (!DIRS.includes(mapped)) {
        throw new Error(`Ungültige Blickrichtung: ${String(value).slice(0, 10)}`);
    }
    return mapped;
}

/** Richtung für Schüler:innen: N, O, S, W. */
export const studentDir = (dir) => (dir === 'E' ? 'O' : dir);

const isInt = (v) => Number.isInteger(v);

function cellList(raw, label, cols, rows) {
    const out = [];
    const seen = new Set();
    for (const item of Array.isArray(raw) ? raw : []) {
        if (!Array.isArray(item) || item.length !== 2 || !isInt(item[0]) || !isInt(item[1])) {
            throw new Error(`${label}: Jede Position muss die Form [x, y] haben.`);
        }
        const [x, y] = item;
        if (x < 0 || y < 0 || x >= cols || y >= rows) {
            throw new Error(`${label}: Das Feld (${x}|${y}) liegt außerhalb der Welt.`);
        }
        const k = y * cols + x;
        if (!seen.has(k)) {
            seen.add(k);
            out.push([x, y]);
        }
    }
    return out;
}

/**
 * Prüft und normalisiert die Daten einer Welt (docs/DATENFORMATE.md, 2.16).
 * Wirft Error mit deutscher Meldung. Unbekannte Felder fallen weg.
 */
export function normalizeWorldData(raw) {
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
        throw new Error('Die Welt fehlt.');
    }
    const cols = raw.cols;
    const rows = raw.rows;
    if (!isInt(cols) || !isInt(rows) || cols < MIN_SIZE || rows < MIN_SIZE || cols > MAX_SIZE || rows > MAX_SIZE) {
        throw new Error(`Die Welt muss zwischen ${MIN_SIZE} und ${MAX_SIZE} Spalten und Zeilen haben.`);
    }
    const robo = raw.robo ?? {};
    if (!isInt(robo.x) || !isInt(robo.y) || robo.x < 0 || robo.y < 0 || robo.x >= cols || robo.y >= rows) {
        throw new Error('Robo steht außerhalb der Welt.');
    }
    const dir = normDir(robo.dir ?? 'E');
    const walls = cellList(raw.walls, 'Wände', cols, rows);
    const boxes = cellList(raw.boxes, 'Kisten', cols, rows);
    const targets = cellList(raw.targets, 'Ziele', cols, rows);
    const wallSet = new Set(walls.map(([x, y]) => y * cols + x));
    const boxSet = new Set(boxes.map(([x, y]) => y * cols + x));
    const roboKey = robo.y * cols + robo.x;
    if (wallSet.has(roboKey)) {
        throw new Error(`Robo steht auf einer Wand (${robo.x}|${robo.y}).`);
    }
    if (boxSet.has(roboKey)) {
        throw new Error(`Robo steht auf einer Kiste (${robo.x}|${robo.y}).`);
    }
    for (const [x, y] of boxes) {
        if (wallSet.has(y * cols + x)) {
            throw new Error(`Eine Kiste steht auf einer Wand (${x}|${y}).`);
        }
    }
    const screws = [];
    const screwSeen = new Set();
    for (const item of Array.isArray(raw.screws) ? raw.screws : []) {
        if (!item || !isInt(item.x) || !isInt(item.y) || !isInt(item.count)) {
            throw new Error('Schrauben: Jeder Eintrag braucht x, y und count (ganze Zahlen).');
        }
        if (item.x < 0 || item.y < 0 || item.x >= cols || item.y >= rows) {
            throw new Error(`Schrauben: Das Feld (${item.x}|${item.y}) liegt außerhalb der Welt.`);
        }
        if (item.count < 1 || item.count > MAX_SCREWS_PER_CELL) {
            throw new Error(`Schrauben: Die Anzahl muss zwischen 1 und ${MAX_SCREWS_PER_CELL} liegen.`);
        }
        const k = item.y * cols + item.x;
        if (wallSet.has(k)) {
            throw new Error(`Schrauben: Auf einer Wand (${item.x}|${item.y}) kann keine Schraube liegen.`);
        }
        if (boxSet.has(k)) {
            throw new Error(`Schrauben: Unter einer Kiste (${item.x}|${item.y}) kann keine Schraube liegen.`);
        }
        if (screwSeen.has(k)) {
            throw new Error(`Schrauben: Das Feld (${item.x}|${item.y}) kommt doppelt vor.`);
        }
        screwSeen.add(k);
        screws.push({ x: item.x, y: item.y, count: item.count });
    }
    for (const [x, y] of targets) {
        if (wallSet.has(y * cols + x)) {
            throw new Error(`Ein Ziel liegt auf einer Wand (${x}|${y}).`);
        }
    }
    let basket = 0;
    if (raw.basket !== undefined && raw.basket !== null) {
        if (!isInt(raw.basket) || raw.basket < 0 || raw.basket > 9999) {
            throw new Error('Der Korb enthält eine ungültige Anzahl Schrauben.');
        }
        basket = raw.basket;
    }
    return { cols, rows, robo: { x: robo.x, y: robo.y, dir }, walls, boxes, screws, targets, basket };
}

/** Veränderliche Welt mit Zügen und Sensoren. */
export class RoboWorld {
    constructor(data) {
        const d = normalizeWorldData(data);
        this.cols = d.cols;
        this.rows = d.rows;
        this.x = d.robo.x;
        this.y = d.robo.y;
        this.dir = d.robo.dir;
        this.walls = new Set(d.walls.map(([x, y]) => y * d.cols + x));
        this.boxes = new Set(d.boxes.map(([x, y]) => y * d.cols + x));
        this.targets = new Set(d.targets.map(([x, y]) => y * d.cols + x));
        this.screws = new Map(d.screws.map((s) => [s.y * d.cols + s.x, s.count]));
        this.basket = d.basket;
        this.moves = 0;
        this.crashed = false;
    }

    clone() {
        const copy = new RoboWorld(this.toJSON());
        copy.moves = this.moves;
        copy.crashed = this.crashed;
        return copy;
    }

    key(x, y) {
        return y * this.cols + x;
    }

    inside(x, y) {
        return x >= 0 && y >= 0 && x < this.cols && y < this.rows;
    }

    cellOf(key) {
        return [key % this.cols, Math.floor(key / this.cols)];
    }

    front(distance = 1) {
        const [dx, dy] = DELTA[this.dir];
        return [this.x + dx * distance, this.y + dy * distance];
    }

    isWall(x, y) {
        return !this.inside(x, y) || this.walls.has(this.key(x, y));
    }

    hasBox(x, y) {
        return this.inside(x, y) && this.boxes.has(this.key(x, y));
    }

    screwsAt(x, y) {
        return this.inside(x, y) ? (this.screws.get(this.key(x, y)) ?? 0) : 0;
    }

    isTarget(x, y) {
        return this.inside(x, y) && this.targets.has(this.key(x, y));
    }

    totalScrewsOnFloor() {
        let sum = 0;
        for (const n of this.screws.values()) {
            sum += n;
        }
        return sum;
    }

    // ---------- Sensoren ----------
    vorneFrei() {
        const [x, y] = this.front();
        return !this.isWall(x, y) && !this.hasBox(x, y);
    }

    vorneWand() {
        const [x, y] = this.front();
        return this.isWall(x, y);
    }

    vorneKiste() {
        const [x, y] = this.front();
        return this.hasBox(x, y);
    }

    aufSchraube() {
        return this.screwsAt(this.x, this.y) > 0;
    }

    aufZiel() {
        return this.isTarget(this.x, this.y);
    }

    // ---------- Züge ----------
    /**
     * Führt ein Protokollzeichen aus. Rückgabe:
     *   { ok: true, code, ...Einzelheiten }  oder  { ok: false, code, message }
     * Bei einem Fehler ist Robo danach kaputt (crashed).
     */
    step(code) {
        if (this.crashed) {
            return { ok: false, code, message: MESSAGES.crashed };
        }
        const fail = (message) => {
            this.crashed = true;
            return { ok: false, code, message };
        };
        if (this.moves >= MAX_MOVES) {
            return fail(MESSAGES.limit);
        }
        switch (code) {
            case 'v': {
                const [x, y] = this.front();
                if (!this.inside(x, y)) {
                    return fail(MESSAGES.edge);
                }
                if (this.walls.has(this.key(x, y))) {
                    return fail(MESSAGES.wall);
                }
                if (this.boxes.has(this.key(x, y))) {
                    return fail(MESSAGES.box);
                }
                const from = [this.x, this.y];
                this.x = x;
                this.y = y;
                this.moves++;
                return { ok: true, code, from, to: [x, y] };
            }
            case 'l':
            case 'r': {
                const i = DIRS.indexOf(this.dir);
                const from = this.dir;
                this.dir = DIRS[(i + (code === 'r' ? 1 : 3)) % 4];
                this.moves++;
                return { ok: true, code, fromDir: from, toDir: this.dir };
            }
            case 'a': {
                const k = this.key(this.x, this.y);
                const n = this.screws.get(k) ?? 0;
                if (n < 1) {
                    return fail(MESSAGES.noScrew);
                }
                if (n === 1) {
                    this.screws.delete(k);
                } else {
                    this.screws.set(k, n - 1);
                }
                this.basket++;
                this.moves++;
                return { ok: true, code, at: [this.x, this.y] };
            }
            case 'b': {
                if (this.basket < 1) {
                    return fail(MESSAGES.emptyBasket);
                }
                const k = this.key(this.x, this.y);
                this.screws.set(k, (this.screws.get(k) ?? 0) + 1);
                this.basket--;
                this.moves++;
                return { ok: true, code, at: [this.x, this.y] };
            }
            case 's': {
                const [bx, by] = this.front();
                if (!this.hasBox(bx, by)) {
                    return fail(MESSAGES.noBox);
                }
                const [tx, ty] = this.front(2);
                if (!this.inside(tx, ty) || this.walls.has(this.key(tx, ty)) || this.boxes.has(this.key(tx, ty))) {
                    return fail(MESSAGES.blocked);
                }
                this.boxes.delete(this.key(bx, by));
                this.boxes.add(this.key(tx, ty));
                const from = [this.x, this.y];
                this.x = bx;
                this.y = by;
                this.moves++;
                return { ok: true, code, from, to: [bx, by], box: { from: [bx, by], to: [tx, ty] } };
            }
            default:
                return fail(`Unbekannter Befehl im Protokoll: ${String(code).slice(0, 10)}`);
        }
    }

    /** Führt Protokollzeichen nacheinander aus; bricht beim ersten Fehler ab. */
    run(codes) {
        for (const code of codes) {
            const r = this.step(code);
            if (!r.ok) {
                return r;
            }
        }
        return { ok: true };
    }

    toJSON() {
        const cells = (set) => [...set].sort((a, b) => a - b).map((k) => this.cellOf(k));
        return {
            cols: this.cols,
            rows: this.rows,
            robo: { x: this.x, y: this.y, dir: this.dir },
            walls: cells(this.walls),
            boxes: cells(this.boxes),
            screws: [...this.screws.entries()].sort((a, b) => a[0] - b[0]).map(([k, count]) => {
                const [x, y] = this.cellOf(k);
                return { x, y, count };
            }),
            targets: cells(this.targets),
            basket: this.basket,
        };
    }
}

/** Ist das ein gültiges Protokollzeichen (Zug oder Fehler "!Text")? */
export function isTraceCode(code) {
    return typeof code === 'string' && (/^[vlrabs]$/.test(code) || (code.startsWith('!') && code.length > 1));
}

/**
 * Kompakte Eingabezeile für den Java-Lauf ("@KWROBO {...}").
 * Gleiche Felder wie die Weltdaten, nur ohne Leerraum.
 */
export function worldToLine(worldData) {
    const d = normalizeWorldData(worldData);
    return `@KWROBO ${JSON.stringify(d)}\n`;
}

export function describeWorld(world) {
    const w = world instanceof RoboWorld ? world : new RoboWorld(world);
    const parts = [
        `Welt mit ${w.cols} Spalten und ${w.rows} Zeilen`,
        `Robo steht auf Feld ${w.x}|${w.y} und schaut nach ${DIR_NAMES[w.dir]}`,
        `${w.walls.size} Wände, ${w.boxes.size} Kisten, ${w.totalScrewsOnFloor()} Schrauben, ${w.targets.size} Ziele`,
    ];
    return `${parts.join('. ')}.`;
}
