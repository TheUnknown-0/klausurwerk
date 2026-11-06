<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Core\Context;

/**
 * KI-Zugang (MCP): Schalter der Schule und die öffentlichen Adressen.
 * Standard: aus. Nur Admins (bzw. EINSTELLUNGEN_BEARBEITEN) schalten ihn ein.
 */
final class AiAccess
{
    public const SETTING = 'ai_access_enabled';

    public static function enabled(Context $ctx): bool
    {
        return $ctx->settings->getBool(self::SETTING);
    }

    /** Aussteller (issuer) des Autorisierungsservers: die öffentliche Wurzeladresse. */
    public static function issuer(Context $ctx): string
    {
        return rtrim($ctx->publicBase(), '/');
    }

    /** Die MCP-Adresse, die Lehrkräfte im KI-Programm eintragen (zugleich Ziel der Schlüssel). */
    public static function mcpUrl(Context $ctx): string
    {
        return self::issuer($ctx) . '/mcp';
    }

    public static function server(Context $ctx): OAuthServer
    {
        return new OAuthServer($ctx->db);
    }
}
