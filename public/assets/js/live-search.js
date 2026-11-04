/*
 * Live-Suche für Filterformulare.
 *
 * Ein GET-Formular mit data-live="key" lädt beim Tippen (entprellt) bzw. bei
 * Änderung eines Selects die Seite per fetch neu und tauscht nur den Bereich
 * [data-live-target="key"] aus. Serverlogik, Rechte und Pagination bleiben
 * unverändert — ohne JavaScript arbeitet das Formular ganz normal.
 *
 * Seitenlinks innerhalb des Zielbereichs (z. B. ?seite=2) werden ebenfalls
 * per fetch geladen; die URL wird per History-API nachgeführt, damit Reload,
 * Zurück-Taste und Teilen des Links weiterhin funktionieren.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 250;

    function init(form) {
        var key = form.getAttribute('data-live');
        var target = document.querySelector('[data-live-target="' + key + '"]');
        if (!target || (form.method || 'get').toLowerCase() !== 'get') {
            return;
        }
        target.setAttribute('aria-live', 'polite');
        target.setAttribute('aria-busy', 'false');

        var timer = null;
        var controller = null;

        function buildUrl() {
            var url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
            var params = new URLSearchParams();
            new FormData(form).forEach(function (value, name) {
                if (typeof value === 'string' && value !== '') {
                    params.append(name, value);
                }
            });
            url.search = params.toString();
            return url.toString();
        }

        function load(url, push) {
            if (controller) {
                controller.abort();
            }
            controller = new AbortController();
            target.classList.add('is-loading');
            target.setAttribute('aria-busy', 'true');

            fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' },
                signal: controller.signal
            }).then(function (res) {
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }
                return res.text();
            }).then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.querySelector('[data-live-target="' + key + '"]');
                if (!fresh) {
                    // Unerwartete Antwort (z. B. Login-Seite): normal navigieren
                    window.location.href = url;
                    return;
                }
                target.innerHTML = fresh.innerHTML;
                if (push) {
                    window.history.pushState({ live: key }, '', url);
                } else {
                    window.history.replaceState({ live: key }, '', url);
                }
                target.classList.remove('is-loading');
                target.setAttribute('aria-busy', 'false');
                document.dispatchEvent(new CustomEvent('live-search:updated', { detail: { key: key, target: target } }));
            }).catch(function (err) {
                if (err && err.name === 'AbortError') {
                    return;
                }
                target.classList.remove('is-loading');
                target.setAttribute('aria-busy', 'false');
                if (window.BM && typeof window.BM.flash === 'function') {
                    window.BM.flash('error', 'Die Liste konnte nicht aktualisiert werden.');
                }
            });
        }

        function schedule() {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { load(buildUrl(), false); }, DEBOUNCE_MS);
        }

        // Textfelder: entprellt beim Tippen
        form.addEventListener('input', function (e) {
            var el = e.target;
            if (el.matches('input[type="search"], input[type="text"], input[type="number"], input:not([type])')) {
                schedule();
            }
        });

        // Auswahlfelder, Datum, Checkboxen: sofort
        form.addEventListener('change', function (e) {
            var el = e.target;
            if (el.matches('select, input[type="checkbox"], input[type="radio"], input[type="date"], input[type="datetime-local"]')) {
                window.clearTimeout(timer);
                load(buildUrl(), false);
            }
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            window.clearTimeout(timer);
            load(buildUrl(), false);
        });

        // Seitenlinks und „Zurücksetzen“ im Zielbereich ebenfalls live laden
        target.addEventListener('click', function (e) {
            var a = e.target.closest('a[href]');
            if (!a || a.hasAttribute('download') || a.target === '_blank' || e.ctrlKey || e.metaKey || e.shiftKey) {
                return;
            }
            if (!a.matches('.pagination a, [data-live-link], .pager a')) {
                return;
            }
            var url = new URL(a.href, window.location.href);
            if (url.origin !== window.location.origin) {
                return;
            }
            e.preventDefault();
            syncForm(url);
            load(url.toString(), true);
        });

        // Formularfelder an eine URL angleichen (nach Pagination / Zurück-Taste)
        function syncForm(url) {
            Array.prototype.forEach.call(form.elements, function (el) {
                if (!el.name || el.type === 'submit' || el.type === 'button') {
                    return;
                }
                var value = url.searchParams.get(el.name);
                if (el.type === 'checkbox' || el.type === 'radio') {
                    el.checked = value !== null && (value === el.value);
                } else {
                    el.value = value === null ? '' : value;
                }
            });
        }

        window.addEventListener('popstate', function (e) {
            if (e.state && e.state.live && e.state.live !== key) {
                return;
            }
            var url = new URL(window.location.href);
            syncForm(url);
            load(url.toString(), false);
        });
    }

    document.querySelectorAll('form[data-live]').forEach(init);
})();
