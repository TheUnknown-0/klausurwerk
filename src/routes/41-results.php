<?php

declare(strict_types=1);

use App\Controllers\ResultPdfController;
use App\Controllers\ResultsController;
use App\Core\Router;

/** Ergebnisse, Klausurspiegel, Export, PDF und Statusfluss der Rückgabe. */
return static function (Router $r): void {
    $r->get('/klausuren/{id}/ergebnisse', [ResultsController::class, 'index']);
    $r->get('/klausuren/{id}/ergebnisse/export', [ResultsController::class, 'export']);
    $r->post('/klausuren/{id}/ergebnisse/einstellungen', [ResultsController::class, 'saveSettings']);
    $r->post('/klausuren/{id}/ergebnisse/alle-abschliessen', [ResultsController::class, 'finalizeAll']);
    $r->post('/api/ergebnisse/{id}/person/{pid}', [ResultsController::class, 'person']);

    $r->get('/klausuren/{id}/ergebnisse/pdf', [ResultPdfController::class, 'all']);
    $r->get('/klausuren/{id}/ergebnisse/pdf/{pid}', [ResultPdfController::class, 'one']);

    $r->post('/klausuren/{id}/freigeben', [ResultsController::class, 'release']);
    $r->post('/klausuren/{id}/zuruecknehmen', [ResultsController::class, 'withdraw']);
    $r->post('/klausuren/{id}/archivieren', [ResultsController::class, 'archive']);
};
