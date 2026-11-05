<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Aufbewahrungsfristen: welche Klausuren sind überfällig, Protokolle und Klausuren löschen.
 *
 * Zwei Fristen (Einstellungen retention_answers_months, retention_logs_months):
 *  - Protokolle (participation_events, answer_ops, exam_requests) nach retention_logs_months,
 *    Bezug finished_at, sonst returned_at, sonst updated_at.
 *  - Ganze Klausur samt Antworten, Noten und Dateien nach retention_answers_months,
 *    Bezug returned_at, sonst finished_at, sonst updated_at.
 * Laufende Klausuren (Warteraum, läuft, pausiert) sind nie fällig.
 */
final class Retention
{
    public const DEFAULT_ANSWERS_MONTHS = 24;
    public const DEFAULT_LOGS_MONTHS = 6;
    public const MIN_MONTHS = 1;
    public const MAX_MONTHS = 120;

    public function __construct(
        private readonly Database $db,
        private readonly Settings $settings,
    ) {
    }

    public function answersMonths(): int
    {
        return $this->clamp($this->settings->getInt('retention_answers_months', self::DEFAULT_ANSWERS_MONTHS), self::DEFAULT_ANSWERS_MONTHS);
    }

    public function logsMonths(): int
    {
        return $this->clamp($this->settings->getInt('retention_logs_months', self::DEFAULT_LOGS_MONTHS), self::DEFAULT_LOGS_MONTHS);
    }

    /**
     * Klausuren mit überschrittener Frist, älteste zuerst.
     *
     * Felder: id, title, status, course_name, answers_ref, logs_ref, answers_due (bool),
     * logs_due (bool, nur wenn noch Protokolle vorhanden sind), log_rows, participants.
     *
     * @return list<array<string, mixed>>
     */
    public function dueExams(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now');
        $answersLimit = $now->modify('-' . $this->answersMonths() . ' months')->format('Y-m-d H:i:s');
        $logsLimit = $now->modify('-' . $this->logsMonths() . ' months')->format('Y-m-d H:i:s');

        $rows = $this->db->fetchAll(
            "SELECT e.id, e.title, e.status, c.name AS course_name,
                    COALESCE(e.returned_at, e.finished_at, e.updated_at) AS answers_ref,
                    COALESCE(e.finished_at, e.returned_at, e.updated_at) AS logs_ref,
                    (SELECT COUNT(*) FROM participation_events pe WHERE pe.exam_id = e.id)
                  + (SELECT COUNT(*) FROM exam_requests er WHERE er.exam_id = e.id)
                  + (SELECT COUNT(*) FROM answer_ops ao JOIN participations p ON p.id = ao.participation_id WHERE p.exam_id = e.id) AS log_rows,
                    (SELECT COUNT(*) FROM participations p2 WHERE p2.exam_id = e.id) AS participants
             FROM exams e
             LEFT JOIN courses c ON c.id = e.course_id
             WHERE e.status NOT IN ('lobby','running','paused')
             ORDER BY COALESCE(e.returned_at, e.finished_at, e.updated_at), e.id",
        );

        $due = [];
        foreach ($rows as $row) {
            $answersDue = (string) $row['answers_ref'] <= $answersLimit;
            $logsDue = (string) $row['logs_ref'] <= $logsLimit && (int) $row['log_rows'] > 0;
            if (!$answersDue && !$logsDue) {
                continue;
            }
            $row['answers_due'] = $answersDue;
            $row['logs_due'] = $logsDue;
            $due[] = $row;
        }

        return $due;
    }

    public function countDue(?\DateTimeImmutable $now = null): int
    {
        return count($this->dueExams($now));
    }

    /** Liefert die Zeile einer fälligen Klausur oder null (Schutz gegen manipulierte Formulare). */
    public function findDue(int $examId, ?\DateTimeImmutable $now = null): ?array
    {
        foreach ($this->dueExams($now) as $row) {
            if ((int) $row['id'] === $examId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Löscht Ereignisse, Antwortschritte (Replay) und Anfragen einer Klausur.
     *
     * @return array{events: int, ops: int, requests: int}
     */
    public function deleteLogs(int $examId): array
    {
        return $this->db->transaction(function () use ($examId): array {
            $events = $this->db->run('DELETE FROM participation_events WHERE exam_id = ?', [$examId])->rowCount();
            $ops = $this->db->run(
                'DELETE FROM answer_ops WHERE participation_id IN (SELECT id FROM participations WHERE exam_id = ?)',
                [$examId],
            )->rowCount();
            $requests = $this->db->run('DELETE FROM exam_requests WHERE exam_id = ?', [$examId])->rowCount();

            return ['events' => $events, 'ops' => $ops, 'requests' => $requests];
        });
    }

    /**
     * Löscht eine Klausur vollständig: Datenbankzeilen (Aufgaben, Teilnahmen, Antworten, Noten per
     * Kaskade) und danach die Dateien unter uploads/materials und uploads/answers.
     *
     * @return array{files: int, missing: int}
     */
    public function deleteExam(int $examId, string $uploadsDir): array
    {
        $materials = $this->db->fetchAll('SELECT stored_name FROM exam_materials WHERE exam_id = ?', [$examId]);
        // Antwortdateien über denselben Dienst wie ExamService::delete und UsersController::destroy.
        $answers = (new AnswerFiles($this->db, $uploadsDir))->deleteFilesOfExam($examId);

        $this->db->run('DELETE FROM exams WHERE id = ?', [$examId]);
        (new LiveStore())->clearExam($examId);

        $files = $answers['files'];
        $missing = $answers['missing'];
        foreach ($materials as $row) {
            $name = (string) $row['stored_name'];
            // Nur einfache Dateinamen, nie Pfade.
            if ($name === '' || $name !== basename($name) || str_contains($name, '..')) {
                $missing++;
                continue;
            }
            $path = rtrim($uploadsDir, '/\\') . '/' . Materials::SUBDIR . '/' . $name;
            if (is_file($path) && @unlink($path)) {
                $files++;
            } else {
                $missing++;
            }
        }

        return ['files' => $files, 'missing' => $missing];
    }

    // ---------- intern ----------

    private function clamp(int $value, int $default): int
    {
        return $value >= self::MIN_MONTHS && $value <= self::MAX_MONTHS ? $value : $default;
    }
}
