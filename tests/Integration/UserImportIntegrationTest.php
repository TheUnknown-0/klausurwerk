<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\UserImport;
use Tests\Support\AdminFixtures;
use Tests\Support\DatabaseTestCase;

/** CSV-Import gegen die Datenbank: Vorschau, Kursanlage, Mitgliedschaften, Wiederholung. */
final class UserImportIntegrationTest extends DatabaseTestCase
{
    use AdminFixtures;

    private const YEAR = '2026/27';

    /** @return array{actor_id: int, all_courses: bool, own_course_ids: list<int>, may_create_courses: bool, may_set_accommodation: bool} */
    private function scope(int $actorId, bool $all = true, array $own = [], bool $create = true, bool $accommodation = true): array
    {
        return [
            'actor_id' => $actorId,
            'all_courses' => $all,
            'own_course_ids' => $own,
            'may_create_courses' => $create,
            'may_set_accommodation' => $accommodation,
        ];
    }

    private function import(string $csv, array $scope, array $levels = []): array
    {
        $parsed = UserImport::parseCsv($csv);
        self::assertArrayNotHasKey('error', $parsed);
        $importer = new UserImport($this->db);
        $analysis = $importer->analyze($parsed['rows'], $scope, self::YEAR);

        return [$analysis, $importer->execute($analysis, $levels, $scope['actor_id'])];
    }

    public function testImportLegtKonteKurseUndMitgliedschaftenAn(): void
    {
        $admin = $this->createUser('admin');
        $csv = "Vorname;Nachname;Kurs;Benutzername;Nachteilsausgleich\n"
            . "Anna;Müller;10a;;\n"
            . "Jonas;Schröder;10a;jonas.s;25\n"
            . "Eva;Meier;11 Info;;\n";

        [$analysis, $result] = $this->import($csv, $this->scope((int) $admin['id']));

        self::assertSame(['new' => 3, 'skip' => 0, 'error' => 0], $analysis['counts']);
        self::assertSame(3, $result['created']);
        self::assertSame(2, $result['courses_created']);
        self::assertCount(3, $result['user_ids']);

        $anna = $this->db->fetchOne("SELECT * FROM users WHERE username = 'anna.mueller'");
        self::assertNotNull($anna, 'Umlaute werden im Benutzernamen transliteriert');
        self::assertSame('student', $anna['role']);
        self::assertSame('import', $anna['created_via']);
        self::assertNull($anna['password'], 'ohne Karte kein Passwort');
        self::assertSame(0, (int) $anna['accommodation_percent']);

        $jonas = $this->db->fetchOne("SELECT * FROM users WHERE username = 'jonas.s'");
        self::assertSame(25, (int) $jonas['accommodation_percent']);

        // 10a und 11 Info wurden angelegt: Klassenstufe aus dem Namen, aktuelles Schuljahr, Hauptlehrkraft = Importierende.
        $c10 = $this->db->fetchOne("SELECT * FROM courses WHERE name = '10a'");
        self::assertSame(10, (int) $c10['grade_level']);
        self::assertSame(self::YEAR, $c10['school_year']);
        self::assertSame((int) $admin['id'], (int) $c10['owner_id']);
        $c11 = $this->db->fetchOne("SELECT * FROM courses WHERE name = '11 Info'");
        self::assertSame(11, (int) $c11['grade_level']);

        self::assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM course_members WHERE course_id = ?', [$c10['id']]));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM course_members WHERE course_id = ?', [$c11['id']]));
    }

    public function testKursOhneErratbareKlassenstufeBrauchtAngabe(): void
    {
        $admin = $this->createUser('admin');
        $parsed = UserImport::parseCsv("Vorname;Nachname;Kurs\nAnna;Müller;Q1 Info\n");
        $importer = new UserImport($this->db);
        $analysis = $importer->analyze($parsed['rows'], $this->scope((int) $admin['id']), self::YEAR);

        try {
            $importer->execute($analysis, [], (int) $admin['id']);
            self::fail('Ohne Klassenstufe darf kein Kurs angelegt werden.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Q1 Info', $e->getMessage());
        }
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM users WHERE role = 'student'"), 'nichts wurde geschrieben');

        $result = $importer->execute($analysis, ['q1 info' => '12'], (int) $admin['id']);
        self::assertSame(1, $result['created']);
        self::assertSame(12, (int) $this->db->fetchValue("SELECT grade_level FROM courses WHERE name = 'Q1 Info'"));
    }

    public function testBenutzernamenWerdenEindeutigMitZahl(): void
    {
        $admin = $this->createUser('admin');
        $this->createUser('student', ['username' => 'anna.mueller']);
        $csv = "Vorname;Nachname;Kurs\nAnna;Müller;10a\nAnna;Müller;10b\n";

        [, $result] = $this->import($csv, $this->scope((int) $admin['id']));

        self::assertSame(2, $result['created']);
        self::assertNotNull($this->db->fetchOne("SELECT 1 FROM users WHERE username = 'anna.mueller2'"));
        self::assertNotNull($this->db->fetchOne("SELECT 1 FROM users WHERE username = 'anna.mueller3'"));
    }

    public function testFehlerzeilenWerdenNichtImportiert(): void
    {
        $admin = $this->createUser('admin');
        $this->createUser('student', ['username' => 'vergeben']);
        $csv = "Vorname;Nachname;Kurs;Benutzername;Nachteilsausgleich\n"
            . ";Ohne;10a;;\n"
            . "Max;Mustermann;;;\n"
            . "Doppelt;Name;10a;vergeben;\n"
            . "Ungueltig;Name;10a;a b;\n"
            . "Zu;Viel;10a;;150\n"
            . "Gut;Gemacht;10a;;\n";

        [$analysis, $result] = $this->import($csv, $this->scope((int) $admin['id']));

        self::assertSame(5, $analysis['counts']['error']);
        self::assertSame(1, $analysis['counts']['new']);
        self::assertSame(1, $result['created']);
        self::assertSame('error', $analysis['rows'][0]['status']);
        self::assertStringContainsString('Pflicht', $analysis['rows'][0]['reason']);
        self::assertStringContainsString('Kurs fehlt', $analysis['rows'][1]['reason']);
        self::assertStringContainsString('vergeben', $analysis['rows'][2]['reason']);
        self::assertStringContainsString('ungültig', $analysis['rows'][3]['reason']);
        self::assertStringContainsString('0 bis 100', $analysis['rows'][4]['reason']);
    }

    public function testBenutzernameInDerDateiNurEinmal(): void
    {
        $admin = $this->createUser('admin');
        $csv = "Vorname;Nachname;Kurs;Benutzername\nA;Eins;10a;gleich\nB;Zwei;10a;GLEICH\n";
        [$analysis] = $this->import($csv, $this->scope((int) $admin['id']));

        self::assertSame('new', $analysis['rows'][0]['status']);
        self::assertSame('error', $analysis['rows'][1]['status']);
        self::assertStringContainsString('mehrfach', $analysis['rows'][1]['reason']);
    }

    public function testZweiterLaufUeberspringtVorhandene(): void
    {
        $admin = $this->createUser('admin');
        $csv = "Vorname;Nachname;Kurs\nAnna;Müller;10a\nBen;Schmidt;10a\n";
        $this->import($csv, $this->scope((int) $admin['id']));

        [$analysis, $result] = $this->import($csv, $this->scope((int) $admin['id']));

        self::assertSame(['new' => 0, 'skip' => 2, 'error' => 0], $analysis['counts']);
        self::assertSame(0, $result['created']);
        self::assertSame(2, $result['skipped']);
        self::assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM users WHERE role = 'student'"));
    }

    public function testLehrkraftNurInEigenenKursenUndMitRechten(): void
    {
        $teacher = $this->createUser('teacher');
        $other = $this->createUser('teacher');
        $own = $this->createCourse('10a', 10, (int) $teacher['id'], self::YEAR);
        $foreign = $this->createCourse('10b', 10, (int) $other['id'], self::YEAR);

        $csv = "Vorname;Nachname;Kurs;Nachteilsausgleich\nAnna;Eigen;10a;\nBen;Fremd;10b;\nCem;Neu;10c;\nDora;Nta;10a;20\n";

        // Ohne Recht für neue Kurse und ohne Nachteilsausgleich.
        $scope = $this->scope((int) $teacher['id'], false, [(int) $own['id']], false, false);
        [$analysis, $result] = $this->import($csv, $scope);

        self::assertSame('new', $analysis['rows'][0]['status']);
        self::assertSame('error', $analysis['rows'][1]['status']);
        self::assertStringContainsString('anderen Lehrkraft', $analysis['rows'][1]['reason']);
        self::assertSame('error', $analysis['rows'][2]['status']);
        self::assertStringContainsString('keine Kurse anlegen', $analysis['rows'][2]['reason']);
        self::assertSame('error', $analysis['rows'][3]['status']);
        self::assertStringContainsString('Nachteilsausgleich', $analysis['rows'][3]['reason']);

        self::assertSame(1, $result['created']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM course_members WHERE course_id = ?', [$foreign['id']]));
        self::assertNull($this->db->fetchOne("SELECT 1 FROM courses WHERE name = '10c'"));
    }

    public function testLehrkraftMitKursrechtLegtNeuenKursAnUndWirdHauptlehrkraft(): void
    {
        $teacher = $this->createUser('teacher');
        $scope = $this->scope((int) $teacher['id'], false, [], true, false);

        [, $result] = $this->import("Vorname;Nachname;Kurs\nAnna;Neu;9c\n", $scope);

        self::assertSame(1, $result['created']);
        $course = $this->db->fetchOne("SELECT * FROM courses WHERE name = '9c'");
        self::assertSame((int) $teacher['id'], (int) $course['owner_id']);
        self::assertSame(9, (int) $course['grade_level']);
    }

    public function testBenutzernameWirdBeimAusfuehrenErneutGeprueft(): void
    {
        $admin = $this->createUser('admin');
        $parsed = UserImport::parseCsv("Vorname;Nachname;Kurs;Benutzername\nA;B;10a;wettlauf\nC;D;10a;\n");
        $importer = new UserImport($this->db);
        $analysis = $importer->analyze($parsed['rows'], $this->scope((int) $admin['id']), self::YEAR);

        // Zwischen Vorschau und Ausführen nimmt jemand den Namen weg.
        $this->createUser('student', ['username' => 'wettlauf']);
        $result = $importer->execute($analysis, [], (int) $admin['id']);

        self::assertSame(1, $result['created']);
        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('wettlauf', $result['messages'][0]);
    }
}
