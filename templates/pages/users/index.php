<?php
/**
 * Benutzerliste mit Live-Suche, Filtern und Pagination.
 * Erwartet: $users, $courseNames (user_id => list<string>), $total, $page, $pages, $search,
 * $roleFilter, $courseFilter, $statusFilter, $courseOptions, $roleLabels, $isAdmin,
 * $canCreate, $canEdit, $canImport, $canCards
 */

$listUrl = $ctx->url('/admin/benutzer');
$filtered = $search !== '' || $roleFilter !== '' || $courseFilter > 0 || $statusFilter !== '';

/** Baut eine Listen-URL mit den aktuellen Filtern. */
$pageUrl = static function (int $target) use ($listUrl, $search, $roleFilter, $courseFilter, $statusFilter): string {
    $query = array_filter([
        'q' => $search,
        'rolle' => $roleFilter,
        'kurs' => $courseFilter > 0 ? (string) $courseFilter : '',
        'status' => $statusFilter,
        'seite' => $target > 1 ? (string) $target : '',
    ], static fn (string $value): bool => $value !== '');

    return $listUrl . ($query === [] ? '' : '?' . http_build_query($query));
};

$roleBadge = static fn (string $role): string => match ($role) {
    'admin' => 'badge badge-danger',
    'teacher' => 'badge badge-info',
    default => 'badge',
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Benutzer</h1>
        <p class="page-sub"><?= e((string) $total) ?> Konten<?= $filtered ? ' (gefiltert)' : '' ?>.<?= $isAdmin ? '' : ' Du siehst die Schüler:innen deiner Kurse.' ?></p>
    </div>
    <div class="page-actions">
        <?php if ($canCards): ?>
            <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/zugangskarten')) ?>">🪪 Zugangskarten</a>
        <?php endif; ?>
        <?php if ($canImport): ?>
            <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/benutzer/import')) ?>">📥 CSV-Import</a>
        <?php endif; ?>
        <?php if ($canCreate): ?>
            <a class="btn btn-primary" href="<?= e($ctx->url('/admin/benutzer/neu')) ?>">➕ Neuer Benutzer</a>
        <?php endif; ?>
    </div>
</div>

<form class="card card-pad mb-2" method="get" action="<?= e($listUrl) ?>" data-live="users">
    <div class="form-grid">
        <div class="field">
            <label for="q">Suche</label>
            <input class="input" type="search" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Name, Benutzername oder E-Mail" autocomplete="off">
        </div>
        <?php if ($isAdmin): ?>
            <div class="field">
                <label for="rolle">Rolle</label>
                <select id="rolle" name="rolle">
                    <option value="">Alle Rollen</option>
                    <?php foreach ($roleLabels as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $roleFilter === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="field">
            <label for="kurs">Kurs</label>
            <select id="kurs" name="kurs">
                <option value="">Alle Kurse</option>
                <?php foreach ($courseOptions as $course): ?>
                    <option value="<?= e((string) (int) $course['id']) ?>"<?= $courseFilter === (int) $course['id'] ? ' selected' : '' ?>>
                        <?= e($course['name']) ?> (<?= e($course['school_year']) ?>)<?= (int) $course['is_archived'] === 1 ? ' [archiviert]' : '' ?>
                    </option>
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
                <th>Kurse</th>
                <th>Status</th>
                <th>Letzter Login</th>
                <th style="text-align:right;">Aktionen</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $row): ?>
                <?php
                $id = (int) $row['id'];
                $role = (string) $row['role'];
                $fullName = trim((string) $row['firstname'] . ' ' . (string) $row['lastname']);
                $names = $courseNames[$id] ?? [];
                ?>
                <tr>
                    <td class="mono"><?= e($row['username']) ?></td>
                    <td>
                        <?= e($fullName !== '' ? $fullName : $row['username']) ?>
                        <?php if ($auth->id() === $id): ?>
                            <span class="badge badge-info">Du</span>
                        <?php endif; ?>
                        <?php if (!empty($row['email'])): ?>
                            <div class="text-sm text-soft"><?= e($row['email']) ?></div>
                        <?php endif; ?>
                        <?php if ($role === 'student' && (int) $row['accommodation_percent'] > 0): ?>
                            <div class="text-sm text-soft">Nachteilsausgleich +<?= e((string) (int) $row['accommodation_percent']) ?> %</div>
                        <?php endif; ?>
                    </td>
                    <td><span class="<?= e($roleBadge($role)) ?>"><?= e($roleLabels[$role] ?? $role) ?></span></td>
                    <td>
                        <?php if ($names === []): ?>
                            <span class="text-faint">–</span>
                        <?php else: ?>
                            <div class="chip-row">
                                <?php foreach (array_slice($names, 0, 3) as $name): ?>
                                    <span class="badge badge-primary"><?= e($name) ?></span>
                                <?php endforeach; ?>
                                <?php if (count($names) > 3): ?>
                                    <span class="badge">+<?= e((string) (count($names) - 3)) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int) $row['is_active'] !== 1): ?>
                            <span class="badge badge-danger">inaktiv</span>
                        <?php elseif ($role === 'student' && $row['card_issued_at'] === null && $row['password'] === null): ?>
                            <span class="badge badge-warning">keine Karte</span>
                        <?php elseif ((int) $row['must_change_password'] === 1): ?>
                            <span class="badge badge-warning">Passwortwechsel</span>
                        <?php else: ?>
                            <span class="badge badge-success">aktiv</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm text-soft">
                        <?= !empty($row['last_login_at']) ? e(format_datetime($row['last_login_at'])) : 'nie' ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e($ctx->url('/admin/benutzer/' . $id)) ?>"><?= $canEdit ? 'Bearbeiten' : 'Ansehen' ?></a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="cluster mt-2 pager">
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
