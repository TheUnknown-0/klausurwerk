<?php

declare(strict_types=1);

use App\Controllers\GradingScalesController;
use App\Core\Router;

/** Notenschlüssel. */
return static function (Router $r): void {
    $r->get('/admin/notenschluessel', [GradingScalesController::class, 'index']);
    $r->get('/admin/notenschluessel/neu', [GradingScalesController::class, 'create']);
    $r->post('/admin/notenschluessel/neu', [GradingScalesController::class, 'store']);
    $r->get('/admin/notenschluessel/{id}', [GradingScalesController::class, 'edit']);
    $r->post('/admin/notenschluessel/{id}', [GradingScalesController::class, 'update']);
    $r->post('/admin/notenschluessel/{id}/loeschen', [GradingScalesController::class, 'destroy']);
};
