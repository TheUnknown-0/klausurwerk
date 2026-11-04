<?php
/**
 * Operator bearbeiten.
 * Erwartet: $operator, $values, $afbLevels, $taskCount
 */

$base = $ctx->url('/admin/operatoren');
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($base) ?>">← Operatoren</a></div>
        <h1 class="page-title"><?= e($operator['name']) ?></h1>
        <p class="page-sub">Bei <?= e((string) $taskCount) ?> Aufgabe(n) zugeordnet.</p>
    </div>
</div>

<form class="card card-pad max-w-lg" method="post" action="<?= e($base . '/' . (int) $operator['id']) ?>">
    <?= $csrf->field() ?>
    <div class="form-grid">
        <div class="field">
            <label for="name">Name</label>
            <input class="input" type="text" id="name" name="name" maxlength="60" required value="<?= e($values['name']) ?>">
        </div>
        <div class="field">
            <label for="afb">Anforderungsbereich</label>
            <select id="afb" name="afb">
                <?php foreach ($afbLevels as $level): ?>
                    <option value="<?= e($level) ?>"<?= $values['afb'] === $level ? ' selected' : '' ?>><?= e($level) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="field">
        <label for="description">Erläuterung</label>
        <input class="input" type="text" id="description" name="description" maxlength="500" value="<?= e($values['description']) ?>">
    </div>
    <div class="cluster">
        <button class="btn btn-primary" type="submit">Speichern</button>
        <a class="btn btn-ghost" href="<?= e($base) ?>">Abbrechen</a>
    </div>
</form>
