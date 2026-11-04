/**
 * Klausur-Einstellungen im Browser: Standardwerte (Spiegel von
 * Services\ExamSettings::defaults) und Zusammenführen mit dem, was der Server liefert.
 * Rein, ohne DOM.
 */
export function defaultSettings(mode = 'exam') {
    const practice = mode === 'practice';
    return {
        lock_on_violation: !practice,
        violation_grace_ms: 300,
        paste_policy: practice ? 'allow_log' : 'internal',
        require_seb: false,
        toilet_pauses_clock: false,
        allow_self_submit: true,
        shuffle_choices: true,
        show_points: true,
        run_mode: 'run',
        java_version: '21',
        python_packages: [],
        editor: { autocomplete: practice, lint: false, close_brackets: true, font_size: 15 },
        aids: { java_api: practice, python_doc: practice, sql_cheatsheet: practice, operators: true, calculator: false },
    };
}

export function mergeSettings(incoming, mode = 'exam') {
    const d = defaultSettings(mode);
    const s = incoming && typeof incoming === 'object' ? incoming : {};
    return {
        ...d,
        ...s,
        editor: { ...d.editor, ...(s.editor && typeof s.editor === 'object' ? s.editor : {}) },
        aids: { ...d.aids, ...(s.aids && typeof s.aids === 'object' ? s.aids : {}) },
    };
}
