/**
 * QR-Code der Zugangskarte: Die Karte verlinkt auf /login#u=<name>&p=<passwort>.
 * Der Fragment-Teil (#…) erreicht den Server nie (kein Log, kein Referer).
 * Das Skript füllt die Felder und entfernt das Fragment sofort aus der Adresse.
 */
(function () {
    'use strict';
    var hash = window.location.hash ? window.location.hash.substring(1) : '';
    if (!hash) { return; }
    var params = new URLSearchParams(hash);
    var form = document.querySelector('[data-login-form]');
    if (!form) { return; }
    var u = params.get('u');
    var p = params.get('p');
    if (u) { form.querySelector('[name="username"]').value = u; }
    if (p) { form.querySelector('[name="password"]').value = p; }
    try { history.replaceState(null, '', window.location.pathname + window.location.search); } catch (e) { /* egal */ }
    if (u && p) { form.querySelector('button[type="submit"]').focus(); }
})();
