<?php /** Fehlerseite. Erwartet: $status (int), $message (string). */ ?>
<div style="text-align:center;">
    <div style="font-family:var(--font-display);font-size:3.4rem;font-weight:800;color:var(--accent);line-height:1;"><?= e($status) ?></div>
    <h1 style="font-size:1.3rem;margin-top:8px;">
        <?= $status === 404 ? 'Hier ist nichts.' : 'Das hat nicht geklappt.' ?>
    </h1>
    <p class="text-soft" style="white-space:pre-wrap;"><?= e($message) ?></p>
    <a class="btn btn-primary" href="<?= e($ctx->url('/')) ?>">Zur Startseite</a>
</div>
