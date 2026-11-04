<?php
/**
 * Zugangskarten drucken.
 * Erwartet: $courseOptions, $course, $members, $search, $found, $searchLimit, $importCount, $maxCards
 */

$base = $ctx->url('/admin/zugangskarten');
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Zugangskarten</h1>
        <p class="page-sub">Karten mit Name, Benutzername, Passwort und QR-Code. 8 Karten je A4-Seite.</p>
    </div>
</div>

<div class="alert alert-warning" role="note">
    <div>
        <strong>Achtung: Beim Drucken werden neue Passwörter erzeugt.</strong>
        Alte Karten der ausgewählten Personen sind danach sofort ungültig. Das PDF enthält die Passwörter im Klartext und
        wird nirgends gespeichert. Drucke es gleich aus und lösche die Datei danach.
    </div>
</div>

<?php if ($importCount > 0): ?>
    <div class="card card-pad mb-2">
        <h3 class="mt-0">Zuletzt importierte Personen (<?= e((string) $importCount) ?>)</h3>
        <form method="post" action="<?= e($base) ?>" class="stack">
            <?= $csrf->field() ?>
            <input type="hidden" name="quelle" value="import">
            <label class="checkbox-row">
                <input type="checkbox" name="bestaetigt" value="1" required>
                <span>Ich weiß, dass dabei neue Passwörter erzeugt und alte Karten ungültig werden.</span>
            </label>
            <div><button class="btn btn-primary" type="submit">Karten für den letzten Import drucken</button></div>
        </form>
    </div>
<?php endif; ?>

<form id="cards-form" method="post" action="<?= e($base) ?>" data-cards-form>
    <?= $csrf->field() ?>
    <?php if ($course !== null): ?>
        <input type="hidden" name="kurs_id" value="<?= e((string) (int) $course['id']) ?>">
    <?php endif; ?>

    <div class="grid-2 mb-2">
        <div class="card card-pad">
            <h3 class="mt-0">1. Kurs wählen</h3>
            <div class="field">
                <label for="kurs-select">Kurs</label>
                <select id="kurs-select" data-course-jump data-base="<?= e($base) ?>">
                    <option value="">Bitte wählen</option>
                    <?php foreach ($courseOptions as $option): ?>
                        <option value="<?= e((string) (int) $option['id']) ?>"<?= $course !== null && (int) $course['id'] === (int) $option['id'] ? ' selected' : '' ?>>
                            <?= e($option['name']) ?> (<?= e($option['school_year']) ?>, <?= e((string) (int) $option['member_count']) ?> Personen)
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><div class="hint">Ohne JavaScript: <a href="<?= e($base) ?>?kurs=1">?kurs=ID</a> an die Adresse anhängen.</div></noscript>
            </div>
            <?php if ($course !== null): ?>
                <div class="cluster space-between">
                    <strong><?= e($course['name']) ?></strong>
                    <button class="btn btn-sm btn-ghost" type="button" data-toggle-all="[data-card-pick]">Alle an/aus</button>
                </div>
                <?php if ($members === []): ?>
                    <p class="text-soft text-sm">Dieser Kurs hat keine aktiven Schüler:innen.</p>
                <?php else: ?>
                    <div class="checkbox-list pick-list">
                        <?php foreach ($members as $member): ?>
                            <label>
                                <input type="checkbox" name="user_ids[]" value="<?= e((string) (int) $member['id']) ?>" checked
                                       data-card-pick data-label="<?= e(display_name($member)) ?>">
                                <span><?= e(display_name($member)) ?>
                                    <span class="text-faint text-sm mono"><?= e($member['username']) ?></span>
                                    <?php if ($member['card_issued_at'] !== null): ?>
                                        <span class="badge" title="Letzte Karte vom <?= e(format_date($member['card_issued_at'])) ?>">hat Karte</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">keine Karte</span>
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="card card-pad">
            <h3 class="mt-0">Oder einzelne Personen</h3>
            <p class="text-sm text-soft">Suche unten nach Namen und hake an. Die Auswahl bleibt beim Weitersuchen erhalten.</p>
            <div data-card-selection class="chip-row" aria-live="polite"></div>
            <div data-card-hidden></div>
        </div>
    </div>

    <div class="card card-pad mb-2">
        <label class="checkbox-row">
            <input type="checkbox" name="bestaetigt" value="1" required>
            <span>Ich weiß, dass dabei neue Passwörter erzeugt werden und die alten Karten dieser Personen ungültig werden.</span>
        </label>
        <div class="cluster mt-2">
            <button class="btn btn-primary" type="submit" data-confirm="Neue Passwörter erzeugen und Karten als PDF ausgeben? Alte Karten werden ungültig.">
                🪪 Karten drucken
            </button>
            <span class="text-sm text-soft">Höchstens <?= e((string) $maxCards) ?> Karten auf einmal.</span>
        </div>
    </div>
</form>

<form class="card card-pad mb-2" method="get" action="<?= e($base) ?>" data-live="cards-search">
    <?php if ($course !== null): ?>
        <input type="hidden" name="kurs" value="<?= e((string) (int) $course['id']) ?>">
    <?php endif; ?>
    <div class="field mb-0">
        <label for="q">Einzelne Personen suchen</label>
        <input class="input" type="search" id="q" name="q" value="<?= e($search) ?>" autocomplete="off" placeholder="Name oder Benutzername">
    </div>
</form>

<div data-live-target="cards-search">
<?php if ($search !== ''): ?>
    <?php if ($found === []): ?>
        <p class="text-soft">Keine Treffer für „<?= e($search) ?>“.</p>
    <?php else: ?>
        <div class="card card-pad">
            <div class="checkbox-list pick-list">
                <?php foreach ($found as $person): ?>
                    <label>
                        <input type="checkbox" name="user_ids[]" form="cards-form" value="<?= e((string) (int) $person['id']) ?>"
                               data-card-pick data-label="<?= e(display_name($person)) ?>">
                        <span><?= e(display_name($person)) ?>
                            <span class="text-faint text-sm mono"><?= e($person['username']) ?></span>
                            <?php if (($person['courses'] ?? '') !== ''): ?>
                                <span class="text-sm text-soft">· <?= e($person['courses']) ?></span>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php if (count($found) >= $searchLimit): ?>
                <p class="text-sm text-soft mb-0">Es werden höchstens <?= e((string) $searchLimit) ?> Treffer gezeigt. Bitte die Suche eingrenzen.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
</div>
