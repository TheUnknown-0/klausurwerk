<?php
/**
 * Einstellungen einer Klausur.
 * Erwartet: $exam, $old, $courses, $rooms, $settings (normalisiert), $editable, $profiles,
 * $activeProfile (Schlüssel oder null), $advancedOpen (Erweitert aufgeklappt).
 *
 * Aufbau: Profile zum Schnellstart, dann das Wichtige (Stammdaten, Überwachung und Abgabe,
 * Editor-Hilfen und Hilfsmittel), Feineinstellungen eingeklappt unter "Erweitert".
 */

$id = (int) $exam['id'];
$sharedChecked = array_key_exists('shared_in_archive', $old) ? !empty($old['shared_in_archive']) : (int) $exam['shared_in_archive'] === 1;
$disabled = $editable ? '' : ' disabled';

/** Ein Schalter mit Erklärung; $path z. B. ['lock_on_violation'] oder ['editor', 'lint']. */
$toggle = static function (array $path, string $label, string $help) use ($settings, $disabled): string {
    $value = $settings;
    foreach ($path as $p) {
        $value = $value[$p] ?? false;
    }
    $name = 'settings' . implode('', array_map(static fn (string $p): string => '[' . $p . ']', $path));
    $inputId = 's-' . implode('-', $path);

    return '<div class="setting-row"><label class="checkbox-row" for="' . e($inputId) . '">'
        . '<input type="hidden" name="' . e($name) . '" value="0">'
        . '<input type="checkbox" id="' . e($inputId) . '" name="' . e($name) . '" value="1"' . ($value ? ' checked' : '') . $disabled . ' aria-describedby="' . e($inputId) . '-h"> '
        . '<strong>' . e($label) . '</strong></label>'
        . '<div class="hint" id="' . e($inputId) . '-h">' . e($help) . '</div></div>';
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a href="<?= e($ctx->url('/klausuren/' . $id)) ?>">← <?= e($exam['title']) ?></a></div>
        <h1 class="page-title">Einstellungen</h1>
    </div>
</div>

<?php if (!$editable): ?>
    <div class="alert alert-info">Die Klausur ist gestartet oder abgeschlossen. Alle Einstellungen sind gesperrt und nur noch lesbar, damit alle Teilnehmenden unter gleichen Bedingungen arbeiten.</div>
<?php endif; ?>

<?php if ($editable): ?>
    <section class="card card-pad settings-card settings-profiles" aria-labelledby="profiles-title">
        <h2 class="card-title" id="profiles-title">Schnellstart mit Profil</h2>
        <p class="card-lead">Ein Klick setzt die wichtigen Schalter. Feineinstellungen wie Java-Version oder Schriftgröße bleiben, wie sie sind.</p>
        <div class="profile-grid">
            <?php foreach ($profiles as $key => $p): ?>
                <form method="post" action="<?= e($ctx->url('/klausuren/' . $id . '/einstellungen/profil')) ?>" class="profile-card<?= $activeProfile === $key ? ' is-active' : '' ?>"
                      data-confirm="<?= e('Profil "' . $p['label'] . '" anwenden? Nicht gespeicherte Änderungen auf dieser Seite gehen verloren.') ?>">
                    <?= $csrf->field() ?>
                    <input type="hidden" name="profile" value="<?= e($key) ?>">
                    <div class="profile-head">
                        <strong><?= e($p['label']) ?></strong>
                        <?php if ($activeProfile === $key): ?><span class="badge badge-primary">aktiv</span><?php endif; ?>
                    </div>
                    <p class="text-sm text-soft"><?= e($p['description']) ?></p>
                    <button class="btn btn-sm" type="submit"<?= $activeProfile === $key ? ' disabled' : '' ?>>Anwenden</button>
                </form>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<form method="post" action="<?= e($ctx->url('/klausuren/' . $id . '/einstellungen')) ?>" class="stack">
    <?= $csrf->field() ?>

    <section class="card card-pad settings-card">
        <h2 class="card-title">Stammdaten</h2>
        <p class="card-lead">Titel, Kurs, Termin und Raum der Klausur.</p>
        <?php require __DIR__ . '/_meta-fields.php'; ?>
    </section>

    <section class="card card-pad settings-card">
        <h2 class="card-title">Überwachung und Abgabe</h2>
        <p class="card-lead">Was bei Verstößen passiert, wer beitreten darf und wie abgegeben wird.</p>
        <?= $toggle(['lock_on_violation'], 'Bei Verstoß sperren', 'Verlässt jemand das Vollbild oder den Klausur-Tab, wird die Person gesperrt, bis die Lehrkraft sie freigibt.') ?>
        <div class="field">
            <label for="s-paste">Einfügen (Copy &amp; Paste)</label>
            <select id="s-paste" name="settings[paste_policy]"<?= $disabled ?>>
                <option value="internal" <?= $settings['paste_policy'] === 'internal' ? 'selected' : '' ?>>nur innerhalb der Klausur</option>
                <option value="block" <?= $settings['paste_policy'] === 'block' ? 'selected' : '' ?>>gar nicht erlaubt</option>
                <option value="allow_log" <?= $settings['paste_policy'] === 'allow_log' ? 'selected' : '' ?>>erlaubt, aber protokolliert</option>
            </select>
            <div class="hint">Einfügen von außen wird bei "nur innerhalb" blockiert und im Protokoll vermerkt.</div>
        </div>
        <?= $toggle(['require_seb'], 'Nur mit Safe Exam Browser', 'Der Beitritt gelingt nur mit einer passenden SEB-Datei (Prüfung über den Config Key). Die Datei erzeugst du unter "SEB-Datei".') ?>
        <?= $toggle(['require_access_code'], 'Klausurcode verlangen', 'Zum Beitritt wird ein 6-stelliger Code benötigt, den du im Raum nennst oder anschreibst.') ?>
        <?php if (!empty($exam['access_code'])): ?>
            <div class="code-box">
                <span class="text-soft">Klausurcode:</span>
                <strong class="mono code-value"><?= e($exam['access_code']) ?></strong>
            </div>
        <?php endif; ?>
        <?= $toggle(['allow_self_submit'], 'Abgabe-Knopf anzeigen', 'Schüler:innen können die Abgabe anfragen; die Lehrkraft bestätigt sie im Pult.') ?>
        <?= $toggle(['show_points'], 'Punkte bei den Aufgaben anzeigen', 'Schüler:innen sehen, wie viele Punkte eine Aufgabe bringt.') ?>
    </section>

    <section class="card card-pad settings-card">
        <h2 class="card-title">Editor-Hilfen und Hilfsmittel</h2>
        <p class="card-lead">Was den Schüler:innen beim Schreiben hilft. Hilfsmittel erscheinen in einer Seitenleiste; hochgeladene Materialien mit "als Hilfsmittel" kommen dazu.</p>
        <?= $toggle(['editor', 'autocomplete'], 'Autovervollständigung', 'Der Code-Editor schlägt Wörter und Schlüsselwörter vor. Für Klausuren meist aus, für Übungen an.') ?>
        <?= $toggle(['editor', 'lint'], 'Fehlermarkierung im Editor', 'Syntaxfehler werden schon beim Tippen markiert.') ?>
        <?= $toggle(['aids', 'java_api'], 'Java-API-Referenz', 'Kurzreferenz der häufigsten Java-Klassen.') ?>
        <?= $toggle(['aids', 'python_doc'], 'Python-Kurzreferenz', 'Eingebaute Funktionen und gängige Methoden.') ?>
        <?= $toggle(['aids', 'sql_cheatsheet'], 'SQL-Spickzettel', 'SELECT, JOIN, GROUP BY und weitere Grundlagen.') ?>
        <?= $toggle(['aids', 'operators'], 'Operatorenliste', 'Die Operatoren (nennen, beschreiben, erläutern ...) mit Bedeutung.') ?>
        <?= $toggle(['aids', 'calculator'], 'Taschenrechner', 'Einfacher Taschenrechner in der Seitenleiste.') ?>
    </section>

    <details class="card card-pad settings-card settings-advanced"<?= $advancedOpen ? ' open' : '' ?>>
        <summary>
            <span class="card-title">Erweitert</span>
            <span class="text-soft text-sm">Feinheiten der Überwachung, Ausführung von Code, Editor, Archiv<?= $advancedOpen ? ' (hier weicht etwas vom Standard ab)' : '' ?></span>
        </summary>

        <h3 class="settings-sub">Überwachung im Detail</h3>
        <div class="field">
            <label for="s-grace">Toleranz für kurze Fokusverluste (Millisekunden)</label>
            <input class="input" type="number" id="s-grace" name="settings[violation_grace_ms]" min="0" max="5000" step="50" value="<?= e((string) $settings['violation_grace_ms']) ?>"<?= $disabled ?>>
            <div class="hint">Fokusverluste, die kürzer sind, zählen nur als Hinweis und nicht als Verstoß (0 bis 5000).</div>
        </div>
        <?= $toggle(['toilet_pauses_clock'], 'Abwesenheit hält die Uhr an', 'Wer den Raum kurz verlässt (z. B. Toilette) und im Pult als abwesend markiert wird, behält die Zeit.') ?>
        <?= $toggle(['live_view'], 'Live-Ansicht der Klausurseite im Pult', 'Das Pult zeigt je Person die aktuelle Aufgabe, einen Code-Ausschnitt um die Cursorzeile und die letzten Konsolenzeilen (Live-Wand). Funktioniert auch im Safe Exam Browser. Es wird nichts gespeichert, nur der jeweils letzte Stand liegt im Arbeitsspeicher und wird am Klausurende gelöscht.') ?>
        <?= $toggle(['screen_share'], 'Bildschirmfreigabe verlangen (ganzer Bildschirm)', 'Der Browser fragt nach der Freigabe des ganzen Bildschirms; das Pult zeigt in der Live-Wand ein Standbild (etwa alle 5 Sekunden, bei Fokus 1 Bild pro Sekunde). Datenschutz: Das ist ein Eingriff in die Privatsphäre, teile den Schüler:innen vorab mit, dass der Bildschirm übertragen wird. Während der Übertragung sehen sie einen deutlichen Hinweis. Bilder werden nie gespeichert, nur das letzte liegt im Arbeitsspeicher und wird am Klausurende gelöscht. Beendet jemand die Freigabe oder gibt nur ein Fenster frei, gibt es einen Alarm im Pult, aber keine Sperre. Im Safe Exam Browser entfällt die Freigabe.') ?>

        <h3 class="settings-sub">Darstellung und Ausführung</h3>
        <?= $toggle(['shuffle_choices'], 'Auswahlaufgaben mischen', 'Antwortoptionen und Zuordnungen erscheinen je Person in anderer Reihenfolge.') ?>
        <div class="form-grid">
            <div class="field">
                <label for="s-run">Code ausführen</label>
                <select id="s-run" name="settings[run_mode]"<?= $disabled ?>>
                    <option value="run" <?= $settings['run_mode'] === 'run' ? 'selected' : '' ?>>Programme ausführen</option>
                    <option value="syntax" <?= $settings['run_mode'] === 'syntax' ? 'selected' : '' ?>>nur Syntax prüfen</option>
                    <option value="none" <?= $settings['run_mode'] === 'none' ? 'selected' : '' ?>>nicht ausführen</option>
                </select>
                <div class="hint">Standard für Code-Aufgaben; einzelne Aufgaben können abweichen.</div>
            </div>
            <div class="field">
                <label for="s-java">Java-Version</label>
                <select id="s-java" name="settings[java_version]"<?= $disabled ?>>
                    <option value="21" <?= $settings['java_version'] === '21' ? 'selected' : '' ?>>Java 21</option>
                    <option value="25" <?= $settings['java_version'] === '25' ? 'selected' : '' ?>>Java 25</option>
                </select>
            </div>
        </div>
        <fieldset class="field">
            <legend class="label-like">Python-Pakete zusätzlich laden</legend>
            <?php foreach (['numpy' => 'NumPy', 'matplotlib' => 'Matplotlib'] as $pkg => $label): ?>
                <label class="checkbox-row"><input type="checkbox" name="settings[python_packages][]" value="<?= e($pkg) ?>" <?= in_array($pkg, $settings['python_packages'], true) ? 'checked' : '' ?><?= $disabled ?>> <?= e($label) ?></label>
            <?php endforeach; ?>
            <div class="hint">Mehr Pakete verlängern die Ladezeit beim ersten Start von Python.</div>
        </fieldset>

        <h3 class="settings-sub">Editor</h3>
        <?= $toggle(['editor', 'close_brackets'], 'Klammern automatisch schließen', 'Beim Tippen von ( [ { " wird die schließende Klammer ergänzt.') ?>
        <div class="field">
            <label for="s-font">Schriftgröße im Editor</label>
            <input class="input" type="number" id="s-font" name="settings[editor][font_size]" min="11" max="24" value="<?= e((string) $settings['editor']['font_size']) ?>"<?= $disabled ?>>
            <div class="hint">11 bis 24 Pixel.</div>
        </div>

        <h3 class="settings-sub">Archiv</h3>
        <label class="checkbox-row" for="shared">
            <input type="hidden" name="shared_in_archive" value="0">
            <input type="checkbox" id="shared" name="shared_in_archive" value="1" <?= $sharedChecked ? 'checked' : '' ?><?= $disabled ?>>
            <strong>Im Archiv für Kolleg:innen freigeben</strong>
        </label>
        <div class="hint">Andere Lehrkräfte finden die Aufgaben dieser Klausur im Archiv und dürfen sie samt Musterlösungen in eigene Klausuren kopieren. Kopien sind unabhängig vom Original.</div>
    </details>

    <?php if ($editable): ?>
        <div class="cluster">
            <button class="btn btn-primary" type="submit">Einstellungen speichern</button>
            <a class="btn btn-ghost" href="<?= e($ctx->url('/klausuren/' . $id)) ?>">Abbrechen</a>
        </div>
    <?php endif; ?>
</form>

<?php if ($editable && !empty($settings['require_access_code'])): ?>
    <form class="mt-2" method="post" action="<?= e($ctx->url('/klausuren/' . $id . '/code')) ?>" data-confirm="Einen neuen Klausurcode erzeugen? Der alte Code wird ungültig.">
        <?= $csrf->field() ?>
        <button class="btn" type="submit"><?= icon('refresh-cw') ?>Neuen Klausurcode erzeugen</button>
    </form>
<?php endif; ?>
