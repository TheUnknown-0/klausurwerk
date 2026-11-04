<?php

declare(strict_types=1);

use App\Controllers\UsersController;
use App\Core\Router;

/** Benutzerverwaltung — statische Pfade vor den {id}-Routen registrieren. */
return static function (Router $r): void {
    $r->get('/admin/benutzer', [UsersController::class, 'index']);
    $r->get('/admin/benutzer/neu', [UsersController::class, 'create']);
    $r->post('/admin/benutzer/neu', [UsersController::class, 'store']);

    $r->get('/admin/benutzer/import', [UsersController::class, 'showImport']);
    $r->post('/admin/benutzer/import', [UsersController::class, 'importPreview']);
    $r->post('/admin/benutzer/import/ausfuehren', [UsersController::class, 'importRun']);
    $r->post('/admin/benutzer/import/abbrechen', [UsersController::class, 'importCancel']);
    $r->post('/admin/benutzer/import/schueler-loeschen', [UsersController::class, 'deleteImportedStudents']);
    $r->get('/admin/benutzer/zugangsdaten-pdf', [UsersController::class, 'importCredentials']);
    $r->post('/admin/benutzer/klasse-passwoerter', [UsersController::class, 'classPasswords']);

    $r->get('/admin/benutzer/{id}', [UsersController::class, 'edit']);
    $r->post('/admin/benutzer/{id}', [UsersController::class, 'update']);
    $r->post('/admin/benutzer/{id}/passwort', [UsersController::class, 'resetPassword']);
    $r->post('/admin/benutzer/{id}/loeschen', [UsersController::class, 'destroy']);
};
