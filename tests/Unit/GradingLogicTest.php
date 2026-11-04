<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AutoGrader;
use App\Services\ExamStatistics;
use App\Services\GradingService;
use App\Services\ResultPdf;
use PHPUnit\Framework\TestCase;

/** Rechenlogik der Korrektur ohne Datenbank: Punkte, Rundung, Endwert, Testpunkte, Anker, Statistik. */
final class GradingLogicTest extends TestCase
{
    // ---------- Rundung und Eingabe ----------

    public function testRoundHalf(): void
    {
        self::assertSame(2.5, GradingService::roundHalf(2.4));
        self::assertSame(2.5, GradingService::roundHalf(2.26));
        self::assertSame(2.0, GradingService::roundHalf(2.2));
        self::assertSame(3.0, GradingService::roundHalf(2.75));
        self::assertSame(0.0, GradingService::roundHalf(0.2));
        self::assertSame(1.0, GradingService::roundHalf(0.75));
    }

    public function testSuggestedFromRatioIsRoundedAndBounded(): void
    {
        self::assertSame(2.0, AutoGrader::suggestedFromRatio(4.0, 0.5));
        self::assertSame(1.5, AutoGrader::suggestedFromRatio(5.0, 0.3));   // 1,5
        self::assertSame(5.0, AutoGrader::suggestedFromRatio(5.0, 1.7));   // Verhältnis über 1 wird gedeckelt
        self::assertSame(0.0, AutoGrader::suggestedFromRatio(5.0, -0.4));
        self::assertSame(1.5, AutoGrader::suggestedFromRatio(3.0, 1 / 3 + 0.2)); // 1,6 -> 1,5
    }

    public function testNormalizePoints(): void
    {
        self::assertNull(GradingService::normalizePoints('', 4.0));
        self::assertNull(GradingService::normalizePoints(null, 4.0));
        self::assertSame(2.5, GradingService::normalizePoints('2,5', 4.0));
        self::assertSame(4.0, GradingService::normalizePoints(4, 4.0));
        self::assertSame(0.0, GradingService::normalizePoints('0', 4.0));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPoints(): iterable
    {
        yield 'zu viel' => ['4,5'];
        yield 'negativ' => ['-1'];
        yield 'kein Halbschritt' => ['1,3'];
        yield 'Text' => ['abc'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPoints')]
    public function testNormalizePointsRejects(mixed $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GradingService::normalizePoints($raw, 4.0);
    }

    // ---------- Endwert bei Erst- und Zweitkorrektur ----------

    public function testFinalEntryWithoutPoints(): void
    {
        $r = GradingService::finalEntry([['grader_id' => 1, 'points' => null]], 1);
        self::assertNull($r['points']);
        self::assertFalse($r['differs']);
        self::assertSame(0, $r['count']);
    }

    public function testFinalEntryPrefersOwner(): void
    {
        $r = GradingService::finalEntry([
            ['grader_id' => 2, 'points' => '3.00', 'updated_at' => '2026-01-02 10:00:00'],
            ['grader_id' => 1, 'points' => '2.50', 'updated_at' => '2026-01-01 10:00:00'],
        ], 1);
        self::assertSame(2.5, $r['points']);
        self::assertSame(1, $r['grader_id']);
        self::assertSame('owner', $r['source']);
        self::assertTrue($r['differs']);
    }

    public function testFinalEntrySingleEntryCountsWithoutOwner(): void
    {
        $r = GradingService::finalEntry([['grader_id' => 7, 'points' => 3]], 1);
        self::assertSame(3.0, $r['points']);
        self::assertSame('single', $r['source']);
        self::assertFalse($r['differs']);
    }

    public function testFinalEntryEqualValuesDoNotDiffer(): void
    {
        $r = GradingService::finalEntry([
            ['grader_id' => 1, 'points' => 2],
            ['grader_id' => 2, 'points' => 2.0],
        ], 1);
        self::assertFalse($r['differs']);
    }

    public function testFinalEntryTakesLatestWhenOwnerHasNoEntry(): void
    {
        $r = GradingService::finalEntry([
            ['grader_id' => 2, 'points' => 1, 'updated_at' => '2026-01-01 10:00:00'],
            ['grader_id' => 3, 'points' => 4, 'updated_at' => '2026-01-01 11:00:00'],
        ], 1);
        self::assertSame(4.0, $r['points']);
        self::assertSame('latest', $r['source']);
        self::assertTrue($r['differs']);
    }

    public function testFinalEntryIgnoresClearedOwnerEntry(): void
    {
        $r = GradingService::finalEntry([
            ['grader_id' => 1, 'points' => null],
            ['grader_id' => 2, 'points' => 2],
        ], 1);
        self::assertSame(2.0, $r['points']);
        self::assertSame('single', $r['source']);
    }

    // ---------- Prozent und Note ----------

    public function testPercentAndGrade(): void
    {
        self::assertSame(80.0, GradingService::percent(24.0, 30.0));
        self::assertNull(GradingService::percent(1.0, 0.0));

        $scale = ['thresholds' => [
            ['label' => '1', 'value' => 1, 'min_percent' => 85],
            ['label' => '2', 'value' => 2, 'min_percent' => 70],
            ['label' => '3', 'value' => 3, 'min_percent' => 0],
        ]];
        self::assertSame('2', GradingService::gradeOf($scale, 80.0)['label']);
        self::assertSame('1', GradingService::gradeOf($scale, 85.0)['label']);
        self::assertSame('3', GradingService::gradeOf($scale, 0.0)['label']);
        self::assertNull(GradingService::gradeOf(null, 50.0));
        self::assertNull(GradingService::gradeOf($scale, null));
    }

    // ---------- Anker ----------

    public function testNormalizeAnchor(): void
    {
        self::assertSame(['kind' => 'task'], GradingService::normalizeAnchor(['kind' => 'task', 'x' => 1]));
        self::assertSame(
            ['kind' => 'code', 'file' => 'Main.java', 'from_line' => 3, 'to_line' => 5],
            GradingService::normalizeAnchor(['kind' => 'code', 'file' => 'Main.java', 'from_line' => '3', 'to_line' => '5']),
        );
        self::assertSame(
            ['kind' => 'code', 'file' => 'a.py', 'from_line' => 2, 'to_line' => 2],
            GradingService::normalizeAnchor(['kind' => 'code', 'file' => 'a.py', 'from_line' => 2]),
        );
        $text = GradingService::normalizeAnchor(['kind' => 'text', 'from' => 4, 'to' => 9, 'quote' => 'abc']);
        self::assertSame(['kind' => 'text', 'from' => 4, 'to' => 9, 'quote' => 'abc'], $text);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badAnchors(): iterable
    {
        yield 'unbekannt' => [['kind' => 'foto']];
        yield 'Dateiname' => [['kind' => 'code', 'file' => '../x', 'from_line' => 1, 'to_line' => 1]];
        yield 'Zeile 0' => [['kind' => 'code', 'file' => 'a.py', 'from_line' => 0, 'to_line' => 1]];
        yield 'umgekehrt' => [['kind' => 'code', 'file' => 'a.py', 'from_line' => 5, 'to_line' => 2]];
        yield 'Text umgekehrt' => [['kind' => 'text', 'from' => 9, 'to' => 4]];
    }

    /** @param array<string, mixed> $anchor */
    #[\PHPUnit\Framework\Attributes\DataProvider('badAnchors')]
    public function testNormalizeAnchorRejects(array $anchor): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GradingService::normalizeAnchor($anchor);
    }

    public function testPseudonymsAreStableAndComplete(): void
    {
        $a = GradingService::pseudonyms(5, [10, 11, 12, 13]);
        $b = GradingService::pseudonyms(5, [13, 12, 11, 10]);
        self::assertSame($a, $b);
        $numbers = array_values($a);
        sort($numbers);
        self::assertSame([1, 2, 3, 4], $numbers);
    }

    // ---------- Code-Tests ----------

    public function testCodeScoreUsesTestPointsAndCapsAtTaskPoints(): void
    {
        $tests = [['name' => 'a', 'points' => 2], ['name' => 'b', 'points' => 3], ['name' => 'c', 'points' => 3]];
        $results = [['ok' => true], ['ok' => true], ['ok' => true, 'message' => '']];
        $r = AutoGrader::codeScore($tests, $results, 6.0);
        self::assertSame(6.0, $r['suggested']);   // 8 Testpunkte, gedeckelt auf 6
        self::assertSame(3, $r['details']['passed']);
        self::assertSame('tests', $r['details']['source']);

        $r = AutoGrader::codeScore($tests, [['ok' => true], ['ok' => false, 'message' => 'x'], ['ok' => false]], 8.0);
        self::assertSame(2.0, $r['suggested']);
        self::assertSame(2.0, $r['details']['results'][0]['earned']);
        self::assertSame(0.0, $r['details']['results'][1]['earned']);
        self::assertSame('x', $r['details']['results'][1]['message']);
    }

    public function testCodeScoreIsProportionalWithoutTestPoints(): void
    {
        $tests = [['name' => 'a'], ['name' => 'b'], ['name' => 'c'], ['name' => 'd']];
        $r = AutoGrader::codeScore($tests, [['ok' => true], ['ok' => true], ['ok' => true], ['ok' => false]], 5.0);
        self::assertSame(4.0, $r['suggested']); // 3,75 -> 4,0
        $r = AutoGrader::codeScore($tests, [['ok' => true], ['ok' => false], ['ok' => false], ['ok' => false]], 5.0);
        self::assertSame(1.5, $r['suggested']); // 1,25 wird zu 1,5
    }

    public function testCodeScoreTreatsMissingResultsAsFailed(): void
    {
        $r = AutoGrader::codeScore([['name' => 'a', 'points' => 1], ['name' => 'b', 'points' => 1]], [['ok' => true]], 2.0);
        self::assertSame(1.0, $r['suggested']);
        self::assertFalse($r['details']['results'][1]['ok']);
    }

    // ---------- Statistik ----------

    public function testMeanMedianDistribution(): void
    {
        self::assertNull(ExamStatistics::mean([]));
        self::assertSame(2.0, ExamStatistics::mean([1, 2, 3]));
        self::assertSame(2.0, ExamStatistics::median([3, 1, 2]));
        self::assertSame(2.5, ExamStatistics::median([4, 1, 2, 3]));
        self::assertNull(ExamStatistics::median([]));
        self::assertSame(['1' => 1, '2' => 0, '3' => 2], ExamStatistics::distribution(['1', '3', '3'], ['1', '2', '3']));
    }

    public function testComputeStatistics(): void
    {
        $stats = ExamStatistics::compute([
            'tasks' => [
                ['id' => 1, 'number' => '1', 'title' => 'A', 'points' => 4, 'afb' => 'I'],
                ['id' => 2, 'number' => '2', 'title' => 'B', 'points' => 6, 'afb' => 'II'],
            ],
            'persons' => [
                ['total' => 10, 'max' => 10, 'percent' => 100.0, 'grade_label' => '1', 'grade_value' => 1.0, 'points' => [1 => 4, 2 => 6]],
                ['total' => 4, 'max' => 10, 'percent' => 40.0, 'grade_label' => '4', 'grade_value' => 4.0, 'points' => [1 => 2, 2 => 2]],
                ['total' => 0, 'max' => 10, 'percent' => 0.0, 'grade_label' => '6', 'grade_value' => 6.0, 'points' => [1 => 0, 2 => null]],
            ],
            'order' => ['1', '2', '3', '4', '5', '6'],
        ]);
        self::assertSame(3, $stats['n']);
        self::assertEqualsWithDelta(46.667, $stats['mean_percent'], 0.01);
        self::assertSame(40.0, $stats['median_percent']);
        self::assertSame(4.0, $stats['median_grade']);
        self::assertSame(['1' => 1, '2' => 0, '3' => 0, '4' => 1, '5' => 0, '6' => 1], $stats['distribution']);

        // Aufgabe 1: Durchschnitt 2, Quote 50 %; Aufgabe 2: nur zwei bewertete Personen (6 und 2)
        self::assertSame(2.0, $stats['tasks'][0]['average']);
        self::assertEqualsWithDelta(0.5, $stats['tasks'][0]['rate'], 1e-9);
        self::assertSame(2, $stats['tasks'][1]['n']);
        self::assertEqualsWithDelta(4 / 6, $stats['tasks'][1]['rate'], 1e-9);
        self::assertSame(1, $stats['tasks'][0]['full']);
        self::assertSame(1, $stats['tasks'][0]['zero']);

        // Anforderungsbereiche: I = 6/12, II = 8/12
        self::assertSame('I', $stats['afb'][0]['afb']);
        self::assertEqualsWithDelta(0.5, $stats['afb'][0]['rate'], 1e-9);
        self::assertSame('II', $stats['afb'][1]['afb']);
        self::assertEqualsWithDelta(8 / 12, $stats['afb'][1]['rate'], 1e-9);
    }

    public function testComputeWithoutPersons(): void
    {
        $stats = ExamStatistics::compute(['tasks' => [['id' => 1, 'number' => '1', 'title' => '', 'points' => 2, 'afb' => null]], 'persons' => [], 'order' => ['1']]);
        self::assertSame(0, $stats['n']);
        self::assertNull($stats['mean_percent']);
        self::assertNull($stats['tasks'][0]['rate']);
        self::assertSame(['1' => 0], $stats['distribution']);
    }

    // ---------- PDF-Texte ----------

    public function testAnchorLabelsForPdf(): void
    {
        self::assertSame('Zeile 4 (Main.java):', ResultPdf::anchorLabel(['kind' => 'code', 'file' => 'Main.java', 'from_line' => 4, 'to_line' => 4]));
        self::assertSame('Zeilen 4 bis 6 (a.py):', ResultPdf::anchorLabel(['kind' => 'code', 'file' => 'a.py', 'from_line' => 4, 'to_line' => 6]));
        self::assertSame('Allgemein:', ResultPdf::anchorLabel(['kind' => 'task']));
        self::assertStringStartsWith('Zu "', ResultPdf::anchorLabel(['kind' => 'text', 'quote' => 'Hallo']));
    }

    public function testPdfStartsWithPdfHeaderAndHandlesUmlauts(): void
    {
        $pdf = new ResultPdf('Schule am Tor');
        self::assertSame('Gr' . "\xFC" . "\xDF" . 'e', $pdf->t('Grüße'));
        $bytes = $pdf->render([self::sampleReport()]);
        self::assertStringStartsWith('%PDF', $bytes);
        self::assertGreaterThan(1500, strlen($bytes));
    }

    public function testPdfBreaksLongCodeAcrossPages(): void
    {
        $code = implode("\n", array_map(static fn (int $i): string => "    int wert{$i} = {$i} * 2; // Zeile mit etwas längerem Kommentar damit umgebrochen werden muss, vielleicht", range(1, 200)));
        $report = self::sampleReport();
        $report['tasks'][1]['answer'] = ['files' => [['name' => 'Main.java', 'content' => $code]]];
        $bytes = (new ResultPdf('S'))->render([$report, $report]);
        self::assertStringStartsWith('%PDF', $bytes);
        preg_match_all('#/Type /Page\b#', $bytes, $m);
        self::assertGreaterThan(4, count($m[0]), 'Langer Code braucht mehrere Seiten, zwei Personen mindestens zwei Startseiten.');
    }

    /** @return array<string, mixed> */
    private static function sampleReport(): array
    {
        return [
            'exam' => ['id' => 1, 'title' => 'Klausur Q1: Listen', 'course' => 'INF Q1', 'date' => '2026-11-04 08:00:00'],
            'person' => ['name' => 'Mia Müller', 'username' => 'mia'],
            'summary' => ['total' => 7.5, 'max' => 10.0, 'percent' => 75.0, 'grade_label' => '2', 'scale_type' => 'grades', 'general_comment' => "Gut gemacht.\nWeiter so!", 'finalized_at' => null],
            'tasks' => [
                ['id' => 1, 'number' => '1', 'title' => 'Listen', 'depth' => 0, 'answer_type' => null, 'answer' => null, 'answer_text' => '', 'preview_png' => null, 'comments' => [], 'earned' => null, 'points' => 10.0, 'overtime' => false],
                [
                    'id' => 2, 'number' => '1a', 'title' => 'Methode', 'depth' => 1, 'answer_type' => 'code', 'points' => 6.0, 'earned' => 4.5, 'overtime' => true,
                    'answer' => ['files' => [['name' => 'Main.java', 'content' => "public class Main {\n    // Größe prüfen\n}\n"]]], 'answer_text' => '', 'preview_png' => null,
                    'comments' => [['id' => 1, 'grader_id' => 1, 'grader_name' => 'L', 'anchor' => ['kind' => 'code', 'file' => 'Main.java', 'from_line' => 2, 'to_line' => 2], 'body' => 'Schön gelöst, aber ohne Prüfung auf null.']],
                ],
                [
                    'id' => 3, 'number' => '1b', 'title' => 'Erklärung', 'depth' => 1, 'answer_type' => 'text', 'points' => 4.0, 'earned' => 3.0, 'overtime' => false,
                    'answer' => ['text' => 'Eine verkettete Liste besteht aus Knoten – jeder kennt seinen Nachfolger.'], 'answer_text' => 'Eine verkettete Liste besteht aus Knoten – jeder kennt seinen Nachfolger.', 'preview_png' => null,
                    'comments' => [['id' => 2, 'grader_id' => 1, 'grader_name' => 'L', 'anchor' => ['kind' => 'task'], 'body' => 'Es fehlt die Laufzeit.']],
                ],
            ],
        ];
    }
}
