<?php

declare(strict_types=1);

use App\Controllers\ExamPreviewController;
use App\Controllers\MaterialController;
use App\Controllers\RunController;
use App\Controllers\SebEndController;
use App\Controllers\StudentExamController;
use App\Core\Router;

/** Schüleransicht, Vorschau, Material, Java-Ausführung. */
return static function (Router $r): void {
    // Seiten
    $r->get('/meine-klausuren', [StudentExamController::class, 'index']);
    $r->get('/klausur/{id}', [StudentExamController::class, 'show']);
    $r->get('/klausur/{id}/material/{mid}', [MaterialController::class, 'show']);
    $r->get('/klausur/{id}/antwortdatei/{fid}', [StudentExamController::class, 'answerFile']);
    $r->get('/klausuren/{id}/vorschau', [ExamPreviewController::class, 'show']);
    $r->get('/seb-beenden', [SebEndController::class, 'show']);

    // Schüler-APIs
    $r->post('/api/klausur/{id}/beitreten', [StudentExamController::class, 'join']);
    $r->post('/api/klausur/{id}/bereit', [StudentExamController::class, 'ready']);
    $r->get('/api/klausur/{id}/inhalt', [StudentExamController::class, 'content']);
    $r->post('/api/klausur/{id}/puls', [StudentExamController::class, 'pulse']);
    $r->post('/api/klausur/{id}/speichern', [StudentExamController::class, 'save']);
    $r->post('/api/klausur/{id}/ereignis', [StudentExamController::class, 'event']);
    $r->post('/api/klausur/{id}/anfrage', [StudentExamController::class, 'request']);
    $r->post('/api/klausur/{id}/datei', [StudentExamController::class, 'upload']);
    $r->post('/api/klausur/{id}/vorschaubild', [StudentExamController::class, 'previewImage']);
    // Live-Wand (nur Arbeitsspeicher, siehe Services\LiveStore)
    $r->post('/api/klausur/{id}/live', [StudentExamController::class, 'live']);
    $r->post('/api/klausur/{id}/bildschirm', [StudentExamController::class, 'screen']);

    // Java-Ausführung (Runner)
    $r->post('/api/ausfuehren', [RunController::class, 'start']);
    $r->get('/api/ausfuehren/{runId}', [RunController::class, 'poll']);
    $r->post('/api/ausfuehren/{runId}/eingabe', [RunController::class, 'input']);
    $r->post('/api/ausfuehren/{runId}/stopp', [RunController::class, 'stop']);
};
