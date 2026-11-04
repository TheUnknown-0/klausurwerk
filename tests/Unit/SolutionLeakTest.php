<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Statische Prüfung: Dateien, die Schüler:innen bedienen, erwähnen die Lösungstabelle nie
 * (ARCHITECTURE.md, Sicherheits-Pflicht 5). Geprüft wird der gesamte Quelltext inklusive Kommentare.
 */
final class SolutionLeakTest extends TestCase
{
    private const FORBIDDEN = 'task_solutions';

    /** @return iterable<string, array{string}> */
    public static function studentFacingFiles(): iterable
    {
        $root = dirname(__DIR__, 2) . '/src/';
        foreach ([
            'Controllers/StudentExamController.php',
            'Controllers/ExamPreviewController.php',
            'Controllers/MaterialController.php',
            'Controllers/RunController.php',
            'Controllers/SebEndController.php',
            'Services/ExamContent.php',
            'Services/AnswerStore.php',
            'Services/ParticipationState.php',
            'Services/DeviceBinding.php',
            'Services/SebGuard.php',
            'Services/RunLimiter.php',
            'Services/EventLog.php',
            // Rückgabe (Stufe 2): Bericht und PDF für Schüler:innen lesen nie Lösungsdaten.
            'Services/ResultReport.php',
            'Services/ResultPdf.php',
        ] as $file) {
            yield $file => [$root . $file];
        }
    }

    #[DataProvider('studentFacingFiles')]
    public function testFileDoesNotMentionSolutionTable(string $path): void
    {
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringNotContainsString(self::FORBIDDEN, $source, basename($path) . ' darf die Lösungstabelle nicht erwähnen.');
    }

    public function testStudentTemplatesAndBundlesDoNotMentionSolutionTable(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['/templates/pages/student', '/templates/pages/student-results', '/templates/pages/pult'] as $dir) {
            foreach (glob($root . $dir . '/*.php') ?: [] as $file) {
                self::assertStringNotContainsString(self::FORBIDDEN, (string) file_get_contents($file), basename($file));
            }
        }
    }

    /**
     * Die Rückgabe an Schüler:innen darf aus der Lösungstabelle ausschließlich den Erwartungshorizont lesen,
     * und nur nach Freigabe durch die Lehrkraft. Musterlösung, Tests und richtige Antworten sind tabu.
     */
    public function testStudentResultsReadOnlyTheReleasedExpectation(): void
    {
        $path = dirname(__DIR__, 2) . '/src/Controllers/StudentResultsController.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        // Jede Erwähnung der Tabelle steht in einer SELECT-Anweisung, die nur task_id und expectation_md liest.
        $mentions = substr_count($source, self::FORBIDDEN);
        self::assertSame(1, $mentions, 'Die Lösungstabelle darf genau einmal vorkommen (Erwartungshorizont).');
        self::assertMatchesRegularExpression(
            '/SELECT\s+s\.task_id,\s*s\.expectation_md\s+FROM\s+task_solutions\s+s\s+JOIN\s+tasks\s+t\s+ON\s+t\.id\s*=\s*s\.task_id\s+WHERE\s+t\.exam_id\s*=\s*\?/',
            $source,
        );

        // Die Abfrage läuft nur hinter dem Freigabe-Schalter der Klausur.
        self::assertMatchesRegularExpression('/show_expectation_on_return[^;]*\?\s*\$this->expectations\(/', $source);

        // Alles andere aus der Lösungstabelle und den Aufgabendiensten, die sie laden, kommt nicht vor.
        foreach (['model_answer', 's.solution', 's.tests', 'solution_json', 'tests_json', "'correct'", '"correct"', 'fullList', 'TaskService', 'autoCheck'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source, 'Verbotener Zugriff in StudentResultsController: ' . $forbidden);
        }
    }

    public function testGradingBundleForStudentsDoesNotMentionSolutions(): void
    {
        $root = dirname(__DIR__, 2) . '/frontend/src/grading/review.js';
        self::assertFileExists($root);
        $source = (string) file_get_contents($root);
        foreach (['task_solutions', 'model_html', 'model_answer', 'solutionView', 'solution-view'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }
}
