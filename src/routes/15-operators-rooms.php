<?php

declare(strict_types=1);

use App\Controllers\OperatorsController;
use App\Controllers\RoomsController;
use App\Core\Router;

/** Operatoren und Räume. */
return static function (Router $r): void {
    $r->get('/admin/operatoren', [OperatorsController::class, 'index']);
    $r->post('/admin/operatoren/neu', [OperatorsController::class, 'store']);
    $r->get('/admin/operatoren/{id}', [OperatorsController::class, 'edit']);
    $r->post('/admin/operatoren/{id}', [OperatorsController::class, 'update']);
    $r->post('/admin/operatoren/{id}/loeschen', [OperatorsController::class, 'destroy']);
    $r->post('/admin/operatoren/{id}/verschieben', [OperatorsController::class, 'move']);

    $r->get('/admin/raeume', [RoomsController::class, 'index']);
    $r->post('/admin/raeume/neu', [RoomsController::class, 'store']);
    $r->get('/admin/raeume/{id}', [RoomsController::class, 'show']);
    $r->post('/admin/raeume/{id}', [RoomsController::class, 'update']);
    $r->post('/admin/raeume/{id}/loeschen', [RoomsController::class, 'destroy']);
    $r->post('/admin/raeume/{id}/platz-leeren', [RoomsController::class, 'clearSeat']);
    $r->post('/admin/raeume/{id}/plaetze-leeren', [RoomsController::class, 'clearAll']);
};
