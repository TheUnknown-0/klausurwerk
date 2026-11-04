/**
 * Rechtevergabe-UI: Filter über alle Berechtigungen, Bereichs-Umschalter und
 * eine Vorschau der Abhängigkeitslogik (verbindlich entschieden wird immer
 * serverseitig im PermissionService).
 * Konventionen:
 *   [data-permission-form][data-dependencies]  → JSON-Map key → [Voraussetzungen]
 *   [data-permission-filter]                   → Suchfeld
 *   [data-permission-card] / [data-permission-row][data-search]
 *   [data-toggle-permissions]                  → alle Boxen der Karte umschalten
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-permission-form]');
    if (!form) { return; }

    // ---------- Abhängigkeiten ----------
    var requires = {};
    try {
        requires = JSON.parse(form.getAttribute('data-dependencies') || '{}') || {};
    } catch (e) {
        requires = {};
    }

    var dependents = {};
    Object.keys(requires).forEach(function (key) {
        (requires[key] || []).forEach(function (needed) {
            if (!dependents[needed]) { dependents[needed] = []; }
            dependents[needed].push(key);
        });
    });

    function boxFor(key) {
        return form.querySelector('input[type="checkbox"][data-permission="' + key + '"]');
    }

    /** Läuft die Kette map[key] rekursiv ab und wendet fn auf jede Checkbox an. */
    function cascade(key, map, fn) {
        var stack = (map[key] || []).slice();
        var seen = {};
        while (stack.length) {
            var current = stack.pop();
            if (seen[current]) { continue; }
            seen[current] = true;

            var box = boxFor(current);
            if (box && !box.disabled) { fn(box); }
            (map[current] || []).forEach(function (next) { stack.push(next); });
        }
    }

    form.addEventListener('change', function (ev) {
        var box = ev.target;
        if (!box.matches || !box.matches('input[type="checkbox"][data-permission]')) { return; }

        var key = box.getAttribute('data-permission');
        if (box.checked) {
            cascade(key, requires, function (dep) { dep.checked = true; });
        } else {
            cascade(key, dependents, function (dep) { dep.checked = false; });
        }
    });

    // ---------- Bereichs-Umschalter ----------
    document.addEventListener('click', function (ev) {
        var button = ev.target.closest('[data-toggle-permissions]');
        if (!button) { return; }

        var card = button.closest('[data-permission-card]');
        if (!card) { return; }

        var boxes = Array.prototype.slice.call(
            card.querySelectorAll('input[type="checkbox"][data-permission]:not([disabled])')
        );
        var enable = boxes.some(function (box) { return !box.checked; });
        boxes.forEach(function (box) {
            if (box.checked !== enable) {
                box.checked = enable;
                box.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    });

    // ---------- Filter ----------
    var filter = document.querySelector('[data-permission-filter]');
    if (!filter) { return; }

    filter.addEventListener('input', function () {
        var term = filter.value.trim().toLowerCase();

        document.querySelectorAll('[data-permission-card]').forEach(function (card) {
            var visible = 0;
            card.querySelectorAll('[data-permission-row]').forEach(function (row) {
                var match = term === '' || (row.getAttribute('data-search') || '').indexOf(term) !== -1;
                row.hidden = !match;
                if (match) { visible++; }
            });
            card.hidden = visible === 0;
        });
    });
})();
