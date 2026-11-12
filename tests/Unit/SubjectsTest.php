<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CorrectionMarks;
use App\Services\ExamSettings;
use App\Services\Markdown;
use App\Services\MediaPlays;
use App\Services\Subjects;
use PHPUnit\Framework\TestCase;

/** Fächer: Zuordnung, Hilfsmittel und Profile je Fach, Markdown für Sprachen und Chemie. */
final class SubjectsTest extends TestCase
{
    public function testFreieBezeichnungenWerdenZugeordnet(): void
    {
        self::assertSame('Englisch', Subjects::canonical('Englisch LK'));
        self::assertSame('Englisch', Subjects::canonical('en'));
        self::assertSame('Französisch', Subjects::canonical('Frz'));
        self::assertSame('Chemie', Subjects::canonical('chemie (Q2)'));
        self::assertNull(Subjects::canonical('Darstellendes Spiel'));
        self::assertSame('fremdsprache', Subjects::group('Spanisch GK'));
        self::assertSame('latein', Subjects::group('Latein'));
        self::assertSame('informatik', Subjects::group('Informatik'));
        self::assertSame('allgemein', Subjects::group('Darstellendes Spiel'));
        self::assertSame('fr', Subjects::language('Französisch'));
        self::assertSame('la', Subjects::charset('Latein'));
        self::assertSame('chem', Subjects::charset('Chemie'));
    }

    public function testCleanBegrenztLaenge(): void
    {
        self::assertNull(Subjects::clean('   '));
        self::assertSame('Englisch LK', Subjects::clean("  Englisch \t LK "));
        $this->expectException(\InvalidArgumentException::class);
        Subjects::clean(str_repeat('x', 61));
    }

    public function testStandardHilfsmittelJeFach(): void
    {
        $chem = ExamSettings::defaults('exam', 'Chemie')['aids'];
        self::assertTrue($chem['periodic_table']);
        self::assertTrue($chem['calculator']);
        self::assertFalse($chem['java_api']);
        $en = ExamSettings::defaults('exam', 'Englisch')['aids'];
        self::assertSame(['java_api' => false, 'python_doc' => false, 'sql_cheatsheet' => false, 'operators' => true, 'calculator' => false, 'periodic_table' => false], $en);
        // Informatik unverändert
        $inf = ExamSettings::defaults('practice')['aids'];
        self::assertTrue($inf['java_api']);
        self::assertFalse($inf['periodic_table']);
    }

    public function testProfileJeFach(): void
    {
        $locker = ExamSettings::profile('locker', 'practice', 'Chemie');
        self::assertTrue($locker['aids']['periodic_table']);
        self::assertFalse($locker['aids']['java_api'], 'Keine Programmier-Hilfen in Chemie');
        self::assertSame('locker', ExamSettings::matchingProfile($locker, 'practice', 'Chemie'));
        $streng = ExamSettings::profile('streng', 'exam', 'Chemie');
        self::assertTrue($streng['aids']['periodic_table'], 'Fachübliche Hilfsmittel bleiben im strengen Profil');
        self::assertSame('streng', ExamSettings::matchingProfile($streng, 'exam', 'Chemie'));
        // Informatik wie bisher
        self::assertFalse(ExamSettings::profile('streng', 'exam')['aids']['calculator']);
    }

    public function testKorrekturzeichenFolgenDemFach(): void
    {
        self::assertSame('fremdsprache', CorrectionMarks::setForSubject('Englisch LK'));
        self::assertSame('deutsch', CorrectionMarks::setForSubject('De'));
        self::assertSame('latein', CorrectionMarks::setForSubject('Latein'));
        self::assertSame('aus', CorrectionMarks::setForSubject('Chemie'));
    }

    public function testMarkdownZeilennummernUndChemie(): void
    {
        $md = new Markdown('', 1);
        $html = $md->render("```zeilen\nErste Zeile\nZweite <b>\n\nDritte\nVierte\nFünfte\n```");
        self::assertStringContainsString('<div class="md-lines">', $html);
        self::assertStringContainsString('data-n="1">Erste Zeile</span>', $html);
        self::assertStringContainsString('Zweite &lt;b&gt;', $html, 'Inhalt bleibt escaped');
        self::assertStringContainsString('<span class="md-line is-blank"></span><span class="md-line" data-n="3">Dritte', $html, 'Leerzeilen ohne Nummer');
        self::assertStringContainsString('is-marked" data-n="5">Fünfte', $html);
        self::assertStringContainsString('data-n="12">', $md->render("```zeilen-12\nab Zeile zwölf\n```"));

        $chem = $md->render('Wasser: \ce{2 H2 + O2 -> 2 H2O}, Code `\ce{x}` bleibt.');
        self::assertStringContainsString('<span class="math-inline">\ce{2 H2 + O2 -&gt; 2 H2O}</span>', $chem);
        self::assertStringContainsString('<code>\ce{x}</code>', $chem);
    }

    public function testMedienRegeln(): void
    {
        self::assertTrue(MediaPlays::isMedia('audio/mpeg'));
        self::assertTrue(MediaPlays::isMedia('video/mp4'));
        self::assertFalse(MediaPlays::isMedia('application/pdf'));
        self::assertSame('teacher', MediaPlays::cleanPolicy('irgendwas'));
        self::assertSame('limited', MediaPlays::cleanPolicy('limited'));
        self::assertSame(1, MediaPlays::cleanLimit(0));
        self::assertSame(MediaPlays::MAX_LIMIT, MediaPlays::cleanLimit(99));
    }
}
