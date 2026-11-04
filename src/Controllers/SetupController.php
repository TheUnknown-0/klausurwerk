<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;

/**
 * Erst-Einrichtung: Solange kein Benutzer existiert, führt /setup durch
 * das Anlegen des Admin-Kontos und des Schulnamens.
 */
final class SetupController extends Controller
{
    public function show(array $params): string
    {
        $this->assertSetupNeeded();

        return $this->render('pages/auth/setup', ['title' => 'Einrichtung', 'wide' => true], 'minimal');
    }

    public function run(array $params): string
    {
        $this->assertSetupNeeded();
        $this->requireCsrf();

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $schoolName = trim((string) ($_POST['school_name'] ?? ''));
        $firstname = trim((string) ($_POST['firstname'] ?? ''));
        $lastname = trim((string) ($_POST['lastname'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));

        $back = $this->ctx->url('/setup');
        $this->ctx->session->rememberInput($_POST);

        if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
            $this->flash('error', 'Der Admin-Benutzername darf 3 bis 50 Zeichen lang sein (Buchstaben, Zahlen, Punkt, Minus, Unterstrich).');
            $this->redirect($back);
        }
        if (mb_strlen($password) < AuthController::MIN_PASSWORD_LENGTH || $password !== $confirm) {
            $this->flash('error', 'Das Passwort muss mindestens 8 Zeichen lang sein und beide Eingaben müssen übereinstimmen.');
            $this->redirect($back);
        }
        if ($schoolName === '') {
            $this->flash('error', 'Bitte den Namen der Schule angeben.');
            $this->redirect($back);
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->flash('error', 'Bitte eine gültige E-Mail-Adresse angeben (oder das Feld leer lassen).');
            $this->redirect($back);
        }

        $this->ctx->db->transaction(function () use ($username, $password, $schoolName, $firstname, $lastname, $email): void {
            $this->ctx->settings->set('school_name', $schoolName);
            $this->ctx->db->run(
                "INSERT INTO users (username, email, password, role, firstname, lastname)
                 VALUES (?, ?, ?, 'admin', ?, ?)",
                [$username, $email !== '' ? $email : null, password_hash($password, PASSWORD_DEFAULT),
                 $firstname !== '' ? $firstname : 'Admin', $lastname],
            );
        });

        $this->ctx->audit->log('Erst-Einrichtung abgeschlossen', 'info', "Admin: {$username}, Schule: {$schoolName}");

        $user = $this->ctx->db->fetchOne('SELECT * FROM users WHERE username = ?', [$username]);
        $this->ctx->auth->loginAs($user);
        $this->flash('success', 'Einrichtung abgeschlossen. Willkommen! Lege als Nächstes Lehrkräfte, Kurse und Schüler:innen an.');
        $this->redirect($this->ctx->url('/dashboard'));
    }

    private function assertSetupNeeded(): void
    {
        if ($this->ctx->db->fetchValue('SELECT 1 FROM users LIMIT 1') !== null) {
            throw new HttpException(404);
        }
    }
}
