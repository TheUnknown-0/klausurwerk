<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Services\CorrectionMarks;
use App\Services\ExamSettings;
use App\Services\GradingService;
use PHPUnit\Framework\TestCase;

/** Korrekturzeichen: Zeichensätze, Zählung, Fehlerquotient, Anker-Prüfung (ohne Datenbank). */
final class CorrectionMarksTest extends TestCase
{
    public function testSetForSubject(): void
    {
        self::assertSame('deutsch', CorrectionMarks::setForSubject('Deutsch'));
        foreach (['Englisch', 'Französisch', 'Spanisch', 'Italienisch', 'Russisch', ' englisch '] as $subject) {
            self::assertSame('fremdsprache', CorrectionMarks::setForSubject($subject), $subject);
        }
        self::assertSame('latein', CorrectionMarks::setForSubject('Latein'));
        self::assertSame('latein', CorrectionMarks::setForSubject('Altgriechisch'));
        self::assertSame('aus', CorrectionMarks::setForSubject('Informatik'));
        self::assertSame('aus', CorrectionMarks::setForSubject(''));
    }

    public function testSetsHaveExpectedMarks(): void
    {
        $codes = static fn (string $set): array => array_column(CorrectionMarks::definitions($set), 'code');
        self::assertSame(['R', 'Gr', 'Z', 'A', 'W', 'Sb', 'T', 'Bz', 'St', 'Wdh'], $codes('deutsch'));
        self::assertSame(['sp', 'gr', 'voc', 'wo', 't', 'prep', 'st', 'ex', '!'], $codes('fremdsprache'));
        self::assertSame(['Vok', 'Gr', 'K', 'S', 'Ausl'], $codes('latein'));
        self::assertSame([], $codes('aus'));
        self::assertSame(2.0, CorrectionMarks::find('latein', 'S')['weight']);
        self::assertNull(CorrectionMarks::find('deutsch', 'sp'));
    }

    public function testActiveSetFollowsSettingAndSubject(): void
    {
        $exam = static fn (array $settings, ?string $subject): array => ['settings' => json_encode($settings)] + ($subject !== null ? ['subject' => $subject] : []);
        self::assertSame('aus', CorrectionMarks::activeSet($exam([], null)));
        self::assertSame('deutsch', CorrectionMarks::activeSet($exam([], 'Deutsch')));
        self::assertSame('deutsch', CorrectionMarks::activeSet($exam(['correction_marks' => 'auto'], 'Deutsch')));
        self::assertSame('latein', CorrectionMarks::activeSet($exam(['correction_marks' => 'latein'], 'Deutsch')));
        self::assertSame('aus', CorrectionMarks::activeSet($exam(['correction_marks' => 'aus'], 'Deutsch')));
        self::assertSame('aus', CorrectionMarks::activeSet($exam(['correction_marks' => 'unsinn'], 'Informatik')));
    }

    public function testSettingIsNormalized(): void
    {
        $d = ExamSettings::defaults();
        self::assertSame('auto', $d['correction_marks']);
        self::assertFalse($d['correction_marks_show_quotient']);
        self::assertSame('latein', ExamSettings::normalize(['correction_marks' => 'latein'])['correction_marks']);
        self::assertSame('auto', ExamSettings::normalize(['correction_marks' => 'x'])['correction_marks']);
        self::assertTrue(ExamSettings::normalize(['correction_marks_show_quotient' => '1'])['correction_marks_show_quotient']);
        self::assertNotContains('correction_marks', ExamSettings::ADVANCED_KEYS);
    }

    public function testSummarizeWeightsAndQuotient(): void
    {
        $defs = [
            ['code' => 'R', 'name' => 'R', 'counts' => true, 'weight' => 1.0],
            ['code' => 'S', 'name' => 'S', 'counts' => true, 'weight' => 2.0],
            ['code' => 'H', 'name' => 'H', 'counts' => false, 'weight' => 1.0],
            ['code' => 'h', 'name' => 'h', 'counts' => true, 'weight' => 0.5],
        ];
        $s = CorrectionMarks::summarize(['R', 'R', 'S', 'H', 'h', 'ZZ'], 120, $defs);
        self::assertSame(['R' => 2, 'S' => 1, 'H' => 1, 'h' => 1], $s['counts']);
        self::assertSame(4, $s['errors']);
        self::assertSame(4.5, $s['weighted']);
        self::assertSame(3.8, $s['quotient']);
        self::assertNull(CorrectionMarks::summarize(['R'], 0, $defs)['quotient']);
    }

    public function testCombineUsesSums(): void
    {
        $defs = CorrectionMarks::definitions('deutsch');
        $a = CorrectionMarks::summarize(['R', 'Gr'], 50, $defs);
        $b = CorrectionMarks::summarize(['R'], 150, $defs);
        $all = CorrectionMarks::combine([$a, $b]);
        self::assertSame(['R' => 2, 'Gr' => 1], $all['counts']);
        self::assertSame(3.0, $all['weighted']);
        self::assertSame(200, $all['words']);
        self::assertSame(1.5, $all['quotient']);
    }

    public function testCountWordsAndCodes(): void
    {
        self::assertSame(0, CorrectionMarks::countWords(" \n "));
        self::assertSame(4, CorrectionMarks::countWords("Ein Satz\nmit  vier"));
        $comments = [
            ['anchor' => ['kind' => 'text', 'from' => 0, 'to' => 1, 'quote' => 'x', 'mark' => 'Gr']],
            ['anchor' => ['kind' => 'text', 'from' => 0, 'to' => 1, 'quote' => 'x']],
            ['anchor' => ['kind' => 'code', 'file' => 'a', 'from_line' => 1, 'to_line' => 1, 'mark' => 'R']],
        ];
        self::assertSame(['Gr'], CorrectionMarks::codesOf($comments));
    }

    public function testAnnotateInsertsMarksAfterThePassage(): void
    {
        self::assertSame('Er gehte [Gr] heim [R] .', CorrectionMarks::annotate('Er gehte heim .', [['to' => 8, 'code' => 'Gr'], ['to' => 13, 'code' => 'R']]));
        // Positionen zählen wie in JavaScript (UTF-16): das Emoji belegt zwei Einheiten.
        self::assertSame('😀 ab [Gr] c', CorrectionMarks::annotate('😀 ab c', [['to' => 5, 'code' => 'Gr']]));
        self::assertSame('x [A] [B]', CorrectionMarks::annotate('x', [['to' => 1, 'code' => 'A'], ['to' => 1, 'code' => 'B']]));
        self::assertSame('x', CorrectionMarks::annotate('x', []));
    }

    public function testAnchorKeepsValidMarkOnly(): void
    {
        $a = ['kind' => 'text', 'from' => 3, 'to' => 9, 'quote' => 'abc'];
        self::assertSame($a + ['mark' => 'Gr'], GradingService::normalizeAnchor($a + ['mark' => 'Gr']));
        self::assertSame($a + ['mark' => '!'], GradingService::normalizeAnchor($a + ['mark' => '!']));
        self::assertSame($a, GradingService::normalizeAnchor($a + ['mark' => '']));
        self::assertSame($a, GradingService::normalizeAnchor($a));
        foreach (['<b>', 'zu lang', ['x'], 5] as $bad) {
            try {
                GradingService::normalizeAnchor($a + ['mark' => $bad]);
                self::fail('Ungültiges Zeichen wurde angenommen.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        // Bei anderen Ankerarten wird ein Zeichen verworfen.
        self::assertSame(['kind' => 'task'], GradingService::normalizeAnchor(['kind' => 'task', 'mark' => 'Gr']));
    }

    public function testSaveMarkSettingsRejectsUnknownChoice(): void
    {
        $service = (new \ReflectionClass(GradingService::class))->newInstanceWithoutConstructor();
        $this->expectException(HttpException::class);
        $service->saveMarkSettings(['status' => 'grading', 'settings' => '{}', 'id' => 1], 'klingonisch', false);
    }
}
