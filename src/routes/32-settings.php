<?php

declare(strict_types=1);

use App\Controllers\SettingsController;
use App\Core\Router;

/** Einstellungen: Schule/App, Logo, öffentliche Adresse, Seitenpasswort, Selbstbedienung. */
return static function (Router $r): void {
    $r->get('/admin/einstellungen', [SettingsController::class, 'index']);
    $r->post('/admin/einstellungen', [SettingsController::class, 'save']);
    $r->post('/admin/einstellungen/logo', [SettingsController::class, 'saveLogo']);
};
