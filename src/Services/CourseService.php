<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Permissions as P;

/**
 * Kurse: Schuljahr, Rechte an Kursen, Mitgliedschaften.
 *
 * "Eigener" Kurs = die Person ist Hauptlehrkraft (courses.owner_id) oder steht in
 * course_teachers. Fremde Kurse dürfen nur Admins und Lehrkräfte mit ALLE_KURSE
 * sehen und bearbeiten.
 */
final class CourseService
{
    public const MIN_GRADE = 5;
    public const MAX_GRADE = 13;

    public function __construct(
        private readonly Database $db,
        private readonly Auth $auth,
    ) {
    }

    // ---------- Schuljahr, Klassenstufe ----------

    /** Schuljahr im Format 2026/27 (Schuljahresbeginn August). */
    public static function schoolYearFor(\DateTimeInterface $date): string
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');
        $start = $month >= 8 ? $year : $year - 1;

        return sprintf('%d/%02d', $start, ($start + 1) % 100);
    }

    public static function currentSchoolYear(): string
    {
        return self::schoolYearFor(new \DateTimeImmutable('now'));
    }

    /** Gültiges Schuljahr: 2026/27 (zweite Zahl = Folgejahr). */
    public static function isValidSchoolYear(string $value): bool
    {
        if (preg_match('/^(\d{4})\/(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return (int) $m[2] === (((int) $m[1]) + 1) % 100;
    }

    /** Rät die Klassenstufe aus dem Kursnamen ("10a" ergibt 10, "11 Info" ergibt 11), sonst null. */
    public static function guessGradeLevel(string $name): ?int
    {
        if (preg_match('/^\s*(\d{1,2})(?!\d)/', $name, $m) !== 1) {
            return null;
        }
        $level = (int) $m[1];

        return $level >= self::MIN_GRADE && $level <= self::MAX_GRADE ? $level : null;
    }

    // ---------- Rechte ----------

    public function seesAllCourses(): bool
    {
        return $this->auth->isAdmin() || ($this->auth->isTeacher() && $this->auth->can(P::ALLE_KURSE));
    }

    /**
     * IDs der Kurse, in denen die Lehrkraft Hauptlehrkraft ist oder als weitere Lehrkraft steht.
     *
     * @return list<int>
     */
    public function ownCourseIds(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id FROM courses WHERE owner_id = ?
             UNION SELECT course_id AS id FROM course_teachers WHERE user_id = ?',
            [$userId, $userId],
        );

        return array_map('intval', array_column($rows, 'id'));
    }

    /** Ist der angemeldete Nutzer Lehrkraft dieses Kurses (owner oder course_teachers)? */
    public function isOwnCourse(int $courseId): bool
    {
        $userId = $this->auth->id();
        if ($userId === null) {
            return false;
        }

        return in_array($courseId, $this->ownCourseIds($userId), true);
    }

    /** Darf der Nutzer den Kurs sehen? */
    public function canView(int $courseId): bool
    {
        if (!$this->auth->isStaff() || !$this->auth->can(P::KURSE_SEHEN)) {
            return false;
        }

        return $this->seesAllCourses() || $this->isOwnCourse($courseId);
    }

    /** Darf der Nutzer den Kurs bearbeiten (Stammdaten, Mitglieder)? */
    public function canEdit(int $courseId): bool
    {
        if (!$this->auth->isStaff() || !$this->auth->can(P::KURSE_BEARBEITEN)) {
            return false;
        }

        return $this->seesAllCourses() || $this->isOwnCourse($courseId);
    }

    /**
     * Darf der Nutzer die Schüler:in verwalten? Admins und Lehrkräfte mit ALLE_KURSE immer,
     * sonst nur Mitglieder eigener Kurse.
     */
    public function canManageStudent(int $studentId): bool
    {
        if (!$this->auth->isStaff()) {
            return false;
        }
        if ($this->seesAllCourses()) {
            return true;
        }
        $userId = $this->auth->id();
        if ($userId === null) {
            return false;
        }

        return $this->db->fetchValue(
            'SELECT 1 FROM course_members cm
             WHERE cm.user_id = ?
               AND (cm.course_id IN (SELECT id FROM courses WHERE owner_id = ?)
                    OR cm.course_id IN (SELECT course_id FROM course_teachers WHERE user_id = ?))
             LIMIT 1',
            [$studentId, $userId, $userId],
        ) !== null;
    }

    // ---------- Lesen ----------

    /**
     * Kurse für Listen und Auswahlfelder, die der Nutzer sehen darf.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleCourses(bool $includeArchived = false): array
    {
        $archived = $includeArchived ? '' : ' AND c.is_archived = 0';
        $select = 'SELECT c.*, u.firstname AS owner_firstname, u.lastname AS owner_lastname,
                          (SELECT COUNT(*) FROM course_members cm WHERE cm.course_id = c.id) AS member_count
                   FROM courses c LEFT JOIN users u ON u.id = c.owner_id';
        $order = ' ORDER BY c.is_archived, c.school_year DESC, c.grade_level, c.name';

        if ($this->seesAllCourses()) {
            return $this->db->fetchAll($select . ' WHERE 1 = 1' . $archived . $order);
        }
        $userId = $this->auth->id();
        if ($userId === null) {
            return [];
        }

        return $this->db->fetchAll(
            $select . ' WHERE (c.owner_id = ? OR c.id IN (SELECT course_id FROM course_teachers WHERE user_id = ?))'
            . $archived . $order,
            [$userId, $userId],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $courseId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM courses WHERE id = ?', [$courseId]);
    }

    /** @return list<array<string, mixed>> */
    public function members(int $courseId): array
    {
        return $this->db->fetchAll(
            'SELECT u.id, u.username, u.firstname, u.lastname, u.is_active, u.accommodation_percent, u.card_issued_at
             FROM course_members cm JOIN users u ON u.id = cm.user_id
             WHERE cm.course_id = ? ORDER BY u.lastname, u.firstname, u.username',
            [$courseId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function teachers(int $courseId): array
    {
        return $this->db->fetchAll(
            'SELECT u.id, u.username, u.firstname, u.lastname
             FROM course_teachers ct JOIN users u ON u.id = ct.user_id
             WHERE ct.course_id = ? ORDER BY u.lastname, u.firstname',
            [$courseId],
        );
    }

    /** Kurse einer Schüler:in (Name, Id). @return list<array<string, mixed>> */
    public function coursesOfStudent(int $studentId): array
    {
        return $this->db->fetchAll(
            'SELECT c.id, c.name, c.school_year, c.is_archived
             FROM course_members cm JOIN courses c ON c.id = cm.course_id
             WHERE cm.user_id = ? ORDER BY c.is_archived, c.school_year DESC, c.name',
            [$studentId],
        );
    }

    // ---------- Schreiben ----------

    /**
     * Legt einen Kurs an. Gibt die neue ID zurück.
     *
     * @throws \InvalidArgumentException bei ungültigen Werten
     */
    public function create(string $name, int $gradeLevel, string $schoolYear, string $subject, ?int $ownerId, ?int $scaleId = null): int
    {
        $name = trim($name);
        $subject = trim($subject) !== '' ? trim($subject) : 'Informatik';
        $error = self::validateFields($name, $gradeLevel, $schoolYear, $subject);
        if ($error !== null) {
            throw new \InvalidArgumentException($error);
        }
        if ($this->nameTaken($name, $schoolYear, null)) {
            throw new \InvalidArgumentException('Im Schuljahr ' . $schoolYear . ' gibt es schon einen Kurs „' . $name . '“.');
        }

        $this->db->run(
            'INSERT INTO courses (name, grade_level, school_year, subject, grading_scale_id, owner_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$name, $gradeLevel, $schoolYear, $subject, $scaleId, $ownerId],
        );

        return $this->db->lastInsertId();
    }

    public function nameTaken(string $name, string $schoolYear, ?int $exceptId): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM courses WHERE name = ? AND school_year = ? AND id <> ? LIMIT 1',
            [$name, $schoolYear, $exceptId ?? 0],
        ) !== null;
    }

    public static function validateFields(string $name, int $gradeLevel, string $schoolYear, string $subject): ?string
    {
        if ($name === '' || mb_strlen($name) > 100) {
            return 'Der Kursname ist Pflicht und darf höchstens 100 Zeichen lang sein.';
        }
        if ($gradeLevel < self::MIN_GRADE || $gradeLevel > self::MAX_GRADE) {
            return 'Die Klassenstufe muss zwischen 5 und 13 liegen.';
        }
        if (!self::isValidSchoolYear($schoolYear)) {
            return 'Das Schuljahr braucht das Format 2026/27.';
        }
        if (mb_strlen($subject) > 60) {
            return 'Das Fach darf höchstens 60 Zeichen lang sein.';
        }

        return null;
    }

    /** Fügt eine Schüler:in hinzu (idempotent). Gibt true zurück, wenn neu. */
    public function addMember(int $courseId, int $studentId): bool
    {
        $stmt = $this->db->run(
            "INSERT IGNORE INTO course_members (course_id, user_id)
             SELECT ?, id FROM users WHERE id = ? AND role = 'student'",
            [$courseId, $studentId],
        );

        return $stmt->rowCount() > 0;
    }

    public function removeMember(int $courseId, int $studentId): void
    {
        $this->db->run('DELETE FROM course_members WHERE course_id = ? AND user_id = ?', [$courseId, $studentId]);
    }

    /**
     * Ersetzt die weiteren Lehrkräfte (nur echte Lehrkräfte/Admins, nicht die Hauptlehrkraft).
     *
     * @param list<int> $teacherIds
     */
    public function setTeachers(int $courseId, array $teacherIds, ?int $ownerId): void
    {
        $this->db->transaction(function () use ($courseId, $teacherIds, $ownerId): void {
            $this->db->run('DELETE FROM course_teachers WHERE course_id = ?', [$courseId]);
            foreach (array_unique($teacherIds) as $id) {
                if ($id <= 0 || $id === $ownerId) {
                    continue;
                }
                $this->db->run(
                    "INSERT IGNORE INTO course_teachers (course_id, user_id)
                     SELECT ?, id FROM users WHERE id = ? AND role IN ('teacher','admin')",
                    [$courseId, $id],
                );
            }
        });
    }
}
