<?php

declare(strict_types=1);

use App\Controllers\AccessCardsController;
use App\Core\Router;

/** Zugangskarten drucken. */
return static function (Router $r): void {
    $r->get('/admin/zugangskarten', [AccessCardsController::class, 'index']);
    $r->post('/admin/zugangskarten', [AccessCardsController::class, 'printCards']);
};
