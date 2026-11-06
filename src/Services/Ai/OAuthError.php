<?php

declare(strict_types=1);

namespace App\Services\Ai;

/** Fehler im OAuth-Ablauf mit Code nach RFC 6749 (invalid_request, invalid_grant, ...). */
final class OAuthError extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
    ) {
        parent::__construct($description);
    }

    /** @return array{error: string, error_description: string} */
    public function toArray(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
