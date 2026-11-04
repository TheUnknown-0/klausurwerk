<?php
/**
 * Klausur anlegen. Erwartet: $exam (null), $old, $courses, $rooms.
 */

use App\Services\ExamService;

$val = static fn (string $key, mixed $fallback = ''): string => (string) ($old[$key] ?? $fallback);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a href="<?= e($ctx->url('/klausuren')) ?>">← Klausuren</a></div>
        <h1 class="page-title">Neue Klausur</h1>
        <p class="page-sub">Die Klausur wird als Entwurf angelegt. Aufgaben und weitere Einstellungen folgen danach.</p>
    </div>
</div>

<form class="card card-pad max-w-lg" method="post" action="<?= e($ctx->url('/klausuren/neu')) ?>" data-exam-form>
    <?= $csrf->field() ?>
    <?php require __DIR__ . '/_meta-fields.php'; ?>
    <div class="cluster">
        <button class="btn btn-primary" type="submit">Klausur anlegen</button>
        <a class="btn btn-ghost" href="<?= e($ctx->url('/klausuren')) ?>">Abbrechen</a>
    </div>
</form>
