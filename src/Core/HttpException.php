<?php

declare(strict_types=1);

namespace App\Core;

/** Ausnahme mit HTTP-Statuscode — wird vom Front-Controller in eine Fehlerseite bzw. JSON-Antwort übersetzt. */
final class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status));
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Ungültige Anfrage.',
            401 => 'Bitte melde dich an.',
            403 => 'Keine Berechtigung.',
            404 => 'Seite nicht gefunden.',
            405 => 'Methode nicht erlaubt.',
            419 => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.',
            429 => 'Zu viele Anfragen. Bitte warte einen Moment.',
            default => 'Es ist ein Fehler aufgetreten.',
        };
    }
}
