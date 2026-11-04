<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\AccessCards;
use App\Services\ExamAccess;
use App\Services\GradingScales;
use App\Services\GradingService;
use App\Services\ResultReport;

/**
 * Ergebnis-PDF für Lehrkräfte: eine Person oder alle (Sammel-PDF, je Person neue Seite).
 * Braucht die Fähigkeit "grade". Die PDF-Erzeugung selbst liegt in Services\ResultPdf.
 */
final class ResultPdfController extends Controller
{
    private function report(): ResultReport
    {
        $db = $this->ctx->db;

        return new ResultReport($db, new GradingService($db, new GradingScales($db)), (string) ($this->ctx->config['app']['base_url'] ?? ''));
    }

    /** @param array<string, mixed> $exam */
    private function assertPdfPhase(array $exam): void
    {
        if (!in_array((string) $exam['status'], ['grading', 'returned', 'archived'], true)) {
            throw new HttpException(409, 'Das PDF gibt es erst, wenn die Korrektur begonnen hat.');
        }
    }

    /** GET /klausuren/{id}/ergebnisse/pdf */
    public function all(array $params): never
    {
        $this->requireStaff();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_GRADE);
        $this->assertPdfPhase($exam);
        $this->ctx->session->release();
        @set_time_limit(180);

        $people = $this->ctx->db->fetchAll(
            'SELECT p.* FROM participations p JOIN users u ON u.id = p.user_id WHERE p.exam_id = ? ORDER BY u.lastname, u.firstname, p.id',
            [(int) $exam['id']],
        );
        $bytes = $this->report()->renderPdf($exam, $people, (string) ($this->ctx->settings->get('school_name') ?? ''));
        $this->ctx->audit->log('ergebnis_pdf', 'info', 'Sammel-PDF der Klausur #' . (int) $exam['id'] . ' (' . count($people) . ' Personen) erzeugt.');
        AccessCards::sendPdf($bytes, 'ergebnisse-' . (string) $exam['title'] . '.pdf');
    }

    /** GET /klausuren/{id}/ergebnisse/pdf/{pid} */
    public function one(array $params): never
    {
        $this->requireStaff();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_GRADE);
        $this->assertPdfPhase($exam);
        $this->ctx->session->release();

        $p = $this->ctx->db->fetchOne('SELECT * FROM participations WHERE id = ? AND exam_id = ?', [(int) $params['pid'], (int) $exam['id']]);
        if ($p === null) {
            throw new HttpException(404, 'Teilnahme nicht gefunden.');
        }
        $user = $this->ctx->db->fetchOne('SELECT firstname, lastname, username FROM users WHERE id = ?', [(int) $p['user_id']]) ?? [];
        $bytes = $this->report()->renderPdf($exam, [$p], (string) ($this->ctx->settings->get('school_name') ?? ''));
        AccessCards::sendPdf($bytes, 'ergebnis-' . (string) $exam['title'] . '-' . display_name($user) . '.pdf');
    }
}
