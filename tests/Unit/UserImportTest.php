<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AccessCards;
use App\Services\CardPdf;
use App\Services\CourseService;
use App\Services\UserImport;
use PHPUnit\Framework\TestCase;

/** Benutzernamen, CSV-Zerlegung, Schuljahr und Passwörter, ohne Datenbank. */
final class UserImportTest extends TestCase
{
    public function testBenutzernameMitUmlautenTransliteriert(): void
    {
        self::assertSame('juergen.mueller', UserImport::baseUsername('Jürgen', 'Müller'));
        self::assertSame('anna.weiss', UserImport::baseUsername('Anna', 'Weiß'));
        self::assertSame('oezlem.oeztuerk', UserImport::baseUsername('Özlem', 'Öztürk'));
    }

    public function testBenutzernameErsterVornameUndGanzerNachname(): void
    {
        self::assertSame('annalena.vonderheide', UserImport::baseUsername('Anna-Lena Marie', 'von der Heide'));
        self::assertSame('jean.dubois', UserImport::baseUsername('Jean Pierre', "Du'Bois"));
        self::assertSame('lukasz.nowak', UserImport::baseUsername('Łukasz', 'Nowak'));
        self::assertSame('rene.dupont', UserImport::baseUsername('René', 'Dupont'));
    }

    public function testBenutzernameAmEndeGueltigUndLangGenug(): void
    {
        foreach ([['Jo', 'Li'], ['A', 'B'], ['', 'Meier'], ['Max', ''], ['!!!', '???']] as [$first, $last]) {
            $name = UserImport::baseUsername($first, $last);
            self::assertTrue(UserImport::isValidUsername($name), "'$name' aus '$first' '$last' muss gültig sein");
        }
    }

    public function testSlugEntferntSonderzeichen(): void
    {
        self::assertSame('ab12', UserImport::slug("A-b 1'2"));
        self::assertSame('', UserImport::slug('---'));
    }

    public function testBenutzernameGueltigkeit(): void
    {
        self::assertTrue(UserImport::isValidUsername('anna.mueller'));
        self::assertTrue(UserImport::isValidUsername('a-b_c.9'));
        self::assertFalse(UserImport::isValidUsername('ab'));
        self::assertFalse(UserImport::isValidUsername('anna mueller'));
        self::assertFalse(UserImport::isValidUsername('anna#mueller'));
        self::assertFalse(UserImport::isValidUsername(str_repeat('a', 101)));
    }

    public function testCsvMitSemikolonUndKopfzeile(): void
    {
        $csv = "Vorname;Nachname;Kurs;Benutzername;Nachteilsausgleich\r\nAnna;Müller;10a;;\r\nJonas;Schröder;10a;jonas.s;25\r\n";
        $parsed = UserImport::parseCsv($csv);
        self::assertArrayNotHasKey('error', $parsed);
        self::assertSame(';', $parsed['delimiter']);
        self::assertCount(2, $parsed['rows']);
        self::assertSame('Anna', $parsed['rows'][0]['firstname']);
        self::assertSame('', $parsed['rows'][0]['username']);
        self::assertSame('jonas.s', $parsed['rows'][1]['username']);
        self::assertSame('25', $parsed['rows'][1]['accommodation']);
        self::assertSame(3, $parsed['rows'][1]['line']);
    }

    public function testCsvMitKommaBomUndAliasen(): void
    {
        $csv = "\xEF\xBB\xBFnachname,vorname,klasse\nMeier,Eva,11 Info\n\nKurz,Tim,11 Info\n";
        $parsed = UserImport::parseCsv($csv);
        self::assertArrayNotHasKey('error', $parsed);
        self::assertSame(',', $parsed['delimiter']);
        self::assertCount(2, $parsed['rows']);
        self::assertSame('Eva', $parsed['rows'][0]['firstname']);
        self::assertSame('Meier', $parsed['rows'][0]['lastname']);
        self::assertSame('11 Info', $parsed['rows'][0]['course']);
        self::assertSame(4, $parsed['rows'][1]['line'], 'Leerzeilen zählen für die Zeilennummer mit');
    }

    public function testCsvMitAnfuehrungszeichenUndKommaImFeld(): void
    {
        $parsed = UserImport::parseCsv("Vorname;Nachname;Kurs\n\"Anna, Lena\";\"O'Neil\";\"10a, Info\"\n");
        self::assertSame('Anna, Lena', $parsed['rows'][0]['firstname']);
        self::assertSame("O'Neil", $parsed['rows'][0]['lastname']);
        self::assertSame('10a, Info', $parsed['rows'][0]['course']);
    }

    public function testCsvFehlerfaelle(): void
    {
        self::assertArrayHasKey('error', UserImport::parseCsv("Vorname;Nachname\nA;B\n"));
        self::assertArrayHasKey('error', UserImport::parseCsv("Vorname;Nachname;Kurs\n"));
        self::assertArrayHasKey('error', UserImport::parseCsv(''));

        $big = "Vorname;Nachname;Kurs\n" . str_repeat("A;B;10a\n", UserImport::MAX_ROWS + 1);
        self::assertArrayHasKey('error', UserImport::parseCsv($big));
    }

    public function testKlassenstufeAusKursnamen(): void
    {
        self::assertSame(10, CourseService::guessGradeLevel('10a'));
        self::assertSame(11, CourseService::guessGradeLevel('11 Info LK'));
        self::assertSame(5, CourseService::guessGradeLevel(' 5b'));
        self::assertNull(CourseService::guessGradeLevel('Q1 Info'));
        self::assertNull(CourseService::guessGradeLevel('14a'));
        self::assertNull(CourseService::guessGradeLevel('4c'));
        self::assertNull(CourseService::guessGradeLevel('Info 10'));
        self::assertNull(CourseService::guessGradeLevel('100'));
    }

    public function testSchuljahrAusDatum(): void
    {
        self::assertSame('2026/27', CourseService::schoolYearFor(new \DateTimeImmutable('2026-11-04')));
        self::assertSame('2026/27', CourseService::schoolYearFor(new \DateTimeImmutable('2026-08-01')));
        self::assertSame('2025/26', CourseService::schoolYearFor(new \DateTimeImmutable('2026-07-31')));
        self::assertSame('2026/27', CourseService::schoolYearFor(new \DateTimeImmutable('2027-03-15')));
        self::assertSame('2099/00', CourseService::schoolYearFor(new \DateTimeImmutable('2099-09-01')));
    }

    public function testSchuljahrFormat(): void
    {
        self::assertTrue(CourseService::isValidSchoolYear('2026/27'));
        self::assertTrue(CourseService::isValidSchoolYear('2099/00'));
        self::assertFalse(CourseService::isValidSchoolYear('2026/28'));
        self::assertFalse(CourseService::isValidSchoolYear('26/27'));
        self::assertFalse(CourseService::isValidSchoolYear('2026-27'));
    }

    public function testPasswortOhneVerwechselbareZeichen(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $password = AccessCards::generatePassword();
            self::assertSame(10, strlen($password));
            self::assertSame(0, preg_match('/[0O1lIo]/', $password), "'$password' enthält verwechselbare Zeichen");
            self::assertSame(1, preg_match('/^[A-Za-z2-9]{10}$/', $password));
        }
        self::assertNotSame(AccessCards::generatePassword(), AccessCards::generatePassword());
    }

    public function testKartenPdfHatAchtKartenJeSeite(): void
    {
        $cards = [];
        for ($i = 1; $i <= 9; $i++) {
            $cards[] = ['name' => "Schüler:in $i Müller", 'username' => "schueler$i", 'courses' => '10a, 11 Info', 'password' => AccessCards::generatePassword()];
        }
        $pdf = (new CardPdf())->render($cards, 'Muster-Schule', 'Klausurwerk', 'https://klausuren.example.org/login');

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(2, preg_match_all('#/Type /Page\b(?!s)#', $pdf), '9 Karten brauchen 2 Seiten');

        $one = (new CardPdf())->render(array_slice($cards, 0, 8), '', 'Klausurwerk', 'https://x.example/login');
        self::assertSame(1, preg_match_all('#/Type /Page\b(?!s)#', $one), '8 Karten passen auf 1 Seite');
    }

    public function testQrInhaltTraegtZugangsdatenImFragment(): void
    {
        self::assertSame(
            'https://x.example/login#u=anna.mueller&p=Ab3dEf7hKm',
            CardPdf::qrPayload('https://x.example/login', 'anna.mueller', 'Ab3dEf7hKm'),
        );
    }
}
