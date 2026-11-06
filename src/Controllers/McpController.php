<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Ai\AiAccess;
use App\Services\Ai\McpServer;
use App\Services\Ai\McpTools;

/**
 * MCP-Endpunkt für KI-Programme (Claude, ChatGPT und andere).
 *
 * Anmeldung nicht über die Sitzung, sondern mit einem Bearer-Schlüssel aus dem OAuth-Ablauf
 * (OAuthController). Ohne gültigen Schlüssel: 401 mit Verweis auf die Metadaten (RFC 9728), damit
 * das KI-Programm die Anmeldung im Browser startet. Danach gelten die Rechte der Lehrkraft.
 */
final class McpController extends Controller
{
    /** Größte Anfrage (Material bis 20 MB als Base64). */
    private const MAX_BODY = 30 * 1024 * 1024;

    private function cors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id');
        header('Cache-Control: no-store');
    }

    /** @return array<string, mixed> */
    private function unauthorized(string $error = '', string $description = ''): array
    {
        $meta = AiAccess::issuer($this->ctx) . '/.well-known/oauth-protected-resource/mcp';
        $challenge = 'Bearer resource_metadata="' . $meta . '", scope="klausurwerk"';
        if ($error !== '') {
            $challenge .= ', error="' . $error . '", error_description="' . str_replace('"', "'", $description) . '"';
        }
        header('WWW-Authenticate: ' . $challenge);
        http_response_code(401);

        return ['error' => $error !== '' ? $error : 'unauthorized', 'error_description' => $description !== '' ? $description : 'Anmeldung erforderlich.'];
    }

    /** POST /mcp */
    public function handle(array $params): array|string
    {
        $this->cors();
        if (!AiAccess::enabled($this->ctx)) {
            http_response_code(403);

            return McpServer::error(null, -32001, 'Der KI-Zugang ist an dieser Schule nicht freigeschaltet. Admins schalten ihn unter Verwaltung › Einstellungen ein.');
        }

        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $header = (string) $value;
                }
            }
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m) !== 1) {
            return $this->unauthorized();
        }
        $grant = AiAccess::server($this->ctx)->authenticate($m[1], AiAccess::mcpUrl($this->ctx));
        if ($grant === null) {
            return $this->unauthorized('invalid_token', 'Der Schlüssel ist ungültig, abgelaufen oder widerrufen.');
        }
        $user = $this->ctx->db->fetchOne(
            "SELECT * FROM users WHERE id = ? AND is_active = 1 AND role IN ('admin', 'teacher')",
            [(int) $grant['user_id']],
        );
        if ($user === null) {
            // Konto gesperrt, gelöscht oder keine Lehrkraft mehr: Zugang endet.
            AiAccess::server($this->ctx)->revokeGrant((int) $grant['id'], null);

            return $this->unauthorized('invalid_token', 'Das Konto ist nicht mehr aktiv.');
        }
        $this->ctx->auth->actAs($user);

        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY) {
            http_response_code(413);

            return McpServer::error(null, -32600, 'Anfrage zu groß (höchstens 30 MB).');
        }
        $raw = file_get_contents('php://input');
        try {
            $payload = json_decode($raw === false ? '' : $raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            http_response_code(400);

            return McpServer::error(null, -32700, 'Ungültiges JSON.');
        }

        $clientName = (string) ($this->ctx->db->fetchValue('SELECT client_name FROM oauth_clients WHERE client_id = ?', [(string) $grant['client_id']]) ?? 'KI-Programm');
        $server = new McpServer(new McpTools($this->ctx, $clientName));
        $response = $server->handle($payload);
        if ($response === null) {
            http_response_code(202);

            return '';
        }

        return $response;
    }

    /** GET /mcp: Dieser Server schickt nichts von sich aus (kein SSE-Strom). */
    public function stream(array $params): string
    {
        $this->cors();
        header('Allow: POST');
        http_response_code(405);

        return '';
    }
}
