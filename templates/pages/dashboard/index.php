<?php
/**
 * Übersicht für Lehrkräfte und Admins.
 * Erwartet: $user, $live, $planned, $grading, $myCourses, $canCreateExam, $canSeeCourses, $hints
 */

$statusLabels = [
    'draft' => 'Entwurf', 'ready' => 'Bereit', 'lobby' => 'Warteraum offen', 'running' => 'Läuft', 'paused' => 'Pausiert',
    'finished' => 'Beendet', 'grading' => 'In Korrektur',
];
$statusBadge = [
    'lobby' => 'badge badge-info', 'running' => 'badge badge-success', 'paused' => 'badge badge-warning',
    'draft' => 'badge', 'ready' => 'badge badge-primary', 'finished' => 'badge badge-accent', 'grading' => 'badge badge-accent',
];

/** Eine Klausurgruppe als Karte. */
$renderGroup = static function (string $heading, string $emptyText, array $exams, bool $showPult) use ($ctx, $statusLabels, $statusBadge): void {
    ?>
    <section class="card dash-group">
        <div class="card-header"><h3 style="margin:0;"><?= e($heading) ?> <span class="text-faint">(<?= e((string) count($exams)) ?>)</span></h3></div>
        <?php if ($exams === []): ?>
            <div class="card-body"><p class="text-soft text-sm mb-0"><?= e($emptyText) ?></p></div>
        <?php else: ?>
            <ul class="dash-list">
                <?php foreach ($exams as $exam): ?>
                    <li>
                        <div class="dash-main">
                            <a href="<?= e($ctx->url('/klausuren/' . (int) $exam['id'])) ?>"><strong><?= e($exam['title']) ?></strong></a>
                            <div class="text-sm text-soft">
                                <?= e($exam['course_name'] ?? 'ohne Kurs') ?>
                                <?php if ($exam['scheduled_at'] !== null): ?> · <?= e(format_datetime($exam['scheduled_at'])) ?><?php endif; ?>
                                <?php if ((int) $exam['participants'] > 0): ?> · <?= e((string) (int) $exam['submitted']) ?>/<?= e((string) (int) $exam['participants']) ?> abgegeben<?php endif; ?>
                                <?php if (!$exam['is_owner']): ?> · Aufsicht<?php endif; ?>
                            </div>
                        </div>
                        <span class="<?= e($statusBadge[$exam['status']] ?? 'badge') ?>"><?= e($statusLabels[$exam['status']] ?? $exam['status']) ?></span>
                        <?php if ($showPult): ?>
                            <a class="btn btn-sm btn-primary" href="<?= e($ctx->url('/klausuren/' . (int) $exam['id'] . '/pult')) ?>">Pult</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Übersicht</div>
        <h1 class="page-title">Hallo <?= e($user['firstname'] !== '' ? $user['firstname'] : $user['username']) ?></h1>
        <p class="page-sub">Deine Klausuren und Kurse auf einen Blick.</p>
    </div>
    <div class="page-actions">
        <?php if ($canCreateExam): ?>
            <a class="btn btn-primary" href="<?= e($ctx->url('/klausuren/neu')) ?>">➕ Neue Klausur</a>
        <?php endif; ?>
    </div>
</div>

<?php foreach ($hints as $hint): ?>
    <div class="alert alert-<?= e($hint['level']) ?>" role="note">
        <div>
            <?= e($hint['text']) ?>
            <?php if ($hint['url'] !== null): ?>
                <a href="<?= e($hint['url']) ?>"><?= e($hint['link']) ?></a>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<div class="dash-grid">
    <div class="stack">
        <?php $renderGroup('Laufend und Warteraum', 'Gerade läuft keine Klausur.', $live, true); ?>
        <?php $renderGroup('Geplant und Entwürfe', 'Keine geplante Klausur.', $planned, false); ?>
        <?php $renderGroup('In Korrektur', 'Nichts zu korrigieren.', $grading, false); ?>
    </div>

    <aside class="stack">
        <section class="card">
            <div class="card-header"><h3 style="margin:0;">Meine Kurse</h3></div>
            <?php if ($myCourses === []): ?>
                <div class="card-body">
                    <p class="text-soft text-sm">Du bist noch keinem Kurs zugeordnet.</p>
                    <?php if ($canSeeCourses): ?>
                        <a class="btn btn-sm btn-ghost" href="<?= e($ctx->url('/admin/kurse')) ?>">Zu den Kursen</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <ul class="dash-list">
                    <?php foreach ($myCourses as $course): ?>
                        <li>
                            <div class="dash-main">
                                <?php if ($canSeeCourses): ?>
                                    <a href="<?= e($ctx->url('/admin/kurse/' . (int) $course['id'])) ?>"><strong><?= e($course['name']) ?></strong></a>
                                <?php else: ?>
                                    <strong><?= e($course['name']) ?></strong>
                                <?php endif; ?>
                                <div class="text-sm text-soft">Klasse <?= e((string) (int) $course['grade_level']) ?> · <?= e($course['school_year']) ?></div>
                            </div>
                            <span class="badge"><?= e((string) (int) $course['member_count']) ?> Personen</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </aside>
</div>
