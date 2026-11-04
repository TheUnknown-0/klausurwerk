<?php

declare(strict_types=1);

namespace App\Core;

/**
 * CSRF-Schutz: ein Token pro Session, Prüfung bei allen schreibenden Requests.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';
    public const FIELD = '_csrf';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function validate(?string $token): bool
    {
        $expected = $this->session->get(self::SESSION_KEY);

        return is_string($expected) && is_string($token) && hash_equals($expected, $token);
    }

    /** HTML für ein verstecktes Formularfeld mit dem aktuellen Token. */
    public function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            htmlspecialchars($this->token(), ENT_QUOTES),
        );
    }
}
