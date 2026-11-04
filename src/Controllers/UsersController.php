<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions as P;
use App\Services\Pdf;
use PDO;

/**
 * Benutzerverwaltung: Liste, Anlegen, Bearbeiten, Ausschlusskriterien,
 * Passwort-Reset, Löschen/Deaktivieren, CSV-Import mit Vorschau sowie
 * Zugangsdaten-PDFs.
 */
final class UsersController extends Controller
{
    public const ROLE_LABELS = [
        'admin' => 'Administrator',
        'orga' => 'Orga-Team',
        'teacher' => 'Lehrkraft',
        'student' => 'Schüler:in',
    ];

    private const PER_PAGE = 50;
    private const MAX_CSV_BYTES = 2 * 1024 * 1024;
    private const MAX_IMPORT_ROWS = 2000;

    /** Session-Schlüssel */
    private const SESSION_IMPORT = '_users_import';
    private const SESSION_IMPORT_RESULT = '_users_import_result';
    private const SESSION_CREDENTIALS = '_users_credentials';
    private const SESSION_GENERATED = '_generated_password';

    /** Erkannte Kopfzeilen-Namen → interne Spalte (alles kleingeschrieben). */
    private const CSV_ALIASES = [
        'username' => 'username', 'benutzername' => 'username', 'login' => 'username',
        'vorname' => 'firstname', 'firstname' => 'firstname',
        'nachname' => 'lastname', 'lastname' => 'lastname', 'name' => 'lastname',
        'klasse' => 'class', 'class' => 'class',
        'stufe' => 'grade', 'jahrgang' => 'grade', 'jahrgangsstufe' => 'grade', 'grade' => 'grade',
        'rolle' => 'role', 'role' => 'role',
        'email' => 'email', 'e-mail' => 'email', 'mail' => 'email',
        'passwort' => 'password', 'password' => 'password',
        'kriterien' => 'criteria', 'ausschlusskriterien' => 'criteria', 'criteria' => 'criteria',
    ];

    /** Spalten für die Formatbeschreibung im Import-Formular. */
    public const CSV_COLUMNS = ['username', 'vorname', 'nachname', 'klasse', 'stufe', 'rolle', 'email', 'passwort', 'kriterien'];

    // ---------- Liste ----------

    /** GET /admin/benutzer */
    public function index(array $params): string
    {
        $this->requirePermission(P::BENUTZER_SEHEN);

        $search = trim((string) ($_GET['q'] ?? ''));
        $roleFilter = (string) ($_GET['rolle'] ?? '');
        $classFilter = trim((string) ($_GET['klasse'] ?? ''));
        $statusFilter = (string) ($_GET['status'] ?? '');
        $page = max(1, (int) ($_GET['seite'] ?? 1));

        if ($roleFilter !== '' && !isset(self::ROLE_LABELS[$roleFilter])) {
            $roleFilter = '';
        }
        if (!in_array($statusFilter, ['', 'aktiv', 'inaktiv'], true)) {
            $statusFilter = '';
        }

        $where = ['1 = 1'];
        $args = [];
        if ($roleFilter !== '') {
            $where[] = 'u.role = ?';
            $args[] = $roleFilter;
        }
        if ($classFilter !== '') {
            $where[] = 'u.class = ?';
            $args[] = $classFilter;
        }
        if ($statusFilter === 'aktiv') {
            $where[] = 'u.is_active = 1';
        } elseif ($statusFilter === 'inaktiv') {
            $where[] = 'u.is_active = 0';
        }
        if ($search !== '') {
            $like = '%' . self::escapeLike($search) . '%';
            $where[] = "(u.username LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ? OR CONCAT(u.firstname, ' ', u.lastname) LIKE ? OR u.email LIKE ?)";
            array_push($args, $like, $like, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM users u WHERE ' . $whereSql, $args);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $users = $this->fetchPaged(
            'SELECT u.id, u.username, u.email, u.firstname, u.lastname, u.class, u.grade, u.role,
                    u.is_active, u.must_change_password, u.last_login_at,
                    (u.password IS NULL) AS no_password
             FROM users u
             WHERE ' . $whereSql . "
             ORDER BY FIELD(u.role, 'admin', 'orga', 'teacher', 'student'), u.class, u.lastname, u.firstname, u.username
             LIMIT ? OFFSET ?",
            $args,
            self::PER_PAGE,
            ($page - 1) * self::PER_PAGE,
        );

        // Ausschlusskriterien der angezeigten Schüler:innen (als Chips)
        $exclusions = [];
        $studentIds = [];
        foreach ($users as $row) {
            if ($row['role'] === 'student') {
                $studentIds[] = (int) $row['id'];
            }
        }
        if ($studentIds !== []) {
            $rows = $this->ctx->db->fetchAll(
                'SELECT ue.user_id, ec.name FROM user_exclusions ue
                 JOIN exclusion_criteria ec ON ec.id = ue.criterion_id
                 WHERE ue.user_id IN (' . implode(',', array_fill(0, count($studentIds), '?')) . ')
                 ORDER BY ec.sort_order, ec.name',
                $studentIds,
            );
            foreach ($rows as $row) {
                $exclusions[(int) $row['user_id']][] = (string) $row['name'];
            }
        }

        $counts = [];
        foreach ($this->ctx->db->fetchAll('SELECT role, COUNT(*) AS n FROM users GROUP BY role') as $row) {
            $counts[(string) $row['role']] = (int) $row['n'];
        }
        $inactive = (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM users WHERE is_active = 0');

        $classes = array_map(
            'strval',
            array_column($this->ctx->db->fetchAll("SELECT DISTINCT class FROM users WHERE class IS NOT NULL AND class <> '' ORDER BY class"), 'class'),
        );

        $generated = $this->ctx->session->get(self::SESSION_GENERATED);
        $this->ctx->session->remove(self::SESSION_GENERATED);

        return $this->render('pages/users/index', [
            'title' => 'Benutzer',
            'users' => $users,
            'exclusions' => $exclusions,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'search' => $search,
            'roleFilter' => $roleFilter,
            'classFilter' => $classFilter,
            'statusFilter' => $statusFilter,
            'classes' => $classes,
            'counts' => $counts,
            'inactive' => $inactive,
            'roleLabels' => self::ROLE_LABELS,
            'generated' => is_array($generated) ? $generated : null,
            'canCreate' => $this->ctx->auth->can(P::BENUTZER_ERSTELLEN),
            'canEdit' => $this->ctx->auth->can(P::BENUTZER_BEARBEITEN),
            'canDelete' => $this->ctx->auth->can(P::BENUTZER_LOESCHEN),
            'canImport' => $this->ctx->auth->can(P::BENUTZER_IMPORTIEREN),
            'canReset' => $this->ctx->auth->can(P::BENUTZER_PASSWORT_ZURUECKSETZEN),
            'pageScripts' => ['users.js'],
        ]);
    }

    // ---------- Anlegen ----------

    /** GET /admin/benutzer/neu */
    public function create(array $params): string
    {
        $this->requirePermission(P::BENUTZER_ERSTELLEN);

        return $this->render('pages/users/form', [
            'title' => 'Neuer Benutzer',
            'user' => null,
            'old' => $this->ctx->session->pullOldInput(),
            'roles' => $this->assignableRoles(),
            'criteria' => $this->criteriaForForm(),
            'userExclusions' => [],
            'canAssignCriteria' => $this->ctx->auth->can(P::KRITERIEN_ZUWEISEN),
            'action' => $this->ctx->url('/admin/benutzer/neu'),
            'pageScripts' => ['users.js'],
        ]);
    }

    /** POST /admin/benutzer/neu */
    public function store(array $params): string
    {
        $this->requirePermission(P::BENUTZER_ERSTELLEN);
        $this->requireCsrf();

        $data = $this->readForm();
        $back = $this->ctx->url('/admin/benutzer/neu');

        $error = $this->validate($data);
        if ($error === null && !isset($this->assignableRoles()[$data['role']])) {
            $error = 'Diese Rolle darfst du nicht vergeben.';
        }
        if ($error === null && $this->usernameTaken($data['username'], null)) {
            $error = 'Dieser Benutzername ist bereits vergeben.';
        }

        $generate = ($_POST['generate_password'] ?? '') === '1';
        $password = (string) ($_POST['password'] ?? '');
        if ($error === null) {
            if ($generate) {
                $password = self::generatePassword();
            } elseif ($password === '') {
                $error = 'Bitte ein Passwort eingeben oder „Passwort generieren“ wählen.';
            } else {
                $error = AuthController::validateNewPassword($password, $password);
            }
        }

        if ($error !== null) {
            $this->flash('error', $error);
            $this->ctx->session->rememberInput($_POST);
            $this->redirect($back);
        }

        $mustChange = $generate || ($_POST['must_change_password'] ?? '') === '1';

        $userId = $this->ctx->db->transaction(function () use ($data, $password, $mustChange): int {
            $this->ctx->db->run(
                'INSERT INTO users (username, email, password, firstname, lastname, class, grade, role, is_active, must_change_password)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $data['username'],
                    $data['email'],
                    password_hash($password, PASSWORD_DEFAULT),
                    $data['firstname'],
                    $data['lastname'],
                    $data['class'],
                    $data['grade'],
                    $data['role'],
                    $data['is_active'] ? 1 : 0,
                    $mustChange ? 1 : 0,
                ],
            );
            $userId = $this->ctx->db->lastInsertId();
            if ($data['role'] === 'student' && $this->ctx->auth->can(P::KRITERIEN_ZUWEISEN)) {
                $this->saveExclusions($userId, $this->readExclusions(), []);
            }

            return $userId;
        });

        $this->ctx->session->pullOldInput();
        $this->ctx->audit->log(
            'benutzer.erstellen',
            'info',
            sprintf('Benutzer #%d "%s" (%s %s, Rolle: %s, Klasse: %s) angelegt', $userId, $data['username'], $data['firstname'], $data['lastname'], $data['role'], $data['class'] ?? '—'),
        );

        if ($generate) {
            $this->ctx->session->set(self::SESSION_GENERATED, [
                'username' => $data['username'],
                'name' => trim($data['firstname'] . ' ' . $data['lastname']),
                'password' => $password,
            ]);
        }
        $this->flash('success', 'Der Benutzer wurde angelegt.');
        $this->redirect($this->ctx->url('/admin/benutzer'));
    }

    // ---------- Bearbeiten ----------

    /** GET /admin/benutzer/{id} */
    public function edit(array $params): string
    {
        $this->requirePermission(P::BENUTZER_BEARBEITEN);
        $user = $this->loadUser((int) ($params['id'] ?? 0));
        $this->assertMayManage($user);
        $userId = (int) $user['id'];

        $userExclusions = [];
        foreach ($this->ctx->db->fetchAll('SELECT criterion_id, note FROM user_exclusions WHERE user_id = ?', [$userId]) as $row) {
            $userExclusions[(int) $row['criterion_id']] = (string) ($row['note'] ?? '');
        }

        $enrollmentCount = (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM enrollments WHERE user_id = ?', [$userId]);

        return $this->render('pages/users/form', [
            'title' => 'Benutzer bearbeiten',
            'user' => $user,
            'old' => $this->ctx->session->pullOldInput(),
            'roles' => $this->assignableRoles($user),
            'criteria' => $this->criteriaForForm(),
            'userExclusions' => $userExclusions,
            'canAssignCriteria' => $this->ctx->auth->can(P::KRITERIEN_ZUWEISEN),
            'canReset' => $this->ctx->auth->can(P::BENUTZER_PASSWORT_ZURUECKSETZEN),
            'canDelete' => $this->ctx->auth->can(P::BENUTZER_LOESCHEN),
            'enrollmentCount' => $enrollmentCount,
            'action' => $this->ctx->url('/admin/benutzer/' . $userId),
            'pageScripts' => ['users.js'],
        ]);
    }

    /** POST /admin/benutzer/{id} */
    public function update(array $params): string
    {
        $this->requirePermission(P::BENUTZER_BEARBEITEN);
        $this->requireCsrf();

        $user = $this->loadUser((int) ($params['id'] ?? 0));
        $this->assertMayManage($user);
        $userId = (int) $user['id'];
        $isSelf = $this->ctx->auth->id() === $userId;
        $back = $this->ctx->url('/admin/benutzer/' . $userId);

        $data = $this->readForm();
        $error = $this->validate($data);

        if ($error === null && $isSelf) {
            // Eigenes Konto: Rolle und Aktiv-Status sind unantastbar
            if ($data['role'] !== (string) $user['role']) {
                $error = 'Deine eigene Rolle kannst du nicht ändern.';
            } elseif (!$data['is_active']) {
                $error = 'Du kannst dein eigenes Konto nicht deaktivieren.';
            }
        }
        if ($error === null && !isset($this->assignableRoles($user)[$data['role']])) {
            $error = 'Diese Rolle darfst du nicht vergeben.';
        }
        if ($error === null && $this->usernameTaken($data['username'], $userId)) {
            $error = 'Dieser Benutzername ist bereits vergeben.';
        }
        if ($error === null && (string) $user['role'] === 'admin'
            && ($data['role'] !== 'admin' || !$data['is_active']) && $this->isLastAdmin($userId)) {
            $error = 'Das ist das letzte Administrator-Konto — es kann weder herabgestuft noch deaktiviert werden.';
        }

        $password = (string) ($_POST['password'] ?? '');
        if ($error === null && $password !== '') {
            $error = AuthController::validateNewPassword($password, $password);
        }

        if ($error !== null) {
            $this->flash('error', $error);
            $this->ctx->session->rememberInput($_POST);
            $this->redirect($back);
        }

        $roleChanged = $data['role'] !== (string) $user['role'];
        $mustChange = ($_POST['must_change_password'] ?? '') === '1';
        $canAssign = $this->ctx->auth->can(P::KRITERIEN_ZUWEISEN);

        $previous = [];
        foreach ($this->ctx->db->fetchAll('SELECT criterion_id FROM user_exclusions WHERE user_id = ?', [$userId]) as $row) {
            $previous[] = (int) $row['criterion_id'];
        }

        $revokedRights = 0;
        $newlyAdded = [];
        $this->ctx->db->transaction(function () use ($data, $password, $mustChange, $userId, $roleChanged, $canAssign, $previous, &$revokedRights, &$newlyAdded): void {
            $this->ctx->db->run(
                'UPDATE users SET username = ?, email = ?, firstname = ?, lastname = ?, class = ?, grade = ?, role = ?, is_active = ?, must_change_password = ?
                 WHERE id = ?',
                [
                    $data['username'],
                    $data['email'],
                    $data['firstname'],
                    $data['lastname'],
                    $data['class'],
                    $data['grade'],
                    $data['role'],
                    $data['is_active'] ? 1 : 0,
                    $mustChange ? 1 : 0,
                    $userId,
                ],
            );
            if ($password !== '') {
                $this->ctx->db->run('UPDATE users SET password = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
            }
            if ($roleChanged) {
                $revokedRights = $this->revokeGranularRights($userId);
                if ($data['role'] !== 'student') {
                    $this->ctx->db->run('DELETE FROM user_exclusions WHERE user_id = ?', [$userId]);
                }
            }
            if ($data['role'] === 'student' && $canAssign) {
                $newlyAdded = $this->saveExclusions($userId, $this->readExclusions(), $previous);
            }
        });

        // Frisch gesetzte Kriterien gegen bestehende feste Einschreibungen prüfen
        $conflicts = 0;
        if ($newlyAdded !== []) {
            $conflicts = (int) $this->ctx->db->fetchValue(
                "SELECT COUNT(DISTINCT e.id) FROM enrollments e
                 JOIN station_exclusions se ON se.station_id = e.station_id
                 WHERE e.user_id = ? AND e.status = 'assigned'
                   AND se.criterion_id IN (" . implode(',', array_fill(0, count($newlyAdded), '?')) . ')',
                array_merge([$userId], $newlyAdded),
            );
        }

        $changes = [];
        foreach (['username' => 'Benutzername', 'role' => 'Rolle', 'class' => 'Klasse', 'grade' => 'Stufe'] as $key => $label) {
            $before = (string) ($user[$key] ?? '');
            $after = (string) ($data[$key] ?? '');
            if ($before !== $after) {
                $changes[] = sprintf('%s: %s → %s', $label, $before !== '' ? $before : '—', $after !== '' ? $after : '—');
            }
        }
        if ((int) $user['is_active'] !== ($data['is_active'] ? 1 : 0)) {
            $changes[] = $data['is_active'] ? 'aktiviert' : 'deaktiviert';
        }
        if ($password !== '') {
            $changes[] = 'Passwort neu gesetzt';
        }
        if ($revokedRights > 0) {
            $changes[] = sprintf('%d Rechtezuweisung(en) entzogen', $revokedRights);
        }
        if ($newlyAdded !== []) {
            $changes[] = sprintf('%d Ausschlusskriterium/-kriterien neu gesetzt', count($newlyAdded));
        }

        $this->ctx->session->pullOldInput();
        $this->ctx->audit->log(
            'benutzer.bearbeiten',
            ($revokedRights > 0 || $password !== '' || $conflicts > 0) ? 'warning' : 'info',
            sprintf('Benutzer #%d "%s"%s', $userId, (string) $user['username'], $changes === [] ? ' (keine Änderung)' : ' — ' . implode(', ', $changes)),
        );

        if ($revokedRights > 0) {
            $this->flash('warning', 'Mit der neuen Rolle wurden alle bisherigen Berechtigungen und Gruppenzuweisungen dieses Benutzers entzogen.');
        }
        if ($conflicts > 0) {
            $this->flash(
                'warning',
                sprintf(
                    '%d bestehende feste Einschreibung(en) verstoßen jetzt gegen ein neu gesetztes Ausschlusskriterium. Sie wurden nicht gelöscht — bitte unter „Einschreibungen“ prüfen.',
                    $conflicts,
                ),
            );
        }
        $this->flash('success', 'Die Änderungen wurden gespeichert.');
        $this->redirect($this->ctx->url('/admin/benutzer'));
    }

    // ---------- Passwort ----------

    /** POST /admin/benutzer/{id}/passwort */
    public function resetPassword(array $params): string
    {
        $this->requirePermission(P::BENUTZER_PASSWORT_ZURUECKSETZEN);
        $this->requireCsrf();

        $user = $this->loadUser((int) ($params['id'] ?? 0));
        $this->assertMayManage($user);
        $userId = (int) $user['id'];

        $password = self::generatePassword();
        $this->ctx->db->run(
            'UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $userId],
        );
        // Klartext nur für die einmalige Anzeige nach dem Redirect
        $this->ctx->session->set(self::SESSION_GENERATED, [
            'username' => (string) $user['username'],
            'name' => trim((string) $user['firstname'] . ' ' . (string) $user['lastname']),
            'password' => $password,
        ]);
        $this->ctx->audit->log(
            'benutzer.passwort_zuruecksetzen',
            'warning',
            sprintf('Benutzer #%d "%s" — neues Zufallspasswort, Wechsel beim nächsten Login erzwungen', $userId, (string) $user['username']),
        );
        $this->redirect($this->ctx->url('/admin/benutzer'));
    }

    /**
     * POST /admin/benutzer/klasse-passwoerter
     * Neue Passwörter für alle aktiven Schüler:innen einer Klasse — Ausgabe
     * direkt als Zugangsdaten-PDF.
     */
    public function classPasswords(array $params): string
    {
        $this->requirePermission(P::BENUTZER_PASSWORT_ZURUECKSETZEN);
        $this->requireCsrf();

        $class = trim((string) ($_POST['klasse'] ?? ''));
        $list = $this->ctx->url('/admin/benutzer');
        if ($class === '') {
            $this->flash('error', 'Bitte eine Klasse auswählen.');
            $this->redirect($list);
        }

        $students = $this->ctx->db->fetchAll(
            "SELECT id, username, firstname, lastname, class FROM users
             WHERE role = 'student' AND is_active = 1 AND class = ?
             ORDER BY lastname, firstname, username",
            [$class],
        );
        if ($students === []) {
            $this->flash('warning', sprintf('In der Klasse „%s“ gibt es keine aktiven Schüler:innen.', $class));
            $this->redirect($list);
        }

        $credentials = [];
        $this->ctx->db->transaction(function () use ($students, &$credentials): void {
            foreach ($students as $student) {
                $password = self::generatePassword();
                $this->ctx->db->run(
                    'UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?',
                    [password_hash($password, PASSWORD_DEFAULT), (int) $student['id']],
                );
                $credentials[] = [
                    'name' => trim((string) $student['firstname'] . ' ' . (string) $student['lastname']),
                    'username' => (string) $student['username'],
                    'class' => (string) ($student['class'] ?? ''),
                    'password' => $password,
                ];
            }
        });

        $this->ctx->audit->log(
            'benutzer.klassenpasswoerter',
            'critical',
            sprintf('Klasse "%s": %d Passwörter neu gesetzt und als PDF ausgegeben', $class, count($credentials)),
        );

        $this->credentialsPdf($credentials, 'Zugangsdaten Klasse ' . $class)
            ->emit('zugangsdaten-' . $class . '.pdf', 'D');
    }

    /**
     * GET /admin/benutzer/zugangsdaten-pdf
     * Zugangsdaten der beim letzten Import angelegten Konten — einmalig.
     */
    public function importCredentials(array $params): string
    {
        $this->requirePermission(P::BENUTZER_IMPORTIEREN);

        $credentials = $this->ctx->session->get(self::SESSION_CREDENTIALS);
        if (!is_array($credentials) || $credentials === []) {
            $this->flash('warning', 'Es liegen keine Zugangsdaten mehr vor — das PDF kann nur einmal heruntergeladen werden.');
            $this->redirect($this->ctx->url('/admin/benutzer/import'));
        }
        $this->ctx->session->remove(self::SESSION_CREDENTIALS);

        $this->ctx->audit->log('benutzer.zugangsdaten_pdf', 'warning', sprintf('%d Zugangsdaten als PDF heruntergeladen', count($credentials)));

        $this->credentialsPdf($credentials, 'Zugangsdaten (Import)')
            ->emit('zugangsdaten-import.pdf', 'D');
    }

    // ---------- Löschen ----------

    /** POST /admin/benutzer/{id}/loeschen */
    public function destroy(array $params): string
    {
        $this->requirePermission(P::BENUTZER_LOESCHEN);
        $this->requireCsrf();

        $user = $this->loadUser((int) ($params['id'] ?? 0));
        $this->assertMayManage($user);
        $userId = (int) $user['id'];
        $list = $this->ctx->url('/admin/benutzer');

        if ($this->ctx->auth->id() === $userId) {
            $this->flash('error', 'Du kannst dein eigenes Konto nicht löschen.');
            $this->redirect($list);
        }
        if ((string) $user['role'] === 'admin' && $this->isLastAdmin($userId)) {
            $this->flash('error', 'Das ist das letzte Administrator-Konto und kann nicht gelöscht werden.');
            $this->redirect($list);
        }

        // Aktive Schüler:innen mit Einschreibungen werden nur deaktiviert, damit
        // ihre Einschreibungen nicht versehentlich mitgelöscht werden. Ist das
        // Konto bereits inaktiv, war das eine bewusste Entscheidung — dann wird
        // es (samt Einschreibungen per ON DELETE CASCADE) endgültig entfernt.
        $enrollments = (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM enrollments WHERE user_id = ?', [$userId]);
        if ((string) $user['role'] === 'student' && $enrollments > 0 && (int) $user['is_active'] === 1) {
            $this->ctx->db->run('UPDATE users SET is_active = 0 WHERE id = ?', [$userId]);
            $this->ctx->audit->log(
                'benutzer.deaktivieren',
                'warning',
                sprintf('Benutzer #%d "%s" deaktiviert statt gelöscht (%d Einschreibung(en) vorhanden)', $userId, (string) $user['username'], $enrollments),
            );
            $this->flash(
                'warning',
                sprintf('„%s“ hat %d Einschreibung(en) und wurde deshalb nur deaktiviert. Erneutes Löschen entfernt das Konto samt Einschreibungen endgültig.', (string) $user['username'], $enrollments),
            );
            $this->redirect($list);
        }

        // Abhängige Datensätze hängen per FK ON DELETE CASCADE / SET NULL am Benutzer.
        $this->ctx->db->run('DELETE FROM users WHERE id = ?', [$userId]);

        $this->ctx->audit->log(
            'benutzer.loeschen',
            'warning',
            sprintf(
                'Benutzer #%d "%s" (%s %s, Rolle: %s) gelöscht%s',
                $userId,
                (string) $user['username'],
                (string) $user['firstname'],
                (string) $user['lastname'],
                (string) $user['role'],
                $enrollments > 0 ? sprintf(' — inkl. %d Einschreibung(en)', $enrollments) : '',
            ),
        );
        $this->flash('success', 'Der Benutzer wurde gelöscht.');
        $this->redirect($list);
    }

    // ---------- CSV-Import ----------

    /** GET /admin/benutzer/import */
    public function showImport(array $params): string
    {
        $this->requirePermission(P::BENUTZER_IMPORTIEREN);

        $result = $this->ctx->session->get(self::SESSION_IMPORT_RESULT);
        $this->ctx->session->remove(self::SESSION_IMPORT_RESULT);
        $credentials = $this->ctx->session->get(self::SESSION_CREDENTIALS);

        return $this->render('pages/users/import', [
            'title' => 'Benutzer importieren',
            'preview' => null,
            'result' => is_array($result) ? $result : null,
            'credentialsAvailable' => is_array($credentials) && $credentials !== [],
            'columns' => self::CSV_COLUMNS,
            'maxBytes' => self::MAX_CSV_BYTES,
            'maxRows' => self::MAX_IMPORT_ROWS,
            'canAssignCriteria' => $this->ctx->auth->can(P::KRITERIEN_ZUWEISEN),
            'roleLabels' => self::ROLE_LABELS,
            'canPurgeImported' => $this->ctx->auth->isAdmin(),
            'importedStudentCount' => (int) $this->ctx->db->fetchValue(
                "SELECT COUNT(*) FROM users WHERE role = 'student' AND created_via = 'import'",
            ),
        ]);
    }

    /** POST /admin/benutzer/import — Datei einlesen und Vorschau anzeigen. */
    public function importPreview(array $params): string
    {
        $this->requirePermission(P::BENUTZER_IMPORTIEREN);
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/benutzer/import');

        $content = $this->readUploadedCsv();
        if (!is_string($content)) {
            $this->flash('error', $content['error']);
            $this->redirect($back);
        }

        $parsed = $this->parseCsv($content);
        if (isset($parsed['error'])) {
            $this->flash('error', $parsed['error']);
            $this->redirect($back);
        }

        $rows = $parsed['rows'];
        $stats = ['new' => 0, 'update' => 0, 'error' => 0];
        foreach ($rows as $row) {
            $stats[$row['status']]++;
        }

        // Nur importierbare Zeilen in die Session — die Vorschau zeigt alle
        $importable = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] !== 'error'));
        $this->ctx->session->set(self::SESSION_IMPORT, [
            'rows' => $importable,
            'created_at' => time(),
        ]);

        return $this->render('pages/users/import', [
            'title' => 'Benutzer importieren — Vorschau',
            'preview' => ['rows' => $rows, 'stats' => $stats, 'delimiter' => $parsed['delimiter']],
            'result' => null,
            'credentialsAvailable' => false,
            'columns' => self::CSV_COLUMNS,
            'maxBytes' => self::MAX_CSV_BYTES,
            'maxRows' => self::MAX_IMPORT_ROWS,
            'canAssignCriteria' => $this->ctx->auth->can(P::KRITERIEN_ZUWEISEN),
            'roleLabels' => self::ROLE_LABELS,
        ]);
    }

    /** POST /admin/benutzer/import/abbrechen */
    public function importCancel(array $params): string
    {
        $this->requirePermission(P::BENUTZER_IMPORTIEREN);
        $this->requireCsrf();
        $this->ctx->session->remove(self::SESSION_IMPORT);
        $this->flash('info', 'Der Import wurde abgebrochen — es wurde nichts übernommen.');
        $this->redirect($this->ctx->url('/admin/benutzer/import'));
    }

    /** POST /admin/benutzer/import/ausfuehren — geparste Zeilen aus der Session übernehmen. */
    public function importRun(array $params): string
    {
        $this->requirePermission(P::BENUTZER_IMPORTIEREN);
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/benutzer/import');

        $pending = $this->ctx->session->get(self::SESSION_IMPORT);
        $this->ctx->session->remove(self::SESSION_IMPORT);
        if (!is_array($pending) || !is_array($pending['rows'] ?? null) || $pending['rows'] === []) {
            $this->flash('error', 'Es liegt keine Import-Vorschau vor. Bitte die Datei erneut hochladen.');
            $this->redirect($back);
        }

        $canAssign = $this->ctx->auth->can(P::KRITERIEN_ZUWEISEN);
        $allowedRoles = $this->assignableRoles();
        $actorId = $this->ctx->auth->id();

        $created = 0;
        $updated = 0;
        $errors = [];
        $credentials = [];
        $createdNames = [];

        foreach ($pending['rows'] as $row) {
            $line = (int) ($row['line'] ?? 0);
            $role = (string) $row['role'];
            if (!isset($allowedRoles[$role])) {
                $errors[] = sprintf('Zeile %d: Rolle "%s" darfst du nicht vergeben.', $line, $role);
                continue;
            }

            try {
                $existing = $this->ctx->db->fetchOne('SELECT * FROM users WHERE username = ?', [$row['username']]);

                if ($existing !== null) {
                    if ((string) $existing['role'] === 'admin' && !$this->ctx->auth->isAdmin()) {
                        $errors[] = sprintf('Zeile %d: "%s" ist ein Administrator-Konto.', $line, $row['username']);
                        continue;
                    }
                    $this->ctx->db->transaction(function () use ($existing, $row, $canAssign, $actorId): void {
                        $this->ctx->db->run(
                            'UPDATE users SET firstname = ?, lastname = ?, class = ?, grade = ?, email = COALESCE(?, email) WHERE id = ?',
                            [$row['firstname'], $row['lastname'], $row['class'], $row['grade'], $row['email'], (int) $existing['id']],
                        );
                        if ($row['password'] !== null) {
                            $this->ctx->db->run(
                                'UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?',
                                [password_hash($row['password'], PASSWORD_DEFAULT), (int) $existing['id']],
                            );
                        }
                        if ($canAssign && (string) $existing['role'] === 'student' && $row['criteria_given']) {
                            $this->replaceExclusionsByIds((int) $existing['id'], $row['criteria'], $actorId);
                        }
                    });
                    $updated++;
                    continue;
                }

                $password = $row['password'] ?? self::generatePassword();
                $this->ctx->db->transaction(function () use ($row, $role, $password, $canAssign, $actorId): void {
                    $this->ctx->db->run(
                        "INSERT INTO users (username, email, password, firstname, lastname, class, grade, role, is_active, must_change_password, created_via)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 'import')",
                        [
                            $row['username'],
                            $row['email'],
                            password_hash($password, PASSWORD_DEFAULT),
                            $row['firstname'],
                            $row['lastname'],
                            $row['class'],
                            $row['grade'],
                            $role,
                        ],
                    );
                    $userId = $this->ctx->db->lastInsertId();
                    if ($canAssign && $role === 'student' && $row['criteria'] !== []) {
                        $this->replaceExclusionsByIds($userId, $row['criteria'], $actorId);
                    }
                });
                $created++;
                $createdNames[] = (string) $row['username'];
                if ($row['password'] === null) {
                    $credentials[] = [
                        'name' => trim($row['firstname'] . ' ' . $row['lastname']),
                        'username' => (string) $row['username'],
                        'class' => (string) ($row['class'] ?? ''),
                        'password' => $password,
                    ];
                }
            } catch (\PDOException $e) {
                $errors[] = sprintf('Zeile %d: "%s" konnte nicht gespeichert werden.', $line, $row['username']);
            }
        }

        $this->ctx->audit->log(
            'benutzer.import',
            'warning',
            sprintf('CSV-Import: %d angelegt, %d aktualisiert, %d Fehler, %d generierte Passwörter', $created, $updated, count($errors), count($credentials)),
        );

        if ($credentials !== []) {
            $this->ctx->session->set(self::SESSION_CREDENTIALS, $credentials);
        }
        $this->ctx->session->set(self::SESSION_IMPORT_RESULT, [
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
            'createdNames' => $createdNames,
            'credentials' => count($credentials),
        ]);

        if ($created + $updated > 0) {
            $this->flash('success', sprintf('%d Konten angelegt, %d aktualisiert.', $created, $updated));
        } else {
            $this->flash('warning', 'Es wurde kein Konto übernommen.');
        }
        $this->redirect($back);
    }

    /**
     * POST /admin/benutzer/import/schueler-loeschen
     *
     * Löscht ALLE per CSV importierten Schüler:innen vollständig — unabhängig
     * von Aktiv-Status und bestehenden Einschreibungen (die per ON DELETE
     * CASCADE mitgehen). Nur für Administratoren.
     */
    public function deleteImportedStudents(array $params): never
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/benutzer/import');
        $db = $this->ctx->db;

        if ((int) ($_POST['confirm'] ?? 0) !== 1) {
            $this->flash('error', 'Bitte das Löschen ausdrücklich bestätigen.');
            $this->redirect($back);
        }

        $count = (int) $db->fetchValue("SELECT COUNT(*) FROM users WHERE role = 'student' AND created_via = 'import'");
        if ($count === 0) {
            $this->flash('info', 'Es gibt keine per CSV importierten Schüler:innen zum Löschen.');
            $this->redirect($back);
        }

        $enrollments = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM enrollments e JOIN users u ON u.id = e.user_id
             WHERE u.role = 'student' AND u.created_via = 'import'",
        );

        // Abhängige Datensätze (Einschreibungen, Anwesenheit, Ausschlüsse …)
        // hängen per FK ON DELETE CASCADE / SET NULL am Benutzer.
        $db->run("DELETE FROM users WHERE role = 'student' AND created_via = 'import'");

        $this->ctx->audit->log(
            'benutzer.import_loeschen',
            'critical',
            sprintf('%d per CSV importierte Schüler:innen vollständig gelöscht (inkl. %d Einschreibung(en)).', $count, $enrollments),
        );
        $this->flash('success', sprintf('%d per CSV importierte Schüler:innen wurden gelöscht.', $count));
        $this->redirect($back);
    }

    // ---------- Helfer: Formular & Validierung ----------

    /**
     * @return array{username: string, firstname: string, lastname: string, email: ?string, class: ?string, grade: ?int, role: string, is_active: bool}
     */
    private function readForm(): array
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        $class = trim((string) ($_POST['class'] ?? ''));
        $grade = trim((string) ($_POST['grade'] ?? ''));

        return [
            'username' => trim((string) ($_POST['username'] ?? '')),
            'firstname' => trim((string) ($_POST['firstname'] ?? '')),
            'lastname' => trim((string) ($_POST['lastname'] ?? '')),
            'email' => $email !== '' ? $email : null,
            'class' => $class !== '' ? $class : null,
            'grade' => $grade !== '' ? (int) $grade : null,
            'role' => (string) ($_POST['role'] ?? ''),
            'is_active' => ($_POST['is_active'] ?? '') === '1',
        ];
    }

    /** @param array<string, mixed> $data */
    private function validate(array $data): ?string
    {
        if ($data['username'] === '') {
            return 'Benutzername ist ein Pflichtfeld.';
        }
        // Lehrkräfte und Admins brauchen nur einen der beiden Namen — bei allen
        // anderen Rollen (Schüler:in, Orga) bleiben beide verpflichtend.
        if (in_array($data['role'], ['teacher', 'admin'], true)) {
            if ($data['firstname'] === '' && $data['lastname'] === '') {
                return 'Bitte Vorname oder Nachname angeben.';
            }
        } elseif ($data['firstname'] === '' || $data['lastname'] === '') {
            return 'Vorname und Nachname sind Pflichtfelder.';
        }
        if (preg_match('/^[a-zA-Z0-9._@-]{3,100}$/', (string) $data['username']) !== 1) {
            return 'Der Benutzername darf 3–100 Zeichen lang sein (Buchstaben, Zahlen, Punkt, Minus, Unterstrich, @).';
        }
        if (mb_strlen((string) $data['firstname']) > 100 || mb_strlen((string) $data['lastname']) > 100) {
            return 'Vor- und Nachname dürfen höchstens 100 Zeichen lang sein.';
        }
        if ($data['email'] !== null && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            return 'Die E-Mail-Adresse ist ungültig.';
        }
        if ($data['class'] !== null && mb_strlen((string) $data['class']) > 50) {
            return 'Die Klassenbezeichnung darf höchstens 50 Zeichen lang sein.';
        }
        if ($data['grade'] !== null && ($data['grade'] < 1 || $data['grade'] > 13)) {
            return 'Die Jahrgangsstufe muss zwischen 1 und 13 liegen.';
        }
        if (!isset(self::ROLE_LABELS[$data['role']])) {
            return 'Bitte eine gültige Rolle auswählen.';
        }

        return null;
    }

    /**
     * Rollen, die der eingeloggte Benutzer vergeben darf: `admin` nur für
     * Administratoren. Die aktuelle Rolle des bearbeiteten Kontos bleibt
     * immer wählbar.
     *
     * @return array<string, string>
     */
    private function assignableRoles(?array $user = null): array
    {
        $roles = [];
        if ($this->ctx->auth->isAdmin()) {
            $roles['admin'] = self::ROLE_LABELS['admin'];
        }
        $roles['orga'] = self::ROLE_LABELS['orga'];
        $roles['teacher'] = self::ROLE_LABELS['teacher'];
        $roles['student'] = self::ROLE_LABELS['student'];

        if ($user !== null) {
            $current = (string) $user['role'];
            if (!isset($roles[$current]) && isset(self::ROLE_LABELS[$current])) {
                $roles = [$current => self::ROLE_LABELS[$current]] + $roles;
            }
        }

        return $roles;
    }

    /** @return list<array<string, mixed>> aktive Ausschlusskriterien */
    private function criteriaForForm(): array
    {
        return $this->ctx->db->fetchAll(
            'SELECT id, name, description FROM exclusion_criteria WHERE is_active = 1 ORDER BY sort_order, name',
        );
    }

    /**
     * Liest die Kriterien-Auswahl aus dem Formular (nur aktive Kriterien).
     *
     * @return array<int, string> criterion_id => Notiz
     */
    private function readExclusions(): array
    {
        $ids = $_POST['kriterien'] ?? [];
        $notes = $_POST['kriterien_notiz'] ?? [];
        if (!is_array($ids)) {
            return [];
        }
        $active = array_map('intval', array_column($this->criteriaForForm(), 'id'));

        $selection = [];
        foreach ($ids as $raw) {
            $id = (int) $raw;
            if ($id <= 0 || !in_array($id, $active, true)) {
                continue;
            }
            $note = is_array($notes) ? trim((string) ($notes[$id] ?? '')) : '';
            $selection[$id] = mb_substr($note, 0, 500);
        }

        return $selection;
    }

    /**
     * Ersetzt die Kriterienzuordnung eines Benutzers.
     *
     * @param  array<int, string> $selection criterion_id => Notiz
     * @param  list<int>          $previous  bisherige criterion_ids
     * @return list<int> neu hinzugekommene criterion_ids
     */
    private function saveExclusions(int $userId, array $selection, array $previous): array
    {
        $this->ctx->db->run('DELETE FROM user_exclusions WHERE user_id = ?', [$userId]);
        $setBy = $this->ctx->auth->id();
        $added = [];
        foreach ($selection as $criterionId => $note) {
            $this->ctx->db->run(
                'INSERT INTO user_exclusions (user_id, criterion_id, note, set_by) VALUES (?, ?, ?, ?)',
                [$userId, $criterionId, $note !== '' ? $note : null, $setBy],
            );
            if (!in_array($criterionId, $previous, true)) {
                $added[] = $criterionId;
            }
        }

        return $added;
    }

    /** @param list<int> $criterionIds */
    private function replaceExclusionsByIds(int $userId, array $criterionIds, ?int $setBy): void
    {
        $this->ctx->db->run('DELETE FROM user_exclusions WHERE user_id = ?', [$userId]);
        foreach (array_unique($criterionIds) as $criterionId) {
            $this->ctx->db->run(
                'INSERT INTO user_exclusions (user_id, criterion_id, set_by) VALUES (?, ?, ?)',
                [$userId, (int) $criterionId, $setBy],
            );
        }
    }

    // ---------- Helfer: Zugriff ----------

    private function loadUser(int $id): array
    {
        $user = $id > 0 ? $this->ctx->db->fetchOne('SELECT * FROM users WHERE id = ?', [$id]) : null;
        if ($user === null) {
            throw new HttpException(404, 'Dieser Benutzer existiert nicht.');
        }

        return $user;
    }

    /** Nur Administratoren dürfen Administrator-Konten verwalten. */
    private function assertMayManage(array $target): void
    {
        if ((string) $target['role'] === 'admin' && !$this->ctx->auth->isAdmin()) {
            throw new HttpException(403, 'Administrator-Konten können nur von Administratoren verwaltet werden.');
        }
    }

    private function isLastAdmin(int $exceptId): bool
    {
        return (int) $this->ctx->db->fetchValue(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?",
            [$exceptId],
        ) === 0;
    }

    private function usernameTaken(string $username, ?int $exceptId): bool
    {
        return $this->ctx->db->fetchValue(
            'SELECT 1 FROM users WHERE username = ? AND id <> ? LIMIT 1',
            [$username, $exceptId ?? 0],
        ) !== null;
    }

    private function revokeGranularRights(int $userId): int
    {
        $direct = $this->ctx->db->run('DELETE FROM user_permissions WHERE user_id = ?', [$userId])->rowCount();
        $groups = $this->ctx->db->run('DELETE FROM user_permission_groups WHERE user_id = ?', [$userId])->rowCount();

        return $direct + $groups;
    }

    // ---------- Helfer: CSV ----------

    /** @return string|array{error: string} */
    private function readUploadedCsv(): string|array
    {
        $file = $_FILES['csv'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['error' => 'Bitte eine CSV-Datei auswählen.'];
        }
        if ((int) $file['error'] === UPLOAD_ERR_INI_SIZE || (int) $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return ['error' => 'Die Datei ist zu groß (maximal 2 MB).'];
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return ['error' => 'Die Datei konnte nicht hochgeladen werden.'];
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_CSV_BYTES) {
            return ['error' => 'Die Datei ist zu groß (maximal 2 MB).'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['error' => 'Die Datei konnte nicht gelesen werden.'];
        }
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt'], true)) {
            return ['error' => 'Ungültige Datei. Bitte eine CSV-Datei (.csv oder .txt) hochladen.'];
        }

        $content = file_get_contents($tmp);
        if ($content === false || trim($content) === '') {
            return ['error' => 'Die Datei ist leer.'];
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }

        return $content;
    }

    /**
     * Zerlegt den CSV-Inhalt, validiert jede Zeile und bestimmt den Status
     * (new / update / error). Es wird noch nichts gespeichert.
     *
     * @return array{rows: list<array<string, mixed>>, delimiter: string}|array{error: string}
     */
    private function parseCsv(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));
        if (count($lines) < 2) {
            return ['error' => 'Die Datei enthält außer der Kopfzeile keine Daten.'];
        }
        if (count($lines) - 1 > self::MAX_IMPORT_ROWS) {
            return ['error' => sprintf('Zu viele Zeilen (maximal %d je Import).', self::MAX_IMPORT_ROWS)];
        }

        $delimiter = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';

        // Kopfzeile → Spaltenzuordnung
        $columns = [];
        foreach (str_getcsv($lines[0], $delimiter, '"', '\\') as $position => $label) {
            $key = mb_strtolower(trim((string) $label));
            if (isset(self::CSV_ALIASES[$key])) {
                $columns[self::CSV_ALIASES[$key]] = $position;
            }
        }
        foreach (['username', 'firstname', 'lastname'] as $required) {
            if (!isset($columns[$required])) {
                return ['error' => 'Die Kopfzeile muss mindestens die Spalten username, vorname und nachname enthalten.'];
            }
        }

        $canAssign = $this->ctx->auth->can(P::KRITERIEN_ZUWEISEN);
        $allowedRoles = $this->assignableRoles();
        $criteriaByName = [];
        foreach ($this->ctx->db->fetchAll('SELECT id, name FROM exclusion_criteria WHERE is_active = 1') as $row) {
            $criteriaByName[mb_strtolower((string) $row['name'])] = (int) $row['id'];
        }

        $existing = [];
        foreach ($this->ctx->db->fetchAll('SELECT username, role FROM users') as $row) {
            $existing[mb_strtolower((string) $row['username'])] = (string) $row['role'];
        }

        $rows = [];
        $seen = [];
        foreach ($lines as $index => $line) {
            if ($index === 0) {
                continue;
            }
            $lineNumber = $index + 1;
            $cells = str_getcsv($line, $delimiter, '"', '\\');
            $get = static fn (string $key): string => isset($columns[$key]) ? trim((string) ($cells[$columns[$key]] ?? '')) : '';

            $row = [
                'line' => $lineNumber,
                'username' => $get('username'),
                'firstname' => mb_substr($get('firstname'), 0, 100),
                'lastname' => mb_substr($get('lastname'), 0, 100),
                'class' => $get('class') !== '' ? mb_substr($get('class'), 0, 50) : null,
                'grade' => null,
                'role' => $get('role') !== '' ? mb_strtolower($get('role')) : 'student',
                'email' => $get('email') !== '' ? $get('email') : null,
                'password' => $get('password') !== '' ? $get('password') : null,
                'criteria' => [],
                'criteria_names' => [],
                'criteria_given' => isset($columns['criteria']),
                'status' => 'new',
                'reason' => '',
            ];

            // Lehrkräfte und Admins brauchen nur einen der beiden Namen, siehe validate().
            $namesOk = in_array($row['role'], ['teacher', 'admin'], true)
                ? ($row['firstname'] !== '' || $row['lastname'] !== '')
                : ($row['firstname'] !== '' && $row['lastname'] !== '');

            $problems = [];
            if ($row['username'] === '' || !$namesOk) {
                $problems[] = 'Pflichtfelder fehlen (username, vorname/nachname)';
            } elseif (preg_match('/^[a-zA-Z0-9._@-]{3,100}$/', $row['username']) !== 1) {
                $problems[] = 'ungültiger Benutzername';
            }

            $gradeRaw = $get('grade');
            if ($gradeRaw !== '') {
                if (preg_match('/^\d{1,2}$/', $gradeRaw) !== 1 || (int) $gradeRaw < 1 || (int) $gradeRaw > 13) {
                    $problems[] = 'ungültige Stufe „' . $gradeRaw . '“';
                } else {
                    $row['grade'] = (int) $gradeRaw;
                }
            }
            if (!isset(self::ROLE_LABELS[$row['role']])) {
                $problems[] = 'unbekannte Rolle „' . $row['role'] . '“';
            } elseif (!isset($allowedRoles[$row['role']])) {
                $problems[] = 'Rolle „' . $row['role'] . '“ darfst du nicht vergeben';
            }
            if ($row['email'] !== null && filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false) {
                $problems[] = 'ungültige E-Mail';
            }
            if ($row['password'] !== null) {
                $passwordError = AuthController::validateNewPassword($row["password"], $row["password"]);
                if ($passwordError !== null) {
                    $problems[] = 'Passwort: ' . $passwordError;
                }
            }

            $criteriaRaw = $get('criteria');
            if ($criteriaRaw !== '') {
                if (!$canAssign) {
                    $problems[] = 'Ausschlusskriterien dürfen von dir nicht zugewiesen werden';
                } elseif ($row['role'] !== 'student') {
                    $problems[] = 'Ausschlusskriterien nur für Schüler:innen';
                } else {
                    foreach (preg_split('/\s*,\s*/', $criteriaRaw) ?: [] as $name) {
                        $name = trim($name);
                        if ($name === '') {
                            continue;
                        }
                        $id = $criteriaByName[mb_strtolower($name)] ?? null;
                        if ($id === null) {
                            $problems[] = 'unbekanntes Kriterium „' . $name . '“';
                            continue;
                        }
                        $row['criteria'][] = $id;
                        $row['criteria_names'][] = $name;
                    }
                }
            }

            $key = mb_strtolower($row['username']);
            if ($key !== '' && isset($seen[$key])) {
                $problems[] = 'Benutzername kommt in der Datei mehrfach vor';
            }
            $seen[$key] = true;

            if ($problems === []) {
                if (isset($existing[$key])) {
                    $row['status'] = 'update';
                    if ($existing[$key] === 'admin' && !$this->ctx->auth->isAdmin()) {
                        $problems[] = 'Administrator-Konto';
                    } elseif ($existing[$key] !== $row['role']) {
                        $row['reason'] = 'Rolle bleibt „' . (self::ROLE_LABELS[$existing[$key]] ?? $existing[$key]) . '“';
                    }
                }
            }
            if ($problems !== []) {
                $row['status'] = 'error';
                $row['reason'] = implode('; ', $problems);
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'delimiter' => $delimiter];
    }

    // ---------- Helfer: PDF ----------

    /**
     * Zugangsdaten als Kärtchen (2 Spalten) zum Ausschneiden.
     *
     * @param list<array{name: string, username: string, class: string, password: string}> $credentials
     */
    private function credentialsPdf(array $credentials, string $title): Pdf
    {
        $schoolName = (string) ($this->ctx->settings->get('school_name') ?? '');
        $appName = (string) ($this->ctx->settings->get('app_name') ?: 'Gartenarbeitstag');
        $loginUrl = $this->ctx->publicUrl('/login');

        $pdf = new Pdf('P', $schoolName, $appName, $title, false);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        $cardWidth = 95.0;
        $cardHeight = 42.0;
        $left = 10.0;
        $top = 12.0;
        $gapX = 0.0;
        $perRow = 2;
        $perPage = 6 * $perRow;

        foreach ($credentials as $i => $card) {
            $slot = $i % $perPage;
            if ($i > 0 && $slot === 0) {
                $pdf->AddPage();
            }
            $x = $left + ($slot % $perRow) * ($cardWidth + $gapX);
            $y = $top + intdiv($slot, $perRow) * $cardHeight;

            $pdf->SetDrawColor(160, 168, 178);
            $pdf->SetLineWidth(0.2);
            $pdf->Rect($x, $y, $cardWidth, $cardHeight);

            $pdf->SetTextColor(95, 105, 118);
            $pdf->SetFont('Helvetica', '', 7);
            $pdf->SetXY($x + 4, $y + 3);
            $pdf->Cell($cardWidth - 8, 4, $pdf->fit($schoolName !== '' ? $schoolName . ' · ' . $appName : $appName, $cardWidth - 8), 0, 0, 'L');

            $pdf->SetTextColor(25, 30, 38);
            $pdf->SetFont('Helvetica', 'B', 11);
            $pdf->SetXY($x + 4, $y + 8);
            $pdf->Cell($cardWidth - 8, 6, $pdf->fit($card['name'] . ($card['class'] !== '' ? ' (' . $card['class'] . ')' : ''), $cardWidth - 8), 0, 0, 'L');

            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetXY($x + 4, $y + 16);
            $pdf->Cell(28, 6, $pdf->t('Benutzername:'), 0, 0, 'L');
            $pdf->SetFont('Courier', 'B', 11);
            $pdf->Cell($cardWidth - 36, 6, $pdf->fit($card['username'], $cardWidth - 36), 0, 0, 'L');

            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetXY($x + 4, $y + 23);
            $pdf->Cell(28, 6, $pdf->t('Passwort:'), 0, 0, 'L');
            $pdf->SetFont('Courier', 'B', 11);
            $pdf->Cell($cardWidth - 36, 6, $pdf->fit($card['password'], $cardWidth - 36), 0, 0, 'L');

            $pdf->SetTextColor(95, 105, 118);
            $pdf->SetFont('Helvetica', '', 7);
            $pdf->SetXY($x + 4, $y + 31);
            $pdf->Cell($cardWidth - 8, 4, $pdf->fit($loginUrl, $cardWidth - 8), 0, 0, 'L');
            $pdf->SetXY($x + 4, $y + 35);
            $pdf->Cell($cardWidth - 8, 4, $pdf->t('Beim ersten Login musst du ein neues Passwort setzen.'), 0, 0, 'L');
        }

        $pdf->SetTextColor(0, 0, 0);

        return $pdf;
    }

    // ---------- Helfer: Sonstiges ----------

    /**
     * LIMIT/OFFSET müssen als Integer gebunden werden.
     *
     * @param  list<mixed> $args
     * @return list<array<string, mixed>>
     */
    private function fetchPaged(string $sql, array $args, int $limit, int $offset): array
    {
        $stmt = $this->ctx->db->pdo()->prepare($sql);
        $position = 1;
        foreach ($args as $value) {
            $stmt->bindValue($position++, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($position, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** Zufallspasswort ohne leicht verwechselbare Zeichen (0/O, 1/l/I). */
    public static function generatePassword(int $length = 10): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }
}
