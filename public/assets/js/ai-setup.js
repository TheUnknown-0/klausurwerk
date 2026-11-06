/* KI-Assistent: Adresse und Befehle in die Zwischenablage kopieren. */
(function () {
    'use strict';
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-copy-target]');
        if (!btn) {
            return;
        }
        var el = document.getElementById(btn.getAttribute('data-copy-target'));
        if (!el) {
            return;
        }
        var text = el.value !== undefined ? el.value : el.textContent;
        var done = function () {
            var label = btn.lastChild;
            var before = label.textContent;
            btn.classList.add('is-copied');
            label.textContent = ' Kopiert';
            setTimeout(function () { btn.classList.remove('is-copied'); label.textContent = before; }, 1800);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text.trim()).then(done, function () { select(el); });
        } else {
            select(el);
        }
    });
    function select(el) {
        if (el.select) {
            el.select();
        } else {
            var range = document.createRange();
            range.selectNodeContents(el);
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        }
    }
})();
