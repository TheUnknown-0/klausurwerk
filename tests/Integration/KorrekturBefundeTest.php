<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\AutoGrader;
use App\Services\ExamService;
use App\Services\Materials;
use App\Services\TaskService;
use App\Services\TaskTypes\OrderingType;
use App\Services\TaskValidationException;
use Tests\Support\DatabaseTestCase;

/** Regressionstests: Aufgabenpunkte in 0,5-Schritten und Startreihenfolge bei Reihenfolge-Aufgaben. */
final class KorrekturBefundeTest extends DatabaseTestCase
{
    /** @return array<string, mixed> */
    private function input(string $points): array
    {
        return [
            'title' => 'MC', 'body_md' => 'x', 'points' => $points, 'answer_type' => 'choice',
            'config' => ['multiple' => false, 'options' => [['id' => 'a', 'text_md' => 'A'], ['id' => 'b', 'text_md' => 'B']], 'shuffle' => true],
            'solution' => ['correct' => ['b']], 'model_answer' => 'B', 'expectation_md' => 'B',
        ];
    }

    public function testAufgabenpunkteNurInHalbenSchritten(): void
    {
        $dir = sys_get_temp_dir();
        $tasks = new TaskService($this->db, new Materials($this->db, $dir));
        $exams = new ExamService($this->db, $tasks, new Materials($this->db, $dir));
        $this->db->run("INSERT INTO users (username, firstname, lastname, role) VALUES (?, 'Lena', 'Lehrer', 'teacher')", ['lehrer' . bin2hex(random_bytes(3))]);
        $teacher = $this->db->lastInsertId();
        $id = $exams->create(['title' => 'K', 'mode' => 'exam', 'duration_minutes' => '90'], $teacher);
        $exam = $this->db->fetchOne('SELECT * FROM exams WHERE id = ?', [$id]);
        $exam['settings_array'] = json_decode((string) $exam['settings'], true);
        $taskId = $tasks->create($exam, null, 'choice');

        foreach (['2.25', '0.3', '1,75'] as $bad) {
            try {
                $tasks->save($exam, $taskId, $this->input($bad));
                self::fail("{$bad} hätte abgelehnt werden müssen");
            } catch (TaskValidationException) {
                self::assertTrue(true);
            }
        }
        foreach (['2.5', '3', '0', '1,5'] as $good) {
            $tasks->save($exam, $taskId, $this->input($good));
            self::assertSame((float) str_replace(',', '.', $good), (float) $this->db->fetchValue('SELECT points FROM tasks WHERE id = ?', [$taskId]));
        }
    }

    public function testFehlendeReihenfolgeWirdMitGemischterStartreihenfolgeBewertet(): void
    {
        $grader = new AutoGrader($this->db);
        $config = ['items' => [['id' => 'a', 'text_md' => 'A'], ['id' => 'b', 'text_md' => 'B'], ['id' => 'c', 'text_md' => 'C']]];
        $participation = ['id' => 1, 'shuffle_seed' => 424242];
        $task = ['id' => 17, 'points' => 2.0, 'answer_type' => 'ordering', 'config' => $config, 'solution' => [], 'tests' => []];

        $seed = (424242 + 17) & 0x7FFFFFFF;
        $start = array_column((new OrderingType())->studentConfig($config, $seed)['items'], 'id');

        // Lösung = angezeigte Startreihenfolge: unberührt abgegeben zählt voll
        $task['solution'] = ['order' => $start, 'scoring' => 'all_or_nothing'];
        self::assertSame(2.0, $grader->gradePair([], $task, $participation, null)['suggested']);
        self::assertSame(2.0, $grader->gradePair([], $task, $participation, ['order' => []])['suggested']);

        // Lösung weicht ab: 0 Punkte, aber als geprüfte Antwort (nicht "empty")
        $task['solution'] = ['order' => array_reverse($start), 'scoring' => 'all_or_nothing'];
        $r = $grader->gradePair([], $task, $participation, null);
        self::assertSame(0.0, $r['suggested']);
        self::assertSame('ordering', $r['details']['source']);

        // Andere Aufgabentypen bleiben "keine Antwort"
        $choice = ['id' => 18, 'points' => 1.0, 'answer_type' => 'choice', 'config' => [], 'solution' => [], 'tests' => []];
        self::assertSame('empty', $grader->gradePair([], $choice, $participation, null)['details']['source']);
    }
}
