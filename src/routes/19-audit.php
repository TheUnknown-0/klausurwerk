<?php

declare(strict_types=1);

use App\Controllers\AuditLogController;
use App\Core\Router;

/** Audit-Log: Übersicht mit Filtern und CSV-Export. */
return static function (Router $r): void {
    $r->get('/admin/audit-log', [AuditLogController::class, 'index']);
    $r->get('/admin/audit-log/export', [AuditLogController::class, 'export']);
};
