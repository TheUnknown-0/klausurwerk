<?php
/**
 * Einstellungen der Anwendung.
 * Erwartet: $schoolName, $appName, $logo, $publicBaseUrl, $baseIsGuessed,
 * $guessedBase, $sitePasswordEnabled, $sitePasswordSet, $studentSelfService,
 * $canEdit, $isAdmin, $minPasswordLength.
 */
$settingsBlocks = page_blocks('admin-einstellungen', [
    'allgemein' => 'Schule & App',
    'logo' => 'Logo',
    'zugang' => 'Zugang & Selbstbedienung',
]);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">⚙️ Einstellungen</h1>
        <p class="page-sub">Grundlegende Angaben zur Anwendung — gelten für alle Aktionstage.</p>
    </div>
</div>

<?php foreach ($settingsBlocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'allgemein'): ?>
    <div class="card card-pad mb-2">
        <form method="post" action="<?= e($ctx->url('/admin/einstellungen')) ?>">
            <?= $csrf->field() ?>
            <div class="form-grid">
                <div class="field">
                    <label for="school_name">Schulname</label>
                    <input class="input" type="text" id="school_name" name="school_name" maxlength="200"
                           value="<?= e($schoolName) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                    <div class="hint">Erscheint z. B. auf Zugangsdaten- und Druck-PDFs.</div>
                </div>
                <div class="field">
                    <label for="app_name">App-Name</label>
                    <input class="input" type="text" id="app_name" name="app_name" maxlength="100"
                           placeholder="Gartenarbeitstag" value="<?= e($appName) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                    <div class="hint">Titel in Sidebar und Browser-Tab (Standard: „Gartenarbeitstag“).</div>
                </div>
                <div class="field">
                    <label for="public_base_url">Öffentliche Adresse</label>
                    <input class="input" type="url" id="public_base_url" name="public_base_url" maxlength="255"
                           placeholder="https://gartenarbeitstag.beispiel-schule.de" value="<?= e($publicBaseUrl) ?>"
                           <?= $canEdit ? '' : 'disabled' ?>>
                    <div class="hint">
                        Für Links in PDFs und Zugangsdaten. Leer lassen, um die Adresse aus dem jeweiligen Aufruf zu
                        übernehmen<?= $baseIsGuessed ? ' (derzeit vermutet: ' . e($guessedBase) . ')' : '' ?>.
                    </div>
                </div>
            </div>
            <?php if ($canEdit): ?>
                <input type="hidden" name="student_self_service" value="<?= $studentSelfService ? '1' : '0' ?>">
                <?php if ($isAdmin): ?>
                    <input type="hidden" name="site_password_enabled" value="<?= $sitePasswordEnabled ? '1' : '0' ?>">
                <?php endif; ?>
                <button class="btn btn-primary" type="submit">Speichern</button>
            <?php endif; ?>
        </form>
    </div>

<?php elseif ($blockKey === 'logo'): ?>
    <div class="card card-pad mb-2">
        <?php if (!empty($logo)): ?>
            <div class="cluster mb-2">
                <img class="brand-logo" style="width:64px;height:64px;"
                     src="<?= e($ctx->url('/medien/logos/' . $logo)) ?>" alt="Aktuelles Schullogo">
                <?php if ($canEdit): ?>
                    <form method="post" action="<?= e($ctx->url('/admin/einstellungen/logo')) ?>">
                        <?= $csrf->field() ?>
                        <input type="hidden" name="remove" value="1">
                        <button class="btn btn-sm btn-danger-ghost" type="submit">Entfernen</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p class="text-soft text-sm">Noch kein Logo hinterlegt — es wird ein Platzhalter angezeigt.</p>
        <?php endif; ?>
        <?php if ($canEdit): ?>
            <form method="post" action="<?= e($ctx->url('/admin/einstellungen/logo')) ?>" enctype="multipart/form-data">
                <?= $csrf->field() ?>
                <div class="field">
                    <label for="logo">Neues Logo (PNG, JPG, WebP oder SVG, max. 2 MB)</label>
                    <input class="input" type="file" id="logo" name="logo" accept=".png,.jpg,.jpeg,.webp,.svg" required>
                </div>
                <button class="btn btn-primary" type="submit">Logo hochladen</button>
            </form>
        <?php endif; ?>
    </div>

<?php elseif ($blockKey === 'zugang'): ?>
    <div class="grid-2">
        <div class="card card-pad">
            <h3 class="mt-0">Selbstbedienung</h3>
            <p class="text-sm text-soft">
                Legt fest, ob Schüler:innen sich selbst in Stände einschreiben bzw. Wünsche abgeben dürfen.
                Ist die Selbstbedienung deaktiviert, übernimmt die Orga alle Einschreibungen.
            </p>
            <?php if ($canEdit): ?>
                <form method="post" action="<?= e($ctx->url('/admin/einstellungen')) ?>">
                    <?= $csrf->field() ?>
                    <input type="hidden" name="school_name" value="<?= e($schoolName) ?>">
                    <input type="hidden" name="app_name" value="<?= e($appName) ?>">
                    <input type="hidden" name="public_base_url" value="<?= e($publicBaseUrl) ?>">
                    <label class="checkbox-row">
                        <input type="checkbox" name="student_self_service" value="1" <?= $studentSelfService ? 'checked' : '' ?>>
                        <span>Schüler:innen dürfen sich selbst einschreiben</span>
                    </label>
                    <button class="btn btn-primary btn-sm mt-2" type="submit">Speichern</button>
                </form>
            <?php else: ?>
                <span class="badge <?= $studentSelfService ? 'badge-success' : '' ?>">
                    <?= $studentSelfService ? 'Aktiviert' : 'Deaktiviert' ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="card card-pad">
            <h3 class="mt-0">Seitenpasswort</h3>
            <p class="text-sm text-soft">
                Zusätzlicher Zugangscode für die gesamte Anwendung (z. B. bei Betrieb im offenen Schulnetz) —
                unabhängig vom persönlichen Login. Nur Administrator:innen können ihn ändern.
            </p>
            <?php if ($isAdmin): ?>
                <form method="post" action="<?= e($ctx->url('/admin/einstellungen')) ?>">
                    <?= $csrf->field() ?>
                    <input type="hidden" name="school_name" value="<?= e($schoolName) ?>">
                    <input type="hidden" name="app_name" value="<?= e($appName) ?>">
                    <input type="hidden" name="public_base_url" value="<?= e($publicBaseUrl) ?>">
                    <input type="hidden" name="student_self_service" value="<?= $studentSelfService ? '1' : '0' ?>">
                    <div class="field">
                        <label for="site_password">Neues Seitenpasswort setzen</label>
                        <input class="input" type="password" id="site_password" name="site_password"
                               minlength="<?= e((string) $minPasswordLength) ?>" autocomplete="new-password">
                        <div class="hint">
                            Leer lassen, um das bestehende Passwort zu behalten.
                            <?= $sitePasswordSet ? 'Es ist aktuell eines gesetzt.' : 'Es ist aktuell keines gesetzt.' ?>
                        </div>
                    </div>
                    <label class="checkbox-row">
                        <input type="checkbox" name="site_password_enabled" value="1" <?= $sitePasswordEnabled ? 'checked' : '' ?>>
                        <span>Seitenpasswort-Schutz aktiv</span>
                    </label>
                    <button class="btn btn-primary btn-sm mt-2" type="submit">Speichern</button>
                </form>
            <?php else: ?>
                <span class="badge <?= $sitePasswordEnabled ? 'badge-success' : '' ?>">
                    <?= $sitePasswordEnabled ? 'Aktiv' : 'Nicht aktiv' ?>
                </span>
                <div class="hint">Nur Administrator:innen können das Seitenpasswort ändern.</div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
