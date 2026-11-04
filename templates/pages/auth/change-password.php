<?php
/** Passwortwechsel. Erwartet: $forced, $viaCode (bool). */
$skipCurrent = $forced || $viaCode;
?>
<h1 style="font-size:1.35rem;">Passwort ändern</h1>
<?php if ($forced): ?>
    <div class="alert alert-warning">Bitte lege zunächst ein neues Passwort fest, bevor du weiterarbeitest.</div>
<?php elseif ($viaCode): ?>
    <div class="alert alert-info">Du bist per Code angemeldet — du kannst hier ein neues Passwort festlegen, ohne das alte zu kennen.</div>
<?php endif; ?>

<form method="post" action="">
    <?= $csrf->field() ?>

    <?php if (!$skipCurrent): ?>
        <div class="field">
            <label for="current_password">Aktuelles Passwort</label>
            <input class="input" type="password" id="current_password" name="current_password" required autocomplete="current-password">
        </div>
    <?php endif; ?>
    <div class="field">
        <label for="password">Neues Passwort</label>
        <input class="input" type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
        <div class="hint">Mindestens 8 Zeichen.</div>
    </div>
    <div class="field">
        <label for="password_confirm">Neues Passwort wiederholen</label>
        <input class="input" type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
    </div>

    <button class="btn btn-primary btn-block" type="submit">Passwort speichern</button>
</form>
