<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

/**
 * Klausuren: Anlegen, Stammdaten, Einstellungen, Statuswechsel draft/ready,
 * Löschen, Kopieren, Lehrkräfte. Rechte prüft der Controller (ExamAccess).
 */
final class ExamService
{
    public const MODES = ['exam' => 'Klausur', 'practice' => 'Übung', 'probe' => 'Probe (Technik-Check)'];
    public const STATUS_LABELS = [
        'draft' => 'Entwurf',
        'ready' => 'Bereit',
        'lobby' => 'Warteraum',
        'running' => 'Läuft',
        'paused' => 'Pausiert',
        'finished' => 'Beendet',
        'grading' => 'In Korrektur',
        'returned' => 'Zurückgegeben',
        'archived' => 'Archiviert',
    ];
    /** Status, in denen Stammdaten und Einstellungen änderbar sind. */
    public const EDITABLE_STATUSES = ['draft', 'ready'];
    public const DELETABLE_STATUSES = ['draft', 'ready', 'archived'];
    /** Gut lesbare Zeichen für den Klausurcode (ohne 0/O, 1/I/L). */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly Database $db,
        private readonly TaskService $tasks,
        private readonly Materials $materials,
    ) {
    }

    public static function isEditable(array $exam): bool
    {
        return in_array((string) $exam['status'], self::EDITABLE_STATUSES, true);
    }

    // ---------- Anlegen & Stammdaten ----------

    /**
     * Prüft und normalisiert die Stammdaten aus einem Formular.
     *
     * @param array<string, mixed> $in
     * @return array{title: string, description: ?string, mode: string, course_id: ?int, room_id: ?int, duration_minutes: ?int, scheduled_at: ?string}
     */
    public function normalizeMeta(array $in): array
    {
        $title = trim(mb_substr((string) ($in['title'] ?? ''), 0, 200));
        if ($title === '') {
            throw new \InvalidArgumentException('Bitte gib einen Titel an.');
        }
        $mode = (string) ($in['mode'] ?? 'exam');
        if (!isset(self::MODES[$mode])) {
            throw new \InvalidArgumentException('Ungültiger Modus.');
        }

        $courseId = ($in['course_id'] ?? '') === '' ? null : (int) $in['course_id'];
        if ($courseId !== null && $this->db->fetchValue('SELECT id FROM courses WHERE id = ?', [$courseId]) === null) {
            throw new \InvalidArgumentException('Der Kurs existiert nicht.');
        }
        $roomId = ($in['room_id'] ?? '') === '' ? null : (int) $in['room_id'];
        if ($roomId !== null && $this->db->fetchValue('SELECT id FROM rooms WHERE id = ?', [$roomId]) === null) {
            throw new \InvalidArgumentException('Der Raum existiert nicht.');
        }

        $duration = trim((string) ($in['duration_minutes'] ?? ''));
        if ($duration === '') {
            if ($mode !== 'practice') {
                throw new \InvalidArgumentException('Bitte gib die Dauer in Minuten an (nur bei Übungen darf sie fehlen).');
            }
            $durationMinutes = null;
        } else {
            $durationMinutes = (int) $duration;
            if ($durationMinutes < 1 || $durationMinutes > 600) {
                throw new \InvalidArgumentException('Die Dauer muss zwischen 1 und 600 Minuten liegen.');
            }
        }

        $scheduled = trim((string) ($in['scheduled_at'] ?? ''));
        $scheduledAt = null;
        if ($scheduled !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $scheduled)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $scheduled)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i', $scheduled);
            if ($parsed === false) {
                throw new \InvalidArgumentException('Der Termin ist ungültig.');
            }
            $scheduledAt = $parsed->format('Y-m-d H:i:00');
        }

        $description = trim((string) ($in['description'] ?? ''));

        return [
            'title' => $title,
            'description' => $description === '' ? null : mb_substr($description, 0, 5000),
            'mode' => $mode,
            'course_id' => $courseId,
            'room_id' => $roomId,
            'duration_minutes' => $durationMinutes,
            'scheduled_at' => $scheduledAt,
        ];
    }

    /** @param array<string, mixed> $in */
    public function create(array $in, int $ownerId): int
    {
        $meta = $this->normalizeMeta($in);
        $settings = ExamSettings::defaults($meta['mode']);
        $this->db->run(
            'INSERT INTO exams (title, description, course_id, owner_id, room_id, mode, status, duration_minutes, scheduled_at, settings)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $meta['title'], $meta['description'], $meta['course_id'], $ownerId, $meta['room_id'], $meta['mode'],
                'draft', $meta['duration_minutes'], $meta['scheduled_at'],
                json_encode($settings, JSON_UNESCAPED_UNICODE),
            ],
        );

        return $this->db->lastInsertId();
    }

    /**
     * Stammdaten ändern (nur draft/ready).
     *
     * @param array<string, mixed> $exam
     * @param array<string, mixed> $in
     */
    public function updateMeta(array $exam, array $in): void
    {
        $this->assertEditable($exam);
        $meta = $this->normalizeMeta($in);
        $this->db->run(
            'UPDATE exams SET title = ?, description = ?, course_id = ?, room_id = ?, mode = ?, duration_minutes = ?, scheduled_at = ? WHERE id = ?',
            [$meta['title'], $meta['description'], $meta['course_id'], $meta['room_id'], $meta['mode'], $meta['duration_minutes'], $meta['scheduled_at'], (int) $exam['id']],
        );
    }

    /**
     * Einstellungen speichern: Stammdaten, ExamSettings, Klausurcode, Archiv-Freigabe.
     *
     * @param array<string, mixed> $exam
     * @param array<string, mixed> $in
     */
    public function saveSettings(array $exam, array $in): void
    {
        $this->assertEditable($exam);
        $meta = $this->normalizeMeta($in);
        $settings = ExamSettings::normalize(is_array($in['settings'] ?? null) ? $in['settings'] : [], $meta['mode']);

        $code = $exam['access_code'] !== null ? (string) $exam['access_code'] : null;
        if ($settings['require_access_code']) {
            $code = $code ?: $this->generateAccessCode();
        }

        $this->db->run(
            'UPDATE exams SET title = ?, description = ?, course_id = ?, room_id = ?, mode = ?, duration_minutes = ?, scheduled_at = ?,
                settings = ?, access_code = ?, shared_in_archive = ? WHERE id = ?',
            [
                $meta['title'], $meta['description'], $meta['course_id'], $meta['room_id'], $meta['mode'], $meta['duration_minutes'], $meta['scheduled_at'],
                json_encode($settings, JSON_UNESCAPED_UNICODE), $code,
                !empty($in['shared_in_archive']) && filter_var($in['shared_in_archive'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                (int) $exam['id'],
            ],
        );
    }

    /** Neuer, gut lesbarer Klausurcode (6 Zeichen). */
    public function generateAccessCode(): string
    {
        $code = '';
        $max = strlen(self::CODE_ALPHABET) - 1;
        for ($i = 0; $i < 6; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /** @param array<string, mixed> $exam */
    public function regenerateAccessCode(array $exam): string
    {
        $this->assertEditable($exam);
        $code = $this->generateAccessCode();
        $this->db->run('UPDATE exams SET access_code = ? WHERE id = ?', [$code, (int) $exam['id']]);

        return $code;
    }

    private function assertEditable(array $exam): void
    {
        if (!self::isEditable($exam)) {
            throw new HttpException(409, 'Die Klausur ist gestartet oder abgeschlossen: Einstellungen sind nur noch lesbar.');
        }
    }

    // ---------- Status ----------

    /**
     * Fertigstellen (draft -> ready). Fehler verhindern den Wechsel.
     *
     * @param array<string, mixed> $exam
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function markReady(array $exam): array
    {
        if ($exam['status'] !== 'draft') {
            throw new HttpException(409, 'Nur Entwürfe können fertiggestellt werden.');
        }
        $report = $exam['mode'] === 'probe' ? ['errors' => [], 'warnings' => []] : $this->tasks->check((int) $exam['id']);
        if ((string) $exam['mode'] === 'exam' && $exam['duration_minutes'] === null) {
            $report['errors'][] = 'Die Dauer fehlt.';
        }
        if ($report['errors'] === []) {
            $this->db->run("UPDATE exams SET status = 'ready' WHERE id = ? AND status = 'draft'", [(int) $exam['id']]);
        }

        return $report;
    }

    /** @param array<string, mixed> $exam */
    public function backToDraft(array $exam): void
    {
        if ($exam['status'] !== 'ready') {
            throw new HttpException(409, 'Nur bereite Klausuren können zurück in den Entwurf.');
        }
        $this->db->run("UPDATE exams SET status = 'draft' WHERE id = ? AND status = 'ready'", [(int) $exam['id']]);
    }

    // ---------- Löschen & Kopieren ----------

    /** @param array<string, mixed> $exam */
    public function delete(array $exam): void
    {
        if (!in_array((string) $exam['status'], self::DELETABLE_STATUSES, true)) {
            throw new HttpException(409, 'Eine gestartete oder laufende Klausur kann nicht gelöscht werden. Schließe sie ab und archiviere sie zuerst.');
        }
        $id = (int) $exam['id'];
        $this->materials->deleteFilesOfExam($id);
        (new LiveStore())->clearExam($id);
        $this->db->run('DELETE FROM exams WHERE id = ?', [$id]);
    }

    /**
     * Ganze Klausur als neuen Entwurf kopieren (Aufgaben, Lösungen, Material, Einstellungen).
     * Ohne Teilnahmen, Lehrkräfte und Termin.
     *
     * @param array<string, mixed> $exam
     */
    public function copy(array $exam, int $ownerId, ?string $title = null): int
    {
        $sourceId = (int) $exam['id'];

        return (int) $this->db->transaction(function () use ($exam, $ownerId, $title, $sourceId): int {
            $this->db->run(
                'INSERT INTO exams (title, description, course_id, owner_id, room_id, mode, status, duration_minutes, settings, copied_from_exam_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    mb_substr($title ?? ((string) $exam['title'] . ' (Kopie)'), 0, 200), $exam['description'], $exam['course_id'], $ownerId,
                    $exam['room_id'], $exam['mode'], 'draft', $exam['duration_minutes'], $exam['settings'], $sourceId,
                ],
            );
            $newId = $this->db->lastInsertId();

            // Alle Materialien kopieren (auch nicht im Text verwendete, z. B. Hilfsmittel).
            $materialMap = [];
            $sourceMaterials = $this->materials->forExam($sourceId);
            foreach ($sourceMaterials as $row) {
                $materialMap[(int) $row['id']] = (int) $this->materials->copyTo($row, $newId, null)['id'];
            }

            $taskMap = [];
            $tops = $this->db->fetchAll('SELECT id FROM tasks WHERE exam_id = ? AND parent_id IS NULL ORDER BY position, id', [$sourceId]);
            foreach ($tops as $top) {
                $this->copyTopLevel((int) $top['id'], $newId, $materialMap, $taskMap);
            }

            // Zuordnung Material -> Aufgabe übernehmen.
            foreach ($sourceMaterials as $row) {
                if ($row['task_id'] !== null && isset($taskMap[(int) $row['task_id']])) {
                    $this->db->run('UPDATE exam_materials SET task_id = ? WHERE id = ?', [$taskMap[(int) $row['task_id']], $materialMap[(int) $row['id']]]);
                }
            }

            return $newId;
        });
    }

    /**
     * @param array<int, int> $materialMap
     * @param array<int, int> $taskMap
     */
    private function copyTopLevel(int $taskId, int $newExamId, array &$materialMap, array &$taskMap): void
    {
        $this->tasks->copyTask($taskId, $newExamId, null, true, $materialMap, $taskMap);
    }

    // ---------- Lehrkräfte ----------

    /**
     * @param array<string, mixed> $exam
     */
    public function addStaff(array $exam, int $userId, int $addedBy): void
    {
        $user = $this->db->fetchOne("SELECT id, role, is_active FROM users WHERE id = ? AND role IN ('teacher','admin')", [$userId]);
        if ($user === null || (int) $user['is_active'] !== 1) {
            throw new \InvalidArgumentException('Diese Lehrkraft gibt es nicht oder das Konto ist deaktiviert.');
        }
        if ($userId === (int) $exam['owner_id']) {
            throw new \InvalidArgumentException('Die Hauptlehrkraft ist bereits zugeordnet.');
        }
        $this->db->run(
            'INSERT IGNORE INTO exam_staff (exam_id, user_id, added_by) VALUES (?, ?, ?)',
            [(int) $exam['id'], $userId, $addedBy],
        );
    }

    public function updateStaff(int $examId, int $userId, bool $viewSolutions, bool $grade): void
    {
        $this->db->run(
            'UPDATE exam_staff SET can_view_solutions = ?, can_grade = ? WHERE exam_id = ? AND user_id = ?',
            [$viewSolutions ? 1 : 0, $grade ? 1 : 0, $examId, $userId],
        );
    }

    public function removeStaff(int $examId, int $userId): void
    {
        $this->db->run('DELETE FROM exam_staff WHERE exam_id = ? AND user_id = ?', [$examId, $userId]);
    }

    /** @return list<array<string, mixed>> */
    public function staffOf(int $examId): array
    {
        return $this->db->fetchAll(
            'SELECT s.*, u.firstname, u.lastname, u.username FROM exam_staff s JOIN users u ON u.id = s.user_id
             WHERE s.exam_id = ? ORDER BY u.lastname, u.firstname',
            [$examId],
        );
    }

    // ---------- Listen & Kennzahlen ----------

    /**
     * Klausurliste der übergebenen (sichtbaren) IDs mit Filtern.
     *
     * @param list<int> $visibleIds
     * @param array{course?: int, status?: string, mode?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function listFor(array $visibleIds, array $filters = [], ?int $userId = null): array
    {
        if ($visibleIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($visibleIds), '?'));
        $sql = "SELECT e.*, c.name AS course_name, u.firstname AS owner_firstname, u.lastname AS owner_lastname,
                    (SELECT COUNT(*) FROM tasks t WHERE t.exam_id = e.id AND t.answer_type IS NOT NULL) AS task_count,
                    (SELECT COALESCE(SUM(t.points), 0) FROM tasks t WHERE t.exam_id = e.id AND t.answer_type IS NOT NULL) AS total_points
                FROM exams e
                LEFT JOIN courses c ON c.id = e.course_id
                LEFT JOIN users u ON u.id = e.owner_id
                WHERE e.id IN ({$in})";
        $params = array_values($visibleIds);
        if (!empty($filters['course'])) {
            $sql .= ' AND e.course_id = ?';
            $params[] = (int) $filters['course'];
        }
        if (!empty($filters['status']) && isset(self::STATUS_LABELS[$filters['status']])) {
            $sql .= ' AND e.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['mode']) && isset(self::MODES[$filters['mode']])) {
            $sql .= ' AND e.mode = ?';
            $params[] = $filters['mode'];
        }
        $sql .= ' ORDER BY COALESCE(e.scheduled_at, e.created_at) DESC, e.id DESC';

        $rows = $this->db->fetchAll($sql, $params);
        foreach ($rows as &$row) {
            $row['is_owner'] = $userId !== null && (int) $row['owner_id'] === $userId;
        }

        return $rows;
    }

    /**
     * Aufgabenzahl und Gesamtpunkte (nur Aufgaben mit Antworttyp zählen).
     *
     * @return array{tasks: int, points: float}
     */
    public function totals(int $examId): array
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS n, COALESCE(SUM(points), 0) AS p FROM tasks WHERE exam_id = ? AND answer_type IS NOT NULL',
            [$examId],
        );

        return ['tasks' => (int) $row['n'], 'points' => (float) $row['p']];
    }

    /**
     * Wählbare Kurse: Admins und Lehrkräfte mit ALLE_KURSE alle, sonst eigene.
     *
     * @return list<array<string, mixed>>
     */
    public function courseOptions(int $userId, bool $all): array
    {
        if ($all) {
            return $this->db->fetchAll('SELECT id, name, school_year, grade_level FROM courses WHERE is_archived = 0 ORDER BY grade_level, name');
        }

        return $this->db->fetchAll(
            'SELECT id, name, school_year, grade_level FROM courses WHERE is_archived = 0 AND owner_id = ? ORDER BY grade_level, name',
            [$userId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function roomOptions(): array
    {
        return $this->db->fetchAll('SELECT id, name FROM rooms ORDER BY name');
    }
}
