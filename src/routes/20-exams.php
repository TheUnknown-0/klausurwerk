<?php

declare(strict_types=1);

use App\Controllers\ExamsController;
use App\Controllers\ExamTransferController;
use App\Core\Router;

/** Klausuren: Liste, Anlegen, Übersicht, Einstellungen, Lehrkräfte. Statische Pfade vor {id}. */
return static function (Router $r): void {
    $r->get('/klausuren', [ExamsController::class, 'index']);
    $r->get('/klausuren/neu', [ExamsController::class, 'create']);
    $r->post('/klausuren/neu', [ExamsController::class, 'store']);
    $r->get('/klausuren/import', [ExamTransferController::class, 'form']);
    $r->post('/klausuren/import', [ExamTransferController::class, 'import']);

    $r->get('/klausuren/{id}', [ExamsController::class, 'show']);
    $r->get('/klausuren/{id}/einstellungen', [ExamsController::class, 'settings']);
    $r->post('/klausuren/{id}/einstellungen', [ExamsController::class, 'saveSettings']);
    $r->post('/klausuren/{id}/einstellungen/profil', [ExamsController::class, 'applyProfile']);
    $r->post('/klausuren/{id}/code', [ExamsController::class, 'regenerateCode']);
    $r->post('/klausuren/{id}/status', [ExamsController::class, 'setStatus']);
    $r->post('/klausuren/{id}/loeschen', [ExamsController::class, 'destroy']);
    $r->post('/klausuren/{id}/kopieren', [ExamsController::class, 'copy']);
    $r->get('/klausuren/{id}/export', [ExamTransferController::class, 'export']);

    $r->post('/klausuren/{id}/lehrkraefte', [ExamsController::class, 'addStaff']);
    $r->post('/klausuren/{id}/lehrkraefte/{uid}', [ExamsController::class, 'updateStaff']);
    $r->post('/klausuren/{id}/lehrkraefte/{uid}/entfernen', [ExamsController::class, 'removeStaff']);
    $r->get('/api/klausuren/{id}/lehrkraft-suche', [ExamsController::class, 'staffSearch']);
};
