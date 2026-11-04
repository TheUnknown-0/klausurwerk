<?php
/**
 * CSV-Import von Schüler:innen: Upload, Vorschau, Ergebnis.
 * Erwartet: $analysis (Vorschau oder null), $result (Ergebnis oder null), $canCards, $isAdmin,
 * $importedCount, $levels
 */

$base = $ctx->url('/admin/benutzer');
$statusBadge = [
    'new' => '<span class="badge badge-success">neu</span>',
    'skip' => '<span class="badge">übersprungen</span>',
    'error' => '<span class="badge badge-danger">Fehler</span>',
];
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($base) ?>">← Benutzer</a></div>
        <h1 class="page-title">Schüler:innen importieren</h1>
        <p class="page-sub">CSV-Datei mit Kopfzeile. Fehlende Kurse werden angelegt.</p>
    </div>
</div>

<?php if ($result !== null): ?>
    <?php /* ---------- Ergebnis ---------- */ ?>
    <div class="card card-pad mb-2 max-w-lg">
        <h2 class="mt-0">Import abgeschlossen</h2>
        <div class="stat-grid">
            <div class="stat-card stat-success">
                <div class="stat-value"><?= e((string) (int) $result['created']) ?></div>
                <div class="stat-label">Konten angelegt</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= e((string) (int) $result['courses_created']) ?></div>
                <div class="stat-label">Kurse angelegt</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= e((string) (int) $result['skipped']) ?></div>
                <div class="stat-label">Übersprungen</div>
            </div>
            <div class="stat-card<?= (int) $result['failed'] > 0 ? ' stat-danger' : '' ?>">
                <div class="stat-value"><?= e((string) (int) $result['failed']) ?></div>
                <div class="stat-label">Fehlgeschlagen</div>
            </div>
        </div>
        <?php foreach (array_slice($result['messages'] ?? [], 0, 10) as $message): ?>
            <div class="alert alert-warning"><?= e($message) ?></div>
        <?php endforeach; ?>

        <?php if ((int) $result['created'] > 0 && $canCards): ?>
            <hr class="divider">
            <h3>Zugangskarten für die importierten Personen drucken</h3>
            <p class="text-sm text-soft">
                Die neuen Konten haben noch kein Passwort. Mit den Karten bekommt jede Person ihre Zugangsdaten
                (Name, Benutzername, Passwort und QR-Code). Das PDF enthält 8 Karten je Seite.
            </p>
            <form method="post" action="<?= e($ctx->url('/admin/zugangskarten')) ?>" class="stack">
                <?= $csrf->field() ?>
                <input type="hidden" name="quelle" value="import">
                <label class="checkbox-row">
                    <input type="checkbox" name="bestaetigt" value="1" required>
                    <span>Es werden neue Passwörter erzeugt. Eventuell vorhandene alte Karten dieser Personen werden ungültig.</span>
                </label>
                <div><button class="btn btn-primary" type="submit">🪪 Zugangskarten drucken (<?= e((string) (int) $result['created']) ?>)</button></div>
            </form>
        <?php endif; ?>

        <div class="cluster mt-2">
            <a class="btn btn-ghost" href="<?= e($base) ?>">Zur Benutzerliste</a>
            <form method="post" action="<?= e($base . '/import/abbrechen') ?>">
                <?= $csrf->field() ?>
                <button class="btn btn-ghost" type="submit">Weiteren Import starten</button>
            </form>
        </div>
    </div>

<?php elseif ($analysis !== null): ?>
    <?php /* ---------- Vorschau ---------- */ ?>
    <?php
    $counts = $analysis['counts'];
    $newCourses = array_filter($analysis['courses'], static fn (array $c): bool => !$c['exists'] && $c['allowed'] && (int) $c['count'] > 0);
    ?>
    <div class="stat-grid">
        <div class="stat-card stat-success">
            <div class="stat-value"><?= e((string) $counts['new']) ?></div>
            <div class="stat-label">werden angelegt</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= e((string) $counts['skip']) ?></div>
            <div class="stat-label">werden übersprungen</div>
        </div>
        <div class="stat-card<?= $counts['error'] > 0 ? ' stat-danger' : '' ?>">
            <div class="stat-value"><?= e((string) $counts['error']) ?></div>
            <div class="stat-label">mit Fehlern</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= e((string) count($newCourses)) ?></div>
            <div class="stat-label">neue Kurse (<?= e($analysis['school_year']) ?>)</div>
        </div>
    </div>

    <?php if ($counts['error'] > 0): ?>
        <div class="alert alert-warning">
            Zeilen mit Fehlern werden nicht importiert. Du kannst die Datei korrigieren und erneut hochladen
            oder die fehlerfreien Zeilen jetzt importieren.
        </div>
    <?php endif; ?>

    <form id="import-form" method="post" action="<?= e($base . '/import/ausfuehren') ?>">
        <?= $csrf->field() ?>

        <?php if ($newCourses !== []): ?>
            <div class="card card-pad mb-2">
                <h3 class="mt-0">Neue Kurse</h3>
                <p class="text-sm text-soft">
                    Diese Kurse gibt es noch nicht. Sie werden für das Schuljahr <?= e($analysis['school_year']) ?> angelegt,
                    du wirst Hauptlehrkraft. Die Klassenstufe wird aus dem Namen geraten; wo das nicht ging, bitte wählen.
                </p>
                <div class="form-grid">
                    <?php foreach ($newCourses as $key => $course): ?>
                        <div class="field">
                            <label for="grade-<?= e(md5((string) $key)) ?>"><?= e($course['name']) ?> (<?= e((string) $course['count']) ?> Personen)</label>
                            <select id="grade-<?= e(md5((string) $key)) ?>" name="grade[<?= e(md5((string) $key)) ?>]" required>
                                <option value="">Klassenstufe wählen</option>
                                <?php foreach ($levels as $level): ?>
                                    <option value="<?= e((string) $level) ?>"<?= (int) ($course['grade_level'] ?? 0) === $level ? ' selected' : '' ?>>Klasse <?= e((string) $level) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="table-wrap mb-2">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Zeile</th>
                    <th>Vorname</th>
                    <th>Nachname</th>
                    <th>Kurs</th>
                    <th>Benutzername</th>
                    <th>NTA</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($analysis['rows'] as $row): ?>
                    <tr class="<?= $row['status'] === 'error' ? 'row-error' : '' ?>">
                        <td class="text-soft"><?= e((string) $row['line']) ?></td>
                        <td><?= e($row['firstname']) ?></td>
                        <td><?= e($row['lastname']) ?></td>
                        <td><?= e($row['course']) ?></td>
                        <td class="mono"><?= e($row['username']) ?></td>
                        <td><?= (int) $row['accommodation'] > 0 ? '+' . e((string) (int) $row['accommodation']) . ' %' : '' ?></td>
                        <td>
                            <?= $statusBadge[$row['status']] ?? '' ?>
                            <?php if ($row['reason'] !== ''): ?>
                                <div class="text-sm text-soft"><?= e($row['reason']) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </form>

    <div class="cluster">
        <?php if ($counts['new'] > 0): ?>
            <button class="btn btn-primary" type="submit" form="import-form"><?= e((string) $counts['new']) ?> Schüler:innen importieren</button>
        <?php endif; ?>
        <form method="post" action="<?= e($base . '/import/abbrechen') ?>">
            <?= $csrf->field() ?>
            <button class="btn btn-ghost" type="submit">Abbrechen</button>
        </form>
    </div>

<?php else: ?>
    <?php /* ---------- Upload ---------- */ ?>
    <div class="grid-2">
        <form class="card card-pad" method="post" action="<?= e($base . '/import') ?>" enctype="multipart/form-data">
            <?= $csrf->field() ?>
            <h3 class="mt-0">Datei hochladen</h3>
            <div class="field">
                <label for="csv">CSV-Datei</label>
                <input class="input" type="file" id="csv" name="csv" accept=".csv,.txt,text/csv" required>
                <div class="hint">Höchstens 2 MB und 2000 Zeilen. Du siehst zuerst eine Vorschau, es wird noch nichts gespeichert.</div>
            </div>
            <button class="btn btn-primary" type="submit">Vorschau erstellen</button>
        </form>

        <div class="card card-pad">
            <h3 class="mt-0">Aufbau der Datei</h3>
            <p class="text-sm text-soft">Erste Zeile ist die Kopfzeile. Trenner Semikolon oder Komma, Zeichensatz UTF-8 (oder Windows-1252).</p>
            <ul class="text-sm">
                <li><strong>Vorname</strong> und <strong>Nachname</strong> (Pflicht)</li>
                <li><strong>Kurs</strong>, zum Beispiel 10a oder 11 Info (Pflicht)</li>
                <li><strong>Benutzername</strong> (optional, sonst vorname.nachname)</li>
                <li><strong>Nachteilsausgleich</strong> in Prozent (optional)</li>
            </ul>
            <pre class="code-sample">Vorname;Nachname;Kurs;Benutzername;Nachteilsausgleich
Anna;Müller;10a;;
Jonas;Schröder;10a;jonas.s;25</pre>
        </div>
    </div>

    <?php if ($isAdmin && $importedCount > 0): ?>
        <div class="card card-pad mt-2 danger-zone">
            <h3 class="mt-0">Importierte Schüler:innen löschen</h3>
            <p class="text-sm text-soft">
                Löscht alle <strong><?= e((string) $importedCount) ?></strong> Schüler:innen, die per Import angelegt wurden
                (samt Teilnahmen und Antworten). Von Hand angelegte Konten bleiben. Das lässt sich nicht rückgängig machen.
            </p>
            <form method="post" action="<?= e($base . '/import/schueler-loeschen') ?>" class="cluster"
                  data-confirm="Wirklich alle importierten Schüler:innen endgültig löschen?">
                <?= $csrf->field() ?>
                <div class="field mb-0">
                    <label for="bestaetigung">Zur Bestätigung LÖSCHEN eintippen</label>
                    <input class="input" type="text" id="bestaetigung" name="bestaetigung" autocomplete="off" required>
                </div>
                <button class="btn btn-danger" type="submit">Alle importierten löschen</button>
            </form>
        </div>
    <?php endif; ?>
<?php endif; ?>
