<?php

declare(strict_types=1);

use App\Controllers\GradingController;
use App\Core\Router;

/** Korrektur (Stufe 2): Übersicht, Einzelansicht, Bewertung, Kommentare, automatische Vorbewertung. */
return static function (Router $r): void {
    $r->get('/klausuren/{id}/korrektur', [GradingController::class, 'overview']);
    $r->post('/klausuren/{id}/korrektur/beginnen', [GradingController::class, 'begin']);
    $r->get('/klausuren/{id}/korrektur/{pid}', [GradingController::class, 'show']);

    $r->get('/api/korrektur/{id}/zelle/{pid}/{tid}', [GradingController::class, 'cell']);
    $r->post('/api/korrektur/{id}/bewertung', [GradingController::class, 'saveEntry']);
    $r->post('/api/korrektur/{id}/kommentar', [GradingController::class, 'saveComment']);
    $r->post('/api/korrektur/{id}/kommentar/{cid}/loeschen', [GradingController::class, 'deleteComment']);
    $r->post('/api/korrektur/{id}/vorschlaege', [GradingController::class, 'applySuggestions']);
    $r->post('/api/korrektur/{id}/vorbewertung', [GradingController::class, 'autoStep']);
    $r->post('/api/korrektur/{id}/neu-testen', [GradingController::class, 'retest']);
};
