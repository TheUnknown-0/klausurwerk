// Läuft synchron im <head>, um Theme-Flackern zu vermeiden.
(function () {
    // Kennzeichnet, dass JavaScript läuft. Ohne dieses Skript behält das
    // Dokument die Klasse `no-js`, und die Navigation wird per CSS fest in
    // den Seitenfluss gestellt statt in eine ausklappbare Leiste.
    document.documentElement.classList.remove('no-js');

    try {
        var stored = localStorage.getItem('kw-theme');
        var theme = stored === 'dark' || stored === 'light'
            ? stored
            : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
    } catch (e) {
        document.documentElement.setAttribute('data-theme', 'light');
    }
})();
