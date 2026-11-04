<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Uploads;

/**
 * Auslieferung öffentlicher Medien.
 *
 * Logo und Branding-Bild sind bewusst OHNE Login abrufbar: Sie erscheinen
 * bereits auf der Login-Seite. Die Dateinamen sind zufällig (128 Bit) und
 * nicht erratbar; es werden ausschließlich Bildformate ausgeliefert.
 */
final class FileController extends Controller
{
    /** GET /medien/logos/{file} — Schullogo. */
    public function logo(array $params): string
    {
        $uploads = new Uploads($this->ctx->config['uploads']['dir']);
        $uploads->stream('logos', $params['file']);
    }

    /** GET /medien/branding/{file} — Login-Hintergrundbild. */
    public function branding(array $params): string
    {
        $uploads = new Uploads($this->ctx->config['uploads']['dir']);
        $uploads->stream('branding', $params['file']);
    }
}
