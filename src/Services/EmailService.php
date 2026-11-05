<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\View;

/**
 * E-Mail-Versand: Anmeldecodes für Lehrkräfte/Admins und Test-Mail.
 * Jeder Versand wird in email_log protokolliert.
 */
final class EmailService
{
    public function __construct(
        private readonly Database $db,
        private readonly Mailer $mailer,
        private readonly View $view,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->mailer->isConfigured();
    }

    /** @param array<string, mixed> $user Erwartet id, email, firstname, lastname. */
    public function sendLoginCode(array $user, string $code): bool
    {
        $name = trim((string) $user['firstname'] . ' ' . (string) $user['lastname']);
        $subject = 'Dein Anmeldecode';
        $text = "Hallo,\n\ndein Anmeldecode lautet: {$code}\n\n"
            . "Er ist 10 Minuten gültig und nur einmal verwendbar. "
            . "Falls du diese Anmeldung nicht angefordert hast, kannst du diese Mail ignorieren.";
        $html = $this->view->renderPartial('emails/login-code', ['name' => $name, 'code' => $code]);

        try {
            $this->mailer->send((string) $user['email'], $name, $subject, $text, $html);
            $this->log(null, (int) $user['id'], (string) $user['email'], $subject, 'login_code', 'sent', null);

            return true;
        } catch (\RuntimeException $e) {
            $this->log(null, (int) $user['id'], (string) $user['email'], $subject, 'login_code', 'failed', mb_substr($e->getMessage(), 0, 500));

            return false;
        }
    }

    /** @return array{success: bool, error: ?string} */
    public function sendTest(string $toEmail): array
    {
        $subject = 'Test-Mail von Klausurwerk';
        $text = "Das ist eine Test-Mail von Klausurwerk.\n\n"
            . 'Wenn du diese Mail liest, ist der E-Mail-Versand korrekt eingerichtet.';
        $html = '<p>Das ist eine Test-Mail von Klausurwerk.</p>'
            . '<p>Wenn du diese Mail liest, ist der E-Mail-Versand korrekt eingerichtet.</p>';

        try {
            $this->mailer->send($toEmail, '', $subject, $text, $html);
            $this->log(null, null, $toEmail, $subject, 'test', 'sent', null);

            return ['success' => true, 'error' => null];
        } catch (\RuntimeException $e) {
            $this->log(null, null, $toEmail, $subject, 'test', 'failed', mb_substr($e->getMessage(), 0, 500));

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ---------- Helfer ----------

    private function log(?int $unused, ?int $userId, string $recipient, string $subject, string $template, string $status, ?string $error): void
    {
        $this->db->run(
            'INSERT INTO email_log (user_id, recipient, subject, template, status, error) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $recipient, mb_substr($subject, 0, 255), $template, $status, $error],
        );
    }
}
