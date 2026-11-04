<?php
/**
 * Kurs anlegen/bearbeiten.
 * Erwartet: $course (null = neu), $values, $isNew, $staff, $scales, $canChooseOwner, $levels
 */

$base = $ctx->url('/admin/kurse');
$action = $isNew ? $base . '/neu' : $base . '/' . (int) $course['id'] . '/bearbeiten';
$val = static fn (string $key, string $default = ''): string => (string) ($values[$key] ?? $default);
$teacherIds = array_map('intval', is_array($values['teacher_ids'] ?? null) ? $values['teacher_ids'] : []);
$staffName = static fn (array $u): string => trim((string) $u['lastname'] . ', ' . (string) $u['firstname'], ', ') . ' (' . $u['username'] . ')';
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($isNew ? $base : $base . '/' . (int) $course['id']) ?>">← <?= $isNew ? 'Kurse' : 'Kurs' ?></a></div>
        <h1 class="page-title"><?= e($title) ?></h1>
    </div>
</div>

<form class="card card-pad max-w-lg" method="post" action="<?= e($action) ?>">
    <?= $csrf->field() ?>
    <div class="form-grid">
        <div class="field">
            <label for="name">Kursname</label>
            <input class="input" type="text" id="name" name="name" maxlength="100" required value="<?= e($val('name')) ?>" placeholder="z. B. 11 Info LK">
        </div>
        <div class="field">
            <label for="grade_level">Klassenstufe</label>
            <select id="grade_level" name="grade_level" required>
                <option value="">Bitte wählen</option>
                <?php foreach ($levels as $level): ?>
                    <option value="<?= e((string) $level) ?>"<?= $val('grade_level') === (string) $level ? ' selected' : '' ?>>Klasse <?= e((string) $level) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="school_year">Schuljahr</label>
            <input class="input" type="text" id="school_year" name="school_year" maxlength="7" required pattern="\d{4}/\d{2}"
                   value="<?= e($val('school_year')) ?>" placeholder="2026/27">
            <div class="hint">Format 2026/27.</div>
        </div>
        <div class="field">
            <label for="subject">Fach</label>
            <input class="input" type="text" id="subject" name="subject" maxlength="60" value="<?= e($val('subject', 'Informatik')) ?>">
        </div>
        <div class="field">
            <label for="owner_id">Hauptlehrkraft</label>
            <?php if ($canChooseOwner): ?>
                <select id="owner_id" name="owner_id">
                    <option value="">Keine</option>
                    <?php foreach ($staff as $person): ?>
                        <option value="<?= e((string) (int) $person['id']) ?>"<?= $val('owner_id') === (string) $person['id'] ? ' selected' : '' ?>><?= e($staffName($person)) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input class="input" type="text" value="<?= $isNew ? e(display_name($auth->user() ?? [])) : e($val('owner_id') !== '' ? 'wie bisher' : 'keine') ?>" disabled>
                <div class="hint"><?= $isNew ? 'Du wirst Hauptlehrkraft.' : 'Die Hauptlehrkraft ändern nur Admins.' ?></div>
            <?php endif; ?>
        </div>
        <div class="field">
            <label for="grading_scale_id">Notenschlüssel</label>
            <select id="grading_scale_id" name="grading_scale_id">
                <option value="">Standard der Klassenstufe</option>
                <?php foreach ($scales as $scale): ?>
                    <option value="<?= e((string) (int) $scale['id']) ?>"<?= $val('grading_scale_id') === (string) $scale['id'] ? ' selected' : '' ?>>
                        <?= e($scale['name']) ?><?= $scale['grade_level'] !== null
                            ? (stripos((string) $scale['name'], 'Klasse ' . (int) $scale['grade_level']) === false ? ' (Standard Klasse ' . e((string) (int) $scale['grade_level']) . ')' : ' (Standard)')
                            : ' (Vorlage)' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="hint">Nur wählen, wenn dieser Kurs vom Standard abweicht.</div>
        </div>
    </div>

    <fieldset class="fieldset">
        <legend>Weitere Lehrkräfte (Vertretung, Team-Teaching)</legend>
        <?php if ($staff === []): ?>
            <p class="text-soft text-sm">Es gibt keine weiteren Lehrkräfte.</p>
        <?php else: ?>
            <div class="checkbox-list course-pick">
                <?php foreach ($staff as $person): ?>
                    <label>
                        <input type="checkbox" name="teacher_ids[]" value="<?= e((string) (int) $person['id']) ?>"<?= in_array((int) $person['id'], $teacherIds, true) ? ' checked' : '' ?>>
                        <span><?= e($staffName($person)) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </fieldset>

    <?php if (!$isNew): ?>
        <label class="checkbox-row">
            <input type="checkbox" name="is_archived" value="1"<?= $val('is_archived') === '1' ? ' checked' : '' ?>>
            <span>Kurs ist archiviert (taucht in Auswahllisten nicht mehr auf)</span>
        </label>
    <?php endif; ?>

    <div class="cluster mt-2">
        <button class="btn btn-primary" type="submit"><?= $isNew ? 'Kurs anlegen' : 'Änderungen speichern' ?></button>
        <a class="btn btn-ghost" href="<?= e($isNew ? $base : $base . '/' . (int) $course['id']) ?>">Abbrechen</a>
    </div>
</form>
