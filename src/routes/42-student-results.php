<?php

declare(strict_types=1);

use App\Controllers\StudentResultsController;
use App\Core\Router;

/** Digitale Rückgabe für Schüler:innen. */
return static function (Router $r): void {
    $r->get('/meine-ergebnisse', [StudentResultsController::class, 'index']);
    $r->get('/meine-ergebnisse/{id}', [StudentResultsController::class, 'show']);
    $r->get('/meine-ergebnisse/{id}/pdf', [StudentResultsController::class, 'pdf']);
};
