<?php
/**
 * Zustimmung zum KI-Zugang (OAuth authorize).
 * Erwartet: $client (client_name, client_id), $redirectHost, $user, $fields (versteckte Felder).
 */
?>
<div class="page-eyebrow">KI-Zugang</div>
<h1>KI-Programm verbinden?</h1>
<p><strong><?= e($client['client_name']) ?></strong> möchte in deinem Namen
(<?= e(display_name($user)) ?>) mit Klausurwerk arbeiten. Nach der Zustimmung geht es zurück zu
<code><?= e($redirectHost) ?></code>.</p>

<div class="alert alert-info">
    <div>
        <strong>Das darf das Programm danach:</strong>
        <ul class="mb-0">
            <li>deine Klausuren lesen, auch Aufgaben, Musterlösungen und Erwartungshorizonte</li>
            <li>Klausuren anlegen und ändern, solange sie im Entwurf sind</li>
            <li>Aufgaben anlegen, ändern, verschieben, duplizieren und löschen</li>
            <li>Material hochladen und löschen, im Archiv suchen und Aufgaben übernehmen</li>
        </ul>
    </div>
</div>
<p class="text-sm text-soft">Nie zugänglich sind Schülerdaten: Namen, Antworten, Ergebnisse und Noten.
Laufende oder abgeschlossene Klausuren kann das Programm nicht verändern. Jede Änderung steht im Audit-Log.
Den Zugang beendest du jederzeit unter <em>KI-Assistent</em> in der Navigation.</p>
<p class="text-sm text-soft">Verbinde nur Programme, die du selbst gerade eingerichtet hast. Hast du das nicht
angestoßen, lehne ab.</p>

<form method="post" action="<?= e($ctx->url('/oauth/authorize')) ?>" class="mt-2">
    <?= $csrf->field() ?>
    <?php foreach ($fields as $name => $value): ?>
        <input type="hidden" name="<?= e($name) ?>" value="<?= e((string) $value) ?>">
    <?php endforeach; ?>
    <div class="cluster">
        <button class="btn btn-primary btn-lg" type="submit" name="decision" value="allow">Erlauben</button>
        <button class="btn btn-lg" type="submit" name="decision" value="deny">Ablehnen</button>
    </div>
</form>
