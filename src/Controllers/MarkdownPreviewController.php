<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ExamAccess;
use App\Services\Markdown;

/** POST /api/markdown/vorschau: gleiches Rendering wie in der Klausur (für Lehrkräfte). */
final class MarkdownPreviewController extends Controller
{
    /** POST /api/markdown/vorschau */
    public function preview(array $params): array
    {
        $this->requireStaff();
        $this->requireCsrf();
        $in = $this->jsonInput();
        $markdown = mb_substr((string) ($in['markdown'] ?? ''), 0, 200000);

        $examId = null;
        if (isset($in['exam_id']) && (int) $in['exam_id'] > 0) {
            $candidate = (int) $in['exam_id'];
            if ($this->ctx->exams->can($candidate, ExamAccess::CAP_VIEW)) {
                $examId = $candidate;
            }
        }
        $md = new Markdown($this->ctx->config['app']['base_url'], $examId);

        return ['success' => true, 'html' => $md->render($markdown)];
    }
}
