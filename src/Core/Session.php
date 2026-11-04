<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session-Wrapper inkl. Flash-Messages.
 */
final class Session
{
    public function start(bool $secureCookie): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secureCookie,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('klausurwerk_session');
        session_start();
    }

    /**
     * Gibt die Sitzungssperre frei. PHP sperrt die Sitzungsdatei für die Dauer
     * eines Requests; ohne Freigabe warten parallele Anfragen derselben Person
     * (Herzschlag, Autosave, Ereignisse) aufeinander. Nach release() sind
     * Schreibzugriffe auf $_SESSION wirkungslos, Lesen geht weiter.
     */
    public function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Erneuert die Session-ID (nach Login/Logout gegen Session-Fixation). */
    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'],
                'domain' => $p['domain'],
                'secure' => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'],
            ]);
        }
        session_destroy();
    }

    /** Hinterlegt eine Flash-Message für den nächsten Request. */
    public function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type: string, message: string}> */
    public function pullFlashes(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return $flashes;
    }

    /** Merkt sich Formulareingaben für die Wiederanzeige nach Validierungsfehlern. */
    public function rememberInput(array $input): void
    {
        unset($input['password'], $input['password_confirm'], $input['_csrf']);
        $_SESSION['_old_input'] = $input;
    }

    /** @return array<string, mixed> */
    public function pullOldInput(): array
    {
        $old = $_SESSION['_old_input'] ?? [];
        unset($_SESSION['_old_input']);

        return $old;
    }
}
