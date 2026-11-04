<?php
/**
 * Übersicht der Berechtigungsgruppen .
 * Erwartet: $groups, $items (groupId => list<string>), $canManageGroups
 */

use App\Services\PermissionService;

$base = $ctx->url('/admin/berechtigungen');
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Berechtigungsgruppen</h1>
        <p class="page-sub">Rechte-Vorlagen, die Benutzern zugewiesen werden können.</p>
    </div>
    <?php if ($canManageGroups): ?>
        <div class="page-actions">
            <a class="btn btn-primary" href="<?= e($base . '/gruppen/neu') ?>">➕ Neue Gruppe</a>
        </div>
    <?php endif; ?>
</div>

<div class="tabs">
    <a class="tab" href="<?= e($base) ?>">Benutzer</a>
    <a class="tab active" href="<?= e($base . '/gruppen') ?>">Gruppen</a>
</div>

<?php $groupBlocks = page_blocks('admin-berechtigungsgruppen', [
    'keine-gruppen' => 'Hinweis: noch keine Gruppen',
    'gruppenliste' => 'Gruppenliste',
]); ?>
<?php foreach ($groupBlocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'keine-gruppen'): ?>
    <?php if ($groups === []): ?>
    <div class="empty-state">
        <div class="empty-icon">🗂️</div>
        <p>Es gibt noch keine Berechtigungsgruppen.</p>
        <?php if ($canManageGroups): ?>
            <a class="btn btn-primary" href="<?= e($base . '/gruppen/neu') ?>">Erste Gruppe anlegen</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<?php elseif ($blockKey === 'gruppenliste'): ?>
    <?php if ($groups !== []): ?>
    <div class="stack">
        <?php foreach ($groups as $group): ?>
            <?php $groupId = (int) $group['id']; ?>
            <div class="card">
                <div class="card-header">
                    <h3 style="margin:0;"><?= e($group['name']) ?></h3>
                    <span class="badge badge-info"><?= e((string) (int) $group['user_count']) ?> Benutzer</span>
                </div>
                <div class="card-body">
                    <?php if (!empty($group['description'])): ?>
                        <p class="text-soft text-sm"><?= e($group['description']) ?></p>
                    <?php endif; ?>
                    <div class="chip-row">
                        <?php foreach ($items[$groupId] ?? [] as $permission): ?>
                            <span class="badge badge-primary"><?= e(PermissionService::label($permission)) ?></span>
                        <?php endforeach; ?>
                        <?php if (($items[$groupId] ?? []) === []): ?>
                            <span class="text-faint text-sm">Keine Rechte hinterlegt.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($canManageGroups): ?>
                    <div class="card-footer">
                        <a class="btn btn-sm btn-ghost"
                           href="<?= e($base . '/gruppen/' . $groupId . '/bearbeiten') ?>">Bearbeiten</a>
                        <form method="post" action="<?= e($base . '/gruppen/' . $groupId . '/loeschen') ?>"
                              data-confirm="Gruppe „<?= e($group['name']) ?>“ wirklich löschen? Bereits erteilte Einzelrechte bleiben bestehen.">
                            <?= $csrf->field() ?>
                            <button class="btn btn-sm btn-danger-ghost" type="submit">Löschen</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
