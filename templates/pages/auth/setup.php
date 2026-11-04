<?php /** Erst-Einrichtung (nur solange keine Benutzer existieren). */ $old = $ctx->session->pullOldInput(); ?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css', $ctx->config['app']['base_url'])) ?>">
<div class="page-eyebrow">Einrichtung</div>
<h1>Willkommen bei Klausurwerk</h1>
<p class="text-soft">
    Richte in einem Schritt das Admin-Konto und deine Schule ein.
</p>

<form method="post" action="">
    <?= $csrf->field() ?>

    <h3>Admin-Konto</h3>
    <div class="form-grid">
        <div class="field">
            <label for="firstname">Vorname</label>
            <input class="input" type="text" id="firstname" name="firstname" value="<?= e($old['firstname'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="lastname">Nachname</label>
            <input class="input" type="text" id="lastname" name="lastname" value="<?= e($old['lastname'] ?? '') ?>">
        </div>
    </div>
    <div class="form-grid">
        <div class="field">
            <label for="username">Benutzername</label>
            <input class="input" type="text" id="username" name="username" required pattern="[a-zA-Z0-9._\-]{3,50}" value="<?= e($old['username'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="email">E-Mail (für Anmeldecodes)</label>
            <input class="input" type="email" id="email" name="email" value="<?= e($old['email'] ?? '') ?>">
        </div>
    </div>
    <div class="form-grid">
        <div class="field">
            <label for="password">Passwort</label>
            <input class="input" type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
            <div class="hint">Mindestens 8 Zeichen.</div>
        </div>
        <div class="field">
            <label for="password_confirm">Passwort wiederholen</label>
            <input class="input" type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
        </div>
    </div>

    <hr class="divider">
    <h3>Schule</h3>
    <div class="field">
        <label for="school_name">Name der Schule</label>
        <input class="input" type="text" id="school_name" name="school_name" required value="<?= e($old['school_name'] ?? '') ?>">
    </div>

    <button class="btn btn-primary btn-block btn-lg" type="submit">Einrichtung abschließen</button>
</form>
