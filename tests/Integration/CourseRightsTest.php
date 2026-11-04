<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Permissions as P;
use App\Services\CourseService;
use Tests\Support\AdminFixtures;
use Tests\Support\DatabaseTestCase;

/** Rechte an Kursen: eigene, weitere Lehrkraft, ALLE_KURSE, Admin, Schüler:innen. */
final class CourseRightsTest extends DatabaseTestCase
{
    use AdminFixtures;

    private function service(int $userId): CourseService
    {
        return new CourseService($this->db, $this->authFor($userId));
    }

    public function testHauptlehrkraftSiehtUndBearbeitetEigenenKurs(): void
    {
        $teacher = $this->createUser('teacher');
        $course = $this->createCourse('10a', 10, (int) $teacher['id']);
        $service = $this->service((int) $teacher['id']);

        self::assertTrue($service->canView((int) $course['id']));
        self::assertTrue($service->canEdit((int) $course['id']));
        self::assertTrue($service->isOwnCourse((int) $course['id']));
    }

    public function testFremdenKursSiehtNurMitAlleKurse(): void
    {
        $owner = $this->createUser('teacher');
        $other = $this->createUser('teacher');
        $course = $this->createCourse('10a', 10, (int) $owner['id']);
        $id = (int) $course['id'];

        $service = $this->service((int) $other['id']);
        self::assertFalse($service->canView($id));
        self::assertFalse($service->canEdit($id));
        self::assertFalse($service->canManageStudent((int) $this->createUser('student')['id']));

        $this->grantPermission((int) $other['id'], P::ALLE_KURSE);
        $service = $this->service((int) $other['id']);
        self::assertTrue($service->canView($id));
        self::assertTrue($service->canEdit($id));
    }

    public function testWeitereLehrkraftHatZugriff(): void
    {
        $owner = $this->createUser('teacher');
        $helper = $this->createUser('teacher');
        $course = $this->createCourse('10a', 10, (int) $owner['id']);
        $this->db->run('INSERT INTO course_teachers (course_id, user_id) VALUES (?, ?)', [$course['id'], $helper['id']]);

        $service = $this->service((int) $helper['id']);
        self::assertTrue($service->canEdit((int) $course['id']));
        self::assertSame([(int) $course['id']], $service->ownCourseIds((int) $helper['id']));
    }

    public function testOhneKursRechtKeinZugriffAuchFuerEigenenKurs(): void
    {
        $teacher = $this->createUser('teacher');
        $course = $this->createCourse('10a', 10, (int) $teacher['id']);
        // Rollen-Standards enthalten KURSE_SEHEN und KURSE_BEARBEITEN; ein Admin kann sie nicht entziehen,
        // aber ein Schüler hat nie Rechte, auch nicht als Hauptlehrkraft eingetragen.
        $student = $this->createUser('student');
        $this->db->run('UPDATE courses SET owner_id = ? WHERE id = ?', [$student['id'], $course['id']]);

        $service = $this->service((int) $student['id']);
        self::assertFalse($service->canView((int) $course['id']));
        self::assertFalse($service->canEdit((int) $course['id']));
    }

    public function testAdminDarfAlles(): void
    {
        $owner = $this->createUser('teacher');
        $course = $this->createCourse('10a', 10, (int) $owner['id']);
        $admin = $this->createUser('admin');
        $service = $this->service((int) $admin['id']);

        self::assertTrue($service->canView((int) $course['id']));
        self::assertTrue($service->canEdit((int) $course['id']));
        self::assertTrue($service->canManageStudent((int) $this->createUser('student')['id']));
        self::assertTrue($service->seesAllCourses());
    }

    public function testSchuelerVerwaltungNurInEigenenKursen(): void
    {
        $teacher = $this->createUser('teacher');
        $mine = $this->createCourse('10a', 10, (int) $teacher['id']);
        $theirs = $this->createCourse('10b', 10, null);
        $inMine = $this->createUser('student');
        $inTheirs = $this->createUser('student');
        $nowhere = $this->createUser('student');
        $this->addMember((int) $mine['id'], (int) $inMine['id']);
        $this->addMember((int) $theirs['id'], (int) $inTheirs['id']);

        $service = $this->service((int) $teacher['id']);
        self::assertTrue($service->canManageStudent((int) $inMine['id']));
        self::assertFalse($service->canManageStudent((int) $inTheirs['id']));
        self::assertFalse($service->canManageStudent((int) $nowhere['id']));
    }

    public function testSichtbareKurseUndArchiv(): void
    {
        $teacher = $this->createUser('teacher');
        $other = $this->createUser('teacher');
        $mine = $this->createCourse('10a', 10, (int) $teacher['id']);
        $old = $this->createCourse('9a', 9, (int) $teacher['id'], '2025/26');
        $this->db->run('UPDATE courses SET is_archived = 1 WHERE id = ?', [$old['id']]);
        $this->createCourse('10b', 10, (int) $other['id']);

        $service = $this->service((int) $teacher['id']);
        self::assertSame([(int) $mine['id']], array_map(static fn (array $c): int => (int) $c['id'], $service->visibleCourses()));
        self::assertCount(2, $service->visibleCourses(true));
    }

    public function testMitgliederHinzufuegenNurSchuelerUndIdempotent(): void
    {
        $teacher = $this->createUser('teacher');
        $course = $this->createCourse('10a', 10, (int) $teacher['id']);
        $student = $this->createUser('student');
        $service = $this->service((int) $teacher['id']);

        self::assertTrue($service->addMember((int) $course['id'], (int) $student['id']));
        self::assertFalse($service->addMember((int) $course['id'], (int) $student['id']));
        self::assertFalse($service->addMember((int) $course['id'], (int) $teacher['id']), 'Lehrkräfte sind keine Mitglieder');
        self::assertCount(1, $service->members((int) $course['id']));

        $service->removeMember((int) $course['id'], (int) $student['id']);
        self::assertSame([], $service->members((int) $course['id']));
    }

    public function testKursAnlegenPruftNamenSchuljahrUndEindeutigkeit(): void
    {
        $teacher = $this->createUser('teacher');
        $service = $this->service((int) $teacher['id']);

        $id = $service->create('  11 Info ', 11, '2026/27', '', (int) $teacher['id']);
        $course = $service->find($id);
        self::assertSame('11 Info', $course['name']);
        self::assertSame('Informatik', $course['subject']);

        foreach ([['11 Info', 11, '2026/27'], ['', 11, '2026/27'], ['x', 4, '2026/27'], ['x', 14, '2026/27'], ['x', 10, '2026-27']] as [$name, $level, $year]) {
            try {
                $service->create($name, $level, $year, 'Informatik', null);
                self::fail("Kurs '$name' / $level / $year hätte abgelehnt werden müssen");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        // Gleicher Name in einem anderen Schuljahr ist erlaubt.
        self::assertGreaterThan(0, $service->create('11 Info', 11, '2027/28', 'Informatik', null));
    }

    public function testWeitereLehrkraefteOhneHauptlehrkraftUndNurStaff(): void
    {
        $owner = $this->createUser('teacher');
        $helper = $this->createUser('teacher');
        $student = $this->createUser('student');
        $course = $this->createCourse('10a', 10, (int) $owner['id']);
        $service = $this->service((int) $owner['id']);

        $service->setTeachers((int) $course['id'], [(int) $owner['id'], (int) $helper['id'], (int) $student['id'], 0], (int) $owner['id']);

        self::assertSame([(int) $helper['id']], array_map(static fn (array $t): int => (int) $t['id'], $service->teachers((int) $course['id'])));
    }
}
