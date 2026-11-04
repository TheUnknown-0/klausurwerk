<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\AccessCardsController;
use App\Controllers\AuditLogController;
use App\Controllers\CoursesController;
use App\Controllers\DashboardController;
use App\Controllers\GradingScalesController;
use App\Controllers\OperatorsController;
use App\Controllers\PermissionsController;
use App\Controllers\RetentionController;
use App\Controllers\RoomsController;
use App\Controllers\SettingsController;
use App\Controllers\UserSearchController;
use App\Controllers\UsersController;
use App\Core\Auth;
use App\Core\Context;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Session;
use App\Core\View;
use App\Services\Audit;
use App\Services\Settings;
use Tests\Support\AdminFixtures;
use Tests\Support\DatabaseTestCase;

/**
 * Rauchtest: Jede GET-Seite der Verwaltung rendert für Admin und Lehrkraft ohne PHP-Warnung,
 * Zugriffsgrenzen (403/404) greifen, die Personensuche liefert die versprochenen Felder.
 */
final class AdminPagesSmokeTest extends DatabaseTestCase
{
    use AdminFixtures;

    private Context $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        // Jede Warnung (undefinierte Variable, Array-Zugriff auf null) soll den Test brechen.
        set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $no, $file, $line);
        });
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_URI'] = '/admin';
        unset($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        $_GET = [];
        $_POST = [];
        unset($_SESSION['user_id'], $_SESSION['_flash'], $_SESSION['_old_input']);
        parent::tearDown();
    }

    private function context(int $userId): Context
    {
        $_SESSION['user_id'] = $userId;
        $session = new Session();
        $csrf = new Csrf($session);
        $auth = new Auth($session, $this->db);
        $view = new View(dirname(__DIR__, 2) . '/templates');
        $settings = new Settings($this->db);
        $config = [
            'app' => ['env' => 'development', 'base_url' => '', 'secure_cookies' => false],
            'mail' => ['host' => '', 'from_address' => ''],
            'runner' => ['token' => ''],
            'uploads' => ['dir' => sys_get_temp_dir(), 'max_logo_bytes' => 2097152],
        ];
        $ctx = new Context($config, $this->db, $session, $csrf, $auth, $view, $settings, new Audit($this->db, $auth));
        $view->share('ctx', $ctx);
        $view->share('auth', $auth);
        $view->share('csrf', $csrf);

        return $ctx;
    }

    /** @param class-string $class */
    private function page(int $userId, string $class, string $action, array $params = []): string|array
    {
        $this->ctx = $this->context($userId);

        return (new $class($this->ctx))->$action($params);
    }

    private function assertHtml(string|array $result, string $needle): void
    {
        self::assertIsString($result);
        self::assertStringContainsString('<html', $result);
        self::assertStringContainsString($needle, $result);
        self::assertStringNotContainsString('Gartenarbeitstag', $result);
        self::assertStringNotContainsString("\u{2014}", $result, 'keine Gedankenstriche');
    }

    public function testAdminSiehtAlleVerwaltungsseiten(): void
    {
        $admin = $this->createUser('admin', ['firstname' => 'Ada']);
        $teacher = $this->createUser('teacher');
        $student = $this->createUser('student', ['firstname' => 'Sven', 'lastname' => 'Suchname', 'accommodation_percent' => 25]);
        $course = $this->createCourse('10a', 10, (int) $teacher['id']);
        $this->addMember((int) $course['id'], (int) $student['id']);
        $this->createExam('Klausur 1', (int) $course['id'], 'draft');
        $this->db->run("INSERT INTO operators (name, afb, description, sort_order) VALUES ('Testoperator', 'I', 'Teste Sie', 1)");
        $this->db->run("INSERT INTO rooms (name, grid_cols, grid_rows) VALUES ('R 204', 4, 3)");
        $roomId = $this->db->lastInsertId();
        $this->db->run('INSERT INTO room_positions (room_id, user_id, col, `row`) VALUES (?, ?, 1, 1)', [$roomId, $student['id']]);
        $this->db->run("INSERT INTO permission_groups (name) VALUES ('Fachbereich')");
        $groupId = $this->db->lastInsertId();
        $scaleId = (int) $this->db->fetchValue('SELECT id FROM grading_scales WHERE grade_level = 10');
        $id = (int) $admin['id'];

        $this->assertHtml($this->page($id, DashboardController::class, 'index'), 'Hallo Ada');
        $this->assertHtml($this->page($id, UsersController::class, 'index'), 'Suchname');
        $this->assertHtml($this->page($id, UsersController::class, 'create'), 'Neuer Benutzer');
        $this->assertHtml($this->page($id, UsersController::class, 'edit', ['id' => $student['id']]), 'Zugangskarte');
        $this->assertHtml($this->page($id, UsersController::class, 'edit', ['id' => $teacher['id']]), 'Passwort zurücksetzen');
        $this->assertHtml($this->page($id, UsersController::class, 'showImport'), 'Aufbau der Datei');
        $this->assertHtml($this->page($id, CoursesController::class, 'index'), '10a');
        $this->assertHtml($this->page($id, CoursesController::class, 'create'), 'Neuer Kurs');
        $this->assertHtml($this->page($id, CoursesController::class, 'show', ['id' => $course['id']]), 'Klausur 1');
        $this->assertHtml($this->page($id, CoursesController::class, 'edit', ['id' => $course['id']]), 'Weitere Lehrkräfte');
        $this->assertHtml($this->page($id, AccessCardsController::class, 'index'), 'neue Passwörter');
        $_GET['kurs'] = (string) $course['id'];
        $_GET['q'] = 'such';
        $this->assertHtml($this->page($id, AccessCardsController::class, 'index'), 'Suchname');
        $_GET = [];
        $this->assertHtml($this->page($id, GradingScalesController::class, 'index'), 'Standard je Klassenstufe');
        $this->assertHtml($this->page($id, GradingScalesController::class, 'create'), 'Stufen');
        $this->assertHtml($this->page($id, GradingScalesController::class, 'edit', ['id' => $scaleId]), 'Klasse 10');
        $this->assertHtml($this->page($id, OperatorsController::class, 'index'), 'Testoperator');
        $this->assertHtml($this->page($id, OperatorsController::class, 'edit', ['id' => $this->db->fetchValue('SELECT id FROM operators WHERE name = ?', ['Testoperator'])]), 'Anforderungsbereich');
        $this->assertHtml($this->page($id, RoomsController::class, 'index'), 'R 204');
        $this->assertHtml($this->page($id, RoomsController::class, 'show', ['id' => $roomId]), 'Sitzplan');
        $this->assertHtml($this->page($id, SettingsController::class, 'index'), 'Kein Mailserver');
        $this->assertHtml($this->page($id, RetentionController::class, 'index'), 'Fristen');
        $this->assertHtml($this->page($id, PermissionsController::class, 'index'), 'Berechtigungskonzept');
        $_GET['benutzer'] = (string) $teacher['id'];
        $this->assertHtml($this->page($id, PermissionsController::class, 'index'), 'Rechte');
        $_GET = [];
        $this->assertHtml($this->page($id, PermissionsController::class, 'groups'), 'Fachbereich');
        $this->assertHtml($this->page($id, PermissionsController::class, 'createGroup'), 'Neue Berechtigungsgruppe');
        $this->assertHtml($this->page($id, PermissionsController::class, 'editGroup', ['id' => $groupId]), 'Fachbereich');
        $this->assertHtml($this->page($id, AuditLogController::class, 'index'), 'Audit-Log');
    }

    public function testLehrkraftSiehtNurEigeneSchuelerUndKurse(): void
    {
        $teacher = $this->createUser('teacher');
        $other = $this->createUser('teacher');
        $mine = $this->createCourse('Kurs-Eigen-Q', 10, (int) $teacher['id']);
        $theirs = $this->createCourse('Kurs-Fremd-Z', 10, (int) $other['id']);
        $own = $this->createUser('student', ['lastname' => 'Eigener']);
        $foreign = $this->createUser('student', ['lastname' => 'Fremder']);
        $this->addMember((int) $mine['id'], (int) $own['id']);
        $this->addMember((int) $theirs['id'], (int) $foreign['id']);
        $id = (int) $teacher['id'];

        $users = $this->page($id, UsersController::class, 'index');
        self::assertStringContainsString('Eigener', $users);
        self::assertStringNotContainsString('Fremder', $users);
        self::assertStringNotContainsString($other['username'], $users, 'Lehrkräfte sehen keine Lehrkräfte-Konten');

        $courses = $this->page($id, CoursesController::class, 'index');
        self::assertStringContainsString('Kurs-Eigen-Q', $courses);
        self::assertStringNotContainsString('Kurs-Fremd-Z', $courses);

        $this->assertHtml($this->page($id, UsersController::class, 'edit', ['id' => $own['id']]), 'Eigener');
        $this->assertHtml($this->page($id, DashboardController::class, 'index'), 'Meine Kurse');

        foreach ([
            [UsersController::class, 'edit', ['id' => $foreign['id']]],
            [UsersController::class, 'edit', ['id' => $other['id']]],
            [CoursesController::class, 'show', ['id' => $theirs['id']]],
            [CoursesController::class, 'edit', ['id' => $theirs['id']]],
            [SettingsController::class, 'index', []],
            [PermissionsController::class, 'index', []],
            [RetentionController::class, 'index', []],
            [AuditLogController::class, 'index', []],
            [GradingScalesController::class, 'index', []],
            [OperatorsController::class, 'index', []],
        ] as [$class, $action, $params]) {
            try {
                $this->page($id, $class, $action, $params);
                self::fail("$class::$action hätte 403 liefern müssen");
            } catch (HttpException $e) {
                self::assertSame(403, $e->status, "$class::$action");
            }
        }
    }

    public function testSchuelerKommenNirgendsHinein(): void
    {
        $student = $this->createUser('student');
        foreach ([
            [DashboardController::class, 'index'],
            [UsersController::class, 'index'],
            [CoursesController::class, 'index'],
            [AccessCardsController::class, 'index'],
            [UserSearchController::class, 'search'],
        ] as [$class, $action]) {
            try {
                $this->page((int) $student['id'], $class, $action);
                self::fail("$class::$action hätte 403 liefern müssen");
            } catch (HttpException $e) {
                self::assertSame(403, $e->status, "$class::$action");
            }
        }
    }

    public function testPersonensucheLiefertVersprochenesFormat(): void
    {
        $teacher = $this->createUser('teacher', ['firstname' => 'Tina', 'lastname' => 'Lehrerin']);
        $course = $this->createCourse('10a', 10, (int) $teacher['id']);
        $this->createUser('student', ['firstname' => 'Zara', 'lastname' => 'Zufall', 'username' => 'zara.zufall']);
        $inCourse = $this->createUser('student', ['firstname' => 'Zoe', 'lastname' => 'Zufall', 'username' => 'zoe.zufall']);
        $this->createUser('student', ['firstname' => 'Zed', 'lastname' => 'Zufall', 'username' => 'zed.zufall', 'is_active' => 0]);
        $this->addMember((int) $course['id'], (int) $inCourse['id']);
        $id = (int) $teacher['id'];

        $_GET = ['q' => 'zufall', 'role' => 'student'];
        $result = $this->page($id, UserSearchController::class, 'search');
        self::assertTrue($result['success']);
        self::assertCount(2, $result['users'], 'inaktive Konten fehlen');
        $zoe = array_values(array_filter($result['users'], static fn (array $u): bool => $u['username'] === 'zoe.zufall'))[0];
        self::assertSame(['id', 'name', 'username', 'role', 'kurse'], array_keys($zoe));
        self::assertSame('Zoe Zufall', $zoe['name']);
        self::assertSame('student', $zoe['role']);
        self::assertSame([['id' => (int) $course['id'], 'name' => '10a']], $zoe['kurse']);

        $_GET = ['q' => 'zoe', 'role' => 'student', 'course_id' => (string) $course['id']];
        self::assertCount(1, $this->page($id, UserSearchController::class, 'search')['users']);
        $_GET = ['q' => 'zara', 'role' => 'student', 'course_id' => (string) $course['id']];
        self::assertCount(0, $this->page($id, UserSearchController::class, 'search')['users']);

        $_GET = ['q' => 'lehrerin', 'role' => 'teacher'];
        $teachers = $this->page($id, UserSearchController::class, 'search')['users'];
        self::assertCount(1, $teachers);
        self::assertSame('teacher', $teachers[0]['role']);

        $_GET = ['role' => 'wizard'];
        $bad = $this->page($id, UserSearchController::class, 'search');
        self::assertFalse($bad['success']);
    }

    public function testPersonensucheBegrenztAufZwanzigTreffer(): void
    {
        $teacher = $this->createUser('teacher');
        for ($i = 0; $i < 25; $i++) {
            $this->createUser('student', ['lastname' => 'Masse']);
        }
        $_GET = ['q' => 'masse'];

        self::assertCount(20, $this->page((int) $teacher['id'], UserSearchController::class, 'search')['users']);
    }

    public function testBenutzernamenVorschlagIstEindeutig(): void
    {
        $teacher = $this->createUser('teacher');
        $this->createUser('student', ['username' => 'anna.mueller']);
        $_GET = ['vorname' => 'Anna', 'nachname' => 'Müller'];

        $result = $this->page((int) $teacher['id'], UserSearchController::class, 'suggestUsername');

        self::assertSame('anna.mueller2', $result['username']);
    }

    public function testMitgliedHinzufuegenPerJson(): void
    {
        $teacher = $this->createUser('teacher');
        $course = $this->createCourse('10a', 10, (int) $teacher['id']);
        $student = $this->createUser('student');
        $this->ctx = $this->context((int) $teacher['id']);
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $this->ctx->csrf->token();
        $_POST = ['user_id' => (string) $student['id']];

        $result = (new CoursesController($this->ctx))->addMember(['id' => $course['id']]);

        self::assertTrue($result['success']);
        self::assertTrue($result['added']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM course_members WHERE course_id = ?', [$course['id']]));

        $again = (new CoursesController($this->ctx))->addMember(['id' => $course['id']]);
        self::assertFalse($again['added']);

        // Ohne CSRF-Token wird abgelehnt.
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $this->expectException(HttpException::class);
        (new CoursesController($this->ctx))->addMember(['id' => $course['id']]);
    }

    public function testFremderKursLaesstKeineMitgliederHinzufuegen(): void
    {
        $owner = $this->createUser('teacher');
        $intruder = $this->createUser('teacher');
        $course = $this->createCourse('10a', 10, (int) $owner['id']);
        $student = $this->createUser('student');
        $this->ctx = $this->context((int) $intruder['id']);
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $this->ctx->csrf->token();
        $_POST = ['user_id' => (string) $student['id']];

        try {
            (new CoursesController($this->ctx))->addMember(['id' => $course['id']]);
            self::fail('403 erwartet');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM course_members WHERE course_id = ?', [$course['id']]));
    }

    public function testFaelligeKlausurErscheintImDashboardFuerAdmins(): void
    {
        $admin = $this->createUser('admin');
        $exam = $this->createExam('Uralt', null, 'returned', '2020-01-01 10:00:00', '2020-02-01 10:00:00');
        self::assertGreaterThan(0, (int) $exam['id']);

        $html = $this->page((int) $admin['id'], DashboardController::class, 'index');

        self::assertStringContainsString('Aufbewahrungsfrist', $html);
        self::assertStringContainsString('RUNNER_TOKEN', $html);
        self::assertStringContainsString('Mailserver', $html);
    }

    public function testDashboardZeigtEigeneKlausurenNachZustand(): void
    {
        $teacher = $this->createUser('teacher');
        $aide = $this->createUser('teacher');
        $course = $this->createCourse('10a', 10, (int) $teacher['id']);
        $live = $this->createExam('Läuft gerade', (int) $course['id'], 'running');
        $this->createExam('Geplante Arbeit', (int) $course['id'], 'ready');
        $this->createExam('Wird korrigiert', (int) $course['id'], 'grading');
        $this->createExam('Archivierte', (int) $course['id'], 'archived');
        $foreign = $this->createExam('Fremde Klausur', null, 'running');
        $this->db->run('UPDATE exams SET owner_id = ? WHERE id <> ?', [$teacher['id'], $foreign['id']]);
        $this->db->run('UPDATE exams SET owner_id = ? WHERE id = ?', [$aide['id'], $foreign['id']]);
        $this->db->run('INSERT INTO exam_staff (exam_id, user_id) VALUES (?, ?)', [$live['id'], $aide['id']]);

        $html = $this->page((int) $teacher['id'], DashboardController::class, 'index');

        self::assertStringContainsString('Läuft gerade', $html);
        self::assertStringContainsString('/klausuren/' . $live['id'] . '/pult', $html);
        self::assertStringContainsString('Geplante Arbeit', $html);
        self::assertStringContainsString('Wird korrigiert', $html);
        self::assertStringNotContainsString('Archivierte', $html);
        self::assertStringNotContainsString('Fremde Klausur', $html);
        self::assertStringNotContainsString('RUNNER_TOKEN', $html, 'Hinweise nur für Admins');

        $aideHtml = $this->page((int) $aide['id'], DashboardController::class, 'index');
        self::assertStringContainsString('Läuft gerade', $aideHtml, 'Aufsicht sieht die Klausur');
        self::assertStringContainsString('Fremde Klausur', $aideHtml);
    }
}
