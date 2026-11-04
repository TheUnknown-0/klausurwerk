<?php
/**
 * Freundliche Seite, wenn eine Klausur (noch) nicht oder nicht mehr geöffnet ist.
 * Erwartet: $exam, $status, $hasParticipation.
 */
$messages = [
    'draft' => 'Diese Klausur ist noch nicht freigegeben.',
    'ready' => 'Diese Klausur ist noch nicht geöffnet. Warte, bis die Lehrkraft den Warteraum öffnet.',
    'finished' => 'Diese Klausur ist beendet.',
    'grading' => 'Diese Klausur ist beendet und wird gerade korrigiert.',
    'returned' => 'Diese Klausur ist korrigiert.',
    'archived' => 'Diese Klausur ist archiviert.',
];
$message = $messages[$status] ?? 'Diese Klausur ist gerade nicht geöffnet.';
?>
<div class="empty-state">
    <div class="empty-icon"><?= icon('file-pen-line') ?></div>
    <h1 class="page-title"><?= e($exam['title']) ?></h1>
    <p><?= e($message) ?></p>
    <p>
        <?php if ($status === 'returned' && $hasParticipation): ?>
            <a class="btn btn-primary" href="<?= e($ctx->url('/meine-ergebnisse/' . (int) $exam['id'])) ?>">Ergebnis ansehen</a>
        <?php endif; ?>
        <a class="btn" href="<?= e($ctx->url('/meine-klausuren')) ?>">Zu meinen Klausuren</a>
    </p>
</div>
