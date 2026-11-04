<?php

declare(strict_types=1);

use App\Controllers\SettingsController;
use App\Core\Router;

/** Einstellungen: Schule/App, Logo, Adresse, Anmeldecode, Takt, Seitenpasswort, Test-Mail. */
return static function (Router $r): void {
    $r->get('/admin/einstellungen', [SettingsController::class, 'index']);
    $r->post('/admin/einstellungen', [SettingsController::class, 'save']);
    $r->post('/admin/einstellungen/logo', [SettingsController::class, 'saveLogo']);
    $r->post('/admin/einstellungen/testmail', [SettingsController::class, 'sendTestMail']);
};
