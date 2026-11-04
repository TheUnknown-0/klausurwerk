<?php
/** Flash-Meldungen aus der Session (einmalige Anzeige). */
$flashes = $ctx->session->pullFlashes();
if ($flashes !== []): ?>
    <div class="flash-stack">
        <?php foreach ($flashes as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
