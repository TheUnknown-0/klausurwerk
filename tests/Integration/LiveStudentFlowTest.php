<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\StudentExamController;
use App\Core\HttpException;
use App\Services\DeviceBinding;
use App\Services\ExamClock;
use Tests\Support\DatabaseTestCase;
use Tests\Support\LiveFixtures;

/** Schüler-Controller ohne HTTP: Beitritt, Inhalt, Ereignisse, Anfragen, Herzschlag, Autosave. */
final class LiveStudentFlowTest extends DatabaseTestCase
{
    use LiveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_COOKIE = [];
        unset($_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH']);
        parent::tearDown();
    }

    /**
     * Ruft eine Aktion als Benutzer auf.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|string
     */
    private function call(?int $userId, string $action, int $examId, array $input = [], array $extra = []): array|string
    {
        $ctx = $this->makeContext($userId);
        $_POST = $input;

        return (new StudentExamController($ctx))->$action(['id' => (string) $examId] + $extra);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function setup1(string $status = 'lobby', array $settings = [], array $examOverrides = []): array
    {
        $student = $this->makeUser('student', 'Mia');
        $course = $this->makeCourse([$student]);
        $exam = $this->makeExam($course, $status, $examOverrides, $settings);

        return [$student, $exam];
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

    // ---------- Beitritt ----------

    public function testJoinCreatesWaitingParticipationWithDeviceCookie(): void
    {
        [$student, $exam] = $this->setup1('lobby');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $r = $this->call($student, 'join', (int) $exam['id'], ['client' => ['ua' => 'Test', 'screen' => ['w' => 1920, 'h' => 1080], 'seb' => false]]);

        self::assertTrue($r['success']);
        self::assertSame('waiting', $r['participation']['state']);
        self::assertIsInt($r['server_time_ms']);
        $row = $this->db->fetchOne('SELECT * FROM participations WHERE exam_id = ? AND user_id = ?', [$exam['id'], $student]);
        self::assertNotNull($row);
        self::assertSame('waiting', $row['state']);
        self::assertSame(64, strlen((string) $row['device_hash']));
        self::assertNotSame(0, (int) $row['shuffle_seed']);
        $token = $_COOKIE[DeviceBinding::cookieName((int) $exam['id'])] ?? null;
        self::assertNotNull($token, 'Geräte-Cookie wurde gesetzt');
        self::assertSame(DeviceBinding::hash($token), $row['device_hash'], 'gespeichert wird nur der Hash');
        self::assertContains('joined', $this->eventTypes((int) $row['id']));
    }

    public function testJoinCopiesAccommodationPercent(): void
    {
        $student = $this->makeUser('student', 'Mia', 25);
        $exam = $this->makeExam($this->makeCourse([$student]), 'lobby');
        $this->call($student, 'join', (int) $exam['id']);
        self::assertSame(25, (int) $this->db->fetchValue('SELECT accommodation_percent FROM participations WHERE user_id = ?', [$student]));
    }

    public function testJoinTwiceOnSameDeviceKeepsOneParticipation(): void
    {
        [$student, $exam] = $this->setup1('lobby');
        $this->call($student, 'join', (int) $exam['id']);
        $r = $this->call($student, 'join', (int) $exam['id']);

        self::assertSame('waiting', $r['participation']['state']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM participations WHERE exam_id = ?', [$exam['id']]));
    }

    public function testJoinRequiresCourseMembership(): void
    {
        [, $exam] = $this->setup1('lobby');
        $outsider = $this->makeUser('student', 'Otto');
        $this->expectStatus(403, fn () => $this->call($outsider, 'join', (int) $exam['id']));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM participations'));
    }

    public function testJoinNeedsOpenExam(): void
    {
        [$student, $exam] = $this->setup1('ready');
        $r = $this->call($student, 'join', (int) $exam['id']);
        self::assertFalse($r['success']);

        $this->db->run("UPDATE exams SET status = 'finished' WHERE id = ?", [$exam['id']]);
        $r = $this->call($student, 'join', (int) $exam['id']);
        self::assertFalse($r['success']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM participations'));
    }

    public function testJoinRejectsTeachersAndAnonymous(): void
    {
        [, $exam] = $this->setup1('lobby');
        $teacher = $this->makeUser('teacher', 'Lehrer');
        $this->expectStatus(403, fn () => $this->call($teacher, 'join', (int) $exam['id']));
        $this->expectStatus(401, fn () => $this->call(null, 'join', (int) $exam['id']));
    }

    public function testJoinRequiresCsrf(): void
    {
        [$student, $exam] = $this->setup1('lobby');
        $ctx = $this->makeContext($student);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'falsch';
        $this->expectStatus(419, fn () => (new StudentExamController($ctx))->join(['id' => (string) $exam['id']]));
    }

    public function testAccessCodeIsChecked(): void
    {
        [$student, $exam] = $this->setup1('lobby', ['require_access_code' => true], ['access_code' => 'AB12CD']);

        $this->expectStatus(403, fn () => $this->call($student, 'join', (int) $exam['id'], ['access_code' => 'falsch']));
        $this->expectStatus(403, fn () => $this->call($student, 'join', (int) $exam['id'], []));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM participations'));

        $r = $this->call($student, 'join', (int) $exam['id'], ['access_code' => ' ab12cd ']);
        self::assertTrue($r['success']);
    }

    public function testAccessCodeAttemptsAreLimited(): void
    {
        [$student, $exam] = $this->setup1('lobby', ['require_access_code' => true], ['access_code' => 'AB12CD']);
        for ($i = 0; $i < 10; $i++) {
            $this->expectStatus(403, fn () => $this->call($student, 'join', (int) $exam['id'], ['access_code' => 'x']));
        }
        $this->expectStatus(429, fn () => $this->call($student, 'join', (int) $exam['id'], ['access_code' => 'AB12CD']));
    }

    // ---------- Gerätewechsel ----------

    public function testOtherDeviceWhileRunningBecomesDevicePendingWithRequest(): void
    {
        [$student, $exam] = $this->setup1('running');
        $this->call($student, 'join', (int) $exam['id']);
        $pid = (int) $this->db->fetchValue('SELECT id FROM participations WHERE user_id = ?', [$student]);
        $this->db->run("UPDATE participations SET state = 'active' WHERE id = ?", [$pid]);
        $oldToken = $_COOKIE[DeviceBinding::cookieName((int) $exam['id'])];

        unset($_COOKIE[DeviceBinding::cookieName((int) $exam['id'])]); // anderes Gerät
        $r = $this->call($student, 'join', (int) $exam['id']);

        self::assertSame('device_pending', $r['participation']['state']);
        $row = $this->reloadParticipation($pid);
        self::assertSame('device_pending', $row['state']);
        self::assertSame('active', $row['resume_state']);
        self::assertSame(DeviceBinding::hash($oldToken), $row['device_hash'], 'altes Gerät bleibt gebunden bis zur Freigabe');
        self::assertNotNull($row['pending_device_hash']);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM exam_requests WHERE participation_id = ? AND type = 'device' AND status = 'open'", [$pid]));
        self::assertContains('device_change_requested', $this->eventTypes($pid));

        // Wiederholtes Laden auf dem neuen Gerät legt keine zweite Anfrage an.
        $this->call($student, 'join', (int) $exam['id']);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM exam_requests WHERE participation_id = ? AND type = 'device'", [$pid]));
    }

    public function testOldDeviceIsShutOutAfterApproval(): void
    {
        [$student, $exam] = $this->setup1('running');
        $examId = (int) $exam['id'];
        $this->call($student, 'join', $examId);
        $pid = (int) $this->db->fetchValue('SELECT id FROM participations WHERE user_id = ?', [$student]);
        $this->db->run("UPDATE participations SET state = 'active' WHERE id = ?", [$pid]);
        $old = $_COOKIE[DeviceBinding::cookieName($examId)];
        unset($_COOKIE[DeviceBinding::cookieName($examId)]);
        $this->call($student, 'join', $examId);
        $new = $_COOKIE[DeviceBinding::cookieName($examId)];

        $devices = new DeviceBinding($this->db);
        $devices->approve($pid);
        $this->db->run("UPDATE participations SET state = 'active', pending_device_hash = NULL WHERE id = ?", [$pid]);

        $_COOKIE[DeviceBinding::cookieName($examId)] = $old;
        $r = $this->call($student, 'pulse', $examId, ['client_state' => []]);
        self::assertSame('locked', $r['state']);
        self::assertStringContainsString('Gerät', (string) $r['lock_reason']);
        $this->expectStatus(403, fn () => $this->call($student, 'save', $examId, ['items' => [], 'contents' => []]));

        $_COOKIE[DeviceBinding::cookieName($examId)] = $new;
        self::assertSame('active', $this->call($student, 'pulse', $examId, ['client_state' => []])['state']);
    }

    // ---------- Inhalt ----------

    public function testContentBeforeStartIs409(): void
    {
        [$student, $exam] = $this->setup1('lobby');
        $this->makeTask((int) $exam['id'], 'text');
        $this->call($student, 'join', (int) $exam['id']);

        $r = $this->call($student, 'content', (int) $exam['id']);
        self::assertFalse($r['success']);
        self::assertArrayNotHasKey('tasks', $r);
    }

    public function testContentWhileRunningButWaitingIs409(): void
    {
        [$student, $exam] = $this->setup1('running');
        $this->makeTask((int) $exam['id'], 'text');
        $this->call($student, 'join', (int) $exam['id']);

        $r = $this->call($student, 'content', (int) $exam['id']);
        self::assertFalse($r['success']);
    }

    public function testContentAfterStartContainsTasksAndOwnAnswers(): void
    {
        [$student, $exam] = $this->setup1('running');
        $task = $this->makeTask((int) $exam['id'], 'text');
        $this->call($student, 'join', (int) $exam['id']);
        $pid = (int) $this->db->fetchValue('SELECT id FROM participations WHERE user_id = ?', [$student]);
        $this->db->run("UPDATE participations SET state = 'active', last_seq = 7 WHERE id = ?", [$pid]);
        $this->db->run("INSERT INTO answers (participation_id, task_id, content, version, flagged, updated_at) VALUES (?, ?, '{\"text\":\"hallo\"}', 3, 1, ?)", [$pid, $task, ExamClock::toDb(self::T0)]);

        $r = $this->call($student, 'content', (int) $exam['id']);

        self::assertTrue($r['success']);
        self::assertSame($task, $r['tasks'][0]['id']);
        self::assertSame(['content' => ['text' => 'hallo'], 'version' => 3, 'flagged' => true], $r['answers'][$task]);
        self::assertSame(7, $r['last_seq']);
        self::assertSame('Testklausur', $r['exam']['title']);
    }

    public function testTeacherWithViewGetsPreviewContentWithoutParticipation(): void
    {
        $teacher = $this->makeUser('teacher', 'Lehrer');
        $exam = $this->makeExam(null, 'draft', ['owner_id' => $teacher]);
        $task = $this->makeTask((int) $exam['id'], 'text');

        $r = $this->call($teacher, 'content', (int) $exam['id']);

        self::assertTrue($r['success']);
        self::assertSame($task, $r['tasks'][0]['id']);
        self::assertEquals((object) [], $r['answers']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM participations'));
    }

    public function testForeignTeacherCannotPreview(): void
    {
        $owner = $this->makeUser('teacher', 'Besitzer');
        $other = $this->makeUser('teacher', 'Fremd');
        $exam = $this->makeExam(null, 'running', ['owner_id' => $owner]);
        $this->expectStatus(403, fn () => $this->call($other, 'content', (int) $exam['id']));
    }

    public function testTeachersAreRejectedByOtherStudentApis(): void
    {
        $teacher = $this->makeUser('teacher', 'Lehrer');
        $exam = $this->makeExam(null, 'running', ['owner_id' => $teacher]);
        foreach (['pulse', 'save', 'event', 'request', 'ready', 'upload'] as $action) {
            $this->expectStatus(403, fn () => $this->call($teacher, $action, (int) $exam['id']));
        }
    }

    // ---------- Bereit / späte Teilnahme ----------

    public function testReadyMarksParticipantAndActivatesLateJoinerOnRunningExam(): void
    {
        [$student, $exam] = $this->setup1('running');
        $this->call($student, 'join', (int) $exam['id']);

        $r = $this->call($student, 'ready', (int) $exam['id']);

        self::assertTrue($r['success']);
        self::assertSame('active', $r['state']);
        $row = $this->db->fetchOne('SELECT * FROM participations WHERE user_id = ?', [$student]);
        self::assertNotNull($row['ready_at']);
        self::assertContains('ready', $this->eventTypes((int) $row['id']));
    }

    public function testReadyInLobbyKeepsWaiting(): void
    {
        [$student, $exam] = $this->setup1('lobby');
        $this->call($student, 'join', (int) $exam['id']);
        $r = $this->call($student, 'ready', (int) $exam['id']);
        self::assertSame('waiting', $r['state']);
    }

    public function testApiNeedsParticipation(): void
    {
        [$student, $exam] = $this->setup1('running');
        $this->expectStatus(409, fn () => $this->call($student, 'pulse', (int) $exam['id']));
    }

    // ---------- Ereignisse ----------

    /** @return array{0: int, 1: array<string, mixed>, 2: int} */
    private function activeStudent(string $status = 'running', array $settings = [], array $examOverrides = []): array
    {
        [$student, $exam] = $this->setup1($status, $settings, $examOverrides);
        $this->call($student, 'join', (int) $exam['id']);
        $pid = (int) $this->db->fetchValue('SELECT id FROM participations WHERE user_id = ?', [$student]);
        $this->db->run("UPDATE participations SET state = 'active' WHERE id = ?", [$pid]);

        return [$student, $exam, $pid];
    }

    public function testViolationLocksWhenConfigured(): void
    {
        [$student, $exam, $pid] = $this->activeStudent();

        $r = $this->call($student, 'event', (int) $exam['id'], ['events' => [['type' => 'fullscreen_exit', 'client_ts' => self::T0, 'details' => []]]]);

        self::assertTrue($r['success']);
        self::assertSame('locked', $r['state']);
        self::assertSame('Vollbild verlassen', $r['lock_reason']);
        self::assertSame('locked', $this->reloadParticipation($pid)['state']);
        $event = $this->db->fetchOne("SELECT * FROM participation_events WHERE participation_id = ? AND type = 'fullscreen_exit'", [$pid]);
        self::assertSame('alarm', $event['severity']);
        self::assertSame(self::T0, (int) $event['client_ts']);
        self::assertContains('state_changed', $this->eventTypes($pid));
    }

    public function testViolationDoesNotLockInPracticeMode(): void
    {
        [$student, $exam, $pid] = $this->activeStudent('running', ['lock_on_violation' => true], ['mode' => 'practice']);

        $r = $this->call($student, 'event', (int) $exam['id'], ['events' => [['type' => 'fullscreen_exit'], ['type' => 'visibility_hidden']]]);

        self::assertSame('active', $r['state']);
        self::assertSame('active', $this->reloadParticipation($pid)['state']);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM participation_events WHERE participation_id = ? AND severity = 'alarm'", [$pid]));
        self::assertContains('fullscreen_exit', $this->eventTypes($pid));
    }

    public function testViolationDoesNotLockWithoutLockOnViolation(): void
    {
        [$student, $exam, $pid] = $this->activeStudent('running', ['lock_on_violation' => false]);
        $r = $this->call($student, 'event', (int) $exam['id'], ['events' => [['type' => 'fullscreen_exit']]]);
        self::assertSame('active', $r['state']);
        self::assertSame('alarm', $this->db->fetchValue("SELECT severity FROM participation_events WHERE participation_id = ? AND type = 'fullscreen_exit'", [$pid]));
    }

    public function testShortBlurIsOnlyInfo(): void
    {
        [$student, $exam, $pid] = $this->activeStudent();
        $r = $this->call($student, 'event', (int) $exam['id'], ['events' => [['type' => 'window_blur', 'details' => ['duration_ms' => 100]]]]);
        self::assertSame('active', $r['state']);
        self::assertSame('info', $this->db->fetchValue("SELECT severity FROM participation_events WHERE participation_id = ? AND type = 'window_blur'", [$pid]));

        $r = $this->call($student, 'event', (int) $exam['id'], ['events' => [['type' => 'window_blur', 'details' => ['duration_ms' => 4000]]]]);
        self::assertSame('locked', $r['state']);
    }

    public function testNonViolationEventsDoNotLock(): void
    {
        [$student, $exam] = $this->activeStudent();
        $r = $this->call($student, 'event', (int) $exam['id'], ['events' => [
            ['type' => 'paste_blocked', 'details' => ['length' => 40]],
            ['type' => 'resize', 'details' => ['w' => 800, 'h' => 600]],
        ]]);
        self::assertSame('active', $r['state']);
    }

    public function testUnknownEventTypeRejectsWholeRequest(): void
    {
        [$student, $exam, $pid] = $this->activeStudent();
        foreach (['state_changed', 'time_up', 'seb_missing', 'connection_lost', 'zaubern', ''] as $type) {
            $r = $this->call($student, 'event', (int) $exam['id'], ['events' => [['type' => 'resize'], ['type' => $type]]]);
            self::assertFalse($r['success'], $type);
        }
        self::assertNotContains('resize', $this->eventTypes($pid), 'nichts geschrieben, wenn ein Typ ungültig ist');
    }

    public function testMoreThan50EventsInOneRequestRejected(): void
    {
        [$student, $exam, $pid] = $this->activeStudent();
        $events = array_fill(0, 51, ['type' => 'resize']);
        $r = $this->call($student, 'event', (int) $exam['id'], ['events' => $events]);
        self::assertFalse($r['success']);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM participation_events WHERE participation_id = ? AND type = 'resize'", [$pid]));

        $r = $this->call($student, 'event', (int) $exam['id'], ['events' => array_fill(0, 50, ['type' => 'resize'])]);
        self::assertTrue($r['success']);
    }

    public function testEventDetailsAreSanitised(): void
    {
        [$student, $exam, $pid] = $this->activeStudent();
        $this->call($student, 'event', (int) $exam['id'], ['events' => [['type' => 'resize', 'details' => [
            'w' => 1, 'text' => str_repeat('ä', 500), 'nested' => ['x' => 1], 'bad key!' => 1,
        ]]]]);
        $details = json_decode((string) $this->db->fetchValue("SELECT details FROM participation_events WHERE participation_id = ? AND type = 'resize'", [$pid]), true);
        self::assertSame(1, $details['w']);
        self::assertSame(200, mb_strlen($details['text']));
        self::assertArrayNotHasKey('nested', $details);
        self::assertArrayNotHasKey('bad key!', $details);
    }

    // ---------- Anfragen ----------

    public function testHandRaiseAndLower(): void
    {
        [$student, $exam, $pid] = $this->activeStudent();
        $this->call($student, 'request', (int) $exam['id'], ['type' => 'hand']);
        self::assertNotNull($this->reloadParticipation($pid)['hand_raised_at']);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM exam_requests WHERE participation_id = ? AND type = 'hand' AND status = 'open'", [$pid]));

        $this->call($student, 'request', (int) $exam['id'], ['type' => 'hand']);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM exam_requests WHERE participation_id = ? AND type = 'hand'", [$pid]), 'kein zweiter offener Eintrag');

        $this->call($student, 'request', (int) $exam['id'], ['type' => 'hand', 'cancel' => true]);
        self::assertNull($this->reloadParticipation($pid)['hand_raised_at']);
        self::assertSame('cancelled', $this->db->fetchValue("SELECT status FROM exam_requests WHERE participation_id = ? AND type = 'hand'", [$pid]));
        self::assertContains('hand_raised', $this->eventTypes($pid));
        self::assertContains('hand_lowered', $this->eventTypes($pid));
    }

    public function testReleaseRequestOnlyWhenBlocked(): void
    {
        [$student, $exam, $pid] = $this->activeStudent();
        $r = $this->call($student, 'request', (int) $exam['id'], ['type' => 'release']);
        self::assertFalse($r['success']);

        $this->db->run("UPDATE participations SET state = 'locked' WHERE id = ?", [$pid]);
        $r = $this->call($student, 'request', (int) $exam['id'], ['type' => 'release']);
        self::assertTrue($r['success']);
        $this->call($student, 'request', (int) $exam['id'], ['type' => 'release']);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM exam_requests WHERE participation_id = ? AND type = 'release' AND status = 'open'", [$pid]));
    }

    public function testSubmitRequestAndWithdraw(): void
    {
        [$student, $exam, $pid] = $this->activeStudent();

        $r = $this->call($student, 'request', (int) $exam['id'], ['type' => 'submit']);
        self::assertSame('submit_requested', $r['state']);
        self::assertSame('submit_requested', $this->reloadParticipation($pid)['state']);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM exam_requests WHERE participation_id = ? AND type = 'submit' AND status = 'open'", [$pid]));

        $r = $this->call($student, 'request', (int) $exam['id'], ['type' => 'submit', 'cancel' => true]);
        self::assertSame('active', $r['state']);
        self::assertSame('cancelled', $this->db->fetchValue("SELECT status FROM exam_requests WHERE participation_id = ? AND type = 'submit'", [$pid]));
    }

    public function testSelfSubmitCanBeDisabled(): void
    {
        [$student, $exam, $pid] = $this->activeStudent('running', ['allow_self_submit' => false]);
        $r = $this->call($student, 'request', (int) $exam['id'], ['type' => 'submit']);
        self::assertFalse($r['success']);
        self::assertSame('active', $this->reloadParticipation($pid)['state']);
    }

    public function testPracticeSelfSubmitIsFinalImmediately(): void
    {
        [$student, $exam, $pid] = $this->activeStudent('running', [], ['mode' => 'practice']);
        $r = $this->call($student, 'request', (int) $exam['id'], ['type' => 'submit']);
        self::assertSame('submitted', $r['state']);
        self::assertSame('submitted', $this->reloadParticipation($pid)['state']);
    }

    public function testUnknownRequestTypeRejected(): void
    {
        [$student, $exam] = $this->activeStudent();
        $r = $this->call($student, 'request', (int) $exam['id'], ['type' => 'abgeben-sofort']);
        self::assertFalse($r['success']);
    }

    // ---------- Herzschlag ----------

    public function testPulseReturnsStateClockAnnouncementsAndStoresClientState(): void
    {
        [$student, $exam, $pid] = $this->activeStudent();
        $this->db->run('UPDATE exams SET started_at = ? WHERE id = ?', [ExamClock::toDb(ExamClock::nowMs() - 60_000), $exam['id']]);
        $this->db->run('UPDATE participations SET joined_at = ? WHERE id = ?', [ExamClock::toDb(self::T0 - 1000), $pid]);
        $this->db->run('INSERT INTO exam_announcements (exam_id, participation_id, message, created_at) VALUES (?, NULL, ?, ?)', [$exam['id'], 'Für alle', ExamClock::toDb(self::T0)]);
        $this->db->run('INSERT INTO exam_announcements (exam_id, participation_id, message, created_at) VALUES (?, ?, ?, ?)', [$exam['id'], $pid, 'Nur du', ExamClock::toDb(self::T0 + 1)]);
        $other = $this->makeUser('student', 'Fremd');
        $otherP = $this->makeParticipation((int) $exam['id'], $other);
        $this->db->run('INSERT INTO exam_announcements (exam_id, participation_id, message, created_at) VALUES (?, ?, ?, ?)', [$exam['id'], $otherP['id'], 'Für jemand anderen', ExamClock::toDb(self::T0 + 2)]);

        $r = $this->call($student, 'pulse', (int) $exam['id'], [
            'client_state' => ['fullscreen' => true, 'visible' => true, 'focused' => true, 'seb' => true, 'screen' => ['w' => 1920, 'h' => 1080], 'pending_saves' => 3, 'current_task_id' => 5],
            'announcement_cursor' => 0,
        ]);

        self::assertTrue($r['success']);
        self::assertSame('active', $r['state']);
        self::assertSame('running', $r['exam_status']);
        self::assertTrue($r['clock']['started']);
        self::assertFalse($r['clock']['time_up']);
        self::assertFalse($r['clock']['unlimited']);
        self::assertIsInt($r['clock']['ends_at_ms']);
        self::assertSame(['Für alle', 'Nur du'], array_column($r['announcements'], 'message'));
        self::assertFalse($r['announcements'][0]['personal']);
        self::assertTrue($r['announcements'][1]['personal']);
        self::assertSame($r['announcements'][1]['id'], $r['announcement_cursor']);
        self::assertFalse($r['hand_raised']);

        $stored = json_decode((string) $this->reloadParticipation($pid)['client_state'], true);
        self::assertTrue($stored['fullscreen']);
        self::assertSame(3, $stored['pending_saves']);
        self::assertSame(5, $stored['current_task_id']);
        self::assertFalse($stored['seb'], 'SEB zählt nur mit gültigem Header, nicht nach Angabe des Clients');
        self::assertGreaterThan(self::T0, ExamClock::toMs($this->reloadParticipation($pid)['last_seen_at']));

        $r2 = $this->call($student, 'pulse', (int) $exam['id'], ['announcement_cursor' => $r['announcement_cursor']]);
        self::assertSame([], $r2['announcements']);
        self::assertSame($r['announcement_cursor'], $r2['announcement_cursor']);
    }

    public function testPulseReportsHandAndOpenRequests(): void
    {
        [$student, $exam, $pid] = $this->activeStudent();
        $this->call($student, 'request', (int) $exam['id'], ['type' => 'hand']);
        $r = $this->call($student, 'pulse', (int) $exam['id']);
        self::assertTrue($r['hand_raised']);
        self::assertSame(['hand'], $r['open_requests']);
        self::assertNotNull($pid);
    }

    public function testPulseReportsLockedStateAndReason(): void
    {
        [$student, $exam] = $this->activeStudent();
        $this->call($student, 'event', (int) $exam['id'], ['events' => [['type' => 'visibility_hidden']]]);
        $r = $this->call($student, 'pulse', (int) $exam['id']);
        self::assertSame('locked', $r['state']);
        self::assertSame('Fenster oder Tab gewechselt', $r['lock_reason']);
    }

    public function testPulseInLobbyHasNoClock(): void
    {
        [$student, $exam] = $this->setup1('lobby');
        $this->call($student, 'join', (int) $exam['id']);
        $r = $this->call($student, 'pulse', (int) $exam['id']);
        self::assertSame('waiting', $r['state']);
        self::assertSame('lobby', $r['exam_status']);
        self::assertFalse($r['clock']['started']);
        self::assertNull($r['clock']['ends_at_ms']);
    }

    // ---------- Speichern ----------

    public function testSaveThroughControllerAndLockedIs409WithState(): void
    {
        [$student, $exam, $pid] = $this->activeStudent();
        $task = $this->makeTask((int) $exam['id'], 'text');
        $item = ['seq' => 1, 'task_id' => $task, 'client_ts' => ExamClock::nowMs(), 'kind' => 'value', 'payload' => ['text' => 'a']];

        $r = $this->call($student, 'save', (int) $exam['id'], ['items' => [$item], 'contents' => [$task => ['content' => ['text' => 'a'], 'flagged' => false]]]);
        self::assertTrue($r['success']);
        self::assertSame(1, $r['ack_seq']);
        self::assertSame([$task => 1], (array) $r['versions']);

        $this->db->run("UPDATE participations SET state = 'locked', state_changed_at = ? WHERE id = ?", [ExamClock::toDb(ExamClock::nowMs() - 60_000), $pid]);
        $item2 = ['seq' => 2, 'task_id' => $task, 'client_ts' => ExamClock::nowMs(), 'kind' => 'value', 'payload' => ['text' => 'b']];
        $r = $this->call($student, 'save', (int) $exam['id'], ['items' => [$item2], 'contents' => [$task => ['content' => ['text' => 'b']]]]);
        self::assertFalse($r['success']);
        self::assertSame('locked', $r['state']);
        self::assertSame('{"text":"a"}', $this->db->fetchValue('SELECT content FROM answers WHERE participation_id = ?', [$pid]));
    }

    // ---------- SEB ----------

    public function testSebRequiredJoinFailsWithoutHeader(): void
    {
        $key = str_repeat('c', 64);
        [$student, $exam] = $this->setup1('lobby', ['require_seb' => true], ['seb_config_key' => $key]);

        $this->expectStatus(403, fn () => $this->call($student, 'join', (int) $exam['id']));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM participations'));
    }

    public function testSebRequiredJoinWorksWithValidHeaderAndFailsWithWrongOne(): void
    {
        $key = str_repeat('c', 64);
        [$student, $exam] = $this->setup1('lobby', ['require_seb' => true], ['seb_config_key' => $key]);

        $ctx = $this->makeContext($student);
        $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH'] = hash('sha256', 'http://localhost/test' . $key);
        $_POST = [];
        $r = (new StudentExamController($ctx))->join(['id' => (string) $exam['id']]);
        self::assertTrue($r['success']);

        $pid = (int) $this->db->fetchValue('SELECT id FROM participations WHERE user_id = ?', [$student]);
        $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH'] = hash('sha256', 'http://andere-seite/test' . $key);
        $this->expectStatus(403, fn () => (new StudentExamController($ctx))->pulse(['id' => (string) $exam['id']]));
        self::assertContains('seb_missing', $this->eventTypes($pid));
    }

    // ---------- Seiten ----------

    public function testShowRendersBootJsonForOpenExam(): void
    {
        [$student, $exam] = $this->setup1('lobby');
        $html = $this->call($student, 'show', (int) $exam['id']);

        self::assertIsString($html);
        self::assertStringContainsString('id="exam-app"', $html);
        self::assertStringContainsString('id="exam-boot"', $html);
        self::assertStringContainsString('exam.js', $html);
        self::assertStringContainsString('exam.css', $html);
        preg_match('#<script type="application/json" id="exam-boot">(.*?)</script>#s', $html, $m);
        $boot = json_decode($m[1], true);
        self::assertSame((int) $exam['id'], $boot['exam_id']);
        self::assertSame('exam', $boot['mode']);
        self::assertFalse($boot['preview']);
        self::assertSame('/api/klausur/' . $exam['id'], $boot['api_base']);
        self::assertFalse($boot['needs_access_code']);
        self::assertSame(['required' => false, 'detected' => false], $boot['seb']);
        self::assertSame('/assets/vendor/pyodide/', $boot['assets']['pyodide']);
        self::assertStringNotContainsString('</script><script', $html);
    }

    public function testShowEscapesTitleInsideBootJson(): void
    {
        $student = $this->makeUser('student', 'Mia');
        $exam = $this->makeExam($this->makeCourse([$student]), 'lobby', ['title' => '</script><script>alert(1)</script>']);
        $html = $this->call($student, 'show', (int) $exam['id']);

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringNotContainsString('</script><script>', $html);
    }

    public function testShowForFinishedExamIsFriendlyPage(): void
    {
        [$student, $exam] = $this->setup1('finished');
        $html = $this->call($student, 'show', (int) $exam['id']);
        self::assertIsString($html);
        self::assertStringContainsString('beendet', $html);
        self::assertStringNotContainsString('exam-boot', $html);
    }

    public function testShowForNonMemberIs403(): void
    {
        [, $exam] = $this->setup1('lobby');
        $outsider = $this->makeUser('student', 'Otto');
        $this->expectStatus(403, fn () => $this->call($outsider, 'show', (int) $exam['id']));
    }

    public function testShowNeedsCodeFlagForNewParticipant(): void
    {
        [$student, $exam] = $this->setup1('lobby', ['require_access_code' => true], ['access_code' => 'AB12CD']);
        $html = $this->call($student, 'show', (int) $exam['id']);
        preg_match('#id="exam-boot">(.*?)</script>#s', (string) $html, $m);
        self::assertTrue(json_decode($m[1], true)['needs_access_code']);
        self::assertStringNotContainsString('AB12CD', (string) $html);
    }

    public function testIndexGroupsExams(): void
    {
        $student = $this->makeUser('student', 'Mia');
        $course = $this->makeCourse([$student]);
        $this->makeExam($course, 'running', ['title' => 'Klausur Läuft']);
        $this->makeExam($course, 'lobby', ['title' => 'Übung Listen', 'mode' => 'practice']);
        $done = $this->makeExam($course, 'returned', ['title' => 'Klausur Alt']);
        $this->makeExam($course, 'finished', ['title' => 'Klausur Ohne Teilnahme']);
        $this->makeExam($course, 'draft', ['title' => 'Entwurf Geheim']);
        $this->makeParticipation((int) $done['id'], $student, 'submitted');

        $html = (string) $this->call($student, 'index', 0);

        self::assertStringContainsString('Klausur Läuft', $html);
        self::assertStringContainsString('Zur Klausur', $html);
        self::assertStringContainsString('Übung Listen', $html);
        self::assertStringContainsString('Zur Übung', $html);
        self::assertStringContainsString('/meine-ergebnisse/' . $done['id'], $html);
        self::assertStringNotContainsString('Klausur Ohne Teilnahme', $html);
        self::assertStringNotContainsString('Entwurf Geheim', $html);
    }

    public function testIndexIsStudentOnly(): void
    {
        $teacher = $this->makeUser('teacher', 'Lehrer');
        $this->expectStatus(403, fn () => $this->call($teacher, 'index', 0));
    }

    public function testShortBlurWithClientPayloadMsIsOnlyInfo(): void
    {
        [$student, $exam] = $this->activeStudent();
        $r = $this->call($student, 'event', (int) $exam['id'], ['events' => [['type' => 'window_blur', 'details' => ['ms' => 100]]]]);
        self::assertSame('active', $r['state']);
    }

    public function testStaleStateInSubmitRequestIs409NotException(): void
    {
        [$student, $exam, $pid] = $this->activeStudent();
        $this->db->run("UPDATE participations SET state = 'locked' WHERE id = ?", [$pid]);
        $ctrl = new StudentExamController($this->makeContext($student));
        $m = new \ReflectionMethod($ctrl, 'staleStateError');
        $r = $m->invoke($ctrl, $pid, new \DomainException('Übergang nicht erlaubt'));
        self::assertSame('locked', $r['state']);
        self::assertFalse($r['success']);
    }
}
