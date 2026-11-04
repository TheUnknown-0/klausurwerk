<?php
/**
 * Klausurseite (Schüleransicht und Lehrer-Vorschau). Die Oberfläche baut das Bundle exam.js auf.
 * Erwartet: $boot (Bootstrap-Daten nach docs/API.md Abschnitt 1).
 */
?>
<div id="exam-app"></div>
<noscript>
    <div class="minimal-wrap">
        <div class="minimal-card">
            <h1>JavaScript nötig</h1>
            <p class="text-soft">Die Klausuroberfläche braucht JavaScript. Bitte aktiviere es oder wende dich an die Aufsicht.</p>
        </div>
    </div>
</noscript>
<script type="application/json" id="exam-boot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
