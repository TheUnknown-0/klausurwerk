<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * HTTP-Client für den Runner-Container (siehe docs/API.md, Abschnitt 5).
 *
 * Alle Methoden liefern die dekodierte JSON-Antwort des Runners und werfen bei
 * Problemen eine \RuntimeException mit deutscher, für Nutzer:innen geeigneter
 * Meldung; der HTTP-Status steht in getCode() (0 = keine Antwort).
 *
 * Konfiguration: config('runner') mit url, token, timeout_seconds. Optional
 * 'transport' => 'curl' | 'stream' (Standard: curl, falls die Erweiterung da ist).
 */
final class RunnerClient
{
    private const RUN_ID = '/^r_[0-9a-f]{24}$/';
    private const CONNECT_TIMEOUT = 3;
    /** Obergrenze für ein /tests-Gesamtbudget, passend zum Runner. */
    private const TESTS_MAX_SECONDS = 300;

    private string $url;
    private string $token;
    private int $timeout;
    private string $transportName;

    /** @var (callable(string, string, array<int, string>, ?string, int): array{0: int, 1: string})|null */
    private $transport;

    /**
     * @param array<string, mixed> $runnerConfig
     * @param (callable(string, string, array<int, string>, ?string, int): array{0: int, 1: string})|null $transport
     *        Austauschbarer Transport (Methode, URL, Header, Body, Timeout) => [Status, Body];
     *        wirft RuntimeException mit Code 0 bei Verbindungsproblemen. Nur für Tests.
     */
    public function __construct(array $runnerConfig, ?callable $transport = null)
    {
        $this->url = rtrim((string) ($runnerConfig['url'] ?? ''), '/');
        $this->token = (string) ($runnerConfig['token'] ?? '');
        $this->timeout = max(1, (int) ($runnerConfig['timeout_seconds'] ?? 20));
        $wanted = (string) ($runnerConfig['transport'] ?? 'curl');
        $this->transportName = ($wanted === 'curl' && function_exists('curl_init')) ? 'curl' : 'stream';
        $this->transport = $transport;
    }

    /**
     * Lauf starten.
     *
     * @param array<string, mixed> $payload language, java_version, files, main_file, mode, stdin, gui, limits
     * @return array{run_id: string}
     */
    public function start(array $payload): array
    {
        $response = $this->request('POST', '/runs', $payload, $this->timeout);
        if (!isset($response['run_id']) || !is_string($response['run_id'])) {
            throw new RuntimeException('Die Ausführungsumgebung hat unerwartet geantwortet.', 502);
        }

        return $response;
    }

    /**
     * Ausgabe ab Index $offset samt Status abrufen.
     *
     * @return array<string, mixed> output, offset, finished, status, exit_code, timed_out,
     *                              compile_errors, compile_ok, message, killed_reason, gui_frames
     */
    public function poll(string $runId, int $offset): array
    {
        $this->assertRunId($runId);

        return $this->request('GET', '/runs/' . $runId . '?offset=' . max(0, $offset), null, min($this->timeout, 10));
    }

    public function stdin(string $runId, string $text): void
    {
        $this->assertRunId($runId);
        $this->request('POST', '/runs/' . $runId . '/stdin', ['text' => $text], min($this->timeout, 10));
    }

    public function stop(string $runId): void
    {
        $this->assertRunId($runId);
        $this->request('POST', '/runs/' . $runId . '/stop', null, min($this->timeout, 10));
    }

    /**
     * Versteckte Tests synchron ausführen.
     *
     * @param array<string, mixed> $payload language, java_version, files, main_file, tests, sql_setup,
     *                                      limits (seconds, memory_mb, total_seconds), reveal
     * @return array{results: array<int, array<string, mixed>>}
     */
    public function tests(array $payload): array
    {
        $budget = 0;
        if (isset($payload['limits']['total_seconds']) && is_numeric($payload['limits']['total_seconds'])) {
            $budget = (int) $payload['limits']['total_seconds'];
        }
        // Der Runner rechnet ohne Angabe mit Anzahl Tests mal Zeitlimit; hier großzügig warten.
        $timeout = min(self::TESTS_MAX_SECONDS + 30, max($this->timeout, $budget > 0 ? $budget + 30 : 120));
        $response = $this->request('POST', '/tests', $payload, $timeout);
        if (!isset($response['results']) || !is_array($response['results'])) {
            throw new RuntimeException('Die Ausführungsumgebung hat unerwartet geantwortet.', 502);
        }

        return $response;
    }

    /**
     * Robo-Programm in einer Testwelt ausführen (Stufe 4).
     *
     * @param array<string, mixed> $payload language, files, main_file, world, goal
     * @return array<string, mixed> ok, trace, final_world, message
     */
    public function robo(array $payload): array
    {
        return $this->request('POST', '/robo', $payload, max($this->timeout, 30));
    }

    /** true, wenn der Runner auf /healthz mit "ok" antwortet (wirft nie). */
    public function health(): bool
    {
        if ($this->url === '') {
            return false;
        }
        try {
            [$status, $body] = $this->send('GET', '/healthz', [], null, 3);
        } catch (RuntimeException) {
            return false;
        }

        return $status === 200 && trim($body) === 'ok';
    }

    // --- Intern ---------------------------------------------------------------

    private function assertRunId(string $runId): void
    {
        if (preg_match(self::RUN_ID, $runId) !== 1) {
            throw new RuntimeException('Ungültige Lauf-Kennung.', 400);
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload, int $timeout): array
    {
        if ($this->url === '' || $this->token === '') {
            throw new RuntimeException('Die Ausführungsumgebung ist nicht eingerichtet.', 0);
        }
        $body = null;
        if ($payload !== null) {
            try {
                $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            } catch (\JsonException) {
                throw new RuntimeException('Die Anfrage an die Ausführungsumgebung konnte nicht erstellt werden.', 400);
            }
        }
        $headers = ['X-Runner-Token: ' . $this->token, 'Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        [$status, $raw] = $this->send($method, $path, $headers, $body, $timeout);

        $data = json_decode($raw, true);
        if ($status >= 200 && $status < 300) {
            if (!is_array($data)) {
                throw new RuntimeException('Die Ausführungsumgebung hat unerwartet geantwortet.', 502);
            }

            return $data;
        }
        throw new RuntimeException($this->errorMessage($status, is_array($data) ? $data : []), $status);
    }

    /** @param array<string, mixed> $data */
    private function errorMessage(int $status, array $data): string
    {
        $message = isset($data['error']) && is_string($data['error']) ? trim($data['error']) : '';

        return match (true) {
            $status === 401, $status === 403 => 'Die Verbindung zur Ausführungsumgebung ist nicht freigegeben (Zugangsschlüssel stimmt nicht).',
            $status === 404 && $message === 'Unbekannte Anfrage.' => 'Die Ausführungsumgebung kennt diese Funktion nicht.',
            $status === 404 => 'Dieser Lauf existiert nicht mehr.',
            $status === 503 && $message !== '' => $message,
            in_array($status, [400, 409, 413, 429], true) && $message !== '' => $message,
            $status === 429 => 'Die Ausführungsumgebung ist gerade ausgelastet. Bitte versuche es gleich noch einmal.',
            default => 'Die Ausführungsumgebung hat einen Fehler gemeldet (HTTP ' . $status . ').',
        };
    }

    /**
     * @param array<int, string> $headers
     * @return array{0: int, 1: string}
     */
    private function send(string $method, string $path, array $headers, ?string $body, int $timeout): array
    {
        $url = $this->url . $path;
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body, $timeout);
        }

        return $this->transportName === 'curl'
            ? $this->sendCurl($method, $url, $headers, $body, $timeout)
            : $this->sendStream($method, $url, $headers, $body, $timeout);
    }

    /** @return array{0: int, 1: string} */
    private function sendCurl(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Die Ausführungsumgebung ist nicht erreichbar.', 0);
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false || $status === 0) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new RuntimeException('Die Ausführungsumgebung antwortet nicht rechtzeitig.', 0);
            }
            throw new RuntimeException('Die Ausführungsumgebung ist nicht erreichbar.', 0);
        }

        return [$status, (string) $raw];
    }

    /** @return array{0: int, 1: string} */
    private function sendStream(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $http = [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ];
        if ($body !== null) {
            $http['content'] = $body;
        }
        $context = stream_context_create(['http' => $http]);
        $started = microtime(true);
        $raw = @file_get_contents($url, false, $context);
        /** @var array<int, string> $responseHeaders gesetzt von file_get_contents */
        $responseHeaders = $http_response_header ?? [];
        $status = 0;
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        if ($raw === false || $status === 0) {
            if (microtime(true) - $started >= $timeout - 0.5) {
                throw new RuntimeException('Die Ausführungsumgebung antwortet nicht rechtzeitig.', 0);
            }
            throw new RuntimeException('Die Ausführungsumgebung ist nicht erreichbar.', 0);
        }

        return [$status, $raw];
    }
}
