<?php
/**
 * Liste der zurückgegebenen Klausuren der Schüler:in.
 * Erwartet: $rows (Klausur, Kurs, Punkte, Note, Abschluss).
 */
$fmt = static fn (mixed $v): string => $v === null ? '–' : number_format((float) $v, 1, ',', '');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Meine Ergebnisse</h1>
        <p class="page-sub">Hier siehst du deine korrigierten Klausuren, sobald deine Lehrkraft sie freigegeben hat.</p>
    </div>
</div>

<section aria-labelledby="res-title">
    <h2 id="res-title">Zurückgegebene Klausuren</h2>
    <?php if ($rows === []): ?>
        <div class="empty-state card-body">
            <p class="text-soft">Noch ist keine deiner Klausuren zurückgegeben. Sobald deine Lehrkraft das Ergebnis freigibt, findest du es hier.</p>
            <a class="btn" href="<?= e($ctx->url('/meine-klausuren')) ?>">Zu meinen Klausuren</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th scope="col">Klausur</th><th scope="col">Kurs</th><th scope="col">Zurückgegeben</th><th scope="col">Punkte</th><th scope="col">Note</th><th scope="col"><span class="sr-only">Ansehen</span></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): $ready = $row['finalized_at'] !== null; ?>
                    <tr>
                        <th scope="row"><?= e($row['title']) ?></th>
                        <td><?= e((string) $row['course_name']) ?></td>
                        <td><?= $row['returned_at'] ? e(format_date($row['returned_at'])) : '–' ?></td>
                        <?php if ($ready): ?>
                            <td><?= e(format_points($row['total_points'])) ?> von <?= e(format_points($row['max_points'])) ?> (<?= e($fmt($row['percent'])) ?> %)</td>
                            <td><strong><?= e((string) ($row['grade_label'] ?? '–')) ?></strong></td>
                            <td class="text-right"><a class="btn btn-primary btn-sm" href="<?= e($ctx->url('/meine-ergebnisse/' . (int) $row['id'])) ?>">Ergebnis ansehen</a></td>
                        <?php else: ?>
                            <td colspan="2" class="text-soft">Deine Korrektur ist noch nicht abgeschlossen.</td>
                            <td></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
