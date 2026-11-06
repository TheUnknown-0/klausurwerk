<?php

declare(strict_types=1);

namespace App\Services\Web;

/** Seite nicht abrufbar (Netz, Größe, Typ, gesperrtes Ziel-Netz). Meldung geht an die Person. */
class WebError extends \RuntimeException
{
}
