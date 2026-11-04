<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Auth;
use App\Core\Session;
use App\Services\Audit;
use App\Services\AutoAssign;
use App\Services\EnrollmentService;
use App\Services\LimitCheck;

/**
 * Testdaten-Helfer für Integrationstests. Erwartet `$this->db` (Database)
 * aus DatabaseTestCase. Alle Methoden legen Datensätze an und geben IDs
 * bzw. Zeilen zurück; nichts wird gecacht.
 */
trait Fixtures
{
    /**
     * @param array<string, mixed> $overrides Spalten von garden_days
     * @return array<string, mixed> die angelegte Zeile
     */
    protected function createDay(array $overrides = []): array
    {
        $row = array_merge([
            'name' => 'Gartenarbeitstag Test',
            'event_date' => '2026-05-20',
            'status' => 'active',
            'mode' => 'direct',
            'registration_start' => null,
            'registration_end' => null,
            'min_blocks_per_student' => 1,
            'max_blocks_per_student' => null,
            'wishes_per_block' => 3,
            'waitlist_enabled' => 1,
        ], $overrides);

        $this->db->run(
            'INSERT INTO garden_days (name, event_date, status, mode, registration_start, registration_end,
                min_blocks_per_student, max_blocks_per_student, wishes_per_block, waitlist_enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_values($row),
        );
        $id = $this->db->lastInsertId();

        return $this->db->fetchOne('SELECT * FROM garden_days WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed> */
    protected function reloadDay(int $dayId): array
    {
        return $this->db->fetchOne('SELECT * FROM garden_days WHERE id = ?', [$dayId]);
    }

    protected function createBlock(int $dayId, string $name = 'Vormittag', string $start = '08:00:00', string $end = '10:00:00', int $sort = 0): int
    {
        $this->db->run(
            'INSERT INTO time_blocks (garden_day_id, name, start_time, end_time, sort_order) VALUES (?, ?, ?, ?, ?)',
            [$dayId, $name, $start, $end, $sort],
        );

        return $this->db->lastInsertId();
    }

    /**
     * @param array<int, int> $capacities Block-ID => Kapazität
     * @param array<string, mixed> $overrides Spalten von stations
     * @return array<string, mixed> die angelegte Zeile
     */
    protected function createStation(int $dayId, string $name, array $capacities = [], array $overrides = []): array
    {
        $row = array_merge([
            'max_per_class' => null,
            'max_per_grade' => null,
            'allowed_grades' => null,
            'allowed_classes' => null,
            'min_students' => null,
            'is_active' => 1,
            'sort_order' => 0,
        ], $overrides);

        $this->db->run(
            'INSERT INTO stations (garden_day_id, name, max_per_class, max_per_grade, allowed_grades, allowed_classes, min_students, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$dayId, $name, ...array_values($row)],
        );
        $id = $this->db->lastInsertId();

        foreach ($capacities as $blockId => $capacity) {
            $this->db->run(
                'INSERT INTO station_blocks (station_id, time_block_id, capacity) VALUES (?, ?, ?)',
                [$id, $blockId, $capacity],
            );
        }

        return $this->db->fetchOne('SELECT * FROM stations WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed> */
    protected function reloadStation(int $stationId): array
    {
        return $this->db->fetchOne('SELECT * FROM stations WHERE id = ?', [$stationId]);
    }

    /** @return array<string, mixed> die angelegte Zeile */
    protected function createStudent(string $username, ?string $class = '5a', ?int $grade = 5): array
    {
        $this->db->run(
            "INSERT INTO users (username, firstname, lastname, class, grade, role) VALUES (?, ?, 'Test', ?, ?, 'student')",
            [$username, ucfirst($username), $class, $grade],
        );
        $id = $this->db->lastInsertId();

        return $this->db->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    protected function createTeacher(string $username): int
    {
        $this->db->run(
            "INSERT INTO users (username, firstname, lastname, role) VALUES (?, ?, 'Lehrkraft', 'teacher')",
            [$username, ucfirst($username)],
        );

        return $this->db->lastInsertId();
    }

    protected function createCriterion(string $name, bool $active = true): int
    {
        $this->db->run(
            'INSERT INTO exclusion_criteria (name, is_active) VALUES (?, ?)',
            [$name, $active ? 1 : 0],
        );

        return $this->db->lastInsertId();
    }

    protected function excludeUser(int $userId, int $criterionId): void
    {
        $this->db->run('INSERT INTO user_exclusions (user_id, criterion_id) VALUES (?, ?)', [$userId, $criterionId]);
    }

    protected function excludeStation(int $stationId, int $criterionId): void
    {
        $this->db->run('INSERT INTO station_exclusions (station_id, criterion_id) VALUES (?, ?)', [$stationId, $criterionId]);
    }

    /** Direkter Insert, an allen Prüfungen vorbei (für Ausgangslagen). */
    protected function insertEnrollment(int $dayId, int $userId, int $stationId, int $blockId, string $status = 'assigned', ?int $priority = null, string $source = 'orga'): int
    {
        $this->db->run(
            'INSERT INTO enrollments (garden_day_id, user_id, station_id, time_block_id, status, priority, source)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$dayId, $userId, $stationId, $blockId, $status, $priority, $source],
        );

        return $this->db->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    protected function enrollment(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM enrollments WHERE id = ?', [$id]);
    }

    protected function limits(): LimitCheck
    {
        return new LimitCheck($this->db);
    }

    protected function audit(): Audit
    {
        return new Audit($this->db, new Auth(new Session(), $this->db));
    }

    protected function enrollments(): EnrollmentService
    {
        return new EnrollmentService($this->db, $this->limits(), $this->audit());
    }

    protected function autoAssign(): AutoAssign
    {
        return new AutoAssign($this->db, $this->limits(), $this->audit());
    }

    /**
     * @param list<array{code: string, message: string, hard: bool}> $violations
     * @return list<string>
     */
    protected static function codes(array $violations): array
    {
        return array_column($violations, 'code');
    }
}
