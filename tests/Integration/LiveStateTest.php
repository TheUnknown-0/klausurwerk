<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Services\DeviceBinding;
use App\Services\EventLog;
use App\Services\ExamClock;
use App\Services\ParticipationState;
use App\Services\PultActions;
use Tests\Support\DatabaseTestCase;
use Tests\Support\LiveFixtures;

/** Zustandsübergänge, Zeitbuchung und alle Pult-Aktionen gegen die Datenbank. */
final class LiveStateTest extends DatabaseTestCase
{
    use LiveFixtures;

    private EventLog $events;
    private ParticipationState $states;
    private DeviceBinding $devices;
    private PultActions $actions;
    private int $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new EventLog($this->db);
        $this->states = new ParticipationState($this->db, $this->events);
        $this->devices = new DeviceBinding($this->db);
        $this->actions = new PultActions($this->db, $this->events, $this->states, $this->devices);
        $this->teacher = $this->makeUser('teacher', 'Lehrer');
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function act(array $exam, array $input, int $nowMs): array
    {
        return $this->actions->run($exam, $input, $this->teacher, $nowMs);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function runningExamWithStudent(array $settings = [], string $state = 'active'): array
    {
        $student = $this->makeUser('student', 'Mia');
        $course = $this->makeCourse([$student]);
        $exam = $this->makeExam($course, 'running', [], $settings);

        return [$exam, $this->makeParticipation((int) $exam['id'], $student, $state)];
    }

    public function testTransitionWritesStateTimestampResumeStateAndEvent(): void
    {
        [$exam, $p] = $this->runningExamWithStudent();
        $now = self::T0 + 600_000;

        $row = $this->states->transition((int) $p['id'], 'locked', 'Vollbild verlassen', null, $now, ['lock_reason' => 'Vollbild verlassen']);

        self::assertSame('locked', $row['state']);
        self::assertSame('active', $row['resume_state']);
        self::assertSame('Vollbild verlassen', $row['lock_reason']);
        self::assertSame($now, ExamClock::toMs($row['state_changed_at']));

        $event = $this->db->fetchOne("SELECT * FROM participation_events WHERE participation_id = ? AND type = 'state_changed'", [$p['id']]);
        self::assertNotNull($event);
        $details = json_decode((string) $event['details'], true);
        self::assertSame(['from' => 'active', 'to' => 'locked'], ['from' => $details['from'], 'to' => $details['to']]);
        self::assertSame('info', $event['severity']);
    }

    public function testInvalidTransitionThrowsAndKeepsState(): void
    {
        [, $p] = $this->runningExamWithStudent();

        try {
            $this->states->transition((int) $p['id'], 'waiting', 'x', null, self::T0);
            self::fail('DomainException erwartet');
        } catch (\DomainException) {
            // erwartet
        }
        self::assertSame('active', $this->reloadParticipation((int) $p['id'])['state']);
        self::assertSame([], $this->eventTypes((int) $p['id']));
    }

    public function testUnknownTargetStateThrows(): void
    {
        [, $p] = $this->runningExamWithStudent();
        $this->expectException(\DomainException::class);
        $this->states->transition((int) $p['id'], 'gibtsnicht', '', null, self::T0);
    }

    public function testAbsentWithoutToiletClockKeepsTimeRunning(): void
    {
        [$exam, $p] = $this->runningExamWithStudent(['toilet_pauses_clock' => false]);
        $this->states->transition((int) $p['id'], 'absent', 'Toilette', $this->teacher, self::T0 + 600_000);
        $mid = $this->reloadParticipation((int) $p['id']);
        self::assertNull($mid['paused_at']);

        $this->states->release((int) $p['id'], 'zurück', $this->teacher, self::T0 + 780_000);
        $after = $this->reloadParticipation((int) $p['id']);

        self::assertSame('active', $after['state']);
        self::assertSame(0, (int) $after['paused_seconds']);
        $clock = ExamClock::forParticipation($exam, $after, self::T0 + 780_000);
        self::assertSame(self::T0 + 90 * 60_000, $clock['ends_at_ms']);
    }

    public function testAbsentWithToiletClockStopsTimeForTheAbsence(): void
    {
        [$exam, $p] = $this->runningExamWithStudent(['toilet_pauses_clock' => true]);
        $this->states->transition((int) $p['id'], 'absent', 'Toilette', $this->teacher, self::T0 + 600_000);
        $mid = $this->reloadParticipation((int) $p['id']);
        self::assertSame(self::T0 + 600_000, ExamClock::toMs($mid['paused_at']));

        // Während der Abwesenheit bleibt die Restzeit stehen.
        $during = ExamClock::forParticipation($exam, $mid, self::T0 + 700_000);
        self::assertSame(90 * 60_000 - 600_000, $during['remaining_ms']);

        $this->states->release((int) $p['id'], 'zurück', $this->teacher, self::T0 + 780_000);
        $after = $this->reloadParticipation((int) $p['id']);

        self::assertNull($after['paused_at']);
        self::assertSame(180, (int) $after['paused_seconds']);
        self::assertSame(self::T0 + 90 * 60_000 + 180_000, ExamClock::forParticipation($exam, $after, self::T0 + 780_000)['ends_at_ms']);
    }

    public function testSubmitAbsentPersonClosesOpenClock(): void
    {
        [, $p] = $this->runningExamWithStudent(['toilet_pauses_clock' => true]);
        $this->states->transition((int) $p['id'], 'absent', '', null, self::T0 + 60_000);
        $this->states->transition((int) $p['id'], 'submitted', 'Abgabe', $this->teacher, self::T0 + 120_000);
        $row = $this->reloadParticipation((int) $p['id']);

        self::assertNull($row['paused_at']);
        self::assertSame(60, (int) $row['paused_seconds']);
        self::assertSame($this->teacher, (int) $row['submitted_by']);
    }

    public function testIndividualPauseAndResume(): void
    {
        [$exam, $p] = $this->runningExamWithStudent();
        $r = $this->act($exam, ['action' => 'pause', 'participation_ids' => [(int) $p['id']]], self::T0 + 60_000);
        self::assertSame(1, $r['affected']);
        self::assertSame('paused', $this->reloadParticipation((int) $p['id'])['state']);

        $r = $this->act($exam, ['action' => 'resume', 'participation_ids' => [(int) $p['id']]], self::T0 + 360_000);
        self::assertSame(1, $r['affected']);
        $row = $this->reloadParticipation((int) $p['id']);
        self::assertSame('active', $row['state']);
        self::assertSame(300, (int) $row['paused_seconds']);
    }

    public function testStartActivatesWaitingAndSetsStartTime(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $b = $this->makeUser('student', 'Ben');
        $exam = $this->makeExam($this->makeCourse([$a, $b]), 'lobby');
        $pa = $this->makeParticipation((int) $exam['id'], $a, 'waiting');
        $pb = $this->makeParticipation((int) $exam['id'], $b, 'waiting');

        $r = $this->act($exam, ['action' => 'start'], self::T0);

        self::assertTrue($r['success']);
        self::assertSame(2, $r['affected']);
        $fresh = $this->reloadExam((int) $exam['id']);
        self::assertSame('running', $fresh['status']);
        self::assertSame(self::T0, ExamClock::toMs($fresh['started_at']));
        self::assertSame('active', $this->reloadParticipation((int) $pa['id'])['state']);
        self::assertSame('active', $this->reloadParticipation((int) $pb['id'])['state']);

        try {
            $this->act($fresh, ['action' => 'start'], self::T0 + 1000);
            self::fail('409 erwartet');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testOpenLobbyOnlyFromDraftOrReady(): void
    {
        $exam = $this->makeExam(null, 'ready');
        $r = $this->act($exam, ['action' => 'open_lobby'], self::T0);
        self::assertSame('lobby', $r['exam_status']);
        self::assertSame('lobby', $this->reloadExam((int) $exam['id'])['status']);

        $this->expectException(HttpException::class);
        $this->act($this->reloadExam((int) $exam['id']), ['action' => 'open_lobby'], self::T0);
    }

    public function testPauseAllAndResumeAllBookTimeOnce(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $b = $this->makeUser('student', 'Ben');
        $c = $this->makeUser('student', 'Cleo');
        $exam = $this->makeExam($this->makeCourse([$a, $b, $c]), 'running', [], ['toilet_pauses_clock' => true]);
        $pa = $this->makeParticipation((int) $exam['id'], $a, 'active');
        $pb = $this->makeParticipation((int) $exam['id'], $b, 'active');
        $pc = $this->makeParticipation((int) $exam['id'], $c, 'active');

        // Cleo ist vor der Gesamtpause abwesend (Uhr steht ihretwegen schon).
        $this->states->transition((int) $pc['id'], 'absent', '', $this->teacher, self::T0 + 300_000);

        $this->act($exam, ['action' => 'pause_all'], self::T0 + 600_000);
        $paused = $this->reloadExam((int) $exam['id']);
        self::assertSame('paused', $paused['status']);
        self::assertSame(self::T0 + 600_000, ExamClock::toMs($paused['paused_at']));
        $rowA = $this->reloadParticipation((int) $pa['id']);
        self::assertSame('paused', $rowA['state']);
        self::assertSame(ParticipationState::PAUSE_ALL_REASON, $rowA['lock_reason']);
        self::assertNull($rowA['paused_at'], 'Gesamtpause startet keine Einzelpause');
        // Cleos Einzelpause wurde zum Beginn der Gesamtpause abgeschlossen (300 s).
        $rowC = $this->reloadParticipation((int) $pc['id']);
        self::assertSame('absent', $rowC['state']);
        self::assertNull($rowC['paused_at']);
        self::assertSame(300, (int) $rowC['paused_seconds']);

        $this->act($paused, ['action' => 'resume_all'], self::T0 + 900_000);
        $resumed = $this->reloadExam((int) $exam['id']);
        self::assertSame('running', $resumed['status']);
        self::assertNull($resumed['paused_at']);
        self::assertSame(300, (int) $resumed['paused_seconds']);
        self::assertSame('active', $this->reloadParticipation((int) $pa['id'])['state']);
        self::assertSame('active', $this->reloadParticipation((int) $pb['id'])['state']);
        // Cleo bleibt abwesend, ihre Uhr läuft wieder an (Toilette hält die Uhr an).
        $rowC = $this->reloadParticipation((int) $pc['id']);
        self::assertSame('absent', $rowC['state']);
        self::assertSame(self::T0 + 900_000, ExamClock::toMs($rowC['paused_at']));

        // Doppelt gezählt wird nichts: Anna hat 300 s Gesamtpause und keine Einzelpause.
        $clockA = ExamClock::forParticipation($resumed, $this->reloadParticipation((int) $pa['id']), self::T0 + 1_000_000);
        self::assertSame(self::T0 + 90 * 60_000 + 300_000, $clockA['ends_at_ms']);
    }

    public function testResumeAllNeedsPausedExam(): void
    {
        [$exam] = $this->runningExamWithStudent();
        $this->expectException(HttpException::class);
        $this->act($exam, ['action' => 'resume_all'], self::T0);
    }

    public function testFinishNeedsAllSubmitted(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $b = $this->makeUser('student', 'Ben');
        $exam = $this->makeExam($this->makeCourse([$a, $b]), 'running');
        $pa = $this->makeParticipation((int) $exam['id'], $a, 'active');
        $pb = $this->makeParticipation((int) $exam['id'], $b, 'active');

        $r = $this->act($exam, ['action' => 'finish'], self::T0 + 5_000_000);
        self::assertFalse($r['success']);
        self::assertSame(409, $r['status']);
        self::assertCount(2, $r['errors']);
        self::assertSame('running', $this->reloadExam((int) $exam['id'])['status']);

        $this->act($exam, ['action' => 'submit', 'participation_ids' => 'all'], self::T0 + 5_000_000);
        self::assertSame('submitted', $this->reloadParticipation((int) $pa['id'])['state']);
        self::assertSame('submitted', $this->reloadParticipation((int) $pb['id'])['state']);

        $r = $this->act($exam, ['action' => 'finish'], self::T0 + 5_100_000);
        self::assertTrue($r['success']);
        $fresh = $this->reloadExam((int) $exam['id']);
        self::assertSame('finished', $fresh['status']);
        self::assertSame(self::T0 + 5_100_000, ExamClock::toMs($fresh['finished_at']));
    }

    public function testFinishClosesRunningExamPause(): void
    {
        [$exam, $p] = $this->runningExamWithStudent();
        $this->act($exam, ['action' => 'pause_all'], self::T0 + 600_000);
        $this->act($this->reloadExam((int) $exam['id']), ['action' => 'submit', 'participation_ids' => [(int) $p['id']]], self::T0 + 660_000);
        $this->act($this->reloadExam((int) $exam['id']), ['action' => 'finish'], self::T0 + 900_000);

        $fresh = $this->reloadExam((int) $exam['id']);
        self::assertSame('finished', $fresh['status']);
        self::assertNull($fresh['paused_at']);
        self::assertSame(300, (int) $fresh['paused_seconds']);
    }

    public function testAddTimeAccumulatesAndNeverGoesBelowZero(): void
    {
        [$exam, $p] = $this->runningExamWithStudent();
        $id = (int) $p['id'];

        $this->act($exam, ['action' => 'add_time', 'participation_ids' => [$id], 'minutes' => 10], self::T0 + 1000);
        self::assertSame(10, (int) $this->reloadParticipation($id)['extra_minutes']);

        $this->act($exam, ['action' => 'add_time', 'participation_ids' => [$id], 'minutes' => -4], self::T0 + 2000);
        self::assertSame(6, (int) $this->reloadParticipation($id)['extra_minutes']);

        $this->act($exam, ['action' => 'add_time', 'participation_ids' => [$id], 'minutes' => -60], self::T0 + 3000);
        self::assertSame(0, (int) $this->reloadParticipation($id)['extra_minutes']);

        $r = $this->act($exam, ['action' => 'add_time', 'participation_ids' => [$id], 'minutes' => -5], self::T0 + 4000);
        self::assertSame(0, $r['affected']);
        self::assertCount(1, $r['errors']);
        self::assertContains('time_added', $this->eventTypes($id));
    }

    public function testAddTimeAfterTimeUpAllowsNewSignal(): void
    {
        [$exam, $p] = $this->runningExamWithStudent();
        $id = (int) $p['id'];
        $this->db->run('UPDATE participations SET time_up_notified_at = ? WHERE id = ?', [ExamClock::toDb(self::T0 + 91 * 60_000), $id]);

        $this->act($exam, ['action' => 'add_time', 'participation_ids' => [$id], 'minutes' => 15], self::T0 + 92 * 60_000);

        self::assertNull($this->reloadParticipation($id)['time_up_notified_at']);
    }

    public function testSubmitRequestApprove(): void
    {
        [$exam, $p] = $this->runningExamWithStudent();
        $id = (int) $p['id'];
        $this->states->transition($id, 'submit_requested', 'Abgabe angefragt', null, self::T0 + 1000);
        $this->db->run("INSERT INTO exam_requests (exam_id, participation_id, type, status, created_at) VALUES (?, ?, 'submit', 'open', ?)", [$exam['id'], $id, ExamClock::toDb(self::T0)]);

        $r = $this->act($exam, ['action' => 'approve_submit', 'participation_ids' => [$id]], self::T0 + 2000);

        self::assertSame(1, $r['affected']);
        $row = $this->reloadParticipation($id);
        self::assertSame('submitted', $row['state']);
        self::assertSame($this->teacher, (int) $row['submitted_by']);
        self::assertSame('approved', $this->db->fetchValue("SELECT status FROM exam_requests WHERE participation_id = ?", [$id]));
    }

    public function testSubmitRequestReject(): void
    {
        [$exam, $p] = $this->runningExamWithStudent();
        $id = (int) $p['id'];
        $this->states->transition($id, 'submit_requested', '', null, self::T0 + 1000);
        $this->db->run("INSERT INTO exam_requests (exam_id, participation_id, type, status, created_at) VALUES (?, ?, 'submit', 'open', ?)", [$exam['id'], $id, ExamClock::toDb(self::T0)]);

        $this->act($exam, ['action' => 'reject_submit', 'participation_ids' => [$id]], self::T0 + 2000);

        self::assertSame('active', $this->reloadParticipation($id)['state']);
        self::assertNull($this->reloadParticipation($id)['submit_requested_at']);
        self::assertSame('rejected', $this->db->fetchValue("SELECT status FROM exam_requests WHERE participation_id = ?", [$id]));
    }

    public function testApproveSubmitWithoutRequestIsAnError(): void
    {
        [$exam, $p] = $this->runningExamWithStudent();
        $r = $this->act($exam, ['action' => 'approve_submit', 'participation_ids' => [(int) $p['id']]], self::T0);
        self::assertSame(0, $r['affected']);
        self::assertSame((int) $p['id'], $r['errors'][0]['participation_id']);
    }

    public function testReleaseLockedResolvesOpenReleaseRequest(): void
    {
        [$exam, $p] = $this->runningExamWithStudent();
        $id = (int) $p['id'];
        $this->states->transition($id, 'locked', 'Vollbild', null, self::T0 + 1000, ['lock_reason' => 'Vollbild verlassen']);
        $this->db->run("INSERT INTO exam_requests (exam_id, participation_id, type, status, created_at) VALUES (?, ?, 'release', 'open', ?)", [$exam['id'], $id, ExamClock::toDb(self::T0)]);

        $r = $this->act($exam, ['action' => 'release', 'participation_ids' => [$id]], self::T0 + 5000);

        self::assertSame(1, $r['affected']);
        $row = $this->reloadParticipation($id);
        self::assertSame('active', $row['state']);
        self::assertNull($row['lock_reason']);
        self::assertNull($row['resume_state']);
        self::assertSame('approved', $this->db->fetchValue("SELECT status FROM exam_requests WHERE participation_id = ?", [$id]));
    }

    public function testBulkActionCollectsErrorsPerPerson(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $b = $this->makeUser('student', 'Ben');
        $exam = $this->makeExam($this->makeCourse([$a, $b]), 'running');
        $pa = $this->makeParticipation((int) $exam['id'], $a, 'locked');
        $pb = $this->makeParticipation((int) $exam['id'], $b, 'active');

        $r = $this->act($exam, ['action' => 'release', 'participation_ids' => [(int) $pa['id'], (int) $pb['id'], 999999]], self::T0 + 1000);

        self::assertSame(1, $r['affected']);
        self::assertCount(2, $r['errors']);
        self::assertSame('active', $this->reloadParticipation((int) $pa['id'])['state']);
        self::assertSame('active', $this->reloadParticipation((int) $pb['id'])['state']);
    }

    public function testReleaseAllTouchesOnlyBlockedPeople(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $b = $this->makeUser('student', 'Ben');
        $exam = $this->makeExam($this->makeCourse([$a, $b]), 'running');
        $this->makeParticipation((int) $exam['id'], $a, 'locked');
        $this->makeParticipation((int) $exam['id'], $b, 'active');

        $r = $this->act($exam, ['action' => 'release', 'participation_ids' => 'all'], self::T0 + 1000);

        self::assertSame(1, $r['affected']);
        self::assertSame([], $r['errors']);
    }

    public function testLockAndSetAbsent(): void
    {
        [$exam, $p] = $this->runningExamWithStudent();
        $id = (int) $p['id'];
        $this->act($exam, ['action' => 'lock', 'participation_ids' => [$id], 'message' => 'Handy auf dem Tisch'], self::T0 + 1000);
        $row = $this->reloadParticipation($id);
        self::assertSame('locked', $row['state']);
        self::assertSame('Handy auf dem Tisch', $row['lock_reason']);

        $this->act($exam, ['action' => 'release', 'participation_ids' => [$id]], self::T0 + 2000);
        $this->act($exam, ['action' => 'set_absent', 'participation_ids' => [$id]], self::T0 + 3000);
        self::assertSame('absent', $this->reloadParticipation($id)['state']);
    }

    public function testDeviceChangeApprove(): void
    {
        [$exam, $p] = $this->runningExamWithStudent();
        $id = (int) $p['id'];
        $old = DeviceBinding::newToken();
        $new = DeviceBinding::newToken();
        $this->devices->bind($id, $old);
        $this->states->transition($id, 'device_pending', 'Gerätewechsel', null, self::T0 + 1000);
        self::assertTrue($this->devices->requestChange((int) $exam['id'], $id, $new, self::T0 + 1000));
        self::assertFalse($this->devices->requestChange((int) $exam['id'], $id, $new, self::T0 + 1500), 'keine doppelte Anfrage');

        $r = $this->act($exam, ['action' => 'approve_device', 'participation_ids' => [$id]], self::T0 + 2000);

        self::assertSame(1, $r['affected']);
        $row = $this->reloadParticipation($id);
        self::assertSame('active', $row['state']);
        self::assertSame(DeviceBinding::OK, DeviceBinding::check($row, $new));
        self::assertSame(DeviceBinding::FOREIGN, DeviceBinding::check($row, $old));
        self::assertNull($row['pending_device_hash']);
        self::assertSame('approved', $this->db->fetchValue("SELECT status FROM exam_requests WHERE participation_id = ? AND type = 'device'", [$id]));
    }

    public function testDeviceChangeRejectKeepsOldDevice(): void
    {
        [$exam, $p] = $this->runningExamWithStudent();
        $id = (int) $p['id'];
        $old = DeviceBinding::newToken();
        $new = DeviceBinding::newToken();
        $this->devices->bind($id, $old);
        $this->states->transition($id, 'device_pending', '', null, self::T0 + 1000);
        $this->devices->requestChange((int) $exam['id'], $id, $new, self::T0 + 1000);

        $this->act($exam, ['action' => 'reject_device', 'participation_ids' => [$id]], self::T0 + 2000);

        $row = $this->reloadParticipation($id);
        self::assertSame('active', $row['state']);
        self::assertSame(DeviceBinding::OK, DeviceBinding::check($row, $old));
        self::assertSame(DeviceBinding::FOREIGN, DeviceBinding::check($row, $new));
    }

    public function testDevicePendingReturnsToPreviousState(): void
    {
        [, $p] = $this->runningExamWithStudent();
        $id = (int) $p['id'];
        $this->states->transition($id, 'locked', 'x', null, self::T0 + 1000, ['lock_reason' => 'x']);
        $this->states->transition($id, 'device_pending', 'x', null, self::T0 + 2000);
        self::assertSame('locked', $this->reloadParticipation($id)['resume_state']);

        $this->states->release($id, 'ok', $this->teacher, self::T0 + 3000);
        self::assertSame('locked', $this->reloadParticipation($id)['state']);
    }

    public function testReopenNeedsReasonAndRunningExam(): void
    {
        [$exam, $p] = $this->runningExamWithStudent(state: 'submitted');
        $id = (int) $p['id'];

        try {
            $this->act($exam, ['action' => 'reopen', 'participation_ids' => [$id]], self::T0 + 1000);
            self::fail('400 erwartet');
        } catch (HttpException $e) {
            self::assertSame(400, $e->status);
        }

        $r = $this->act($exam, ['action' => 'reopen', 'participation_ids' => [$id], 'message' => 'Versehentlich abgegeben'], self::T0 + 2000);
        self::assertSame(1, $r['affected']);
        $row = $this->reloadParticipation($id);
        self::assertSame('active', $row['state']);
        self::assertNull($row['submitted_at']);
        self::assertNull($row['submitted_by']);
    }

    public function testAnnounceToAllAndToPerson(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $b = $this->makeUser('student', 'Ben');
        $exam = $this->makeExam($this->makeCourse([$a, $b]), 'running');
        $pa = $this->makeParticipation((int) $exam['id'], $a);
        $this->makeParticipation((int) $exam['id'], $b);

        $this->act($exam, ['action' => 'announce', 'participation_ids' => 'all', 'message' => 'Aufgabe 2b: gemeint ist x.'], self::T0 + 1000);
        $this->act($exam, ['action' => 'announce', 'participation_ids' => [(int) $pa['id']], 'message' => 'Nur für dich.'], self::T0 + 2000);

        $rows = $this->db->fetchAll('SELECT participation_id, message, created_by FROM exam_announcements WHERE exam_id = ? ORDER BY id', [$exam['id']]);
        self::assertCount(2, $rows);
        self::assertNull($rows[0]['participation_id']);
        self::assertSame((int) $pa['id'], (int) $rows[1]['participation_id']);
        self::assertSame($this->teacher, (int) $rows[0]['created_by']);

        $this->expectException(HttpException::class);
        $this->act($exam, ['action' => 'announce', 'participation_ids' => 'all', 'message' => '  '], self::T0 + 3000);
    }

    public function testAckHandLowersHand(): void
    {
        [$exam, $p] = $this->runningExamWithStudent();
        $id = (int) $p['id'];
        $this->db->run('UPDATE participations SET hand_raised_at = ? WHERE id = ?', [ExamClock::toDb(self::T0), $id]);
        $this->db->run("INSERT INTO exam_requests (exam_id, participation_id, type, status, created_at) VALUES (?, ?, 'hand', 'open', ?)", [$exam['id'], $id, ExamClock::toDb(self::T0)]);

        $this->act($exam, ['action' => 'ack_hand', 'participation_ids' => [$id]], self::T0 + 1000);

        self::assertNull($this->reloadParticipation($id)['hand_raised_at']);
        self::assertSame('approved', $this->db->fetchValue("SELECT status FROM exam_requests WHERE participation_id = ?", [$id]));
        self::assertContains('hand_lowered', $this->eventTypes($id));
    }

    public function testActionsLeaveEventsWithActor(): void
    {
        [$exam, $p] = $this->runningExamWithStudent();
        $this->act($exam, ['action' => 'lock', 'participation_ids' => [(int) $p['id']]], self::T0 + 1000);
        $actor = $this->db->fetchValue("SELECT actor_id FROM participation_events WHERE participation_id = ? AND type = 'state_changed'", [$p['id']]);
        self::assertSame($this->teacher, (int) $actor);
    }

    public function testExamStatusChangeLeavesExamEventAndAuditEntry(): void
    {
        $exam = $this->makeExam(null, 'ready');
        $audit = new \App\Services\Audit($this->db, new \App\Core\Auth(new \App\Core\Session(), $this->db));
        $actions = new PultActions($this->db, $this->events, $this->states, $this->devices, $audit);

        $actions->run($exam, ['action' => 'open_lobby'], $this->teacher, self::T0);

        $event = $this->db->fetchOne("SELECT * FROM participation_events WHERE exam_id = ? AND participation_id IS NULL", [$exam['id']]);
        self::assertSame('state_changed', $event['type']);
        self::assertSame($this->teacher, (int) $event['actor_id']);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'exam.status.lobby'"));
    }

    public function testUnknownActionAndActionOnClosedExam(): void
    {
        [$exam, $p] = $this->runningExamWithStudent();
        try {
            $this->act($exam, ['action' => 'zaubern'], self::T0);
            self::fail('400 erwartet');
        } catch (HttpException $e) {
            self::assertSame(400, $e->status);
        }

        $this->db->run("UPDATE exams SET status = 'finished' WHERE id = ?", [$exam['id']]);
        try {
            $this->act($exam, ['action' => 'lock', 'participation_ids' => [(int) $p['id']]], self::T0);
            self::fail('409 erwartet');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testRejectReleaseKeepsPersonLockedAndClosesRequest(): void
    {
        [$exam, $p] = $this->runningExamWithStudent();
        $id = (int) $p['id'];
        $this->states->transition($id, 'locked', 'x', null, self::T0 + 1000, ['lock_reason' => 'x']);
        $this->db->run("INSERT INTO exam_requests (exam_id, participation_id, type, status, created_at) VALUES (?, ?, 'release', 'open', ?)", [$exam['id'], $id, ExamClock::toDb(self::T0)]);

        $r = $this->act($exam, ['action' => 'reject_release', 'participation_ids' => [$id]], self::T0 + 2000);

        self::assertSame(1, $r['affected']);
        self::assertSame('locked', $this->reloadParticipation($id)['state']);
        self::assertSame('rejected', $this->db->fetchValue("SELECT status FROM exam_requests WHERE participation_id = ?", [$id]));
        self::assertContains('request_rejected', $this->eventTypes($id));
    }

    public function testReleaseDuringExamPauseReturnsToPause(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $b = $this->makeUser('student', 'Ben');
        $exam = $this->makeExam($this->makeCourse([$a, $b]), 'running');
        $pa = $this->makeParticipation((int) $exam['id'], $a, 'active');
        $pb = $this->makeParticipation((int) $exam['id'], $b, 'locked');

        $this->act($exam, ['action' => 'pause_all'], self::T0 + 60_000);
        $r = $this->act($this->reloadExam((int) $exam['id']), ['action' => 'release', 'participation_ids' => [(int) $pb['id']]], self::T0 + 120_000);
        self::assertSame(1, $r['affected']);
        $row = $this->reloadParticipation((int) $pb['id']);
        self::assertSame('paused', $row['state'], 'wer während der Gesamtpause freigegeben wird, gehört zur Pause');
        self::assertSame(ParticipationState::PAUSE_ALL_REASON, $row['lock_reason']);
        self::assertNull($row['paused_at']);

        $this->act($this->reloadExam((int) $exam['id']), ['action' => 'resume_all'], self::T0 + 180_000);
        self::assertSame('active', $this->reloadParticipation((int) $pa['id'])['state']);
        self::assertSame('active', $this->reloadParticipation((int) $pb['id'])['state']);
    }

    public function testResumeAllActivatesLateJoinerWhoWasReadyDuringPause(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $exam = $this->makeExam($this->makeCourse([$a]), 'paused', ['paused_at' => ExamClock::toDb(self::T0 + 60_000)]);
        $p = $this->makeParticipation((int) $exam['id'], $a, 'waiting');
        $this->db->run('UPDATE participations SET ready_at = ? WHERE id = ?', [ExamClock::toDb(self::T0 + 70_000), $p['id']]);

        $this->act($exam, ['action' => 'resume_all'], self::T0 + 120_000);

        self::assertSame('active', $this->reloadParticipation((int) $p['id'])['state']);
    }
    public function testSubmitByTeacherClosesOpenDeviceRequest(): void
    {
        [$exam, $p] = $this->runningExamWithStudent();
        $id = (int) $p['id'];
        $this->devices->bind($id, DeviceBinding::newToken());
        $this->states->transition($id, 'device_pending', 'Gerätewechsel', null, self::T0 + 1000);
        $this->devices->requestChange((int) $exam['id'], $id, DeviceBinding::newToken(), self::T0 + 1000);

        $this->act($exam, ['action' => 'submit', 'participation_ids' => [$id]], self::T0 + 2000);

        self::assertSame('submitted', $this->reloadParticipation($id)['state']);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM exam_requests WHERE participation_id = ? AND type = 'device' AND status = 'open'", [$id]));
    }

    public function testIndividualPauseKeepsRunningWhileDevicePending(): void
    {
        [$exam, $p] = $this->runningExamWithStudent();
        $id = (int) $p['id'];
        $this->states->transition($id, 'paused', 'Pause', $this->teacher, self::T0 + 60_000);
        $this->states->transition($id, 'device_pending', 'Gerät', null, self::T0 + 120_000);
        $mid = $this->reloadParticipation($id);
        self::assertSame(self::T0 + 60_000, ExamClock::toMs($mid['paused_at']), 'Pause läuft über den Gerätewechsel weiter');
        self::assertSame(0, (int) $mid['paused_seconds']);

        $this->states->release($id, 'ok', $this->teacher, self::T0 + 180_000);
        $after = $this->reloadParticipation($id);
        self::assertSame('paused', $after['state']);
        self::assertNotNull($after['paused_at']);

        $this->states->transition($id, 'active', 'weiter', $this->teacher, self::T0 + 240_000);
        $end = $this->reloadParticipation($id);
        self::assertNull($end['paused_at']);
        self::assertSame(180, (int) $end['paused_seconds'], 'Wartezeit des Gerätewechsels zählt zur Pause');
    }

    public function testDeviceReleaseKeepsOriginalLockReason(): void
    {
        [, $p] = $this->runningExamWithStudent();
        $id = (int) $p['id'];
        $this->states->transition($id, 'locked', 'Vollbild', null, self::T0 + 1000, ['lock_reason' => 'Vollbild verlassen']);
        $this->states->transition($id, 'device_pending', 'Gerät', null, self::T0 + 2000);
        $this->states->release($id, 'Gerätewechsel freigegeben', $this->teacher, self::T0 + 3000);

        $row = $this->reloadParticipation($id);
        self::assertSame('locked', $row['state']);
        self::assertSame('Vollbild verlassen', $row['lock_reason']);
    }
}
