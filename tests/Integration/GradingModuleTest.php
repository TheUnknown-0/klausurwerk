<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\GradingController;
use App\Controllers\ResultsController;
use App\Controllers\StudentResultsController;
use App\Core\HttpException;
use App\Services\AutoGrader;
use App\Services\ExamStatistics;
use App\Services\GradingScales;
use App\Services\GradingService;
use App\Services\ResultReport;
use App\Services\RunnerClient;
use Tests\Support\DatabaseTestCase;
use Tests\Support\LiveFixtures;

/** Korrektur, Vorbewertung, Rechte, Statusfluss, Rückgabe und PDF gegen die Datenbank. */
final class GradingModuleTest extends DatabaseTestCase
{
    use LiveFixtures;

    private const MODEL_SECRET = 'MUSTERLOESUNG-GEHEIM-4711';
    private const TEST_SECRET = 'VERSTECKTER-TEST-ERWARTUNG-0815';
    private const EXPECTATION = 'ERWARTUNGSHORIZONT-SICHTBAR-42';

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

    // ---------- Hilfen ----------

    private function expectStatus(int $status, callable $fn): void
    {
        try {
            $fn();
            self::fail("HTTP {$status} erwartet");
        } catch (HttpException $e) {
            self::assertSame($status, $e->status, $e->getMessage());
        }
    }

    private function grading(): GradingService
    {
        return new GradingService($this->db, new GradingScales($this->db));
    }

    /** @param array<string, mixed> $solution @param list<array<string, mixed>> $tests */
    private function solution(int $taskId, array $solution = [], string $model = '', string $expectation = '', array $tests = []): void
    {
        $this->db->run(
            'INSERT INTO task_solutions (task_id, solution, model_answer, expectation_md, tests) VALUES (?, ?, ?, ?, ?)',
            [$taskId, json_encode($solution === [] ? new \stdClass() : $solution), $model, $expectation, json_encode($tests)],
        );
    }

    /** @param array<string, mixed> $content */
    private function answer(int $participationId, int $taskId, array $content, bool $overtime = false): void
    {
        $this->db->run(
            'INSERT INTO answers (participation_id, task_id, content, version, has_overtime) VALUES (?, ?, ?, 1, ?)',
            [$participationId, $taskId, json_encode($content), $overtime ? 1 : 0],
        );
    }

    private function setPoints(int $taskId, float $points): void
    {
        $this->db->run('UPDATE tasks SET points = ? WHERE id = ?', [$points, $taskId]);
    }

    /**
     * Klausur mit Auswahl-, Zuordnungs-, Lücken-, Text- und Code-Aufgabe, drei Schüler:innen.
     *
     * @return array<string, mixed>
     */
    private function scene(string $status = 'finished'): array
    {
        $owner = $this->makeUser('teacher', 'Haupt');
        $s1 = $this->makeUser('student', 'Anna');
        $s2 = $this->makeUser('student', 'Ben');
        $s3 = $this->makeUser('student', 'Cleo');
        $course = $this->makeCourse([$s1, $s2, $s3]);
        $exam = $this->makeExam($course, $status, ['owner_id' => $owner]);
        $eid = (int) $exam['id'];

        $choice = $this->makeTask($eid, 'choice', null, ['multiple' => false, 'options' => [['id' => 'a', 'text_md' => 'Stack'], ['id' => 'b', 'text_md' => 'Queue']], 'shuffle' => false], 0);
        $this->solution($choice, ['correct' => ['a'], 'scoring' => 'all_or_nothing'], 'Stack, weil ' . self::MODEL_SECRET, self::EXPECTATION . ' Auswahl');
        $matching = $this->makeTask($eid, 'matching', null, [
            'left' => [['id' => 'l1', 'text_md' => 'push'], ['id' => 'l2', 'text_md' => 'enqueue']],
            'right' => [['id' => 'r1', 'text_md' => 'Stack'], ['id' => 'r2', 'text_md' => 'Queue']], 'shuffle' => false,
        ], 1);
        $this->solution($matching, ['pairs' => ['l1' => 'r1', 'l2' => 'r2']]);
        $gaps = $this->makeTask($eid, 'gaps', null, [
            'mode' => 'code', 'language' => 'java', 'template' => 'int i {{g1}} 3;',
            'gaps' => [['id' => 'g1', 'width' => 3, 'options' => null]],
        ], 2);
        $this->solution($gaps, ['gaps' => ['g1' => ['accepted' => ['<'], 'case_sensitive' => true, 'trim' => true, 'regex' => null]]]);
        $text = $this->makeTask($eid, 'text', null, [], 3);
        $this->solution($text, [], 'Muster ' . self::MODEL_SECRET);
        $code = $this->makeTask($eid, 'code', null, ['language' => 'java', 'main_file' => 'Main.java', 'java_version' => '21'], 4);
        $this->solution($code, [], 'class Main { /* ' . self::MODEL_SECRET . ' */ }', 'Code-Erwartung', [
            ['name' => 'Addition', 'type' => 'io', 'input' => '2 3', 'expected_output' => self::TEST_SECRET, 'compare' => 'trim', 'points' => 1],
            ['name' => 'Subtraktion', 'type' => 'io', 'input' => '5 3', 'expected_output' => '2', 'compare' => 'trim', 'points' => 3],
        ]);
        $this->setPoints($code, 4.0);

        $p1 = $this->makeParticipation($eid, $s1, 'submitted');
        $p2 = $this->makeParticipation($eid, $s2, 'submitted');
        $p3 = $this->makeParticipation($eid, $s3, 'active');

        return compact('owner', 's1', 's2', 's3', 'course', 'exam', 'eid', 'choice', 'matching', 'gaps', 'text', 'code', 'p1', 'p2', 'p3');
    }

    /** @param array<string, mixed> $post @param array<string, mixed> $get @return array<string, mixed>|string */
    private function call(string $controller, ?int $userId, string $action, array $params = [], array $post = [], array $get = []): array|string
    {
        $ctx = $this->makeContext($userId);
        $_POST = $post;
        $_GET = $get;

        return (new $controller($ctx))->$action($params);
    }

    // ---------- Vorbewertung: Auswahl, Zuordnung, Lücken ----------

    public function testAutoStatusCountsOnlyAutoGradableTasks(): void
    {
        $s = $this->scene();
        $grader = new AutoGrader($this->db);
        $status = $grader->status($s['eid']);
        // Auswahl, Zuordnung, Lücken und Code mit Tests: 4 Aufgaben x 3 Personen; Freitext lässt sich nicht prüfen
        self::assertSame(12, $status['total']);
        self::assertSame(12, $status['pending']);
        self::assertSame(0, $status['errors']);
        self::assertSame([$s['choice'], $s['matching'], $s['gaps'], $s['code']], array_keys($grader->autoTasks($s['eid'])));
    }

    public function testAutoGraderStepRoundsAndMarksEmptyAnswers(): void
    {
        $s = $this->scene();
        $pid1 = (int) $s['p1']['id'];
        $pid2 = (int) $s['p2']['id'];
        $this->answer($pid1, $s['choice'], ['selected' => ['a']]);
        $this->answer($pid2, $s['choice'], ['selected' => ['b']]);
        $this->answer($pid2, $s['matching'], ['pairs' => ['l1' => 'r1', 'l2' => 'r1']]);
        $this->answer($pid1, $s['gaps'], ['values' => ['g1' => ' < ']]);
        $this->answer($pid2, $s['gaps'], ['values' => ['g1' => '<=']]);

        // Code-Aufgaben brauchen den Runner: hier nicht prüfen, sondern gesondert (siehe unten)
        $this->db->run('DELETE FROM task_solutions WHERE task_id = ?', [$s['code']]);

        $grader = new AutoGrader($this->db);
        $r = $grader->step($s['exam'], 100);
        self::assertSame(9, $r['processed'], '3 Personen x (Auswahl, Zuordnung, Lücken)');
        self::assertSame(0, $r['remaining']);
        self::assertFalse($r['aborted']);

        $get = fn (int $pid, int $tid): array => $this->db->fetchOne('SELECT * FROM auto_gradings WHERE participation_id = ? AND task_id = ?', [$pid, $tid]);
        self::assertSame(4.0, (float) $get($pid1, $s['choice'])['suggested_points']);
        self::assertSame(0.0, (float) $get($pid2, $s['choice'])['suggested_points']);
        self::assertSame(2.0, (float) $get($pid2, $s['matching'])['suggested_points'], 'eine von zwei Zuordnungen richtig: halbe Punkte');
        self::assertSame(4.0, (float) $get($pid1, $s['gaps'])['suggested_points'], 'Leerraum wird ignoriert');
        self::assertSame(0.0, (float) $get($pid2, $s['gaps'])['suggested_points']);
        // Person ohne Antwort: 0 Punkte, als leer vermerkt
        $empty = $get((int) $s['p3']['id'], $s['choice']);
        self::assertSame(0.0, (float) $empty['suggested_points']);
        self::assertSame('empty', json_decode($empty['details'], true)['source']);
        self::assertSame('choice', json_decode($get($pid1, $s['choice'])['details'], true)['source']);

        // zweiter Lauf: nichts mehr zu tun
        self::assertSame(0, $grader->step($s['exam'], 100)['processed']);
    }

    public function testAutoGraderProcessesInBatchesAndReportsRemainder(): void
    {
        $s = $this->scene();
        $this->db->run('DELETE FROM task_solutions WHERE task_id = ?', [$s['code']]);
        $grader = new AutoGrader($this->db);
        $first = $grader->step($s['exam'], 4);
        self::assertSame(4, $first['processed']);
        self::assertSame(5, $first['remaining']);
        $second = $grader->step($s['exam'], 4);
        self::assertSame(1, $second['remaining']);
        self::assertSame(0, $grader->step($s['exam'], 4)['remaining']);
    }

    public function testRetestRecalculatesSingleCell(): void
    {
        $s = $this->scene();
        $pid = (int) $s['p1']['id'];
        $this->answer($pid, $s['choice'], ['selected' => ['b']]);
        $grader = new AutoGrader($this->db);
        self::assertSame(0.0, $grader->retest($s['exam'], $pid, $s['choice'])['suggested']);

        $this->db->run('UPDATE task_solutions SET solution = ? WHERE task_id = ?', [json_encode(['correct' => ['b'], 'scoring' => 'all_or_nothing']), $s['choice']]);
        $again = new AutoGrader($this->db);
        self::assertSame(4.0, $again->retest($s['exam'], $pid, $s['choice'])['suggested']);
        self::assertNull($again->retest($s['exam'], $pid, $s['text']), 'Freitext lässt sich nicht automatisch prüfen');
    }

    // ---------- Vorbewertung: Code mit Runner (Fake-Transport) ----------

    /** @param list<array<string, mixed>> $results */
    private function fakeRunner(array $results, array &$calls): RunnerClient
    {
        return new RunnerClient(['url' => 'http://runner:8080', 'token' => 'geheim', 'timeout_seconds' => 5], function (string $method, string $url, array $headers, ?string $body) use ($results, &$calls): array {
            $calls[] = ['method' => $method, 'url' => $url, 'body' => json_decode((string) $body, true)];

            return [200, json_encode(['results' => $results])];
        });
    }

    public function testCodeTestsRunThroughRunnerWithReadonlyFiles(): void
    {
        $s = $this->scene();
        $pid = (int) $s['p1']['id'];
        $this->db->run('INSERT INTO task_files (task_id, filename, content, is_readonly, position) VALUES (?, ?, ?, 1, 0)', [$s['code'], 'Helper.java', 'class Helper {}']);
        $this->db->run('INSERT INTO task_files (task_id, filename, content, is_readonly, position) VALUES (?, ?, ?, 0, 1)', [$s['code'], 'Main.java', '// Start']);
        $this->answer($pid, $s['code'], ['files' => [
            ['name' => 'Main.java', 'content' => 'class Main { public static void main(String[] a) {} }'],
            ['name' => 'Helper.java', 'content' => 'class Helper { /* manipuliert */ }'],
        ]]);

        $calls = [];
        $runner = $this->fakeRunner([['name' => 'Addition', 'ok' => true], ['name' => 'Subtraktion', 'ok' => false, 'message' => 'erwartet 2', 'stdout' => '3']], $calls);
        $grader = new AutoGrader($this->db, $runner);
        $result = $grader->retest($s['exam'], $pid, $s['code']);

        self::assertCount(1, $calls);
        self::assertSame('POST', $calls[0]['method']);
        self::assertStringEndsWith('/tests', $calls[0]['url']);
        $payload = $calls[0]['body'];
        self::assertSame('java', $payload['language']);
        self::assertSame('21', $payload['java_version']);
        self::assertTrue($payload['reveal'], 'Lehrkräfte-Läufe verlangen die erwarteten Ausgaben');
        self::assertSame('Main.java', $payload['main_file']);
        self::assertCount(2, $payload['tests']);
        $files = array_column($payload['files'], 'content', 'name');
        self::assertSame('class Helper {}', $files['Helper.java'], 'Schreibgeschützte Aufgabendatei kann die Abgabe nicht ersetzen');
        self::assertStringContainsString('public static void main', $files['Main.java']);

        // Testpunkte 1 + 3, nur der erste bestanden: 1 Punkt
        self::assertSame(1.0, $result['suggested']);
        $row = $this->db->fetchOne('SELECT * FROM auto_gradings WHERE participation_id = ? AND task_id = ?', [$pid, $s['code']]);
        $details = json_decode($row['details'], true);
        self::assertSame('tests', $details['source']);
        self::assertSame(1, $details['passed']);
        self::assertSame(2, $details['total']);
        self::assertSame('erwartet 2', $details['results'][1]['message']);
        self::assertFalse($details['results'][1]['ok']);
    }

    public function testCodeWithoutAnswerSkipsRunner(): void
    {
        $s = $this->scene();
        $calls = [];
        $grader = new AutoGrader($this->db, $this->fakeRunner([], $calls));
        $r = $grader->retest($s['exam'], (int) $s['p2']['id'], $s['code']);
        self::assertSame(0.0, $r['suggested']);
        self::assertSame('empty', $r['details']['source']);
        self::assertSame([], $calls);
    }

    public function testRunnerOutageIsRecordedAndCanBeRetried(): void
    {
        $s = $this->scene();
        foreach (['p1', 'p2'] as $key) {
            $this->answer((int) $s[$key]['id'], $s['code'], ['files' => [['name' => 'Main.java', 'content' => 'class Main {}']]]);
        }
        foreach (['choice', 'matching', 'gaps'] as $key) {
            $this->db->run('DELETE FROM task_solutions WHERE task_id = ?', [$s[$key]]);
        }

        $down = new RunnerClient(['url' => 'http://runner:8080', 'token' => 't'], static function (): array {
            throw new \RuntimeException('Die Ausführungsumgebung ist nicht erreichbar.', 0);
        });
        $result = (new AutoGrader($this->db, $down))->step($s['exam'], 10);
        self::assertTrue($result['aborted']);
        self::assertStringContainsString('nicht erreichbar', (string) $result['message']);
        self::assertSame(1, $result['errors'], 'nach dem ersten Ausfall wird abgebrochen');

        $row = $this->db->fetchOne('SELECT * FROM auto_gradings WHERE task_id = ? AND suggested_points IS NULL', [$s['code']]);
        self::assertNotNull($row);
        self::assertSame('error', json_decode($row['details'], true)['source']);

        // Runner wieder da: Fehler löschen, erneut laufen lassen
        $calls = [];
        $up = new AutoGrader($this->db, $this->fakeRunner([['name' => 'Addition', 'ok' => true], ['name' => 'Subtraktion', 'ok' => true]], $calls));
        self::assertSame(1, $up->status($s['eid'])['errors']);
        $up->reset($s['eid'], 'errors');
        $done = $up->step($s['exam'], 10);
        self::assertFalse($done['aborted']);
        self::assertSame(0, $done['remaining']);
        self::assertSame(0, $up->status($s['eid'])['errors']);
        $good = $this->db->fetchAll('SELECT suggested_points FROM auto_gradings WHERE task_id = ? AND participation_id IN (?, ?)', [$s['code'], $s['p1']['id'], $s['p2']['id']]);
        self::assertSame([4.0, 4.0], array_map(static fn (array $r): float => (float) $r['suggested_points'], $good));
    }

    // ---------- Bewertung speichern und Rechte ----------

    public function testSavingPointsRequiresGradeCapability(): void
    {
        $s = $this->scene('grading');
        $aide = $this->makeUser('teacher', 'Aufsicht');
        $grader = $this->makeUser('teacher', 'Zweit');
        $stranger = $this->makeUser('teacher', 'Fremd');
        $this->db->run('INSERT INTO exam_staff (exam_id, user_id, can_view_solutions, can_grade) VALUES (?, ?, 1, 0)', [$s['eid'], $aide]);
        $this->db->run('INSERT INTO exam_staff (exam_id, user_id, can_view_solutions, can_grade) VALUES (?, ?, 0, 1)', [$s['eid'], $grader]);
        $post = ['participation_id' => $s['p1']['id'], 'task_id' => $s['choice'], 'points' => '3'];
        $params = ['id' => (string) $s['eid']];

        $this->expectStatus(403, fn () => $this->call(GradingController::class, $s['s1'], 'saveEntry', $params, $post));
        $this->expectStatus(403, fn () => $this->call(GradingController::class, $stranger, 'saveEntry', $params, $post));
        $this->expectStatus(403, fn () => $this->call(GradingController::class, $aide, 'saveEntry', $params, $post));
        $this->expectStatus(401, fn () => $this->call(GradingController::class, null, 'saveEntry', $params, $post));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM grading_entries'));

        $ok = $this->call(GradingController::class, $grader, 'saveEntry', $params, $post);
        self::assertTrue($ok['success']);
        self::assertSame(3.0, $ok['points']);
        $ok = $this->call(GradingController::class, $s['owner'], 'saveEntry', $params, $post);
        self::assertTrue($ok['success']);
        self::assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM grading_entries'), 'je Lehrkraft ein Eintrag');

        // Aufsicht darf auch lesend nicht in die Zelle
        $this->expectStatus(403, fn () => $this->call(GradingController::class, $aide, 'cell', $params + ['pid' => (string) $s['p1']['id'], 'tid' => (string) $s['choice']]));
        $this->expectStatus(403, fn () => $this->call(GradingController::class, $aide, 'overview', $params));
    }

    public function testSavingPointsValidatesInputAndBindsToExam(): void
    {
        $s = $this->scene('grading');
        $other = $this->scene('grading');
        $params = ['id' => (string) $s['eid']];
        $base = ['participation_id' => $s['p1']['id'], 'task_id' => $s['choice']];

        $this->expectStatus(422, fn () => $this->call(GradingController::class, $s['owner'], 'saveEntry', $params, $base + ['points' => '5']));
        $this->expectStatus(422, fn () => $this->call(GradingController::class, $s['owner'], 'saveEntry', $params, $base + ['points' => '1,3']));
        $this->expectStatus(422, fn () => $this->call(GradingController::class, $s['owner'], 'saveEntry', $params, $base + ['points' => '-1']));
        // Teilnahme oder Aufgabe einer anderen Klausur
        $this->expectStatus(404, fn () => $this->call(GradingController::class, $s['owner'], 'saveEntry', $params, ['participation_id' => $other['p1']['id'], 'task_id' => $s['choice'], 'points' => '1']));
        $this->expectStatus(404, fn () => $this->call(GradingController::class, $s['owner'], 'saveEntry', $params, ['participation_id' => $s['p1']['id'], 'task_id' => $other['choice'], 'points' => '1']));

        $r = $this->call(GradingController::class, $s['owner'], 'saveEntry', $params, $base + ['points' => '2,5']);
        self::assertSame(2.5, $r['points']);
        // Leeren nimmt die Bewertung zurück
        $r = $this->call(GradingController::class, $s['owner'], 'saveEntry', $params, $base + ['points' => '']);
        self::assertNull($r['points']);
        self::assertNull($r['final']['points']);
    }

    public function testGradingIsOnlyPossibleInGradingPhase(): void
    {
        $s = $this->scene('finished');
        $params = ['id' => (string) $s['eid']];
        $post = ['participation_id' => $s['p1']['id'], 'task_id' => $s['choice'], 'points' => '1'];
        $this->expectStatus(409, fn () => $this->call(GradingController::class, $s['owner'], 'saveEntry', $params, $post));
        $this->db->run("UPDATE exams SET status = 'returned' WHERE id = ?", [$s['eid']]);
        $this->expectStatus(409, fn () => $this->call(GradingController::class, $s['owner'], 'saveEntry', $params, $post));
        $this->expectStatus(409, fn () => $this->call(GradingController::class, $s['owner'], 'saveComment', $params, ['participation_id' => $s['p1']['id'], 'task_id' => $s['text'], 'anchor' => ['kind' => 'task'], 'body' => 'x']));
        $this->db->run("UPDATE exams SET status = 'running' WHERE id = ?", [$s['eid']]);
        $this->expectStatus(409, fn () => $this->call(GradingController::class, $s['owner'], 'saveEntry', $params, $post));
    }

    // ---------- Erst- und Zweitkorrektur ----------

    public function testOwnerDecidesAndSecondGraderSeesOthersOnlyOnRequest(): void
    {
        $s = $this->scene('grading');
        $second = $this->makeUser('teacher', 'Zweit');
        $this->db->run('INSERT INTO exam_staff (exam_id, user_id, can_view_solutions, can_grade) VALUES (?, ?, 1, 1)', [$s['eid'], $second]);
        $params = ['id' => (string) $s['eid']];
        $cellParams = $params + ['pid' => (string) $s['p1']['id'], 'tid' => (string) $s['choice']];
        $base = ['participation_id' => $s['p1']['id'], 'task_id' => $s['choice']];

        $this->call(GradingController::class, $second, 'saveEntry', $params, $base + ['points' => '2']);
        $this->call(GradingController::class, $s['owner'], 'saveEntry', $params, $base + ['points' => '3']);

        // Hauptlehrkraft sieht alles, ihr Wert zählt, die Abweichung wird gemeldet
        $own = $this->call(GradingController::class, $s['owner'], 'cell', $cellParams);
        self::assertSame(3.0, $own['final']['points']);
        self::assertSame('owner', $own['final']['source']);
        self::assertTrue($own['final']['differs']);
        self::assertCount(1, $own['others']);
        self::assertSame(2.0, $own['others'][0]['points']);

        // Zweitkorrektur sieht ohne Schalter nur die eigene Bewertung
        $blind = $this->call(GradingController::class, $second, 'cell', $cellParams);
        self::assertSame(2.0, $blind['own_points']);
        self::assertSame([], $blind['others']);
        self::assertFalse($blind['final']['differs']);
        $open = $this->call(GradingController::class, $second, 'cell', $cellParams, [], ['fremde' => '1']);
        self::assertCount(1, $open['others']);
        self::assertTrue($open['final']['differs']);

        // Summe verwendet den Endwert der Hauptlehrkraft
        $totals = $this->grading()->computeTotals($this->reloadExam($s['eid']), [(int) $s['p1']['id']]);
        self::assertSame(3.0, $totals[(int) $s['p1']['id']]['total']);

        // Matrix: Hauptlehrkraft sieht "abweichend"
        $matrix = $this->grading()->overview($this->reloadExam($s['eid']), $s['owner'], true, false);
        $cell = $matrix['rows'][0]['cells'][$s['choice']];
        self::assertSame('diff', $cell['status']);
        self::assertSame(1, $matrix['progress']['diff']);
        $blindMatrix = $this->grading()->overview($this->reloadExam($s['eid']), $second, false, false);
        self::assertSame('graded', $blindMatrix['rows'][0]['cells'][$s['choice']]['status']);
    }

    public function testOnlyEntryOfSecondGraderCountsWhenOwnerHasNone(): void
    {
        $s = $this->scene('grading');
        $second = $this->makeUser('teacher', 'Zweit');
        $this->db->run('INSERT INTO exam_staff (exam_id, user_id, can_view_solutions, can_grade) VALUES (?, ?, 1, 1)', [$s['eid'], $second]);
        $this->call(GradingController::class, $second, 'saveEntry', ['id' => (string) $s['eid']], ['participation_id' => $s['p1']['id'], 'task_id' => $s['choice'], 'points' => '4']);
        $totals = $this->grading()->computeTotals($this->reloadExam($s['eid']), [(int) $s['p1']['id']]);
        self::assertSame(4.0, $totals[(int) $s['p1']['id']]['total']);
    }

    // ---------- Matrix, Vorschläge ----------

    public function testMatrixShowsSuggestionsAndApplySuggestions(): void
    {
        $s = $this->scene('grading');
        $pid = (int) $s['p1']['id'];
        $this->answer($pid, $s['choice'], ['selected' => ['a']], true);
        $this->db->run('DELETE FROM task_solutions WHERE task_id = ?', [$s['code']]);
        (new AutoGrader($this->db))->step($this->reloadExam($s['eid']), 100);

        $exam = $this->reloadExam($s['eid']);
        $matrix = $this->grading()->overview($exam, $s['owner'], true, false);
        $row = array_values(array_filter($matrix['rows'], static fn (array $r): bool => $r['participation_id'] === $pid))[0];
        self::assertSame('suggested', $row['cells'][$s['choice']]['status']);
        self::assertSame(4.0, $row['cells'][$s['choice']]['points']);
        self::assertTrue($row['cells'][$s['choice']]['overtime']);
        self::assertSame('open', $row['cells'][$s['text']]['status']);
        self::assertSame(0.0, $row['sum']);
        self::assertGreaterThan(0.0, $row['sum_suggested']);
        self::assertFalse($matrix['rows'][2]['submitted'], 'Cleo hat nicht abgegeben');

        $applied = $this->grading()->applySuggestions($exam, $s['owner'], false);
        self::assertGreaterThan(0, $applied);
        $after = $this->grading()->overview($exam, $s['owner'], true, false);
        $row = array_values(array_filter($after['rows'], static fn (array $r): bool => $r['participation_id'] === $pid))[0];
        self::assertSame('graded', $row['cells'][$s['choice']]['status']);
        self::assertSame(4.0, $row['sum']);
        // zweiter Aufruf übernimmt nichts doppelt
        self::assertSame(0, $this->grading()->applySuggestions($exam, $s['owner'], false));
    }

    public function testAnonymousOverviewHidesNames(): void
    {
        $s = $this->scene('grading');
        $matrix = $this->grading()->overview($this->reloadExam($s['eid']), $s['owner'], true, true);
        foreach ($matrix['rows'] as $row) {
            self::assertMatchesRegularExpression('/^Nr\. \d+$/', $row['label']);
        }
        $html = (string) $this->call(GradingController::class, $s['owner'], 'overview', ['id' => (string) $s['eid']], [], ['anonym' => '1']);
        self::assertStringNotContainsString('Anna Test', $html);
        self::assertStringNotContainsString('Ben Test', $html);
        self::assertStringNotContainsString('Cleo Test', $html);
        self::assertStringContainsString('Nr. 1', $html);
        $named = (string) $this->call(GradingController::class, $s['owner'], 'overview', ['id' => (string) $s['eid']]);
        self::assertStringContainsString('Anna Test', $named);
    }

    // ---------- Kommentare ----------

    public function testCommentsAreOwnedByTheirGrader(): void
    {
        $s = $this->scene('grading');
        $second = $this->makeUser('teacher', 'Zweit');
        $this->db->run('INSERT INTO exam_staff (exam_id, user_id, can_view_solutions, can_grade) VALUES (?, ?, 1, 1)', [$s['eid'], $second]);
        $params = ['id' => (string) $s['eid']];
        $base = ['participation_id' => $s['p1']['id'], 'task_id' => $s['code']];

        $created = $this->call(GradingController::class, $second, 'saveComment', $params, $base + ['anchor' => ['kind' => 'code', 'file' => 'Main.java', 'from_line' => 2, 'to_line' => 3], 'body' => ' Null prüfen ']);
        self::assertSame('Null prüfen', $created['comment']['body']);
        self::assertSame(['kind' => 'code', 'file' => 'Main.java', 'from_line' => 2, 'to_line' => 3], $created['comment']['anchor']);
        $cid = $created['comment']['id'];

        // fremde Kommentare lassen sich nicht ändern
        $this->expectStatus(404, fn () => $this->call(GradingController::class, $s['owner'], 'saveComment', $params, $base + ['id' => $cid, 'anchor' => ['kind' => 'task'], 'body' => 'überschrieben']));
        $this->expectStatus(422, fn () => $this->call(GradingController::class, $second, 'saveComment', $params, $base + ['anchor' => ['kind' => 'code', 'file' => '../x', 'from_line' => 1, 'to_line' => 1], 'body' => 'x']));
        $this->expectStatus(422, fn () => $this->call(GradingController::class, $second, 'saveComment', $params, $base + ['anchor' => ['kind' => 'task'], 'body' => '   ']));

        $updated = $this->call(GradingController::class, $second, 'saveComment', $params, $base + ['id' => $cid, 'anchor' => ['kind' => 'task'], 'body' => 'neu']);
        self::assertSame('neu', $updated['comment']['body']);

        // Hauptlehrkraft sieht den Kommentar, Zweitkorrektur ohne Schalter nur eigene
        $cell = $this->call(GradingController::class, $s['owner'], 'cell', $params + ['pid' => (string) $s['p1']['id'], 'tid' => (string) $s['code']]);
        self::assertCount(1, $cell['comments']);
        self::assertFalse($cell['comments'][0]['mine']);
        $this->call(GradingController::class, $s['owner'], 'saveComment', $params, $base + ['anchor' => ['kind' => 'task'], 'body' => 'Meins']);
        $mine = $this->call(GradingController::class, $second, 'cell', $params + ['pid' => (string) $s['p1']['id'], 'tid' => (string) $s['code']]);
        self::assertCount(1, $mine['comments']);

        // Löschen: andere Lehrkraft nicht, Hauptlehrkraft ja
        $anotherStaff = $this->makeUser('teacher', 'Dritt');
        $this->db->run('INSERT INTO exam_staff (exam_id, user_id, can_view_solutions, can_grade) VALUES (?, ?, 1, 1)', [$s['eid'], $anotherStaff]);
        $this->expectStatus(404, fn () => $this->call(GradingController::class, $anotherStaff, 'deleteComment', $params + ['cid' => (string) $cid]));
        self::assertTrue($this->call(GradingController::class, $s['owner'], 'deleteComment', $params + ['cid' => (string) $cid])['success']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM grading_comments'));
    }

    // ---------- Einzelansicht und Schnittstellen ----------

    public function testShowPageBootContainsSolutionsOnlyForGraders(): void
    {
        $s = $this->scene('grading');
        $aide = $this->makeUser('teacher', 'Aufsicht');
        $this->db->run('INSERT INTO exam_staff (exam_id, user_id, can_view_solutions, can_grade) VALUES (?, ?, 1, 0)', [$s['eid'], $aide]);
        $params = ['id' => (string) $s['eid'], 'pid' => (string) $s['p1']['id']];

        $html = (string) $this->call(GradingController::class, $s['owner'], 'show', $params, [], ['aufgabe' => (string) $s['code'], 'modus' => 'aufgabe']);
        self::assertStringContainsString('id="grading-boot"', $html);
        self::assertStringContainsString('grading.js', $html);
        preg_match('#id="grading-boot">(.*?)</script>#s', $html, $m);
        $boot = json_decode($m[1], true);
        self::assertSame('task', $boot['mode']);
        self::assertSame($s['code'], $boot['current']['task_id']);
        self::assertFalse($boot['read_only']);
        self::assertTrue($boot['is_owner']);
        self::assertCount(3, $boot['participants']);
        $byId = array_column($boot['tasks'], null, 'id');
        self::assertStringContainsString(self::MODEL_SECRET, $byId[$s['code']]['model_html']);
        self::assertSame(2, $byId[$s['code']]['tests_count']);
        self::assertSame(['a'], $byId[$s['choice']]['solution']['correct']);
        self::assertStringContainsString(self::EXPECTATION, $byId[$s['choice']]['expectation_html']);
        // Die erwartete Ausgabe versteckter Tests steht nicht in der Seite
        self::assertStringNotContainsString(self::TEST_SECRET, $html);

        $this->expectStatus(403, fn () => $this->call(GradingController::class, $aide, 'show', $params));
        $this->expectStatus(403, fn () => $this->call(GradingController::class, $s['s1'], 'show', $params));
        $this->expectStatus(404, fn () => $this->call(GradingController::class, $s['owner'], 'show', ['id' => (string) $s['eid'], 'pid' => '999999']));

        $this->db->run("UPDATE exams SET status = 'returned' WHERE id = ?", [$s['eid']]);
        $readOnly = (string) $this->call(GradingController::class, $s['owner'], 'show', $params);
        preg_match('#id="grading-boot">(.*?)</script>#s', $readOnly, $m2);
        self::assertTrue(json_decode($m2[1], true)['read_only']);
    }

    public function testCellDeliversAnswerAutoResultAndFlags(): void
    {
        $s = $this->scene('grading');
        $pid = (int) $s['p1']['id'];
        $this->answer($pid, $s['code'], ['files' => [['name' => 'Main.java', 'content' => 'class Main {}']]], true);
        $this->db->run("INSERT INTO answer_ops (participation_id, task_id, seq, client_ts, kind, payload, is_paste) VALUES (?, ?, 1, 1, 'ops', '{}', 1), (?, ?, 2, 2, 'ops', '{}', 0)", [$pid, $s['code'], $pid, $s['code']]);
        $calls = [];
        $grader = new AutoGrader($this->db, $this->fakeRunner([['ok' => true], ['ok' => false]], $calls));
        $grader->retest($s['exam'], $pid, $s['code']);

        $cell = $this->call(GradingController::class, $s['owner'], 'cell', ['id' => (string) $s['eid'], 'pid' => (string) $pid, 'tid' => (string) $s['code']]);
        self::assertTrue($cell['answer']['has_overtime']);
        self::assertSame(1, $cell['pastes']);
        self::assertSame('Main.java', $cell['answer']['content']['files'][0]['name']);
        self::assertSame(1.0, $cell['auto']['suggested_points']);
        self::assertSame('tests', $cell['auto']['details']['source']);
        self::assertSame(4.0, $cell['max_points']);
        self::assertNull($cell['own_points']);
        $empty = $this->call(GradingController::class, $s['owner'], 'cell', ['id' => (string) $s['eid'], 'pid' => (string) $s['p2']['id'], 'tid' => (string) $s['code']]);
        self::assertNull($empty['answer']);
        self::assertNull($empty['auto']);
    }

    public function testAutoStepEndpointReportsProgress(): void
    {
        $s = $this->scene('finished');
        $this->db->run('DELETE FROM task_solutions WHERE task_id = ?', [$s['code']]);
        $params = ['id' => (string) $s['eid']];
        $first = $this->call(GradingController::class, $s['owner'], 'autoStep', $params, ['limit' => 4]);
        self::assertTrue($first['success']);
        self::assertSame(4, $first['processed']);
        self::assertSame(5, $first['remaining']);
        $second = $this->call(GradingController::class, $s['owner'], 'autoStep', $params, ['limit' => 25]);
        self::assertSame(5, $second['processed']);
        self::assertSame(0, $second['remaining']);
        $reset = $this->call(GradingController::class, $s['owner'], 'autoStep', $params, ['reset' => 'all', 'limit' => 25]);
        self::assertSame(9, $reset['processed']);

        $this->db->run("UPDATE exams SET status = 'returned' WHERE id = ?", [$s['eid']]);
        $refused = $this->call(GradingController::class, $s['owner'], 'autoStep', $params);
        self::assertFalse($refused['success']);
        self::assertSame(409, http_response_code());
        http_response_code(200);
        $this->expectStatus(403, fn () => $this->call(GradingController::class, $s['s1'], 'autoStep', $params));
    }

    public function testResultsPersonEndpointSavesCommentAndFinalizes(): void
    {
        $s = $this->scene('grading');
        $params = ['id' => (string) $s['eid'], 'pid' => (string) $s['p1']['id']];
        $r = $this->call(ResultsController::class, $s['owner'], 'person', $params, ['general_comment' => 'Weiter so', 'finalized' => true]);
        self::assertTrue($r['success']);
        self::assertTrue($r['finalized']);
        self::assertSame('Weiter so', $this->db->fetchValue('SELECT general_comment FROM participation_results WHERE participation_id = ?', [$s['p1']['id']]));
        $r = $this->call(ResultsController::class, $s['owner'], 'person', $params, ['finalized' => false]);
        self::assertFalse($r['finalized']);
        self::assertSame('Weiter so', $this->db->fetchValue('SELECT general_comment FROM participation_results WHERE participation_id = ?', [$s['p1']['id']]), 'Kommentar bleibt beim Öffnen des Abschlusses');

        $this->expectStatus(422, fn () => $this->call(ResultsController::class, $s['owner'], 'person', $params, ['general_comment' => str_repeat('x', 5001)]));
        $this->expectStatus(403, fn () => $this->call(ResultsController::class, $s['s1'], 'person', $params, ['finalized' => true]));
        $this->expectStatus(404, fn () => $this->call(ResultsController::class, $s['owner'], 'person', ['id' => (string) $s['eid'], 'pid' => '999999'], ['finalized' => true]));
        $this->db->run("UPDATE exams SET status = 'returned' WHERE id = ?", [$s['eid']]);
        $this->expectStatus(409, fn () => $this->call(ResultsController::class, $s['owner'], 'person', $params, ['finalized' => true]));
    }

    // ---------- Statusfluss und Ergebnisse ----------

    public function testStatusFlowFromFinishedToArchived(): void
    {
        $s = $this->scene('finished');
        $svc = $this->grading();
        $exam = $this->reloadExam($s['eid']);

        $this->expectStatus(409, fn () => $svc->release($exam));
        $svc->begin($exam);
        self::assertSame('grading', $this->reloadExam($s['eid'])['status']);
        $this->expectStatus(409, fn () => $svc->begin($this->reloadExam($s['eid'])));

        $svc->saveEntry($this->reloadExam($s['eid']), (int) $s['p1']['id'], $s['choice'], $s['owner'], 4);
        $r = $svc->release($this->reloadExam($s['eid']));
        self::assertSame(0, $r['finalized']);
        self::assertSame(3, $r['open']);
        $returned = $this->reloadExam($s['eid']);
        self::assertSame('returned', $returned['status']);
        self::assertNotNull($returned['returned_at']);

        $this->expectStatus(409, fn () => $svc->saveEntry($returned, (int) $s['p1']['id'], $s['choice'], $s['owner'], 2));
        $this->expectStatus(409, fn () => $svc->begin($returned));

        $svc->withdraw($returned);
        $back = $this->reloadExam($s['eid']);
        self::assertSame('grading', $back['status']);
        self::assertNull($back['returned_at']);
        $this->expectStatus(409, fn () => $svc->archive($back));

        $svc->release($back);
        $svc->archive($this->reloadExam($s['eid']));
        self::assertSame('archived', $this->reloadExam($s['eid'])['status']);
    }

    public function testOnlyOwnerMayReleaseAndWithdraw(): void
    {
        $s = $this->scene('grading');
        $grader = $this->makeUser('teacher', 'Zweit');
        $this->db->run('INSERT INTO exam_staff (exam_id, user_id, can_view_solutions, can_grade) VALUES (?, ?, 1, 1)', [$s['eid'], $grader]);
        $params = ['id' => (string) $s['eid']];
        foreach (['release', 'withdraw', 'archive', 'saveSettings'] as $action) {
            $this->expectStatus(403, fn () => $this->call(ResultsController::class, $grader, $action, $params));
            $this->expectStatus(403, fn () => $this->call(ResultsController::class, $s['s1'], $action, $params));
        }
        $this->expectStatus(403, fn () => $this->call(ResultsController::class, $s['s1'], 'index', $params));
        // Zweitkorrektur darf die Ergebnisseite sehen
        $html = (string) $this->call(ResultsController::class, $grader, 'index', $params);
        self::assertStringContainsString('Ergebnisse je Person', $html);
        self::assertStringContainsString('Klausurspiegel', $html);
    }

    public function testTotalsGradeAndFinalizing(): void
    {
        $s = $this->scene('grading');
        $this->db->run("INSERT INTO grading_scales (name, type, thresholds) VALUES ('Testschlüssel', 'grades', ?)", [json_encode([
            ['label' => '1', 'value' => 1, 'min_percent' => 80],
            ['label' => '2', 'value' => 2, 'min_percent' => 50],
            ['label' => '3', 'value' => 3, 'min_percent' => 0],
        ])]);
        $scaleId = $this->db->lastInsertId();
        $svc = $this->grading();
        $exam = $svc->changeScale($this->reloadExam($s['eid']), $scaleId);
        self::assertSame($scaleId, (int) $exam['grading_scale_id']);

        // Maximalpunkte: 4 Aufgaben mit Antwort je 4 P. (+ Code 4 P.) = 20
        $pid = (int) $s['p1']['id'];
        foreach ([[$s['choice'], 4], [$s['matching'], 4], [$s['gaps'], 4], [$s['text'], 2], [$s['code'], 2]] as [$tid, $pts]) {
            $svc->saveEntry($exam, $pid, $tid, $s['owner'], $pts);
        }
        $t = $svc->computeTotals($exam, [$pid])[$pid];
        self::assertSame(16.0, $t['total']);
        self::assertSame(20.0, $t['max']);
        self::assertSame(80.0, $t['percent']);
        self::assertSame('1', $t['grade_label']);
        self::assertSame(5, $t['graded']);

        $stored = $this->db->fetchOne('SELECT * FROM participation_results WHERE participation_id = ?', [$pid]);
        self::assertSame('1', $stored['grade_label']);
        self::assertNull($stored['finalized_at']);

        $svc->saveGeneralComment($exam, $pid, "Gut gearbeitet.\nWeiter so");
        $svc->setFinalized($exam, $pid, true, $s['owner']);
        $row = $this->db->fetchOne('SELECT * FROM participation_results WHERE participation_id = ?', [$pid]);
        self::assertNotNull($row['finalized_at']);
        self::assertSame("Gut gearbeitet.\nWeiter so", $row['general_comment']);

        // Ändern der Punkte setzt den Abschluss zurück
        $r = $svc->saveEntry($exam, $pid, $s['text'], $s['owner'], 0);
        self::assertTrue($r['unfinalized']);
        self::assertNull($this->db->fetchValue('SELECT finalized_at FROM participation_results WHERE participation_id = ?', [$pid]));
        self::assertSame(14.0, $r['totals']['total']);
        self::assertSame('2', $r['totals']['grade_label']);

        // Alle vollständig bewerteten abschließen: nur Anna ist vollständig
        $done = $svc->finalizeComplete($exam, $s['owner']);
        self::assertSame(1, $done['finalized']);
        self::assertSame(2, $done['skipped']);

        // Ergebniszeilen
        $rows = $svc->results($exam);
        self::assertCount(3, $rows);
        $anna = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['id'] === (int) $s['p1']['id']))[0];
        self::assertSame('2', $anna['grade_label']);
        self::assertNotNull($anna['finalized_at']);
    }

    public function testStatisticsFromExam(): void
    {
        $s = $this->scene('grading');
        $this->db->run("INSERT INTO grading_scales (name, type, thresholds) VALUES ('S', 'grades', ?)", [json_encode([
            ['label' => '1', 'value' => 1, 'min_percent' => 50],
            ['label' => '2', 'value' => 2, 'min_percent' => 0],
        ])]);
        $scaleId = $this->db->lastInsertId();
        $svc = $this->grading();
        $exam = $svc->changeScale($this->reloadExam($s['eid']), $scaleId);
        $svc->saveEntry($exam, (int) $s['p1']['id'], $s['choice'], $s['owner'], 4);
        $svc->saveEntry($exam, (int) $s['p2']['id'], $s['choice'], $s['owner'], 0);

        $stats = ExamStatistics::forExam($svc, $exam);
        self::assertSame(2, $stats['n'], 'nur abgegebene oder bewertete Arbeiten (Cleo hat nicht abgegeben)');
        self::assertSame('grades', $stats['scale_type']);
        self::assertSame(2.0, $stats['tasks'][0]['average']);
        self::assertSame(2, $stats['tasks'][0]['n']);
        self::assertSame(0, $stats['distribution'][1]);
        self::assertSame(2, $stats['distribution'][2], 'Anna 20 %, Ben 0 %: beide liegen unter 50 %');
    }

    // ---------- Rückgabe an Schüler:innen ----------

    /** Klausur mit Antworten und Bewertung für Anna, freigegeben wie angegeben. @return array<string, mixed> */
    private function returnedScene(bool $finalize = true, bool $release = true, bool $showExpectation = false): array
    {
        $s = $this->scene('grading');
        $pid = (int) $s['p1']['id'];
        $this->answer($pid, $s['choice'], ['selected' => ['a']]);
        $this->answer($pid, $s['text'], ['text' => 'Eine Warteschlange arbeitet nach FIFO.']);
        $this->answer($pid, $s['code'], ['files' => [['name' => 'Main.java', 'content' => "class Main {\n    int x = 1;\n}\n"]]]);
        $svc = $this->grading();
        $exam = $this->reloadExam($s['eid']);
        $svc->saveEntry($exam, $pid, $s['choice'], $s['owner'], 4);
        $svc->saveEntry($exam, $pid, $s['text'], $s['owner'], 1.5);
        $svc->saveEntry($exam, $pid, $s['code'], $s['owner'], 3);
        $this->call(GradingController::class, $s['owner'], 'saveComment', ['id' => (string) $s['eid']], [
            'participation_id' => $pid, 'task_id' => $s['code'], 'anchor' => ['kind' => 'code', 'file' => 'Main.java', 'from_line' => 2, 'to_line' => 2], 'body' => 'Name des Feldes besser wählen',
        ]);
        // Kommentar einer Zweitkorrektur ohne Endwert-Bezug bleibt unsichtbar
        $second = $this->makeUser('teacher', 'Zweit');
        $this->db->run('INSERT INTO exam_staff (exam_id, user_id, can_view_solutions, can_grade) VALUES (?, ?, 1, 1)', [$s['eid'], $second]);
        $this->call(GradingController::class, $second, 'saveComment', ['id' => (string) $s['eid']], [
            'participation_id' => $pid, 'task_id' => $s['code'], 'anchor' => ['kind' => 'task'], 'body' => 'INTERNE-NOTIZ-ZWEITKORREKTUR',
        ]);
        $svc->saveGeneralComment($exam, $pid, 'Solide Leistung.');
        if ($finalize) {
            $svc->setFinalized($exam, $pid, true, $s['owner']);
        }
        if ($showExpectation) {
            $this->db->run('UPDATE exams SET show_expectation_on_return = 1 WHERE id = ?', [$s['eid']]);
        }
        if ($release) {
            $svc->release($this->reloadExam($s['eid']));
        }

        return $s;
    }

    public function testStudentSeesResultOnlyAfterReleaseAndOwnFinalization(): void
    {
        $s = $this->returnedScene(finalize: true, release: false);
        $params = ['id' => (string) $s['eid']];
        // noch nicht freigegeben
        $this->expectStatus(403, fn () => $this->call(StudentResultsController::class, $s['s1'], 'show', $params));
        $list = (string) $this->call(StudentResultsController::class, $s['s1'], 'index');
        self::assertStringNotContainsString('Testklausur', $list);

        $this->grading()->release($this->reloadExam($s['eid']));
        $html = (string) $this->call(StudentResultsController::class, $s['s1'], 'show', $params);
        self::assertStringContainsString('Solide Leistung.', $html);
        self::assertStringContainsString('review-boot', $html);
        $list = (string) $this->call(StudentResultsController::class, $s['s1'], 'index');
        self::assertStringContainsString('Testklausur', $list);

        // Ben wurde nicht abgeschlossen: sieht nichts, obwohl freigegeben
        $this->expectStatus(403, fn () => $this->call(StudentResultsController::class, $s['s2'], 'show', $params));
        $benList = (string) $this->call(StudentResultsController::class, $s['s2'], 'index');
        self::assertStringContainsString('noch nicht abgeschlossen', $benList);

        // Fremde Schüler:in ohne Teilnahme und Lehrkräfte
        $outsider = $this->makeUser('student', 'Dora');
        $this->expectStatus(404, fn () => $this->call(StudentResultsController::class, $outsider, 'show', $params));
        $this->expectStatus(403, fn () => $this->call(StudentResultsController::class, $s['owner'], 'show', $params));
        $this->expectStatus(401, fn () => $this->call(StudentResultsController::class, null, 'show', $params));

        // Zurücknehmen verbirgt das Ergebnis wieder
        $this->grading()->withdraw($this->reloadExam($s['eid']));
        $this->expectStatus(403, fn () => $this->call(StudentResultsController::class, $s['s1'], 'show', $params));
    }

    public function testStudentNeverGetsSolutionsAndSeesExpectationOnlyWhenReleased(): void
    {
        $s = $this->returnedScene();
        $params = ['id' => (string) $s['eid']];
        $html = (string) $this->call(StudentResultsController::class, $s['s1'], 'show', $params);

        foreach ([self::MODEL_SECRET, self::TEST_SECRET, self::EXPECTATION, 'INTERNE-NOTIZ-ZWEITKORREKTUR', 'Code-Erwartung'] as $secret) {
            self::assertStringNotContainsString($secret, $html, 'Dem Schüler darf nichts davon angezeigt werden: ' . $secret);
        }
        self::assertStringContainsString('Name des Feldes besser wählen', $html, 'Kommentare der zählenden Lehrkraft sind sichtbar');
        preg_match('#id="review-boot">(.*?)</script>#s', $html, $m);
        $boot = json_decode($m[1], true);
        self::assertIsArray($boot);
        $json = json_encode($boot);
        self::assertStringNotContainsString('correct', (string) $json);
        self::assertStringNotContainsString('solution', (string) $json);
        self::assertStringNotContainsString('tests', (string) $json);
        self::assertFalse($boot['show_expectation']);
        $byId = array_column($boot['tasks'], null, 'id');
        self::assertSame(4.0, (float) $byId[$s['choice']]['earned']);
        self::assertSame(['selected' => ['a']], $byId[$s['choice']]['answer']);
        self::assertNull($byId[$s['gaps']]['answer']);
        self::assertSame(3.0, (float) $byId[$s['code']]['earned']);
        self::assertCount(1, $byId[$s['code']]['comments']);
        self::assertSame('', $byId[$s['choice']]['expectation_html']);

        // Mit Freigabe des Erwartungshorizonts erscheint nur dieser
        $this->db->run('UPDATE exams SET show_expectation_on_return = 1 WHERE id = ?', [$s['eid']]);
        $shown = (string) $this->call(StudentResultsController::class, $s['s1'], 'show', $params);
        self::assertStringContainsString(self::EXPECTATION, $shown);
        // Der Erwartungshorizont der Code-Aufgabe gehört dazu, Musterlösung, Tests und interne Notizen nicht
        self::assertStringContainsString('Code-Erwartung', $shown);
        foreach ([self::MODEL_SECRET, self::TEST_SECRET, 'INTERNE-NOTIZ-ZWEITKORREKTUR'] as $secret) {
            self::assertStringNotContainsString($secret, $shown);
        }
    }

    // ---------- PDF ----------

    public function testResultPdfIsGeneratedForOneAndAllPersons(): void
    {
        $s = $this->returnedScene();
        $report = new ResultReport($this->db, $this->grading(), '');
        $exam = $this->reloadExam($s['eid']);

        $one = $report->renderPdf($exam, [$this->reloadParticipation((int) $s['p1']['id'])], 'Testschule');
        self::assertStringStartsWith('%PDF', $one);

        $people = $this->db->fetchAll('SELECT * FROM participations WHERE exam_id = ? ORDER BY id', [$s['eid']]);
        $all = $report->renderPdf($exam, $people, 'Testschule');
        self::assertStringStartsWith('%PDF', $all);
        preg_match_all('#/Type /Page\b#', $one, $a);
        preg_match_all('#/Type /Page\b#', $all, $b);
        self::assertGreaterThan(count($a[0]), count($b[0]), 'Sammel-PDF: je Person eine neue Seite');

        $data = $report->build($exam, $this->reloadParticipation((int) $s['p1']['id']), false, true);
        $codeTask = array_values(array_filter($data['tasks'], static fn (array $t): bool => $t['id'] === $s['code']))[0];
        self::assertSame(3.0, $codeTask['earned']);
        self::assertCount(1, $codeTask['comments'], 'Im Bericht stehen die Kommentare der Lehrkraft, deren Punkte zählen');

        $forStudent = $report->build($exam, $this->reloadParticipation((int) $s['p1']['id']), true);
        $codeStudent = array_values(array_filter($forStudent['tasks'], static fn (array $t): bool => $t['id'] === $s['code']))[0];
        self::assertCount(1, $codeStudent['comments']);
        self::assertSame(8.5, $forStudent['summary']['total']);
        self::assertSame(20.0, $forStudent['summary']['max']);
    }

    public function testPdfRequiresGradeCapabilityAndAvailablePhase(): void
    {
        $s = $this->scene('finished');
        $aide = $this->makeUser('teacher', 'Aufsicht');
        $this->db->run('INSERT INTO exam_staff (exam_id, user_id, can_view_solutions, can_grade) VALUES (?, ?, 0, 0)', [$s['eid'], $aide]);
        $params = ['id' => (string) $s['eid']];
        $this->expectStatus(403, fn () => $this->call(\App\Controllers\ResultPdfController::class, $aide, 'all', $params));
        $this->expectStatus(403, fn () => $this->call(\App\Controllers\ResultPdfController::class, $s['s1'], 'all', $params));
        $this->expectStatus(409, fn () => $this->call(\App\Controllers\ResultPdfController::class, $s['owner'], 'all', $params));
        $this->expectStatus(403, fn () => $this->call(StudentResultsController::class, $s['s1'], 'pdf', $params));
    }
}
