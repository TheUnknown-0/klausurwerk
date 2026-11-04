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
        foreach (['/templates/pages/student', '/templates/pages/pult'] as $dir) {
            foreach (glob($root . $dir . '/*.php') ?: [] as $file) {
                self::assertStringNotContainsString(self::FORBIDDEN, (string) file_get_contents($file), basename($file));
            }
        }
    }
}
