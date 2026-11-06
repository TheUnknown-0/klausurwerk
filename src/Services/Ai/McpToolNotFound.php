<?php

declare(strict_types=1);

namespace App\Services\Ai;

/** Unbekanntes Werkzeug: JSON-RPC-Fehler -32602 statt Werkzeugergebnis. */
final class McpToolNotFound extends \RuntimeException
{
    public function __construct(public readonly string $tool)
    {
        parent::__construct('Unbekanntes Werkzeug: ' . mb_substr($tool, 0, 80));
    }
}
