<?php

declare(strict_types=1);

use App\Controllers\ExamMaterialsController;
use App\Core\Router;

/** Material einer Klausur (Verwaltung durch Lehrkräfte). */
return static function (Router $r): void {
    $r->get('/klausuren/{id}/material', [ExamMaterialsController::class, 'index']);
    $r->post('/klausuren/{id}/material', [ExamMaterialsController::class, 'upload']);
    $r->post('/klausuren/{id}/material/{mid}/hilfsmittel', [ExamMaterialsController::class, 'toggleAid']);
    $r->post('/klausuren/{id}/material/{mid}/zuordnung', [ExamMaterialsController::class, 'assign']);
    $r->post('/klausuren/{id}/material/{mid}/loeschen', [ExamMaterialsController::class, 'delete']);
};
