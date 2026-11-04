<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\EventLog;
use App\Services\ExamClock;
use App\Services\ExamContent;
use App\Services\LiveStatus;
use Tests\Support\DatabaseTestCase;
use Tests\Support\LiveFixtures;

/** Pult-Stand, Verbindungs- und Zeitereignisse, Inhalt ohne Lösungsfelder. */
final class LiveStatusTest extends DatabaseTestCase
{
    use LiveFixtures;

    private LiveStatus $status;
    private EventLog $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new EventLog($this->db);
        $this->status = new LiveStatus($this->db, $this->events);
    }

    private function countEvents(int $examId, string $type, ?int $participationId = null): int
    {
        if ($participationId === null) {
            return (int) $this->db->fetchValue('SELECT COUNT(*) FROM participation_events WHERE exam_id = ? AND type = ?', [$examId, $type]);
        }

        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM participation_events WHERE exam_id = ? AND type = ? AND participation_id = ?',
            [$examId, $type, $participationId],
        );
    }

    public function testStandListsParticipantsNotJoinedAndOnlineFlag(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $b = $this->makeUser('student', 'Ben');
        $c = $this->makeUser('student', 'Cleo');
        $exam = $this->makeExam($this->makeCourse([$a, $b, $c]), 'running');
        $t1 = $this->makeTask((int) $exam['id'], 'text');
        $this->makeTask((int) $exam['id'], 'text', null, [], 1);
        $this->makeTask((int) $exam['id'], null, null, [], 2);
        $now = self::T0 + 600_000;
        $pa = $this->makeParticipation((int) $exam['id'], $a, 'active', ['last_seen_at' => ExamClock::toDb($now - 1200)]);
        $pb = $this->makeParticipation((int) $exam['id'], $b, 'locked', ['last_seen_at' => ExamClock::toDb($now - 8000)]);
        $this->db->run('UPDATE participations SET answered_count = 1, flagged_count = 1, hand_raised_at = ? WHERE id = ?', [ExamClock::toDb($now - 5000), $pa['id']]);
        $this->db->run("INSERT INTO exam_requests (exam_id, participation_id, type, status, created_at) VALUES (?, ?, 'release', 'open', ?)", [$exam['id'], $pb['id'], ExamClock::toDb($now - 3000)]);

        $stand = $this->status->stand($exam, 0, $now);

        self::assertTrue($stand['success']);
        self::assertSame($now, $stand['server_time_ms']);
        self::assertSame('running', $stand['exam']['status']);
        self::assertSame(90, $stand['exam']['duration_minutes']);
        self::assertNull($stand['exam']['room']);
        self::assertCount(2, $stand['participants']);
        self::assertCount(1, $stand['not_joined']);
        self::assertSame('Cleo', explode(' ', $stand['not_joined'][0]['name'])[0]);

        $byName = [];
        foreach ($stand['participants'] as $p) {
            $byName[explode(' ', $p['name'])[0]] = $p;
        }
        self::assertTrue($byName['Anna']['online']);
        self::assertFalse($byName['Ben']['online']);
        self::assertSame(['answered' => 1, 'flagged' => 1, 'total' => 2], $byName['Anna']['progress']);
        self::assertTrue($byName['Anna']['hand_raised']);
        self::assertSame('release', $byName['Ben']['open_requests'][0]['type']);
        self::assertSame(90 * 60_000 - 600_000, $byName['Anna']['clock']['remaining_ms']);
        self::assertSame(1200, $byName['Anna']['last_seen_ms_ago']);
        self::assertNull($byName['Anna']['position']);
        self::assertFalse($byName['Anna']['alarm']);
        self::assertNotNull($t1);
    }

    public function testStandIncludesRoomAndSeatPositions(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $this->db->run("INSERT INTO rooms (name, grid_cols, grid_rows) VALUES ('R 204', 8, 6)");
        $roomId = $this->db->lastInsertId();
        $this->db->run('INSERT INTO room_positions (room_id, user_id, col, `row`) VALUES (?, ?, 2, 1)', [$roomId, $a]);
        $exam = $this->makeExam($this->makeCourse([$a]), 'running', ['room_id' => $roomId]);
        $this->makeParticipation((int) $exam['id'], $a);

        $stand = $this->status->stand($exam, 0, self::T0 + 1000);

        self::assertSame(['id' => $roomId, 'name' => 'R 204', 'cols' => 8, 'rows' => 6], $stand['exam']['room']);
        self::assertSame(['col' => 2, 'row' => 1], $stand['participants'][0]['position']);
    }

    public function testCursorReturnsOnlyNewEventsAndFlagsAlarm(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $exam = $this->makeExam($this->makeCourse([$a]), 'running');
        $now = self::T0 + 60_000;
        $p = $this->makeParticipation((int) $exam['id'], $a, 'active', ['last_seen_at' => ExamClock::toDb($now + 5000)]);
        $first = $this->events->log((int) $exam['id'], (int) $p['id'], 'resize', 'info', ['w' => 1], null, null, $now);

        $stand = $this->status->stand($exam, 0, $now);
        self::assertCount(1, $stand['events']);
        self::assertSame($first, $stand['cursor']);
        self::assertFalse($stand['participants'][0]['alarm']);

        $alarm = $this->events->log((int) $exam['id'], (int) $p['id'], 'fullscreen_exit', 'alarm', [], null, null, $now + 1000);
        $stand = $this->status->stand($exam, $first, $now + 2000);
        self::assertCount(1, $stand['events']);
        self::assertSame('fullscreen_exit', $stand['events'][0]['type']);
        self::assertSame('alarm', $stand['events'][0]['severity']);
        self::assertSame($alarm, $stand['cursor']);
        self::assertTrue($stand['participants'][0]['alarm']);

        $stand = $this->status->stand($exam, $alarm, $now + 3000);
        self::assertSame([], $stand['events']);
        self::assertSame($alarm, $stand['cursor']);
        self::assertFalse($stand['participants'][0]['alarm']);
    }

    public function testConnectionLostIsWrittenOnceAndRestoredOnce(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $exam = $this->makeExam($this->makeCourse([$a]), 'running');
        $now = self::T0 + 600_000;
        $p = $this->makeParticipation((int) $exam['id'], $a, 'active', ['last_seen_at' => ExamClock::toDb($now - 9000)]);
        $id = (int) $p['id'];

        $this->status->sweep($exam, $now);
        self::assertSame(0, $this->countEvents((int) $exam['id'], 'connection_lost', $id), 'unter 10 s noch nicht');

        $this->status->sweep($exam, $now + 2000);
        $this->status->sweep($exam, $now + 4000);
        self::assertSame(1, $this->countEvents((int) $exam['id'], 'connection_lost', $id));
        self::assertNotNull($this->reloadParticipation($id)['offline_reported_at']);

        // Herzschlag kehrt zurück.
        $this->db->run('UPDATE participations SET last_seen_at = ? WHERE id = ?', [ExamClock::toDb($now + 5000), $id]);
        $this->status->sweep($exam, $now + 5500);
        $this->status->sweep($exam, $now + 6000);
        self::assertSame(1, $this->countEvents((int) $exam['id'], 'connection_restored', $id));
        self::assertNull($this->reloadParticipation($id)['offline_reported_at']);

        // Nochmaliger Ausfall wird erneut gemeldet.
        $this->status->sweep($exam, $now + 30_000);
        self::assertSame(2, $this->countEvents((int) $exam['id'], 'connection_lost', $id));
    }

    public function testNoConnectionEventsForSubmittedOrNeverSeen(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $b = $this->makeUser('student', 'Ben');
        $exam = $this->makeExam($this->makeCourse([$a, $b]), 'running');
        $old = ExamClock::toDb(self::T0 - 600_000);
        $this->makeParticipation((int) $exam['id'], $a, 'submitted', ['last_seen_at' => $old]);
        $pb = $this->makeParticipation((int) $exam['id'], $b, 'active');
        $this->db->run('UPDATE participations SET last_seen_at = NULL WHERE id = ?', [$pb['id']]);

        $this->status->sweep($exam, self::T0 + 600_000);

        self::assertSame(0, $this->countEvents((int) $exam['id'], 'connection_lost'));
    }

    public function testTimeUpIsSignalledOncePerPersonAndForTheExam(): void
    {
        $a = $this->makeUser('student', 'Anna', 25);
        $b = $this->makeUser('student', 'Ben');
        $exam = $this->makeExam($this->makeCourse([$a, $b]), 'running', ['duration_minutes' => 60]);
        $pa = $this->makeParticipation((int) $exam['id'], $a, 'active', ['accommodation_percent' => 25]);
        $pb = $this->makeParticipation((int) $exam['id'], $b, 'active');
        $seen = ExamClock::toDb(self::T0 + 61 * 60_000);
        $this->db->run('UPDATE participations SET last_seen_at = ?', [$seen]);

        // 61 Minuten: Ben ist fertig, Anna (60 x 1,25 = 75 min) noch nicht.
        $now = self::T0 + 61 * 60_000;
        $this->status->sweep($exam, $now);
        $this->status->sweep($this->reloadExam((int) $exam['id']), $now + 1000);

        self::assertSame(1, $this->countEvents((int) $exam['id'], 'time_up', (int) $pb['id']));
        self::assertSame(0, $this->countEvents((int) $exam['id'], 'time_up', (int) $pa['id']));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM participation_events WHERE exam_id = ? AND type = 'time_up' AND participation_id IS NULL", [$exam['id']]));
        self::assertNotNull($this->reloadExam((int) $exam['id'])['time_up_notified_at']);

        $later = self::T0 + 76 * 60_000;
        $this->db->run('UPDATE participations SET last_seen_at = ?', [ExamClock::toDb($later)]);
        $this->status->sweep($this->reloadExam((int) $exam['id']), $later);
        self::assertSame(1, $this->countEvents((int) $exam['id'], 'time_up', (int) $pa['id']));
        self::assertSame(1, $this->countEvents((int) $exam['id'], 'time_up', (int) $pb['id']));
    }

    public function testNoTimeUpForUnlimitedExam(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $exam = $this->makeExam($this->makeCourse([$a]), 'running', ['duration_minutes' => null, 'mode' => 'practice']);
        $p = $this->makeParticipation((int) $exam['id'], $a);
        $now = self::T0 + 99 * 3_600_000;
        $this->db->run('UPDATE participations SET last_seen_at = ? WHERE id = ?', [ExamClock::toDb($now), $p['id']]);

        $this->status->sweep($exam, $now);

        self::assertSame(0, $this->countEvents((int) $exam['id'], 'time_up'));
    }

    public function testSweepDoesNothingOutsideOpenExams(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $exam = $this->makeExam($this->makeCourse([$a]), 'finished');
        $this->makeParticipation((int) $exam['id'], $a, 'active', ['last_seen_at' => ExamClock::toDb(self::T0 - 600_000)]);

        $this->status->sweep($exam, self::T0 + 600_000);

        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM participation_events'));
    }

    public function testInhaltContainsNoSolutionFields(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $exam = $this->makeExam($this->makeCourse([$a]), 'running');
        $parent = $this->makeTask((int) $exam['id'], null);
        $choice = $this->makeTask((int) $exam['id'], 'choice', $parent, [
            'multiple' => false,
            'shuffle' => true,
            'options' => [['id' => 'a', 'text_md' => 'Eins'], ['id' => 'b', 'text_md' => 'Zwei']],
        ]);
        $this->db->run(
            "INSERT INTO task_solutions (task_id, solution, model_answer, expectation_md, tests) VALUES (?, ?, 'GEHEIME-MUSTERLOESUNG', 'GEHEIMER-ERWARTUNGSHORIZONT', ?)",
            [$choice, json_encode(['correct' => ['b'], 'scoring' => 'all_or_nothing']), json_encode([['name' => 'GEHEIMER-TEST', 'type' => 'io']])],
        );
        $this->db->run("INSERT INTO task_files (task_id, filename, content, is_readonly, position) VALUES (?, 'Hilfe.java', 'class Hilfe {}', 1, 0)", [$choice]);
        $p = $this->makeParticipation((int) $exam['id'], $a);

        $content = (new ExamContent($this->db))->forStudent($exam, $p);
        $json = json_encode($content, JSON_UNESCAPED_UNICODE);

        foreach (['GEHEIME-MUSTERLOESUNG', 'GEHEIMER-ERWARTUNGSHORIZONT', 'GEHEIMER-TEST', 'model_answer', 'expectation', 'correct', 'solution', 'scoring'] as $secret) {
            self::assertStringNotContainsString($secret, (string) $json, $secret);
        }
        self::assertSame('1', $content['tasks'][0]['number']);
        self::assertSame('1a', $content['tasks'][0]['children'][0]['number']);
        self::assertSame($choice, $content['tasks'][0]['children'][0]['id']);
        self::assertSame($parent, $content['tasks'][0]['children'][0]['parent_id']);
        self::assertSame('choice', $content['tasks'][0]['children'][0]['answer_type']);
        self::assertSame([['name' => 'Hilfe.java', 'content' => 'class Hilfe {}', 'readonly' => true]], $content['tasks'][0]['children'][0]['files']);
        self::assertStringContainsString('<strong>fett</strong>', $content['tasks'][0]['body_html']);
        self::assertSame(0, $content['last_seq']);
    }

    public function testStudentConfigIsShuffledDeterministicallyPerPersonAndNotInPreview(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $exam = $this->makeExam($this->makeCourse([$a]), 'running');
        $options = [];
        foreach (range(1, 12) as $i) {
            $options[] = ['id' => 'o' . $i, 'text_md' => 'Option ' . $i];
        }
        $task = $this->makeTask((int) $exam['id'], 'choice', null, ['multiple' => false, 'shuffle' => true, 'options' => $options]);
        $p = $this->makeParticipation((int) $exam['id'], $a, 'active', ['shuffle_seed' => 777]);
        $content = new ExamContent($this->db);

        $ids = static fn (array $payload): array => array_column($payload['tasks'][0]['config']['options'], 'id');
        $first = $ids($content->forStudent($exam, $p));
        $second = $ids($content->forStudent($exam, $p));
        $preview = $ids($content->forPreview($exam));

        self::assertSame($first, $second, 'gleicher Seed, gleiche Reihenfolge');
        self::assertNotSame(array_column($options, 'id'), $first, 'für Schüler:innen gemischt');
        self::assertSame(array_column($options, 'id'), $preview, 'Vorschau ohne Mischen');
        self::assertNotNull($task);
    }

    public function testOperatorsOnlyWhenAidEnabled(): void
    {
        $a = $this->makeUser('student', 'Anna');
        $this->db->run("INSERT INTO operators (name, afb, description, sort_order) VALUES ('nenne', 'I', 'Zähle auf.', 1)");
        $withAid = $this->makeExam($this->makeCourse([$a]), 'running', [], ['aids' => ['operators' => true]]);
        $withoutAid = $this->makeExam(null, 'running', [], ['aids' => ['operators' => false]]);
        $p = $this->makeParticipation((int) $withAid['id'], $a);
        $p2 = $this->makeParticipation((int) $withoutAid['id'], $a);
        $content = new ExamContent($this->db);

        self::assertSame('nenne', $content->forStudent($withAid, $p)['operators'][0]['name']);
        self::assertSame([], $content->forStudent($withoutAid, $p2)['operators']);
    }

    public function testMaterialsListUsesProtectedRoute(): void
    {
        $exam = $this->makeExam(null, 'running');
        $this->db->run(
            "INSERT INTO exam_materials (exam_id, original_name, stored_name, mime, size_bytes, is_aid) VALUES (?, 'Blatt.pdf', 'abc123.pdf', 'application/pdf', 10, 1)",
            [$exam['id']],
        );
        $id = $this->db->lastInsertId();

        $materials = (new ExamContent($this->db))->materials($exam);

        self::assertSame([['id' => $id, 'name' => 'Blatt.pdf', 'url' => '/klausur/' . $exam['id'] . '/material/' . $id, 'is_aid' => true, 'mime' => 'application/pdf']], $materials);
    }

    public function testStandCarriesBaseClockOfTheExam(): void
    {
        $exam = $this->makeExam(null, 'running', ['duration_minutes' => 60]);
        $now = self::T0 + 10 * 60_000;

        $stand = $this->status->stand($exam, 0, $now);

        self::assertSame(self::T0 + 60 * 60_000, $stand['exam']['base_ends_at_ms']);
        self::assertSame(50 * 60_000, $stand['exam']['base_remaining_ms']);
        self::assertSame(self::T0, $stand['exam']['started_at_ms']);
        self::assertFalse($stand['exam']['paused']);
    }

    public function testBaseClockStandsStillWhilePaused(): void
    {
        $exam = $this->makeExam(null, 'paused', ['duration_minutes' => 60, 'paused_at' => ExamClock::toDb(self::T0 + 600_000)]);

        $a = $this->status->stand($exam, 0, self::T0 + 600_000);
        $b = $this->status->stand($exam, 0, self::T0 + 900_000);

        self::assertTrue($a['exam']['paused']);
        self::assertSame($a['exam']['base_remaining_ms'], $b['exam']['base_remaining_ms']);
    }

    public function testUnlimitedExamHasNoBaseClock(): void
    {
        $exam = $this->makeExam(null, 'running', ['duration_minutes' => null, 'mode' => 'practice']);
        $stand = $this->status->stand($exam, 0, self::T0 + 1000);
        self::assertNull($stand['exam']['base_ends_at_ms']);
        self::assertNull($stand['exam']['base_remaining_ms']);
        self::assertNull($stand['exam']['duration_minutes']);
    }}
