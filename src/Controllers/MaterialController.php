<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\ExamAccess;
use App\Services\Materials;
use App\Services\MediaPlays;

/**
 * Auslieferung von Klausur-Material (Bilder, PDF, Audio, Video) über eine geschützte Route.
 *
 * Teilnehmende bekommen Material erst ab dem Start der Klausur (vorher 403), Lehrkräfte mit
 * Fähigkeit "view" jederzeit. Der Pfad kommt aus Materials::pathFor() und wird nie aus der URL gebaut.
 *
 * Audio/Video für Schüler:innen nach Abspielregel (Services\MediaPlays): 'teacher' gar nicht,
 * 'limited' nur mit Einmal-Schlüssel (?t=…) aus POST /api/klausur/{id}/medien/{mid}/abspielen.
 * Audio/Video unterstützt Range-Anfragen (Vorspulen im Pult).
 */
final class MaterialController extends Controller
{
    private const INLINE_TYPES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml', 'application/pdf',
        'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'video/mp4', 'video/webm',
    ];

    /** GET /klausur/{id}/material/{mid} */
    public function show(array $params): string
    {
        $user = $this->requireLogin();
        $examId = (int) $params['id'];
        $materialId = (int) $params['mid'];

        $exam = $this->ctx->db->fetchOne('SELECT * FROM exams WHERE id = ?', [$examId]);
        if ($exam === null) {
            throw new HttpException(404, 'Material nicht gefunden.');
        }

        $participation = null;
        if ($this->ctx->auth->isStaff()) {
            $this->ctx->exams->require($examId, ExamAccess::CAP_VIEW);
        } elseif ($this->ctx->auth->isStudent()) {
            $participation = $this->requireParticipant($exam, $user);
        } else {
            throw new HttpException(403);
        }

        $row = $this->ctx->db->fetchOne('SELECT * FROM exam_materials WHERE id = ? AND exam_id = ?', [$materialId, $examId]);
        if ($row === null) {
            throw new HttpException(404, 'Material nicht gefunden.');
        }
        $media = MediaPlays::isMedia((string) $row['mime']);
        if ($participation !== null && $media) {
            $this->checkPlayPolicy($row, $participation);
        }
        $materials = new Materials($this->ctx->db, (string) $this->ctx->config['uploads']['dir']);
        $path = $materials->pathFor($row);
        if (!is_file($path)) {
            throw new HttpException(404, 'Material nicht gefunden.');
        }

        // Die Sitzung wird nicht mehr gebraucht; große Dateien sollen keine parallelen Anfragen blockieren.
        $this->ctx->session->release();
        $this->send($path, (string) $row['mime'], (string) $row['original_name'], $media && $participation === null);
    }

    /**
     * Schüler:innen: Mitglied des Kurses, mit Teilnahme, Klausur gestartet; bei SEB-Pflicht gültiger Header.
     *
     * @param array<string, mixed> $exam
     * @param array<string, mixed> $user
     * @return array<string, mixed> Teilnahme
     */
    private function requireParticipant(array $exam, array $user): array
    {
        $examId = (int) $exam['id'];
        $participation = $this->ctx->db->fetchOne(
            'SELECT * FROM participations WHERE exam_id = ? AND user_id = ?',
            [$examId, (int) $user['id']],
        );
        if ($participation === null || !in_array((string) $exam['status'], ['running', 'paused', 'finished', 'grading', 'returned'], true)) {
            throw new HttpException(403, 'Das Material ist erst ab dem Start der Klausur verfügbar.');
        }
        if (in_array((string) $exam['status'], ['running', 'paused'], true)) {
            (new \App\Services\LiveServices($this->ctx))->seb()->enforce($exam, $participation);
        }

        return $participation;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $participation
     */
    private function checkPlayPolicy(array $row, array $participation): void
    {
        $policy = MediaPlays::cleanPolicy($row['play_policy'] ?? 'teacher');
        if ($policy === 'teacher') {
            throw new HttpException(403, 'Diese Aufnahme spielt die Lehrkraft für alle ab.');
        }
        if ($policy === 'limited') {
            $token = (string) ($_GET['t'] ?? '');
            if (!(new MediaPlays($this->ctx->db))->redeem((int) $participation['id'], (int) $row['id'], $token)) {
                throw new HttpException(403, 'Bitte starte die Aufnahme über den Abspielknopf.');
            }
        }
    }

    /** Streamt die Datei mit sicheren Headern (inline nur für Bilder, PDF, Audio, Video). */
    private function send(string $path, string $mime, string $originalName, bool $ranges): never
    {
        $inline = in_array($mime, self::INLINE_TYPES, true);
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $originalName) ?: 'datei';
        $size = (int) filesize($path);
        $start = 0;
        $end = $size - 1;

        header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"{$safeName}\"; filename*=UTF-8''" . rawurlencode($originalName));
        header('X-Content-Type-Options: nosniff');
        header('Cross-Origin-Resource-Policy: same-origin');
        // Einmal-Abrufe (Limit) nicht zwischenspeichern, sonst spielte der Browser ohne Server erneut ab.
        header('Cache-Control: ' . (MediaPlays::isMedia($mime) && !$ranges ? 'no-store' : 'private, max-age=300'));
        if ($mime === 'image/svg+xml') {
            // SVG nie mit Skripten ausführen, auch bei direktem Aufruf.
            header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'", true);
        }
        if ($ranges) {
            header('Accept-Ranges: bytes');
            $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
            if (preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) === 1 && ($m[1] !== '' || $m[2] !== '')) {
                if ($m[1] === '') {
                    $start = max(0, $size - (int) $m[2]);
                } else {
                    $start = (int) $m[1];
                    $end = $m[2] === '' ? $size - 1 : min($size - 1, (int) $m[2]);
                }
                if ($start > $end || $start >= $size) {
                    http_response_code(416);
                    header('Content-Range: bytes */' . $size);
                    exit;
                }
                http_response_code(206);
                header("Content-Range: bytes {$start}-{$end}/{$size}");
            }
        }
        header('Content-Length: ' . (string) ($end - $start + 1));
        if ($start === 0 && $end === $size - 1) {
            readfile($path);
            exit;
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            exit;
        }
        fseek($fh, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($fh)) {
            $chunk = fread($fh, min(65536, $left));
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            $left -= strlen($chunk);
        }
        fclose($fh);
        exit;
    }
}
