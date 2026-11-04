<?php

declare(strict_types=1);

use App\Controllers\RetentionController;
use App\Core\Router;

/** Aufbewahrung und Löschung. */
return static function (Router $r): void {
    $r->get('/admin/aufbewahrung', [RetentionController::class, 'index']);
    $r->post('/admin/aufbewahrung/fristen', [RetentionController::class, 'saveSettings']);
    $r->post('/admin/aufbewahrung/protokolle', [RetentionController::class, 'deleteLogs']);
    $r->post('/admin/aufbewahrung/klausur-loeschen', [RetentionController::class, 'deleteExam']);
};
