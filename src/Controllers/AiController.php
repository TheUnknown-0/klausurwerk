<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\Ai\AiAccess;

/**
 * Einrichtungsseite "KI-Assistent": Schalter für Admins, Schritt-für-Schritt-Anleitung je
 * KI-Programm, verbundene Programme mit Widerruf.
 */
final class AiController extends Controller
{
    /** GET /ki */
    public function index(array $params): string
    {
        $user = $this->requireStaff();
        $isAdmin = $this->ctx->auth->isAdmin();
        $server = AiAccess::server($this->ctx);
        $server->cleanup();
        $mcpUrl = AiAccess::mcpUrl($this->ctx);
        $host = strtolower((string) parse_url($mcpUrl, PHP_URL_HOST));

        return $this->render('pages/ai/index', [
            'title' => 'KI-Assistent',
            'enabled' => AiAccess::enabled($this->ctx),
            'isAdmin' => $isAdmin,
            'mcpUrl' => $mcpUrl,
            'isHttps' => str_starts_with($mcpUrl, 'https://'),
            'isLocal' => in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.local'),
            'baseIsGuessed' => $this->ctx->baseIsGuessed(),
            'grants' => $server->activeGrants((int) $user['id']),
            'allGrants' => $isAdmin ? $server->activeGrants() : [],
            'pageScripts' => ['ai-setup.js'],
            'pageStyles' => ['admin.css', 'ai.css'],
        ]);
    }

    /** POST /ki/schalter: KI-Zugang der Schule ein- oder ausschalten (nur Admins). */
    public function toggle(array $params): never
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $on = ($_POST['enabled'] ?? '') === '1';
        $this->ctx->settings->set(AiAccess::SETTING, $on ? '1' : '0');
        if ($on) {
            $this->ctx->audit->log('ki.zugang_eingeschaltet', 'warning', 'KI-Zugang (MCP) für die Schule eingeschaltet.');
            $this->flash('success', 'Der KI-Zugang ist eingeschaltet. Lehrkräfte können jetzt ihr KI-Programm verbinden.');
        } else {
            $n = AiAccess::server($this->ctx)->revokeAll();
            $this->ctx->audit->log('ki.zugang_ausgeschaltet', 'warning', 'KI-Zugang (MCP) ausgeschaltet, ' . $n . ' Verbindung(en) beendet.');
            $this->flash('success', 'Der KI-Zugang ist ausgeschaltet.' . ($n > 0 ? ' ' . $n . ' Verbindung(en) wurden beendet.' : ''));
        }
        $this->redirect($this->ctx->url('/ki'));
    }

    /** POST /ki/zugaenge/{gid}/beenden: eigene Verbindung beenden (Admins: jede). */
    public function revoke(array $params): never
    {
        $user = $this->requireStaff();
        $this->requireCsrf();
        $gid = (int) $params['gid'];
        $ok = AiAccess::server($this->ctx)->revokeGrant($gid, $this->ctx->auth->isAdmin() ? null : (int) $user['id']);
        if (!$ok) {
            throw new HttpException(404, 'Diese Verbindung gibt es nicht (mehr).');
        }
        $this->ctx->audit->log('ki.zugang_beendet', 'info', 'KI-Verbindung #' . $gid . ' beendet.');
        $this->flash('success', 'Die Verbindung wurde beendet. Das KI-Programm hat ab sofort keinen Zugriff mehr.');
        $this->redirect($this->ctx->url('/ki'));
    }
}
