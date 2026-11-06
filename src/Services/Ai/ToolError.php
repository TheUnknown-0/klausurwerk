<?php

declare(strict_types=1);

namespace App\Services\Ai;

/** Fachlicher Fehler eines MCP-Werkzeugs: geht als isError-Ergebnis an das Sprachmodell zurück. */
final class ToolError extends \RuntimeException
{
}
