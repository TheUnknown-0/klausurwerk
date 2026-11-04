<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Zielseite, mit der die SEB-Konfiguration die Sitzung beendet ("Klausur beendet, du kannst SEB schließen").
 * Ohne Klausurbezug, aber nur für Angemeldete.
 */
final class SebEndController extends Controller
{
    /** GET /seb-beenden */
    public function show(array $params): string
    {
        $this->requireLogin();

        return $this->render('pages/student/seb-end', ['title' => 'Klausur beendet'], 'minimal');
    }
}
