<?php /** Hinweis für Konten ohne Berechtigungen. */ ?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css', $ctx->config['app']['base_url'])) ?>">
<div class="card card-pad notice-sheet">
    <div class="page-eyebrow"><?= icon('lock') ?> Konto</div>
    <h1>Noch keine Berechtigungen</h1>
    <p class="text-soft mb-0">
        Dein Konto ist angelegt, aber es wurden dir noch keine Bereiche freigeschaltet.
        Bitte wende dich an die Administration.
    </p>
</div>
