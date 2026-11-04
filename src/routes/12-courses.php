<?php

declare(strict_types=1);

use App\Controllers\CoursesController;
use App\Core\Router;

/** Kurse und Mitglieder. */
return static function (Router $r): void {
    $r->get('/admin/kurse', [CoursesController::class, 'index']);
    $r->get('/admin/kurse/neu', [CoursesController::class, 'create']);
    $r->post('/admin/kurse/neu', [CoursesController::class, 'store']);

    $r->get('/admin/kurse/{id}', [CoursesController::class, 'show']);
    $r->get('/admin/kurse/{id}/bearbeiten', [CoursesController::class, 'edit']);
    $r->post('/admin/kurse/{id}/bearbeiten', [CoursesController::class, 'update']);
    $r->post('/admin/kurse/{id}/archivieren', [CoursesController::class, 'toggleArchive']);
    $r->post('/admin/kurse/{id}/loeschen', [CoursesController::class, 'destroy']);
    $r->post('/admin/kurse/{id}/mitglieder', [CoursesController::class, 'addMember']);
    $r->post('/admin/kurse/{id}/mitglieder/{userId}/entfernen', [CoursesController::class, 'removeMember']);
};
