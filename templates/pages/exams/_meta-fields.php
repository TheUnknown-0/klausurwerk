<?php
/**
 * Stammdaten-Felder (gemeinsam für Anlegen und Einstellungen).
 * Erwartet: $exam (oder null), $old, $courses, $rooms; $val-Funktion wird hier gebildet.
 */

use App\Services\ExamService;

$mv = static function (string $key, mixed $fallback = '') use ($old, $exam): string {
    if (array_key_exists($key, $old)) {
        return (string) $old[$key];
    }

    return (string) ($exam[$key] ?? $fallback);
};
$scheduledValue = $mv('scheduled_at');
if ($scheduledValue !== '' && !str_contains($scheduledValue, 'T')) {
    $ts = strtotime($scheduledValue);
    $scheduledValue = $ts ? date('Y-m-d\TH:i', $ts) : '';
}
$disabled = isset($editable) && !$editable ? ' disabled' : '';
?>
<div class="form-grid">
    <div class="field" style="grid-column: 1 / -1">
        <label for="title">Titel *</label>
        <input class="input" type="text" id="title" name="title" maxlength="200" required<?= $disabled ?>
               value="<?= e($mv('title')) ?>" placeholder="z. B. Klausur Q1: Listen und Rekursion">
    </div>
    <div class="field">
        <label for="mode">Modus *</label>
        <select id="mode" name="mode"<?= $disabled ?>>
            <?php foreach (ExamService::MODES as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $mv('mode', 'exam') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="hint">Klausur: überwacht, mit Uhr. Übung: ohne Überwachung, Uhr optional. Probe: Technik-Check ohne Aufgaben.</div>
    </div>
    <div class="field">
        <label for="course_id">Kurs</label>
        <select id="course_id" name="course_id"<?= $disabled ?>>
            <option value="">(noch kein Kurs)</option>
            <?php foreach ($courses as $c): ?>
                <option value="<?= e($c['id']) ?>" <?= $mv('course_id') === (string) $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['name'] . ' (' . $c['school_year'] . ')') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="hint">Nur Mitglieder des Kurses können teilnehmen.</div>
    </div>
    <div class="field">
        <label for="duration_minutes">Dauer in Minuten</label>
        <input class="input" type="number" id="duration_minutes" name="duration_minutes" min="1" max="600"<?= $disabled ?>
               value="<?= e($mv('duration_minutes')) ?>" placeholder="z. B. 90">
        <div class="hint">Bei Übungen darf die Dauer leer bleiben (ohne Zeitlimit).</div>
    </div>
    <div class="field">
        <label for="room_id">Raum</label>
        <select id="room_id" name="room_id"<?= $disabled ?>>
            <option value="">(kein Raum)</option>
            <?php foreach ($rooms as $r): ?>
                <option value="<?= e($r['id']) ?>" <?= $mv('room_id') === (string) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="scheduled_at">Geplanter Termin</label>
        <input class="input" type="datetime-local" id="scheduled_at" name="scheduled_at"<?= $disabled ?> value="<?= e($scheduledValue) ?>">
    </div>
    <div class="field" style="grid-column: 1 / -1">
        <label for="description">Beschreibung (intern)</label>
        <textarea class="input" id="description" name="description" rows="3" maxlength="5000"<?= $disabled ?>><?= e($mv('description')) ?></textarea>
    </div>
</div>
