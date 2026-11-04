<?php
/**
 * Notenschlüssel anlegen/bearbeiten.
 * Erwartet: $scale (null = neu), $values (name, type, grade_level, steps), $types, $levels, $usedBy
 */

$base = $ctx->url('/admin/notenschluessel');
$isNew = $scale === null;
$action = $isNew ? $base . '/neu' : $base . '/' . (int) $scale['id'];
$steps = is_array($values['steps'] ?? null) ? $values['steps'] : [];
if ($steps === []) {
    $steps = [['label' => '', 'value' => '', 'min_percent' => '0']];
}
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($base) ?>">← Notenschlüssel</a></div>
        <h1 class="page-title"><?= e($title) ?></h1>
    </div>
    <?php if (!$isNew): ?>
        <div class="page-actions">
            <a class="btn btn-ghost" href="<?= e($base . '/neu?kopie=' . (int) $scale['id']) ?>">Als Vorlage kopieren</a>
        </div>
    <?php endif; ?>
</div>

<form class="card card-pad max-w-lg mb-2" method="post" action="<?= e($action) ?>" data-scale-form>
    <?= $csrf->field() ?>
    <div class="form-grid">
        <div class="field">
            <label for="name">Name</label>
            <input class="input" type="text" id="name" name="name" maxlength="100" required value="<?= e($values['name'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="type">Typ</label>
            <select id="type" name="type" required>
                <?php foreach ($types as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= ($values['type'] ?? '') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="grade_level">Standard für Klassenstufe</label>
            <select id="grade_level" name="grade_level">
                <option value="">Keine (Vorlage)</option>
                <?php foreach ($levels as $level): ?>
                    <option value="<?= e((string) $level) ?>"<?= (string) ($values['grade_level'] ?? '') === (string) $level ? ' selected' : '' ?>>Klasse <?= e((string) $level) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="hint">Je Klassenstufe gibt es genau einen Standard.</div>
        </div>
    </div>

    <h3>Stufen</h3>
    <p class="text-sm text-soft">
        Von der besten zur schwächsten Stufe. „Ab Prozent“ muss absteigend sein, die letzte Stufe beginnt bei 0.
        Der Wert ist die Note (z. B. 1,3) oder die Punktzahl (z. B. 13), die in Berechnungen verwendet wird.
    </p>
    <div class="table-wrap mb-2">
        <table class="data-table scale-table">
            <thead>
            <tr>
                <th scope="col">Beschriftung</th>
                <th scope="col">Wert</th>
                <th scope="col">Ab Prozent</th>
                <th scope="col"><span class="sr-only">Zeile entfernen</span></th>
            </tr>
            </thead>
            <tbody data-steps>
            <?php foreach ($steps as $i => $step): ?>
                <tr data-step>
                    <td><input class="input" type="text" name="label[]" maxlength="10" required aria-label="Beschriftung Stufe <?= e((string) ($i + 1)) ?>" value="<?= e($step['label'] ?? '') ?>"></td>
                    <td><input class="input w-narrow" type="text" inputmode="decimal" name="value[]" required aria-label="Wert Stufe <?= e((string) ($i + 1)) ?>" value="<?= e($step['value'] ?? '') ?>"></td>
                    <td><input class="input w-narrow" type="text" inputmode="decimal" name="min_percent[]" required aria-label="Ab Prozent Stufe <?= e((string) ($i + 1)) ?>" value="<?= e($step['min_percent'] ?? '') ?>"></td>
                    <td><button class="btn btn-sm btn-danger-ghost" type="button" data-remove-step aria-label="Stufe entfernen"><?= icon('x') ?></button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="cluster">
        <button class="btn btn-ghost" type="button" data-add-step><?= icon('plus') ?> Stufe hinzufügen</button>
    </div>
    <div class="alert alert-info mt-2" data-scale-check hidden></div>

    <div class="cluster mt-2">
        <button class="btn btn-primary" type="submit"><?= $isNew ? 'Anlegen' : 'Änderungen speichern' ?></button>
        <a class="btn btn-ghost" href="<?= e($base) ?>">Abbrechen</a>
    </div>
</form>

<?php if (!$isNew): ?>
    <div class="card card-pad max-w-lg">
        <h3 class="mt-0">Verwendung</h3>
        <?php if ($usedBy === [] && $scale['grade_level'] === null): ?>
            <p class="text-sm text-soft">Kein Kurs nutzt diese Vorlage.</p>
        <?php elseif ($scale['grade_level'] !== null): ?>
            <p class="text-sm text-soft">Standard für alle Kurse der Klasse <?= e((string) (int) $scale['grade_level']) ?>, die keinen eigenen Schlüssel gewählt haben.</p>
        <?php endif; ?>
        <?php if ($usedBy !== []): ?>
            <div class="chip-row">
                <?php foreach ($usedBy as $course): ?>
                    <a class="badge badge-primary" href="<?= e($ctx->url('/admin/kurse/' . (int) $course['id'])) ?>"><?= e($course['name']) ?> (<?= e($course['school_year']) ?>)</a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="post" action="<?= e($base . '/' . (int) $scale['id'] . '/loeschen') ?>" class="mt-2"
              data-confirm="Notenschlüssel „<?= e($scale['name']) ?>“ wirklich löschen?">
            <?= $csrf->field() ?>
            <button class="btn btn-danger-ghost" type="submit">Notenschlüssel löschen</button>
        </form>
    </div>
<?php endif; ?>
