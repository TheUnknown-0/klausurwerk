<?php /** Anmeldung per Code — Schritt 2: Code eingeben. Erwartet: $username, optional $redirect. */ ?>
<h1 style="font-size:1.35rem;">Code eingeben</h1>
<p class="text-soft text-sm">
    Falls für <strong><?= e($username) ?></strong> ein Konto mit hinterlegter E-Mail existiert,
    haben wir gerade einen 6-stelligen Code verschickt. Er ist 10 Minuten gültig.
</p>

<form method="post" action="<?= e($ctx->url('/login-code/bestaetigen')) ?>">
    <?= $csrf->field() ?>
    <?php if (!empty($redirect)): ?>
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
    <?php endif; ?>

    <div class="field">
        <label for="code">Code</label>
        <input class="input" type="text" id="code" name="code" required autofocus
               inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"
               style="letter-spacing:4px;font-size:1.2rem;text-align:center;">
    </div>

    <button class="btn btn-primary btn-block btn-lg" type="submit">Anmelden</button>
</form>

<p class="text-center text-sm mt-2">
    <a href="<?= e($ctx->url('/login-code') . (!empty($redirect) ? '?redirect=' . rawurlencode($redirect) : '')) ?>">Neuen Code anfordern</a>
    ·
    <a href="<?= e($ctx->url('/login')) ?>">Zurück zur Passwort-Anmeldung</a>
</p>
<p class="text-center text-faint text-sm">Zwischen zwei Codes liegt mindestens eine Minute.</p>
