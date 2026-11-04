/**
 * Einstellungen: Logo-Vorschau vor dem Hochladen und Standardwerte für die Takt-Felder.
 */
(function () {
    'use strict';

    var input = document.querySelector('[data-logo-input]');
    var preview = document.querySelector('[data-logo-preview]');
    if (input && preview) {
        var objectUrl = null;
        input.addEventListener('change', function () {
            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
                objectUrl = null;
            }
            var file = input.files && input.files[0];
            if (!file || file.type.indexOf('image/') !== 0) {
                preview.hidden = true;
                return;
            }
            objectUrl = URL.createObjectURL(file);
            preview.src = objectUrl;
            preview.hidden = false;
        });
    }

    var reset = document.querySelector('[data-reset-intervals]');
    if (reset) {
        reset.addEventListener('click', function () {
            document.querySelectorAll('input[data-default]').forEach(function (field) {
                field.value = field.getAttribute('data-default');
            });
        });
    }
})();
