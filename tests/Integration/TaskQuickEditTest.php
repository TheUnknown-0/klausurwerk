<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Services\AnswerFiles;
use App\Services\ExamService;
use App\Services\Materials;
use App\Services\TaskService;
use Tests\Support\DatabaseTestCase;

/** Fragenliste: Frage darunter einfügen und Duplizieren. */
final class TaskQuickEditTest extends DatabaseTestCase
{
    private TaskService $tasks;
    private ExamService $exams;
    private string $uploadDir;
    private int $examId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploadDir = sys_get_temp_dir() . '/kw-test-' . bin2hex(random_bytes(6));
        mkdir($this->uploadDir, 0775, true);
        $materials = new Materials($this->db, $this->uploadDir);
        $this->tasks = new TaskService($this->db, $materials);
        $this->exams = new ExamService($this->db, $this->tasks, $materials, new AnswerFiles($this->db, $this->uploadDir));
        $this->db->run("INSERT INTO users (username, firstname, lastname, role) VALUES (?, 'Lena', 'Lehrer', 'teacher')", ['lehrer' . bin2hex(random_bytes(3))]);
        $this->examId = $this->exams->create(['title' => 'Q1', 'mode' => 'exam', 'duration_minutes' => 90], $this->db->lastInsertId());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @rmdir($this->uploadDir);
    }

    /** @return array<string, mixed> */
    private function exam(): array
    {
        return $this->db->fetchOne('SELECT * FROM exams WHERE id = ?', [$this->examId]);
    }

    /** @return list<string> Titel in Anzeigereihenfolge */
    private function titles(): array
    {
        return array_map(static fn (array $t): string => $t['number'] . ' ' . $t['title'], $this->tasks->fullList($this->examId));
    }

    public function testFrageDarunterLandetDirektHinterDerGewaehlten(): void
    {
        $a = $this->tasks->create($this->exam(), null, 'text', 'A');
        $this->tasks->create($this->exam(), null, 'text', 'B');
        $c = $this->tasks->create($this->exam(), null, 'choice', 'C');
        $this->tasks->placeAfter($this->exam(), $c, $a);
        self::assertSame(['1 A', '2 C', '3 B'], $this->titles());
    }

    public function testVerschiedeneEbenenBleibenUnveraendert(): void
    {
        $head = $this->tasks->create($this->exam(), null, null, 'Kopf');
        $sub = $this->tasks->create($this->exam(), $head, 'text', 'Teil');
        $top = $this->tasks->create($this->exam(), null, 'text', 'Oben');
        $this->tasks->placeAfter($this->exam(), $top, $sub);
        self::assertSame(['1 Kopf', '1a Teil', '2 Oben'], $this->titles());
    }

    public function testDuplizierenKopiertLoesungUndSetztDahinter(): void
    {
        $a = $this->tasks->create($this->exam(), null, 'choice', 'Datentypen');
        $this->tasks->save($this->exam(), $a, [
            'title' => 'Datentypen', 'body_md' => 'Welcher?', 'points' => 2, 'answer_type' => 'choice',
            'config' => ['multiple' => false, 'options' => [['id' => 'a', 'text_md' => 'int'], ['id' => 'b', 'text_md' => 'String']]],
            'solution' => ['correct' => ['b']], 'model_answer' => '', 'expectation_md' => 'b', 'tests' => [], 'files' => [],
        ]);
        $this->tasks->create($this->exam(), null, 'text', 'Danach');

        $copy = $this->tasks->duplicate($this->exam(), $a);
        self::assertSame(['1 Datentypen', '2 Datentypen (Kopie)', '3 Danach'], $this->titles());
        $row = $this->tasks->find($this->examId, $copy);
        self::assertSame(['b'], $row['solution']['correct']);
        self::assertSame('b', $row['expectation_md']);
        self::assertSame(2.0, $row['points']);
    }

    public function testDuplizierenEinerUeberschriftNimmtTeilaufgabenMit(): void
    {
        $head = $this->tasks->create($this->exam(), null, null, 'Kopf');
        $this->tasks->create($this->exam(), $head, 'text', 'Teil');
        $this->tasks->duplicate($this->exam(), $head);
        self::assertSame(['1 Kopf', '1a Teil', '2 Kopf (Kopie)', '2a Teil'], $this->titles());
    }

    public function testNachDemStartGesperrt(): void
    {
        $a = $this->tasks->create($this->exam(), null, 'text', 'A');
        $this->db->run("UPDATE exams SET status = 'running' WHERE id = ?", [$this->examId]);
        $this->expectException(HttpException::class);
        $this->tasks->duplicate($this->exam(), $a);
    }
}
