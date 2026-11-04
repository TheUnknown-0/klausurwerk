<?php /** Zweiter Faktor für Lehrkräfte/Admins: Code aus der E-Mail. */ ?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css', $ctx->config['app']['base_url'])) ?>">
<div class="page-eyebrow">Zweiter Faktor</div>
<h1>Anmeldecode eingeben</h1>
<p class="text-soft text-sm">
    Wir haben dir einen 6-stelligen Code an deine hinterlegte E-Mail-Adresse geschickt.
    Er ist 10 Minuten gültig.
</p>

<form method="post" action="<?= e($ctx->url('/login/zweiter-faktor')) ?>">
    <?= $csrf->field() ?>
    <div class="field">
        <label for="code">Code</label>
        <input class="input input-code" type="text" id="code" name="code" required autofocus
               inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code">
    </div>
    <button class="btn btn-primary btn-block btn-lg" type="submit">Anmelden</button>
</form>

<p class="text-center text-sm mt-2">
    <a href="<?= e($ctx->url('/login')) ?>">Zurück zur Anmeldung</a>
</p>
