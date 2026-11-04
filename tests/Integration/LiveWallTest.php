<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\PultController;
use App\Controllers\StudentExamController;
use App\Core\HttpException;
use App\Services\ExamClock;
use App\Services\ExamSettings;
use App\Services\LiveStore;
use Tests\Support\DatabaseTestCase;
use Tests\Support\LiveFixtures;

/** Live-Wand: Schüler-Endpunkte /live und /bildschirm, Pult-Endpunkte, Ereignisse, Aufräumen. */
final class LiveWallTest extends DatabaseTestCase
{
    use LiveFixtures;

    private string $dir;
    private string|false $oldEnv;
    private bool $keepRate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldEnv = getenv('LIVE_DIR');
        $this->dir = sys_get_temp_dir() . '/kw-livewall-test-' . bin2hex(random_bytes(4));
        putenv('LIVE_DIR=' . $this->dir);
        $_POST = [];
        $_GET = [];
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->dir . '/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->dir);
        putenv($this->oldEnv === false ? 'LIVE_DIR' : 'LIVE_DIR=' . $this->oldEnv);
        $_POST = [];
        $_GET = [];
        http_response_code(200);
        parent::tearDown();
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function student(?int $userId, string $action, int $examId, array $input = []): array
    {
        http_response_code(200);
        // Das Ratenlimit (2 je Sekunde) ist eigener Test; sonst würden Mehrfachaufrufe hier gedrosselt.
        if (!$this->keepRate) {
            foreach (glob($this->dir . '/*/*.rate-*') ?: [] as $f) {
                @unlink($f);
            }
        }
        $ctx = $this->makeContext($userId);
        $_POST = $input;

        return (new StudentExamController($ctx))->$action(['id' => (string) $examId]);
    }

    /** @param array<string, mixed> $post @param array<string, mixed> $extra */
    private function pult(?int $userId, string $action, int $examId, array $post = [], array $extra = []): array|string
    {
        http_response_code(200);
        $ctx = $this->makeContext($userId);
        $_POST = $post;

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

    /** @return array{teacher: int, student: int, exam: array<string, mixed>, p: array<string, mixed>} */
    private function scene(array $settings = ['live_view' => true, 'screen_share' => true], string $state = 'active', string $status = 'running'): array
    {
        $teacher = $this->makeUser('teacher', 'Lehrer');
        $student = $this->makeUser('student', 'Mia');
        $exam = $this->makeExam($this->makeCourse([$student]), $status, ['owner_id' => $teacher], $settings);
        $p = $this->makeParticipation((int) $exam['id'], $student, $state);

        return ['teacher' => $teacher, 'student' => $student, 'exam' => $exam, 'p' => $p];
    }

    private static function jpeg(int $bytes = 200): string
    {
        return 'data:image/jpeg;base64,' . base64_encode("\xFF\xD8\xFF\xE0" . str_repeat('x', $bytes));
    }

    /** @return array<string, mixed> */
    private static function livePayload(array $over = []): array
    {
        return $over + [
            'task_id' => 5, 'view' => 'task', 'file' => 'Main.java', 'cursor_line' => 12,
            'code_excerpt' => "int a = 1;\nint b = 2;", 'excerpt_start_line' => 10,
            'console_tail' => "Hallo\nWelt", 'client_ms' => 1234,
        ];
    }

    // ---------- Einstellungen ----------

    public function testSettingsDefaultsAndNormalization(): void
    {
        $d = ExamSettings::defaults();
        self::assertTrue($d['live_view']);
        self::assertFalse($d['screen_share']);

        $n = ExamSettings::normalize(['live_view' => '0', 'screen_share' => '1']);
        self::assertFalse($n['live_view']);
        self::assertTrue($n['screen_share']);
        self::assertTrue(ExamSettings::normalize([])['live_view']);
    }

    public function testExamWithoutLiveKeysUsesDefaults(): void
    {
        $s = $this->scene();
        $this->db->run("UPDATE exams SET settings = '{}' WHERE id = ?", [$s['exam']['id']]);
        $id = (int) $s['exam']['id'];

        $r = $this->student($s['student'], 'live', $id, self::livePayload());
        self::assertTrue($r['ok'], 'live_view ist standardmäßig an');
        $this->student($s['student'], 'screen', $id, ['jpeg' => self::jpeg(), 'width' => 10, 'height' => 10, 'surface' => 'monitor']);
        self::assertSame(409, http_response_code(), 'screen_share ist standardmäßig aus');
        self::assertSame(['live_view' => true, 'screen_share' => false], $this->pult($s['teacher'], 'live', $id)['data']['settings']);
    }

    // ---------- /live ----------

    public function testLiveStoresStateAndAnswersWithInterval(): void
    {
        $s = $this->scene();
        $r = $this->student($s['student'], 'live', (int) $s['exam']['id'], self::livePayload());

        self::assertTrue($r['ok']);
        self::assertSame(['focus' => false, 'screen_interval_ms' => 5000], $r['data']);
        $state = (new LiveStore())->getState((int) $s['exam']['id'], (int) $s['p']['id']);
        self::assertSame('Main.java', $state['file']);
        self::assertSame(12, $state['cursor_line']);
        self::assertSame("Hallo\nWelt", $state['console_tail']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM participation_events WHERE participation_id = ?', [$s['p']['id']]), 'nichts in der Datenbank');
    }

    public function testLiveTrimsLongExcerptAndConsole(): void
    {
        $s = $this->scene();
        $code = implode("\n", array_map(static fn (int $i): string => 'zeile ' . $i, range(1, 100)));
        $console = implode("\n", array_map(static fn (int $i): string => 'out ' . $i, range(1, 50)));
        $this->student($s['student'], 'live', (int) $s['exam']['id'], self::livePayload(['code_excerpt' => $code, 'console_tail' => $console]));

        $state = (new LiveStore())->getState((int) $s['exam']['id'], (int) $s['p']['id']);
        $codeLines = explode("\n", $state['code_excerpt']);
        self::assertCount(60, $codeLines);
        self::assertSame('zeile 1', $codeLines[0]);
        $consoleLines = explode("\n", $state['console_tail']);
        self::assertCount(20, $consoleLines);
        self::assertSame('out 50', $consoleLines[19], 'Konsole behält das Ende');

        $this->student($s['student'], 'live', (int) $s['exam']['id'], self::livePayload(['code_excerpt' => str_repeat('a', 9000), 'console_tail' => str_repeat('b', 9000)]));
        $state = (new LiveStore())->getState((int) $s['exam']['id'], (int) $s['p']['id']);
        self::assertSame(4000, mb_strlen($state['code_excerpt']));
        self::assertSame(2000, mb_strlen($state['console_tail']));
    }

    public function testLiveReportsFocus(): void
    {
        $s = $this->scene();
        (new LiveStore())->setFocus((int) $s['exam']['id'], (int) $s['p']['id']);

        $r = $this->student($s['student'], 'live', (int) $s['exam']['id'], self::livePayload());
        self::assertSame(['focus' => true, 'screen_interval_ms' => 1000], $r['data']);
    }

    public function testLiveRejectsInvalidData(): void
    {
        $s = $this->scene();
        foreach ([['view' => 'a b!'], ['code_excerpt' => ['x']], ['file' => 5], ['cursor_line' => 'x']] as $bad) {
            $r = $this->student($s['student'], 'live', (int) $s['exam']['id'], self::livePayload($bad));
            self::assertFalse($r['success']);
            self::assertSame(422, http_response_code());
        }
    }

    public function testLiveIs409WhenSettingOffOrStateOrStatusWrong(): void
    {
        $off = $this->scene(['live_view' => false]);
        $this->student($off['student'], 'live', (int) $off['exam']['id'], self::livePayload());
        self::assertSame(409, http_response_code());

        foreach (['waiting', 'submitted', 'absent', 'device_pending'] as $state) {
            $s = $this->scene(['live_view' => true], $state);
            $r = $this->student($s['student'], 'live', (int) $s['exam']['id'], self::livePayload());
            self::assertFalse($r['success'], $state);
            self::assertSame(409, http_response_code(), $state);
        }
        $lobby = $this->scene(['live_view' => true], 'active', 'lobby');
        $this->student($lobby['student'], 'live', (int) $lobby['exam']['id'], self::livePayload());
        self::assertSame(409, http_response_code());

        foreach (['locked', 'paused'] as $state) {
            $s = $this->scene(['live_view' => true], $state);
            $r = $this->student($s['student'], 'live', (int) $s['exam']['id'], self::livePayload());
            self::assertTrue($r['ok'], $state);
        }
    }

    public function testLiveIsRateLimited(): void
    {
        $this->keepRate = true;
        $s = $this->scene();
        $codes = [];
        for ($i = 0; $i < 6; $i++) {
            $r = $this->student($s['student'], 'live', (int) $s['exam']['id'], self::livePayload());
            $codes[] = ($r['ok'] ?? false) ? 200 : http_response_code();
        }
        self::assertSame(200, $codes[0]);
        self::assertContains(429, $codes);
    }

    public function testLiveAccessRules(): void
    {
        $s = $this->scene();
        $other = $this->makeUser('student', 'Fremd');
        $id = (int) $s['exam']['id'];

        $this->expectStatus(401, fn () => $this->student(null, 'live', $id, self::livePayload()));
        $this->expectStatus(403, fn () => $this->student($s['teacher'], 'live', $id, self::livePayload()));
        $this->expectStatus(403, fn () => $this->student($other, 'live', $id, self::livePayload()));

        $member = $this->makeUser('student', 'OhneTeilnahme');
        $this->db->run('INSERT INTO course_members (course_id, user_id) VALUES (?, ?)', [$s['exam']['course_id'], $member]);
        $this->expectStatus(409, fn () => $this->student($member, 'live', $id, self::livePayload()));

        $ctx = $this->makeContext($s['student']);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'falsch';
        $this->expectStatus(419, fn () => (new StudentExamController($ctx))->live(['id' => (string) $id]));
    }

    public function testLiveWritesOnlyOwnParticipation(): void
    {
        $s = $this->scene();
        $other = $this->makeUser('student', 'Zweite');
        $this->db->run('INSERT INTO course_members (course_id, user_id) VALUES (?, ?)', [$s['exam']['course_id'], $other]);
        $p2 = $this->makeParticipation((int) $s['exam']['id'], $other);

        // Eine fremde participation_id im Body ändert nichts.
        $this->student($s['student'], 'live', (int) $s['exam']['id'], self::livePayload(['participation_id' => $p2['id']]));
        $store = new LiveStore();
        self::assertNotNull($store->getState((int) $s['exam']['id'], (int) $s['p']['id']));
        self::assertNull($store->getState((int) $s['exam']['id'], (int) $p2['id']));
    }

    // ---------- /bildschirm ----------

    public function testScreenStoresImageAndMeta(): void
    {
        $s = $this->scene();
        $r = $this->student($s['student'], 'screen', (int) $s['exam']['id'], ['jpeg' => self::jpeg(), 'width' => 1280, 'height' => 720, 'surface' => 'monitor']);

        self::assertTrue($r['ok']);
        self::assertSame(['focus' => false, 'screen_interval_ms' => 5000], $r['data']);
        $store = new LiveStore();
        $meta = $store->getScreenMeta((int) $s['exam']['id'], (int) $s['p']['id']);
        self::assertSame([1280, 720, 'monitor'], [$meta['width'], $meta['height'], $meta['surface']]);
        self::assertStringStartsWith("\xFF\xD8\xFF", (string) $store->getScreenImage((int) $s['exam']['id'], (int) $s['p']['id']));
    }

    public function testScreenUnknownSurfaceBecomesUnknown(): void
    {
        $s = $this->scene();
        $this->student($s['student'], 'screen', (int) $s['exam']['id'], ['jpeg' => self::jpeg(), 'width' => 10, 'height' => 10, 'surface' => '../../etc']);
        $meta = (new LiveStore())->getScreenMeta((int) $s['exam']['id'], (int) $s['p']['id']);
        self::assertSame('unknown', $meta['surface']);
    }

    public function testScreenIs409WhenSettingOff(): void
    {
        $s = $this->scene(['live_view' => true, 'screen_share' => false]);
        $r = $this->student($s['student'], 'screen', (int) $s['exam']['id'], ['jpeg' => self::jpeg(), 'width' => 10, 'height' => 10, 'surface' => 'monitor']);
        self::assertFalse($r['success']);
        self::assertSame(409, http_response_code());
    }

    public function testScreenIs409InWrongState(): void
    {
        $s = $this->scene(['screen_share' => true], 'submitted');
        $this->student($s['student'], 'screen', (int) $s['exam']['id'], ['jpeg' => self::jpeg(), 'width' => 10, 'height' => 10, 'surface' => 'monitor']);
        self::assertSame(409, http_response_code());
    }

    public function testScreenRejectsTooLargeWith413(): void
    {
        $s = $this->scene();
        $r = $this->student($s['student'], 'screen', (int) $s['exam']['id'], ['jpeg' => self::jpeg(700 * 1024), 'width' => 10, 'height' => 10, 'surface' => 'monitor']);
        self::assertFalse($r['success']);
        self::assertSame(413, http_response_code());
        self::assertNull((new LiveStore())->getScreenMeta((int) $s['exam']['id'], (int) $s['p']['id']));

        // Knapp unter der Grenze geht durch (Rate-Eimer ist durch die erste Anfrage nicht voll).
        $ok = $this->student($s['student'], 'screen', (int) $s['exam']['id'], ['jpeg' => self::jpeg(590 * 1024), 'width' => 10, 'height' => 10, 'surface' => 'monitor']);
        self::assertTrue($ok['ok']);
    }

    public function testScreenRejectsNonJpeg(): void
    {
        $s = $this->scene();
        $id = (int) $s['exam']['id'];

        $png = 'data:image/jpeg;base64,' . base64_encode("\x89PNG\r\n\x1a\nxxxx");
        $r = $this->student($s['student'], 'screen', $id, ['jpeg' => $png, 'width' => 10, 'height' => 10, 'surface' => 'monitor']);
        self::assertFalse($r['success']);
        self::assertSame(422, http_response_code());

        $wrongPrefix = 'data:image/png;base64,' . base64_encode("\xFF\xD8\xFFxx");
        $this->student($s['student'], 'screen', $id, ['jpeg' => $wrongPrefix, 'width' => 10, 'height' => 10, 'surface' => 'monitor']);
        self::assertSame(422, http_response_code());

        $this->student($s['student'], 'screen', $id, ['jpeg' => 'data:image/jpeg;base64,@@@', 'width' => 10, 'height' => 10, 'surface' => 'monitor']);
        self::assertSame(422, http_response_code());
        self::assertNull((new LiveStore())->getScreenMeta($id, (int) $s['p']['id']));
    }

    public function testScreenRejectsBadDimensions(): void
    {
        $s = $this->scene();
        $this->student($s['student'], 'screen', (int) $s['exam']['id'], ['jpeg' => self::jpeg(), 'width' => 0, 'height' => 10, 'surface' => 'monitor']);
        self::assertSame(422, http_response_code());
    }

    public function testScreenIsRateLimited(): void
    {
        $this->keepRate = true;
        $s = $this->scene();
        $codes = [];
        for ($i = 0; $i < 6; $i++) {
            $r = $this->student($s['student'], 'screen', (int) $s['exam']['id'], ['jpeg' => self::jpeg(), 'width' => 10, 'height' => 10, 'surface' => 'monitor']);
            $codes[] = ($r['ok'] ?? false) ? 200 : http_response_code();
        }
        self::assertSame(200, $codes[0]);
        self::assertContains(429, $codes);
    }

    public function testScreenAccessRules(): void
    {
        $s = $this->scene();
        $id = (int) $s['exam']['id'];
        $in = ['jpeg' => self::jpeg(), 'width' => 10, 'height' => 10, 'surface' => 'monitor'];

        $this->expectStatus(401, fn () => $this->student(null, 'screen', $id, $in));
        $this->expectStatus(403, fn () => $this->student($s['teacher'], 'screen', $id, $in));
        $this->expectStatus(403, fn () => $this->student($this->makeUser('student', 'Fremd'), 'screen', $id, $in));
        $ctx = $this->makeContext($s['student']);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'falsch';
        $this->expectStatus(419, fn () => (new StudentExamController($ctx))->screen(['id' => (string) $id]));
    }

    // ---------- Ereignisse ----------

    public function testScreenShareEventsAlarmWithoutLock(): void
    {
        $s = $this->scene(['screen_share' => true]);
        $id = (int) $s['exam']['id'];
        $events = [
            ['type' => 'screen_share_started', 'details' => ['surface' => 'monitor']],
            ['type' => 'screen_share_stopped'],
            ['type' => 'screen_share_denied', 'details' => ['reason' => 'NotAllowedError']],
            ['type' => 'screen_share_wrong_surface', 'details' => ['surface' => 'window']],
        ];
        $r = $this->student($s['student'], 'event', $id, ['events' => $events]);

        self::assertTrue($r['success']);
        self::assertSame('active', $r['state'], 'keine Sperre');
        $sev = array_column($this->db->fetchAll('SELECT type, severity FROM participation_events WHERE participation_id = ?', [$s['p']['id']]), 'severity', 'type');
        self::assertSame('info', $sev['screen_share_started']);
        self::assertSame('alarm', $sev['screen_share_stopped']);
        self::assertSame('alarm', $sev['screen_share_denied']);
        self::assertSame('alarm', $sev['screen_share_wrong_surface']);
    }

    // ---------- Pult ----------

    public function testPultLiveListsParticipantsWithAges(): void
    {
        $s = $this->scene();
        $id = (int) $s['exam']['id'];
        $this->student($s['student'], 'live', $id, self::livePayload());
        $this->student($s['student'], 'screen', $id, ['jpeg' => self::jpeg(), 'width' => 480, 'height' => 270, 'surface' => 'monitor']);
        $idle = $this->makeUser('student', 'Still');
        $p2 = $this->makeParticipation($id, $idle);

        $r = $this->pult($s['teacher'], 'live', $id);

        self::assertTrue($r['ok']);
        self::assertSame(['live_view' => true, 'screen_share' => true], $r['data']['settings']);
        $byId = array_column($r['data']['participants'], null, 'participation_id');
        self::assertCount(2, $byId);
        $mine = $byId[(int) $s['p']['id']];
        self::assertSame('Main.java', $mine['live']['file']);
        self::assertArrayHasKey('received_ms', $mine['live']);
        self::assertIsInt($mine['live_age_ms']);
        self::assertSame(480, $mine['screen']['width']);
        self::assertIsInt($mine['screen_age_ms']);
        $idleRow = $byId[(int) $p2['id']];
        self::assertNull($idleRow['live']);
        self::assertNull($idleRow['live_age_ms']);
        self::assertNull($idleRow['screen']);
        self::assertNull($idleRow['screen_age_ms']);
    }

    public function testPultEndpointsNeedMonitorCapability(): void
    {
        $s = $this->scene();
        $id = (int) $s['exam']['id'];
        $stranger = $this->makeUser('teacher', 'Fremd');
        $pid = (string) $s['p']['id'];

        foreach ([$s['student'], $stranger] as $user) {
            $this->expectStatus(403, fn () => $this->pult($user, 'live', $id));
            $this->expectStatus(403, fn () => $this->pult($user, 'screenImage', $id, [], ['participationId' => $pid]));
            $this->expectStatus(403, fn () => $this->pult($user, 'focus', $id, ['participation_id' => (int) $pid]));
        }
        $this->expectStatus(401, fn () => $this->pult(null, 'live', $id));

        $ctx = $this->makeContext($s['teacher']);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'falsch';
        $this->expectStatus(419, fn () => (new PultController($ctx))->focus(['id' => (string) $id]));
    }

    public function testPultImageServedOr404(): void
    {
        $s = $this->scene();
        $id = (int) $s['exam']['id'];
        $pid = (string) $s['p']['id'];

        $this->expectStatus(404, fn () => $this->pult($s['teacher'], 'screenImage', $id, [], ['participationId' => $pid]));

        $this->student($s['student'], 'screen', $id, ['jpeg' => self::jpeg(), 'width' => 10, 'height' => 10, 'surface' => 'monitor']);
        $body = $this->pult($s['teacher'], 'screenImage', $id, [], ['participationId' => $pid]);
        self::assertIsString($body);
        self::assertStringStartsWith("\xFF\xD8\xFF", $body);

        // Teilnahme einer anderen Klausur: 404.
        $other = $this->makeExam($this->makeCourse([$s['student']]), 'running', ['owner_id' => $s['teacher']]);
        $this->expectStatus(404, fn () => $this->pult($s['teacher'], 'screenImage', (int) $other['id'], [], ['participationId' => $pid]));
    }

    public function testPultFocusSetsAndClears(): void
    {
        $s = $this->scene();
        $id = (int) $s['exam']['id'];
        $pid = (int) $s['p']['id'];

        $r = $this->pult($s['teacher'], 'focus', $id, ['participation_id' => $pid]);
        self::assertTrue($r['ok']);
        $live = $this->student($s['student'], 'live', $id, self::livePayload());
        self::assertSame(['focus' => true, 'screen_interval_ms' => 1000], $live['data']);

        $this->pult($s['teacher'], 'focus', $id, ['participation_id' => null]);
        $screen = $this->student($s['student'], 'screen', $id, ['jpeg' => self::jpeg(), 'width' => 10, 'height' => 10, 'surface' => 'monitor']);
        self::assertSame(['focus' => false, 'screen_interval_ms' => 5000], $screen['data']);
    }

    public function testPultFocusIs409AfterTheExamEndedAndLeavesNoFiles(): void
    {
        $s = $this->scene(['live_view' => true, 'screen_share' => true], 'submitted', 'finished');
        $id = (int) $s['exam']['id'];

        $r = $this->pult($s['teacher'], 'focus', $id, ['participation_id' => (int) $s['p']['id']]);
        self::assertFalse($r['success']);
        self::assertSame(409, http_response_code());

        self::assertDirectoryDoesNotExist($this->dir . '/e' . $id);
        // Aufheben bleibt immer möglich und legt nichts an.
        $r = $this->pult($s['teacher'], 'focus', $id, ['participation_id' => null]);
        self::assertTrue($r['ok']);
        self::assertDirectoryDoesNotExist($this->dir . '/e' . $id);
    }

    public function testPultFocusRejectsForeignParticipation(): void
    {
        $s = $this->scene();
        $other = $this->makeExam($this->makeCourse([$s['student']]), 'running', ['owner_id' => $s['teacher']]);
        $foreign = $this->makeParticipation((int) $other['id'], $s['student']);

        $this->expectStatus(404, fn () => $this->pult($s['teacher'], 'focus', (int) $s['exam']['id'], ['participation_id' => (int) $foreign['id']]));
        $r = $this->pult($s['teacher'], 'focus', (int) $s['exam']['id'], ['participation_id' => 'abc']);
        self::assertFalse($r['success']);
        self::assertSame(400, http_response_code());
    }

    // ---------- Aufräumen ----------

    public function testFinishingTheExamDeletesLiveData(): void
    {
        $s = $this->scene();
        $id = (int) $s['exam']['id'];
        $this->student($s['student'], 'live', $id, self::livePayload());
        $this->student($s['student'], 'screen', $id, ['jpeg' => self::jpeg(), 'width' => 10, 'height' => 10, 'surface' => 'monitor']);
        $this->db->run("UPDATE participations SET state = 'submitted' WHERE id = ?", [$s['p']['id']]);
        $store = new LiveStore();
        self::assertNotNull($store->getScreenImage($id, (int) $s['p']['id']));

        $r = $this->pult($s['teacher'], 'action', $id, ['action' => 'finish']);

        self::assertTrue($r['success'], json_encode($r));
        self::assertDirectoryDoesNotExist($this->dir . '/e' . $id);
        self::assertNull($store->getState($id, (int) $s['p']['id']));
        self::assertNull($store->getScreenImage($id, (int) $s['p']['id']));
    }

    public function testPultLiveIgnoresStaleEntries(): void
    {
        $s = $this->scene();
        $id = (int) $s['exam']['id'];
        $store = new LiveStore();
        $old = ExamClock::nowMs() - 200_000;
        $store->putState($id, (int) $s['p']['id'], ['view' => 'task'], $old);
        $store->putScreen($id, (int) $s['p']['id'], "\xFF\xD8\xFFx", 10, 10, 'monitor', $old);

        $row = $this->pult($s['teacher'], 'live', $id)['data']['participants'][0];
        self::assertNull($row['live']);
        self::assertNull($row['screen']);
        $this->expectStatus(404, fn () => $this->pult($s['teacher'], 'screenImage', $id, [], ['participationId' => (string) $s['p']['id']]));
    }
}
