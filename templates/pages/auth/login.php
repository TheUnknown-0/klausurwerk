<?php /** Login. Erwartet optional: $redirect. $codeLoginAvailable (bool). */ ?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css', $ctx->config['app']['base_url'])) ?>">
<div class="page-eyebrow">Anmeldung</div>
<h1>Anmelden</h1>
<p class="text-soft text-sm">Melde dich mit den Daten deiner Zugangskarte bzw. deinem Lehrkräfte-Konto an.</p>

<form method="post" action="" data-login-form>
    <?= $csrf->field() ?>
    <?php if (!empty($redirect)): ?>
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
    <?php endif; ?>

    <div class="field">
        <label for="username">Benutzername</label>
        <input class="input" type="text" id="username" name="username" required autofocus autocomplete="username">
    </div>
    <div class="field">
        <label for="password">Passwort</label>
        <div class="input-group">
            <input class="input" type="password" id="password" name="password" required autocomplete="current-password">
            <button type="button" class="input-icon-btn" data-toggle-password="password" aria-label="Passwort anzeigen" aria-pressed="false"><?= icon('eye') ?></button>
        </div>
    </div>

    <button class="btn btn-primary btn-block btn-lg" type="submit">Anmelden</button>
</form>

<?php if ($codeLoginAvailable): ?>
    <p class="text-center text-sm mt-2">
        <a href="<?= e($ctx->url('/login-code') . (!empty($redirect) ? '?redirect=' . rawurlencode($redirect) : '')) ?>">
            Anmeldung per Code (auch bei vergessenem Passwort)
        </a>
    </p>
<?php endif; ?>

<script src="<?= e(asset('js/login-qr.js', $ctx->config['app']['base_url'])) ?>"></script>
