<?php
/**
 * Einstellungen der Anwendung.
 * Erwartet: $schoolName, $appName, $logo, $publicBaseUrl, $baseIsGuessed, $guessedBase,
 * $staffTwoFactor, $mailConfigured, $heartbeatMs, $pultPollMs, $intervalMin, $intervalMax,
 * $sitePasswordEnabled, $sitePasswordSet, $updateCheckEnabled, $updateCheckConfigured,
 * $testMailDefault, $canEdit, $isAdmin, $minPasswordLength
 */

$disabled = $canEdit ? '' : ' disabled';
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Einstellungen</h1>
        <p class="page-sub">Angaben zur Schule, Anmeldung und Takt der Live-Ansichten.</p>
    </div>
</div>

<?php if (!$canEdit): ?>
    <div class="alert alert-info">Du darfst die Einstellungen ansehen, aber nicht ändern.</div>
<?php endif; ?>

<div class="card card-pad mb-2 max-w-lg">
    <h3 class="mt-0">Schullogo</h3>
    <?php if (!empty($logo)): ?>
        <div class="cluster mb-2">
            <img class="brand-logo logo-preview" src="<?= e($ctx->url('/medien/logos/' . $logo)) ?>" alt="Aktuelles Schullogo" data-logo-current>
            <?php if ($canEdit): ?>
                <form method="post" action="<?= e($ctx->url('/admin/einstellungen/logo')) ?>" data-confirm="Logo entfernen?">
                    <?= $csrf->field() ?>
                    <input type="hidden" name="remove" value="1">
                    <button class="btn btn-sm btn-danger-ghost" type="submit">Entfernen</button>
                </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="text-soft text-sm">Noch kein Logo hinterlegt, es wird ein Platzhalter angezeigt.</p>
    <?php endif; ?>
    <?php if ($canEdit): ?>
        <form method="post" action="<?= e($ctx->url('/admin/einstellungen/logo')) ?>" enctype="multipart/form-data" class="stack">
            <?= $csrf->field() ?>
            <div class="field mb-0">
                <label for="logo">Neues Logo (PNG, JPG, WebP oder SVG, höchstens 2 MB)</label>
                <input class="input" type="file" id="logo" name="logo" accept=".png,.jpg,.jpeg,.webp,.svg" required data-logo-input>
            </div>
            <img class="logo-preview" alt="Vorschau des neuen Logos" data-logo-preview hidden>
            <div><button class="btn btn-primary" type="submit">Logo hochladen</button></div>
        </form>
    <?php endif; ?>
</div>

<form method="post" action="<?= e($ctx->url('/admin/einstellungen')) ?>">
    <?= $csrf->field() ?>

    <div class="card card-pad mb-2 max-w-lg">
        <h3 class="mt-0">Schule und Anwendung</h3>
        <div class="form-grid">
            <div class="field">
                <label for="school_name">Schulname</label>
                <input class="input" type="text" id="school_name" name="school_name" maxlength="200" value="<?= e($schoolName) ?>"<?= $disabled ?>>
                <div class="hint">Steht auf Zugangskarten und PDF-Ausdrucken.</div>
            </div>
            <div class="field">
                <label for="app_name">Name der Anwendung</label>
                <input class="input" type="text" id="app_name" name="app_name" maxlength="100" value="<?= e($appName) ?>" placeholder="Klausurwerk"<?= $disabled ?>>
                <div class="hint">Titel in Seitenleiste und Browser-Tab (Standard: Klausurwerk).</div>
            </div>
        </div>
        <div class="field">
            <label for="public_base_url">Öffentliche Adresse</label>
            <input class="input" type="url" id="public_base_url" name="public_base_url" maxlength="255" value="<?= e($publicBaseUrl) ?>"
                   placeholder="https://klausuren.meine-schule.de"<?= $disabled ?>>
            <div class="hint">
                Wird für den QR-Code auf den Zugangskarten genutzt (Adresse/login).
                <?php if ($baseIsGuessed): ?>
                    Nicht hinterlegt: aktuell wird <code><?= e($guessedBase) ?></code> verwendet, das ist die Adresse dieses Aufrufs.
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card card-pad mb-2 max-w-lg">
        <h3 class="mt-0">Anmeldung von Lehrkräften</h3>
        <label class="checkbox-row">
            <input type="checkbox" name="staff_two_factor" value="1"<?= $staffTwoFactor ? ' checked' : '' ?><?= $disabled ?>>
            <span>Anmeldecode per E-Mail für Lehrkräfte und Admins (zweiter Faktor)</span>
        </label>
        <?php if ($mailConfigured): ?>
            <p class="text-sm text-soft"><span class="badge badge-success">Mailserver eingerichtet</span> Der Code wird nach dem Passwort per E-Mail verschickt. Konten ohne E-Mail-Adresse melden sich ohne Code an.</p>
        <?php else: ?>
            <div class="alert alert-warning mb-0">
                <div>
                    <strong>Kein Mailserver eingerichtet.</strong>
                    Der Anmeldecode ist nur wirksam, wenn <code>MAIL_HOST</code> und <code>MAIL_FROM_ADDRESS</code> in der Konfiguration (.env) gesetzt sind.
                    Bis dahin melden sich Lehrkräfte nur mit Passwort an.
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card card-pad mb-2 max-w-lg">
        <h3 class="mt-0">KI-Assistent</h3>
        <p class="text-sm mb-0">
            <?php if ($ctx->settings->getBool(\App\Services\Ai\AiAccess::SETTING)): ?>
                <span class="badge badge-success">Eingeschaltet</span>
            <?php else: ?>
                <span class="badge">Ausgeschaltet</span>
            <?php endif; ?>
            Lehrkräfte verbinden Claude, ChatGPT und andere KI-Programme mit Klausurwerk (MCP).
            Ein- und ausschalten sowie die Anleitung: <a href="<?= e($ctx->url('/ki')) ?>">KI-Assistent</a>.
        </p>
    </div>

    <div class="card card-pad mb-2 max-w-lg">
        <h3 class="mt-0">Takt der Live-Ansichten</h3>
        <div class="form-grid">
            <div class="field">
                <label for="heartbeat_ms">Herzschlag der Schüleransicht (ms)</label>
                <input class="input w-narrow" type="number" id="heartbeat_ms" name="heartbeat_ms" required
                       min="<?= e((string) $intervalMin) ?>" max="<?= e((string) $intervalMax) ?>" step="100"
                       value="<?= e((string) $heartbeatMs) ?>"<?= $disabled ?> data-default="2000">
                <div class="hint">Wie oft ein Gerät dem Server meldet, dass es da ist (Standard 2000). Kleiner = schneller sichtbar, mehr Last.</div>
            </div>
            <div class="field">
                <label for="pult_poll_ms">Abruf im Pult (ms)</label>
                <input class="input w-narrow" type="number" id="pult_poll_ms" name="pult_poll_ms" required
                       min="<?= e((string) $intervalMin) ?>" max="<?= e((string) $intervalMax) ?>" step="100"
                       value="<?= e((string) $pultPollMs) ?>"<?= $disabled ?> data-default="1500">
                <div class="hint">Wie oft das Pult den Stand der Klasse abfragt (Standard 1500).</div>
            </div>
        </div>
        <?php if ($canEdit): ?>
            <button class="btn btn-sm btn-ghost" type="button" data-reset-intervals>Auf Standard zurücksetzen</button>
        <?php endif; ?>
    </div>

    <?php if ($isAdmin): ?>
        <div class="card card-pad mb-2 max-w-lg">
            <h3 class="mt-0">Seitenpasswort</h3>
            <p class="text-sm text-soft">
                Optionaler Vorhang vor der gesamten Anwendung: Wer die Adresse kennt, braucht trotzdem erst dieses gemeinsame Passwort.
                Nur Administrator:innen können es ändern.
            </p>
            <div class="field">
                <label for="site_password"><?= $sitePasswordSet ? 'Neues Seitenpasswort (leer = unverändert)' : 'Seitenpasswort festlegen' ?></label>
                <input class="input" type="password" id="site_password" name="site_password" autocomplete="new-password"
                       minlength="<?= e((string) $minPasswordLength) ?>">
                <div class="hint">Mindestens <?= e((string) $minPasswordLength) ?> Zeichen.</div>
            </div>
            <label class="checkbox-row">
                <input type="checkbox" name="site_password_enabled" value="1"<?= $sitePasswordEnabled ? ' checked' : '' ?>>
                <span>Seitenpasswort aktivieren</span>
            </label>
        </div>

        <div class="card card-pad mb-2 max-w-lg">
            <h3 class="mt-0">Updates</h3>
            <p class="text-sm text-soft">
                Klausurwerk prüft bei jeder Anmeldung eines Admins und einmal täglich, ob es eine neue Version gibt,
                und meldet sie nur Admins. Dazu ruft der Server die Datei CHANGELOG.md auf GitHub ab; übertragen
                wird dabei nichts außer der Anfrage selbst. Eingespielt wird ein Update nie von allein, sondern
                auf dem Server mit <code>sudo ./update.sh</code>.
            </p>
            <?php if ($updateCheckConfigured): ?>
                <label class="checkbox-row">
                    <input type="checkbox" name="update_check_enabled" value="1"<?= $updateCheckEnabled ? ' checked' : '' ?>>
                    <span>Automatisch nach Updates suchen</span>
                </label>
                <div class="hint">Von Hand geht es immer: Klick auf die Versionsnummer unten links, dann „Nach neuen Versionen suchen“.</div>
            <?php else: ?>
                <input type="hidden" name="update_check_enabled" value="<?= $updateCheckEnabled ? '1' : '0' ?>">
                <div class="alert alert-info mb-0">Auf diesem Server ist die Update-Prüfung abgeschaltet (<code>UPDATE_CHECK_URL=off</code> in der .env).</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
        <div class="cluster mb-2">
            <button class="btn btn-primary" type="submit">Einstellungen speichern</button>
        </div>
    <?php endif; ?>
</form>

<?php if ($canEdit): ?>
    <form class="card card-pad max-w-lg" method="post" action="<?= e($ctx->url('/admin/einstellungen/testmail')) ?>">
        <?= $csrf->field() ?>
        <h3 class="mt-0">Test-Mail</h3>
        <p class="text-sm text-soft">Prüft, ob der E-Mail-Versand funktioniert.</p>
        <div class="field">
            <label for="test_email">Empfängeradresse</label>
            <input class="input" type="email" id="test_email" name="test_email" required value="<?= e($testMailDefault) ?>"<?= $mailConfigured ? '' : ' disabled' ?>>
        </div>
        <button class="btn btn-ghost" type="submit"<?= $mailConfigured ? '' : ' disabled title="Kein Mailserver eingerichtet"' ?>>Test-Mail senden</button>
    </form>
<?php endif; ?>
