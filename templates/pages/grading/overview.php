<?php
/**
 * Korrektur-Übersicht: Matrix Teilnehmende x Aufgaben.
 * Erwartet: $exam, $caps, $isOwner, $overview, $auto, $anonymous, $seeOthers, $statusLabel, $boot.
 */

use App\Services\ExamAccess;

$examId = (int) $exam['id'];
$status = (string) $exam['status'];
$grading = $status === 'grading';
$canGrade = $caps[ExamAccess::CAP_GRADE];
$tasks = $overview['tasks'];
$rows = $overview['rows'];
$progress = $overview['progress'];
$percentDone = $progress['cells'] > 0 ? (int) round($progress['graded'] / $progress['cells'] * 100) : 0;
$base = $ctx->url('/klausuren/' . $examId . '/korrektur');
$qs = static fn (array $q): string => $q === [] ? '' : '?' . http_build_query($q);
$keep = [];
if ($anonymous) {
    $keep['anonym'] = '1';
}
if ($seeOthers && !$isOwner) {
    $keep['fremde'] = '1';
}
$query = static fn (array $extra = []): string => $qs($extra + $keep);
$toggleAnon = $base . $qs($anonymous ? array_diff_key($keep, ['anonym' => 1]) : $keep + ['anonym' => '1']);
$toggleOthers = $base . $qs($seeOthers ? array_diff_key($keep, ['fremde' => 1]) : $keep + ['fremde' => '1']);
$statusText = ['open' => 'offen', 'suggested' => 'Vorschlag', 'graded' => 'bewertet', 'diff' => 'abweichend bewertet', 'error' => 'Vorbewertung fehlgeschlagen'];
$firstPid = $rows !== [] ? (int) $rows[0]['participation_id'] : 0;
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($ctx->url('/klausuren/' . $examId)) ?>">← <?= e($exam['title']) ?></a></div>
        <h1 class="page-title">Korrektur</h1>
        <p class="page-sub cluster">
            <span class="badge badge-primary"><?= e($statusLabel) ?></span>
            <?php if ($anonymous): ?><span class="badge badge-accent">anonym</span><?php endif; ?>
            <?php if (!$grading): ?><span class="text-soft text-sm">Bewertet wird in der Phase „In Korrektur“.</span><?php endif; ?>
        </p>
    </div>
    <div class="page-actions">
        <?php if ($status === 'finished' && $canGrade): ?>
            <form method="post" action="<?= e($ctx->url('/klausuren/' . $examId . '/korrektur/beginnen')) ?>">
                <?= $csrf->field() ?>
                <button class="btn btn-primary" type="submit">▶ Korrektur beginnen</button>
            </form>
        <?php endif; ?>
        <?php if (in_array($status, ['grading', 'returned', 'archived'], true)): ?>
            <a class="btn" href="<?= e($ctx->url('/klausuren/' . $examId . '/ergebnisse')) ?>">📊 Ergebnisse</a>
        <?php endif; ?>
        <a class="btn" href="<?= e($toggleAnon) ?>"><?= $anonymous ? '👁️ Namen zeigen' : '🙈 Anonym korrigieren' ?></a>
        <?php if (!$isOwner): ?>
            <a class="btn" href="<?= e($toggleOthers) ?>"><?= $seeOthers ? 'Fremde Bewertungen ausblenden' : 'Fremde Bewertungen zeigen' ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($rows === []): ?>
    <section class="card"><div class="empty-state card-body"><p>Zu dieser Klausur gibt es noch keine Teilnahmen.</p></div></section>
<?php elseif ($tasks === []): ?>
    <section class="card"><div class="empty-state card-body"><p>Diese Klausur hat keine Aufgaben mit Antwort.</p></div></section>
<?php else: ?>

<div id="grading-overview" data-boot="<?= json_attr($boot) ?>">

<div class="stat-grid">
    <div class="stat-card stat-accent">
        <div class="stat-label">Bewertet</div>
        <div class="stat-value"><?= e((string) $progress['graded']) ?> <span class="text-soft text-sm">von <?= e((string) $progress['cells']) ?></span></div>
        <div class="progress mt-2" role="progressbar" aria-valuenow="<?= e((string) $percentDone) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Fortschritt der Korrektur"><span style="width: <?= e((string) $percentDone) ?>%"></span></div>
    </div>
    <div class="stat-card"><div class="stat-label">Nur Vorschlag</div><div class="stat-value"><?= e((string) $progress['suggested']) ?></div></div>
    <div class="stat-card"><div class="stat-label">Abweichend (Zweitkorrektur)</div><div class="stat-value"><?= e((string) $progress['diff']) ?></div></div>
    <div class="stat-card"><div class="stat-label">Teilnehmende</div><div class="stat-value"><?= e((string) count($rows)) ?></div></div>
</div>

<?php if ($canGrade && $auto['total'] > 0 && in_array($status, ['finished', 'grading'], true)): ?>
<section class="card card-pad mt-2" aria-labelledby="auto-title">
    <h2 class="card-title" id="auto-title">Automatische Vorbewertung</h2>
    <p class="text-sm text-soft">
        Auswahl-, Zuordnungs-, Reihenfolge- und Lückenaufgaben werden sofort geprüft, Programmieraufgaben mit versteckten Tests laufen im Runner.
        Das Ergebnis ist ein Vorschlag (grau in der Matrix); du entscheidest über die Punkte.
    </p>
    <p class="text-sm" data-auto-summary>
        <strong data-auto-done><?= e((string) $auto['done']) ?></strong> von <?= e((string) $auto['total']) ?> geprüft<?php if ($auto['errors'] > 0): ?>,
        <span class="text-danger"><strong data-auto-errors><?= e((string) $auto['errors']) ?></strong> fehlgeschlagen</span><?php endif; ?>.
    </p>
    <div class="cluster">
        <button class="btn btn-primary" type="button" data-auto-run="">⚙️ Automatisch vorbewerten</button>
        <button class="btn" type="button" data-auto-run="errors" <?= $auto['errors'] === 0 ? 'hidden' : '' ?> data-auto-retry>Fehlgeschlagene wiederholen</button>
        <button class="btn" type="button" data-auto-run="all" data-confirm-text="Alle Vorbewertungen löschen und neu berechnen? Deine eigenen Punkte bleiben erhalten.">Alles neu berechnen</button>
        <?php if ($grading): ?>
            <button class="btn" type="button" data-apply="auto" title="Übernimmt Vorschläge aus Auswahl-, Zuordnungs- und Lückenaufgaben, wo du noch nichts bewertet hast.">Vorschläge übernehmen (ohne Code)</button>
            <button class="btn" type="button" data-apply="all" title="Übernimmt alle Vorschläge, auch aus Code-Tests, wo du noch nichts bewertet hast.">Alle Vorschläge übernehmen</button>
        <?php endif; ?>
    </div>
    <div class="progress mt-2" data-auto-progress hidden role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-label="Fortschritt der Vorbewertung"><span style="width: 0%"></span></div>
    <p class="text-sm mt-2" data-auto-message role="status" aria-live="polite"></p>
</section>
<?php endif; ?>

<section class="card mt-2" aria-labelledby="matrix-title">
    <div class="card-header g-matrix-head">
        <h2 class="card-title" id="matrix-title" style="margin:0;">Bewertungsübersicht</h2>
        <div class="cluster g-filters">
            <label class="sr-only" for="g-filter-text"><?= $anonymous ? 'Nummer' : 'Name' ?> suchen</label>
            <input class="input" type="search" id="g-filter-text" placeholder="<?= $anonymous ? 'Nummer suchen' : 'Name suchen' ?>" autocomplete="off" data-filter-text>
            <label class="sr-only" for="g-filter-status">Anzeigen</label>
            <select class="input" id="g-filter-status" data-filter-status>
                <option value="">Alle Personen</option>
                <option value="open">mit offenen Aufgaben</option>
                <option value="diff">mit abweichender Bewertung</option>
                <option value="unsubmitted">ohne Abgabe</option>
                <option value="done">vollständig bewertet</option>
            </select>
        </div>
    </div>
    <div class="table-wrap g-matrix-wrap">
        <table class="data-table g-matrix">
            <thead>
            <tr>
                <th scope="col" class="g-sticky"><?= $anonymous ? 'Nr.' : 'Name' ?></th>
                <?php foreach ($tasks as $t): ?>
                    <th scope="col" class="g-task-head" title="<?= e($t['title'] !== '' ? $t['title'] : 'Aufgabe ' . $t['number']) ?>">
                        <a href="<?= e($base . '/' . $firstPid . $query(['aufgabe' => $t['id'], 'modus' => 'aufgabe'])) ?>" title="Aufgabe <?= e($t['number']) ?> für alle korrigieren">
                            <?= e($t['number']) ?>
                        </a>
                        <span class="g-max"><?= e(format_points($t['points'])) ?> P.</span>
                    </th>
                <?php endforeach; ?>
                <th scope="col" class="g-sum-head">Summe</th>
                <th scope="col" class="g-sum-head" title="Summe einschließlich der noch nicht übernommenen Vorschläge">mit Vorschlägen</th>
            </tr>
            </thead>
            <tbody data-matrix-body>
            <?php foreach ($rows as $row):
                $open = 0;
                $hasDiff = false;
                foreach ($row['cells'] as $c) {
                    if (!in_array($c['status'], ['graded', 'diff'], true)) {
                        $open++;
                    }
                    if ($c['status'] === 'diff') {
                        $hasDiff = true;
                    }
                }
                $flags = [$open > 0 ? 'open' : 'done'];
                if ($hasDiff) {
                    $flags[] = 'diff';
                }
                if (!$row['submitted']) {
                    $flags[] = 'unsubmitted';
                }
                ?>
                <tr data-name="<?= e(mb_strtolower($row['label'])) ?>" data-flags="<?= e(implode(' ', $flags)) ?>">
                    <th scope="row" class="g-sticky">
                        <a href="<?= e($base . '/' . (int) $row['participation_id'] . $query()) ?>"><?= e($row['label']) ?></a>
                        <?php if (!$row['submitted']): ?>
                            <span class="badge badge-warning" title="Zustand: <?= e($row['state']) ?>">ohne Abgabe</span>
                        <?php endif; ?>
                    </th>
                    <?php foreach ($tasks as $t):
                        $c = $row['cells'][$t['id']];
                        $href = $base . '/' . (int) $row['participation_id'] . $query(['aufgabe' => $t['id']]);
                        ?>
                        <td class="g-cell g-<?= e($c['status']) ?><?= $c['answered'] ? '' : ' g-noanswer' ?>">
                            <a href="<?= e($href) ?>">
                                <?php if ($c['points'] !== null): ?>
                                    <span class="g-pts"><?= e(format_points($c['points'])) ?></span>
                                <?php elseif ($c['status'] === 'error'): ?>
                                    <span class="g-pts" aria-hidden="true">!</span>
                                <?php else: ?>
                                    <span class="g-pts" aria-hidden="true">–</span>
                                <?php endif; ?>
                                <span class="sr-only"><?= e($statusText[$c['status']] ?? '') ?><?= $c['answered'] ? '' : ', keine Antwort' ?></span>
                                <?php if ($c['status'] === 'diff'): ?><span class="g-flag" title="Zweitkorrektur weicht ab" aria-hidden="true">⚠</span><?php endif; ?>
                                <?php if ($c['overtime']): ?><span class="g-flag" title="Änderungen nach Ablauf der Zeit" aria-hidden="true">⏰</span><?php endif; ?>
                                <?php if ($c['pastes'] > 0): ?><span class="g-flag" title="<?= e((string) $c['pastes']) ?> Einfügungen" aria-hidden="true">📋</span><?php endif; ?>
                            </a>
                        </td>
                    <?php endforeach; ?>
                    <td class="g-sum"><strong><?= e(format_points($row['sum'])) ?></strong></td>
                    <td class="g-sum g-sum-soft"><?= e(format_points($row['sum_suggested'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer g-legend" aria-label="Legende">
        <span class="g-cell g-graded"><span class="g-pts">2</span></span> bewertet
        <span class="g-cell g-suggested"><span class="g-pts">2</span></span> Vorschlag
        <span class="g-cell g-open"><span class="g-pts">–</span></span> offen
        <span class="g-cell g-diff"><span class="g-pts">2</span></span> abweichend
        <span class="g-cell g-error"><span class="g-pts">!</span></span> Fehler
        <span>⏰ Überziehung</span> <span>📋 Einfügungen</span>
    </div>
</section>

</div>
<?php endif; ?>
