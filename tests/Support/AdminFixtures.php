<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Auth;
use App\Core\Session;

/**
 * Testdaten-Helfer für das Verwaltungs-Modul. Erwartet `$this->db` aus DatabaseTestCase.
 */
trait AdminFixtures
{
    private int $fixtureCounter = 0;

    /**
     * @param array<string, mixed> $overrides Spalten von users
     * @return array<string, mixed> die angelegte Zeile
     */
    protected function createUser(string $role = 'student', array $overrides = []): array
    {
        $n = ++$this->fixtureCounter;
        $row = array_merge([
            'username' => $role . '_' . $n . '_' . bin2hex(random_bytes(3)),
            'firstname' => ucfirst($role),
            'lastname' => 'Test' . $n,
            'role' => $role,
            'is_active' => 1,
        ], $overrides);

        $columns = array_keys($row);
        $this->db->run(
            'INSERT INTO users (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')',
            array_values($row),
        );

        return $this->db->fetchOne('SELECT * FROM users WHERE id = ?', [$this->db->lastInsertId()]);
    }

    /** @return array<string, mixed> die angelegte Zeile */
    protected function createCourse(string $name, int $gradeLevel = 10, ?int $ownerId = null, string $schoolYear = '2026/27'): array
    {
        $this->db->run(
            'INSERT INTO courses (name, grade_level, school_year, owner_id) VALUES (?, ?, ?, ?)',
            [$name, $gradeLevel, $schoolYear, $ownerId],
        );

        return $this->db->fetchOne('SELECT * FROM courses WHERE id = ?', [$this->db->lastInsertId()]);
    }

    protected function addMember(int $courseId, int $userId): void
    {
        $this->db->run('INSERT INTO course_members (course_id, user_id) VALUES (?, ?)', [$courseId, $userId]);
    }

    protected function grantPermission(int $userId, string $permission): void
    {
        $this->db->run('INSERT INTO user_permissions (user_id, permission) VALUES (?, ?)', [$userId, $permission]);
    }

    /** Auth für einen Nutzer, ohne echte Sitzung (liest $_SESSION direkt). */
    protected function authFor(int $userId): Auth
    {
        $_SESSION['user_id'] = $userId;

        return new Auth(new Session(), $this->db);
    }

    /** @return array<string, mixed> eine Klausur mit Mindestangaben */
    protected function createExam(string $title, ?int $courseId = null, string $status = 'finished', ?string $finishedAt = null, ?string $returnedAt = null): array
    {
        $this->db->run(
            "INSERT INTO exams (title, course_id, status, settings, finished_at, returned_at) VALUES (?, ?, ?, '{}', ?, ?)",
            [$title, $courseId, $status, $finishedAt, $returnedAt],
        );

        return $this->db->fetchOne('SELECT * FROM exams WHERE id = ?', [$this->db->lastInsertId()]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id']);
        parent::tearDown();
    }
}
