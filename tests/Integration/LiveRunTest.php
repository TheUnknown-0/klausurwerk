<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\RunController;
use App\Core\HttpException;
use App\Services\ExamClock;
use App\Services\RunLimiter;
use Tests\Support\DatabaseTestCase;
use Tests\Support\LiveFixtures;

/** Begrenzung der Java-Läufe und Zugangsprüfungen des Run-Controllers (ohne echten Runner). */
final class LiveRunTest extends DatabaseTestCase
{
    use LiveFixtures;

    private RunLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter = new RunLimiter($this->db);
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        parent::tearDown();
    }

    private function expectStatus(int $status, callable $fn): void
    {
        try {
            $fn();
            self::fail("HTTP {$status} erwartet");
        } catch (HttpException $e) {
            self::assertSame($status, $e->status, $e->getMessage());
        }
    }

    // ---------- RunLimiter ----------

    public function testOnlyOneRunningRunPerPerson(): void
    {
        $user = $this->makeUser('student');
        $now = self::T0;
        $this->limiter->check($user, $now);
        $this->limiter->register('r_1', $user, null, null, $now);

        $this->expectStatus(429, fn () => $this->limiter->check($user, $now + 1000));
    }

    public function testFinishedRunFreesTheSlot(): void
    {
        $user = $this->makeUser('student');
        $this->limiter->register('r_1', $user, null, null, self::T0);
        $this->limiter->finish('r_1', self::T0 + 500);
        $this->limiter->check($user, self::T0 + 1000);
        self::assertNull($this->limiter->running($user, self::T0 + 1000));
    }

    public function testAbandonedRunFreesTheSlot(): void
    {
        $user = $this->makeUser('student');
        $this->limiter->register('r_1', $user, null, null, self::T0);
        $this->limiter->touch('r_1', self::T0 + 2000);

        self::assertSame('r_1', $this->limiter->running($user, self::T0 + 9000));
        self::assertNull($this->limiter->running($user, self::T0 + 2000 + RunLimiter::ABANDON_MS + 1));
    }

    public function testNeverPolledRunBlocksNextStartUntilRunnerHasReapedIt(): void
    {
        // Wer Läufe startet und nie abfragt, darf nicht alle paar Sekunden den nächsten starten.
        $user = $this->makeUser('student');
        $this->limiter->register('r_1', $user, null, null, self::T0);

        self::assertNull($this->limiter->running($user, self::T0 + RunLimiter::ABANDON_MS + 1));
        $this->expectStatus(429, fn () => $this->limiter->check($user, self::T0 + RunLimiter::ABANDON_MS + 1));
        $this->expectStatus(429, fn () => $this->limiter->check($user, self::T0 + RunLimiter::LINGER_MS - 1));
        // Nach dem Abbruch durch den Runner (60 s ohne Abfrage) ist der Platz frei.
        $this->limiter->check($user, self::T0 + RunLimiter::LINGER_MS + 1);
        self::assertTrue(true);
    }

    public function testRunningPollKeepsTheSlotBusy(): void
    {
        $user = $this->makeUser('student');
        $this->limiter->register('r_1', $user, null, null, self::T0);
        for ($t = 1; $t <= 20; $t++) {
            $this->limiter->touch('r_1', self::T0 + $t * 5000);
        }
        self::assertSame('r_1', $this->limiter->running($user, self::T0 + 20 * 5000 + 1000));
    }

    public function testThirtyStartsPerMinute(): void
    {
        $user = $this->makeUser('student');
        for ($i = 0; $i < RunLimiter::MAX_STARTS_PER_MINUTE; $i++) {
            $at = self::T0 + $i * 1500;
            $this->limiter->check($user, $at);
            $this->limiter->register('r_' . $i, $user, null, null, $at);
            $this->limiter->finish('r_' . $i, $at + 100);
        }
        $this->expectStatus(429, fn () => $this->limiter->check($user, self::T0 + 31 * 1500));
        // Eine Minute nach dem ersten Start ist wieder Platz.
        $this->limiter->check($user, self::T0 + 60_000 + 1500);
        self::assertTrue(true);
    }

    public function testLimitsArePerPerson(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $b = $this->makeUser('student', 'Ben');
        $this->limiter->register('r_a', $a, null, null, self::T0);
        $this->limiter->check($b, self::T0 + 100);
        self::assertTrue(true);
    }

    public function testRunIdBelongsToOwner(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $b = $this->makeUser('student', 'Ben');
        $this->limiter->register('r_geheim', $a, null, null, self::T0);

        self::assertNotNull($this->limiter->find('r_geheim', $a));
        self::assertNull($this->limiter->find('r_geheim', $b));
        self::assertNull($this->limiter->find('r_gibtsnicht', $a));
    }

    public function testGenericRateLimit(): void
    {
        $user = $this->makeUser('student');
        for ($i = 0; $i < 3; $i++) {
            $this->limiter->hit('test', $user, 3, 60_000, self::T0 + $i);
        }
        $this->expectStatus(429, fn () => $this->limiter->hit('test', $user, 3, 60_000, self::T0 + 10));
    }

    public function testGenericRateLimitWindowExpires(): void
    {
        $user = $this->makeUser('student');
        $this->limiter->hit('test', $user, 1, 1000, self::T0);
        $this->limiter->hit('test', $user, 1, 1000, self::T0 + 1001);
        self::assertTrue(true);
    }

    public function testCleanupRemovesOldRows(): void
    {
        $user = $this->makeUser('student');
        $this->limiter->register('r_old', $user, null, null, self::T0 - 10 * 3_600_000);
        $this->limiter->register('r_new', $user, null, null, self::T0);
        $this->limiter->cleanup(self::T0);
        self::assertNull($this->limiter->find('r_old', $user));
        self::assertNotNull($this->limiter->find('r_new', $user));
    }

    // ---------- RunController: Zugang ----------

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function start(?int $userId, array $input): array
    {
        $ctx = $this->makeContext($userId);
        $_POST = $input;

        return (new RunController($ctx))->start([]);
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: int} */
    private function javaScene(array $taskConfig = [], array $settings = []): array
    {
        $student = $this->makeUser('student', 'Mia');
        $exam = $this->makeExam($this->makeCourse([$student]), 'running', [], $settings);
        $task = $this->makeTask((int) $exam['id'], 'code', null, $taskConfig + ['language' => 'java', 'main_file' => 'Main.java', 'allow_new_files' => false]);
        $this->db->run("INSERT INTO task_files (task_id, filename, content, is_readonly, position) VALUES (?, 'Main.java', 'class Main {}', 0, 0)", [$task]);
        $this->db->run("INSERT INTO task_files (task_id, filename, content, is_readonly, position) VALUES (?, 'Lib.java', 'class Lib {}', 1, 1)", [$task]);
        $this->makeParticipation((int) $exam['id'], $student, 'active');

        return [$student, $exam, $task];
    }

    public function testStudentNeedsActiveParticipation(): void
    {
        [$student, $exam, $task] = $this->javaScene();
        $this->db->run("UPDATE participations SET state = 'locked' WHERE user_id = ?", [$student]);

        $this->expectStatus(403, fn () => $this->start($student, [
            'exam_id' => $exam['id'], 'task_id' => $task, 'language' => 'java',
            'files' => [['name' => 'Main.java', 'content' => 'x']], 'mode' => 'run',
        ]));
    }

    public function testStudentOutsideTheCourseIsRejected(): void
    {
        [, $exam, $task] = $this->javaScene();
        $outsider = $this->makeUser('student', 'Otto');
        $this->expectStatus(403, fn () => $this->start($outsider, [
            'exam_id' => $exam['id'], 'task_id' => $task, 'files' => [['name' => 'Main.java', 'content' => 'x']],
        ]));
    }

    public function testRunModeOfTheTaskDecides(): void
    {
        [$student, $exam, $task] = $this->javaScene(['run_mode' => 'none']);
        $input = ['exam_id' => $exam['id'], 'task_id' => $task, 'files' => [['name' => 'Main.java', 'content' => 'x']]];
        $this->expectStatus(403, fn () => $this->start($student, $input + ['mode' => 'run']));
        $this->expectStatus(403, fn () => $this->start($student, $input + ['mode' => 'syntax']));
    }

    public function testSyntaxOnlyAllowsSyntaxMode(): void
    {
        [$student, $exam, $task] = $this->javaScene(['run_mode' => 'syntax']);
        $input = ['exam_id' => $exam['id'], 'task_id' => $task, 'files' => [['name' => 'Main.java', 'content' => 'x']]];
        $this->expectStatus(403, fn () => $this->start($student, $input + ['mode' => 'run']));
        // Syntaxprüfung ist erlaubt und scheitert erst am (hier nicht vorhandenen) Runner.
        $this->assertRunnerUnavailable($student, $input + ['mode' => 'syntax']);
    }

    public function testExamSettingIsDefaultWhenTaskHasNoOverride(): void
    {
        [$student, $exam, $task] = $this->javaScene([], ['run_mode' => 'none']);
        $this->expectStatus(403, fn () => $this->start($student, [
            'exam_id' => $exam['id'], 'task_id' => $task, 'files' => [['name' => 'Main.java', 'content' => 'x']],
        ]));
    }

    public function testNonJavaTaskIsRejected(): void
    {
        [$student, $exam, $task] = $this->javaScene(['language' => 'python']);
        $this->expectStatus(400, fn () => $this->start($student, [
            'exam_id' => $exam['id'], 'task_id' => $task, 'files' => [['name' => 'Main.java', 'content' => 'x']],
        ]));
        $r = $this->start($student, ['language' => 'python', 'exam_id' => $exam['id'], 'task_id' => $task, 'files' => []]);
        self::assertFalse($r['success']);
    }

    public function testFileValidation(): void
    {
        [$student, $exam, $task] = $this->javaScene();
        $base = ['exam_id' => $exam['id'], 'task_id' => $task];
        $bad = [
            'keine Dateien' => [],
            'Pfad im Namen' => [['name' => '../Main.java', 'content' => 'x']],
            'neue Datei nicht erlaubt' => [['name' => 'Main.java', 'content' => 'x'], ['name' => 'Neu.java', 'content' => 'x']],
            'zu groß' => [['name' => 'Main.java', 'content' => str_repeat('x', 200001)]],
            'Inhalt fehlt' => [['name' => 'Main.java']],
        ];
        foreach ($bad as $name => $files) {
            $this->expectStatus(400, fn () => $this->start($student, $base + ['files' => $files]));
            self::assertNotSame('', $name);
        }
        $this->expectStatus(400, fn () => $this->start($student, $base + ['files' => [['name' => 'Main.java', 'content' => 'x']], 'main_file' => 'Fehlt.java']));
    }

    public function testValidStudentRequestReachesTheRunner(): void
    {
        [$student, $exam, $task] = $this->javaScene();
        $this->assertRunnerUnavailable($student, [
            'exam_id' => $exam['id'], 'task_id' => $task, 'mode' => 'run',
            // Eine Datei mit dem Namen der schreibgeschützten wird ignoriert, nicht abgelehnt.
            'files' => [['name' => 'Main.java', 'content' => 'class Main {}'], ['name' => 'Lib.java', 'content' => 'MANIPULIERT']],
        ]);
    }

    public function testTeacherCanRunFreely(): void
    {
        $teacher = $this->makeUser('teacher', 'Lehrer');
        $this->assertRunnerUnavailable($teacher, ['language' => 'java', 'files' => [['name' => 'Main.java', 'content' => 'x']]]);
    }

    public function testTeacherWithExamNeedsViewRight(): void
    {
        [, $exam, $task] = $this->javaScene();
        $stranger = $this->makeUser('teacher', 'Fremd');
        $this->expectStatus(403, fn () => $this->start($stranger, [
            'exam_id' => $exam['id'], 'task_id' => $task, 'files' => [['name' => 'Main.java', 'content' => 'x']],
        ]));
    }

    public function testStartNeedsLogin(): void
    {
        $this->expectStatus(401, fn () => $this->start(null, ['files' => []]));
    }

    public function testForeignRunIdIs404ForPollInputAndStop(): void
    {
        $owner = $this->makeUser('student', 'Anna');
        $other = $this->makeUser('student', 'Ben');
        $this->limiter->register('r_geheim', $owner, null, null, ExamClock::nowMs());

        foreach (['poll', 'input', 'stop'] as $action) {
            $this->expectStatus(404, function () use ($other, $action): void {
                $ctx = $this->makeContext($other);
                $_POST = ['text' => 'hallo'];
                (new RunController($ctx))->$action(['runId' => 'r_geheim']);
            });
        }
        $this->expectStatus(404, function () use ($other): void {
            $ctx = $this->makeContext($other);
            (new RunController($ctx))->poll(['runId' => 'r_gibtsnicht']);
        });
    }

    public function testSecondStartWhileRunningIs429(): void
    {
        [$student, $exam, $task] = $this->javaScene();
        $this->limiter->register('r_laeuft', $student, (int) $exam['id'], $task, ExamClock::nowMs());

        $this->expectStatus(429, fn () => $this->start($student, [
            'exam_id' => $exam['id'], 'task_id' => $task, 'files' => [['name' => 'Main.java', 'content' => 'x']],
        ]));
    }

    /** Erwartet, dass die Anfrage alle Prüfungen besteht und erst am Runner (503) scheitert. */
    private function assertRunnerUnavailable(int $userId, array $input): void
    {
        try {
            $r = $this->start($userId, $input);
            self::assertFalse($r['success']);
            self::assertSame(503, http_response_code());
        } catch (HttpException $e) {
            self::assertSame(503, $e->status, $e->getMessage());
        }
    }
}
