<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions as P;
use App\Services\Uploads;

/**
 * Einstellungen der Anwendung: Schule/App, Logo, öffentliche Adresse,
 * Seitenpasswort (nur Admin) und Selbstbedienung für Schüler:innen.
 *
 * Einzige Instanz (kein Mandantenbezug) — alle Werte liegen als
 * Key/Value in der settings-Tabelle (siehe Services\Settings).
 */
final class SettingsController extends Controller
{
    /** GET /admin/einstellungen */
    public function index(array $params): string
    {
        $this->requirePermission(P::EINSTELLUNGEN_SEHEN);
        $settings = $this->ctx->settings;

        return $this->render('pages/settings/index', [
            'title' => 'Einstellungen',
            'schoolName' => (string) ($settings->get('school_name') ?? ''),
            'appName' => (string) ($settings->get('app_name') ?? ''),
            'logo' => $settings->get('school_logo'),
            'publicBaseUrl' => (string) ($settings->get('public_base_url') ?? ''),
            'baseIsGuessed' => $this->ctx->baseIsGuessed(),
            'guessedBase' => $this->ctx->publicBase(),
            'sitePasswordEnabled' => $settings->getBool('site_password_enabled'),
            'sitePasswordSet' => $settings->get('site_password') !== null,
            'studentSelfService' => $settings->getBool('student_self_service', true),
            'canEdit' => $this->ctx->auth->can(P::EINSTELLUNGEN_BEARBEITEN),
            'isAdmin' => $this->ctx->auth->isAdmin(),
            'minPasswordLength' => AuthController::MIN_PASSWORD_LENGTH,
        ]);
    }

    /** POST /admin/einstellungen */
    public function save(array $params): string
    {
        $this->requirePermission(P::EINSTELLUNGEN_BEARBEITEN);
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/einstellungen');
        $settings = $this->ctx->settings;

        $schoolName = trim((string) ($_POST['school_name'] ?? ''));
        $appName = trim((string) ($_POST['app_name'] ?? ''));
        $publicBaseUrl = trim((string) ($_POST['public_base_url'] ?? ''));

        if (mb_strlen($schoolName) > 200 || mb_strlen($appName) > 100) {
            $this->flash('error', 'Schulname oder App-Name ist zu lang.');
            $this->redirect($back);
        }
        if ($publicBaseUrl !== '') {
            $valid = filter_var($publicBaseUrl, FILTER_VALIDATE_URL) !== false
                && (str_starts_with($publicBaseUrl, 'http://') || str_starts_with($publicBaseUrl, 'https://'));
            if (!$valid) {
                $this->flash('error', 'Die öffentliche Adresse muss mit http:// oder https:// beginnen und gültig sein.');
                $this->redirect($back);
            }
            $publicBaseUrl = rtrim($publicBaseUrl, '/');
        }

        $changes = [];
        if ($schoolName !== (string) ($settings->get('school_name') ?? '')) {
            $changes[] = 'Schulname';
        }
        if ($appName !== (string) ($settings->get('app_name') ?? '')) {
            $changes[] = 'App-Name';
        }
        if ($publicBaseUrl !== (string) ($settings->get('public_base_url') ?? '')) {
            $changes[] = 'Öffentliche Adresse';
        }

        $settings->set('school_name', $schoolName !== '' ? $schoolName : null);
        $settings->set('app_name', $appName !== '' ? $appName : null);
        $settings->set('public_base_url', $publicBaseUrl !== '' ? $publicBaseUrl : null);

        $selfService = ($_POST['student_self_service'] ?? '') === '1';
        if ($selfService !== $settings->getBool('student_self_service', true)) {
            $changes[] = 'Selbstbedienung: ' . ($selfService ? 'aktiviert' : 'deaktiviert');
        }
        $settings->set('student_self_service', $selfService ? '1' : '0');

        // Seitenpasswort ist sicherheitskritisch für die gesamte Anwendung —
        // nur Administrator:innen dürfen es setzen oder den Schutz umschalten.
        if ($this->ctx->auth->isAdmin()) {
            $newPassword = (string) ($_POST['site_password'] ?? '');
            if ($newPassword !== '') {
                if (mb_strlen($newPassword) < AuthController::MIN_PASSWORD_LENGTH) {
                    $this->flash('error', 'Das Seitenpasswort muss mindestens '
                        . AuthController::MIN_PASSWORD_LENGTH . ' Zeichen lang sein.');
                    $this->redirect($back);
                }
                $settings->set('site_password', password_hash($newPassword, PASSWORD_DEFAULT));
                $changes[] = 'Seitenpasswort geändert';
            }

            $enabled = ($_POST['site_password_enabled'] ?? '') === '1';
            if ($enabled && $settings->get('site_password') === null) {
                $this->flash('error', 'Bitte zuerst ein Seitenpasswort setzen, bevor der Schutz aktiviert wird.');
                $this->redirect($back);
            }
            if ($enabled !== $settings->getBool('site_password_enabled')) {
                $changes[] = 'Seitenpasswort-Schutz: ' . ($enabled ? 'aktiviert' : 'deaktiviert');
            }
            $settings->set('site_password_enabled', $enabled ? '1' : '0');
        }

        $this->ctx->audit->log(
            'einstellungen.speichern',
            in_array('Seitenpasswort geändert', $changes, true) ? 'warning' : 'info',
            $changes === [] ? 'Keine Änderung' : implode(', ', $changes),
        );
        $this->flash('success', 'Die Einstellungen wurden gespeichert.');
        $this->redirect($back);
    }

    /** POST /admin/einstellungen/logo — Schullogo hochladen oder entfernen. */
    public function saveLogo(array $params): string
    {
        $this->requirePermission(P::EINSTELLUNGEN_BEARBEITEN);
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/einstellungen');
        $settings = $this->ctx->settings;
        $uploads = new Uploads($this->ctx->config['uploads']['dir']);
        $current = $settings->get('school_logo');

        if (isset($_POST['remove'])) {
            if ($current !== null) {
                $uploads->delete('logos', $current);
                $settings->delete('school_logo');
            }
            $this->ctx->audit->log('einstellungen.logo_entfernen', 'info', 'Schullogo entfernt');
            $this->flash('success', 'Logo entfernt.');
            $this->redirect($back);
        }

        $stored = $uploads->store(
            $_FILES['logo'] ?? [],
            'logos',
            ['png', 'jpg', 'jpeg', 'webp', 'svg'],
            (int) ($this->ctx->config['uploads']['max_logo_bytes'] ?? 2097152),
        );
        if ($current !== null) {
            $uploads->delete('logos', $current);
        }
        $settings->set('school_logo', $stored['filename']);

        $this->ctx->audit->log('einstellungen.logo', 'info', $stored['original_name']);
        $this->flash('success', 'Logo gespeichert.');
        $this->redirect($back);
    }
}
