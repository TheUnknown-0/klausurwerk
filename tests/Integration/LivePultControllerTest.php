<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\ExamPreviewController;
use App\Controllers\MaterialController;
use App\Controllers\PultController;
use App\Core\HttpException;
use App\Services\ExamClock;
use Tests\Support\DatabaseTestCase;
use Tests\Support\LiveFixtures;

/** Pult-Controller und Zugriffsrechte (Fähigkeit monitor), Vorschau, Material. */
final class LivePultControllerTest extends DatabaseTestCase
{
    use LiveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        parent::tearDown();
    }

    /** @param array<string, mixed> $post @param array<string, mixed> $get @return array<string, mixed>|string */
    private function pult(?int $userId, string $action, int $examId, array $post = [], array $get = [], array $extra = []): array|string
    {
        $ctx = $this->makeContext($userId);
        $_POST = $post;
        $_GET = $get;

        return (new PultController($ctx))->$action(['id' => (string) $examId] + $extra);
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

    /** @return array{0: int, 1: array<string, mixed>, 2: int, 3: array<string, mixed>} */
    private function scene(string $status = 'running'): array
    {
        $teacher = $this->makeUser('teacher', 'Lehrer');
        $student = $this->makeUser('student', 'Mia');
        $exam = $this->makeExam($this->makeCourse([$student]), $status, ['owner_id' => $teacher]);
        $p = $this->makeParticipation((int) $exam['id'], $student, 'active', ['last_seen_at' => ExamClock::toDb(ExamClock::nowMs())]);

        return [$teacher, $exam, $student, $p];
    }

    // ---------- Rechte ----------

    public function testPultIsClosedToStudentsAndStrangers(): void
    {
        [, $exam, $student] = $this->scene();
        $stranger = $this->makeUser('teacher', 'Fremd');

        foreach (['state', 'action', 'seatPlan'] as $action) {
            $this->expectStatus(403, fn () => $this->pult($student, $action, (int) $exam['id']));
            $this->expectStatus(403, fn () => $this->pult($stranger, $action, (int) $exam['id']));
        }
        foreach (['answers', 'replay', 'events'] as $action) {
            $this->expectStatus(403, fn () => $this->pult($stranger, $action, (int) $exam['id'], [], [], ['participationId' => '1']));
        }
        $this->expectStatus(403, fn () => $this->pult($student, 'show', (int) $exam['id']));
        $this->expectStatus(403, fn () => $this->pult($stranger, 'show', (int) $exam['id']));
        $this->expectStatus(401, fn () => $this->pult(null, 'state', (int) $exam['id']));
        $this->expectStatus(404, fn () => $this->pult($stranger, 'state', 999999));
    }

    public function testAddedSupervisorMayOperateThePult(): void
    {
        [, $exam, , $p] = $this->scene();
        $aide = $this->makeUser('teacher', 'Aufsicht');
        $this->db->run('INSERT INTO exam_staff (exam_id, user_id) VALUES (?, ?)', [$exam['id'], $aide]);

        $stand = $this->pult($aide, 'state', (int) $exam['id']);
        self::assertTrue($stand['success']);
        self::assertCount(1, $stand['participants']);

        $r = $this->pult($aide, 'action', (int) $exam['id'], ['action' => 'lock', 'participation_ids' => [(int) $p['id']]]);
        self::assertSame(1, $r['affected']);
    }

    public function testActionRequiresCsrf(): void
    {
        [$teacher, $exam] = $this->scene();
        $ctx = $this->makeContext($teacher);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'falsch';
        $this->expectStatus(419, fn () => (new PultController($ctx))->action(['id' => (string) $exam['id']]));
    }

    // ---------- Seite ----------

    public function testShowRendersPultWithBootJson(): void
    {
        [$teacher, $exam] = $this->scene();
        $html = $this->pult($teacher, 'show', (int) $exam['id']);

        self::assertStringContainsString('id="pult-app"', $html);
        self::assertStringContainsString('pult.js', $html);
        self::assertStringContainsString('pult.css', $html);
        self::assertStringContainsString('app-content-wide', $html);
        preg_match('#id="pult-boot">(.*?)</script>#s', $html, $m);
        $boot = json_decode($m[1], true);
        self::assertSame((int) $exam['id'], $boot['exam_id']);
        self::assertSame('/api/pult/' . $exam['id'], $boot['api_base']);
        self::assertSame(1500, $boot['poll_ms']);
        self::assertTrue($boot['can']['monitor']);
        self::assertFalse($boot['has_room']);
    }

    public function testPollIntervalComesFromSettingAndIsClamped(): void
    {
        [$teacher, $exam] = $this->scene();
        $this->db->run("INSERT INTO settings (setting_key, setting_value) VALUES ('pult_poll_ms', '2500') ON DUPLICATE KEY UPDATE setting_value = '2500'");
        preg_match('#id="pult-boot">(.*?)</script>#s', (string) $this->pult($teacher, 'show', (int) $exam['id']), $m);
        self::assertSame(2500, json_decode($m[1], true)['poll_ms']);

        $this->db->run("UPDATE settings SET setting_value = '5' WHERE setting_key = 'pult_poll_ms'");
        preg_match('#id="pult-boot">(.*?)</script>#s', (string) $this->pult($teacher, 'show', (int) $exam['id']), $m);
        self::assertSame(500, json_decode($m[1], true)['poll_ms']);
    }

    // ---------- Stand und Aktionen ----------

    public function testStateReturnsApiShape(): void
    {
        [$teacher, $exam, , $p] = $this->scene();

        $stand = $this->pult($teacher, 'state', (int) $exam['id'], [], ['cursor' => '0']);

        foreach (['success', 'server_time_ms', 'exam', 'participants', 'not_joined', 'events', 'cursor'] as $key) {
            self::assertArrayHasKey($key, $stand);
        }
        $part = $stand['participants'][0];
        foreach (['id', 'user_id', 'name', 'state', 'lock_reason', 'online', 'last_seen_ms_ago', 'client_state', 'progress', 'clock', 'hand_raised', 'open_requests', 'last_save_ms_ago', 'pending', 'position', 'alarm'] as $key) {
            self::assertArrayHasKey($key, $part, $key);
        }
        self::assertSame((int) $p['id'], $part['id']);
        self::assertTrue($part['online']);
    }

    public function testActionStartViaControllerAndFinishConflictStatus(): void
    {
        $teacher = $this->makeUser('teacher', 'Lehrer');
        $student = $this->makeUser('student', 'Mia');
        $exam = $this->makeExam($this->makeCourse([$student]), 'lobby', ['owner_id' => $teacher]);
        $this->makeParticipation((int) $exam['id'], $student, 'waiting');

        $r = $this->pult($teacher, 'action', (int) $exam['id'], ['action' => 'start']);
        self::assertTrue($r['success']);
        self::assertSame('running', $this->reloadExam((int) $exam['id'])['status']);

        $r = $this->pult($teacher, 'action', (int) $exam['id'], ['action' => 'finish']);
        self::assertFalse($r['success']);
        self::assertCount(1, $r['errors']);
        self::assertSame('running', $this->reloadExam((int) $exam['id'])['status']);
    }

    public function testStatusChangesNeedMonitorCapability(): void
    {
        [, $exam] = $this->scene('lobby');
        $viewerOnly = $this->makeUser('teacher', 'Fremd');
        $this->expectStatus(403, fn () => $this->pult($viewerOnly, 'action', (int) $exam['id'], ['action' => 'start']));
        self::assertSame('lobby', $this->reloadExam((int) $exam['id'])['status']);
    }

    // ---------- Antworten, Replay, Ereignisse ----------

    public function testAnswersShowLiveContentAndOptionalTasks(): void
    {
        [$teacher, $exam, , $p] = $this->scene();
        $task = $this->makeTask((int) $exam['id'], 'text');
        $this->db->run(
            "INSERT INTO answers (participation_id, task_id, content, version, flagged, has_overtime, updated_at) VALUES (?, ?, '{\"text\":\"Hallo\"}', 4, 1, 1, ?)",
            [$p['id'], $task, ExamClock::toDb(self::T0)],
        );

        $r = $this->pult($teacher, 'answers', (int) $exam['id'], [], [], ['participationId' => (string) $p['id']]);
        self::assertSame(['content' => ['text' => 'Hallo'], 'version' => 4, 'updated_at_ms' => self::T0, 'has_overtime' => true, 'flagged' => true], $r['answers'][$task]);
        self::assertSame($task, $r['tasks'][0]['id']);
        self::assertSame('running', $r['participation']['state'] === 'active' ? 'running' : 'x');

        $r = $this->pult($teacher, 'answers', (int) $exam['id'], [], ['tasks' => '0'], ['participationId' => (string) $p['id']]);
        self::assertArrayNotHasKey('tasks', $r);
    }

    public function testAnswersOfAnotherExamAre404(): void
    {
        [$teacher, $exam] = $this->scene();
        [, $otherExam, , $otherP] = $this->scene();
        $this->expectStatus(404, fn () => $this->pult($teacher, 'answers', (int) $exam['id'], [], [], ['participationId' => (string) $otherP['id']]));
        self::assertNotNull($otherExam);
    }

    public function testReplayFiltersByTaskAndPages(): void
    {
        [$teacher, $exam, , $p] = $this->scene();
        $t1 = $this->makeTask((int) $exam['id'], 'text');
        $t2 = $this->makeTask((int) $exam['id'], 'text', null, [], 1);
        for ($seq = 1; $seq <= 5; $seq++) {
            $this->db->run(
                "INSERT INTO answer_ops (participation_id, task_id, seq, client_ts, server_ts, kind, payload, is_paste, is_overtime) VALUES (?, ?, ?, ?, ?, 'value', '{\"text\":\"x\"}', ?, 0)",
                [$p['id'], $seq % 2 === 1 ? $t1 : $t2, $seq, self::T0 + $seq, ExamClock::toDb(self::T0 + $seq), $seq === 3 ? 1 : 0],
            );
        }
        $extra = ['participationId' => (string) $p['id']];

        $r = $this->pult($teacher, 'replay', (int) $exam['id'], [], ['task_id' => (string) $t1], $extra);
        self::assertSame([1, 3, 5], array_column($r['ops'], 'seq'));
        self::assertTrue($r['ops'][1]['is_paste']);
        self::assertSame(['text' => 'x'], $r['ops'][0]['payload']);
        self::assertSame(self::T0 + 1, $r['ops'][0]['client_ts']);
        self::assertSame(self::T0 + 1, $r['ops'][0]['server_ts_ms']);
        self::assertFalse($r['has_more']);

        $r = $this->pult($teacher, 'replay', (int) $exam['id'], [], ['limit' => '2'], $extra);
        self::assertSame([1, 2], array_column($r['ops'], 'seq'));
        self::assertTrue($r['has_more']);

        $r = $this->pult($teacher, 'replay', (int) $exam['id'], [], ['after_seq' => '2', 'limit' => '10'], $extra);
        self::assertSame([3, 4, 5], array_column($r['ops'], 'seq'));
    }

    public function testEventsOfOnePerson(): void
    {
        [$teacher, $exam, , $p] = $this->scene();
        $this->db->run("INSERT INTO participation_events (exam_id, participation_id, type, severity, created_at) VALUES (?, ?, 'resize', 'info', ?)", [$exam['id'], $p['id'], ExamClock::toDb(self::T0)]);
        $this->db->run("INSERT INTO participation_events (exam_id, participation_id, type, severity, actor_id, created_at) VALUES (?, ?, 'time_added', 'info', ?, ?)", [$exam['id'], $p['id'], $teacher, ExamClock::toDb(self::T0 + 1)]);

        $r = $this->pult($teacher, 'events', (int) $exam['id'], [], [], ['participationId' => (string) $p['id']]);

        self::assertSame(['resize', 'time_added'], array_column($r['events'], 'type'));
        self::assertNull($r['events'][0]['actor']);
        self::assertSame('Lehrer', explode(' ', (string) $r['events'][1]['actor'])[0]);
    }

    // ---------- Sitzplan ----------

    /** @return array{0: int, 1: array<string, mixed>, 2: int, 3: int} */
    private function sceneWithRoom(): array
    {
        $teacher = $this->makeUser('teacher', 'Lehrer');
        $a = $this->makeUser('student', 'Anna');
        $b = $this->makeUser('student', 'Ben');
        $this->db->run("INSERT INTO rooms (name, grid_cols, grid_rows) VALUES ('R 1', 4, 3)");
        $roomId = $this->db->lastInsertId();
        $exam = $this->makeExam($this->makeCourse([$a, $b]), 'running', ['owner_id' => $teacher, 'room_id' => $roomId]);

        return [$teacher, $exam, $a, $b];
    }

    public function testSeatPlanNeedsARoom(): void
    {
        [$teacher, $exam, $student] = $this->scene();
        $r = $this->pult($teacher, 'seatPlan', (int) $exam['id'], ['positions' => [['user_id' => $student, 'col' => 0, 'row' => 0]]]);
        self::assertFalse($r['success']);
    }

    public function testSeatPlanSavesMovesAndClears(): void
    {
        [$teacher, $exam, $a, $b] = $this->sceneWithRoom();
        $roomId = (int) $exam['room_id'];

        $r = $this->pult($teacher, 'seatPlan', (int) $exam['id'], ['positions' => [
            ['user_id' => $a, 'col' => 0, 'row' => 0],
            ['user_id' => $b, 'col' => 3, 'row' => 2],
        ]]);
        self::assertTrue($r['success']);
        self::assertSame(2, $r['saved']);

        // Tausch der Plätze in einem Zug.
        $this->pult($teacher, 'seatPlan', (int) $exam['id'], ['positions' => [
            ['user_id' => $a, 'col' => 3, 'row' => 2],
            ['user_id' => $b, 'col' => 0, 'row' => 0],
        ]]);
        $rows = $this->db->fetchAll('SELECT user_id, col, `row` FROM room_positions WHERE room_id = ? ORDER BY user_id', [$roomId]);
        self::assertSame([[$a, 3, 2], [$b, 0, 0]], array_map(static fn (array $r): array => [(int) $r['user_id'], (int) $r['col'], (int) $r['row']], $rows));

        // Platz freigeben.
        $this->pult($teacher, 'seatPlan', (int) $exam['id'], ['positions' => [['user_id' => $b, 'col' => null, 'row' => null]]]);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM room_positions WHERE room_id = ?', [$roomId]));
    }

    public function testSeatPlanTakesOverCellOfOutsider(): void
    {
        [$teacher, $exam, $a] = $this->sceneWithRoom();
        $outsider = $this->makeUser('student', 'Fremd');
        $this->db->run('INSERT INTO room_positions (room_id, user_id, col, `row`) VALUES (?, ?, 1, 1)', [$exam['room_id'], $outsider]);

        $r = $this->pult($teacher, 'seatPlan', (int) $exam['id'], ['positions' => [['user_id' => $a, 'col' => 1, 'row' => 1]]]);

        self::assertTrue($r['success']);
        self::assertSame($a, (int) $this->db->fetchValue('SELECT user_id FROM room_positions WHERE room_id = ? AND col = 1 AND `row` = 1', [$exam['room_id']]));
    }

    public function testSeatPlanValidates(): void
    {
        [$teacher, $exam, $a, $b] = $this->sceneWithRoom();
        $outsider = $this->makeUser('student', 'Fremd');
        $cases = [
            'außerhalb Spalte' => [['user_id' => $a, 'col' => 4, 'row' => 0]],
            'außerhalb Zeile' => [['user_id' => $a, 'col' => 0, 'row' => 3]],
            'negativ' => [['user_id' => $a, 'col' => -1, 'row' => 0]],
            'doppelter Platz' => [['user_id' => $a, 'col' => 0, 'row' => 0], ['user_id' => $b, 'col' => 0, 'row' => 0]],
            'doppelte Person' => [['user_id' => $a, 'col' => 0, 'row' => 0], ['user_id' => $a, 'col' => 1, 'row' => 0]],
            'fremde Person' => [['user_id' => $outsider, 'col' => 0, 'row' => 0]],
        ];
        foreach ($cases as $name => $positions) {
            $r = $this->pult($teacher, 'seatPlan', (int) $exam['id'], ['positions' => $positions]);
            self::assertFalse($r['success'], $name);
        }
        $r = $this->pult($teacher, 'seatPlan', (int) $exam['id'], []);
        self::assertFalse($r['success']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM room_positions'));
    }

    // ---------- Vorschau und Material ----------

    public function testPreviewPageForTeacherWithView(): void
    {
        [$teacher, $exam] = $this->scene('draft');
        $ctx = $this->makeContext($teacher);
        $html = (new ExamPreviewController($ctx))->show(['id' => (string) $exam['id']]);

        preg_match('#id="exam-boot">(.*?)</script>#s', $html, $m);
        $boot = json_decode($m[1], true);
        self::assertTrue($boot['preview']);
        self::assertSame('/api/klausur/' . $exam['id'], $boot['api_base']);
        self::assertStringContainsString('exam.js', $html);
    }

    public function testPreviewIsClosedToStudentsAndStrangers(): void
    {
        [, $exam, $student] = $this->scene('draft');
        $stranger = $this->makeUser('teacher', 'Fremd');
        $this->expectStatus(403, fn () => (new ExamPreviewController($this->makeContext($student)))->show(['id' => (string) $exam['id']]));
        $this->expectStatus(403, fn () => (new ExamPreviewController($this->makeContext($stranger)))->show(['id' => (string) $exam['id']]));
    }

    public function testMaterialIsForbiddenBeforeStartAndForOutsiders(): void
    {
        $teacher = $this->makeUser('teacher', 'Lehrer');
        $student = $this->makeUser('student', 'Mia');
        $outsider = $this->makeUser('student', 'Otto');
        $exam = $this->makeExam($this->makeCourse([$student]), 'lobby', ['owner_id' => $teacher]);
        $this->db->run(
            "INSERT INTO exam_materials (exam_id, original_name, stored_name, mime, size_bytes) VALUES (?, 'a.pdf', 'zzz.pdf', 'application/pdf', 1)",
            [$exam['id']],
        );
        $mid = $this->db->lastInsertId();
        $this->makeParticipation((int) $exam['id'], $student, 'waiting');
        $stranger = $this->makeUser('teacher', 'Fremd');

        foreach ([$student, $outsider, $stranger] as $user) {
            $this->expectStatus(403, fn () => (new MaterialController($this->makeContext($user)))->show(['id' => (string) $exam['id'], 'mid' => (string) $mid]));
        }
        // Nach dem Start ist das Material für Teilnehmende freigegeben, für Außenstehende weiter nicht.
        $this->db->run("UPDATE exams SET status = 'running' WHERE id = ?", [$exam['id']]);
        $this->expectStatus(403, fn () => (new MaterialController($this->makeContext($outsider)))->show(['id' => (string) $exam['id'], 'mid' => (string) $mid]));
        // Teilnehmende mit gültigem Zugang scheitern hier erst an der fehlenden Datei (404), nicht an den Rechten.
        $this->expectStatus(404, fn () => (new MaterialController($this->makeContext($student)))->show(['id' => (string) $exam['id'], 'mid' => (string) $mid]));
    }

    public function testMaterialNeedsLogin(): void
    {
        $exam = $this->makeExam(null, 'running');
        $this->expectStatus(401, fn () => (new MaterialController($this->makeContext(null)))->show(['id' => (string) $exam['id'], 'mid' => '1']));
    }
}
