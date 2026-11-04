<?php
/**
 * Lokale QR-Code-Erzeugung — abhängigkeitsfrei (kein GD/Composer).
 *
 * Baut auf der MIT-lizenzierten Pure-PHP-Bibliothek von Kazuhiko Arase
 * (lib/qrcode.php) auf und rendert die Modul-Matrix als:
 *   - SVG (qrSvg)          → Web/Dashboard, scharf bei jeder Größe
 *   - FPDF-Rechtecke (qrDrawFpdf) → PDFs, ohne Bild-Extension
 *
 * Damit verlässt kein Token mehr den Server (Ablösung von qrserver.com).
 */

require_once __DIR__ . '/qrcode.php';

/**
 * Erzeugt das QRCode-Objekt (bereits make()-d) für beliebige Daten.
 * Fehlerkorrektur-Level M ist ein guter Kompromiss aus Robustheit/Dichte.
 */
function qrBuild(string $data, int $ecLevel = QR_ERROR_CORRECT_LEVEL_M): QRCode {
    return QRCode::getMinimumQRCode($data, $ecLevel);
}

/**
 * Liefert die QR-Matrix als SVG-String.
 *
 * @param int $scale  Kantenlänge eines Moduls in SVG-Einheiten (px)
 * @param int $margin Ruhezone in Modulen (Norm: 4)
 */
function qrSvg(string $data, int $scale = 6, int $margin = 4,
               string $dark = '#000000', string $light = '#ffffff'): string {
    $qr    = qrBuild($data);
    $count = $qr->getModuleCount();
    $dim   = ($count + 2 * $margin) * $scale;

    $rects = '';
    for ($r = 0; $r < $count; $r++) {
        for ($c = 0; $c < $count; $c++) {
            if ($qr->isDark($r, $c)) {
                $x = ($c + $margin) * $scale;
                $y = ($r + $margin) * $scale;
                $rects .= "<rect x=\"$x\" y=\"$y\" width=\"$scale\" height=\"$scale\"/>";
            }
        }
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dim . '" height="' . $dim . '" '
        . 'viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges" role="img" aria-label="QR-Code">'
        . '<rect width="' . $dim . '" height="' . $dim . '" fill="' . $light . '"/>'
        . '<g fill="' . $dark . '">' . $rects . '</g></svg>';
}

/**
 * Zeichnet einen QR-Code als gefüllte Rechtecke in ein FPDF-Dokument.
 *
 * @param object $pdf    FPDF-Instanz
 * @param float  $x      linke obere Ecke (mm)
 * @param float  $y      linke obere Ecke (mm)
 * @param float  $size   Zielbreite/-höhe inkl. Ruhezone (mm)
 * @param int    $margin Ruhezone in Modulen
 */
function qrDrawFpdf($pdf, string $data, float $x, float $y, float $size,
                    int $margin = 4, int $ecLevel = QR_ERROR_CORRECT_LEVEL_M): void {
    $qr     = qrBuild($data, $ecLevel);
    $count  = $qr->getModuleCount();
    $total  = $count + 2 * $margin;
    $module = $size / $total; // mm pro Modul

    // Hintergrund weiß
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect($x, $y, $size, $size, 'F');

    // Module schwarz
    $pdf->SetFillColor(0, 0, 0);
    for ($r = 0; $r < $count; $r++) {
        $c = 0;
        while ($c < $count) {
            if ($qr->isDark($r, $c)) {
                // Aufeinanderfolgende dunkle Module einer Zeile zu einem Rechteck zusammenfassen
                $runStart = $c;
                while ($c < $count && $qr->isDark($r, $c)) {
                    $c++;
                }
                $rx = $x + ($runStart + $margin) * $module;
                $ry = $y + ($r + $margin) * $module;
                $rw = ($c - $runStart) * $module;
                $pdf->Rect($rx, $ry, $rw, $module, 'F');
            } else {
                $c++;
            }
        }
    }
}
