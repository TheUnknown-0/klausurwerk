<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csp;
use App\Core\HttpException;
use App\Services\Ai\AiAccess;
use App\Services\Ai\OAuthError;
use App\Services\Ai\OAuthServer;

/**
 * OAuth 2.1 für den KI-Zugang (MCP): Metadaten, Client-Registrierung, Zustimmung, Token, Widerruf.
 *
 * Ohne Login-Guard erreichbar sind nur die Endpunkte, die KI-Programme von ihrem Server aus aufrufen
 * (Metadaten, Registrierung, Token, Widerruf). Sie geben nie Daten der Anwendung heraus, nur Schlüssel
 * gegen einen gültigen Code bzw. Erneuerungsschlüssel. Die Zustimmung (authorize) verlangt eine
 * angemeldete Lehrkraft und CSRF.
 */
final class OAuthController extends Controller
{
    private function server(): OAuthServer
    {
        return AiAccess::server($this->ctx);
    }

    /** Antwort für Programme (JSON, CORS offen, nicht zwischenspeichern). */
    private function machine(array $body, int $status = 200): array
    {
        http_response_code($status);
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('Access-Control-Allow-Origin: *');

        return $body;
    }

    private function oauthError(OAuthError $e): array
    {
        return $this->machine($e->toArray(), $e->status);
    }

    // ---------- Metadaten ----------

    /** GET /.well-known/oauth-protected-resource (RFC 9728), auch mit /mcp angehängt. */
    public function protectedResource(array $params): array
    {
        return $this->machine([
            'resource' => AiAccess::mcpUrl($this->ctx),
            'authorization_servers' => [AiAccess::issuer($this->ctx)],
            'scopes_supported' => [OAuthServer::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Klausurwerk',
            'resource_documentation' => $this->ctx->publicUrl('/ki'),
        ]);
    }

    /** GET /.well-known/oauth-authorization-server (RFC 8414) */
    public function metadata(array $params): array
    {
        $issuer = AiAccess::issuer($this->ctx);

        return $this->machine([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/oauth/authorize',
            'token_endpoint' => $issuer . '/oauth/token',
            'registration_endpoint' => $issuer . '/oauth/register',
            'revocation_endpoint' => $issuer . '/oauth/revoke',
            'scopes_supported' => [OAuthServer::SCOPE],
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'authorization_response_iss_parameter_supported' => true,
            'service_documentation' => $this->ctx->publicUrl('/ki'),
        ]);
    }

    // ---------- Registrierung ----------

    /** POST /oauth/register (RFC 7591), ohne Anmeldung: Programme melden sich selbst an. */
    public function register(array $params): array
    {
        if (!AiAccess::enabled($this->ctx)) {
            return $this->machine(['error' => 'access_denied', 'error_description' => 'Der KI-Zugang ist an dieser Schule nicht freigeschaltet.'], 403);
        }
        try {
            return $this->machine($this->server()->registerClient($this->jsonInput()), 201);
        } catch (OAuthError $e) {
            return $this->oauthError($e);
        }
    }

    // ---------- Zustimmung ----------

    /**
     * Client und Redirect prüfen. Schlägt das fehl, wird NICHT zurückgeleitet (sonst offener Redirect).
     *
     * @param array<string, mixed> $q
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function clientAndRedirect(array $q): array
    {
        $client = $this->server()->findClient((string) ($q['client_id'] ?? ''));
        if ($client === null) {
            throw new HttpException(400, 'Unbekanntes KI-Programm. Bitte die Verbindung im KI-Programm neu einrichten.');
        }
        $redirect = (string) ($q['redirect_uri'] ?? '');
        if ($redirect === '' && count($client['redirect_uris']) === 1) {
            $redirect = $client['redirect_uris'][0];
        }
        if (!OAuthServer::redirectMatches($client['redirect_uris'], $redirect)) {
            throw new HttpException(400, 'Die Rücksprungadresse passt nicht zum angemeldeten KI-Programm.');
        }

        return [$client, $redirect];
    }

    /** @param array<string, string> $params */
    private function back(string $redirect, array $params): never
    {
        $params['iss'] = AiAccess::issuer($this->ctx);
        $sep = str_contains($redirect, '?') ? '&' : '?';
        header('Location: ' . $redirect . $sep . http_build_query($params, '', '&', PHP_QUERY_RFC3986), true, 302);
        exit;
    }

    /** GET /oauth/authorize: Lehrkraft meldet sich an und stimmt zu. */
    public function authorize(array $params): string
    {
        [$client, $redirect] = $this->clientAndRedirect($_GET);
        $user = $this->requireStaff();
        $state = (string) ($_GET['state'] ?? '');

        if (!AiAccess::enabled($this->ctx)) {
            return $this->render('pages/ai/consent-off', ['title' => 'KI-Zugang', 'isAdmin' => $this->ctx->auth->isAdmin()], 'minimal');
        }
        try {
            $checked = $this->server()->checkAuthorizeParams($_GET, AiAccess::mcpUrl($this->ctx));
        } catch (OAuthError $e) {
            $this->back($redirect, ['error' => $e->error, 'error_description' => $e->getMessage()] + ($state !== '' ? ['state' => $state] : []));
        }

        // Das Formular leitet nach dem Absenden zum KI-Programm weiter: form-action muss das erlauben.
        Csp::send(['form-action' => [OAuthServer::originOf($redirect)]]);

        return $this->render('pages/ai/consent', [
            'title' => 'KI-Zugang erlauben',
            'client' => $client,
            'redirectHost' => (string) (parse_url($redirect, PHP_URL_HOST) ?: parse_url($redirect, PHP_URL_SCHEME)),
            'user' => $user,
            'fields' => [
                'client_id' => $client['client_id'],
                'redirect_uri' => $redirect,
                'state' => $state,
                'response_type' => 'code',
                'code_challenge' => $checked['code_challenge'],
                'code_challenge_method' => 'S256',
                'scope' => (string) ($_GET['scope'] ?? ''),
                'resource' => (string) ($_GET['resource'] ?? ''),
            ],
        ], 'minimal');
    }

    /** POST /oauth/authorize: Entscheidung der Lehrkraft. */
    public function decide(array $params): never
    {
        $user = $this->requireStaff();
        $this->requireCsrf();
        [$client, $redirect] = $this->clientAndRedirect($_POST);
        $state = (string) ($_POST['state'] ?? '');
        $withState = static fn (array $p): array => $state !== '' ? $p + ['state' => $state] : $p;

        if (!AiAccess::enabled($this->ctx)) {
            $this->back($redirect, $withState(['error' => 'access_denied', 'error_description' => 'KI-Zugang nicht freigeschaltet']));
        }
        if (($_POST['decision'] ?? '') !== 'allow') {
            $this->back($redirect, $withState(['error' => 'access_denied', 'error_description' => 'Von der Lehrkraft abgelehnt']));
        }
        try {
            $checked = $this->server()->checkAuthorizeParams($_POST, AiAccess::mcpUrl($this->ctx));
        } catch (OAuthError $e) {
            $this->back($redirect, $withState(['error' => $e->error, 'error_description' => $e->getMessage()]));
        }
        $code = $this->server()->createCode($client['client_id'], (int) $user['id'], $redirect, $checked['code_challenge'], $checked['scope'], $checked['resource']);
        $this->ctx->audit->log('ki.zugang_erteilt', 'warning', 'KI-Programm "' . $client['client_name'] . '" (' . $client['client_id'] . ') verbunden.');
        $this->back($redirect, $withState(['code' => $code]));
    }

    // ---------- Token und Widerruf ----------

    /** @return array<string, mixed> Formular (application/x-www-form-urlencoded) oder JSON */
    private function formInput(): array
    {
        return $_POST !== [] ? $_POST : $this->jsonInput();
    }

    /** POST /oauth/token: Code oder Erneuerungsschlüssel gegen Schlüssel tauschen. */
    public function token(array $params): array
    {
        $in = $this->formInput();
        $canonical = AiAccess::mcpUrl($this->ctx);
        try {
            if (!AiAccess::enabled($this->ctx)) {
                throw new OAuthError('access_denied', 'Der KI-Zugang ist an dieser Schule nicht freigeschaltet.', 403);
            }
            $clientId = (string) ($in['client_id'] ?? '');

            return $this->machine(match ((string) ($in['grant_type'] ?? '')) {
                'authorization_code' => $this->server()->exchangeCode(
                    $clientId,
                    (string) ($in['code'] ?? ''),
                    (string) ($in['redirect_uri'] ?? ''),
                    (string) ($in['code_verifier'] ?? ''),
                    $in['resource'] ?? null,
                    $canonical,
                ),
                'refresh_token' => $this->server()->refresh($clientId, (string) ($in['refresh_token'] ?? ''), $in['resource'] ?? null, $canonical),
                default => throw new OAuthError('unsupported_grant_type', 'Nur authorization_code und refresh_token.'),
            });
        } catch (OAuthError $e) {
            return $this->oauthError($e);
        }
    }

    /** POST /oauth/revoke (RFC 7009): antwortet immer mit 200. */
    public function revoke(array $params): array
    {
        $token = (string) ($this->formInput()['token'] ?? '');
        if ($token !== '') {
            $this->server()->revokeToken($token);
        }

        return $this->machine([]);
    }
}
