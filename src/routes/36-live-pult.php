<?php

declare(strict_types=1);

use App\Controllers\PultController;
use App\Core\Router;

/** Lehrer-Pult. */
return static function (Router $r): void {
    $r->get('/klausuren/{id}/pult', [PultController::class, 'show']);

    $r->get('/api/pult/{id}/stand', [PultController::class, 'state']);
    $r->post('/api/pult/{id}/aktion', [PultController::class, 'action']);
    $r->get('/api/pult/{id}/antworten/{participationId}', [PultController::class, 'answers']);
    $r->get('/api/pult/{id}/replay/{participationId}', [PultController::class, 'replay']);
    $r->post('/api/pult/{id}/sitzplan', [PultController::class, 'seatPlan']);
    $r->get('/api/pult/{id}/ereignisse/{participationId}', [PultController::class, 'events']);
};
