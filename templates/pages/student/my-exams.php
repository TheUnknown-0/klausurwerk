<?php
/**
 * Klausurliste der Schüler:innen.
 * Erwartet: $open, $practice, $finished (Zeilen aus StudentExamController::index).
 */
$statusLabels = [
    'lobby' => ['Warteraum offen', 'badge-info'],
    'running' => ['Läuft', 'badge-success'],
    'paused' => ['Pausiert', 'badge-warning'],
    'finished' => ['Beendet', 'badge'],
    'grading' => ['In Korrektur', 'badge'],
    'returned' => ['Zurückgegeben', 'badge-primary'],
];
$modeLabels = ['exam' => 'Klausur', 'practice' => 'Übung', 'probe' => 'Technik-Check'];
$badge = static function (string $status) use ($statusLabels): string {
    [$label, $class] = $statusLabels[$status] ?? [$status, 'badge'];

    return '<span class="badge ' . e($class) . '">' . e($label) . '</span>';
};
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Meine Klausuren</h1>
        <p class="page-sub">Hier findest du geöffnete Klausuren, Übungen und deine beendeten Klausuren.</p>
    </div>
</div>

<div class="stack">
<section aria-labelledby="open-title">
    <h2 id="open-title">Offene Klausuren</h2>
    <?php if ($open === []): ?>
        <div class="empty-state card-body">
            <p class="text-soft">Gerade ist keine Klausur geöffnet. Sobald deine Lehrkraft den Warteraum öffnet, erscheint sie hier.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Klausur</th><th>Kurs</th><th>Art</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($open as $row): ?>
                    <tr>
                        <td><strong><?= e($row['title']) ?></strong></td>
                        <td><?= e($row['course_name']) ?></td>
                        <td><?= e($modeLabels[$row['mode']] ?? $row['mode']) ?></td>
                        <td><?= $badge((string) $row['status']) ?></td>
                        <td class="text-right">
                            <a class="btn btn-primary btn-sm" href="<?= e($ctx->url('/klausur/' . (int) $row['id'])) ?>">Zur Klausur</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($practice !== []): ?>
    <section class="mt-2" aria-labelledby="practice-title">
        <h2 id="practice-title">Übungen</h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Übung</th><th>Kurs</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($practice as $row): ?>
                    <tr>
                        <td><strong><?= e($row['title']) ?></strong></td>
                        <td><?= e($row['course_name']) ?></td>
                        <td><?= $badge((string) $row['status']) ?></td>
                        <td class="text-right">
                            <a class="btn btn-primary btn-sm" href="<?= e($ctx->url('/klausur/' . (int) $row['id'])) ?>">Zur Übung</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($finished !== []): ?>
    <section class="mt-2" aria-labelledby="done-title">
        <h2 id="done-title">Beendete Klausuren</h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Klausur</th><th>Kurs</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($finished as $row): ?>
                    <tr>
                        <td><strong><?= e($row['title']) ?></strong></td>
                        <td><?= e($row['course_name']) ?></td>
                        <td><?= $badge((string) $row['status']) ?></td>
                        <td class="text-right">
                            <?php if ($row['status'] === 'returned'): ?>
                                <a class="btn btn-sm" href="<?= e($ctx->url('/meine-ergebnisse/' . (int) $row['id'])) ?>">Ergebnis ansehen</a>
                            <?php else: ?>
                                <span class="text-faint text-sm">Noch nicht zurückgegeben</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
</div>
