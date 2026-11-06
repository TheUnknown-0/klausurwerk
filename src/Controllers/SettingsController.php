<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions as P;
use App\Services\EmailService;
use App\Services\Mailer;
use App\Services\UpdateCheck;
use App\Services\Uploads;

/**
 * Einstellungen der Anwendung: Schule/App, Logo, öffentliche Adresse, Anmeldecode für Lehrkräfte,
 * Takt von Herzschlag und Pult, Seitenpasswort (nur Admin) und Test-Mail.
 *
 * Alle Werte liegen als Key/Value in der settings-Tabelle (siehe Services\Settings).
 */
final class SettingsController extends Controller
{
    public const HEARTBEAT_DEFAULT = 2000;
    public const PULT_POLL_DEFAULT = 1500;
    public const INTERVAL_MIN = 500;
    public const INTERVAL_MAX = 30000;

    /** GET /admin/einstellungen */
    public function index(array $params): string
    {
        $this->requirePermission(P::EINSTELLUNGEN_SEHEN);
        $settings = $this->ctx->settings;
        $user = $this->ctx->auth->user();

        return $this->render('pages/settings/index', [
            'title' => 'Einstellungen',
            'schoolName' => (string) ($settings->get('school_name') ?? ''),
            'appName' => (string) ($settings->get('app_name') ?? ''),
            'logo' => $settings->get('school_logo'),
            'publicBaseUrl' => (string) ($settings->get('public_base_url') ?? ''),
            'baseIsGuessed' => $this->ctx->baseIsGuessed(),
            'guessedBase' => $this->ctx->publicBase(),
            'staffTwoFactor' => $settings->getBool('staff_two_factor', true),
            'mailConfigured' => $this->mailConfigured(),
            'heartbeatMs' => $settings->getInt('heartbeat_ms', self::HEARTBEAT_DEFAULT),
            'pultPollMs' => $settings->getInt('pult_poll_ms', self::PULT_POLL_DEFAULT),
            'intervalMin' => self::INTERVAL_MIN,
            'intervalMax' => self::INTERVAL_MAX,
            'sitePasswordEnabled' => $settings->getBool('site_password_enabled'),
            'sitePasswordSet' => $settings->get('site_password') !== null,
            'updateCheckEnabled' => $settings->getBool(UpdateCheck::SETTING_ENABLED, true),
            'updateCheckConfigured' => UpdateCheck::fromContext($this->ctx)->configured(),
            'testMailDefault' => (string) ($user['email'] ?? ''),
            'canEdit' => $this->ctx->auth->can(P::EINSTELLUNGEN_BEARBEITEN),
            'isAdmin' => $this->ctx->auth->isAdmin(),
            'minPasswordLength' => AuthController::MIN_PASSWORD_LENGTH,
            'pageScripts' => ['settings.js'],
            'pageStyles' => ['admin.css'],
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
        $heartbeat = $this->interval($_POST['heartbeat_ms'] ?? '');
        $pultPoll = $this->interval($_POST['pult_poll_ms'] ?? '');

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
        if ($heartbeat === null || $pultPoll === null) {
            $this->flash('error', sprintf(
                'Herzschlag und Pult-Abruf brauchen einen Wert zwischen %d und %d Millisekunden.',
                self::INTERVAL_MIN,
                self::INTERVAL_MAX,
            ));
            $this->redirect($back);
        }

        $changes = [];
        $compare = [
            'Schulname' => [(string) ($settings->get('school_name') ?? ''), $schoolName],
            'App-Name' => [(string) ($settings->get('app_name') ?? ''), $appName],
            'Öffentliche Adresse' => [(string) ($settings->get('public_base_url') ?? ''), $publicBaseUrl],
            'Herzschlag' => [(string) $settings->getInt('heartbeat_ms', self::HEARTBEAT_DEFAULT), (string) $heartbeat],
            'Pult-Abruf' => [(string) $settings->getInt('pult_poll_ms', self::PULT_POLL_DEFAULT), (string) $pultPoll],
        ];
        foreach ($compare as $label => [$before, $after]) {
            if ($before !== $after) {
                $changes[] = $label;
            }
        }

        $settings->set('school_name', $schoolName !== '' ? $schoolName : null);
        $settings->set('app_name', $appName !== '' ? $appName : null);
        $settings->set('public_base_url', $publicBaseUrl !== '' ? $publicBaseUrl : null);
        $settings->set('heartbeat_ms', (string) $heartbeat);
        $settings->set('pult_poll_ms', (string) $pultPoll);

        $twoFactor = ($_POST['staff_two_factor'] ?? '') === '1';
        if ($twoFactor !== $settings->getBool('staff_two_factor', true)) {
            $changes[] = 'Anmeldecode für Lehrkräfte: ' . ($twoFactor ? 'an' : 'aus');
        }
        $settings->set('staff_two_factor', $twoFactor ? '1' : '0');

        // Das Seitenpasswort schützt die gesamte Anwendung: nur Administrator:innen.
        $critical = false;
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
                $critical = true;
            }

            $enabled = ($_POST['site_password_enabled'] ?? '') === '1';
            if ($enabled && $settings->get('site_password') === null) {
                $this->flash('error', 'Bitte zuerst ein Seitenpasswort setzen, bevor der Schutz aktiviert wird.');
                $this->redirect($back);
            }
            if ($enabled !== $settings->getBool('site_password_enabled')) {
                $changes[] = 'Seitenpasswort-Schutz: ' . ($enabled ? 'aktiviert' : 'deaktiviert');
                $critical = true;
            }
            $settings->set('site_password_enabled', $enabled ? '1' : '0');

            $updateCheck = ($_POST['update_check_enabled'] ?? '') === '1';
            if ($updateCheck !== $settings->getBool(UpdateCheck::SETTING_ENABLED, true)) {
                $changes[] = 'Update-Prüfung: ' . ($updateCheck ? 'an' : 'aus');
            }
            $settings->set(UpdateCheck::SETTING_ENABLED, $updateCheck ? '1' : '0');
        }

        $this->ctx->audit->log(
            'einstellungen.speichern',
            $critical ? 'warning' : 'info',
            $changes === [] ? 'Keine Änderung' : implode(', ', $changes),
        );
        $this->flash('success', 'Die Einstellungen wurden gespeichert.');
        $this->redirect($back);
    }

    /** POST /admin/einstellungen/logo: Schullogo hochladen oder entfernen. */
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

    /** POST /admin/einstellungen/testmail: Test-Mail an die angegebene Adresse senden. */
    public function sendTestMail(array $params): string
    {
        $this->requirePermission(P::EINSTELLUNGEN_BEARBEITEN);
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/einstellungen');

        $to = trim((string) ($_POST['test_email'] ?? ''));
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->flash('error', 'Bitte eine gültige E-Mail-Adresse für die Test-Mail angeben.');
            $this->redirect($back);
        }
        if (!$this->mailConfigured()) {
            $this->flash('error', 'Der Mailserver ist nicht eingerichtet (MAIL_HOST und MAIL_FROM_ADDRESS in der .env).');
            $this->redirect($back);
        }

        $result = (new EmailService($this->ctx->db, new Mailer($this->ctx->config['mail']), $this->ctx->view))->sendTest($to);
        $this->ctx->audit->log('einstellungen.testmail', $result['success'] ? 'info' : 'warning', ($result['success'] ? 'Gesendet an ' : 'Fehlgeschlagen an ') . $to);

        if ($result['success']) {
            $this->flash('success', 'Die Test-Mail wurde an ' . $to . ' gesendet.');
        } else {
            $this->flash('error', 'Die Test-Mail ging nicht raus: ' . (string) $result['error']);
        }
        $this->redirect($back);
    }

    // ---------- Helfer ----------

    private function mailConfigured(): bool
    {
        $mail = $this->ctx->config['mail'] ?? [];

        return (string) ($mail['host'] ?? '') !== '' && (string) ($mail['from_address'] ?? '') !== '';
    }

    /** Millisekunden-Wert im erlaubten Bereich oder null. */
    private function interval(mixed $raw): ?int
    {
        $raw = trim((string) $raw);
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }
        $value = (int) $raw;

        return $value >= self::INTERVAL_MIN && $value <= self::INTERVAL_MAX ? $value : null;
    }
}
