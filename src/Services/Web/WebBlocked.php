<?php

declare(strict_types=1);

namespace App\Services\Web;

/** Adresse ist nicht freigegeben (auch als Ziel einer Weiterleitung). */
final class WebBlocked extends WebError
{
    public function __construct(public readonly string $url)
    {
        parent::__construct('Diese Seite ist für diese Aufgabe nicht freigegeben.');
    }
}
