<?php /** KI-Zugang ist ausgeschaltet. Erwartet: $isAdmin. */ ?>
<div class="page-eyebrow">KI-Zugang</div>
<h1>KI-Zugang nicht freigeschaltet</h1>
<p>An dieser Schule ist der KI-Zugang (noch) ausgeschaltet. Deshalb kann sich kein KI-Programm mit Klausurwerk verbinden.</p>
<?php if ($isAdmin): ?>
    <p>Als Admin schaltest du ihn unter <a href="<?= e($ctx->url('/admin/einstellungen')) ?>">Verwaltung › Einstellungen</a> ein.
    Danach die Verbindung im KI-Programm noch einmal starten.</p>
<?php else: ?>
    <p>Bitte wende dich an die Admins deiner Schule.</p>
<?php endif; ?>
<p><a class="btn" href="<?= e($ctx->url('/')) ?>">Zur Übersicht</a></p>
