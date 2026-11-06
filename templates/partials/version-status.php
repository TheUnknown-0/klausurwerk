<?php
/**
 * Stand der Update-Prüfung im Versionsdialog (nur Admins).
 * Erwartet: $update (Services\UpdateCheck::state()).
 * Wird nach "Jetzt prüfen" von POST /api/version/pruefen neu gerendert (public/assets/js/version.js).
 */

use App\Services\Changelog;

$checkedAt = $update['checkedAt'] !== null ? date('d.m.Y, H:i', (int) $update['checkedAt']) . ' Uhr' : null;
?>
<div class="version-state">
    <?php if ($update['available']): ?>
        <span class="badge badge-warning">Update verfügbar</span>
        <span>Version <strong><?= e($update['latest']) ?></strong> ist erschienen, installiert ist <?= e($update['installed']) ?>.</span>
    <?php elseif (!$update['configured']): ?>
        <span class="badge">Aus</span>
        <span>Die Update-Prüfung ist auf diesem Server abgeschaltet (<code>UPDATE_CHECK_URL=off</code>).</span>
    <?php elseif ($update['error'] !== null && $update['error'] !== ''): ?>
        <span class="badge badge-danger">Prüfung fehlgeschlagen</span>
        <span><?= e($update['error']) ?></span>
    <?php elseif ($checkedAt === null): ?>
        <span class="badge">Noch nicht geprüft</span>
    <?php else: ?>
        <span class="badge badge-success">Aktuell</span>
        <span>Das ist die neueste Version.</span>
    <?php endif; ?>
</div>

<?php if ($update['configured']): ?>
    <p class="version-meta text-sm text-soft">
        <?= $checkedAt !== null ? 'Zuletzt geprüft: ' . e($checkedAt) : 'Bisher keine Prüfung' ?>
        <?php if ($update['available'] && ($update['error'] ?? '') !== ''): ?>
            · dabei fehlgeschlagen: <?= e($update['error']) ?>
        <?php endif; ?>
        <?php if (!$update['enabled']): ?>
            · automatische Prüfung aus (<a href="<?= e($ctx->url('/admin/einstellungen')) ?>">Einstellungen</a>), der Knopf oben geht trotzdem
        <?php endif; ?>
    </p>
<?php endif; ?>

<?php if ($update['available']): ?>
    <?php foreach ($update['news'] as $entry): ?>
        <div class="version-entry version-entry-new">
            <h4>Neu in <?= e($entry['version']) ?>
                <?php if (($date = Changelog::formatDate($entry['date'])) !== null): ?>
                    <span class="text-faint text-sm"><?= e($date) ?></span>
                <?php endif; ?>
            </h4>
            <ul>
                <?php foreach ($entry['items'] as $item): ?>
                    <li><?= Changelog::itemHtml($item) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
    <div class="alert alert-info version-howto">
        <p class="mt-0">Aktualisiert wird auf dem Server, im Installationsordner von Klausurwerk:</p>
        <pre><code>sudo ./update.sh</code></pre>
        <p class="mb-0 text-sm">Nicht während einer laufenden Klausur. Der Updater sichert vorher Datenbank und Uploads und
            ist etwa eine Minute offline. Mehr dazu im Handbuch für Admins, Kapitel 2.8.</p>
    </div>
<?php endif; ?>
