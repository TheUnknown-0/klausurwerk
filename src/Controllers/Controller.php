<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Context;
use App\Core\Csrf;
use App\Core\HttpException;

/**
 * Basisklasse aller Controller.
 *
 * Konventionen:
 *  - Aktionen geben einen String (HTML) oder ein Array (JSON) zurück,
 *    oder beenden den Request per redirect().
 *  - Guards (requireLogin/requirePermission/requireCsrf)
 *    werden am Anfang der Aktion aufgerufen und werfen HttpException.
 */
abstract class Controller
{
    public function __construct(protected readonly Context $ctx)
    {
    }

    // ---------- Guards ----------

    /** @return array<string, mixed> Der eingeloggte Benutzer. */
    protected function requireLogin(): array
    {
        $user = $this->ctx->auth->user();
        if ($user === null) {
            // API-Routen bekommen 401 statt einer Weiterleitung: Ein fetch()
            // folgt dem Redirect und erhält HTML, der Aufrufer könnte das
            // nicht von einer fachlichen Ablehnung unterscheiden und würde
            // gepufferte Scans verwerfen.
            if ($this->isApiRequest()) {
                throw new HttpException(401, 'Deine Anmeldung ist abgelaufen. Bitte neu anmelden.');
            }

            $this->redirect($this->ctx->url('/login') . '?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
        }

        if ((int) ($user['must_change_password'] ?? 0) === 1 && !$this->isPasswordChangeRequest()) {
            if ($this->isApiRequest()) {
                throw new HttpException(401, 'Bitte zuerst das Passwort ändern.');
            }
            $this->redirect($this->ctx->url('/passwort-aendern'));
        }

        return $user;
    }

    /**
     * Ist die Anfrage die Passwortwechsel-Seite selbst? Verglichen wird nur der Pfad (ohne Query-String),
     * sonst ließe sich die Sperre mit "?/passwort-aendern" umgehen.
     */
    protected function isPasswordChangeRequest(): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }

        return rtrim($path, '/') === rtrim($this->ctx->url('/passwort-aendern'), '/');
    }

    /** Erwartet der Aufrufer JSON (API-Route oder Accept-Header)? */
    protected function isApiRequest(): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '';
        if (str_contains($path, '/api/')) {
            return true;
        }

        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /** Prüft Login und eine granulare Berechtigung. */
    protected function requirePermission(string $permission): void
    {
        $this->requireLogin();
        if (!$this->ctx->auth->can($permission)) {
            throw new HttpException(403);
        }
    }

    /** Lehrkräfte und Admins. @return array<string, mixed> */
    protected function requireStaff(): array
    {
        $user = $this->requireLogin();
        if (!$this->ctx->auth->isStaff()) {
            throw new HttpException(403);
        }

        return $user;
    }

    /** Nur Schüler:innen. @return array<string, mixed> */
    protected function requireStudent(): array
    {
        $user = $this->requireLogin();
        if (!$this->ctx->auth->isStudent()) {
            throw new HttpException(403);
        }

        return $user;
    }

    /** Nur Admins. */
    protected function requireAdmin(): void
    {
        $this->requireLogin();
        if (!$this->ctx->auth->isAdmin()) {
            throw new HttpException(403);
        }
    }

    /** CSRF-Prüfung für schreibende Requests (POST-Feld oder X-CSRF-Token-Header). */
    protected function requireCsrf(): void
    {
        $token = $_POST[Csrf::FIELD] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!$this->ctx->csrf->validate(is_string($token) ? $token : null)) {
            throw new HttpException(419);
        }
    }

    // ---------- Antworten ----------

    /** Rendert ein Seiten-Template im Standard-Layout. */
    protected function render(string $template, array $data = [], string $layout = 'app'): string
    {
        return $this->ctx->view->render($template, $data, $layout);
    }

    protected function redirect(string $url): never
    {
        header('Location: ' . $url, true, 303);
        exit;
    }

    protected function flash(string $type, string $message): void
    {
        $this->ctx->session->flash($type, $message);
    }

    /**
     * Anfragedaten einer JSON-Schnittstelle.
     *
     * Fetch-Aufrufe senden JSON, klassische Formulare senden $_POST , 
     * beides muss dieselbe Aktion bedienen.
     *
     * @return array<string, mixed>
     */
    protected function jsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return $_POST;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : $_POST;
    }

    /** JSON-Fehlerantwort als Array (vom Front-Controller serialisiert). */
    protected function jsonError(string $message, int $status = 400): array
    {
        http_response_code($status);

        return ['success' => false, 'error' => $message];
    }
}
