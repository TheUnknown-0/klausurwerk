<?php
/**
 * Benutzerliste mit Filtern und Pagination.
 * Erwartet: $users, $exclusions (user_id => list<name>), $total, $page, $pages,
 * $search, $roleFilter, $classFilter, $statusFilter, $classes, $counts,
 * $inactive, $roleLabels, $generated, $canCreate, $canEdit, $canDelete,
 * $canImport, $canReset
 */

$listUrl = $ctx->url('/admin/benutzer');

/** Baut eine Listen-URL mit den aktuellen Filtern. */
$pageUrl = static function (int $target) use ($listUrl, $search, $roleFilter, $classFilter, $statusFilter): string {
    $query = array_filter([
        'q' => $search,
        'rolle' => $roleFilter,
        'klasse' => $classFilter,
        'status' => $statusFilter,
        'seite' => $target > 1 ? (string) $target : '',
    ], static fn (string $value): bool => $value !== '');

    return $listUrl . ($query === [] ? '' : '?' . http_build_query($query));
};

$roleBadge = static fn (string $role): string => match ($role) {
    'admin' => 'badge badge-danger',
    'orga' => 'badge badge-primary',
    'teacher' => 'badge badge-info',
    default => 'badge',
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Benutzer</h1>
        <p class="page-sub"><?= e((string) $total) ?> Konten<?= $search !== '' || $roleFilter !== '' || $classFilter !== '' || $statusFilter !== '' ? ' (gefiltert)' : '' ?>.</p>
    </div>
    <div class="page-actions">
        <?php if ($canReset && $classes !== []): ?>
            <button class="btn btn-ghost" type="button" data-open-modal="modal-klasse">🖨️ Zugangsdaten-PDF</button>
        <?php endif; ?>
        <?php if ($canImport): ?>
            <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/benutzer/import')) ?>">📥 CSV-Import</a>
        <?php endif; ?>
        <?php if ($canCreate): ?>
            <a class="btn btn-primary" href="<?= e($ctx->url('/admin/benutzer/neu')) ?>">➕ Neuer Benutzer</a>
        <?php endif; ?>
    </div>
</div>

<?php $userBlocks = page_blocks('admin-benutzer', [
    'kennzahlen' => 'Kennzahlen',
    'filter' => 'Filter & Suche',
    'liste' => 'Benutzerliste',
]); ?>
<?php foreach ($userBlocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'kennzahlen'): ?>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value"><?= e((string) array_sum($counts)) ?></div>
        <div class="stat-label">Konten gesamt</div>
    </div>
    <div class="stat-card stat-accent">
        <div class="stat-value"><?= e((string) (($counts['admin'] ?? 0) + ($counts['orga'] ?? 0))) ?></div>
        <div class="stat-label">Verwaltung (Admin & Orga)</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= e((string) ($counts['teacher'] ?? 0)) ?></div>
        <div class="stat-label">Lehrkräfte</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= e((string) ($counts['student'] ?? 0)) ?></div>
        <div class="stat-label">Schüler:innen</div>
    </div>
    <div class="stat-card<?= $inactive > 0 ? ' stat-danger' : '' ?>">
        <div class="stat-value"><?= e((string) $inactive) ?></div>
        <div class="stat-label">Inaktiv</div>
    </div>
</div>

<?php elseif ($blockKey === 'filter'): ?>
<form class="card card-pad mb-2" method="get" action="<?= e($listUrl) ?>" data-live="users">
    <div class="form-grid">
        <div class="field">
            <label for="q">Suche</label>
            <input class="input" type="search" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Name, Benutzername oder E-Mail">
        </div>
        <div class="field">
            <label for="rolle">Rolle</label>
            <select id="rolle" name="rolle">
                <option value="">Alle Rollen</option>
                <?php foreach ($roleLabels as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $roleFilter === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="klasse">Klasse</label>
            <select id="klasse" name="klasse">
                <option value="">Alle Klassen</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= e($class) ?>"<?= $classFilter === (string) $class ? ' selected' : '' ?>><?= e($class) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">Alle</option>
                <option value="aktiv"<?= $statusFilter === 'aktiv' ? ' selected' : '' ?>>Nur aktive</option>
                <option value="inaktiv"<?= $statusFilter === 'inaktiv' ? ' selected' : '' ?>>Nur inaktive</option>
            </select>
        </div>
    </div>
    <div class="cluster">
        <button class="btn btn-primary" type="submit">Filtern</button>
        <a class="btn btn-ghost" href="<?= e($listUrl) ?>">Zurücksetzen</a>
    </div>
</form>

<?php elseif ($blockKey === 'liste'): ?>
<div data-live-target="users">
<?php if ($users === []): ?>
    <div class="empty-state">
        <div class="empty-icon">👥</div>
        <p>Keine Benutzer gefunden.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Benutzername</th>
                <th>Name</th>
                <th>Rolle</th>
                <th>Klasse</th>
                <th>Stufe</th>
                <th>Status</th>
                <th>Letzter Login</th>
                <th>Ausschlusskriterien</th>
                <th style="text-align:right;">Aktionen</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $row): ?>
                <?php
                $id = (int) $row['id'];
                $fullName = trim((string) $row['firstname'] . ' ' . (string) $row['lastname']);
                $role = (string) $row['role'];
                $isSelf = $auth->id() === $id;
                $chips = $exclusions[$id] ?? [];
                ?>
                <tr>
                    <td class="mono"><?= e($row['username']) ?></td>
                    <td>
                        <?= e($fullName !== '' ? $fullName : '—') ?>
                        <?php if ($isSelf): ?>
                            <span class="badge badge-info">Du</span>
                        <?php endif; ?>
                        <?php if (!empty($row['email'])): ?>
                            <div class="text-sm text-soft"><?= e($row['email']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="<?= e($roleBadge($role)) ?>"><?= e($roleLabels[$role] ?? $role) ?></span></td>
                    <td><?= e($row['class'] ?? '—') ?></td>
                    <td><?= $row['grade'] !== null ? e((string) $row['grade']) : '—' ?></td>
                    <td>
                        <?php if ((int) $row['is_active'] !== 1): ?>
                            <span class="badge badge-danger">inaktiv</span>
                        <?php elseif ((int) $row['must_change_password'] === 1): ?>
                            <span class="badge badge-warning">Passwortwechsel</span>
                        <?php else: ?>
                            <span class="badge badge-success">aktiv</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm text-soft">
                        <?= !empty($row['last_login_at']) ? e(date('d.m.Y H:i', strtotime((string) $row['last_login_at']))) : 'nie' ?>
                    </td>
                    <td>
                        <?php if ($role === 'student' && $chips !== []): ?>
                            <div class="chip-row">
                                <?php foreach ($chips as $chip): ?>
                                    <span class="badge badge-warning"><?= e($chip) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span class="text-faint">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <?php if ($canEdit): ?>
                                <a class="btn btn-sm btn-ghost" href="<?= e($ctx->url('/admin/benutzer/' . $id)) ?>">Bearbeiten</a>
                            <?php endif; ?>
                            <?php if ($canReset): ?>
                                <button class="btn btn-sm btn-ghost" type="button"
                                        data-open-modal="modal-passwort"
                                        data-reset-action="<?= e($ctx->url('/admin/benutzer/' . $id . '/passwort')) ?>"
                                        data-reset-name="<?= e($fullName !== '' ? $fullName : (string) $row['username']) ?>">🔑 Passwort</button>
                            <?php endif; ?>
                            <?php if ($canDelete && !$isSelf): ?>
                                <?php
                                $deleteConfirm = $role === 'student' && (int) $row['is_active'] === 1
                                    ? 'Benutzer „' . $row['username'] . '“ wirklich löschen? Schüler:innen mit Einschreibungen werden stattdessen deaktiviert.'
                                    : 'Benutzer „' . $row['username'] . '“ endgültig löschen? Vorhandene Einschreibungen werden mitgelöscht.';
                                ?>
                                <form method="post"
                                      action="<?= e($ctx->url('/admin/benutzer/' . $id . '/loeschen')) ?>"
                                      data-confirm="<?= e($deleteConfirm) ?>">
                                    <?= $csrf->field() ?>
                                    <button class="btn btn-sm btn-danger-ghost" type="submit">Löschen</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="cluster mt-2">
            <?php if ($page > 1): ?>
                <a class="btn btn-sm btn-ghost" data-live-link href="<?= e($pageUrl($page - 1)) ?>">← Zurück</a>
            <?php endif; ?>
            <span class="text-sm text-soft">Seite <?= e((string) $page) ?> von <?= e((string) $pages) ?></span>
            <?php if ($page < $pages): ?>
                <a class="btn btn-sm btn-ghost" data-live-link href="<?= e($pageUrl($page + 1)) ?>">Weiter →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>

<?php if ($canReset): ?>
    <dialog class="modal" id="modal-passwort">
        <form method="post" action="" data-reset-form>
            <?= $csrf->field() ?>
            <div class="modal-header">
                <h3>Passwort zurücksetzen</h3>
                <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
            </div>
            <div class="modal-body">
                <p class="text-sm text-soft">Konto: <strong data-reset-target>—</strong></p>
                <p>
                    Es wird ein neues Zufallspasswort erzeugt und <strong>einmalig angezeigt</strong>.
                    Das Konto muss beim nächsten Login ein eigenes Passwort setzen.
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" type="button" data-close-modal>Abbrechen</button>
                <button class="btn btn-primary" type="submit">Zurücksetzen</button>
            </div>
        </form>
    </dialog>

    <?php if ($classes !== []): ?>
    <dialog class="modal" id="modal-klasse">
        <form method="post" action="<?= e($ctx->url('/admin/benutzer/klasse-passwoerter')) ?>"
              data-confirm="Wirklich für ALLE aktiven Schüler:innen dieser Klasse neue Passwörter setzen? Die bisherigen Passwörter sind danach ungültig.">
            <?= $csrf->field() ?>
            <div class="modal-header">
                <h3>Zugangsdaten-PDF für eine Klasse</h3>
                <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    Für alle aktiven Schüler:innen der gewählten Klasse werden <strong>neue Passwörter</strong> gesetzt
                    und als Kärtchen-PDF ausgegeben. Bestehende Passwörter gelten danach nicht mehr.
                </div>
                <div class="field">
                    <label for="klasse-pdf">Klasse</label>
                    <select id="klasse-pdf" name="klasse" required>
                        <option value="">Bitte wählen</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= e($class) ?>"<?= $classFilter === (string) $class ? ' selected' : '' ?>><?= e($class) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" type="button" data-close-modal>Abbrechen</button>
                <button class="btn btn-danger" type="submit">Passwörter setzen & PDF laden</button>
            </div>
        </form>
    </dialog>
    <?php endif; ?>
<?php endif; ?>

<?php if ($generated !== null): ?>
    <dialog class="modal" id="modal-neues-passwort" data-auto-open="1">
        <div class="modal-header">
            <h3>Neues Passwort</h3>
            <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-warning">
                Dieses Passwort wird nur jetzt angezeigt. Notiere es und gib es persönlich weiter.
            </div>
            <p class="text-sm text-soft">
                <?= e($generated['name']) ?> · <span class="mono"><?= e($generated['username']) ?></span>
            </p>
            <div class="field">
                <label for="generiertes-passwort">Passwort</label>
                <input class="input mono" type="text" id="generiertes-passwort" readonly
                       value="<?= e($generated['password']) ?>">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" type="button" data-copy-target="generiertes-passwort">Kopieren</button>
            <button class="btn btn-primary" type="button" data-close-modal>Fertig</button>
        </div>
    </dialog>
<?php endif; ?>
