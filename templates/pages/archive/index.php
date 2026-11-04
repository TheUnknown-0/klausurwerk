<?php
/**
 * Archiv. Erwartet: $rows, $filters, $page, $pages, $total, $types, $operators, $grades, $targets.
 */

$listUrl = $ctx->url('/archiv');
$pageUrl = static function (int $target) use ($listUrl, $filters): string {
    $query = array_filter([
        'q' => $filters['q'], 'sprache' => $filters['sprache'], 'typ' => $filters['typ'],
        'stufe' => $filters['stufe'] ?: '', 'operator' => $filters['operator'] ?: '',
        'seite' => $target > 1 ? (string) $target : '',
    ], static fn ($v): bool => $v !== '' && $v !== null);

    return $listUrl . ($query === [] ? '' : '?' . http_build_query($query));
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Unterricht</div>
        <h1 class="page-title">Archiv</h1>
        <p class="page-sub"><?= e((string) $total) ?> Aufgabe<?= $total === 1 ? '' : 'n' ?> aus deinen Klausuren und den für Kolleg:innen freigegebenen.</p>
    </div>
</div>

<form class="card card-pad mb-2" method="get" action="<?= e($listUrl) ?>" data-live="archiv">
    <div class="form-grid">
        <div class="field field-wide">
            <label for="a-q">Suche im Titel und Aufgabentext</label>
            <input class="input" type="search" id="a-q" name="q" value="<?= e($filters['q']) ?>" placeholder="z. B. Rekursion, Binärbaum, JOIN">
        </div>
        <div class="field">
            <label for="a-sprache">Sprache</label>
            <select id="a-sprache" name="sprache">
                <option value="">Alle</option>
                <?php foreach (['java' => 'Java', 'python' => 'Python', 'sql' => 'SQL'] as $k => $l): ?>
                    <option value="<?= e($k) ?>" <?= $filters['sprache'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="a-typ">Antworttyp</label>
            <select id="a-typ" name="typ">
                <option value="">Alle</option>
                <?php foreach ($types as $group => $items): ?>
                    <optgroup label="<?= e($group) ?>">
                        <?php foreach ($items as $name => $label): ?>
                            <option value="<?= e($name) ?>" <?= $filters['typ'] === $name ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="a-stufe">Klassenstufe</label>
            <select id="a-stufe" name="stufe">
                <option value="">Alle</option>
                <?php foreach ($grades as $g): ?>
                    <option value="<?= e($g['grade_level']) ?>" <?= (int) $filters['stufe'] === (int) $g['grade_level'] ? 'selected' : '' ?>>Klasse <?= e($g['grade_level']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="a-op">Operator</label>
            <select id="a-op" name="operator">
                <option value="">Alle</option>
                <?php foreach ($operators as $o): ?>
                    <option value="<?= e($o['id']) ?>" <?= (int) $filters['operator'] === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <noscript><button class="btn btn-primary" type="submit">Suchen</button></noscript>
</form>

<div data-live-target="archiv">
<?php if ($rows === []): ?>
    <div class="card empty-state"><div class="empty-icon" aria-hidden="true"><?= icon('archive') ?></div><p>Keine passende Aufgabe gefunden.</p></div>
<?php else: ?>
    <div class="stack">
    <?php foreach ($rows as $r): ?>
        <article class="card card-pad archive-hit">
            <header class="space-between">
                <div>
                    <strong><?= e($r['title'] !== '' ? $r['title'] : '(ohne Titel)') ?></strong>
                    <?php if ($r['parent_title'] !== null): ?><span class="text-soft"> · Teilaufgabe von "<?= e($r['parent_title']) ?>"</span><?php endif; ?>
                </div>
                <div class="cluster">
                    <?php if ($r['answer_type'] !== null): ?><span class="badge badge-primary"><?= e($r['answer_type']) ?></span><?php endif; ?>
                    <?php if ($r['operator_name']): ?><span class="badge"><?= e($r['operator_name']) ?></span><?php endif; ?>
                    <span class="badge"><?= e(format_points($r['points'])) ?> P.</span>
                </div>
            </header>
            <?php if ($r['snippet'] !== ''): ?><p class="archive-snippet"><?= e($r['snippet']) ?></p><?php endif; ?>
            <footer class="space-between text-sm text-soft">
                <span>
                    Aus <a href="<?= e($ctx->url('/klausuren/' . (int) $r['exam_id'])) ?>"><?= e($r['exam_title']) ?></a>
                    · <?= e($r['course_name'] ?? 'ohne Kurs') ?>
                    · <?= e(format_date($r['scheduled_at'] ?: $r['exam_created'])) ?>
                    <?php if ((int) $r['shared_in_archive'] === 1): ?><span class="badge badge-accent">freigegeben</span><?php endif; ?>
                </span>
                <?php if ($targets !== []): ?>
                    <form class="cluster" method="post" action="<?= e($ctx->url('/archiv/kopieren')) ?>">
                        <?= $csrf->field() ?>
                        <input type="hidden" name="task_id" value="<?= e($r['id']) ?>">
                        <label class="sr-only" for="ziel-<?= e($r['id']) ?>">Zielklausur</label>
                        <select id="ziel-<?= e($r['id']) ?>" name="target_exam_id">
                            <?php foreach ($targets as $t): ?><option value="<?= e($t['id']) ?>"><?= e($t['title']) ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm" type="submit"><?= icon('copy') ?>In Klausur kopieren</button>
                    </form>
                <?php endif; ?>
            </footer>
        </article>
    <?php endforeach; ?>
    </div>
    <?php if ($pages > 1): ?>
        <nav class="cluster mt-2" aria-label="Seiten">
            <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e($pageUrl($page - 1)) ?>">← Zurück</a><?php endif; ?>
            <span class="text-soft">Seite <?= e((string) $page) ?> von <?= e((string) $pages) ?></span>
            <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e($pageUrl($page + 1)) ?>">Weiter →</a><?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
<?php if ($targets === [] && $rows !== []): ?>
    <p class="text-sm text-soft mt-2">Zum Kopieren brauchst du einen Entwurf oder eine bereite Klausur, die du bearbeiten darfst.</p>
<?php endif; ?>
</div>
