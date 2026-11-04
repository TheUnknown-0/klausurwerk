<?php
/**
 * Audit-Log — Übersicht mit Filtern und Pagination.
 * Erwartet: $rows, $filters, $severities, $page, $pages, $total, $criticalCount.
 */

$listUrl = $ctx->url('/admin/audit-log');

$pageUrl = static function (int $target) use ($listUrl, $filters): string {
    $query = array_filter([
        'stufe' => $filters['severity'],
        'aktion' => $filters['aktion'],
        'benutzer' => $filters['benutzer'],
        'von' => $filters['von'],
        'bis' => $filters['bis'],
        'suche' => $filters['suche'],
        'seite' => $target > 1 ? (string) $target : '',
    ], static fn (string $v): bool => $v !== '');

    return $listUrl . ($query === [] ? '' : '?' . http_build_query($query));
};

$exportUrl = $ctx->url('/admin/audit-log/export') . '?' . http_build_query(array_filter(
    ['stufe' => $filters['severity'], 'aktion' => $filters['aktion'], 'benutzer' => $filters['benutzer'], 'von' => $filters['von'], 'bis' => $filters['bis'], 'suche' => $filters['suche']],
    static fn ($v): bool => $v !== '',
));

$severityBadge = static fn (string $severity): string => match ($severity) {
    'critical' => '<span class="badge badge-danger">kritisch</span>',
    'warning' => '<span class="badge badge-warning">Warnung</span>',
    default => '<span class="badge badge-info">Info</span>',
};
$severityLabels = ['info' => 'Info', 'warning' => 'Warnung', 'critical' => 'Kritisch'];
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">📜 Audit-Log</h1>
        <p class="page-sub"><?= e((string) $total) ?> Einträge<?= array_filter($filters) !== [] ? ' (gefiltert)' : '' ?>.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($exportUrl) ?>">⬇️ CSV-Export</a>
    </div>
</div>

<form class="card card-pad mb-2" method="get" action="<?= e($listUrl) ?>" data-live="audit">
    <div class="form-grid">
        <div class="field">
            <label for="f-aktion">Aktion / Prefix</label>
            <input class="input" type="text" id="f-aktion" name="aktion" value="<?= e($filters['aktion']) ?>"
                   placeholder="z. B. benutzer.">
        </div>
        <div class="field">
            <label for="f-stufe">Schweregrad</label>
            <select id="f-stufe" name="stufe">
                <option value="">Alle</option>
                <?php foreach ($severities as $severity): ?>
                    <option value="<?= e($severity) ?>" <?= $filters['severity'] === $severity ? 'selected' : '' ?>>
                        <?= e($severityLabels[$severity] ?? $severity) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="f-benutzer">Benutzer</label>
            <input class="input" type="text" id="f-benutzer" name="benutzer" value="<?= e($filters['benutzer']) ?>"
                   placeholder="Benutzername">
        </div>
        <div class="field">
            <label for="f-von">Von</label>
            <input class="input" type="date" id="f-von" name="von" value="<?= e($filters['von']) ?>">
        </div>
        <div class="field">
            <label for="f-bis">Bis</label>
            <input class="input" type="date" id="f-bis" name="bis" value="<?= e($filters['bis']) ?>">
        </div>
        <div class="field">
            <label for="f-suche">Volltextsuche</label>
            <input class="input" type="search" id="f-suche" name="suche" value="<?= e($filters['suche']) ?>"
                   placeholder="Aktion, Benutzer oder Details">
        </div>
    </div>
    <div class="cluster">
        <button class="btn btn-primary" type="submit">Filtern</button>
        <a class="btn btn-ghost" href="<?= e($listUrl) ?>">Zurücksetzen</a>
    </div>
</form>

<div data-live-target="audit">
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value"><?= e((string) $total) ?></div>
        <div class="stat-label">Einträge (gefiltert)</div>
    </div>
    <div class="stat-card<?= $criticalCount > 0 ? ' stat-danger' : '' ?>">
        <div class="stat-value"><?= e((string) $criticalCount) ?></div>
        <div class="stat-label">Kritisch</div>
    </div>
</div>

<?php if ($rows === []): ?>
    <div class="empty-state">
        <div class="empty-icon">📜</div>
        <p>Keine Einträge gefunden.</p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th class="nowrap">Zeitpunkt</th>
                    <th>Benutzer</th>
                    <th>Aktion</th>
                    <th>Schweregrad</th>
                    <th>IP-Adresse</th>
                    <th>Details</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="nowrap text-sm"><?= e(format_datetime($row['created_at'])) ?></td>
                        <td><?= e($row['username'] ?? 'System') ?></td>
                        <td class="mono"><?= e($row['action']) ?></td>
                        <td><?= $severityBadge($row['severity']) ?></td>
                        <td class="mono text-sm"><?= e($row['ip_address'] ?? '—') ?></td>
                        <td class="text-sm text-soft"><?= e($row['details'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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
