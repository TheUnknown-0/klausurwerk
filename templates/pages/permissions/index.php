<?php
/**
 * Berechtigungs-Matrix je Benutzer.
 * Erwartet: $candidates, $selected, $direct, $byGroup, $roleDefaults,
 * $assignedGroups, $groups, $catalog, $dependencies, $allowed, $isSelf,
 * $roleLabels, $canGrant, $canManageGroups
 */

use App\Services\PermissionService;

$base = $ctx->url('/admin/berechtigungen');
$selectedId = $selected !== null ? (int) $selected['id'] : 0;
$editable = $canGrant && $selected !== null && !$isSelf;

/** Darf der Handelnde dieses Recht bewegen? */
$manageable = static fn (string $key): bool => $allowed === null || in_array($key, $allowed, true);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Berechtigungen</h1>
        <p class="page-sub">Granulare Rechte für Orga-Team und Lehrkräfte.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($base . '/gruppen') ?>">🗂️ Berechtigungsgruppen</a>
    </div>
</div>

<div class="tabs">
    <a class="tab active" href="<?= e($base) ?>">Benutzer</a>
    <a class="tab" href="<?= e($base . '/gruppen') ?>">Gruppen</a>
</div>

<?php $permissionBlocks = page_blocks('admin-berechtigungen', [
    'hinweis' => 'Hinweis zum Berechtigungskonzept',
    'benutzerauswahl' => 'Benutzerauswahl',
    'uebersicht' => 'Benutzerübersicht',
    'konto' => 'Gewähltes Konto',
    'gruppen' => 'Berechtigungsgruppen',
    'rechte' => 'Rechte-Matrix',
]); ?>
<?php foreach ($permissionBlocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'hinweis'): ?>
<div class="alert alert-info">
    <strong>Berechtigungskonzept:</strong> Administratoren haben automatisch alle Rechte.
    Schüler:innen bekommen keine granularen Rechte. Orga-Team und Lehrkräfte
    erhalten Rechte direkt, über Berechtigungsgruppen oder als Rollen-Standard. Voraussetzungen werden
    beim Erteilen automatisch mitgegeben, abhängige Rechte beim Entziehen mitgenommen.
    <?php if ($allowed !== null): ?>
        <br>Du kannst nur Rechte vergeben oder entziehen, die du selbst besitzt.
    <?php endif; ?>
</div>

<?php elseif ($blockKey === 'benutzerauswahl'): ?>
<form class="card card-pad mb-2" method="get" action="<?= e($base) ?>">
    <div class="field">
        <label for="benutzer">Benutzer auswählen</label>
        <select id="benutzer" name="benutzer">
            <option value="">– bitte wählen –</option>
            <?php foreach (['orga' => 'Orga-Team', 'teacher' => 'Lehrkräfte'] as $roleKey => $roleTitle): ?>
                <?php $inRole = array_filter($candidates, static fn (array $c): bool => (string) $c['role'] === $roleKey); ?>
                <?php if ($inRole !== []): ?>
                    <optgroup label="<?= e($roleTitle) ?>">
                        <?php foreach ($inRole as $candidate): ?>
                            <option value="<?= e((string) (int) $candidate['id']) ?>"<?= $selectedId === (int) $candidate['id'] ? ' selected' : '' ?>>
                                <?= e(trim((string) $candidate['lastname'] . ', ' . (string) $candidate['firstname'])) ?>
                                (<?= e($candidate['username']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
        <?php if ($candidates === []): ?>
            <div class="hint">Es gibt noch keine Konten mit der Rolle Orga-Team oder Lehrkraft.</div>
        <?php endif; ?>
    </div>
    <button class="btn btn-primary" type="submit">Rechte anzeigen</button>
</form>

<?php elseif ($blockKey === 'uebersicht'): ?>
    <?php if ($selected === null && $candidates !== []): ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Benutzername</th>
                    <th>Rolle</th>
                    <th>Direkte Rechte</th>
                    <th>Gruppen</th>
                    <th style="text-align:right;">Aktion</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($candidates as $candidate): ?>
                    <tr>
                        <td><?= e(trim((string) $candidate['firstname'] . ' ' . (string) $candidate['lastname'])) ?></td>
                        <td class="mono"><?= e($candidate['username']) ?></td>
                        <td><span class="badge badge-primary"><?= e($roleLabels[(string) $candidate['role']] ?? $candidate['role']) ?></span></td>
                        <td><?= e((string) (int) $candidate['direct_count']) ?></td>
                        <td><?= e((string) (int) $candidate['group_count']) ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-sm btn-ghost"
                                   href="<?= e($base . '?benutzer=' . (int) $candidate['id']) ?>">Rechte bearbeiten</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php elseif ($blockKey === 'konto'): ?>
    <?php if ($selected !== null): ?>
    <div class="card card-pad mb-2">
        <div class="cluster">
            <div>
                <strong><?= e(trim((string) $selected['firstname'] . ' ' . (string) $selected['lastname'])) ?></strong>
                <span class="mono text-soft"><?= e($selected['username']) ?></span>
            </div>
            <span class="badge badge-primary"><?= e($roleLabels[(string) $selected['role']] ?? $selected['role']) ?></span>
            <span class="badge"><?= e((string) count($direct)) ?> direkte Rechte</span>
        </div>
        <?php if ($isSelf): ?>
            <div class="alert alert-warning mb-0" style="margin-top:12px;">
                Das ist dein eigenes Konto — eigene Berechtigungen kannst du nicht ändern.
            </div>
        <?php elseif (!$canGrant): ?>
            <div class="alert alert-info mb-0" style="margin-top:12px;">
                Du darfst Berechtigungen ansehen, aber nicht ändern.
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<?php elseif ($blockKey === 'gruppen'): ?>
    <?php if ($selected !== null && $groups !== []): ?>
        <div class="card mb-2">
            <div class="card-header"><h3 style="margin:0;">Berechtigungsgruppen</h3></div>
            <form method="post" action="<?= e($base . '/gruppen-zuweisen') ?>">
                <?= $csrf->field() ?>
                <input type="hidden" name="benutzer" value="<?= e((string) $selectedId) ?>">
                <div class="card-body">
                    <?php foreach ($groups as $group): ?>
                        <label class="checkbox-row">
                            <input type="checkbox" name="gruppen[]" value="<?= e((string) (int) $group['id']) ?>"
                                   <?= in_array((int) $group['id'], $assignedGroups, true) ? ' checked' : '' ?>
                                   <?= $editable ? '' : ' disabled' ?>>
                            <span>
                                <strong><?= e($group['name']) ?></strong>
                                <span class="badge badge-info"><?= e((string) (int) $group['item_count']) ?> Rechte</span>
                                <?php if (!empty($group['description'])): ?>
                                    <span class="text-soft text-sm">— <?= e($group['description']) ?></span>
                                <?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php if ($editable): ?>
                    <div class="card-footer">
                        <button class="btn btn-primary" type="submit">Gruppen speichern</button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    <?php endif; ?>

<?php elseif ($blockKey === 'rechte'): ?>
    <?php if ($selected !== null): ?>
    <form method="post" action="<?= e($base . '/speichern') ?>" data-permission-form
          data-dependencies="<?= e((string) json_encode($dependencies, JSON_UNESCAPED_UNICODE)) ?>">
        <?= $csrf->field() ?>
        <input type="hidden" name="benutzer" value="<?= e((string) $selectedId) ?>">

        <div class="card card-pad mb-2">
            <div class="field mb-0">
                <label for="rechte-filter">Berechtigungen filtern</label>
                <input class="input" type="search" id="rechte-filter" data-permission-filter
                       placeholder="z. B. Stände, Benutzer, Anwesenheit">
            </div>
        </div>

        <?php foreach ($catalog as $groupName => $entries): ?>
            <div class="card mb-2" data-permission-card>
                <div class="card-header">
                    <h3 style="margin:0;"><?= e($groupName) ?></h3>
                    <?php if ($editable): ?>
                        <button class="btn btn-sm btn-ghost" type="button" data-toggle-permissions>Alle an/aus</button>
                    <?php endif; ?>
                </div>
                <div class="table-wrap" style="border:none; border-radius:0;">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Berechtigung</th>
                            <th style="width:90px;">Direkt</th>
                            <th style="width:90px;">Vererbt</th>
                            <th>Herkunft &amp; Voraussetzungen</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($entries as $key => $label): ?>
                            <?php
                            $isDirect = in_array($key, $direct, true);
                            $fromGroups = $byGroup[$key] ?? [];
                            $fromRole = in_array($key, $roleDefaults, true);
                            $canManage = $manageable($key);
                            $requires = $dependencies[$key] ?? [];
                            ?>
                            <tr data-permission-row data-search="<?= e(mb_strtolower($label . ' ' . $key . ' ' . $groupName)) ?>">
                                <td>
                                    <?= e($label) ?>
                                    <div class="text-faint text-sm mono"><?= e($key) ?></div>
                                </td>
                                <td>
                                    <input type="checkbox" name="permissions[]" value="<?= e($key) ?>"
                                           data-permission="<?= e($key) ?>"
                                           <?= $isDirect ? ' checked' : '' ?>
                                           <?= $editable && $canManage ? '' : ' disabled' ?>>
                                    <?php if ($isDirect && !($editable && $canManage)): ?>
                                        <?php /* Bestehendes Recht erhalten, obwohl die Box gesperrt ist. */ ?>
                                        <input type="hidden" name="permissions[]" value="<?= e($key) ?>">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input type="checkbox" disabled
                                           <?= ($fromGroups !== [] || $fromRole) ? ' checked' : '' ?>>
                                </td>
                                <td class="text-sm">
                                    <?php if ($fromGroups !== []): ?>
                                        <span class="badge badge-info">Gruppe: <?= e(implode(', ', $fromGroups)) ?></span>
                                    <?php endif; ?>
                                    <?php if ($fromRole): ?>
                                        <span class="badge badge-accent">Rollen-Standard</span>
                                    <?php endif; ?>
                                    <?php if (!$canManage): ?>
                                        <span class="badge badge-warning">🔒 besitzt du selbst nicht</span>
                                    <?php endif; ?>
                                    <?php if ($requires !== []): ?>
                                        <span class="text-faint">
                                            erfordert <?= e(PermissionService::labelList($requires)) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($editable): ?>
            <div class="cluster">
                <button class="btn btn-primary" type="submit">Berechtigungen speichern</button>
                <a class="btn btn-ghost" href="<?= e($base) ?>">Abbrechen</a>
            </div>
        <?php endif; ?>
    </form>
    <?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
