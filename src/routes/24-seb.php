<?php

declare(strict_types=1);

use App\Controllers\MarkdownPreviewController;
use App\Controllers\SebController;
use App\Core\Router;

/** SEB-Datei und Markdown-Vorschau. */
return static function (Router $r): void {
    $r->get('/klausuren/{id}/seb', [SebController::class, 'form']);
    $r->post('/klausuren/{id}/seb', [SebController::class, 'download']);
    $r->post('/api/markdown/vorschau', [MarkdownPreviewController::class, 'preview']);
};
