<?php
/**
 * Benutzer anlegen/bearbeiten.
 * Erwartet: $user (null beim Anlegen), $old, $roles, $criteria,
 * $userExclusions (criterion_id => Notiz), $canAssignCriteria, $action,
 * optional $enrollmentCount, $canReset, $canDelete
 */
$isNew = $user === null;
$value = static fn (string $key, mixed $fallback = ''): string => (string) ($old[$key] ?? ($user[$key] ?? $fallback) ?? '');
$currentRole = (string) ($old['role'] ?? ($user['role'] ?? 'student'));
$isSelf = !$isNew && $auth->id() === (int) $user['id'];
$namesOptional = in_array($currentRole, ['teacher', 'admin'], true);
$isActive = $old !== [] ? (($old['is_active'] ?? '') === '1') : ($isNew ? true : (int) $user['is_active'] === 1);
$mustChange = $old !== [] ? (($old['must_change_password'] ?? '') === '1') : ($isNew ? true : (int) $user['must_change_password'] === 1);

// Kriterien-Auswahl: alte Eingabe hat Vorrang vor gespeichertem Zustand
$selectedCriteria = $userExclusions;
if ($old !== [] && isset($old['kriterien']) && is_array($old['kriterien'])) {
    $selectedCriteria = [];
    foreach ($old['kriterien'] as $id) {
        $selectedCriteria[(int) $id] = (string) ($old['kriterien_notiz'][(int) $id] ?? '');
    }
}
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($ctx->url('/admin/benutzer')) ?>">← Benutzer</a></div>
        <h1 class="page-title"><?= $isNew ? 'Neuer Benutzer' : e(trim((string) $user['firstname'] . ' ' . (string) $user['lastname'])) ?></h1>
        <?php if (!$isNew): ?>
            <p class="page-sub">
                Benutzername <span class="mono"><?= e($user['username']) ?></span>
                <?php if (!empty($user['last_login_at'])): ?>
                    · letzter Login <?= e(date('d.m.Y H:i', strtotime((string) $user['last_login_at']))) ?>
                <?php else: ?>
                    · noch nie eingeloggt
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</div>

<form class="card card-pad" method="post" action="<?= e($action) ?>" data-user-form>
    <?= $csrf->field() ?>

<?php $formBlocks = page_blocks('admin-benutzer-formular', [
    'name' => 'Vor- und Nachname',
    'zugang' => 'Zugangsdaten',
    'zuordnung' => 'Klasse, Stufe & Rolle',
    'status' => 'Status',
    'kriterien' => 'Ausschlusskriterien',
    'passwort' => 'Passwort',
]); ?>
<?php foreach ($formBlocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'name'): ?>
    <div class="form-grid">
        <div class="field">
            <label for="firstname" data-name-label>Vorname<?= $namesOptional ? '' : ' *' ?></label>
            <input class="input" type="text" id="firstname" name="firstname" maxlength="100"
                   data-name-field<?= $namesOptional ? '' : ' required' ?>
                   value="<?= e($value('firstname')) ?>">
        </div>
        <div class="field">
            <label for="lastname" data-name-label>Nachname<?= $namesOptional ? '' : ' *' ?></label>
            <input class="input" type="text" id="lastname" name="lastname" maxlength="100"
                   data-name-field<?= $namesOptional ? '' : ' required' ?>
                   value="<?= e($value('lastname')) ?>">
        </div>
        <div class="hint" data-name-hint<?= $namesOptional ? '' : ' hidden' ?>>
            Bei Lehrkräften und Admin-Konten genügt Vorname oder Nachname — das jeweils andere Feld bleibt dann leer.
        </div>
    </div>

<?php elseif ($blockKey === 'zugang'): ?>
    <div class="form-grid">
        <div class="field">
            <label for="username">Benutzername *</label>
            <input class="input" type="text" id="username" name="username" required
                   pattern="[a-zA-Z0-9._@\-]{3,100}" autocomplete="off"
                   value="<?= e($value('username')) ?>">
            <div class="hint">3–100 Zeichen: Buchstaben, Zahlen, Punkt, Minus, Unterstrich, @.</div>
        </div>
        <div class="field">
            <label for="email">E-Mail</label>
            <input class="input" type="email" id="email" name="email" maxlength="255"
                   value="<?= e($value('email')) ?>">
        </div>
    </div>

<?php elseif ($blockKey === 'zuordnung'): ?>
    <div class="form-grid">
        <div class="field">
            <label for="role">Rolle *</label>
            <select id="role" name="role" required data-role-select<?= $isSelf ? ' disabled' : '' ?>>
                <?php foreach ($roles as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $currentRole === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($isSelf): ?>
                <input type="hidden" name="role" value="<?= e($currentRole) ?>">
                <div class="hint">Deine eigene Rolle kannst du nicht ändern.</div>
            <?php elseif (!$isNew && (string) $user['role'] !== 'student'): ?>
                <div class="hint">Bei einem Rollenwechsel werden alle Berechtigungen und Gruppenzuweisungen entzogen.</div>
            <?php endif; ?>
        </div>
        <div class="field">
            <label for="class">Klasse</label>
            <input class="input" type="text" id="class" name="class" maxlength="50" placeholder="z. B. 7b"
                   value="<?= e($value('class')) ?>">
            <div class="hint">Wird für Klassenlimits und die Klassenlisten der Lehrkräfte verwendet.</div>
        </div>
        <div class="field">
            <label for="grade">Jahrgangsstufe</label>
            <input class="input" type="number" id="grade" name="grade" min="1" max="13" placeholder="z. B. 7"
                   value="<?= e($value('grade')) ?>">
            <div class="hint">Wird für Stufenlimits und Stufen-Freigaben an Ständen verwendet.</div>
        </div>
    </div>

<?php elseif ($blockKey === 'status'): ?>
    <div class="stack">
        <label class="checkbox-row">
            <input type="checkbox" name="is_active" value="1"<?= $isActive ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>>
            <span>Konto aktiv — inaktive Konten können sich nicht anmelden und werden bei der Zuteilung übersprungen</span>
        </label>
        <?php if ($isSelf): ?>
            <input type="hidden" name="is_active" value="1">
            <div class="hint">Dein eigenes Konto kannst du nicht deaktivieren.</div>
        <?php endif; ?>
        <label class="checkbox-row">
            <input type="checkbox" name="must_change_password" value="1"<?= $mustChange ? ' checked' : '' ?>>
            <span>Passwortwechsel beim nächsten Login erzwingen</span>
        </label>
        <?php if (!$isNew && isset($enrollmentCount) && $enrollmentCount > 0): ?>
            <div class="hint">Dieses Konto hat <?= e((string) $enrollmentCount) ?> Einschreibung(en) / Wünsche.</div>
        <?php endif; ?>
    </div>

<?php elseif ($blockKey === 'kriterien'): ?>
    <?php if ($canAssignCriteria): ?>
        <div data-criteria-section<?= $currentRole !== 'student' ? ' hidden' : '' ?>>
            <?php if ($criteria === []): ?>
                <div class="alert alert-info mb-0">
                    Es sind noch keine aktiven Ausschlusskriterien angelegt —
                    <a href="<?= e($ctx->url('/admin/kriterien')) ?>">Kriterien verwalten</a>.
                </div>
            <?php else: ?>
                <p class="text-sm text-soft">
                    Ausschlusskriterien sperren die Person <strong>hart</strong> für alle Stände, die das jeweilige
                    Kriterium führen — auch für Orga und Admins nicht übersteuerbar.
                </p>
                <div class="stack">
                    <?php foreach ($criteria as $criterion): ?>
                        <?php $cid = (int) $criterion['id']; $checked = array_key_exists($cid, $selectedCriteria); ?>
                        <div>
                            <label class="checkbox-row">
                                <input type="checkbox" name="kriterien[]" value="<?= e((string) $cid) ?>"<?= $checked ? ' checked' : '' ?> data-criterion-toggle="<?= e((string) $cid) ?>">
                                <span>
                                    <strong><?= e($criterion['name']) ?></strong>
                                    <?php if (!empty($criterion['description'])): ?>
                                        <span class="text-soft text-sm">— <?= e($criterion['description']) ?></span>
                                    <?php endif; ?>
                                </span>
                            </label>
                            <div class="field" data-criterion-note="<?= e((string) $cid) ?>"<?= $checked ? '' : ' hidden' ?>>
                                <input class="input" type="text" name="kriterien_notiz[<?= e((string) $cid) ?>]" maxlength="500"
                                       placeholder="Notiz (optional, z. B. Nachweis, Ansprechperson)"
                                       value="<?= e($selectedCriteria[$cid] ?? '') ?>">
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="hint" data-criteria-placeholder<?= $currentRole === 'student' ? ' hidden' : '' ?>>
            Ausschlusskriterien gibt es nur für Schüler:innen.
        </div>
    <?php else: ?>
        <?php if ($currentRole === 'student' && $userExclusions !== []): ?>
            <div class="chip-row">
                <?php foreach ($criteria as $criterion): ?>
                    <?php if (array_key_exists((int) $criterion['id'], $userExclusions)): ?>
                        <span class="badge badge-warning"><?= e($criterion['name']) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <div class="hint">Dir fehlt das Recht, Ausschlusskriterien zu ändern.</div>
        <?php else: ?>
            <div class="hint">Dir fehlt das Recht, Ausschlusskriterien zuzuweisen.</div>
        <?php endif; ?>
    <?php endif; ?>

<?php elseif ($blockKey === 'passwort'): ?>
    <?php if ($isNew): ?>
        <label class="checkbox-row">
            <input type="checkbox" name="generate_password" value="1" data-generate-toggle<?= ($old['generate_password'] ?? '1') === '1' ? ' checked' : '' ?>>
            <span>Passwort generieren (wird nach dem Anlegen einmalig angezeigt; Wechsel beim ersten Login erzwungen)</span>
        </label>
        <div class="field mt-2" data-password-field>
            <label for="password">Passwort *</label>
            <input class="input" type="password" id="password" name="password" minlength="8" autocomplete="new-password">
            <div class="hint">Mindestens 8 Zeichen.</div>
        </div>
    <?php else: ?>
        <div class="field">
            <label for="password">Neues Passwort (optional)</label>
            <input class="input" type="password" id="password" name="password" minlength="8" autocomplete="new-password">
            <div class="hint">
                Leer lassen, um das Passwort nicht zu ändern. Alternativ „🔑 Passwort“ in der Benutzerliste
                für ein zufälliges Passwort.
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>

    <div class="cluster">
        <button class="btn btn-primary" type="submit"><?= $isNew ? 'Benutzer anlegen' : 'Änderungen speichern' ?></button>
        <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/benutzer')) ?>">Abbrechen</a>
    </div>
</form>
