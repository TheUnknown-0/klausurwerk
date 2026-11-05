<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions as P;
use App\Services\AccessCards;
use App\Services\CourseService;
use App\Services\ExamKit;
use App\Services\UserImport;
use PDO;

/**
 * Benutzerverwaltung: Liste, Anlegen, Bearbeiten, Passwort/Karte, Löschen und CSV-Import.
 *
 * Rechte: Admins verwalten alle Konten. Lehrkräfte verwalten nur Schüler:innen, und zwar die
 * Mitglieder ihrer Kurse (mit ALLE_KURSE alle). Lehrkräfte- und Admin-Konten legen nur Admins an.
 */
final class UsersController extends Controller
{
    public const ROLE_LABELS = [
        'admin' => 'Administrator:in',
        'teacher' => 'Lehrkraft',
        'student' => 'Schüler:in',
    ];

    public const SESSION_IMPORT = '_users_import';
    public const SESSION_IMPORT_RESULT = '_users_import_result';
    private const SESSION_GENERATED = '_generated_password';

    private const PER_PAGE = 50;
    private const MAX_CSV_BYTES = 2 * 1024 * 1024;

    // ---------- Liste ----------

    /** GET /admin/benutzer */
    public function index(array $params): string
    {
        $this->requirePermission(P::BENUTZER_SEHEN);
        $courses = $this->courses();

        $search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $role = (string) ($_GET['rolle'] ?? '');
        $role = isset(self::ROLE_LABELS[$role]) ? $role : '';
        $courseFilter = (int) ($_GET['kurs'] ?? 0);
        $status = (string) ($_GET['status'] ?? '');
        $status = in_array($status, ['aktiv', 'inaktiv'], true) ? $status : '';

        $where = ['1 = 1'];
        $args = [];

        if (!$this->ctx->auth->isAdmin()) {
            // Lehrkräfte sehen nur Schüler:innen.
            $where[] = "u.role = 'student'";
            $role = $role === 'student' ? 'student' : '';
            if (!$courses->seesAllCourses()) {
                $own = $courses->ownCourseIds((int) $this->ctx->auth->id());
                if ($own === []) {
                    $where[] = '1 = 0';
                } else {
                    $where[] = 'EXISTS (SELECT 1 FROM course_members m WHERE m.user_id = u.id AND m.course_id IN ('
                        . implode(',', array_fill(0, count($own), '?')) . '))';
                    array_push($args, ...$own);
                }
            }
        } elseif ($role !== '') {
            $where[] = 'u.role = ?';
            $args[] = $role;
        }

        foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $like = '%' . self::escapeLike($token) . '%';
            $where[] = '(u.firstname LIKE ? OR u.lastname LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
            array_push($args, $like, $like, $like, $like);
        }
        if ($courseFilter > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM course_members f WHERE f.user_id = u.id AND f.course_id = ?)';
            $args[] = $courseFilter;
        }
        if ($status === 'aktiv') {
            $where[] = 'u.is_active = 1';
        } elseif ($status === 'inaktiv') {
            $where[] = 'u.is_active = 0';
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM users u WHERE ' . $whereSql, $args);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($_GET['seite'] ?? 1)), $pages);

        $users = $this->fetchPaged(
            'SELECT u.* FROM users u WHERE ' . $whereSql . ' ORDER BY u.role, u.lastname, u.firstname, u.username',
            $args,
            self::PER_PAGE,
            ($page - 1) * self::PER_PAGE,
        );

        $courseNames = [];
        if ($users !== []) {
            $ids = array_map(static fn (array $u): int => (int) $u['id'], $users);
            $rows = $this->ctx->db->fetchAll(
                'SELECT cm.user_id, c.name FROM course_members cm JOIN courses c ON c.id = cm.course_id
                 WHERE cm.user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                 ORDER BY c.is_archived, c.school_year DESC, c.name',
                $ids,
            );
            foreach ($rows as $row) {
                $courseNames[(int) $row['user_id']][] = (string) $row['name'];
            }
        }

        return $this->render('pages/users/index', [
            'title' => 'Benutzer',
            'users' => $users,
            'courseNames' => $courseNames,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'search' => $search,
            'roleFilter' => $role,
            'courseFilter' => $courseFilter,
            'statusFilter' => $status,
            'courseOptions' => $courses->visibleCourses(true),
            'roleLabels' => self::ROLE_LABELS,
            'isAdmin' => $this->ctx->auth->isAdmin(),
            'canCreate' => $this->ctx->auth->can(P::BENUTZER_ERSTELLEN),
            'canEdit' => $this->ctx->auth->can(P::BENUTZER_BEARBEITEN),
            'canImport' => $this->ctx->auth->can(P::BENUTZER_IMPORTIEREN),
            'canCards' => $this->ctx->auth->can(P::ZUGANGSKARTEN_DRUCKEN),
            'pageScripts' => ['users.js'],
            'pageStyles' => ['admin.css'],
        ]);
    }

    // ---------- Anlegen ----------

    /** GET /admin/benutzer/neu */
    public function create(array $params): string
    {
        $this->requirePermission(P::BENUTZER_ERSTELLEN);
        $old = $this->ctx->session->pullOldInput();
        $role = (string) ($_GET['rolle'] ?? ($old['role'] ?? 'student'));
        if (!$this->ctx->auth->isAdmin() || !isset(self::ROLE_LABELS[$role])) {
            $role = 'student';
        }

        return $this->renderForm(null, $old + ['role' => $role, 'is_active' => '1'], 'Neuer Benutzer');
    }

    /** POST /admin/benutzer/neu */
    public function store(array $params): string
    {
        $this->requirePermission(P::BENUTZER_ERSTELLEN);
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/benutzer/neu');

        $data = $this->readForm(null);
        $error = $this->validate($data, null);
        if ($error !== null) {
            $this->ctx->session->rememberInput($_POST);
            $this->flash('error', $error);
            $this->redirect($back);
        }

        $importer = new UserImport($this->ctx->db);
        $generated = null;
        $password = null;
        if ($data['role'] === 'student') {
            if ($data['username'] === '') {
                $data['username'] = $importer->uniqueUsername(UserImport::baseUsername($data['firstname'], $data['lastname']));
            }
        } else {
            [$password, $generated] = $this->choosePassword($data);
        }

        $userId = $this->ctx->db->transaction(function () use ($data, $password): int {
            $this->ctx->db->run(
                'INSERT INTO users (username, email, password, firstname, lastname, role, is_active,
                                    must_change_password, accommodation_percent, accommodation_note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $data['username'],
                    $data['email'] !== '' ? $data['email'] : null,
                    $password !== null ? password_hash($password, PASSWORD_DEFAULT) : null,
                    $data['firstname'],
                    $data['lastname'],
                    $data['role'],
                    $data['is_active'],
                    $data['role'] !== 'student' && $data['must_change_password'] ? 1 : 0,
                    $data['role'] === 'student' && $this->ctx->auth->can(P::NACHTEILSAUSGLEICH_BEARBEITEN) ? $data['accommodation_percent'] : 0,
                    $data['role'] === 'student' && $this->ctx->auth->can(P::NACHTEILSAUSGLEICH_BEARBEITEN) && $data['accommodation_note'] !== '' ? $data['accommodation_note'] : null,
                ],
            );
            $id = $this->ctx->db->lastInsertId();
            if ($data['role'] === 'student') {
                foreach ($data['course_ids'] as $courseId) {
                    $this->ctx->db->run('INSERT IGNORE INTO course_members (course_id, user_id) VALUES (?, ?)', [$courseId, $id]);
                }
            }

            return $id;
        });

        $this->ctx->audit->log(
            'benutzer.anlegen',
            $data['role'] === 'admin' ? 'warning' : 'info',
            sprintf('%s angelegt (%s)', $data['username'], self::ROLE_LABELS[$data['role']]),
        );
        if ($generated !== null) {
            $this->ctx->session->set(self::SESSION_GENERATED, ['user_id' => $userId, 'password' => $generated]);
        }
        $this->flash('success', $data['role'] === 'student'
            ? 'Die Schüler:in wurde angelegt. Zum Anmelden braucht sie noch eine Zugangskarte.'
            : 'Das Konto wurde angelegt.');
        $this->redirect($this->ctx->url('/admin/benutzer/' . $userId));
    }

    // ---------- Bearbeiten ----------

    /** GET /admin/benutzer/{id} */
    public function edit(array $params): string
    {
        $this->requirePermission(P::BENUTZER_SEHEN);
        $user = $this->loadManageable((int) $params['id']);

        $old = $this->ctx->session->pullOldInput();
        $values = $old !== [] ? $old : [
            'role' => $user['role'],
            'firstname' => $user['firstname'],
            'lastname' => $user['lastname'],
            'username' => $user['username'],
            'email' => (string) ($user['email'] ?? ''),
            'is_active' => (string) $user['is_active'],
            'must_change_password' => (string) $user['must_change_password'],
            'accommodation_percent' => (string) $user['accommodation_percent'],
            'accommodation_note' => (string) ($user['accommodation_note'] ?? ''),
            'course_ids' => array_map(
                static fn (array $c): int => (int) $c['id'],
                $this->courses()->coursesOfStudent((int) $user['id']),
            ),
        ];

        $generated = $this->ctx->session->get(self::SESSION_GENERATED);
        $this->ctx->session->remove(self::SESSION_GENERATED);
        $generatedPassword = is_array($generated) && (int) ($generated['user_id'] ?? 0) === (int) $user['id']
            ? (string) $generated['password']
            : null;

        return $this->renderForm($user, $values, display_name($user), $generatedPassword);
    }

    /** POST /admin/benutzer/{id} */
    public function update(array $params): string
    {
        $this->requirePermission(P::BENUTZER_BEARBEITEN);
        $this->requireCsrf();
        $user = $this->loadManageable((int) $params['id']);
        $userId = (int) $user['id'];
        $back = $this->ctx->url('/admin/benutzer/' . $userId);

        $data = $this->readForm($user);
        $error = $this->validate($data, $user);
        if ($error === null && $userId === $this->ctx->auth->id() && $data['is_active'] === 0) {
            $error = 'Du kannst dein eigenes Konto nicht deaktivieren.';
        }
        if ($error === null && $user['role'] === 'admin' && ($data['role'] !== 'admin' || $data['is_active'] === 0)
            && $this->isLastAdmin($userId)) {
            $error = 'Das ist der letzte aktive Administrator. Lege zuerst einen weiteren an.';
        }
        if ($error !== null) {
            $this->ctx->session->rememberInput($_POST);
            $this->flash('error', $error);
            $this->redirect($back);
        }

        $isStudent = $user['role'] === 'student';
        $canAccommodation = $isStudent && $this->ctx->auth->can(P::NACHTEILSAUSGLEICH_BEARBEITEN);
        [$newPassword, $generated] = !$isStudent && ($data['new_password'] !== '' || $data['generate'])
            ? $this->choosePassword($data)
            : [null, null];

        $this->ctx->db->transaction(function () use ($data, $user, $userId, $isStudent, $canAccommodation, $newPassword): void {
            $this->ctx->db->run(
                'UPDATE users SET username = ?, email = ?, firstname = ?, lastname = ?, role = ?, is_active = ?,
                        accommodation_percent = ?, accommodation_note = ? WHERE id = ?',
                [
                    $data['username'],
                    $isStudent ? null : ($data['email'] !== '' ? $data['email'] : null),
                    $data['firstname'],
                    $data['lastname'],
                    $isStudent ? 'student' : $data['role'],
                    $data['is_active'],
                    $canAccommodation ? $data['accommodation_percent'] : (int) $user['accommodation_percent'],
                    $canAccommodation ? ($data['accommodation_note'] !== '' ? $data['accommodation_note'] : null) : $user['accommodation_note'],
                    $userId,
                ],
            );
            if ($newPassword !== null) {
                $this->ctx->db->run(
                    'UPDATE users SET password = ?, must_change_password = ? WHERE id = ?',
                    [password_hash($newPassword, PASSWORD_DEFAULT), $data['must_change_password'] ? 1 : 0, $userId],
                );
            } elseif (!$isStudent) {
                $this->ctx->db->run('UPDATE users SET must_change_password = ? WHERE id = ?', [$data['must_change_password'] ? 1 : 0, $userId]);
            }
            if ($isStudent) {
                $this->syncCourses($userId, $data['course_ids']);
            }
        });

        $notes = [];
        if ($newPassword !== null) {
            $notes[] = 'Passwort neu gesetzt';
        }
        if ($data['role'] !== $user['role']) {
            $notes[] = sprintf('Rolle %s zu %s', $user['role'], $data['role']);
        }
        if ($data['username'] !== $user['username']) {
            $notes[] = sprintf('Benutzername %s zu %s', $user['username'], $data['username']);
        }
        $this->ctx->audit->log(
            'benutzer.bearbeiten',
            $newPassword !== null || $data['role'] !== $user['role'] ? 'warning' : 'info',
            $data['username'] . ($notes !== [] ? ' (' . implode(', ', $notes) . ')' : ''),
        );
        if ($generated !== null) {
            $this->ctx->session->set(self::SESSION_GENERATED, ['user_id' => $userId, 'password' => $generated]);
        }
        $message = 'Die Änderungen wurden gespeichert.';
        if ($isStudent && $data['username'] !== $user['username'] && $user['card_issued_at'] !== null) {
            $message .= ' Der Benutzername hat sich geändert: Die gedruckte Zugangskarte passt nicht mehr, bitte eine neue Karte drucken.';
        }
        $this->flash('success', $message);
        $this->redirect($back);
    }

    /** POST /admin/benutzer/{id}/passwort: Passwort einer Lehrkraft oder eines Admins neu erzeugen. */
    public function resetPassword(array $params): string
    {
        $this->requirePermission(P::BENUTZER_PASSWORT_ZURUECKSETZEN);
        $this->requireCsrf();
        $user = $this->loadManageable((int) $params['id']);
        $userId = (int) $user['id'];
        $back = $this->ctx->url('/admin/benutzer/' . $userId);

        if ($user['role'] === 'student') {
            $this->flash('info', 'Bei Schüler:innen läuft das über „Passwort zurücksetzen und Karte drucken“.');
            $this->redirect($back);
        }

        $password = AccessCards::generatePassword(12);
        $this->ctx->db->run(
            'UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $userId],
        );
        $this->ctx->audit->log('benutzer.passwort_zuruecksetzen', 'warning', (string) $user['username']);
        $this->ctx->session->set(self::SESSION_GENERATED, ['user_id' => $userId, 'password' => $password]);
        $this->flash('success', 'Es wurde ein neues Passwort erzeugt. Es wird unten einmalig angezeigt.');
        $this->redirect($back);
    }

    /** POST /admin/benutzer/{id}/karte: Passwort zurücksetzen und die Zugangskarte als PDF ausgeben. */
    public function issueCard(array $params): string
    {
        $this->requirePermission(P::ZUGANGSKARTEN_DRUCKEN);
        $this->requireCsrf();
        $user = $this->loadManageable((int) $params['id']);
        $back = $this->ctx->url('/admin/benutzer/' . (int) $user['id']);

        if ($user['role'] !== 'student') {
            $this->flash('error', 'Zugangskarten gibt es nur für Schüler:innen.');
            $this->redirect($back);
        }
        if ((int) $user['is_active'] !== 1) {
            $this->flash('error', 'Das Konto ist deaktiviert. Bitte zuerst aktivieren.');
            $this->redirect($back);
        }

        $result = (new AccessCards($this->ctx->db))->issueAndRender(
            [(int) $user['id']],
            (string) ($this->ctx->settings->get('school_name') ?? ''),
            (string) ($this->ctx->settings->get('app_name') ?: 'Klausurwerk'),
            $this->ctx->publicUrl('/login'),
        );
        // Kein Passwort im Log, nur Benutzername.
        $this->ctx->audit->log('zugangskarte.einzeln', 'warning', 'Neue Karte für ' . $user['username']);

        AccessCards::sendPdf($result['pdf'], 'zugangskarte-' . $user['username'] . '.pdf');
    }

    // ---------- Löschen ----------

    /** POST /admin/benutzer/{id}/loeschen */
    public function destroy(array $params): string
    {
        $this->requirePermission(P::BENUTZER_LOESCHEN);
        $this->requireCsrf();
        $user = $this->loadManageable((int) $params['id']);
        $userId = (int) $user['id'];
        $list = $this->ctx->url('/admin/benutzer');

        if ($userId === $this->ctx->auth->id()) {
            $this->flash('error', 'Du kannst dein eigenes Konto nicht löschen.');
            $this->redirect($list);
        }
        if ($user['role'] === 'admin' && (int) $user['is_active'] === 1 && $this->isLastAdmin($userId)) {
            $this->flash('error', 'Das ist der letzte aktive Administrator und kann nicht gelöscht werden.');
            $this->redirect($list);
        }

        // Hochgeladene Antwortdateien zuerst: die Kaskade entfernt nur die Datenbankzeilen.
        ExamKit::answerFiles($this->ctx)->deleteFilesOfUser($userId);
        $this->ctx->db->run('DELETE FROM users WHERE id = ?', [$userId]);
        $this->ctx->audit->log('benutzer.loeschen', 'warning', sprintf('%s (%s) gelöscht', $user['username'], self::ROLE_LABELS[$user['role']] ?? $user['role']));
        $this->flash('success', 'Das Konto „' . $user['username'] . '“ wurde gelöscht.');
        $this->redirect($list);
    }

    // ---------- CSV-Import ----------

    /** GET /admin/benutzer/import */
    public function showImport(array $params): string
    {
        $this->requirePermission(P::BENUTZER_IMPORTIEREN);

        $analysis = $this->ctx->session->get(self::SESSION_IMPORT);
        $result = $this->ctx->session->get(self::SESSION_IMPORT_RESULT);

        return $this->render('pages/users/import', [
            'title' => 'Schüler:innen importieren',
            'analysis' => is_array($analysis) ? $analysis : null,
            'result' => is_array($result) ? $result : null,
            'canCards' => $this->ctx->auth->can(P::ZUGANGSKARTEN_DRUCKEN),
            'isAdmin' => $this->ctx->auth->isAdmin(),
            'importedCount' => $this->ctx->auth->isAdmin()
                ? (int) $this->ctx->db->fetchValue("SELECT COUNT(*) FROM users WHERE role = 'student' AND created_via = 'import'")
                : 0,
            'levels' => range(CourseService::MIN_GRADE, CourseService::MAX_GRADE),
            'pageStyles' => ['admin.css'],
        ]);
    }

    /** POST /admin/benutzer/import: Datei lesen und Vorschau bauen. */
    public function importPreview(array $params): string
    {
        $this->requirePermission(P::BENUTZER_IMPORTIEREN);
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/benutzer/import');

        $content = $this->readUploadedCsv();
        if (is_array($content)) {
            $this->flash('error', $content['error']);
            $this->redirect($back);
        }
        $parsed = UserImport::parseCsv($content);
        if (isset($parsed['error'])) {
            $this->flash('error', $parsed['error']);
            $this->redirect($back);
        }

        $analysis = (new UserImport($this->ctx->db))->analyze($parsed['rows'], $this->importScope());
        $analysis['delimiter'] = $parsed['delimiter'];

        $this->ctx->session->set(self::SESSION_IMPORT, $analysis);
        $this->ctx->session->remove(self::SESSION_IMPORT_RESULT);
        $this->redirect($back);
    }

    /** POST /admin/benutzer/import/ausfuehren */
    public function importRun(array $params): string
    {
        $this->requirePermission(P::BENUTZER_IMPORTIEREN);
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/benutzer/import');

        $analysis = $this->ctx->session->get(self::SESSION_IMPORT);
        if (!is_array($analysis) || !isset($analysis['rows'])) {
            $this->flash('warning', 'Es liegt keine Vorschau vor. Bitte die Datei erneut hochladen.');
            $this->redirect($back);
        }
        if ((int) ($analysis['counts']['new'] ?? 0) === 0) {
            $this->flash('warning', 'Es gibt nichts zu importieren.');
            $this->redirect($back);
        }

        $gradeInput = $_POST['grade'] ?? [];
        $levels = [];
        foreach (array_keys($analysis['courses']) as $courseKey) {
            // Im Formular steht der md5-Hash des Kursschlüssels (Kursnamen können Sonderzeichen enthalten).
            $value = is_array($gradeInput) ? ($gradeInput[md5((string) $courseKey)] ?? null) : null;
            if (is_scalar($value)) {
                $levels[(string) $courseKey] = (string) $value;
            }
        }

        try {
            $result = (new UserImport($this->ctx->db))->execute($analysis, $levels, (int) $this->ctx->auth->id());
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect($back);
        }

        $this->ctx->audit->log(
            'benutzer.import',
            'info',
            sprintf(
                '%d Schüler:innen importiert, %d Kurse angelegt, %d übersprungen, %d fehlgeschlagen',
                $result['created'],
                $result['courses_created'],
                $result['skipped'],
                $result['failed'],
            ),
        );
        $this->ctx->session->remove(self::SESSION_IMPORT);
        $this->ctx->session->set(self::SESSION_IMPORT_RESULT, $result);
        $this->redirect($back);
    }

    /** POST /admin/benutzer/import/abbrechen */
    public function importCancel(array $params): string
    {
        $this->requirePermission(P::BENUTZER_IMPORTIEREN);
        $this->requireCsrf();
        $this->ctx->session->remove(self::SESSION_IMPORT);
        $this->ctx->session->remove(self::SESSION_IMPORT_RESULT);
        $this->redirect($this->ctx->url('/admin/benutzer/import'));
    }

    /** POST /admin/benutzer/import/schueler-loeschen: nur Admins, mit Bestätigungswort. */
    public function deleteImportedStudents(array $params): string
    {
        $this->requireAdmin();
        $this->requirePermission(P::BENUTZER_LOESCHEN);
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/benutzer/import');

        if (!in_array(mb_strtoupper(trim((string) ($_POST['bestaetigung'] ?? ''))), ['LÖSCHEN', 'LOESCHEN'], true)) {
            $this->flash('error', 'Zur Bestätigung bitte LÖSCHEN eintippen. Es wurde nichts gelöscht.');
            $this->redirect($back);
        }

        $count = $this->ctx->db->run("DELETE FROM users WHERE role = 'student' AND created_via = 'import'")->rowCount();
        $this->ctx->session->remove(self::SESSION_IMPORT);
        $this->ctx->session->remove(self::SESSION_IMPORT_RESULT);
        $this->ctx->audit->log('benutzer.import_loeschen', 'critical', sprintf('%d importierte Schüler:innen gelöscht', $count));
        $this->flash('success', sprintf('%d importierte Schüler:innen wurden gelöscht.', $count));
        $this->redirect($back);
    }

    // ---------- Helfer ----------

    private function courses(): CourseService
    {
        return new CourseService($this->ctx->db, $this->ctx->auth);
    }

    /**
     * Lädt ein Konto, das der Nutzer verwalten darf: Admins alle, Lehrkräfte Schüler:innen
     * ihrer Kurse. Sonst 403/404.
     *
     * @return array<string, mixed>
     */
    private function loadManageable(int $id): array
    {
        $user = $this->ctx->db->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
        if ($user === null) {
            throw new HttpException(404, 'Benutzer nicht gefunden.');
        }
        if ($this->ctx->auth->isAdmin()) {
            return $user;
        }
        if ($user['role'] !== 'student' || !$this->courses()->canManageStudent($id)) {
            throw new HttpException(403);
        }

        return $user;
    }

    /** @return array{actor_id: int, all_courses: bool, own_course_ids: list<int>, may_create_courses: bool, may_set_accommodation: bool} */
    private function importScope(): array
    {
        $courses = $this->courses();
        $actorId = (int) $this->ctx->auth->id();

        return [
            'actor_id' => $actorId,
            'all_courses' => $courses->seesAllCourses(),
            'own_course_ids' => $courses->ownCourseIds($actorId),
            'may_create_courses' => $this->ctx->auth->can(P::KURSE_BEARBEITEN),
            'may_set_accommodation' => $this->ctx->auth->can(P::NACHTEILSAUSGLEICH_BEARBEITEN),
        ];
    }

    /** Kurse, die der Nutzer im Formular zuordnen darf (bearbeitbar und nicht archiviert). @return list<array<string, mixed>> */
    private function assignableCourses(): array
    {
        $courses = $this->courses();

        return array_values(array_filter(
            $courses->visibleCourses(false),
            static fn (array $c): bool => $courses->canEdit((int) $c['id']),
        ));
    }

    /**
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $values
     */
    private function renderForm(?array $user, array $values, string $title, ?string $generatedPassword = null): string
    {
        $assignable = $this->assignableCourses();
        $assignableIds = array_map(static fn (array $c): int => (int) $c['id'], $assignable);
        $selected = array_map('intval', is_array($values['course_ids'] ?? null) ? $values['course_ids'] : []);

        // Kurse, die die Person schon hat, der Nutzer aber nicht ändern darf: nur anzeigen.
        $locked = [];
        if ($user !== null) {
            foreach ($this->courses()->coursesOfStudent((int) $user['id']) as $c) {
                if (!in_array((int) $c['id'], $assignableIds, true)) {
                    $locked[] = $c;
                }
            }
        }

        return $this->render('pages/users/form', [
            'title' => $title,
            'user' => $user,
            'values' => $values,
            'isNew' => $user === null,
            'assignable' => $assignable,
            'selectedCourses' => $selected,
            'lockedCourses' => $locked,
            'generatedPassword' => $generatedPassword,
            'roleLabels' => self::ROLE_LABELS,
            'isAdmin' => $this->ctx->auth->isAdmin(),
            'isSelf' => $user !== null && (int) $user['id'] === $this->ctx->auth->id(),
            'canAccommodation' => $this->ctx->auth->can(P::NACHTEILSAUSGLEICH_BEARBEITEN),
            'canCards' => $this->ctx->auth->can(P::ZUGANGSKARTEN_DRUCKEN),
            'canReset' => $this->ctx->auth->can(P::BENUTZER_PASSWORT_ZURUECKSETZEN),
            'canDelete' => $this->ctx->auth->can(P::BENUTZER_LOESCHEN),
            'canSave' => $user === null || $this->ctx->auth->can(P::BENUTZER_BEARBEITEN),
            'mustPickCourse' => !$this->courses()->seesAllCourses(),
            'minPasswordLength' => AuthController::MIN_PASSWORD_LENGTH,
            'pageScripts' => ['users.js'],
            'pageStyles' => ['admin.css'],
        ]);
    }

    /**
     * Liest das Formular. $existing = bestehendes Konto (Rolle steht dann fest für Schüler:innen).
     *
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function readForm(?array $existing): array
    {
        $isAdmin = $this->ctx->auth->isAdmin();
        $posted = (string) ($_POST['role'] ?? '');
        if ($existing === null) {
            $role = $isAdmin && isset(self::ROLE_LABELS[$posted]) ? $posted : 'student';
        } elseif ($existing['role'] === 'student') {
            $role = 'student';
        } else {
            // Konten von Lehrkräften werden nie zu Schüler:innen, nur zwischen Lehrkraft und Admin gewechselt.
            $role = $isAdmin && in_array($posted, ['admin', 'teacher'], true) ? $posted : (string) $existing['role'];
        }

        $assignableIds = array_map(static fn (array $c): int => (int) $c['id'], $this->assignableCourses());
        $courseIds = [];
        foreach (is_array($_POST['course_ids'] ?? null) ? $_POST['course_ids'] : [] as $id) {
            $id = (int) $id;
            if (in_array($id, $assignableIds, true)) {
                $courseIds[$id] = $id;
            }
        }

        $percent = trim((string) ($_POST['accommodation_percent'] ?? '0'));

        return [
            'role' => $role,
            'firstname' => mb_substr(trim((string) ($_POST['firstname'] ?? '')), 0, 100),
            'lastname' => mb_substr(trim((string) ($_POST['lastname'] ?? '')), 0, 100),
            'username' => trim((string) ($_POST['username'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'is_active' => ($_POST['is_active'] ?? '') === '1' ? 1 : 0,
            'must_change_password' => ($_POST['must_change_password'] ?? '') === '1',
            'new_password' => (string) ($_POST['password'] ?? ''),
            'generate' => ($_POST['generate_password'] ?? '') === '1',
            'accommodation_percent' => ctype_digit($percent) ? (int) $percent : -1,
            'accommodation_note' => mb_substr(trim((string) ($_POST['accommodation_note'] ?? '')), 0, 255),
            'course_ids' => array_values($courseIds),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $existing
     */
    private function validate(array $data, ?array $existing): ?string
    {
        $isStudent = $data['role'] === 'student';

        if ($isStudent) {
            if ($data['firstname'] === '' || $data['lastname'] === '') {
                return 'Vorname und Nachname sind Pflicht.';
            }
        } elseif ($data['firstname'] === '' && $data['lastname'] === '') {
            return 'Bitte mindestens einen Namen angeben.';
        }

        if ($data['username'] === '' && !($isStudent && $existing === null)) {
            return 'Der Benutzername ist Pflicht.';
        }
        if ($data['username'] !== '') {
            if (!UserImport::isValidUsername($data['username'])) {
                return 'Der Benutzername braucht 3 bis 100 Zeichen (Buchstaben, Zahlen, Punkt, Minus, Unterstrich).';
            }
            $taken = $this->ctx->db->fetchValue(
                'SELECT 1 FROM users WHERE username = ? AND id <> ? LIMIT 1',
                [$data['username'], $existing !== null ? (int) $existing['id'] : 0],
            );
            if ($taken !== null) {
                return 'Dieser Benutzername ist schon vergeben.';
            }
        }

        if (!$isStudent) {
            if ($data['email'] !== '' && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
                return 'Die E-Mail-Adresse ist ungültig.';
            }
            if ($data['new_password'] !== '' && !$data['generate']) {
                $passwordError = AuthController::validateNewPassword($data['new_password'], $data['new_password']);
                if ($passwordError !== null) {
                    return $passwordError;
                }
            }
            if ($existing === null && $data['new_password'] === '' && !$data['generate']) {
                return 'Bitte ein Passwort eingeben oder „Passwort erzeugen“ wählen.';
            }
        } else {
            if ($data['accommodation_percent'] < 0 || $data['accommodation_percent'] > 100) {
                return 'Der Nachteilsausgleich muss eine ganze Zahl von 0 bis 100 sein.';
            }
            if ($existing === null && $data['course_ids'] === [] && !$this->courses()->seesAllCourses()) {
                return 'Bitte mindestens einen deiner Kurse auswählen (lege bei Bedarf zuerst einen Kurs an).';
            }
        }

        return null;
    }

    /**
     * Passwort für Lehrkraft/Admin: eingegeben oder erzeugt.
     *
     * @param array<string, mixed> $data
     * @return array{0: string|null, 1: string|null} [Passwort im Klartext, erzeugtes Passwort zur Anzeige]
     */
    private function choosePassword(array $data): array
    {
        if ($data['generate'] || $data['new_password'] === '') {
            $password = AccessCards::generatePassword(12);

            return [$password, $password];
        }

        return [$data['new_password'], null];
    }

    /**
     * Gleicht die Kurse einer Schüler:in ab. Nur Kurse, die der Nutzer bearbeiten darf, werden
     * hinzugefügt oder entfernt; andere Mitgliedschaften bleiben unberührt.
     *
     * @param list<int> $selected
     */
    private function syncCourses(int $userId, array $selected): void
    {
        $service = $this->courses();
        foreach ($this->assignableCourses() as $course) {
            $courseId = (int) $course['id'];
            if (in_array($courseId, $selected, true)) {
                $service->addMember($courseId, $userId);
            } else {
                $service->removeMember($courseId, $userId);
            }
        }
    }

    private function isLastAdmin(int $exceptId): bool
    {
        return (int) $this->ctx->db->fetchValue(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?",
            [$exceptId],
        ) === 0;
    }

    /** @return string|array{error: string} */
    private function readUploadedCsv(): string|array
    {
        $file = $_FILES['csv'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['error' => 'Bitte eine CSV-Datei auswählen.'];
        }
        if ((int) $file['error'] === UPLOAD_ERR_INI_SIZE || (int) $file['error'] === UPLOAD_ERR_FORM_SIZE
            || (int) ($file['size'] ?? 0) > self::MAX_CSV_BYTES) {
            return ['error' => 'Die Datei ist zu groß (höchstens 2 MB).'];
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return ['error' => 'Die Datei konnte nicht hochgeladen werden.'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['error' => 'Die Datei konnte nicht gelesen werden.'];
        }
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt'], true)) {
            return ['error' => 'Bitte eine CSV-Datei (.csv oder .txt) hochladen.'];
        }

        $content = file_get_contents($tmp);
        if ($content === false || trim($content) === '') {
            return ['error' => 'Die Datei ist leer.'];
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            // Excel exportiert oft in Windows-1252.
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        return $content;
    }

    /**
     * LIMIT/OFFSET müssen als Integer gebunden werden.
     *
     * @param list<mixed> $args
     * @return list<array<string, mixed>>
     */
    private function fetchPaged(string $sql, array $args, int $limit, int $offset): array
    {
        $stmt = $this->ctx->db->pdo()->prepare($sql . ' LIMIT ? OFFSET ?');
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
}
