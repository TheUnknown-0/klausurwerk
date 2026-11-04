<?php
/**
 * Korrektur-Einzelansicht (Bundle grading.js baut die Oberfläche).
 * Erwartet: $exam, $boot.
 */
?>
<div id="grading-app" class="g-app">
    <p class="text-soft" data-loading>Die Korrektur wird geladen ...</p>
    <noscript><div class="alert alert-error">Für die Korrektur wird JavaScript benötigt.</div></noscript>
</div>
<script type="application/json" id="grading-boot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
