<?php
/**
 * Kursliste.
 * Erwartet: $courses, $showArchived, $canCreate, $sees (sieht alle Kurse)
 */

$base = $ctx->url('/admin/kurse');
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Unterricht</div>
        <h1 class="page-title">Kurse</h1>
        <p class="page-sub"><?= $sees ? 'Alle Kurse der Schule.' : 'Deine Kurse (als Hauptlehrkraft oder weitere Lehrkraft).' ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($showArchived ? $base : $base . '?archiv=1') ?>">
            <?= $showArchived ? 'Archivierte ausblenden' : 'Archivierte anzeigen' ?>
        </a>
        <?php if ($canCreate): ?>
            <a class="btn btn-primary" href="<?= e($base . '/neu') ?>"><?= icon('plus') ?> Neuer Kurs</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($courses === []): ?>
    <div class="empty-state">
        <div class="empty-icon"><?= icon('graduation-cap') ?></div>
        <p>Es gibt noch keine Kurse<?= $showArchived ? '' : ' (archivierte sind ausgeblendet)' ?>.</p>
        <?php if ($canCreate): ?>
            <a class="btn btn-primary" href="<?= e($base . '/neu') ?>">Ersten Kurs anlegen</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Kurs</th>
                <th>Klassenstufe</th>
                <th>Schuljahr</th>
                <th>Fach</th>
                <th>Hauptlehrkraft</th>
                <th>Mitglieder</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($courses as $course): ?>
                <tr>
                    <td>
                        <a href="<?= e($base . '/' . (int) $course['id']) ?>"><strong><?= e($course['name']) ?></strong></a>
                        <?php if ((int) $course['is_archived'] === 1): ?>
                            <span class="badge">archiviert</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) (int) $course['grade_level']) ?></td>
                    <td><?= e($course['school_year']) ?></td>
                    <td><?= e($course['subject']) ?></td>
                    <td><?= $course['owner_id'] !== null ? e(display_name(['firstname' => $course['owner_firstname'], 'lastname' => $course['owner_lastname']])) : '<span class="text-faint">–</span>' ?></td>
                    <td><?= e((string) (int) $course['member_count']) ?></td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e($base . '/' . (int) $course['id']) ?>">Öffnen</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
