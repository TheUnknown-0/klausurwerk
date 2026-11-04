<?php /** Fehlerseite. Erwartet: $status (int), $message (string). */ ?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css', $ctx->config['app']['base_url'])) ?>">
<div class="error-sheet">
    <div class="error-code num"><?= e($status) ?></div>
    <h1 class="error-title">
        <?= $status === 404 ? 'Hier ist nichts.' : 'Das hat nicht geklappt.' ?>
    </h1>
    <p class="text-soft error-message"><?= e($message) ?></p>
    <a class="btn btn-primary" href="<?= e($ctx->url('/')) ?>"><?= icon('house') ?> Zur Startseite</a>
</div>
