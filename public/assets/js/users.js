/**
 * Benutzerverwaltung und Zugangskarten (Seiten-JS, ohne Bundler).
 *
 * Benutzerformular ([data-user-form]):
 *   - [data-role-select]      → blendet [data-role-section="student|staff"] passend zur Rolle ein
 *   - [data-username-input]   → Vorschlag vorname.nachname von der API, solange nicht von Hand geändert
 * Kartenseite ([data-cards-form]):
 *   - [data-course-jump]      → Kurs wechseln (lädt die Seite mit ?kurs=ID)
 *   - [data-toggle-all]       → alle Häkchen der Auswahl umschalten
 *   - [data-card-pick]        → Auswahl aus der Suche bleibt über Suchläufe erhalten
 */
(function () {
    'use strict';

    // ---------- Benutzerformular ----------
    var userForm = document.querySelector('[data-user-form]');
    if (userForm) {
        var roleSelect = userForm.querySelector('[data-role-select]');
        var sections = userForm.querySelectorAll('[data-role-section]');

        var applyRole = function () {
            var role = roleSelect ? roleSelect.value : 'student';
            var key = role === 'student' ? 'student' : 'staff';
            sections.forEach(function (section) {
                section.hidden = section.getAttribute('data-role-section') !== key;
            });
            updateSuggestion();
        };

        var usernameInput = userForm.querySelector('[data-username-input]');
        var hint = userForm.querySelector('[data-username-hint]');
        var touched = usernameInput ? usernameInput.value !== '' : true;
        var auto = userForm.getAttribute('data-username-auto') === '1';
        var timer = null;
        var controller = null;

        if (usernameInput) {
            usernameInput.addEventListener('input', function () { touched = usernameInput.value !== ''; });
        }

        function updateSuggestion() {
            if (!auto || touched || !usernameInput) { return; }
            var role = roleSelect ? roleSelect.value : 'student';
            if (role !== 'student') { return; }
            var first = userForm.querySelector('#firstname').value.trim();
            var last = userForm.querySelector('#lastname').value.trim();
            if (first === '' && last === '') { return; }

            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                if (controller) { controller.abort(); }
                controller = new AbortController();
                var url = userForm.getAttribute('data-suggest-url')
                    + '?vorname=' + encodeURIComponent(first) + '&nachname=' + encodeURIComponent(last);
                fetch(url, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                    signal: controller.signal
                }).then(function (res) { return res.ok ? res.json() : null; }).then(function (data) {
                    if (data && data.success && data.username && !touched) {
                        usernameInput.value = data.username;
                        if (hint) { hint.textContent = 'Vorschlag aus dem Namen. Du kannst ihn ändern.'; }
                    }
                }).catch(function () { /* Vorschlag ist nur Komfort */ });
            }, 300);
        }

        userForm.querySelectorAll('[data-name-part]').forEach(function (input) {
            input.addEventListener('input', updateSuggestion);
        });
        if (roleSelect) {
            roleSelect.addEventListener('change', applyRole);
        }
        applyRole();
    }

    // ---------- Zugangskarten ----------
    var cardsForm = document.querySelector('[data-cards-form]');
    if (cardsForm) {
        var jump = document.querySelector('[data-course-jump]');
        if (jump) {
            jump.addEventListener('change', function () {
                var base = jump.getAttribute('data-base');
                window.location.href = jump.value ? base + '?kurs=' + encodeURIComponent(jump.value) : base;
            });
        }

        document.querySelectorAll('[data-toggle-all]').forEach(function (button) {
            button.addEventListener('click', function () {
                var boxes = cardsForm.querySelectorAll(button.getAttribute('data-toggle-all'));
                var anyUnchecked = Array.prototype.some.call(boxes, function (box) { return !box.checked; });
                boxes.forEach(function (box) { box.checked = anyUnchecked; });
            });
        });

        // Auswahl aus der Suche: bleibt erhalten, wenn die Trefferliste neu geladen wird.
        var picked = new Map();
        var chips = document.querySelector('[data-card-selection]');
        var hidden = document.querySelector('[data-card-hidden]');
        var results = document.querySelector('[data-live-target="cards-search"]');

        var render = function () {
            if (!chips || !hidden) { return; }
            chips.textContent = '';
            hidden.textContent = '';
            picked.forEach(function (label, id) {
                var chip = document.createElement('span');
                chip.className = 'badge badge-primary';
                chip.textContent = label;
                chips.appendChild(chip);

                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'user_ids[]';
                input.value = id;
                hidden.appendChild(input);
            });
            if (picked.size === 0) {
                var none = document.createElement('span');
                none.className = 'text-sm text-faint';
                none.textContent = 'Noch niemand aus der Suche ausgewählt.';
                chips.appendChild(none);
            }
        };

        var restore = function () {
            if (!results) { return; }
            results.querySelectorAll('[data-card-pick]').forEach(function (box) {
                if (picked.has(box.value)) { box.checked = true; }
            });
        };

        document.addEventListener('change', function (event) {
            var box = event.target;
            if (!results || !box.matches || !box.matches('[data-card-pick]') || !results.contains(box)) { return; }
            if (box.checked) {
                picked.set(box.value, box.getAttribute('data-label') || box.value);
            } else {
                picked.delete(box.value);
            }
            render();
        });
        document.addEventListener('live-search:updated', restore);
        render();
    }
})();
