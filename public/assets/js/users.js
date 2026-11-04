/**
 * Benutzerverwaltung: Benutzerformular (Rolle → Ausschlusskriterien-Bereich,
 * Kriterium → Notizfeld, Passwort generieren → Passwortfeld) sowie die
 * Passwort-zurücksetzen-Modals der Benutzerliste.
 * Konventionen (siehe templates/pages/users/{index,form}.php):
 *   [data-user-form]                    → Benutzerformular
 *   [data-role-select]                  → Rollen-Auswahl
 *   [data-criteria-section]             → Kriterien-Block (nur Rolle student)
 *   [data-criteria-placeholder]         → Hinweistext (nur Rolle ≠ student)
 *   [data-criterion-toggle="id"]        → Kriterien-Checkbox
 *   [data-criterion-note="id"]          → zugehöriges Notizfeld
 *   [data-generate-toggle]              → „Passwort generieren“-Checkbox
 *   [data-password-field]               → Passwortfeld-Wrapper (Anlegen)
 *   [data-reset-action] / [data-reset-name] auf Buttons der Benutzerliste
 *   [data-reset-form]                   → Formular im Passwort-Reset-Modal
 *   [data-reset-target]                 → Namensanzeige im Modal
 *   [data-copy-target="id"]             → Button kopiert den Wert des Feldes #id
 */
(function () {
    'use strict';

    // ---------- Benutzerformular ----------
    var form = document.querySelector('[data-user-form]');
    if (form) {
        var roleSelect = form.querySelector('[data-role-select]');
        var criteriaSection = form.querySelector('[data-criteria-section]');
        var criteriaPlaceholder = form.querySelector('[data-criteria-placeholder]');
        var nameFields = form.querySelectorAll('[data-name-field]');
        var nameLabels = form.querySelectorAll('[data-name-label]');
        var nameHint = form.querySelector('[data-name-hint]');

        function applyRole() {
            if (!roleSelect) { return; }
            var isStudent = roleSelect.value === 'student';
            if (criteriaSection) { criteriaSection.hidden = !isStudent; }
            if (criteriaPlaceholder) { criteriaPlaceholder.hidden = isStudent; }

            // Lehrkräfte und Admins: Vorname ODER Nachname genügt — das andere Feld bleibt optional.
            var namesOptional = roleSelect.value === 'teacher' || roleSelect.value === 'admin';
            nameFields.forEach(function (field) { field.required = !namesOptional; });
            nameLabels.forEach(function (label) {
                label.textContent = label.textContent.replace(/\s*\*$/, '') + (namesOptional ? '' : ' *');
            });
            if (nameHint) { nameHint.hidden = !namesOptional; }
        }

        if (roleSelect) {
            roleSelect.addEventListener('change', applyRole);
            applyRole();
        }

        form.addEventListener('change', function (ev) {
            var box = ev.target.closest('[data-criterion-toggle]');
            if (box) {
                var id = box.getAttribute('data-criterion-toggle');
                var note = form.querySelector('[data-criterion-note="' + id + '"]');
                if (note) { note.hidden = !box.checked; }
            }
        });

        var generateToggle = form.querySelector('[data-generate-toggle]');
        var passwordField = form.querySelector('[data-password-field]');
        function applyGenerate() {
            if (!generateToggle || !passwordField) { return; }
            passwordField.hidden = generateToggle.checked;
        }
        if (generateToggle) {
            generateToggle.addEventListener('change', applyGenerate);
            applyGenerate();
        }
    }

    // ---------- Passwort-Reset-Modal (Benutzerliste) ----------
    document.addEventListener('click', function (ev) {
        var button = ev.target.closest('[data-reset-action]');
        if (!button) { return; }

        var modal = document.getElementById('modal-passwort');
        if (!modal) { return; }
        var resetForm = modal.querySelector('[data-reset-form]');
        var target = modal.querySelector('[data-reset-target]');
        if (resetForm) { resetForm.setAttribute('action', button.getAttribute('data-reset-action') || ''); }
        if (target) { target.textContent = button.getAttribute('data-reset-name') || '—'; }
    });

    // ---------- Passwort kopieren / markieren ----------
    document.addEventListener('click', function (ev) {
        var button = ev.target.closest('[data-copy-target]');
        if (!button) { return; }

        var field = document.getElementById(button.getAttribute('data-copy-target'));
        if (!field) { return; }
        field.select();
        field.setSelectionRange(0, field.value.length);

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(field.value).then(function () {
                BM.flash('success', 'Passwort kopiert.');
            }, function () {
                /* Zwischenablage evtl. blockiert — Text bleibt markiert */
            });
        }
    });

    document.addEventListener('focus', function (ev) {
        var field = ev.target;
        if (field.matches && field.matches('input[readonly].mono')) {
            field.select();
        }
    }, true);
})();
