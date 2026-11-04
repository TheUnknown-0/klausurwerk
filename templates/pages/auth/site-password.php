<?php /** Globales Seitenpasswort ("Zugangscode"). Erwartet optional: $redirect. */ ?>
<h1 style="font-size:1.35rem;">Zugangscode</h1>
<p class="text-soft text-sm">Diese Seite ist geschützt. Bitte gib den Zugangscode ein, den du von deiner Schule erhalten hast.</p>

<form method="post" action="">
    <?= $csrf->field() ?>
    <?php if (!empty($redirect)): ?>
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
    <?php endif; ?>

    <div class="field">
        <label for="code">Zugangscode</label>
        <input class="input" type="password" id="code" name="code" required autofocus>
    </div>

    <button class="btn btn-primary btn-block" type="submit">Weiter</button>
</form>
