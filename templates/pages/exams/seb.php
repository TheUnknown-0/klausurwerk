<?php
/**
 * SEB-Datei erzeugen. Erwartet: $exam, $publicBase, $baseGuessed.
 */

$id = (int) $exam['id'];
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($ctx->url('/klausuren/' . $id)) ?>">← <?= e($exam['title']) ?></a></div>
        <h1 class="page-title">🔒 SEB-Datei</h1>
        <p class="page-sub">Konfigurationsdatei für den Safe Exam Browser. Schüler:innen öffnen die Datei, SEB startet im Kiosk-Modus und lädt die Klausur.</p>
    </div>
</div>

<?php if ($baseGuessed): ?>
    <div class="alert alert-warning">Die öffentliche Adresse der Anwendung ist nicht festgelegt. Die Datei verwendet deshalb die Adresse deines Browsers (<code class="mono"><?= e($publicBase) ?></code>). Trage sie unter Einstellungen ein, wenn Schüler:innen die Anwendung unter einer anderen Adresse erreichen.</div>
<?php endif; ?>

<form class="card card-pad max-w-lg" method="post" action="<?= e($ctx->url('/klausuren/' . $id . '/seb')) ?>">
    <?= $csrf->field() ?>
    <dl class="exam-facts">
        <dt>Start-Adresse</dt><dd class="mono"><?= e($publicBase . '/klausur/' . $id) ?></dd>
        <dt>Beenden-Adresse</dt><dd class="mono"><?= e($publicBase . '/seb-beenden') ?></dd>
    </dl>
    <div class="field">
        <label for="quit_password">Passwort zum Beenden von SEB</label>
        <input class="input" type="text" id="quit_password" name="quit_password" maxlength="100" autocomplete="off" aria-describedby="qp-h">
        <div class="hint" id="qp-h">Wird nur als SHA-256-Hash in der Datei abgelegt. Leer lassen: SEB lässt sich dann ohne Passwort beenden. Notiere das Passwort, es lässt sich nicht wiederherstellen.</div>
    </div>
    <details class="field">
        <summary>Erweitert: Config Key manuell festlegen</summary>
        <label for="manual_key">Config Key (64 Zeichen, Hex)</label>
        <input class="input mono" type="text" id="manual_key" name="manual_key" maxlength="64" pattern="[0-9a-fA-F]{64}" autocomplete="off" aria-describedby="mk-h">
        <div class="hint" id="mk-h">SEB berechnet den Config Key aus der geladenen Datei inklusive eigener Standardwerte. Weicht der von SEB angezeigte Key vom berechneten ab, kannst du ihn hier eintragen.</div>
    </details>
    <?php if (!empty($exam['seb_config_key'])): ?>
        <p class="text-sm text-soft">Aktueller Config Key: <code class="mono"><?= e($exam['seb_config_key']) ?></code></p>
    <?php endif; ?>
    <?php if (empty($exam['settings_array']['require_seb'])): ?>
        <div class="alert alert-info">Die Einstellung "Nur mit Safe Exam Browser" ist für diese Klausur aus. Die Datei lässt sich trotzdem erzeugen.</div>
    <?php endif; ?>
    <button class="btn btn-primary" type="submit">⬇️ .seb-Datei herunterladen</button>
</form>
