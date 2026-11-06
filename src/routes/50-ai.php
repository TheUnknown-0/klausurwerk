<?php

declare(strict_types=1);

use App\Controllers\AiController;
use App\Controllers\McpController;
use App\Controllers\OAuthController;
use App\Core\Router;

/** KI-Zugang: MCP-Endpunkt, OAuth 2.1 (Metadaten, Registrierung, Zustimmung, Token) und Einrichtungsseite. */
return static function (Router $r): void {
    $r->get('/.well-known/oauth-protected-resource', [OAuthController::class, 'protectedResource']);
    $r->get('/.well-known/oauth-protected-resource/mcp', [OAuthController::class, 'protectedResource']);
    $r->get('/.well-known/oauth-authorization-server', [OAuthController::class, 'metadata']);
    $r->post('/oauth/register', [OAuthController::class, 'register']);
    $r->get('/oauth/authorize', [OAuthController::class, 'authorize']);
    $r->post('/oauth/authorize', [OAuthController::class, 'decide']);
    $r->post('/oauth/token', [OAuthController::class, 'token']);
    $r->post('/oauth/revoke', [OAuthController::class, 'revoke']);

    $r->post('/mcp', [McpController::class, 'handle']);
    $r->get('/mcp', [McpController::class, 'stream']);

    $r->get('/ki', [AiController::class, 'index']);
    $r->post('/ki/schalter', [AiController::class, 'toggle']);
    $r->post('/ki/zugaenge/{gid}/beenden', [AiController::class, 'revoke']);
};
