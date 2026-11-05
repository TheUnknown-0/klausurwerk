<?php
/**
 * Klausur anlegen. Erwartet: $exam (null), $old, $courses, $rooms, $profiles, $templates.
 */

use App\Services\ExamService;

$val = static fn (string $key, mixed $fallback = ''): string => (string) ($old[$key] ?? $fallback);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a href="<?= e($ctx->url('/klausuren')) ?>">← Klausuren</a></div>
        <h1 class="page-title">Neue Klausur</h1>
        <p class="page-sub">Nötig sind Titel, Modus und bei Klausuren die Dauer. Danach geht es direkt zu den Aufgaben; alles andere lässt sich später ändern.</p>
    </div>
</div>

<form class="card card-pad max-w-lg" method="post" action="<?= e($ctx->url('/klausuren/neu')) ?>" data-exam-form>
    <?= $csrf->field() ?>
    <?php require __DIR__ . '/_meta-fields.php'; ?>
    <?php $tpl = $val('template'); ?>
    <div class="form-grid mt-2">
        <div class="field field-wide">
            <label for="template">Einstellungen</label>
            <select id="template" name="template" aria-describedby="template-h">
                <option value="">Standard für den gewählten Modus</option>
                <optgroup label="Profil">
                    <?php foreach ($profiles as $key => $p): ?>
                        <option value="<?= e('profil:' . $key) ?>" <?= $tpl === 'profil:' . $key ? 'selected' : '' ?>><?= e($p['label']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php if ($templates !== []): ?>
                    <optgroup label="Wie eine frühere Klausur">
                        <?php foreach ($templates as $t): ?>
                            <option value="<?= e('klausur:' . $t['id']) ?>" <?= $tpl === 'klausur:' . $t['id'] ? 'selected' : '' ?>>
                                <?= e($t['title'] . ($t['course'] !== null || $t['date'] !== null ? ' (' . implode(', ', array_filter([$t['course'], $t['date']])) . ')' : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
            <div class="hint" id="template-h">
                <?php foreach ($profiles as $p): ?>
                    <strong><?= e($p['label']) ?>:</strong> <?= e($p['description']) ?><br>
                <?php endforeach; ?>
                Alle Schalter lassen sich danach unter „Einstellungen“ ändern.
            </div>
        </div>
        <?php if ($templates !== []): ?>
            <div class="field field-wide">
                <label class="checkbox-row" for="copy_tasks">
                    <input type="checkbox" id="copy_tasks" name="copy_tasks" value="1" <?= $val('copy_tasks') === '1' ? 'checked' : '' ?>>
                    <strong>Aufgaben, Musterlösungen und Material mitkopieren</strong>
                </label>
                <div class="hint">Nur bei „Wie eine frühere Klausur“: Die neue Klausur startet mit einer unabhängigen Kopie der Aufgaben.</div>
            </div>
        <?php endif; ?>
    </div>
    <div class="cluster">
        <button class="btn btn-primary" type="submit">Klausur anlegen</button>
        <a class="btn btn-ghost" href="<?= e($ctx->url('/klausuren')) ?>">Abbrechen</a>
    </div>
</form>
