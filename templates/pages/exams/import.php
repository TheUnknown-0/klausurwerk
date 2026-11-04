<?php
/** Klausur importieren (ZIP aus dem Export). */
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($ctx->url('/klausuren')) ?>">← Klausuren</a></div>
        <h1 class="page-title">⬆️ Klausur importieren</h1>
        <p class="page-sub">Lade eine ZIP-Datei hoch, die mit "Export" aus Klausurwerk erzeugt wurde. Es entsteht ein neuer Entwurf mit dir als Hauptlehrkraft.</p>
    </div>
</div>

<form class="card card-pad max-w-md" method="post" action="<?= e($ctx->url('/klausuren/import')) ?>" enctype="multipart/form-data">
    <?= $csrf->field() ?>
    <div class="field">
        <label for="datei">ZIP-Datei *</label>
        <input class="input" type="file" id="datei" name="datei" required accept=".zip,application/zip">
        <div class="hint">Enthalten sind Aufgaben, Musterlösungen, Tests, Einstellungen und Material. Personen und Ergebnisse werden nie exportiert.</div>
    </div>
    <button class="btn btn-primary" type="submit">Importieren</button>
</form>
