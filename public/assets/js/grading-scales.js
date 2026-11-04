/**
 * Notenschlüssel-Editor: Stufen hinzufügen/entfernen und eine Sofortprüfung
 * (verbindlich prüft der Server): Grenzen absteigend, letzte Stufe bei 0 Prozent.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-scale-form]');
    if (!form) { return; }

    var body = form.querySelector('[data-steps]');
    var box = form.querySelector('[data-scale-check]');

    function number(text) {
        var n = parseFloat(String(text).replace(',', '.'));
        return isNaN(n) ? null : n;
    }

    function relabel() {
        Array.prototype.forEach.call(body.querySelectorAll('[data-step]'), function (row, i) {
            var inputs = row.querySelectorAll('input');
            var names = ['Beschriftung', 'Wert', 'Ab Prozent'];
            Array.prototype.forEach.call(inputs, function (input, k) {
                input.setAttribute('aria-label', names[k] + ' Stufe ' + (i + 1));
            });
        });
    }

    function check() {
        var rows = body.querySelectorAll('[data-step]');
        var problems = [];
        var previous = null;
        Array.prototype.forEach.call(rows, function (row, i) {
            var min = number(row.querySelector('[name="min_percent[]"]').value);
            if (min === null) { return; }
            if (min < 0 || min > 100) { problems.push('Stufe ' + (i + 1) + ': Prozent zwischen 0 und 100.'); }
            if (previous !== null && min >= previous) { problems.push('Stufe ' + (i + 1) + ': Grenze muss kleiner sein als bei der Stufe davor.'); }
            previous = min;
        });
        if (rows.length > 0) {
            var last = number(rows[rows.length - 1].querySelector('[name="min_percent[]"]').value);
            if (last !== null && last !== 0) { problems.push('Die letzte Stufe muss bei 0 Prozent beginnen.'); }
        }
        if (!box) { return; }
        box.hidden = problems.length === 0;
        box.textContent = problems.join(' ');
    }

    function addRow() {
        var row = document.createElement('tr');
        row.setAttribute('data-step', '');

        function cell(input) {
            var td = document.createElement('td');
            td.appendChild(input);
            row.appendChild(td);
        }
        function field(name, extraClass, inputmode) {
            var input = document.createElement('input');
            input.type = 'text';
            input.name = name;
            input.required = true;
            input.className = 'input' + (extraClass ? ' ' + extraClass : '');
            if (inputmode) { input.setAttribute('inputmode', inputmode); }
            if (name === 'label[]') { input.maxLength = 10; }
            return input;
        }
        cell(field('label[]', '', null));
        cell(field('value[]', 'w-narrow', 'decimal'));
        cell(field('min_percent[]', 'w-narrow', 'decimal'));

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-sm btn-danger-ghost';
        remove.setAttribute('data-remove-step', '');
        remove.setAttribute('aria-label', 'Stufe entfernen');
        remove.textContent = '✕';
        cell(remove);

        body.appendChild(row);
        relabel();
        row.querySelector('input').focus();
    }

    form.addEventListener('click', function (event) {
        if (event.target.closest('[data-add-step]')) {
            addRow();
            check();
            return;
        }
        var remove = event.target.closest('[data-remove-step]');
        if (remove) {
            if (body.querySelectorAll('[data-step]').length > 1) {
                remove.closest('[data-step]').remove();
                relabel();
                check();
            }
        }
    });
    form.addEventListener('input', check);
    check();
})();
