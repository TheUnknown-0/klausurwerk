<?php
/**
 * Notenschlüssel: Standards je Klassenstufe und Vorlagen.
 * Erwartet: $levels, $byLevel, $templates, $usage, $types
 */

$base = $ctx->url('/admin/notenschluessel');
/** Kurzfassung der Grenzen für die Übersicht. */
$summary = static function (array $scale): string {
    $steps = $scale['thresholds'];
    if ($steps === []) {
        return 'keine Stufen';
    }
    $first = $steps[0];
    $last = $steps[count($steps) - 1];

    return count($steps) . ' Stufen, ' . $first['label'] . ' ab ' . $first['min_percent'] . ' %, ' . $last['label'] . ' ab ' . $last['min_percent'] . ' %';
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Notenschlüssel</h1>
        <p class="page-sub">Je Klassenstufe gilt ein Standard. Einzelne Kurse können einen anderen Schlüssel wählen.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= e($base . '/neu') ?>"><?= icon('plus') ?> Neue Vorlage</a>
    </div>
</div>

<h2>Standard je Klassenstufe</h2>
<div class="table-wrap mb-2">
    <table class="data-table">
        <thead>
        <tr>
            <th>Klasse</th>
            <th>Notenschlüssel</th>
            <th>Typ</th>
            <th>Grenzen</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($levels as $level): ?>
            <?php $scale = $byLevel[$level] ?? null; ?>
            <tr>
                <td><strong><?= e((string) $level) ?></strong></td>
                <?php if ($scale !== null): ?>
                    <td><?= e($scale['name']) ?></td>
                    <td><span class="badge"><?= e($types[$scale['type']] ?? $scale['type']) ?></span></td>
                    <td class="text-sm text-soft"><?= e($summary($scale)) ?></td>
                    <td><div class="row-actions"><a class="btn btn-sm btn-ghost" href="<?= e($base . '/' . (int) $scale['id']) ?>">Bearbeiten</a></div></td>
                <?php else: ?>
                    <td colspan="3" class="text-faint">Noch kein Standard: Klausuren dieser Stufe können nicht benotet werden.</td>
                    <td><div class="row-actions"><a class="btn btn-sm btn-ghost" href="<?= e($base . '/neu?stufe=' . $level) ?>">Anlegen</a></div></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<h2>Vorlagen</h2>
<?php if ($templates === []): ?>
    <p class="text-soft">Es gibt keine zusätzlichen Vorlagen. Eine Vorlage ist ein Schlüssel ohne feste Klassenstufe, den einzelne Kurse wählen können.</p>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Name</th>
                <th>Typ</th>
                <th>Grenzen</th>
                <th>Kurse</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($templates as $scale): ?>
                <tr>
                    <td><?= e($scale['name']) ?></td>
                    <td><span class="badge"><?= e($types[$scale['type']] ?? $scale['type']) ?></span></td>
                    <td class="text-sm text-soft"><?= e($summary($scale)) ?></td>
                    <td><?= e((string) ($usage[(int) $scale['id']] ?? 0)) ?></td>
                    <td><div class="row-actions"><a class="btn btn-sm btn-ghost" href="<?= e($base . '/' . (int) $scale['id']) ?>">Bearbeiten</a></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
