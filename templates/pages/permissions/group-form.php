<?php
/**
 * Berechtigungsgruppe anlegen/bearbeiten.
 * Erwartet: $group (null beim Anlegen), $items, $old, $catalog, $allowed, $action
 */

use App\Services\PermissionService;

$base = $ctx->url('/admin/berechtigungen');
$isNew = $group === null;

// Nach einem Validierungsfehler die zuletzt gewählten Rechte wieder anzeigen.
$checked = isset($old['permissions']) && is_array($old['permissions'])
    ? PermissionService::sanitize($old['permissions'])
    : $items;

$manageable = static fn (string $key): bool => $allowed === null || in_array($key, $allowed, true);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($base . '/gruppen') ?>">← Berechtigungsgruppen</a></div>
        <h1 class="page-title"><?= $isNew ? 'Neue Berechtigungsgruppe' : e($group['name']) ?></h1>
        <p class="page-sub">Voraussetzungen werden beim Speichern automatisch ergänzt.</p>
    </div>
</div>

<form method="post" action="<?= e($action) ?>" data-permission-form
      data-dependencies="<?= e((string) json_encode($dependencies, JSON_UNESCAPED_UNICODE)) ?>">
    <?= $csrf->field() ?>

<?php $groupFormBlocks = page_blocks('admin-gruppen-formular', [
    'stammdaten' => 'Name, Beschreibung & Filter',
    'rechte' => 'Rechte-Auswahl',
]); ?>
<?php foreach ($groupFormBlocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'stammdaten'): ?>
    <div class="card card-pad mb-2">
        <div class="form-grid">
            <div class="field">
                <label for="name">Name *</label>
                <input class="input" type="text" id="name" name="name" required maxlength="100"
                       value="<?= e($old['name'] ?? ($group['name'] ?? '')) ?>">
            </div>
            <div class="field">
                <label for="description">Beschreibung</label>
                <input class="input" type="text" id="description" name="description" maxlength="500"
                       value="<?= e($old['description'] ?? ($group['description'] ?? '')) ?>">
            </div>
        </div>
        <div class="field mb-0">
            <label for="rechte-filter">Berechtigungen filtern</label>
            <input class="input" type="search" id="rechte-filter" data-permission-filter
                   placeholder="z. B. Stände, Benutzer, Anwesenheit">
        </div>
    </div>

<?php elseif ($blockKey === 'rechte'): ?>
    <?php foreach ($catalog as $groupName => $entries): ?>
        <div class="card mb-2" data-permission-card>
            <div class="card-header">
                <h3 style="margin:0;"><?= e($groupName) ?></h3>
                <button class="btn btn-sm btn-ghost" type="button" data-toggle-permissions>Alle an/aus</button>
            </div>
            <div class="card-body">
                <?php foreach ($entries as $key => $label): ?>
                    <?php $canManage = $manageable($key); $isChecked = in_array($key, $checked, true); ?>
                    <label class="checkbox-row" data-permission-row
                           data-search="<?= e(mb_strtolower($label . ' ' . $key . ' ' . $groupName)) ?>">
                        <input type="checkbox" name="permissions[]" value="<?= e($key) ?>"
                               data-permission="<?= e($key) ?>"
                               <?= $isChecked ? ' checked' : '' ?>
                               <?= $canManage ? '' : ' disabled' ?>>
                        <span>
                            <?= e($label) ?>
                            <span class="text-faint mono text-sm"><?= e($key) ?></span>
                            <?php if (!$canManage): ?>
                                <span class="badge badge-warning">🔒 besitzt du selbst nicht</span>
                            <?php endif; ?>
                        </span>
                    </label>
                    <?php if ($isChecked && !$canManage): ?>
                        <?php /* Bereits enthaltenes Recht erhalten, obwohl die Box gesperrt ist. */ ?>
                        <input type="hidden" name="permissions[]" value="<?= e($key) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>

    <div class="cluster">
        <button class="btn btn-primary" type="submit"><?= $isNew ? 'Gruppe anlegen' : 'Änderungen speichern' ?></button>
        <a class="btn btn-ghost" href="<?= e($base . '/gruppen') ?>">Abbrechen</a>
    </div>
</form>
