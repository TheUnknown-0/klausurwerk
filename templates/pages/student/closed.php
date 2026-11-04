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
<div class="stack" style="text-align:center;">
    <div style="font-size:2.6rem;" aria-hidden="true">📝</div>
    <h1 style="font-size:1.4rem;"><?= e($exam['title']) ?></h1>
    <p class="text-soft"><?= e($message) ?></p>
    <div class="cluster" style="justify-content:center;">
        <?php if ($status === 'returned' && $hasParticipation): ?>
            <a class="btn btn-primary" href="<?= e($ctx->url('/meine-ergebnisse/' . (int) $exam['id'])) ?>">Ergebnis ansehen</a>
        <?php endif; ?>
        <a class="btn" href="<?= e($ctx->url('/meine-klausuren')) ?>">Zu meinen Klausuren</a>
    </div>
</div>
