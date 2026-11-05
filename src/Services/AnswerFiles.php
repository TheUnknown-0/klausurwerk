<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

/**
 * Hochgeladene Antwortdateien (Zeichenaufgaben: PNG, JPG, PDF) unter uploads/answers.
 *
 * Die Zeilen in answer_files verschwinden per Kaskade mit Teilnahme, Klausur oder Konto; die
 * Dateien auf der Platte nicht. Vor jedem Löschen einer Klausur oder eines Schülerkontos
 * müssen sie deshalb hier entfernt werden (ExamService::delete, UsersController::destroy,
 * Retention::deleteExam).
 */
final class AnswerFiles
{
    public const SUBDIR = 'answers';

    private readonly Uploads $uploads;

    public function __construct(private readonly Database $db, string $uploadDir)
    {
        $this->uploads = new Uploads($uploadDir);
    }

    /**
     * Dateien aller Teilnahmen einer Klausur löschen (vor dem Löschen der Klausur).
     *
     * @return array{files: int, missing: int}
     */
    public function deleteFilesOfExam(int $examId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT af.stored_name FROM answer_files af
             JOIN participations p ON p.id = af.participation_id WHERE p.exam_id = ?',
            [$examId],
        );

        return $this->deleteNames(array_column($rows, 'stored_name'));
    }

    /**
     * Dateien aller Teilnahmen einer Person löschen (vor dem Löschen des Kontos).
     *
     * @return array{files: int, missing: int}
     */
    public function deleteFilesOfUser(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT af.stored_name FROM answer_files af
             JOIN participations p ON p.id = af.participation_id WHERE p.user_id = ?',
            [$userId],
        );

        return $this->deleteNames(array_column($rows, 'stored_name'));
    }

    /**
     * @param list<mixed> $names
     * @return array{files: int, missing: int}
     */
    private function deleteNames(array $names): array
    {
        $files = 0;
        $missing = 0;
        foreach ($names as $name) {
            try {
                $path = $this->uploads->path(self::SUBDIR, (string) $name);
            } catch (HttpException) {
                $missing++;
                continue;
            }
            if (is_file($path) && @unlink($path)) {
                $files++;
            } else {
                $missing++;
            }
        }

        return ['files' => $files, 'missing' => $missing];
    }
}
