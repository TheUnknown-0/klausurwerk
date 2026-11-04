<?php
/**
 * Benutzer per CSV importieren — drei Zustände:
 *  1) Upload-Formular (kein $preview, kein $result)
 *  2) Vorschau nach dem Hochladen ($preview: rows, stats, delimiter)
 *  3) Ergebnis des letzten Laufs ($result: created, updated, errors, createdNames, credentials)
 * Erwartet außerdem: $columns (CSV_COLUMNS), $maxBytes, $maxRows, $canAssignCriteria,
 * $roleLabels, $credentialsAvailable, optional $canPurgeImported (Admin) und
 * $importedStudentCount.
 *
 * Die Zeilen aus der Vorschau werden serverseitig in der Session gehalten —
 * „Ausführen“ und „Abbrechen“ übertragen daher keine Formulardaten, nur CSRF.
 */

$columnInfo = [
    'username' => ['Benutzername', 'Pflicht', 'Aliase: username, benutzername, login'],
    'vorname' => ['Vorname', 'Pflicht', 'Aliase: vorname, firstname'],
    'nachname' => ['Nachname', 'Pflicht', 'Aliase: nachname, lastname, name'],
    'klasse' => ['Klasse', 'optional', 'Aliase: klasse, class — z. B. „7b“'],
    'stufe' => ['Jahrgangsstufe', 'optional', 'Aliase: stufe, jahrgang, jahrgangsstufe, grade — Zahl 1–13'],
    'rolle' => ['Rolle', 'optional, Standard „student“', 'Aliase: rolle, role — admin/orga/teacher/student, nur was du selbst vergeben darfst'],
    'email' => ['E-Mail', 'optional', 'Aliase: email, e-mail, mail'],
    'passwort' => ['Passwort', 'optional', 'Aliase: passwort, password — leer = Zufallspasswort wird erzeugt'],
    'kriterien' => ['Ausschlusskriterien', 'optional', 'Aliase: kriterien, ausschlusskriterien, criteria — Namen, mit Komma getrennt' . ($canAssignCriteria ? '' : ' (dir fehlt das Recht, sie zuzuweisen)')],
];

$statusBadge = static fn (string $status): string => match ($status) {
    'new' => '<span class="badge badge-success">neu</span>',
    'update' => '<span class="badge badge-info">aktualisiert</span>',
    default => '<span class="badge badge-danger">Fehler</span>',
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($ctx->url('/admin/benutzer')) ?>">← Benutzer</a></div>
        <h1 class="page-title">📥 Benutzer importieren</h1>
        <p class="page-sub">Konten aus einer CSV-Datei anlegen oder bestehende Konten aktualisieren.</p>
    </div>
</div>

<?php if ($result !== null): ?>
    <div class="card card-pad mb-2">
        <div class="card-header"><h3>Ergebnis</h3></div>
        <div class="stat-grid">
            <div class="stat-card stat-success">
                <div class="stat-value"><?= e((string) $result['created']) ?></div>
                <div class="stat-label">Neu angelegt</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= e((string) $result['updated']) ?></div>
                <div class="stat-label">Aktualisiert</div>
            </div>
            <div class="stat-card<?= $result['errors'] !== [] ? ' stat-danger' : '' ?>">
                <div class="stat-value"><?= e((string) count($result['errors'])) ?></div>
                <div class="stat-label">Fehler</div>
            </div>
        </div>

        <?php if ($result['credentials'] > 0): ?>
            <div class="alert alert-warning">
                Für <?= e((string) $result['credentials']) ?> neu angelegte Konten wurde ein Zufallspasswort erzeugt.
                Das Zugangsdaten-PDF lässt sich <strong>nur einmal</strong> herunterladen.
                <div class="mt-2">
                    <a class="btn btn-primary btn-sm" href="<?= e($ctx->url('/admin/benutzer/zugangsdaten-pdf')) ?>">🖨️ Zugangsdaten-PDF herunterladen</a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($result['errors'] !== []): ?>
            <div class="field">
                <label>Nicht übernommene Zeilen</label>
                <ul class="stack" style="gap:4px;">
                    <?php foreach ($result['errors'] as $error): ?>
                        <li class="text-sm text-danger"><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="cluster">
            <a class="btn btn-primary" href="<?= e($ctx->url('/admin/benutzer')) ?>">Zur Benutzerliste</a>
            <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/benutzer/import')) ?>">Weiteren Import starten</a>
        </div>
    </div>
<?php elseif ($credentialsAvailable): ?>
    <div class="alert alert-warning">
        Aus einem früheren Import liegen noch nicht abgerufene Zugangsdaten vor.
        <a href="<?= e($ctx->url('/admin/benutzer/zugangsdaten-pdf')) ?>">Zugangsdaten-PDF herunterladen</a> — danach sind sie nicht mehr abrufbar.
    </div>
<?php endif; ?>

<?php if ($preview === null): ?>
    <div class="grid-2">
        <div class="card">
            <div class="card-header"><h3>Datei hochladen</h3></div>
            <div class="card-body">
                <form method="post" action="<?= e($ctx->url('/admin/benutzer/import')) ?>" enctype="multipart/form-data">
                    <?= $csrf->field() ?>
                    <div class="field">
                        <label for="csv">CSV-Datei</label>
                        <input class="input" type="file" id="csv" name="csv" accept=".csv,.txt" required>
                        <div class="hint">
                            Trennzeichen Semikolon oder Komma (wird automatisch erkannt), UTF-8 oder ISO-8859-1,
                            mit Kopfzeile. Max. <?= e((string) round($maxBytes / 1048576, 1)) ?> MB, max. <?= e((string) $maxRows) ?> Zeilen.
                        </div>
                    </div>
                    <button class="btn btn-primary" type="submit">Datei prüfen & Vorschau anzeigen</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3>Spalten der Kopfzeile</h3></div>
            <div class="card-body">
                <p class="text-sm text-soft">
                    Bestehende Benutzernamen werden aktualisiert (Name, Klasse, Stufe, E-Mail; Passwort und
                    Kriterien nur, wenn die jeweilige Spalte ausgefüllt ist). Die Rolle bestehender Konten
                    ändert sich beim Import nicht.
                </p>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Spalte</th>
                            <th>Pflicht</th>
                            <th>Hinweis</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($columns as $column): ?>
                            <?php [$label, $required, $hint] = $columnInfo[$column] ?? [$column, '', '']; ?>
                            <tr>
                                <td class="mono"><?= e($column) ?></td>
                                <td><?= e($label) ?> — <?= e($required) ?></td>
                                <td class="text-sm text-soft"><?= e($hint) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($canPurgeImported)): ?>
        <?php $importedCount = (int) ($importedStudentCount ?? 0); ?>
        <section class="card mt-2">
            <div class="card-header"><h3>🗑️ Importierte Schüler:innen löschen</h3></div>
            <div class="card-body">
                <p class="text-soft">
                    Entfernt <strong>alle</strong> per CSV angelegten Schüler:innen vollständig — samt ihren
                    Einschreibungen und Anwesenheiten. Manuell angelegte Konten und andere Rollen bleiben
                    unberührt. Das lässt sich nicht rückgängig machen.
                </p>
                <p class="text-sm text-soft">
                    Aktuell <strong><?= e((string) $importedCount) ?></strong>
                    per CSV importierte<?= $importedCount === 1 ? 'r' : '' ?> Schüler:in<?= $importedCount === 1 ? '' : 'nen' ?>.
                </p>
                <?php if ($importedCount > 0): ?>
                    <form method="post" action="<?= e($ctx->url('/admin/benutzer/import/schueler-loeschen')) ?>"
                          data-confirm="Wirklich alle <?= e((string) $importedCount) ?> per CSV importierten Schüler:innen samt Einschreibungen löschen?">
                        <?= $csrf->field() ?>
                        <label class="checkbox-row">
                            <input type="checkbox" name="confirm" value="1" required>
                            <span>Ja, alle per CSV importierten Schüler:innen endgültig löschen.</span>
                        </label>
                        <div class="mt-2">
                            <button class="btn btn-danger" type="submit"><?= e((string) $importedCount) ?> Schüler:innen löschen</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
<?php else: ?>
    <?php $stats = $preview['stats']; ?>
    <div class="card card-pad mb-2">
        <div class="stat-grid">
            <div class="stat-card stat-success">
                <div class="stat-value"><?= e((string) $stats['new']) ?></div>
                <div class="stat-label">Neue Konten</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= e((string) $stats['update']) ?></div>
                <div class="stat-label">Aktualisierungen</div>
            </div>
            <div class="stat-card<?= $stats['error'] > 0 ? ' stat-danger' : '' ?>">
                <div class="stat-value"><?= e((string) $stats['error']) ?></div>
                <div class="stat-label">Fehlerhafte Zeilen (werden übersprungen)</div>
            </div>
        </div>
        <p class="text-sm text-soft mb-0">
            Trennzeichen erkannt: <span class="mono"><?= e($preview['delimiter']) ?></span> ·
            Nur Zeilen ohne Fehler werden bei „Ausführen“ übernommen.
        </p>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Zeile</th>
                    <th>Benutzername</th>
                    <th>Name</th>
                    <th>Klasse / Stufe</th>
                    <th>Rolle</th>
                    <th>E-Mail</th>
                    <th>Passwort</th>
                    <th>Kriterien</th>
                    <th>Status</th>
                    <th>Hinweis</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($preview['rows'] as $row): ?>
                    <tr>
                        <td><?= e((string) $row['line']) ?></td>
                        <td class="mono"><?= e($row['username']) ?></td>
                        <td><?= e(trim($row['firstname'] . ' ' . $row['lastname'])) ?></td>
                        <td><?= e($row['class'] ?? '—') ?><?= $row['grade'] !== null ? ' / ' . e((string) $row['grade']) : '' ?></td>
                        <td><?= e($roleLabels[$row['role']] ?? $row['role']) ?></td>
                        <td><?= e($row['email'] ?? '—') ?></td>
                        <td>
                            <?php if ($row['password'] !== null): ?>
                                <span class="mono">vorgegeben</span>
                            <?php else: ?>
                                <span class="text-soft">wird erzeugt</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['criteria_names'] !== []): ?>
                                <div class="chip-row">
                                    <?php foreach ($row['criteria_names'] as $name): ?>
                                        <span class="badge badge-warning"><?= e($name) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-faint">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $statusBadge($row['status']) ?></td>
                        <td class="text-sm text-soft"><?= e($row['reason']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="cluster mt-2">
        <form method="post" action="<?= e($ctx->url('/admin/benutzer/import/ausfuehren')) ?>">
            <?= $csrf->field() ?>
            <button class="btn btn-primary" type="submit"<?= $stats['new'] + $stats['update'] === 0 ? ' disabled' : '' ?>>
                Import ausführen (<?= e((string) ($stats['new'] + $stats['update'])) ?> Konten)
            </button>
        </form>
        <form method="post" action="<?= e($ctx->url('/admin/benutzer/import/abbrechen')) ?>">
            <?= $csrf->field() ?>
            <button class="btn btn-ghost" type="submit">Abbrechen</button>
        </form>
    </div>
<?php endif; ?>
