<?php

declare(strict_types=1);

namespace App\Controllers;

use Throwable;

/**
 * Betriebs-Endpunkte für Container-Healthcheck und Monitoring.
 *
 * Bewusst ohne Anmeldung, ohne Seitenpasswort und ohne verwertbare Details:
 * Die Antwort sagt nur, ob die Anwendung ansprechbar ist und ob sie die
 * Datenbank erreicht — keine Versionen, keine Hostnamen, keine Fehlertexte.
 */
final class HealthController extends Controller
{
    /**
     * GET /healthz — Lebenszeichen für den Container-Healthcheck.
     *
     * Antwortet bewusst auch dann mit „ok“, wenn die Datenbank klemmt: Ein
     * Neustart des Webservers behebt einen Datenbankausfall nicht, und ein
     * dauernd neu startender Container macht die Störung schlimmer.
     */
    public function live(array $params): string
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');

        return 'ok';
    }

    /**
     * GET /readyz — vollständige Bereitschaft inklusive Datenbank.
     * Für Monitoring und Lastverteiler: 200 = bedienbereit, 503 = nicht.
     */
    public function ready(array $params): array
    {
        header('Cache-Control: no-store');

        try {
            $this->ctx->db->fetchValue('SELECT 1');
        } catch (Throwable) {
            http_response_code(503);

            return ['status' => 'nicht bereit', 'database' => false];
        }

        return ['status' => 'bereit', 'database' => true];
    }
}
