<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Schlanker SMTP-Client ohne externe Abhängigkeit (Projekt hat bewusst
 * keine Composer-Pakete, vgl. lib/fpdf für PDFs). Unterstützt STARTTLS,
 * implizites TLS und AUTH LOGIN — das deckt die üblichen Anbieter
 * (Schul-Provider, Gmail, Office 365, o.ä.) ab.
 *
 * Wirft RuntimeException bei jedem Fehler (Verbindung, Auth, Ablehnung durch
 * den Server) — der Aufrufer entscheidet, was mit dem Fehler passiert
 * (z. B. als 'failed' ins email_log schreiben).
 */
final class Mailer
{
    private const TIMEOUT = 15;

    /** @param array{host: string, port: int, encryption: string, username: string, password: string, from_address: string, from_name: string} $config */
    public function __construct(private readonly array $config)
    {
    }

    /** SMTP-Host und Absenderadresse gesetzt? Ohne das bleibt der Versand deaktiviert. */
    public function isConfigured(): bool
    {
        return $this->config['host'] !== '' && $this->config['from_address'] !== '';
    }

    /**
     * Sendet eine Mail mit Text- und HTML-Teil (multipart/alternative).
     *
     * @throws \RuntimeException Verbindungs-, Auth- oder Protokollfehler.
     */
    public function send(string $toEmail, string $toName, string $subject, string $text, string $html): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('E-Mail-Versand ist nicht konfiguriert (MAIL_HOST fehlt).');
        }

        $socket = $this->connect();

        try {
            $this->expect($socket, 220);
            $this->command($socket, 'EHLO ' . $this->heloHost(), 250);

            if ($this->config['encryption'] === 'tls') {
                $this->command($socket, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS fehlgeschlagen.');
                }
                // Nach STARTTLS verlangen die meisten Server ein erneutes EHLO.
                $this->command($socket, 'EHLO ' . $this->heloHost(), 250);
            }

            if ($this->config['username'] !== '') {
                $this->command($socket, 'AUTH LOGIN', 334);
                $this->command($socket, base64_encode($this->config['username']), 334);
                $this->command($socket, base64_encode($this->config['password']), 235);
            }

            $this->command($socket, 'MAIL FROM:<' . $this->config['from_address'] . '>', 250);
            $this->command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->command($socket, 'DATA', 354);

            $message = $this->buildMessage($toEmail, $toName, $subject, $text, $html);
            $this->write($socket, $this->dotStuff($message) . "\r\n.\r\n");
            $this->expect($socket, 250);

            $this->command($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }
    }

    /** @return resource */
    private function connect()
    {
        $host = $this->config['encryption'] === 'ssl'
            ? 'ssl://' . $this->config['host']
            : $this->config['host'];

        $socket = @stream_socket_client(
            $host . ':' . $this->config['port'],
            $errno,
            $errstr,
            self::TIMEOUT,
            STREAM_CLIENT_CONNECT,
        );
        if ($socket === false) {
            throw new \RuntimeException(sprintf('Verbindung zu %s:%d fehlgeschlagen: %s', $this->config['host'], $this->config['port'], $errstr));
        }
        stream_set_timeout($socket, self::TIMEOUT);

        return $socket;
    }

    /** @param resource $socket @param int|list<int> $expectedCode */
    private function command($socket, string $line, int|array $expectedCode): string
    {
        $this->write($socket, $line . "\r\n");

        return $this->expect($socket, $expectedCode);
    }

    /** @param resource $socket */
    private function write($socket, string $data): void
    {
        if (@fwrite($socket, $data) === false) {
            throw new \RuntimeException('Schreiben zum Mailserver fehlgeschlagen.');
        }
    }

    /** @param resource $socket @param int|list<int> $expectedCode @return string Letzte Antwortzeile. */
    private function expect($socket, int|array $expectedCode): string
    {
        $codes = is_array($expectedCode) ? $expectedCode : [$expectedCode];
        $last = '';

        do {
            $line = fgets($socket, 1024);
            if ($line === false) {
                throw new \RuntimeException('Der Mailserver hat die Verbindung ohne Antwort beendet.');
            }
            $last = $line;
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($last, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException(sprintf('Unerwartete Antwort vom Mailserver: %s', trim($last)));
        }

        return $last;
    }

    private function heloHost(): string
    {
        $host = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';

        return preg_replace('/[^a-zA-Z0-9.\-]/', '', explode(':', (string) $host)[0]) ?: 'localhost';
    }

    private function buildMessage(string $toEmail, string $toName, string $subject, string $text, string $html): string
    {
        $boundary = 'gat-' . bin2hex(random_bytes(12));
        $fromHeader = $this->encodeHeaderWord($this->config['from_name']) . ' <' . $this->config['from_address'] . '>';
        $toHeader = $toName !== '' ? $this->encodeHeaderWord($toName) . ' <' . $toEmail . '>' : $toEmail;

        $headers = [
            'Date' => date('r'),
            'From' => $fromHeader,
            'To' => $toHeader,
            'Subject' => $this->encodeHeaderWord($subject),
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . '@' . $this->heloHost() . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
        ];

        $lines = [];
        foreach ($headers as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }
        $lines[] = '';
        $lines[] = '--' . $boundary;
        $lines[] = 'Content-Type: text/plain; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: base64';
        $lines[] = '';
        $lines[] = chunk_split(base64_encode($text));
        $lines[] = '--' . $boundary;
        $lines[] = 'Content-Type: text/html; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: base64';
        $lines[] = '';
        $lines[] = chunk_split(base64_encode($html));
        $lines[] = '--' . $boundary . '--';

        return implode("\r\n", $lines);
    }

    /** RFC 2047 „encoded word" — nötig, sobald Umlaute im Betreff/Namen stecken. */
    private function encodeHeaderWord(string $value): string
    {
        return preg_match('/^[\x20-\x7E]*$/', $value) === 1
            ? $value
            : '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** RFC 5321: Zeilen, die mit einem Punkt beginnen, bekommen einen zweiten Punkt vorangestellt. */
    private function dotStuff(string $message): string
    {
        return preg_replace('/^\./m', '..', $message) ?? $message;
    }
}
