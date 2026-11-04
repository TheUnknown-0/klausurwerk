<?php

declare(strict_types=1);

use App\Controllers\PermissionsController;
use App\Core\Router;

/** Rechtevergabe und Berechtigungsgruppen. */
return static function (Router $r): void {
    $r->get('/admin/berechtigungen', [PermissionsController::class, 'index']);
    $r->post('/admin/berechtigungen/speichern', [PermissionsController::class, 'save']);
    $r->post('/admin/berechtigungen/gruppen-zuweisen', [PermissionsController::class, 'assignGroups']);

    $r->get('/admin/berechtigungen/gruppen', [PermissionsController::class, 'groups']);
    $r->get('/admin/berechtigungen/gruppen/neu', [PermissionsController::class, 'createGroup']);
    $r->post('/admin/berechtigungen/gruppen/neu', [PermissionsController::class, 'storeGroup']);
    $r->get('/admin/berechtigungen/gruppen/{id}/bearbeiten', [PermissionsController::class, 'editGroup']);
    $r->post('/admin/berechtigungen/gruppen/{id}/bearbeiten', [PermissionsController::class, 'updateGroup']);
    $r->post('/admin/berechtigungen/gruppen/{id}/loeschen', [PermissionsController::class, 'deleteGroup']);
};
