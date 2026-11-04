<?php
/**
 * Klausurliste. Erwartet: $exams, $filters, $courses, $canCreate, $canImport.
 */

use App\Services\ExamService;

$listUrl = $ctx->url('/klausuren');
$statusBadge = static fn (string $s): string => match ($s) {
    'draft' => 'badge-info',
    'ready' => 'badge-primary',
    'lobby', 'running' => 'badge-success',
    'paused', 'grading' => 'badge-warning',
    default => '',
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Unterricht</div>
        <h1 class="page-title">📝 Klausuren</h1>
        <p class="page-sub"><?= e((string) count($exams)) ?> Klausur<?= count($exams) === 1 ? '' : 'en' ?><?= array_filter($filters) !== [] ? ' (gefiltert)' : '' ?>.</p>
    </div>
    <div class="page-actions">
        <?php if ($canImport): ?><a class="btn btn-ghost" href="<?= e($ctx->url('/klausuren/import')) ?>">⬆️ Importieren</a><?php endif; ?>
        <?php if ($canCreate): ?><a class="btn btn-primary" href="<?= e($ctx->url('/klausuren/neu')) ?>">+ Neue Klausur</a><?php endif; ?>
    </div>
</div>

<form class="card card-pad mb-2" method="get" action="<?= e($listUrl) ?>" data-live="klausuren">
    <div class="form-grid">
        <div class="field">
            <label for="f-kurs">Kurs</label>
            <select id="f-kurs" name="kurs">
                <option value="">Alle Kurse</option>
                <?php foreach ($courses as $c): ?>
                    <option value="<?= e($c['id']) ?>" <?= (int) $filters['course'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="f-status">Status</label>
            <select id="f-status" name="status">
                <option value="">Alle</option>
                <?php foreach (ExamService::STATUS_LABELS as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="f-modus">Modus</label>
            <select id="f-modus" name="modus">
                <option value="">Alle</option>
                <?php foreach (ExamService::MODES as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $filters['mode'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <noscript><button class="btn btn-primary" type="submit">Filtern</button></noscript>
</form>

<div data-live-target="klausuren">
<?php if ($exams === []): ?>
    <div class="card empty-state">
        <div class="empty-icon" aria-hidden="true">📝</div>
        <p><?= array_filter($filters) !== [] ? 'Keine Klausur passt zu diesen Filtern.' : 'Du hast noch keine Klausur.' ?></p>
        <?php if ($canCreate && array_filter($filters) === []): ?><a class="btn btn-primary" href="<?= e($ctx->url('/klausuren/neu')) ?>">Erste Klausur anlegen</a><?php endif; ?>
    </div>
<?php else: ?>
    <div class="card table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>Titel</th><th>Kurs</th><th>Modus</th><th>Status</th><th>Termin</th><th class="text-right">Aufgaben</th><th class="text-right">Punkte</th></tr>
            </thead>
            <tbody>
            <?php foreach ($exams as $x): ?>
                <tr>
                    <td>
                        <a href="<?= e($ctx->url('/klausuren/' . $x['id'])) ?>"><strong><?= e($x['title']) ?></strong></a>
                        <div class="cluster text-sm text-soft">
                            <?php if ($x['role'] === 'owner' && empty($x['is_owner'])): ?>
                                <span class="badge badge-accent">Alle Klausuren</span>
                            <?php elseif ($x['role'] === 'owner'): ?>
                                <span class="badge badge-primary">Hauptlehrkraft</span>
                            <?php elseif ($x['role'] === 'staff'): ?>
                                <span class="badge">Aufsicht</span>
                            <?php endif; ?>
                            <?php if (empty($x['is_owner']) && ($x['owner_lastname'] ?? '') !== ''): ?>
                                <span><?= e(trim((string) $x['owner_firstname'] . ' ' . (string) $x['owner_lastname'])) ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><?= e($x['course_name'] ?? 'ohne Kurs') ?></td>
                    <td><?= e(ExamService::MODES[$x['mode']] ?? $x['mode']) ?></td>
                    <td><span class="badge <?= e($statusBadge((string) $x['status'])) ?>"><?= e(ExamService::STATUS_LABELS[$x['status']] ?? $x['status']) ?></span></td>
                    <td class="nowrap"><?= $x['scheduled_at'] ? e(format_datetime($x['scheduled_at'])) : '<span class="text-faint">offen</span>' ?></td>
                    <td class="text-right"><?= e((string) $x['task_count']) ?></td>
                    <td class="text-right"><?= e(format_points($x['total_points'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</div>
