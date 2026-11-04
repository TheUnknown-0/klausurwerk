<?php
/**
 * Material einer Klausur. Erwartet: $exam, $materials (mit 'used'), $tasks (Übersicht), $editable.
 */

$id = (int) $exam['id'];
$base = $ctx->url('/klausuren/' . $id . '/material');
$taskLabel = static fn (array $t): string => $t['number'] . ' ' . ($t['title'] !== '' ? $t['title'] : '(ohne Titel)');
$taskNames = [];
foreach ($tasks as $t) {
    $taskNames[(int) $t['id']] = $taskLabel($t);
}
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($ctx->url('/klausuren/' . $id)) ?>">← <?= e($exam['title']) ?></a></div>
        <h1 class="page-title">🖼️ Material</h1>
        <p class="page-sub">Bilder (PNG, JPG, GIF, WebP) und PDF-Dateien, höchstens 20 MB je Datei. Im Aufgabentext einbinden mit <code class="mono">![Beschriftung](material:ID)</code>.</p>
    </div>
</div>

<?php if (!$editable): ?>
    <div class="alert alert-info">Die Klausur ist gestartet oder abgeschlossen: Material kann nicht mehr verändert werden.</div>
<?php else: ?>
    <form class="card card-pad mb-2" method="post" action="<?= e($base) ?>" enctype="multipart/form-data">
        <?= $csrf->field() ?>
        <h2 class="card-title">Datei hochladen</h2>
        <div class="form-grid">
            <div class="field">
                <label for="datei">Datei *</label>
                <input class="input" type="file" id="datei" name="datei" required accept=".png,.jpg,.jpeg,.gif,.webp,.pdf,image/png,image/jpeg,image/gif,image/webp,application/pdf">
            </div>
            <div class="field">
                <label for="task_id">Gehört zu Aufgabe</label>
                <select id="task_id" name="task_id">
                    <option value="">(keine Zuordnung)</option>
                    <?php foreach ($tasks as $t): ?><option value="<?= e($t['id']) ?>"><?= e($taskLabel($t)) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <label class="checkbox-row"><input type="checkbox" name="is_aid" value="1"> Als Hilfsmittel anbieten (Seitenleiste, z. B. Formelsammlung)</label>
        <button class="btn btn-primary" type="submit">Hochladen</button>
    </form>
<?php endif; ?>

<?php if ($materials === []): ?>
    <div class="card empty-state"><div class="empty-icon" aria-hidden="true">🖼️</div><p>Noch kein Material hochgeladen.</p></div>
<?php else: ?>
    <div class="card table-wrap">
        <table class="data-table">
            <thead><tr><th>Datei</th><th>ID</th><th>Größe</th><th>Aufgabe</th><th>Hilfsmittel</th><th>Im Text</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($materials as $m): ?>
                <tr>
                    <td><?= e($m['original_name']) ?> <span class="text-faint text-sm"><?= e($m['mime']) ?></span></td>
                    <td><code class="mono">material:<?= e($m['id']) ?></code></td>
                    <td class="nowrap"><?= e(number_format(((int) $m['size_bytes']) / 1024, 0, ',', '.')) ?> KB</td>
                    <td>
                        <?php if ($editable): ?>
                            <form class="cluster" method="post" action="<?= e($base . '/' . (int) $m['id'] . '/zuordnung') ?>">
                                <?= $csrf->field() ?>
                                <label class="sr-only" for="zu-<?= e($m['id']) ?>">Aufgabe für <?= e($m['original_name']) ?></label>
                                <select id="zu-<?= e($m['id']) ?>" name="task_id">
                                    <option value="">(keine)</option>
                                    <?php foreach ($tasks as $t): ?>
                                        <option value="<?= e($t['id']) ?>" <?= (int) $m['task_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($taskLabel($t)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm" type="submit">OK</button>
                            </form>
                        <?php else: ?>
                            <?= e($taskNames[(int) ($m['task_id'] ?? 0)] ?? '-') ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($editable): ?>
                            <form method="post" action="<?= e($base . '/' . (int) $m['id'] . '/hilfsmittel') ?>">
                                <?= $csrf->field() ?>
                                <button class="btn btn-sm <?= (int) $m['is_aid'] === 1 ? 'btn-primary' : '' ?>" type="submit" aria-pressed="<?= (int) $m['is_aid'] === 1 ? 'true' : 'false' ?>">
                                    <?= (int) $m['is_aid'] === 1 ? 'Ja' : 'Nein' ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <?= (int) $m['is_aid'] === 1 ? 'Ja' : 'Nein' ?>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) $m['used'] > 0 ? e((string) $m['used']) . ' Aufgabe(n)' : '<span class="text-faint">ungenutzt</span>' ?></td>
                    <td class="text-right">
                        <?php if ($editable): ?>
                            <form method="post" action="<?= e($base . '/' . (int) $m['id'] . '/loeschen') ?>" data-confirm="Datei &quot;<?= e($m['original_name']) ?>&quot; löschen?<?= (int) $m['used'] > 0 ? ' Sie wird noch in Aufgabentexten verwendet.' : '' ?>">
                                <?= $csrf->field() ?>
                                <button class="btn btn-sm btn-danger-ghost" type="submit">Löschen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
