<?php
/**
 * Versionsdialog für Admins: Update-Stand und Änderungsverlauf aus CHANGELOG.md.
 * Erwartet: $update (Services\UpdateCheck::state()), $updateDue (bool: Prüfung steht an).
 * Geöffnet über die Versionsnummer in der Seitenleiste oder den Update-Knopf in der Kopfzeile.
 */

use App\Services\Changelog;

$entries = Changelog::local();
?>
<dialog class="modal version-dialog" id="version-dialog" aria-labelledby="version-dialog-title"
        data-check-url="<?= e($ctx->url('/api/version/pruefen')) ?>"<?= $updateDue ? ' data-check-due="1"' : '' ?>>
    <div class="modal-header">
        <h3 id="version-dialog-title">Klausurwerk <?= e($update['installed']) ?></h3>
        <button class="modal-close" type="button" data-close-modal aria-label="Schließen"><?= icon('x') ?></button>
    </div>
    <div class="modal-body stack">
        <?php if ($update['configured']): ?>
            <div class="version-toolbar">
                <button class="btn btn-primary btn-sm" type="button" data-version-check>
                    <?= icon('refresh-cw') ?> <span data-version-check-label>Nach neuen Versionen suchen</span>
                </button>
            </div>
        <?php endif; ?>
        <section class="version-status" data-version-status aria-live="polite">
            <?= $view->renderPartial('partials/version-status', ['update' => $update]) ?>
        </section>

        <section>
            <h4 class="version-heading"><?= icon('history') ?> Änderungen</h4>
            <?php if ($entries === []): ?>
                <p class="text-sm text-soft">CHANGELOG.md fehlt in dieser Installation.</p>
            <?php endif; ?>
            <?php foreach ($entries as $i => $entry): ?>
                <div class="version-entry">
                    <h5><?= e($entry['version']) ?>
                        <?php if ($i === 0): ?><span class="badge badge-primary">installiert</span><?php endif; ?>
                        <?php if (($date = Changelog::formatDate($entry['date'])) !== null): ?>
                            <span class="text-faint text-sm"><?= e($date) ?></span>
                        <?php endif; ?>
                    </h5>
                    <ul>
                        <?php foreach ($entry['items'] as $item): ?>
                            <li><?= Changelog::itemHtml($item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </section>
    </div>
    <div class="modal-footer">
        <button class="btn btn-ghost" type="button" data-close-modal>Schließen</button>
    </div>
</dialog>
