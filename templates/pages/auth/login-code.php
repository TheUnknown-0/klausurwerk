<?php /** Anmeldung per Code — Schritt 1: Benutzername. Erwartet optional: $redirect, $username. */ ?>
<h1 style="font-size:1.35rem;">Anmeldung per Code</h1>
<p class="text-soft text-sm">
    Wir schicken dir einen Code an die für dein Konto hinterlegte E-Mail-Adresse — ohne Passwort.
    Das funktioniert auch, wenn du dein Passwort vergessen hast.
</p>

<form method="post" action="<?= e($ctx->url('/login-code')) ?>">
    <?= $csrf->field() ?>
    <?php if (!empty($redirect)): ?>
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
    <?php endif; ?>

    <div class="field">
        <label for="username">Benutzername</label>
        <input class="input" type="text" id="username" name="username" required autofocus autocomplete="username" value="<?= e($username) ?>">
    </div>

    <button class="btn btn-primary btn-block btn-lg" type="submit">Code anfordern</button>
</form>

<p class="text-center text-sm mt-2">
    <a href="<?= e($ctx->url('/login') . (!empty($redirect) ? '?redirect=' . rawurlencode($redirect) : '')) ?>">Zurück zur Passwort-Anmeldung</a>
</p>
