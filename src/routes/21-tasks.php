<?php

declare(strict_types=1);

use App\Controllers\TasksController;
use App\Core\Router;

/** Aufgaben-Editor und seine JSON-Schnittstelle. */
return static function (Router $r): void {
    $r->get('/klausuren/{id}/aufgaben', [TasksController::class, 'editor']);
    $r->get('/api/klausuren/{id}/aufgaben', [TasksController::class, 'list']);
    $r->post('/api/klausuren/{id}/aufgaben', [TasksController::class, 'create']);
    $r->get('/api/klausuren/{id}/aufgaben/{tid}', [TasksController::class, 'show']);
    $r->post('/api/klausuren/{id}/aufgaben/{tid}', [TasksController::class, 'save']);
    $r->post('/api/klausuren/{id}/aufgaben/{tid}/loeschen', [TasksController::class, 'delete']);
    $r->post('/api/klausuren/{id}/aufgaben/{tid}/verschieben', [TasksController::class, 'move']);
    $r->post('/api/klausuren/{id}/aufgaben/{tid}/duplizieren', [TasksController::class, 'duplicate']);
};
