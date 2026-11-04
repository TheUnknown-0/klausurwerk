<?php

declare(strict_types=1);

use App\Controllers\ArchiveController;
use App\Core\Router;

/** Archiv: Aufgabensuche und Kopieren. */
return static function (Router $r): void {
    $r->get('/archiv', [ArchiveController::class, 'index']);
    $r->post('/archiv/kopieren', [ArchiveController::class, 'copy']);
};
