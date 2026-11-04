<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ExamAccess;
use App\Services\ExamContent;

/**
 * Vorschau der Klausur für Lehrkräfte (gleiche Seite wie die Schüleransicht, ohne Teilnahme).
 * Den Inhalt liefert GET /api/klausur/{id}/inhalt (StudentExamController::content) im Vorschaumodus.
 */
final class ExamPreviewController extends Controller
{
    /** GET /klausuren/{id}/vorschau */
    public function show(array $params): string
    {
        $user = $this->requireStaff();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_VIEW);

        $boot = ExamContent::boot($exam, $user, (string) $this->ctx->config['app']['base_url'], true, false, false);

        return $this->render('pages/student/exam', [
            'title' => 'Vorschau: ' . (string) $exam['title'],
            'boot' => $boot,
            'bundles' => ['exam.js'],
            'pageStyles' => ['exam.css', 'code.css', 'diagrams.css', 'robo.css'],
        ], 'exam');
    }
}
