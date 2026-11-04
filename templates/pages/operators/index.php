<?php
/**
 * Operatorenliste.
 * Erwartet: $operators, $afbLevels, $values
 */

$base = $ctx->url('/admin/operatoren');
$count = count($operators);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Operatoren</h1>
        <p class="page-sub">Handlungsaufforderungen für Aufgaben (Berlin) mit Anforderungsbereich (AFB). Die Reihenfolge gilt in der Auswahl und in der Hilfsmittelliste.</p>
    </div>
</div>

<?php if ($operators === []): ?>
    <div class="empty-state">
        <div class="empty-icon">🔤</div>
        <p>Es gibt noch keine Operatoren.</p>
    </div>
<?php else: ?>
    <div class="table-wrap mb-2">
        <table class="data-table">
            <thead>
            <tr>
                <th>Operator</th>
                <th>AFB</th>
                <th>Erläuterung</th>
                <th>Aufgaben</th>
                <th style="text-align:right;">Aktionen</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($operators as $i => $operator): ?>
                <?php $id = (int) $operator['id']; ?>
                <tr>
                    <td><strong><?= e($operator['name']) ?></strong></td>
                    <td><span class="badge badge-primary"><?= e($operator['afb']) ?></span></td>
                    <td class="text-sm text-soft"><?= e($operator['description']) ?></td>
                    <td><?= e((string) (int) $operator['task_count']) ?></td>
                    <td>
                        <div class="row-actions">
                            <form method="post" action="<?= e($base . '/' . $id . '/verschieben') ?>">
                                <?= $csrf->field() ?>
                                <input type="hidden" name="richtung" value="hoch">
                                <button class="btn btn-sm btn-ghost" type="submit"<?= $i === 0 ? ' disabled' : '' ?> aria-label="<?= e($operator['name']) ?> nach oben">↑</button>
                            </form>
                            <form method="post" action="<?= e($base . '/' . $id . '/verschieben') ?>">
                                <?= $csrf->field() ?>
                                <input type="hidden" name="richtung" value="runter">
                                <button class="btn btn-sm btn-ghost" type="submit"<?= $i === $count - 1 ? ' disabled' : '' ?> aria-label="<?= e($operator['name']) ?> nach unten">↓</button>
                            </form>
                            <a class="btn btn-sm btn-ghost" href="<?= e($base . '/' . $id) ?>">Bearbeiten</a>
                            <form method="post" action="<?= e($base . '/' . $id . '/loeschen') ?>"
                                  data-confirm="Operator „<?= e($operator['name']) ?>“ löschen?<?= (int) $operator['task_count'] > 0 ? ' Er ist bei ' . (int) $operator['task_count'] . ' Aufgabe(n) zugeordnet, die Zuordnung entfällt.' : '' ?>">
                                <?= $csrf->field() ?>
                                <button class="btn btn-sm btn-danger-ghost" type="submit">Löschen</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<form class="card card-pad max-w-lg" method="post" action="<?= e($base . '/neu') ?>">
    <?= $csrf->field() ?>
    <h3 class="mt-0">Neuer Operator</h3>
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
    <button class="btn btn-primary" type="submit">Operator anlegen</button>
</form>
