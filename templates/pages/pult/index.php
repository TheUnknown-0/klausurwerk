<?php
/**
 * Lehrer-Pult. Die Oberfläche baut das Bundle pult.js auf.
 * Erwartet: $exam, $boot (Bootstrap-Daten).
 */
?>
<div id="pult-app" class="pult-app">
    <p class="text-soft">Das Pult wird geladen …</p>
</div>
<noscript><div class="alert alert-error">Das Pult braucht JavaScript.</div></noscript>
<script type="application/json" id="pult-boot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
