<?php

declare(strict_types=1);

use App\Controllers\FileController;
use App\Core\Router;

return static function (Router $r): void {
    $r->get('/medien/logos/{file}', [FileController::class, 'logo']);
    $r->get('/medien/branding/{file}', [FileController::class, 'branding']);
};
