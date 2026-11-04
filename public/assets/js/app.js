/**
 * Basis-Interaktivität: Theme-Umschalter, Sidebar (mobil), Modals,
 * Dropdown-Menüs, Flash-Auto-Dismiss, fetch-Helfer mit CSRF.
 * Konventionen (für alle Module):
 *   data-toggle-theme          → Darkmode umschalten
 *   data-open-modal="id"       → <dialog id> öffnen
 *   data-close-modal           → umgebendes <dialog> schließen
 *   data-menu-toggle           → nächstes .menu-list ein-/ausblenden
 *   data-toggle-password="id"  → Passwortfeld #id zwischen verborgen/sichtbar umschalten
 *   data-confirm="Text"        → Bestätigung vor Submit/Klick
 *   BM.fetchJson(url, options) → fetch mit CSRF-Header + JSON-Handling
 */
(function () {
    'use strict';

    var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    // ---------- Theme ----------
    function toggleTheme() {
        var current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        var next = current === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        try { localStorage.setItem('kw-theme', next); } catch (e) { /* Storage evtl. blockiert */ }
    }

    // ---------- Fetch-Helfer ----------
    function fetchJson(url, options) {
        options = options || {};
        options.headers = Object.assign({
            'X-CSRF-Token': csrfToken,
            'Accept': 'application/json'
        }, options.headers || {});
        if (options.json !== undefined) {
            options.method = options.method || 'POST';
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(options.json);
            delete options.json;
        }
        return fetch(url, options).then(function (res) {
            return res.json().then(function (data) {
                // Statuscode mitgeben: Aufrufer müssen eine fachliche Ablehnung
                // (HTTP 200 mit success:false) von einer Störung unterscheiden
                // können — der Offline-Puffer hängt davon ab.
                if (data && typeof data === 'object') {
                    data.httpStatus = res.status;
                    data.httpOk = res.ok;
                }
                return data;
            }, function () {
                // Keine JSON-Antwort: Anmeldeseite, Proxy-Fehlerseite, Timeout.
                // Das ist KEINE fachliche Ablehnung.
                return {
                    success: false,
                    transient: true,
                    httpStatus: res.status,
                    httpOk: res.ok,
                    error: 'Unerwartete Antwort vom Server (HTTP ' + res.status + ').'
                };
            });
        });
    }

    // ---------- Flash-Meldungen ----------
    function flash(type, message) {
        var stack = document.querySelector('.flash-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'flash-stack';
            document.body.appendChild(stack);
        }
        var el = document.createElement('div');
        el.className = 'alert alert-' + (type || 'info');
        el.setAttribute('role', 'status');
        el.textContent = message;
        stack.appendChild(el);
        setTimeout(function () { el.remove(); }, 6000);
    }

    document.querySelectorAll('.flash-stack .alert').forEach(function (el) {
        setTimeout(function () { el.remove(); }, 6000);
    });

    // ---------- Delegierte Klicks ----------
    document.addEventListener('click', function (ev) {
        var t = ev.target.closest('[data-toggle-theme], [data-open-modal], [data-close-modal], [data-menu-toggle], [data-sidebar-toggle], [data-toggle-password]');

        // Offene Menüs schließen, wenn außerhalb geklickt wird
        if (!ev.target.closest('.menu')) {
            document.querySelectorAll('.menu-list:not([hidden])').forEach(function (m) { m.hidden = true; });
        }
        if (!t) { return; }

        if (t.hasAttribute('data-toggle-theme')) {
            toggleTheme();
        } else if (t.hasAttribute('data-open-modal')) {
            var dlg = document.getElementById(t.getAttribute('data-open-modal'));
            if (dlg && typeof dlg.showModal === 'function') { dlg.showModal(); }
        } else if (t.hasAttribute('data-close-modal')) {
            var parent = t.closest('dialog');
            if (parent) { parent.close(); }
        } else if (t.hasAttribute('data-menu-toggle')) {
            var list = t.closest('.menu') && t.closest('.menu').querySelector('.menu-list');
            if (list) { list.hidden = !list.hidden; }
        } else if (t.hasAttribute('data-sidebar-toggle')) {
            var sidebar = document.querySelector('.sidebar');
            var backdrop = document.querySelector('.sidebar-backdrop');
            if (sidebar) {
                var open = sidebar.classList.toggle('open');
                if (backdrop) { backdrop.hidden = !open; }
            }
        } else if (t.hasAttribute('data-toggle-password')) {
            var pwInput = document.getElementById(t.getAttribute('data-toggle-password'));
            if (pwInput) {
                var showPw = pwInput.type === 'password';
                pwInput.type = showPw ? 'text' : 'password';
                t.setAttribute('aria-pressed', String(showPw));
                t.setAttribute('aria-label', showPw ? 'Passwort verbergen' : 'Passwort anzeigen');
            }
        }
    });

    var backdrop = document.querySelector('.sidebar-backdrop');
    if (backdrop) {
        backdrop.addEventListener('click', function () {
            var sidebar = document.querySelector('.sidebar');
            if (sidebar) { sidebar.classList.remove('open'); }
            backdrop.hidden = true;
        });
    }

    // ---------- Bestätigungen ----------
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (form.matches('[data-confirm]') && !window.confirm(form.getAttribute('data-confirm'))) {
            ev.preventDefault();
        }
    });
    document.addEventListener('click', function (ev) {
        var el = ev.target.closest('a[data-confirm], button[data-confirm]:not([type="submit"])');
        if (el && !window.confirm(el.getAttribute('data-confirm'))) {
            ev.preventDefault();
            ev.stopImmediatePropagation();
        }
    }, true);

    window.BM = { fetchJson: fetchJson, flash: flash, csrfToken: csrfToken };
    window.KW = window.BM;
})();
