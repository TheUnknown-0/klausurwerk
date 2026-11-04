<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\AccessCards;
use Tests\Support\AdminFixtures;
use Tests\Support\DatabaseTestCase;

/** Kartenerzeugung: neues Passwort, card_version, nur aktive Schüler:innen. */
final class AccessCardsIntegrationTest extends DatabaseTestCase
{
    use AdminFixtures;

    public function testKarteAendertPasswortHashUndKartenVersion(): void
    {
        $oldHash = password_hash('AltesPasswort', PASSWORD_DEFAULT);
        $student = $this->createUser('student', ['password' => $oldHash, 'card_version' => 3, 'must_change_password' => 1]);

        $cards = (new AccessCards($this->db))->issue([(int) $student['id']]);

        self::assertCount(1, $cards);
        $after = $this->db->fetchOne('SELECT * FROM users WHERE id = ?', [$student['id']]);
        self::assertNotSame($oldHash, $after['password']);
        self::assertSame(4, (int) $after['card_version']);
        self::assertNotNull($after['card_issued_at']);
        self::assertSame(0, (int) $after['must_change_password'], 'Karten-Passwörter sind fest');
        self::assertTrue(password_verify($cards[0]['password'], (string) $after['password']));
        self::assertFalse(password_verify('AltesPasswort', (string) $after['password']), 'die alte Karte ist ungültig');
        self::assertSame($student['username'], $cards[0]['username']);
    }

    public function testWiederholterDruckMachtVorherigeKarteUngueltig(): void
    {
        $student = $this->createUser('student');
        $service = new AccessCards($this->db);

        $first = $service->issue([(int) $student['id']])[0]['password'];
        $second = $service->issue([(int) $student['id']])[0]['password'];

        $row = $this->db->fetchOne('SELECT * FROM users WHERE id = ?', [$student['id']]);
        self::assertSame(2, (int) $row['card_version']);
        self::assertNotSame($first, $second);
        self::assertFalse(password_verify($first, (string) $row['password']));
        self::assertTrue(password_verify($second, (string) $row['password']));
    }

    public function testNurAktiveSchuelerUndKeinePasswoerterFuerLehrkraefte(): void
    {
        $active = $this->createUser('student');
        $inactive = $this->createUser('student', ['is_active' => 0]);
        $teacher = $this->createUser('teacher', ['password' => 'unveraendert']);

        $cards = (new AccessCards($this->db))->issue([(int) $active['id'], (int) $inactive['id'], (int) $teacher['id'], 999999, (int) $active['id']]);

        self::assertCount(1, $cards, 'doppelte, inaktive, fremde Rollen und unbekannte IDs fallen weg');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT card_version FROM users WHERE id = ?', [$inactive['id']]));
        self::assertSame('unveraendert', $this->db->fetchValue('SELECT password FROM users WHERE id = ?', [$teacher['id']]));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT card_version FROM users WHERE id = ?', [$teacher['id']]));
    }

    public function testKartenEnthaltenKursnamenUndSindNachNameSortiert(): void
    {
        $course = $this->createCourse('10a');
        $archived = $this->createCourse('9z', 9);
        $this->db->run('UPDATE courses SET is_archived = 1 WHERE id = ?', [$archived['id']]);
        $b = $this->createUser('student', ['firstname' => 'Zoe', 'lastname' => 'Becker']);
        $a = $this->createUser('student', ['firstname' => 'Anna', 'lastname' => 'Adler']);
        $this->addMember((int) $course['id'], (int) $a['id']);
        $this->addMember((int) $archived['id'], (int) $a['id']);

        $cards = (new AccessCards($this->db))->issue([(int) $b['id'], (int) $a['id']]);

        self::assertSame(['Anna Adler', 'Zoe Becker'], array_column($cards, 'name'));
        self::assertSame('10a', $cards[0]['courses'], 'archivierte Kurse erscheinen nicht');
        self::assertSame('', $cards[1]['courses']);
    }

    public function testIssueAndRenderLiefertPdf(): void
    {
        $students = [$this->createUser('student'), $this->createUser('student')];
        $result = (new AccessCards($this->db))->issueAndRender(
            array_map(static fn (array $s): int => (int) $s['id'], $students),
            'Musterschule',
            'Klausurwerk',
            'https://klausuren.example.org/login',
        );

        self::assertSame(2, $result['count']);
        self::assertStringStartsWith('%PDF-', $result['pdf']);
    }

    public function testAktiveSchuelerEinesKurses(): void
    {
        $course = $this->createCourse('10a');
        $in = $this->createUser('student');
        $off = $this->createUser('student', ['is_active' => 0]);
        $out = $this->createUser('student');
        $this->addMember((int) $course['id'], (int) $in['id']);
        $this->addMember((int) $course['id'], (int) $off['id']);

        self::assertSame([(int) $in['id']], (new AccessCards($this->db))->activeStudentIdsOfCourse((int) $course['id']));
        self::assertNotSame((int) $out['id'], (int) $in['id']);
    }
}
