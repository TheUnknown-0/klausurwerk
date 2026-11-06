/**
 * Versionsdialog (nur Admins, templates/partials/version-dialog.php).
 *
 *  - Steht eine Prüfung an (nach der Anmeldung oder einmal täglich, data-check-due), fragt
 *    die Seite im Hintergrund POST /api/version/pruefen und meldet eine neue Version.
 *  - "Nach neuen Versionen suchen" oben im Dialog erzwingt dieselbe Prüfung.
 *  - Das Ergebnis aktualisiert Dialog, Punkt an der Versionsnummer und Update-Knopf oben.
 */
(function () {
    'use strict';

    var dialog = document.getElementById('version-dialog');
    if (!dialog || !window.BM) { return; }
    var url = dialog.getAttribute('data-check-url');
    var running = false;

    function apply(data, announce) {
        if (!data || !data.success) {
            if (!announce) {
                window.BM.flash('error', (data && data.error) || 'Die Prüfung ist fehlgeschlagen.');
            }
            return;
        }
        var status = dialog.querySelector('[data-version-status]');
        if (status && typeof data.html === 'string') {
            // HTML aus dem eigenen Server-Template (Inhalte dort escaped).
            status.innerHTML = data.html;
        }
        document.querySelectorAll('[data-update-dot]').forEach(function (el) { el.hidden = !data.available; });
        document.querySelectorAll('[data-update-pill]').forEach(function (el) { el.hidden = !data.available; });
        document.querySelectorAll('[data-update-pill-label]').forEach(function (el) {
            el.textContent = 'Update auf ' + (data.latest || '');
        });
        if (!announce && data.throttled) {
            window.BM.flash('info', data.throttled);
        }
        if (announce && data.available) {
            window.BM.flash('info', 'Klausurwerk ' + data.latest + ' ist erschienen (installiert: '
                + data.installed + '). Der Knopf „Update“ oben zeigt, was neu ist.');
        }
    }

    function check(force, announce) {
        if (running) { return; }
        running = true;
        var button = dialog.querySelector('[data-version-check]');
        var label = dialog.querySelector('[data-version-check-label]');
        if (button && force) {
            button.disabled = true;
            if (label) { label.textContent = 'Suche …'; }
        }
        window.BM.fetchJson(url, { json: { force: !!force } })
            .then(function (data) { apply(data, announce); })
            .catch(function () {
                if (!announce) { window.BM.flash('error', 'Keine Verbindung zum Server.'); }
            })
            .then(function () {
                running = false;
                if (button) { button.disabled = false; }
                if (label) { label.textContent = 'Nach neuen Versionen suchen'; }
            });
    }

    dialog.addEventListener('click', function (ev) {
        if (ev.target.closest('[data-version-check]')) {
            check(true, false);
        }
    });

    if (dialog.getAttribute('data-check-due') === '1') {
        check(false, true);
    }
})();
