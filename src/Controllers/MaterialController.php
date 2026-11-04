<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\ExamAccess;
use App\Services\Materials;

/**
 * Auslieferung von Klausur-Material (Bilder, PDF) über eine geschützte Route.
 *
 * Teilnehmende bekommen Material erst ab dem Start der Klausur (vorher 403), Lehrkräfte mit
 * Fähigkeit "view" jederzeit. Der Pfad kommt aus Materials::pathFor() und wird nie aus der URL gebaut.
 */
final class MaterialController extends Controller
{
    private const INLINE_TYPES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml', 'application/pdf',
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

        if ($this->ctx->auth->isStaff()) {
            $this->ctx->exams->require($examId, ExamAccess::CAP_VIEW);
        } elseif ($this->ctx->auth->isStudent()) {
            $this->requireParticipant($exam, $user);
        } else {
            throw new HttpException(403);
        }

        $row = $this->ctx->db->fetchOne('SELECT * FROM exam_materials WHERE id = ? AND exam_id = ?', [$materialId, $examId]);
        if ($row === null) {
            throw new HttpException(404, 'Material nicht gefunden.');
        }
        $materials = new Materials($this->ctx->db, (string) $this->ctx->config['uploads']['dir']);
        $path = $materials->pathFor($row);
        if (!is_file($path)) {
            throw new HttpException(404, 'Material nicht gefunden.');
        }

        // Die Sitzung wird nicht mehr gebraucht; große Dateien sollen keine parallelen Anfragen blockieren.
        $this->ctx->session->release();
        $this->send($path, (string) $row['mime'], (string) $row['original_name']);
    }

    /**
     * Schüler:innen: Mitglied des Kurses, mit Teilnahme, Klausur gestartet; bei SEB-Pflicht gültiger Header.
     *
     * @param array<string, mixed> $exam
     * @param array<string, mixed> $user
     */
    private function requireParticipant(array $exam, array $user): void
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
    }

    /** Streamt die Datei mit sicheren Headern (inline nur für Bilder und PDF). */
    private function send(string $path, string $mime, string $originalName): never
    {
        $inline = in_array($mime, self::INLINE_TYPES, true);
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $originalName) ?: 'datei';

        header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"{$safeName}\"; filename*=UTF-8''" . rawurlencode($originalName));
        header('X-Content-Type-Options: nosniff');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Cache-Control: private, max-age=300');
        if ($mime === 'image/svg+xml') {
            // SVG nie mit Skripten ausführen, auch bei direktem Aufruf.
            header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'", true);
        }
        readfile($path);
        exit;
    }
}
