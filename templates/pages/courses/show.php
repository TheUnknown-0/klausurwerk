<?php
/**
 * Kursdetail: Stammdaten, Mitglieder, Klausuren.
 * Erwartet: $course, $owner, $teachers, $members, $exams, $scale, $scaleIsOverride,
 * $canEdit, $canCards, $canStudents
 */

$base = $ctx->url('/admin/kurse');
$courseId = (int) $course['id'];
$statusLabels = [
    'draft' => 'Entwurf', 'ready' => 'Bereit', 'lobby' => 'Warteraum', 'running' => 'Läuft', 'paused' => 'Pausiert',
    'finished' => 'Beendet', 'grading' => 'In Korrektur', 'returned' => 'Zurückgegeben', 'archived' => 'Archiviert',
];
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($base) ?>">← Kurse</a></div>
        <h1 class="page-title"><?= e($course['name']) ?>
            <?php if ((int) $course['is_archived'] === 1): ?><span class="badge">archiviert</span><?php endif; ?>
        </h1>
        <p class="page-sub">
            Klasse <?= e((string) (int) $course['grade_level']) ?> · <?= e($course['school_year']) ?> · <?= e($course['subject']) ?>
        </p>
    </div>
    <div class="page-actions">
        <?php if ($canCards): ?>
            <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/zugangskarten?kurs=' . $courseId)) ?>">🪪 Zugangskarten für den Kurs</a>
        <?php endif; ?>
        <?php if ($canEdit): ?>
            <a class="btn btn-primary" href="<?= e($base . '/' . $courseId . '/bearbeiten') ?>">Bearbeiten</a>
        <?php endif; ?>
    </div>
</div>

<div class="grid-3 mb-2">
    <div class="card card-pad">
        <div class="text-sm text-soft">Hauptlehrkraft</div>
        <div><strong><?= $owner !== null ? e(display_name($owner)) : '–' ?></strong></div>
        <?php if ($teachers !== []): ?>
            <div class="text-sm text-soft mt-2">Weitere Lehrkräfte</div>
            <div class="chip-row">
                <?php foreach ($teachers as $teacher): ?>
                    <span class="badge badge-info"><?= e(display_name($teacher)) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="card card-pad">
        <div class="text-sm text-soft">Notenschlüssel</div>
        <?php if ($scale !== null): ?>
            <div><strong><?= e($scale['name']) ?></strong></div>
            <div class="text-sm text-soft"><?= $scaleIsOverride ? 'Abweichung für diesen Kurs' : 'Standard der Klassenstufe' ?></div>
        <?php else: ?>
            <div class="text-soft">Für Klasse <?= e((string) (int) $course['grade_level']) ?> gibt es noch keinen Standard.</div>
        <?php endif; ?>
    </div>
    <div class="card card-pad">
        <div class="text-sm text-soft">Mitglieder</div>
        <div class="stat-value"><?= e((string) count($members)) ?></div>
    </div>
</div>

<?php if ($canEdit): ?>
    <div class="card card-pad mb-2" data-member-add data-course-id="<?= e((string) $courseId) ?>"
         data-search-url="<?= e($ctx->url('/api/benutzer/suche')) ?>"
         data-add-url="<?= e($base . '/' . $courseId . '/mitglieder') ?>">
        <h3 class="mt-0">Schüler:in hinzufügen</h3>
        <div class="field mb-0">
            <label for="member-search">Name oder Benutzername suchen</label>
            <input class="input" type="search" id="member-search" autocomplete="off" data-member-search
                   placeholder="Mindestens 2 Buchstaben" aria-controls="member-results">
        </div>
        <ul class="search-results" id="member-results" data-member-results aria-live="polite"></ul>
        <noscript><p class="text-sm text-soft">Zum Hinzufügen wird JavaScript gebraucht. Alternativ: Benutzer bearbeiten und den Kurs dort ankreuzen.</p></noscript>
    </div>
<?php endif; ?>

<div class="card mb-2">
    <div class="card-header"><h3 style="margin:0;">Mitglieder (<?= e((string) count($members)) ?>)</h3></div>
    <?php if ($members === []): ?>
        <div class="card-body"><p class="text-soft">Noch keine Schüler:innen im Kurs.</p></div>
    <?php else: ?>
        <div class="table-wrap" style="border:none; border-radius:0;">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Benutzername</th>
                    <th>Nachteilsausgleich</th>
                    <th>Karte</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($members as $member): ?>
                    <tr>
                        <td>
                            <?php if ($canStudents): ?>
                                <a href="<?= e($ctx->url('/admin/benutzer/' . (int) $member['id'])) ?>"><?= e(display_name($member)) ?></a>
                            <?php else: ?>
                                <?= e(display_name($member)) ?>
                            <?php endif; ?>
                            <?php if ((int) $member['is_active'] !== 1): ?><span class="badge badge-danger">inaktiv</span><?php endif; ?>
                        </td>
                        <td class="mono"><?= e($member['username']) ?></td>
                        <td><?= (int) $member['accommodation_percent'] > 0 ? '+' . e((string) (int) $member['accommodation_percent']) . ' %' : '<span class="text-faint">–</span>' ?></td>
                        <td class="text-sm text-soft"><?= $member['card_issued_at'] !== null ? e(format_date($member['card_issued_at'])) : 'keine' ?></td>
                        <td>
                            <?php if ($canEdit): ?>
                                <div class="row-actions">
                                    <form method="post" action="<?= e($base . '/' . $courseId . '/mitglieder/' . (int) $member['id'] . '/entfernen') ?>"
                                          data-confirm="<?= e(display_name($member)) ?> aus dem Kurs entfernen? Das Konto bleibt bestehen.">
                                        <?= $csrf->field() ?>
                                        <button class="btn btn-sm btn-danger-ghost" type="submit">Entfernen</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card mb-2">
    <div class="card-header"><h3 style="margin:0;">Klausuren (<?= e((string) count($exams)) ?>)</h3></div>
    <?php if ($exams === []): ?>
        <div class="card-body"><p class="text-soft">Für diesen Kurs gibt es noch keine Klausur.</p></div>
    <?php else: ?>
        <div class="table-wrap" style="border:none; border-radius:0;">
            <table class="data-table">
                <tbody>
                <?php foreach ($exams as $exam): ?>
                    <tr>
                        <td><a href="<?= e($ctx->url('/klausuren/' . (int) $exam['id'])) ?>"><strong><?= e($exam['title']) ?></strong></a></td>
                        <td><span class="badge"><?= e($statusLabels[$exam['status']] ?? $exam['status']) ?></span></td>
                        <td class="text-sm text-soft"><?= $exam['scheduled_at'] !== null ? e(format_datetime($exam['scheduled_at'])) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($canEdit): ?>
    <div class="card card-pad max-w-lg">
        <h3 class="mt-0">Kurs verwalten</h3>
        <div class="cluster">
            <form method="post" action="<?= e($base . '/' . $courseId . '/archivieren') ?>">
                <?= $csrf->field() ?>
                <button class="btn btn-ghost" type="submit"><?= (int) $course['is_archived'] === 1 ? 'Wieder aktivieren' : 'Archivieren' ?></button>
            </form>
            <form method="post" action="<?= e($base . '/' . $courseId . '/loeschen') ?>"
                  data-confirm="Kurs „<?= e($course['name']) ?>“ löschen? Die Schüler:innen-Konten bleiben bestehen.">
                <?= $csrf->field() ?>
                <button class="btn btn-danger-ghost" type="submit"<?= $exams !== [] ? ' disabled title="Der Kurs hat Klausuren: bitte archivieren."' : '' ?>>Kurs löschen</button>
            </form>
        </div>
    </div>
<?php endif; ?>
