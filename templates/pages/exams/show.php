<?php
/**
 * Klausur-Übersicht.
 * Erwartet: $exam, $caps, $role, $course, $owner, $room, $totals, $outline, $staff, $report, $materialCount.
 */

use App\Core\Permissions as P;
use App\Services\ExamAccess;
use App\Services\ExamService;

$id = (int) $exam['id'];
$u = static fn (string $suffix = ''): string => $ctx->url('/klausuren/' . $id . $suffix);
$status = (string) $exam['status'];
$canEdit = $caps[ExamAccess::CAP_EDIT];
$canManage = $caps[ExamAccess::CAP_MANAGE];
$editableNow = ExamService::isEditable($exam);
$gradingVisible = in_array($status, ['finished', 'grading', 'returned', 'archived'], true) && $caps[ExamAccess::CAP_GRADE];
$deletable = in_array($status, ExamService::DELETABLE_STATUSES, true);
$csrfField = $csrf->field();
$post = static fn (string $action, string $label, string $class = 'btn', string $confirm = '', array $fields = []) =>
    '<form method="post" action="' . e($action) . '"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
    . $csrfField
    . implode('', array_map(static fn ($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">', array_keys($fields), $fields))
    . '<button class="' . e($class) . '" type="submit">' . e($label) . '</button></form>';
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($ctx->url('/klausuren')) ?>">← Klausuren</a></div>
        <h1 class="page-title"><?= e($exam['title']) ?></h1>
        <p class="page-sub cluster">
            <span class="badge badge-primary"><?= e(ExamService::STATUS_LABELS[$status] ?? $status) ?></span>
            <span class="badge"><?= e(ExamService::MODES[$exam['mode']] ?? $exam['mode']) ?></span>
            <?php if ($role === 'owner'): ?><span class="badge badge-accent">Hauptlehrkraft</span><?php elseif ($role === 'staff'): ?><span class="badge">Aufsicht</span><?php endif; ?>
        </p>
    </div>
    <div class="page-actions">
        <?php if ($caps[ExamAccess::CAP_MONITOR]): ?><a class="btn btn-primary" href="<?= e($u('/pult')) ?>">🖥️ Pult öffnen</a><?php endif; ?>
        <?php if ($caps[ExamAccess::CAP_VIEW]): ?><a class="btn" href="<?= e($u('/vorschau')) ?>">👁️ Vorschau</a><?php endif; ?>
        <?php if ($gradingVisible): ?><a class="btn" href="<?= e($u('/korrektur')) ?>">✅ Korrektur</a><?php endif; ?>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-label">Aufgaben</div><div class="stat-value"><?= e((string) $totals['tasks']) ?></div></div>
    <div class="stat-card stat-accent"><div class="stat-label">Gesamtpunkte</div><div class="stat-value"><?= e(format_points($totals['points'])) ?></div></div>
    <div class="stat-card"><div class="stat-label">Dauer</div><div class="stat-value"><?= $exam['duration_minutes'] !== null ? e((string) $exam['duration_minutes']) . ' min' : 'offen' ?></div></div>
    <div class="stat-card"><div class="stat-label">Material</div><div class="stat-value"><?= e((string) $materialCount) ?></div></div>
</div>

<div class="grid-2 exam-grid">
    <section class="card card-pad">
        <h2 class="card-title">Eckdaten</h2>
        <dl class="exam-facts">
            <dt>Kurs</dt><dd><?= $course ? e($course['name']) : '<span class="text-faint">kein Kurs</span>' ?></dd>
            <dt>Hauptlehrkraft</dt><dd><?= $owner ? e(display_name($owner)) : '<span class="text-faint">unbekannt</span>' ?></dd>
            <dt>Raum</dt><dd><?= $room ? e((string) $room) : '<span class="text-faint">kein Raum</span>' ?></dd>
            <dt>Termin</dt><dd><?= $exam['scheduled_at'] ? e(format_datetime($exam['scheduled_at'])) : '<span class="text-faint">offen</span>' ?></dd>
            <?php if (!empty($exam['shared_in_archive'])): ?><dt>Archiv</dt><dd>für Kolleg:innen freigegeben</dd><?php endif; ?>
            <?php if (!empty($exam['description'])): ?><dt>Beschreibung</dt><dd><?= nl2br(e($exam['description'])) ?></dd><?php endif; ?>
        </dl>
    </section>

    <section class="card card-pad">
        <h2 class="card-title">Aktionen</h2>
        <div class="stack">
            <div class="cluster">
                <?php if ($canEdit): ?>
                    <a class="btn btn-primary" href="<?= e($u('/aufgaben')) ?>">✏️ Aufgaben bearbeiten</a>
                    <a class="btn" href="<?= e($u('/einstellungen')) ?>">⚙️ Einstellungen</a>
                    <a class="btn" href="<?= e($u('/material')) ?>">🖼️ Material</a>
                    <a class="btn" href="<?= e($u('/seb')) ?>">🔒 SEB-Datei</a>
                <?php endif; ?>
                <?php if ($caps[ExamAccess::CAP_SOLUTIONS] && $auth->can(P::KLAUSUREN_IMPORTIEREN)): ?>
                    <a class="btn" href="<?= e($u('/export')) ?>">⬇️ Export (ZIP)</a>
                <?php endif; ?>
            </div>
            <div class="cluster">
                <?php if ($caps[ExamAccess::CAP_SOLUTIONS] && $auth->can(P::KLAUSUREN_ERSTELLEN)): ?>
                    <?= $post($u('/kopieren'), '📋 Als Vorlage kopieren', 'btn') ?>
                <?php endif; ?>
                <?php if ($canEdit && $status === 'draft'): ?>
                    <?= $post($u('/status'), '✔️ Fertigstellen', 'btn btn-accent', '', ['status' => 'ready']) ?>
                <?php elseif ($canEdit && $status === 'ready'): ?>
                    <?= $post($u('/status'), '↩️ Zurück in den Entwurf', 'btn', '', ['status' => 'draft']) ?>
                <?php endif; ?>
            </div>
            <?php if (!$editableNow && $canEdit): ?>
                <p class="text-sm text-soft">Die Klausur ist gestartet oder abgeschlossen: Aufgabentexte und Einstellungen sind gesperrt. Musterlösung, Erwartungshorizont und Tests bleiben bearbeitbar.</p>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php if ($report !== null && ($report['errors'] !== [] || $report['warnings'] !== [])): ?>
    <section class="card card-pad mt-2" aria-labelledby="check-title">
        <h2 class="card-title" id="check-title">Vor dem Fertigstellen</h2>
        <?php if ($report['errors'] !== []): ?>
            <div class="alert alert-error"><strong>Das muss noch behoben werden:</strong>
                <ul><?php foreach ($report['errors'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
        <?php if ($report['warnings'] !== []): ?>
            <div class="alert alert-warning"><strong>Hinweise (kein Hindernis):</strong>
                <ul><?php foreach ($report['warnings'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="card mt-2">
    <div class="card-header"><h2 class="card-title">Aufgaben</h2></div>
    <?php if ($outline === []): ?>
        <div class="empty-state"><p>Noch keine Aufgaben.</p>
            <?php if ($canEdit): ?><a class="btn btn-primary" href="<?= e($u('/aufgaben')) ?>">Aufgaben anlegen</a><?php endif; ?>
        </div>
    <?php else: ?>
        <ul class="outline-list">
            <?php foreach ($outline as $t): ?>
                <li class="outline-item depth-<?= e((string) $t['depth']) ?>">
                    <span class="outline-num"><?= e($t['number']) ?></span>
                    <span class="outline-title"><?= e($t['title'] !== '' ? $t['title'] : '(ohne Titel)') ?></span>
                    <?php if ($t['answer_type'] !== null): ?><span class="badge"><?= e($t['answer_type']) ?></span><?php endif; ?>
                    <span class="outline-points"><?= e(format_points($t['points'])) ?> P.</span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="card card-pad mt-2" id="lehrkraefte">
    <h2 class="card-title">Lehrkräfte</h2>
    <p class="text-sm text-soft">Hauptlehrkraft: <?= $owner ? e(display_name($owner)) : 'unbekannt' ?>. Weitere Lehrkräfte betreuen als Aufsicht mit; Lösungen und Korrektur nur mit den Schaltern.</p>
    <?php if ($staff === []): ?>
        <p class="text-faint">Keine weiteren Lehrkräfte zugeordnet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Name</th><th>Lösungen sehen</th><th>Korrigieren</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($staff as $s): ?>
                    <tr>
                        <td><?= e(display_name($s)) ?></td>
                        <?php if ($canManage): ?>
                            <td colspan="2">
                                <form class="cluster" method="post" action="<?= e($u('/lehrkraefte/' . (int) $s['user_id'])) ?>">
                                    <?= $csrf->field() ?>
                                    <label class="checkbox-row"><input type="checkbox" name="can_view_solutions" value="1" <?= (int) $s['can_view_solutions'] === 1 ? 'checked' : '' ?>> Lösungen</label>
                                    <label class="checkbox-row"><input type="checkbox" name="can_grade" value="1" <?= (int) $s['can_grade'] === 1 ? 'checked' : '' ?>> Korrigieren</label>
                                    <button class="btn btn-sm" type="submit">Speichern</button>
                                </form>
                            </td>
                            <td class="text-right">
                                <form method="post" action="<?= e($u('/lehrkraefte/' . (int) $s['user_id'] . '/entfernen')) ?>" data-confirm="Diese Lehrkraft von der Klausur entfernen?">
                                    <?= $csrf->field() ?>
                                    <button class="btn btn-sm btn-danger-ghost" type="submit">Entfernen</button>
                                </form>
                            </td>
                        <?php else: ?>
                            <td><?= (int) $s['can_view_solutions'] === 1 ? 'ja' : 'nein' ?></td>
                            <td><?= (int) $s['can_grade'] === 1 ? 'ja' : 'nein' ?></td>
                            <td></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <form class="staff-add" method="post" action="<?= e($u('/lehrkraefte')) ?>"
              data-staff-search data-search-url="<?= e($ctx->url('/api/benutzer/suche')) ?>" data-fallback-url="<?= e($ctx->url('/api/klausuren/' . $id . '/lehrkraft-suche')) ?>">
            <?= $csrf->field() ?>
            <input type="hidden" name="user_id" value="" data-staff-user>
            <div class="field">
                <label for="staff-q">Lehrkraft hinzufügen</label>
                <input class="input" type="search" id="staff-q" autocomplete="off" placeholder="Name oder Benutzername eingeben" data-staff-input
                       role="combobox" aria-expanded="false" aria-controls="staff-results">
                <ul class="staff-results" id="staff-results" role="listbox" hidden data-staff-results></ul>
                <div class="hint" data-staff-chosen>Tippe mindestens zwei Buchstaben und wähle eine Person aus.</div>
            </div>
            <button class="btn btn-primary" type="submit" data-staff-submit disabled>Hinzufügen</button>
        </form>
    <?php endif; ?>
</section>

<?php if ($canManage): ?>
    <section class="card card-pad mt-2">
        <h2 class="card-title">Löschen</h2>
        <?php if ($deletable): ?>
            <p class="text-sm text-soft">Löscht die Klausur mit allen Aufgaben, Lösungen und Materialien endgültig.</p>
            <?= $post($u('/loeschen'), '🗑️ Klausur löschen', 'btn btn-danger', 'Diese Klausur mit allen Aufgaben und Materialien wirklich endgültig löschen?') ?>
        <?php else: ?>
            <div class="alert alert-info">Eine gestartete oder abgeschlossene Klausur kann nicht gelöscht werden. Gelöscht werden können nur Entwürfe, bereite und archivierte Klausuren.</div>
        <?php endif; ?>
    </section>
<?php endif; ?>
