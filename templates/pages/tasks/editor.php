<?php
/**
 * Aufgaben-Editor (Bundle task-editor.js). Erwartet: $exam, $boot.
 * Der gesamte Zustand liegt als JSON im Bootstrap-Block (wird nicht ausgeführt).
 */
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($ctx->url('/klausuren/' . (int) $exam['id'])) ?>">← <?= e($exam['title']) ?></a></div>
        <h1 class="page-title">✏️ Aufgaben</h1>
    </div>
</div>

<div id="task-editor-app" class="task-editor" aria-live="polite">
    <noscript><div class="alert alert-error">Der Aufgaben-Editor braucht JavaScript.</div></noscript>
    <p class="text-soft">Editor wird geladen ...</p>
</div>
<script type="application/json" id="task-editor-boot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?></script>
