<?php
/**
 * Einzelnes Ergebnis einer Schüler:in (Bundle grading.js zeigt die Aufgaben schreibgeschützt).
 * Erwartet: $exam, $summary, $showExpectation, $boot.
 */
$percent = $summary['percent'] === null ? null : number_format((float) $summary['percent'], 1, ',', '');
?>
<div class="page-header">
    <div class="page-title-group">
        <a class="page-eyebrow" href="<?= e($ctx->url('/meine-ergebnisse')) ?>"><?= icon('arrow-left') ?> Meine Ergebnisse</a>
        <h1 class="page-title"><?= e($exam['title']) ?></h1>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e($ctx->url('/meine-ergebnisse/' . (int) $exam['id'] . '/pdf')) ?>"><?= icon('printer') ?> Als PDF speichern</a>
    </div>
</div>

<section class="stat-grid" aria-label="Dein Ergebnis">
    <div class="stat-card stat-accent">
        <div class="stat-label"><?= ($summary['scale_type'] ?? null) === 'points' ? 'Notenpunkte' : 'Note' ?></div>
        <div class="stat-value"><?= e((string) (($summary['grade_label'] ?? '') !== '' ? $summary['grade_label'] : '–')) ?></div>
    </div>
    <div class="stat-card"><div class="stat-label">Punkte</div><div class="stat-value"><?= e(format_points($summary['total'])) ?> <span class="text-soft text-sm">von <?= e(format_points($summary['max'])) ?></span></div></div>
    <div class="stat-card"><div class="stat-label">Prozent</div><div class="stat-value"><?= $percent === null ? '–' : e($percent) . ' %' ?></div></div>
</section>

<?php if (trim((string) $summary['general_comment']) !== ''): ?>
    <section class="card card-pad mt-2" aria-labelledby="gc-title">
        <h2 class="card-title" id="gc-title">Rückmeldung deiner Lehrkraft</h2>
        <p class="g-pre"><?= e($summary['general_comment']) ?></p>
    </section>
<?php endif; ?>

<div id="review-app" class="g-review mt-2">
    <p class="text-soft" data-loading>Deine Aufgaben werden geladen ...</p>
    <noscript><div class="alert alert-error">Für die Ansicht der Aufgaben wird JavaScript benötigt. Dein Ergebnis oben ist bereits vollständig. Das PDF enthält alle Aufgaben.</div></noscript>
</div>
<script type="application/json" id="review-boot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
