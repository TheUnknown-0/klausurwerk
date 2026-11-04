<?php
/**
 * Aufbewahrung: Fristen und überfällige Klausuren.
 * Erwartet: $answersMonths, $logsMonths, $due, $min, $max, $canEdit
 */

$base = $ctx->url('/admin/aufbewahrung');
$statusLabels = [
    'draft' => 'Entwurf', 'ready' => 'Bereit', 'finished' => 'Beendet', 'grading' => 'In Korrektur',
    'returned' => 'Zurückgegeben', 'archived' => 'Archiviert',
];
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Aufbewahrung</h1>
        <p class="page-sub">Fristen für Protokolle und für Antworten samt Noten. Gelöscht wird nur nach deiner Bestätigung.</p>
    </div>
</div>

<form class="card card-pad max-w-lg mb-2" method="post" action="<?= e($base . '/fristen') ?>">
    <?= $csrf->field() ?>
    <h3 class="mt-0">Fristen</h3>
    <div class="form-grid">
        <div class="field">
            <label for="retention_logs_months">Protokolle (Monate)</label>
            <input class="input w-narrow" type="number" id="retention_logs_months" name="retention_logs_months" required
                   min="<?= e((string) $min) ?>" max="<?= e((string) $max) ?>" value="<?= e((string) $logsMonths) ?>">
            <div class="hint">Ereignisse (Verstöße, Sperren), Replay-Schritte und Anfragen, ab Ende der Klausur. Standard 6.</div>
        </div>
        <div class="field">
            <label for="retention_answers_months">Antworten und Noten (Monate)</label>
            <input class="input w-narrow" type="number" id="retention_answers_months" name="retention_answers_months" required
                   min="<?= e((string) $min) ?>" max="<?= e((string) $max) ?>" value="<?= e((string) $answersMonths) ?>">
            <div class="hint">Ganze Klausur samt Aufgaben, Antworten, Bewertungen und Dateien, ab Rückgabe. Standard 24.</div>
        </div>
    </div>
    <button class="btn btn-primary" type="submit">Fristen speichern</button>
</form>

<h2>Fällige Klausuren</h2>
<?php if ($due === []): ?>
    <div class="empty-state">
        <div class="empty-icon">✅</div>
        <p>Keine Klausur hat eine Frist überschritten.</p>
    </div>
<?php else: ?>
    <div class="alert alert-warning">
        <?= e((string) count($due)) ?> Klausur(en) sind fällig. „Protokolle löschen“ entfernt nur Ereignisse, Replay und Anfragen.
        „Klausur vollständig löschen“ entfernt alles, auch Aufgaben (und damit den Eintrag im Archiv), Antworten, Noten und hochgeladene Dateien.
        Beides lässt sich nicht rückgängig machen.
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Klausur</th>
                <th>Kurs</th>
                <th>Status</th>
                <th>Beendet / zurückgegeben</th>
                <th>Protokolle</th>
                <th style="text-align:right;">Aktionen</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($due as $exam): ?>
                <?php $id = (int) $exam['id']; ?>
                <tr>
                    <td><strong><?= e($exam['title']) ?></strong><div class="text-sm text-soft"><?= e((string) (int) $exam['participants']) ?> Teilnahmen</div></td>
                    <td><?= e($exam['course_name'] ?? '–') ?></td>
                    <td><span class="badge"><?= e($statusLabels[$exam['status']] ?? $exam['status']) ?></span></td>
                    <td class="text-sm">
                        <?= e(format_date($exam['answers_ref'])) ?>
                        <div class="text-soft"><?= $exam['answers_due'] ? 'Frist für Antworten überschritten' : 'Antworten noch in Frist' ?></div>
                    </td>
                    <td class="text-sm">
                        <?php if ($exam['logs_due']): ?>
                            <span class="badge badge-warning"><?= e((string) (int) $exam['log_rows']) ?> Einträge fällig</span>
                        <?php else: ?>
                            <span class="text-faint"><?= (int) $exam['log_rows'] > 0 ? 'noch in Frist' : 'keine' ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <?php if ($exam['logs_due']): ?>
                                <button class="btn btn-sm btn-ghost" type="button" data-open-modal="logs-<?= e((string) $id) ?>">Protokolle löschen</button>
                            <?php endif; ?>
                            <?php if ($exam['answers_due']): ?>
                                <button class="btn btn-sm btn-danger-ghost" type="button" data-open-modal="exam-<?= e((string) $id) ?>">Klausur vollständig löschen</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php foreach ($due as $exam): ?>
        <?php $id = (int) $exam['id']; ?>
        <?php foreach ([['logs', 'protokolle', 'Protokolle löschen', 'Löscht Ereignisse, Replay-Schritte und Anfragen dieser Klausur. Antworten und Noten bleiben.', $exam['logs_due']],
                        ['exam', 'klausur-loeschen', 'Klausur vollständig löschen', 'Löscht die Klausur mit Aufgaben, Teilnahmen, Antworten, Bewertungen, Materialien und hochgeladenen Antwortdateien.', $exam['answers_due']]] as [$prefix, $path, $heading, $text, $enabled]): ?>
            <?php if (!$enabled) { continue; } ?>
            <dialog class="modal" id="<?= e($prefix . '-' . $id) ?>" aria-labelledby="<?= e($prefix . '-' . $id) ?>-title">
                <form method="post" action="<?= e($base . '/' . $path) ?>">
                    <?= $csrf->field() ?>
                    <input type="hidden" name="exam_id" value="<?= e((string) $id) ?>">
                    <div class="modal-header">
                        <h3 id="<?= e($prefix . '-' . $id) ?>-title"><?= e($heading) ?></h3>
                        <button class="modal-close" type="button" data-close-modal aria-label="Schließen">✕</button>
                    </div>
                    <div class="modal-body stack">
                        <p><strong><?= e($exam['title']) ?></strong> (<?= e($exam['course_name'] ?? 'ohne Kurs') ?>)</p>
                        <p class="text-sm"><?= e($text) ?> Das lässt sich nicht rückgängig machen.</p>
                        <label class="checkbox-row">
                            <input type="checkbox" name="bestaetigt" value="1" required>
                            <span>Ich habe verstanden, dass die Daten dauerhaft gelöscht werden.</span>
                        </label>
                        <div class="field mb-0">
                            <label for="<?= e($prefix . '-' . $id) ?>-word">Zur Bestätigung LÖSCHEN eintippen</label>
                            <input class="input" type="text" id="<?= e($prefix . '-' . $id) ?>-word" name="bestaetigung" autocomplete="off" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-ghost" type="button" data-close-modal>Abbrechen</button>
                        <button class="btn btn-danger" type="submit"><?= e($heading) ?></button>
                    </div>
                </form>
            </dialog>
        <?php endforeach; ?>
    <?php endforeach; ?>
<?php endif; ?>
