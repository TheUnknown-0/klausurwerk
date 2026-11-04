<?php
/**
 * Raum: Stammdaten und Sitzraster mit gespeicherten Plätzen.
 * Erwartet: $room, $values, $grid (row => col => position), $outside, $seatCount, $exams, $min, $max
 */

$base = $ctx->url('/admin/raeume');
$roomId = (int) $room['id'];
$cols = (int) $room['grid_cols'];
$rows = (int) $room['grid_rows'];
$statusLabels = [
    'draft' => 'Entwurf', 'ready' => 'Bereit', 'lobby' => 'Warteraum', 'running' => 'Läuft', 'paused' => 'Pausiert',
    'finished' => 'Beendet', 'grading' => 'In Korrektur', 'returned' => 'Zurückgegeben', 'archived' => 'Archiviert',
];
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($base) ?>">← Räume</a></div>
        <h1 class="page-title"><?= e($room['name']) ?></h1>
        <p class="page-sub"><?= e((string) $cols) ?> Spalten × <?= e((string) $rows) ?> Zeilen · <?= e((string) $seatCount) ?> gespeicherte Plätze</p>
    </div>
    <div class="page-actions">
        <?php if ($seatCount > 0): ?>
            <form method="post" action="<?= e($base . '/' . $roomId . '/plaetze-leeren') ?>"
                  data-confirm="Alle <?= e((string) $seatCount) ?> gespeicherten Plätze in diesem Raum leeren?">
                <?= $csrf->field() ?>
                <button class="btn btn-ghost" type="submit">Alle Plätze leeren</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($outside !== []): ?>
    <div class="alert alert-warning">
        <div>
            <strong><?= e((string) count($outside)) ?> Platz/Plätze liegen außerhalb des Rasters</strong> (nach einer Verkleinerung):
            <?php foreach ($outside as $position): ?>
                <form class="inline-form" method="post" action="<?= e($base . '/' . $roomId . '/platz-leeren') ?>">
                    <?= $csrf->field() ?>
                    <input type="hidden" name="user_id" value="<?= e((string) (int) $position['user_id']) ?>">
                    <button class="btn btn-sm btn-ghost" type="submit"><?= e(display_name($position)) ?> (Spalte <?= e((string) ((int) $position['col'] + 1)) ?>, Zeile <?= e((string) ((int) $position['row'] + 1)) ?>) leeren</button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card card-pad mb-2">
    <h3 class="mt-0">Sitzplan</h3>
    <p class="text-sm text-soft">Die Tafel ist oben. Gespeichert werden Plätze aus früheren Klausuren in diesem Raum; sie dienen im Pult als Vorschlag.</p>
    <div class="room-grid-scroll">
        <div class="room-board" aria-hidden="true">Tafel</div>
        <table class="room-grid">
            <caption class="sr-only">Sitzplan von <?= e($room['name']) ?></caption>
            <tbody>
            <?php for ($r = 0; $r < $rows; $r++): ?>
                <tr>
                    <?php for ($c = 0; $c < $cols; $c++): ?>
                        <?php $seat = $grid[$r][$c] ?? null; ?>
                        <td class="room-seat<?= $seat !== null ? ' is-taken' : '' ?>">
                            <?php if ($seat !== null): ?>
                                <form method="post" action="<?= e($base . '/' . $roomId . '/platz-leeren') ?>"
                                      data-confirm="Platz von <?= e(display_name($seat)) ?> leeren?">
                                    <?= $csrf->field() ?>
                                    <input type="hidden" name="user_id" value="<?= e((string) (int) $seat['user_id']) ?>">
                                    <span class="seat-name"><?= e(display_name($seat)) ?></span>
                                    <button class="seat-clear" type="submit" aria-label="Platz von <?= e(display_name($seat)) ?> leeren">✕</button>
                                </form>
                            <?php else: ?>
                                <span class="sr-only">frei</span>
                            <?php endif; ?>
                        </td>
                    <?php endfor; ?>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="grid-2">
    <form class="card card-pad" method="post" action="<?= e($base . '/' . $roomId) ?>">
        <?= $csrf->field() ?>
        <h3 class="mt-0">Raum bearbeiten</h3>
        <div class="form-grid">
            <div class="field">
                <label for="name">Name</label>
                <input class="input" type="text" id="name" name="name" maxlength="100" required value="<?= e($values['name']) ?>">
            </div>
            <div class="field">
                <label for="grid_cols">Spalten</label>
                <input class="input w-narrow" type="number" id="grid_cols" name="grid_cols" min="<?= e((string) $min) ?>" max="<?= e((string) $max) ?>" required value="<?= e($values['grid_cols']) ?>">
            </div>
            <div class="field">
                <label for="grid_rows">Zeilen</label>
                <input class="input w-narrow" type="number" id="grid_rows" name="grid_rows" min="<?= e((string) $min) ?>" max="<?= e((string) $max) ?>" required value="<?= e($values['grid_rows']) ?>">
                <div class="hint">Bei einer Verkleinerung bleiben Plätze außerhalb des Rasters erhalten und werden oben angezeigt.</div>
            </div>
        </div>
        <button class="btn btn-primary" type="submit">Speichern</button>
    </form>

    <div class="card card-pad">
        <h3 class="mt-0">Klausuren in diesem Raum</h3>
        <?php if ($exams === []): ?>
            <p class="text-sm text-soft">Noch keine Klausur nutzt diesen Raum.</p>
        <?php else: ?>
            <ul class="plain-list">
                <?php foreach ($exams as $exam): ?>
                    <li>
                        <a href="<?= e($ctx->url('/klausuren/' . (int) $exam['id'])) ?>"><?= e($exam['title']) ?></a>
                        <span class="badge"><?= e($statusLabels[$exam['status']] ?? $exam['status']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <hr class="divider">
        <form method="post" action="<?= e($base . '/' . $roomId . '/loeschen') ?>"
              data-confirm="Raum „<?= e($room['name']) ?>“ löschen? Klausuren behalten ihre Daten, verlieren aber den Raum.">
            <?= $csrf->field() ?>
            <button class="btn btn-danger-ghost" type="submit">Raum löschen</button>
        </form>
    </div>
</div>
