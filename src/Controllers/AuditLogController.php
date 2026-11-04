<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions as P;
use App\Services\Exports;

/**
 * Audit-Log: Filterbare Übersicht sowie CSV-Export.
 *
 * Hinweis zu LIMIT/OFFSET: Beide Werte stammen ausschließlich aus
 * validierten Integern und werden per sprintf('%d') eingesetzt, da MariaDB
 * für LIMIT keine gebundenen Parameter über PDO::ATTR_EMULATE_PREPARES=false
 * akzeptiert. Alle inhaltlichen Filterwerte laufen weiterhin als
 * Prepared-Statement-Parameter.
 */
final class AuditLogController extends Controller
{
    private const PER_PAGE = 50;
    private const SEVERITIES = ['info', 'warning', 'critical'];

    /** GET /admin/audit-log */
    public function index(array $params): string
    {
        $this->requirePermission(P::AUDIT_LOGS_SEHEN);

        $filters = $this->readFilters();
        [$where, $args] = $this->buildWhere($filters);

        $total = (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM audit_logs WHERE ' . $where, $args);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($_GET['seite'] ?? 1)), $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = $this->ctx->db->fetchAll(
            'SELECT * FROM audit_logs WHERE ' . $where
            . ' ORDER BY created_at DESC, id DESC'
            . sprintf(' LIMIT %d OFFSET %d', self::PER_PAGE, $offset),
            $args,
        );

        return $this->render('pages/audit/index', [
            'title' => 'Audit-Log',
            'rows' => $rows,
            'filters' => $filters,
            'severities' => self::SEVERITIES,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'criticalCount' => (int) $this->ctx->db->fetchValue(
                'SELECT COUNT(*) FROM audit_logs WHERE ' . $where . " AND severity = 'critical'",
                $args,
            ),
        ]);
    }

    /** GET /admin/audit-log/export */
    public function export(array $params): string
    {
        $this->requirePermission(P::AUDIT_LOGS_SEHEN);

        $filters = $this->readFilters();
        [$where, $args] = $this->buildWhere($filters);

        $rows = $this->ctx->db->fetchAll(
            'SELECT * FROM audit_logs WHERE ' . $where . ' ORDER BY created_at DESC, id DESC LIMIT 20000',
            $args,
        );

        $this->ctx->audit->log('audit.export', 'info', sprintf('%d Einträge exportiert', count($rows)));

        $header = ['Zeitpunkt', 'Benutzer', 'Aktion', 'Schweregrad', 'IP-Adresse', 'Details'];
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                format_datetime($row['created_at']),
                $row['username'] ?? 'System',
                $row['action'],
                $row['severity'],
                $row['ip_address'] ?? '',
                $row['details'] ?? '',
            ];
        }

        Exports::csv($header, $out, 'audit-log');
    }

    // ---------- Helfer ----------

    /** @return array{severity: string, aktion: string, benutzer: string, von: string, bis: string, suche: string} */
    private function readFilters(): array
    {
        $severity = (string) ($_GET['stufe'] ?? '');

        return [
            'severity' => in_array($severity, self::SEVERITIES, true) ? $severity : '',
            'aktion' => mb_substr(trim((string) ($_GET['aktion'] ?? '')), 0, 100),
            'benutzer' => mb_substr(trim((string) ($_GET['benutzer'] ?? '')), 0, 100),
            'von' => $this->dateInput((string) ($_GET['von'] ?? '')),
            'bis' => $this->dateInput((string) ($_GET['bis'] ?? '')),
            'suche' => mb_substr(trim((string) ($_GET['suche'] ?? '')), 0, 100),
        ];
    }

    /**
     * @param array{severity: string, aktion: string, benutzer: string, von: string, bis: string, suche: string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $where = '1 = 1';
        $args = [];

        if ($filters['severity'] !== '') {
            $where .= ' AND severity = ?';
            $args[] = $filters['severity'];
        }
        if ($filters['aktion'] !== '') {
            $where .= ' AND action LIKE ?';
            $args[] = self::escapeLike($filters['aktion']) . '%';
        }
        if ($filters['benutzer'] !== '') {
            $where .= ' AND username LIKE ?';
            $args[] = '%' . self::escapeLike($filters['benutzer']) . '%';
        }
        if ($filters['von'] !== '') {
            $where .= ' AND created_at >= ?';
            $args[] = $filters['von'] . ' 00:00:00';
        }
        if ($filters['bis'] !== '') {
            $where .= ' AND created_at <= ?';
            $args[] = $filters['bis'] . ' 23:59:59';
        }
        if ($filters['suche'] !== '') {
            $like = '%' . self::escapeLike($filters['suche']) . '%';
            $where .= ' AND (action LIKE ? OR username LIKE ? OR details LIKE ?)';
            array_push($args, $like, $like, $like);
        }

        return [$where, $args];
    }

    /** Akzeptiert nur ein Datum im Format YYYY-MM-DD. */
    private function dateInput(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
