<?php
/**
 * Benutzer anlegen/bearbeiten.
 * Erwartet: $user (null = neu), $values, $isNew, $assignable, $selectedCourses, $lockedCourses,
 * $generatedPassword, $roleLabels, $isAdmin, $isSelf, $canAccommodation, $canCards, $canReset,
 * $canDelete, $canSave, $mustPickCourse, $minPasswordLength
 */

$base = $ctx->url('/admin/benutzer');
$action = $isNew ? $base . '/neu' : $base . '/' . (int) $user['id'];
$role = (string) ($values['role'] ?? 'student');
$isStudent = $role === 'student';
$roleFixed = !$isNew && $user['role'] === 'student';
$val = static fn (string $key, string $default = ''): string => (string) ($values[$key] ?? $default);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($base) ?>">← Benutzer</a></div>
        <h1 class="page-title"><?= e($title) ?></h1>
        <?php if (!$isNew): ?>
            <p class="page-sub">
                <span class="badge"><?= e($roleLabels[$user['role']] ?? $user['role']) ?></span>
                <span class="mono"><?= e($user['username']) ?></span>
                <?php if ($isSelf): ?><span class="badge badge-info">Du</span><?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</div>

<?php if ($generatedPassword !== null): ?>
    <div class="alert alert-warning generated-password" role="alert">
        <div>
            <strong>Neues Passwort (wird nur jetzt einmal angezeigt):</strong>
            <div class="mono generated-password-value"><?= e($generatedPassword) ?></div>
            <div class="text-sm">Gib es der Person sicher weiter. Beim nächsten Login muss sie ein eigenes Passwort wählen.</div>
        </div>
    </div>
<?php endif; ?>

<?php if (!$isNew && $isStudent && $user['card_issued_at'] === null): ?>
    <div class="alert alert-info">
        Diese Schüler:in hat noch keine Zugangskarte und kann sich noch nicht anmelden.
        <?php if ($canCards): ?>Unten kannst du die Karte drucken.<?php endif; ?>
    </div>
<?php endif; ?>

<form class="card card-pad mb-2 max-w-lg" method="post" action="<?= e($action) ?>" data-user-form
      data-suggest-url="<?= e($ctx->url('/api/benutzer/benutzername')) ?>"
      data-username-auto="<?= $isNew ? '1' : '0' ?>">
    <?= $csrf->field() ?>

    <div class="form-grid">
        <?php if ($isNew && $isAdmin): ?>
            <div class="field">
                <label for="role">Rolle</label>
                <select id="role" name="role" data-role-select>
                    <?php foreach ($roleLabels as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $role === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="hint">Lehrkräfte und Admins legen nur Administrator:innen an.</div>
            </div>
        <?php elseif (!$isNew && !$roleFixed && $isAdmin): ?>
            <div class="field">
                <label for="role">Rolle</label>
                <select id="role" name="role" data-role-select<?= $isSelf ? ' disabled' : '' ?>>
                    <option value="teacher"<?= $role === 'teacher' ? ' selected' : '' ?>><?= e($roleLabels['teacher']) ?></option>
                    <option value="admin"<?= $role === 'admin' ? ' selected' : '' ?>><?= e($roleLabels['admin']) ?></option>
                </select>
                <?php if ($isSelf): ?>
                    <input type="hidden" name="role" value="<?= e($role) ?>">
                    <div class="hint">Die eigene Rolle kannst du nicht ändern.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="firstname">Vorname</label>
            <input class="input" type="text" id="firstname" name="firstname" maxlength="100"
                   value="<?= e($val('firstname')) ?>" autocomplete="off" data-name-part>
        </div>
        <div class="field">
            <label for="lastname">Nachname</label>
            <input class="input" type="text" id="lastname" name="lastname" maxlength="100"
                   value="<?= e($val('lastname')) ?>" autocomplete="off" data-name-part>
        </div>
        <div class="field">
            <label for="username">Benutzername</label>
            <input class="input mono" type="text" id="username" name="username" maxlength="100"
                   value="<?= e($val('username')) ?>" autocomplete="off" autocapitalize="none" spellcheck="false"
                   data-username-input>
            <div class="hint" data-username-hint>
                <?php if ($isNew): ?>
                    Bei Schüler:innen wird er aus dem Namen vorgeschlagen (vorname.nachname). Leer lassen, dann wird einer erzeugt.
                <?php else: ?>
                    Buchstaben, Zahlen, Punkt, Minus, Unterstrich (3 bis 100 Zeichen).
                    <?php if ($isStudent): ?>Ändert sich der Name, passt die gedruckte Karte nicht mehr.<?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php /* Bereich Lehrkraft / Admin */ ?>
    <div data-role-section="staff"<?= $isStudent ? ' hidden' : '' ?>>
        <hr class="divider">
        <div class="form-grid">
            <div class="field">
                <label for="email">E-Mail-Adresse</label>
                <input class="input" type="email" id="email" name="email" maxlength="255" value="<?= e($val('email')) ?>" autocomplete="off">
                <div class="hint">Wird für den Anmeldecode per E-Mail gebraucht. Ohne Adresse läuft die Anmeldung ohne Code.</div>
            </div>
            <div class="field">
                <label for="password"><?= $isNew ? 'Passwort' : 'Neues Passwort (leer = unverändert)' ?></label>
                <div class="input-group">
                    <input class="input" type="password" id="password" name="password" autocomplete="new-password"
                           minlength="<?= e((string) $minPasswordLength) ?>">
                    <button class="input-icon-btn" type="button" data-toggle-password="password" aria-label="Passwort anzeigen" aria-pressed="false">👁</button>
                </div>
                <div class="hint">Mindestens <?= e((string) $minPasswordLength) ?> Zeichen.</div>
            </div>
        </div>
        <div class="stack">
            <label class="checkbox-row">
                <input type="checkbox" name="generate_password" value="1"<?= $val('generate_password') === '1' ? ' checked' : '' ?>>
                <span>Passwort automatisch erzeugen (wird danach einmalig angezeigt)</span>
            </label>
            <label class="checkbox-row">
                <input type="checkbox" name="must_change_password" value="1"<?= $isNew || $val('must_change_password') === '1' ? ' checked' : '' ?>>
                <span>Beim nächsten Login ein neues Passwort wählen lassen</span>
            </label>
        </div>
    </div>

    <?php /* Bereich Schüler:in */ ?>
    <div data-role-section="student"<?= $isStudent ? '' : ' hidden' ?>>
        <hr class="divider">
        <fieldset class="fieldset">
            <legend>Kurse<?= $mustPickCourse && $isNew ? ' (mindestens einer)' : '' ?></legend>
            <?php if ($assignable === [] && $lockedCourses === []): ?>
                <p class="text-soft text-sm">
                    Du hast noch keinen Kurs, den du bearbeiten darfst.
                    <a href="<?= e($ctx->url('/admin/kurse/neu')) ?>">Kurs anlegen</a>
                </p>
            <?php else: ?>
                <div class="checkbox-list course-pick">
                    <?php foreach ($assignable as $course): ?>
                        <?php $cid = (int) $course['id']; ?>
                        <label>
                            <input type="checkbox" name="course_ids[]" value="<?= e((string) $cid) ?>"<?= in_array($cid, $selectedCourses, true) ? ' checked' : '' ?>>
                            <span><?= e($course['name']) ?> <span class="text-faint text-sm">(<?= e($course['school_year']) ?>)</span></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($lockedCourses as $course): ?>
                    <div class="text-sm text-soft">Außerdem in <?= e($course['name']) ?> (nicht von dir verwaltet).</div>
                <?php endforeach; ?>
            <?php endif; ?>
        </fieldset>

        <?php if ($canAccommodation): ?>
            <div class="form-grid mt-2">
                <div class="field">
                    <label for="accommodation_percent">Nachteilsausgleich (Prozent Zusatzzeit)</label>
                    <input class="input w-narrow" type="number" id="accommodation_percent" name="accommodation_percent"
                           min="0" max="100" step="1" value="<?= e($val('accommodation_percent', '0')) ?>">
                    <div class="hint">Zum Beispiel 25 für 25 % mehr Zeit. 0 = keiner.</div>
                </div>
                <div class="field">
                    <label for="accommodation_note">Notiz zum Nachteilsausgleich</label>
                    <input class="input" type="text" id="accommodation_note" name="accommodation_note" maxlength="255"
                           value="<?= e($val('accommodation_note')) ?>">
                    <div class="hint">Nur für Lehrkräfte sichtbar.</div>
                </div>
            </div>
        <?php elseif (!$isNew): ?>
            <p class="text-sm text-soft mt-2">
                Nachteilsausgleich: <?= (int) $user['accommodation_percent'] > 0 ? '+' . e((string) (int) $user['accommodation_percent']) . ' %' : 'keiner' ?>
                (Änderungen brauchen das Recht „Nachteilsausgleich festlegen“).
            </p>
        <?php endif; ?>
    </div>

    <hr class="divider">
    <label class="checkbox-row">
        <input type="checkbox" name="is_active" value="1"<?= $val('is_active', '1') === '1' ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>>
        <span>Konto ist aktiv</span>
    </label>
    <?php if ($isSelf): ?>
        <input type="hidden" name="is_active" value="1">
    <?php endif; ?>

    <?php if ($canSave): ?>
        <div class="cluster mt-2">
            <button class="btn btn-primary" type="submit"><?= $isNew ? 'Anlegen' : 'Änderungen speichern' ?></button>
            <a class="btn btn-ghost" href="<?= e($base) ?>">Abbrechen</a>
        </div>
    <?php endif; ?>
</form>

<?php if (!$isNew): ?>
    <div class="grid-2 max-w-lg">
        <?php if ($user['role'] === 'student' && $canCards && (int) $user['is_active'] === 1): ?>
            <div class="card card-pad">
                <h3 class="mt-0">🪪 Zugangskarte</h3>
                <p class="text-sm text-soft">
                    Setzt ein neues Passwort und gibt die Karte als PDF aus. Die bisherige Karte wird ungültig.
                    <?php if ($user['card_issued_at'] !== null): ?>
                        Letzte Karte: <?= e(format_datetime($user['card_issued_at'])) ?>.
                    <?php endif; ?>
                </p>
                <form method="post" action="<?= e($base . '/' . (int) $user['id'] . '/karte') ?>"
                      data-confirm="Das Passwort wird zurückgesetzt und die alte Karte ungültig. Fortfahren?">
                    <?= $csrf->field() ?>
                    <button class="btn btn-accent" type="submit">Passwort zurücksetzen und Karte drucken</button>
                </form>
            </div>
        <?php elseif ($user['role'] !== 'student' && $canReset): ?>
            <div class="card card-pad">
                <h3 class="mt-0">🔑 Passwort zurücksetzen</h3>
                <p class="text-sm text-soft">Erzeugt ein neues Passwort, das einmalig angezeigt wird. Die Person muss es beim Login ändern.</p>
                <form method="post" action="<?= e($base . '/' . (int) $user['id'] . '/passwort') ?>"
                      data-confirm="Neues Passwort erzeugen? Das alte gilt dann nicht mehr.">
                    <?= $csrf->field() ?>
                    <button class="btn btn-accent" type="submit">Neues Passwort erzeugen</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($canDelete && !$isSelf): ?>
            <div class="card card-pad">
                <h3 class="mt-0">🗑️ Konto löschen</h3>
                <p class="text-sm text-soft">
                    Löscht das Konto samt Teilnahmen und Antworten. Das lässt sich nicht rückgängig machen.
                    Zum Sperren ohne Löschen stattdessen „Konto ist aktiv“ abwählen.
                </p>
                <form method="post" action="<?= e($base . '/' . (int) $user['id'] . '/loeschen') ?>"
                      data-confirm="Konto „<?= e($user['username']) ?>“ wirklich endgültig löschen?">
                    <?= $csrf->field() ?>
                    <button class="btn btn-danger" type="submit">Konto löschen</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
