/**
 * Reine Logik rund um die Dateien einer Code-Aufgabe (ohne DOM).
 */

export const DEFAULT_MAIN = { java: 'Main.java', python: 'main.py', sql: 'abfrage.sql' };

/** Erlaubte Endungen für neue Dateien je Sprache. */
const EXTENSIONS = {
    java: ['.java'],
    python: ['.py', '.txt', '.csv', '.json'],
    sql: ['.sql'],
};

export function defaultMainFile(language) {
    return DEFAULT_MAIN[language] ?? 'Main.txt';
}

/**
 * Prüft einen Dateinamen. Gibt eine Fehlermeldung zurück oder null.
 * @param {string} name
 * @param {string[]} existing  bereits vergebene Namen (ohne den umzubenennenden)
 * @param {string} language
 */
export function validateFileName(name, existing, language) {
    const n = String(name ?? '').trim();
    if (n === '') {
        return 'Bitte gib einen Dateinamen ein.';
    }
    if (n.length > 60) {
        return 'Der Dateiname ist zu lang (höchstens 60 Zeichen).';
    }
    if (!/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/.test(n)) {
        return 'Erlaubt sind Buchstaben, Ziffern, Unterstrich, Punkt und Bindestrich.';
    }
    const exts = EXTENSIONS[language] ?? [];
    if (exts.length > 0 && !exts.some((ext) => n.toLowerCase().endsWith(ext))) {
        return `Der Name muss auf ${exts.join(', ')} enden.`;
    }
    if (language === 'java') {
        const cls = n.slice(0, -5);
        if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(cls)) {
            return 'Java-Dateien heißen wie ihre Klasse (nur Buchstaben, Ziffern, Unterstrich).';
        }
    }
    if (existing.some((e) => e.toLowerCase() === n.toLowerCase())) {
        return 'Eine Datei mit diesem Namen gibt es schon.';
    }
    return null;
}

/** Schlägt einen freien Namen vor: Klasse1.java, datei1.py … */
export function suggestFileName(language, existing) {
    const ext = (EXTENSIONS[language] ?? ['.txt'])[0];
    const base = language === 'java' ? 'Klasse' : 'datei';
    for (let i = 1; i < 100; i++) {
        const candidate = `${base}${i}${ext}`;
        if (!existing.some((e) => e.toLowerCase() === candidate.toLowerCase())) {
            return candidate;
        }
    }
    return `${base}${Date.now()}${ext}`;
}

/**
 * Baut die Dateiliste des Widgets.
 * Schreibgeschützte Dateien kommen immer aus der Aufgabe (aktueller Stand),
 * bearbeitbare aus dem gespeicherten Inhalt, fehlende aus der Aufgabe.
 *
 * @param {{name:string, content?:string, readonly?:boolean}[]} taskFiles
 * @param {{files?: {name:string, content:string}[]}|null} content
 * @param {{language?: string, main_file?: string}} config
 * @returns {{name:string, content:string, readonly:boolean}[]}
 */
export function buildFileList(taskFiles, content, config = {}) {
    const result = [];
    const taken = new Set();
    const tf = Array.isArray(taskFiles) ? taskFiles : [];
    const saved = new Map();
    for (const f of Array.isArray(content?.files) ? content.files : []) {
        if (f && typeof f.name === 'string') {
            saved.set(f.name, String(f.content ?? ''));
        }
    }
    const readonlyNames = new Set(tf.filter((f) => f.readonly).map((f) => f.name));

    for (const f of tf) {
        if (taken.has(f.name)) {
            continue;
        }
        if (f.readonly) {
            result.push({ name: f.name, content: String(f.content ?? ''), readonly: true });
        } else {
            result.push({ name: f.name, content: saved.has(f.name) ? saved.get(f.name) : String(f.content ?? ''), readonly: false });
        }
        taken.add(f.name);
    }
    // zusätzlich angelegte Dateien (nur im gespeicherten Inhalt)
    for (const [name, text] of saved) {
        if (!taken.has(name) && !readonlyNames.has(name)) {
            result.push({ name, content: text, readonly: false });
            taken.add(name);
        }
    }
    if (result.length === 0) {
        const main = config.main_file || defaultMainFile(config.language);
        result.push({ name: main, content: '', readonly: false });
    }
    return result;
}

/** Nur die bearbeitbaren Dateien im Speicherformat { files: [{name, content}] }. */
export function toContent(files) {
    return { files: files.filter((f) => !f.readonly).map((f) => ({ name: f.name, content: f.content })) };
}

/** Wählt die Startdatei: config.main_file, sonst erste Datei mit passender Endung. */
export function pickMainFile(files, config = {}) {
    const names = files.map((f) => f.name);
    if (config.main_file && names.includes(config.main_file)) {
        return config.main_file;
    }
    const ext = (EXTENSIONS[config.language] ?? [''])[0];
    return names.find((n) => n.toLowerCase().endsWith(ext)) ?? names[0] ?? null;
}
