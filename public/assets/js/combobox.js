/**
 * Durchsuchbare Auswahl: macht aus <select data-combobox> ein Textfeld mit
 * anklickbarer Trefferliste (für lange Listen wie alle Schüler:innen).
 *
 * Das <select> bleibt als verstecktes Formularfeld erhalten und trägt weiter
 * name/Wert; bei einer Auswahl wird dort ein 'change'-Event ausgelöst, sodass
 * andere Skripte (z. B. enrollments.js) unverändert funktionieren. Ohne
 * JavaScript bleibt das normale Dropdown sichtbar.
 *
 * Gesucht wird über den Optionstext und ggf. das <optgroup>-Label; mehrere
 * Suchwörter müssen alle vorkommen („7a müller“), Groß-/Kleinschreibung und
 * Akzente werden ignoriert.
 */
(function () {
    'use strict';

    var MAX_RESULTS = 50;
    var counter = 0;

    function normalize(s) {
        return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    function init(select) {
        var id = ++counter;
        var items = [];
        Array.prototype.forEach.call(select.options, function (opt) {
            if (!opt.value || opt.disabled) { return; }
            var label = opt.textContent.replace(/\s+/g, ' ').trim();
            var group = opt.parentNode.tagName === 'OPTGROUP' ? opt.parentNode.label : '';
            items.push({ value: opt.value, label: label, group: group, key: normalize(group + ' ' + label) });
        });

        var wrap = document.createElement('div');
        wrap.className = 'combobox';

        var input = document.createElement('input');
        input.type = 'text';
        input.className = select.className;
        input.id = select.id ? select.id + '-search' : 'combobox-' + id;
        input.autocomplete = 'off';
        input.spellcheck = false;
        input.placeholder = select.getAttribute('data-combobox') || 'Tippen zum Suchen …';
        input.required = select.required;
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');

        var list = document.createElement('ul');
        list.className = 'combobox-list';
        list.id = 'combobox-list-' + id;
        list.setAttribute('role', 'listbox');
        list.hidden = true;
        input.setAttribute('aria-controls', list.id);

        // Label auf das Suchfeld umhängen; das Select wird nur noch versteckt mitgeschickt
        if (select.id) {
            var label = document.querySelector('label[for="' + select.id + '"]');
            if (label) { label.htmlFor = input.id; }
        }
        select.required = false;
        select.hidden = true;
        select.tabIndex = -1;
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(input);
        wrap.appendChild(list);
        wrap.appendChild(select);

        var matches = [];
        var active = -1;

        function selectedLabel() {
            var opt = select.options[select.selectedIndex];
            return opt && opt.value ? opt.textContent.replace(/\s+/g, ' ').trim() : '';
        }

        function setValue(value) {
            if (select.value === value) { return; }
            select.value = value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function validate() {
            input.setCustomValidity(select.value || input.value === '' ? '' : 'Bitte einen Eintrag aus der Liste auswählen.');
        }

        function setActive(i) {
            var nodes = list.querySelectorAll('[role="option"]');
            if (active >= 0 && nodes[active]) { nodes[active].setAttribute('aria-selected', 'false'); }
            active = i;
            if (active >= 0 && nodes[active]) {
                nodes[active].setAttribute('aria-selected', 'true');
                input.setAttribute('aria-activedescendant', nodes[active].id);
                nodes[active].scrollIntoView({ block: 'nearest' });
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }

        function close() {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            setActive(-1);
        }

        function open() {
            var tokens = normalize(input.value).split(/\s+/).filter(Boolean);
            matches = items.filter(function (it) {
                return tokens.every(function (t) { return it.key.indexOf(t) !== -1; });
            });

            list.textContent = '';
            active = -1;
            matches.slice(0, MAX_RESULTS).forEach(function (it, i) {
                var li = document.createElement('li');
                li.id = list.id + '-' + i;
                li.className = 'combobox-option';
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', 'false');
                li.textContent = it.label;
                if (it.group) {
                    var meta = document.createElement('span');
                    meta.className = 'combobox-meta';
                    meta.textContent = it.group;
                    li.appendChild(meta);
                }
                li.addEventListener('mousedown', function (e) {
                    e.preventDefault(); // Fokus im Feld halten
                    choose(i);
                });
                list.appendChild(li);
            });
            if (matches.length === 0 || matches.length > MAX_RESULTS) {
                var note = document.createElement('li');
                note.className = 'combobox-note';
                note.setAttribute('role', 'presentation');
                note.textContent = matches.length === 0
                    ? 'Keine Treffer.'
                    : (matches.length - MAX_RESULTS) + ' weitere Treffer — Suche verfeinern.';
                list.appendChild(note);
            }
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            if (tokens.length > 0 && matches.length > 0) { setActive(0); }
        }

        function choose(i) {
            var it = matches[i];
            if (!it) { return; }
            setValue(it.value);
            input.value = it.label;
            validate();
            close();
        }

        input.addEventListener('input', function () {
            if (input.value !== selectedLabel()) { setValue(''); }
            validate();
            open();
        });

        input.addEventListener('click', function () {
            if (list.hidden) { open(); }
        });

        input.addEventListener('keydown', function (e) {
            var count = Math.min(matches.length, MAX_RESULTS);
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (list.hidden) { open(); return; }
                if (count === 0) { return; }
                var step = e.key === 'ArrowDown' ? 1 : -1;
                setActive(active < 0 ? (step > 0 ? 0 : count - 1) : (active + step + count) % count);
            } else if (e.key === 'Enter') {
                if (!list.hidden && active >= 0) {
                    e.preventDefault(); // nicht das Formular abschicken
                    choose(active);
                }
            } else if (e.key === 'Escape') {
                if (!list.hidden) {
                    e.preventDefault();
                    close();
                }
            }
        });

        input.addEventListener('blur', function () {
            close();
            // Halb getippten Text auf die bestehende Auswahl zurücksetzen
            if (select.value) { input.value = selectedLabel(); }
            validate();
        });

        input.value = selectedLabel();
        validate();
    }

    document.querySelectorAll('select[data-combobox]').forEach(init);
})();
