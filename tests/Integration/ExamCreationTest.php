<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Services\AnswerFiles;
use App\Services\ExamAccess;
use App\Services\ExamService;
use App\Services\ExamSettings;
use App\Services\Materials;
use App\Services\TaskService;
use Tests\Support\DatabaseTestCase;

/** Anlegen mit Profil oder Vorlage, Klausurcode, Profil anwenden. */
final class ExamCreationTest extends DatabaseTestCase
{
    private ExamService $exams;
    private TaskService $tasks;
    private int $teacherId;
    private string $uploadDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploadDir = sys_get_temp_dir() . '/kw-test-' . bin2hex(random_bytes(6));
        mkdir($this->uploadDir, 0775, true);
        $materials = new Materials($this->db, $this->uploadDir);
        $this->tasks = new TaskService($this->db, $materials);
        $this->exams = new ExamService($this->db, $this->tasks, $materials, new AnswerFiles($this->db, $this->uploadDir));
        $this->db->run("INSERT INTO users (username, firstname, lastname, role) VALUES (?, 'Lena', 'Lehrer', 'teacher')", ['lehrer' . bin2hex(random_bytes(3))]);
        $this->teacherId = $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @rmdir($this->uploadDir);
    }

    /** @return array<string, mixed> */
    private function exam(int $id): array
    {
        return $this->db->fetchOne('SELECT * FROM exams WHERE id = ?', [$id]);
    }

    public function testStandardOhneCode(): void
    {
        $id = $this->exams->create(['title' => 'Q1', 'mode' => 'exam', 'duration_minutes' => 90], $this->teacherId);
        $exam = $this->exam($id);
        self::assertNull($exam['access_code']);
        self::assertFalse(ExamAccess::settingsOf($exam)['require_access_code']);
    }

    public function testProfilStrengErzeugtKlausurcode(): void
    {
        $id = $this->exams->create(['title' => 'Q1', 'mode' => 'exam', 'duration_minutes' => 90], $this->teacherId, ExamSettings::profile('streng', 'exam'));
        $exam = $this->exam($id);
        self::assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', (string) $exam['access_code']);
        self::assertSame('streng', ExamSettings::matchingProfile(ExamAccess::settingsOf($exam), 'exam'));
    }

    public function testKopieEinerKlausurMitCodeBekommtEigenenCode(): void
    {
        $src = $this->exams->create(['title' => 'Vorlage', 'mode' => 'exam', 'duration_minutes' => 90], $this->teacherId, ExamSettings::profile('streng', 'exam'));
        $this->tasks->create($this->exam($src), null, 'text', 'Erläutere');
        $copy = $this->exams->copy($this->exam($src), $this->teacherId, 'Neu');
        $exam = $this->exam($copy);
        self::assertSame('Neu', $exam['title']);
        self::assertNotNull($exam['access_code']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM tasks WHERE exam_id = ?', [$copy]));
    }

    public function testProfilAnwendenSetztSchalterUndCode(): void
    {
        $id = $this->exams->create(['title' => 'Übung', 'mode' => 'practice'], $this->teacherId);
        $this->exams->applyProfile($this->exam($id), 'streng');
        $exam = $this->exam($id);
        self::assertTrue(ExamAccess::settingsOf($exam)['lock_on_violation']);
        self::assertNotNull($exam['access_code']);

        $this->exams->applyProfile($this->exam($id), 'locker');
        $s = ExamAccess::settingsOf($this->exam($id));
        self::assertFalse($s['require_access_code']);
        self::assertTrue($s['aids']['calculator']);
    }

    public function testProfilNachDemStartGesperrt(): void
    {
        $id = $this->exams->create(['title' => 'Q1', 'mode' => 'exam', 'duration_minutes' => 90], $this->teacherId);
        $this->db->run("UPDATE exams SET status = 'running' WHERE id = ?", [$id]);
        $this->expectException(HttpException::class);
        $this->exams->applyProfile($this->exam($id), 'streng');
    }
}
