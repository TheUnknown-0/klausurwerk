<?php

declare(strict_types=1);

use App\Controllers\DashboardController;
use App\Controllers\UserSearchController;
use App\Core\Router;

/** Verwaltung: Dashboard und Personensuche-API. */
return static function (Router $r): void {
    $r->get('/dashboard', [DashboardController::class, 'index']);

    $r->get('/api/benutzer/suche', [UserSearchController::class, 'search']);
    $r->get('/api/benutzer/benutzername', [UserSearchController::class, 'suggestUsername']);
};
