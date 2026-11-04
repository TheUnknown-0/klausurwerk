/*
 * Lehrkräfte-Suche auf der Klausur-Übersicht.
 * Fragt zuerst GET /api/benutzer/suche?q=&role=teacher (Verwaltungsmodul) und fällt auf
 * /api/klausuren/{id}/lehrkraft-suche zurück, wenn der Endpunkt fehlt oder nichts Brauchbares liefert.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-staff-search]');
    if (!form) { return; }

    var input = form.querySelector('[data-staff-input]');
    var hidden = form.querySelector('[data-staff-user]');
    var list = form.querySelector('[data-staff-results]');
    var chosen = form.querySelector('[data-staff-chosen]');
    var submit = form.querySelector('[data-staff-submit]');
    var primary = form.getAttribute('data-search-url');
    var fallback = form.getAttribute('data-fallback-url');
    var timer = null;
    var seq = 0;
    var active = -1;

    function nameOf(u) {
        if (u.name) { return u.name; }
        if (u.label) { return u.label; }
        var full = ((u.firstname || '') + ' ' + (u.lastname || '')).trim();
        return full || u.username || ('Benutzer ' + u.id);
    }

    function normalize(data) {
        var items = Array.isArray(data) ? data : (data && (data.users || data.results || data.items)) || [];
        return items.filter(function (u) { return u && u.id; });
    }

    function fetchUsers(url, q) {
        var sep = url.indexOf('?') === -1 ? '?' : '&';
        return fetch(url + sep + 'q=' + encodeURIComponent(q) + '&role=teacher', {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (res) {
            if (!res.ok) { throw new Error('http ' + res.status); }
            return res.json();
        }).then(normalize);
    }

    function search(q) {
        var mine = ++seq;
        fetchUsers(primary, q).catch(function () { return fetchUsers(fallback, q); })
            .then(function (users) { if (mine === seq) { render(users); } })
            .catch(function () {
                if (mine === seq) { render([]); chosen.textContent = 'Die Suche ist gerade nicht erreichbar.'; }
            });
    }

    function close() {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        active = -1;
    }

    function choose(user) {
        hidden.value = String(user.id);
        input.value = nameOf(user);
        chosen.textContent = 'Ausgewählt: ' + nameOf(user);
        submit.disabled = false;
        close();
    }

    function render(users) {
        list.replaceChildren();
        if (users.length === 0) {
            chosen.textContent = 'Keine passende Lehrkraft gefunden.';
            close();
            return;
        }
        users.slice(0, 10).forEach(function (u, i) {
            var li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.id = 'staff-opt-' + i;
            li.textContent = nameOf(u) + (u.username ? ' (' + u.username + ')' : '');
            li.addEventListener('mousedown', function (ev) { ev.preventDefault(); choose(u); });
            li._user = u;
            list.appendChild(li);
        });
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        chosen.textContent = users.length + ' Treffer, wähle mit Pfeiltasten oder Klick.';
    }

    input.addEventListener('input', function () {
        hidden.value = '';
        submit.disabled = true;
        clearTimeout(timer);
        var q = input.value.trim();
        if (q.length < 2) { close(); return; }
        timer = setTimeout(function () { search(q); }, 250);
    });

    input.addEventListener('keydown', function (ev) {
        var items = list.querySelectorAll('li');
        if (list.hidden || items.length === 0) { return; }
        if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
            ev.preventDefault();
            active = (active + (ev.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
            items.forEach(function (li, i) { li.setAttribute('aria-selected', i === active ? 'true' : 'false'); });
            input.setAttribute('aria-activedescendant', items[active].id);
        } else if (ev.key === 'Enter' && active >= 0) {
            ev.preventDefault();
            choose(items[active]._user);
        } else if (ev.key === 'Escape') {
            close();
        }
    });

    input.addEventListener('blur', function () { setTimeout(close, 120); });
    form.addEventListener('submit', function (ev) {
        if (hidden.value === '') { ev.preventDefault(); chosen.textContent = 'Bitte wähle zuerst eine Person aus der Liste.'; input.focus(); }
    });
})();
