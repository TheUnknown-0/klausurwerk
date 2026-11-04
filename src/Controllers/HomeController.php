<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Rollenabhängiger Einstieg.
 */
final class HomeController extends Controller
{
    /** GET / */
    public function index(array $params): string
    {
        $user = $this->ctx->auth->user();
        if ($user === null) {
            if ($this->ctx->db->fetchValue('SELECT 1 FROM users LIMIT 1') === null) {
                $this->redirect($this->ctx->url('/setup'));
            }
            $this->redirect($this->ctx->url('/login'));
        }
        if ((int) $user['must_change_password'] === 1) {
            $this->redirect($this->ctx->url('/passwort-aendern'));
        }

        $this->redirect($this->ctx->url($user['role'] === 'student' ? '/meine-klausuren' : '/dashboard'));
    }

    /** GET /keine-rechte: Konto ohne jegliche Berechtigung. */
    public function noPermissions(array $params): string
    {
        $this->requireLogin();

        return $this->render('pages/no-permissions', ['title' => 'Keine Berechtigungen']);
    }
}
