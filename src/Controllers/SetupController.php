<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\FirstSetup;

/**
 * Erst-Einrichtung: Solange kein Benutzer existiert, führt /setup durch
 * das Anlegen des Admin-Kontos und des Schulnamens. Dieselbe Logik nutzt
 * bin/setup.php auf der Kommandozeile (Services\FirstSetup).
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

        $back = $this->ctx->url('/setup');
        $this->ctx->session->rememberInput($_POST);

        [$data, $error] = FirstSetup::validate($_POST);
        if ($data === null) {
            $this->flash('error', (string) $error);
            $this->redirect($back);
        }

        try {
            $userId = $this->setup()->run($data);
        } catch (\RuntimeException) {
            throw new HttpException(404);
        }
        $username = $data['username'];
        $schoolName = $data['school_name'];

        $this->ctx->audit->log('Erst-Einrichtung abgeschlossen', 'info', "Admin: {$username}, Schule: {$schoolName}", $userId, $username);

        $user = $this->ctx->db->fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);
        $this->ctx->auth->loginAs($user);
        $this->flash('success', 'Einrichtung abgeschlossen. Willkommen! Lege als Nächstes Lehrkräfte, Kurse und Schüler:innen an.');
        $this->redirect($this->ctx->url('/dashboard'));
    }

    private function assertSetupNeeded(): void
    {
        if (!$this->setup()->isNeeded()) {
            throw new HttpException(404);
        }
    }

    private function setup(): FirstSetup
    {
        return new FirstSetup($this->ctx->db, $this->ctx->settings);
    }
}
