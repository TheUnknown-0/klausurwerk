<?php /** Zielseite zum Beenden des Safe Exam Browsers. */ ?>
<div class="empty-state">
    <div class="empty-icon"><?= icon('circle-check') ?></div>
    <h1 class="page-title">Klausur beendet</h1>
    <p>Du kannst den Safe Exam Browser jetzt schließen.</p>
    <p><a class="btn" href="<?= e($ctx->url('/meine-klausuren')) ?>">Zu meinen Klausuren</a></p>
</div>
