<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\UpdateCheck;

/**
 * Update-Prüfung für Admins (Versionsdialog in der Seitenleiste).
 *
 * Die Seite ruft die Prüfung nach der Anmeldung eines Admins und einmal täglich selbst auf
 * (public/assets/js/version.js), "Jetzt prüfen" im Dialog erzwingt sie.
 */
final class VersionController extends Controller
{
    /** POST /api/version/pruefen  {force?: bool} */
    public function check(array $params): array
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $force = !empty($this->jsonInput()['force']);

        $session = $this->ctx->session;
        $afterLogin = (bool) $session->get(UpdateCheck::SESSION_DUE, false);
        $session->remove(UpdateCheck::SESSION_DUE);
        // Die Abfrage bei GitHub darf andere Anfragen derselben Sitzung nicht aufhalten.
        $session->release();

        $updates = UpdateCheck::fromContext($this->ctx);
        // Ein Ergebnis gilt für alle Admins; "Jetzt suchen" höchstens einmal je Minute und nie
        // während einer Drosselung durch GitHub (siehe UpdateCheck).
        $throttled = false;
        if ($force) {
            $throttled = !$updates->manualAllowed();
            if (!$throttled) {
                $updates->run();
            }
        } elseif ($updates->isDue($afterLogin)) {
            $updates->run();
        }
        $state = $updates->state();

        return [
            'success' => true,
            'throttled' => $throttled ? ($state['blockedUntil'] !== null
                ? 'GitHub bremst gerade Anfragen. Nächster Versuch ab ' . date('H:i', $state['blockedUntil']) . ' Uhr.'
                : 'Gerade erst geprüft. Damit GitHub nicht bremst, geht das höchstens einmal pro Minute.') : null,
            'installed' => $state['installed'],
            'latest' => $state['latest'],
            'available' => $state['available'],
            'html' => $this->ctx->view->renderPartial('partials/version-status', ['update' => $state]),
        ];
    }
}
