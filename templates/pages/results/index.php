<?php
/**
 * Ergebnisse einer Klausur: Noten, Kommentare, Abschluss, Klausurspiegel, Freigabe.
 * Erwartet: $exam, $caps, $statusLabel, $rows, $stats, $scale, $scales, $finalized, $boot.
 */

use App\Services\ExamAccess;

$examId = (int) $exam['id'];
$status = (string) $exam['status'];
$grading = $status === 'grading';
$canManage = $caps[ExamAccess::CAP_MANAGE];
$u = static fn (string $suffix = ''): string => $ctx->url('/klausuren/' . $examId . $suffix);
$fmt = static fn (?float $v, int $d = 1): string => $v === null ? '–' : number_format($v, $d, ',', '');
$unitPoints = ($stats['scale_type'] ?? null) === 'points';
$gradeNoun = $unitPoints ? 'Notenpunkte' : 'Note';
$dist = $stats['distribution'];
$maxCount = $dist === [] ? 1 : max(1, max($dist));
$total = count($rows);
$open = $total - $finalized;
$post = static fn (string $action, string $label, string $class = 'btn', string $confirm = '', string $iconName = '') =>
    '<form method="post" action="' . e($action) . '"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
    . $csrf->field() . '<button class="' . e($class) . '" type="submit">' . ($iconName !== '' ? icon($iconName) . ' ' : '') . e($label) . '</button></form>';
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($u()) ?>">← <?= e($exam['title']) ?></a></div>
        <h1 class="page-title">Ergebnisse</h1>
        <p class="page-sub cluster">
            <span class="badge badge-primary"><?= e($statusLabel) ?></span>
            <?php if ($scale): ?><span class="badge">Schlüssel: <?= e($scale['name']) ?></span><?php else: ?><span class="badge badge-warning">kein Notenschlüssel</span><?php endif; ?>
            <span class="text-soft text-sm"><?= e((string) $finalized) ?> von <?= e((string) $total) ?> abgeschlossen</span>
        </p>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e($u('/korrektur')) ?>"><?= icon('circle-check') ?> Korrektur</a>
        <a class="btn" href="<?= e($u('/ergebnisse/pdf')) ?>"><?= icon('printer') ?> Sammel-PDF</a>
        <a class="btn" href="<?= e($u('/ergebnisse/export?format=xlsx')) ?>"><?= icon('download') ?> Excel</a>
        <a class="btn" href="<?= e($u('/ergebnisse/export?format=csv')) ?>"><?= icon('download') ?> CSV</a>
    </div>
</div>

<?php if (!$scale): ?>
    <div class="alert alert-warning">Für diese Klausur gibt es keinen Notenschlüssel. Wähle unten einen aus, damit Noten berechnet werden. Punkte und Prozent stehen trotzdem fest.</div>
<?php endif; ?>

<div class="grid-2 exam-grid">
    <section class="card card-pad" aria-labelledby="release-title">
        <h2 class="card-title" id="release-title">Rückgabe an Schüler:innen</h2>
        <?php if ($grading): ?>
            <p class="text-sm text-soft">
                Schüler:innen sehen ihr Ergebnis erst, wenn du die Klausur freigibst <strong>und</strong> ihre Korrektur abgeschlossen ist.
                Aktuell: <?= e((string) $finalized) ?> von <?= e((string) $total) ?> abgeschlossen.
            </p>
            <div class="cluster">
                <?php if ($caps[ExamAccess::CAP_GRADE]): ?>
                    <?= $post($u('/ergebnisse/alle-abschliessen'), 'Alle vollständig bewerteten abschließen', 'btn', '', 'check') ?>
                <?php endif; ?>
                <?php if ($canManage): ?>
                    <?= $post($u('/freigeben'), 'Ergebnisse freigeben', 'btn btn-primary', $open > 0
                        ? $open . ' Personen sind noch nicht abgeschlossen und sehen nichts, bis du sie abschließt. Trotzdem freigeben?'
                        : 'Ergebnisse jetzt für alle sichtbar freigeben?', 'upload') ?>
                <?php else: ?>
                    <span class="text-sm text-soft">Freigeben darf die Hauptlehrkraft.</span>
                <?php endif; ?>
            </div>
        <?php elseif ($status === 'returned'): ?>
            <div class="alert alert-success">Die Ergebnisse sind freigegeben<?= $exam['returned_at'] ? ' (' . e(format_datetime($exam['returned_at'])) . ')' : '' ?>. Schüler:innen sehen ihr Ergebnis, sobald ihre Korrektur abgeschlossen ist.</div>
            <?php if ($canManage): ?>
                <div class="cluster">
                    <?= $post($u('/zuruecknehmen'), 'Freigabe zurücknehmen', 'btn', 'Die Freigabe zurücknehmen? Schüler:innen sehen ihr Ergebnis dann nicht mehr, und du kannst wieder bewerten.', 'rotate-ccw') ?>
                    <?= $post($u('/archivieren'), 'Archivieren', 'btn', 'Die Klausur archivieren? Schüler:innen sehen ihr Ergebnis danach nicht mehr.', 'archive') ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-soft">Diese Klausur ist archiviert.</p>
        <?php endif; ?>
    </section>

    <?php if ($canManage): ?>
    <section class="card card-pad" aria-labelledby="settings-title">
        <h2 class="card-title" id="settings-title">Einstellungen</h2>
        <form method="post" action="<?= e($u('/ergebnisse/einstellungen')) ?>" class="stack">
            <?= $csrf->field() ?>
            <div class="field">
                <label for="r-scale">Notenschlüssel</label>
                <select class="input" id="r-scale" name="grading_scale_id" <?= $grading ? '' : 'disabled' ?>>
                    <option value="">Standard des Kurses<?= $exam['course_id'] === null ? ' (kein Kurs: bitte wählen)' : '' ?></option>
                    <?php foreach ($scales as $s): ?>
                        <option value="<?= e((string) $s['id']) ?>" <?= (int) ($exam['grading_scale_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
                            <?= e($s['name']) ?> (<?= $s['type'] === 'points' ? 'Punkte' : 'Noten' ?><?= $s['grade_level'] !== null ? ', Klasse ' . e((string) $s['grade_level']) : '' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$grading): ?><div class="hint">Der Schlüssel lässt sich nur in der Phase „In Korrektur“ ändern.</div><?php endif; ?>
            </div>
            <label class="checkbox-row">
                <input type="checkbox" name="show_expectation" value="1" <?= (int) ($exam['show_expectation_on_return'] ?? 0) === 1 ? 'checked' : '' ?>>
                Erwartungshorizont bei der Rückgabe zeigen
            </label>
            <div class="hint">Nur der Erwartungshorizont wird sichtbar. Musterlösung und Tests bleiben immer verborgen.</div>
            <div><button class="btn btn-primary" type="submit">Speichern</button></div>
        </form>
    </section>
    <?php endif; ?>
</div>

<div id="results-app" data-boot="<?= json_attr($boot) ?>">

<section class="card card-pad mt-2" aria-labelledby="spiegel-title">
    <h2 class="card-title" id="spiegel-title">Klausurspiegel</h2>
    <?php if ($stats['n'] === 0): ?>
        <p class="text-soft">Noch keine abgegebenen oder bewerteten Arbeiten.</p>
    <?php else: ?>
        <div class="stat-grid">
            <div class="stat-card"><div class="stat-label">Arbeiten</div><div class="stat-value"><?= e((string) $stats['n']) ?></div></div>
            <div class="stat-card stat-accent"><div class="stat-label">Durchschnitt</div><div class="stat-value"><?= e($fmt($stats['mean_percent'])) ?> %</div></div>
            <div class="stat-card"><div class="stat-label">Ø <?= e($gradeNoun) ?></div><div class="stat-value"><?= e($fmt($stats['mean_grade'], $unitPoints ? 1 : 2)) ?></div></div>
            <div class="stat-card"><div class="stat-label">Median <?= e($gradeNoun) ?></div><div class="stat-value"><?= e($fmt($stats['median_grade'], $unitPoints ? 1 : 2)) ?></div></div>
            <div class="stat-card"><div class="stat-label">Bestes / schwächstes</div><div class="stat-value g-small"><?= e($fmt($stats['best_percent'])) ?> % / <?= e($fmt($stats['worst_percent'])) ?> %</div></div>
        </div>

        <?php if ($dist !== []):
            $count = count($dist);
            $slot = 600 / $count;
            $barW = min(46, $slot * 0.7);
            $chartH = 170;
            ?>
            <h3 class="g-h3">Verteilung der <?= $unitPoints ? 'Notenpunkte' : 'Noten' ?></h3>
            <svg class="g-chart" viewBox="0 0 640 230" role="img" aria-labelledby="dist-title dist-desc">
                <title id="dist-title">Verteilung der <?= $unitPoints ? 'Notenpunkte' : 'Noten' ?></title>
                <desc id="dist-desc"><?php
                    $parts = [];
                    foreach ($dist as $label => $n) {
                        $parts[] = $label . ': ' . $n;
                    }
                    echo e(implode(', ', $parts));
                ?></desc>
                <line class="g-axis" x1="20" y1="<?= e((string) (10 + $chartH)) ?>" x2="630" y2="<?= e((string) (10 + $chartH)) ?>"></line>
                <?php $i = 0; foreach ($dist as $label => $n):
                    $h = $maxCount > 0 ? $n / $maxCount * ($chartH - 16) : 0;
                    $x = 25 + $i * $slot + ($slot - $barW) / 2;
                    $y = 10 + $chartH - $h;
                    ?>
                    <rect class="g-bar<?= $n === 0 ? ' g-bar-empty' : '' ?>" x="<?= e(number_format($x, 1, '.', '')) ?>" y="<?= e(number_format($y, 1, '.', '')) ?>" width="<?= e(number_format($barW, 1, '.', '')) ?>" height="<?= e(number_format($h, 1, '.', '')) ?>" rx="3"></rect>
                    <text class="g-bar-count" x="<?= e(number_format($x + $barW / 2, 1, '.', '')) ?>" y="<?= e(number_format($y - 4, 1, '.', '')) ?>" text-anchor="middle"><?= e((string) $n) ?></text>
                    <text class="g-bar-label" x="<?= e(number_format($x + $barW / 2, 1, '.', '')) ?>" y="<?= e((string) (10 + $chartH + 18)) ?>" text-anchor="middle"><?= e((string) $label) ?></text>
                <?php $i++; endforeach; ?>
            </svg>
        <?php endif; ?>

        <h3 class="g-h3">Aufgaben</h3>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th scope="col">Nr.</th><th scope="col">Titel</th><th scope="col">AFB</th><th scope="col" class="text-right">Max.</th><th scope="col" class="text-right">Durchschnitt</th><th scope="col">Lösungsquote</th><th scope="col" class="text-right">Bewertet</th></tr></thead>
                <tbody>
                <?php foreach ($stats['tasks'] as $t): $rate = $t['rate'] === null ? null : (int) round($t['rate'] * 100); ?>
                    <tr>
                        <th scope="row"><?= e($t['number']) ?></th>
                        <td><?= e($t['title'] !== '' ? $t['title'] : '(ohne Titel)') ?></td>
                        <td><?= e((string) ($t['afb'] ?? '–')) ?></td>
                        <td class="text-right"><?= e(format_points($t['points'])) ?></td>
                        <td class="text-right"><?= e($fmt($t['average'], 2)) ?></td>
                        <td>
                            <?php if ($rate !== null): ?>
                                <div class="g-rate"><div class="progress" role="progressbar" aria-valuenow="<?= e((string) $rate) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Lösungsquote Aufgabe <?= e($t['number']) ?>"><span style="width: <?= e((string) $rate) ?>%"></span></div><span><?= e((string) $rate) ?> %</span></div>
                            <?php else: ?>–<?php endif; ?>
                        </td>
                        <td class="text-right"><?= e((string) $t['n']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($stats['afb'] !== []): ?>
            <h3 class="g-h3">Anforderungsbereiche</h3>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th scope="col">Bereich</th><th scope="col" class="text-right">Punkte der Klausur</th><th scope="col">Lösungsquote</th></tr></thead>
                    <tbody>
                    <?php foreach ($stats['afb'] as $a): $rate = $a['rate'] === null ? null : (int) round($a['rate'] * 100); ?>
                        <tr>
                            <th scope="row"><?= $a['afb'] === '' ? 'ohne Angabe' : 'AFB ' . e($a['afb']) ?></th>
                            <td class="text-right"><?= e(format_points($a['points'])) ?></td>
                            <td>
                                <?php if ($rate !== null): ?>
                                    <div class="g-rate"><div class="progress" role="progressbar" aria-valuenow="<?= e((string) $rate) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Lösungsquote <?= e($a['afb'] === '' ? 'ohne Angabe' : 'AFB ' . $a['afb']) ?>"><span style="width: <?= e((string) $rate) ?>%"></span></div><span><?= e((string) $rate) ?> %</span></div>
                                <?php else: ?>–<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="card mt-2" aria-labelledby="persons-title">
    <div class="card-header"><h2 class="card-title" id="persons-title">Ergebnisse je Person</h2></div>
    <div class="table-wrap">
        <table class="data-table g-results">
            <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col" class="text-right">Punkte</th>
                <th scope="col" class="text-right">Prozent</th>
                <th scope="col"><?= e($gradeNoun) ?></th>
                <th scope="col">Rückmeldung an die Person</th>
                <th scope="col">Abschluss</th>
                <th scope="col"><span class="sr-only">Aktionen</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $pid = (int) $r['id'];
                $complete = $r['cells'] > 0 && $r['graded'] >= $r['cells'];
                ?>
                <tr data-pid="<?= e((string) $pid) ?>">
                    <th scope="row">
                        <?= e($r['name']) ?>
                        <?php if ((string) $r['state'] !== 'submitted'): ?><span class="badge badge-warning">ohne Abgabe</span><?php endif; ?>
                        <?php if (!$complete): ?><span class="badge" title="Nicht alle Aufgaben sind bewertet"><?= e((string) $r['graded']) ?>/<?= e((string) $r['cells']) ?> bewertet</span><?php endif; ?>
                    </th>
                    <td class="text-right nowrap" data-total><?= e(format_points($r['total'])) ?> / <?= e(format_points($r['max'])) ?></td>
                    <td class="text-right" data-percent><?= $r['percent'] === null ? '–' : e($fmt((float) $r['percent'])) . ' %' ?></td>
                    <td><strong data-grade><?= e((string) ($r['grade_label'] ?? '–')) ?></strong></td>
                    <td class="g-comment-cell">
                        <label class="sr-only" for="gc-<?= e((string) $pid) ?>">Rückmeldung für <?= e($r['name']) ?></label>
                        <textarea class="input" id="gc-<?= e((string) $pid) ?>" rows="2" maxlength="5000" data-person-comment <?= $grading ? '' : 'readonly' ?>><?= e($r['general_comment']) ?></textarea>
                        <span class="text-faint text-sm" data-save-state aria-live="polite"></span>
                    </td>
                    <td>
                        <label class="checkbox-row">
                            <input type="checkbox" data-finalize <?= $r['finalized_at'] !== null ? 'checked' : '' ?> <?= $grading ? '' : 'disabled' ?>
                                   data-incomplete="<?= $complete ? '0' : '1' ?>">
                            abgeschlossen
                        </label>
                    </td>
                    <td class="nowrap text-right">
                        <a class="btn btn-sm" href="<?= e($u('/korrektur/' . $pid)) ?>">Korrektur</a>
                        <a class="btn btn-sm" href="<?= e($u('/ergebnisse/pdf/' . $pid)) ?>">PDF</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

</div>
