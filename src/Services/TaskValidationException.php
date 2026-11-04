<?php

declare(strict_types=1);

namespace App\Services;

/** Eingabefehler beim Speichern einer Aufgabe, optional mit Feldbezug (für die Anzeige am Feld). */
final class TaskValidationException extends \InvalidArgumentException
{
    public function __construct(string $message, public readonly string $field = '')
    {
        parent::__construct($message);
    }
}
