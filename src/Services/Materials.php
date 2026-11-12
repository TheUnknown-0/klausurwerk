<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

/**
 * Klausur-Material (Bilder, PDF, Audio, Video): Ablage über Services\Uploads im Unterordner
 * 'materials', Metadaten in exam_materials. Auslieferung übernimmt das Live-Modul
 * (pathFor() liefert den absoluten Pfad).
 */
final class Materials
{
    public const SUBDIR = 'materials';
    public const EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'mp3', 'm4a', 'ogg', 'wav', 'mp4', 'webm'];
    public const MAX_BYTES = 20 * 1024 * 1024;
    /** Audio und Video dürfen größer sein (php.ini: upload_max_filesize 200M). */
    public const MAX_MEDIA_BYTES = 190 * 1024 * 1024;
    /** Einheitlicher MIME-Typ je Endung für Audio/Video (finfo schwankt, der Browser braucht ihn). */
    public const MEDIA_MIME = [
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
        'mp4' => 'video/mp4', 'webm' => 'video/webm',
    ];

    private static function maxBytesFor(string $name): int
    {
        return isset(self::MEDIA_MIME[strtolower(pathinfo($name, PATHINFO_EXTENSION))]) ? self::MAX_MEDIA_BYTES : self::MAX_BYTES;
    }

    private readonly Uploads $uploads;

    public function __construct(private readonly Database $db, string $uploadDir)
    {
        $this->uploads = new Uploads($uploadDir);
    }

    /**
     * Speichert eine hochgeladene Datei ($_FILES-Eintrag).
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed> Zeile aus exam_materials
     */
    public function store(int $examId, array $file, ?int $taskId, bool $isAid): array
    {
        $stored = $this->uploads->store($file, self::SUBDIR, self::EXTENSIONS, self::maxBytesFor((string) ($file['name'] ?? '')));

        return $this->insert($examId, $taskId, $isAid, $stored);
    }

    /**
     * Wie store(), aber aus einer lokalen Datei (Import, Kopie).
     *
     * @return array<string, mixed>
     */
    public function storeLocal(int $examId, string $path, string $originalName, ?int $taskId, bool $isAid, string $playPolicy = 'teacher', int $playLimit = 2): array
    {
        $stored = $this->uploads->storeLocal($path, $originalName, self::SUBDIR, self::EXTENSIONS, self::maxBytesFor($originalName));

        return $this->insert($examId, $taskId, $isAid, $stored, $playPolicy, $playLimit);
    }

    /** Abspielregel für Audio/Video setzen. @param array<string, mixed> $row */
    public function setPlayPolicy(array $row, string $policy, int $limit): void
    {
        $this->db->run('UPDATE exam_materials SET play_policy = ?, play_limit = ? WHERE id = ?', [
            MediaPlays::cleanPolicy($policy), MediaPlays::cleanLimit($limit), (int) $row['id'],
        ]);
    }

    /**
     * @param array{filename: string, original_name: string, mime: string, size: int} $stored
     * @return array<string, mixed>
     */
    private function insert(int $examId, ?int $taskId, bool $isAid, array $stored, string $playPolicy = 'teacher', int $playLimit = 2): array
    {
        $ext = strtolower(pathinfo($stored['filename'], PATHINFO_EXTENSION));
        $mime = self::MEDIA_MIME[$ext] ?? $stored['mime'];
        try {
            $this->db->run(
                'INSERT INTO exam_materials (exam_id, task_id, original_name, stored_name, mime, size_bytes, is_aid, play_policy, play_limit)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $examId,
                    $taskId,
                    mb_substr($stored['original_name'], 0, 255),
                    $stored['filename'],
                    $mime,
                    $stored['size'],
                    $isAid ? 1 : 0,
                    MediaPlays::cleanPolicy($playPolicy),
                    MediaPlays::cleanLimit($playLimit),
                ],
            );
        } catch (\Throwable $e) {
            $this->uploads->delete(self::SUBDIR, $stored['filename']);
            throw $e;
        }

        return $this->find($this->db->lastInsertId()) ?? throw new HttpException(500);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM exam_materials WHERE id = ?', [$id]);
    }

    /** Absoluter Pfad der Datei (mit Schutz gegen Path-Traversal). @param array<string, mixed> $row */
    public function pathFor(array $row): string
    {
        return $this->uploads->path(self::SUBDIR, (string) $row['stored_name']);
    }

    /** Entfernt Datei und Zeile. @param array<string, mixed> $row */
    public function delete(array $row): void
    {
        $this->db->run('DELETE FROM exam_materials WHERE id = ?', [(int) $row['id']]);
        $this->uploads->delete(self::SUBDIR, (string) $row['stored_name']);
    }

    /** @return list<array<string, mixed>> */
    public function forExam(int $examId): array
    {
        return $this->db->fetchAll('SELECT * FROM exam_materials WHERE exam_id = ? ORDER BY id', [$examId]);
    }

    /** Dateien aller Materialien einer Klausur vom Datenträger löschen (vor dem Löschen der Klausur). */
    public function deleteFilesOfExam(int $examId): void
    {
        foreach ($this->forExam($examId) as $row) {
            $this->uploads->delete(self::SUBDIR, (string) $row['stored_name']);
        }
    }

    /**
     * Kopiert ein Material in eine andere Klausur (neue Datei, neue Zeile).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function copyTo(array $row, int $targetExamId, ?int $taskId): array
    {
        return $this->storeLocal(
            $targetExamId,
            $this->pathFor($row),
            (string) $row['original_name'],
            $taskId,
            (int) $row['is_aid'] === 1,
            (string) ($row['play_policy'] ?? 'teacher'),
            (int) ($row['play_limit'] ?? 2),
        );
    }
}
