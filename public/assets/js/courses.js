/**
 * Kursdetail: Schüler:innen per Suche zum Kurs hinzufügen.
 *
 * [data-member-add] trägt data-search-url (GET /api/benutzer/suche) und data-add-url
 * (POST /admin/kurse/{id}/mitglieder). Treffer erscheinen in [data-member-results];
 * ein Klick auf "Hinzufügen" sendet user_id und lädt die Seite neu.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-member-add]');
    if (!root || !window.BM) { return; }

    var input = root.querySelector('[data-member-search]');
    var list = root.querySelector('[data-member-results]');
    var searchUrl = root.getAttribute('data-search-url');
    var addUrl = root.getAttribute('data-add-url');
    var timer = null;
    var controller = null;

    function message(text) {
        list.textContent = '';
        var item = document.createElement('li');
        item.className = 'text-sm text-soft';
        item.textContent = text;
        list.appendChild(item);
    }

    function render(users) {
        list.textContent = '';
        if (users.length === 0) {
            message('Keine Treffer.');
            return;
        }
        users.forEach(function (user) {
            var item = document.createElement('li');

            var label = document.createElement('span');
            label.className = 'search-result-name';
            label.textContent = user.name;
            var meta = document.createElement('span');
            meta.className = 'text-faint text-sm mono';
            meta.textContent = ' ' + user.username;
            label.appendChild(meta);
            if (user.kurse && user.kurse.length > 0) {
                var courses = document.createElement('span');
                courses.className = 'text-sm text-soft';
                courses.textContent = ' · ' + user.kurse.map(function (c) { return c.name; }).join(', ');
                label.appendChild(courses);
            }
            item.appendChild(label);

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-primary';
            button.textContent = 'Hinzufügen';
            button.addEventListener('click', function () {
                button.disabled = true;
                window.BM.fetchJson(addUrl, { json: { user_id: user.id } }).then(function (data) {
                    if (data && data.success) {
                        window.location.reload();
                    } else {
                        button.disabled = false;
                        window.BM.flash('error', (data && data.error) || 'Das Hinzufügen hat nicht geklappt.');
                    }
                });
            });
            item.appendChild(button);
            list.appendChild(item);
        });
    }

    function search() {
        var query = input.value.trim();
        if (query.length < 2) {
            list.textContent = '';
            return;
        }
        if (controller) { controller.abort(); }
        controller = new AbortController();
        fetch(searchUrl + '?role=student&q=' + encodeURIComponent(query), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal: controller.signal
        }).then(function (res) { return res.json(); }).then(function (data) {
            if (data && data.success) {
                render(data.users);
            } else {
                message('Die Suche ist fehlgeschlagen.');
            }
        }).catch(function (err) {
            if (!err || err.name !== 'AbortError') { message('Die Suche ist fehlgeschlagen.'); }
        });
    }

    input.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(search, 250);
    });
})();
