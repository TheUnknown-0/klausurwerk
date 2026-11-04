<?php
/**
 * Raumliste mit Formular für einen neuen Raum.
 * Erwartet: $rooms, $values, $min, $max
 */

$base = $ctx->url('/admin/raeume');
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Unterricht</div>
        <h1 class="page-title">Räume</h1>
        <p class="page-sub">Räume mit Sitzraster. Wer wo sitzt, legst du im Pult einer Klausur fest.</p>
    </div>
</div>

<?php if ($rooms === []): ?>
    <div class="empty-state">
        <div class="empty-icon"><?= icon('armchair') ?></div>
        <p>Es gibt noch keine Räume.</p>
    </div>
<?php else: ?>
    <div class="table-wrap mb-2">
        <table class="data-table">
            <thead>
            <tr>
                <th>Raum</th>
                <th>Raster</th>
                <th>Gespeicherte Plätze</th>
                <th>Klausuren</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rooms as $room): ?>
                <tr>
                    <td><a href="<?= e($base . '/' . (int) $room['id']) ?>"><strong><?= e($room['name']) ?></strong></a></td>
                    <td><?= e((string) (int) $room['grid_cols']) ?> × <?= e((string) (int) $room['grid_rows']) ?></td>
                    <td><?= e((string) (int) $room['seat_count']) ?></td>
                    <td><?= e((string) (int) $room['exam_count']) ?></td>
                    <td><div class="row-actions"><a class="btn btn-sm btn-ghost" href="<?= e($base . '/' . (int) $room['id']) ?>">Öffnen</a></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<form class="card card-pad max-w-lg" method="post" action="<?= e($base . '/neu') ?>">
    <?= $csrf->field() ?>
    <h3 class="mt-0">Neuer Raum</h3>
    <div class="form-grid">
        <div class="field">
            <label for="name">Name</label>
            <input class="input" type="text" id="name" name="name" maxlength="100" required value="<?= e($values['name']) ?>" placeholder="z. B. R 204">
        </div>
        <div class="field">
            <label for="grid_cols">Spalten</label>
            <input class="input w-narrow" type="number" id="grid_cols" name="grid_cols" min="<?= e((string) $min) ?>" max="<?= e((string) $max) ?>" required value="<?= e($values['grid_cols']) ?>">
        </div>
        <div class="field">
            <label for="grid_rows">Zeilen</label>
            <input class="input w-narrow" type="number" id="grid_rows" name="grid_rows" min="<?= e((string) $min) ?>" max="<?= e((string) $max) ?>" required value="<?= e($values['grid_rows']) ?>">
        </div>
    </div>
    <button class="btn btn-primary" type="submit">Raum anlegen</button>
</form>
