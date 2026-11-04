<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RunnerClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * RunnerClient ohne echten Runner: erst mit austauschbarem Transport (reine
 * Payload-Logik), dann gegen einen lokalen Fake-Server (php -S) über curl und
 * über Streams.
 */
final class RunnerClientTest extends TestCase
{
    private const RUN_ID = 'r_0123456789abcdef01234567';

    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $router = '';

    public static function setUpBeforeClass(): void
    {
        self::$router = sys_get_temp_dir() . '/kw_fake_runner_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents(self::$router, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($path === '/healthz') { header('Content-Type: text/plain'); echo 'ok'; return; }
if (($_SERVER['HTTP_X_RUNNER_TOKEN'] ?? '') !== 'geheim') { http_response_code(401); echo json_encode(['error' => 'Nicht autorisiert.']); return; }
$body = json_decode(file_get_contents('php://input'), true);
if ($path === '/runs') { echo json_encode(['run_id' => 'r_0123456789abcdef01234567', 'echo' => $body]); return; }
if ($path === '/runs/r_0123456789abcdef01234567') { echo json_encode(['output' => [['stream' => 'stdout', 'text' => 'hi']], 'offset' => (int) ($_GET['offset'] ?? 0) + 1, 'finished' => false]); return; }
if ($path === '/runs/r_0123456789abcdef01234567/stdin' || $path === '/runs/r_0123456789abcdef01234567/stop') { echo json_encode(['ok' => true, 'got' => $body]); return; }
if ($path === '/tests') { echo json_encode(['results' => [['name' => 'x', 'ok' => true]]]); return; }
if ($path === '/langsam') { sleep(3); echo '{}'; return; }
if ($path === '/kaputt') { echo 'kein json'; return; }
if ($path === '/robo') { http_response_code(404); echo json_encode(['error' => 'Unbekannte Anfrage.']); return; }
http_response_code(404); echo json_encode(['error' => 'Dieser Lauf existiert nicht (mehr).']);
PHP);
        if (!function_exists('proc_open')) {
            return;
        }
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = random_int(20000, 40000);
            $process = @proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . $port, self::$router],
                [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );
            if (!is_resource($process)) {
                continue;
            }
            for ($i = 0; $i < 50; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($socket !== false) {
                    fclose($socket);
                    self::$server = $process;
                    self::$port = $port;

                    return;
                }
                usleep(100000);
            }
            proc_terminate($process);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        if (self::$router !== '' && is_file(self::$router)) {
            unlink(self::$router);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function transports(): array
    {
        return ['curl' => ['curl'], 'stream' => ['stream']];
    }

    private function live(string $transport, string $token = 'geheim', int $timeout = 5): RunnerClient
    {
        if (self::$server === null) {
            $this->markTestSkipped('Der lokale Fake-Server konnte nicht gestartet werden.');
        }
        if ($transport === 'curl' && !function_exists('curl_init')) {
            $this->markTestSkipped('curl-Erweiterung fehlt.');
        }

        return new RunnerClient([
            'url' => 'http://127.0.0.1:' . self::$port, 'token' => $token,
            'timeout_seconds' => $timeout, 'transport' => $transport,
        ]);
    }

    /** @param array<string, mixed>|null $answer */
    private function fake(array &$calls, int $status = 200, ?array $answer = null): RunnerClient
    {
        $transport = function (string $method, string $url, array $headers, ?string $body, int $timeout) use (&$calls, $status, $answer): array {
            $calls[] = compact('method', 'url', 'headers', 'body', 'timeout');

            return [$status, json_encode($answer ?? ['run_id' => self::RUN_ID, 'results' => [], 'ok' => true])];
        };

        return new RunnerClient(['url' => 'http://runner:8080/', 'token' => 'tok', 'timeout_seconds' => 20], $transport);
    }

    // --- Payload-Logik ----------------------------------------------------------

    public function testStartSendetJsonMitTokenAufDemRichtigenPfad(): void
    {
        $calls = [];
        $client = $this->fake($calls);
        $result = $client->start(['language' => 'java', 'files' => [['name' => 'Main.java', 'content' => 'Ä "x"']]]);

        $this->assertSame(self::RUN_ID, $result['run_id']);
        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('http://runner:8080/runs', $calls[0]['url']);
        $this->assertContains('X-Runner-Token: tok', $calls[0]['headers']);
        $this->assertContains('Content-Type: application/json', $calls[0]['headers']);
        $decoded = json_decode((string) $calls[0]['body'], true);
        $this->assertSame('Ä "x"', $decoded['files'][0]['content']);
        $this->assertStringContainsString('Ä', (string) $calls[0]['body'], 'Umlaute bleiben lesbar');
    }

    public function testPollStdinStopPfade(): void
    {
        $calls = [];
        $client = $this->fake($calls);
        $client->poll(self::RUN_ID, 7);
        $client->poll(self::RUN_ID, -3);
        $client->stdin(self::RUN_ID, "5\n");
        $client->stop(self::RUN_ID);

        $this->assertSame('GET', $calls[0]['method']);
        $this->assertSame('http://runner:8080/runs/' . self::RUN_ID . '?offset=7', $calls[0]['url']);
        $this->assertNull($calls[0]['body']);
        $this->assertStringEndsWith('?offset=0', $calls[1]['url']);
        $this->assertSame('/runs/' . self::RUN_ID . '/stdin', substr($calls[2]['url'], strlen('http://runner:8080')));
        $this->assertSame(['text' => "5\n"], json_decode((string) $calls[2]['body'], true));
        $this->assertStringEndsWith('/stop', $calls[3]['url']);
        $this->assertSame('POST', $calls[3]['method']);
        $this->assertNull($calls[3]['body']);
    }

    public function testUngueltigeRunIdWirdNichtVerschickt(): void
    {
        $calls = [];
        $client = $this->fake($calls);
        foreach (['', '../etc', 'r_zz', self::RUN_ID . '/x', 'R_0123456789ABCDEF01234567'] as $bad) {
            try {
                $client->poll($bad, 0);
                $this->fail('Erwartet: RuntimeException für ' . $bad);
            } catch (RuntimeException $e) {
                $this->assertSame(400, $e->getCode());
            }
        }
        $this->assertSame([], $calls);
    }

    public function testTestsTimeoutWaechstMitBudget(): void
    {
        $calls = [];
        $client = $this->fake($calls);
        $client->tests(['language' => 'java', 'tests' => []]);
        $client->tests(['limits' => ['total_seconds' => 200]]);
        $client->tests(['limits' => ['total_seconds' => 9999]]);

        $this->assertSame(120, $calls[0]['timeout']);
        $this->assertSame(230, $calls[1]['timeout']);
        $this->assertSame(330, $calls[2]['timeout']);
    }

    public function testNormaleAufrufeNutzenKurzenTimeout(): void
    {
        $calls = [];
        $client = $this->fake($calls);
        $client->start(['files' => []]);
        $client->poll(self::RUN_ID, 0);

        $this->assertSame(20, $calls[0]['timeout']);
        $this->assertSame(10, $calls[1]['timeout']);
    }

    public function testFehlendeAntwortfelderSindFehler(): void
    {
        $calls = [];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(502);
        $this->fake($calls, 200, ['ohne' => 'run_id'])->start([]);
    }

    public function testTestsOhneResultsIstFehler(): void
    {
        $calls = [];
        $this->expectException(RuntimeException::class);
        $this->fake($calls, 200, ['ok' => true])->tests([]);
    }

    /** @return array<string, array{0: int, 1: array<string, mixed>, 2: string}> */
    public static function fehlerFaelle(): array
    {
        return [
            'token' => [401, ['error' => 'Nicht autorisiert.'], 'nicht freigegeben'],
            'lauf weg' => [404, ['error' => 'Dieser Lauf existiert nicht (mehr).'], 'existiert nicht mehr'],
            'unbekannte funktion' => [404, ['error' => 'Unbekannte Anfrage.'], 'kennt diese Funktion nicht'],
            'validierung' => [400, ['error' => 'Ungültiger Dateiname.'], 'Ungültiger Dateiname.'],
            'ausgelastet' => [429, ['error' => 'Die Ausführungsumgebung ist gerade ausgelastet. Bitte versuche es gleich noch einmal.'], 'ausgelastet'],
            'ausgelastet ohne text' => [429, [], 'ausgelastet'],
            'nicht konfiguriert' => [503, ['error' => 'Die Ausführungsumgebung ist nicht konfiguriert (RUNNER_TOKEN fehlt).'], 'nicht konfiguriert'],
            'serverfehler' => [500, ['error' => 'internes Detail'], 'HTTP 500'],
        ];
    }

    /** @param array<string, mixed> $answer */
    #[\PHPUnit\Framework\Attributes\DataProvider('fehlerFaelle')]
    public function testFehlerstatusWerdenZuDeutschenMeldungen(int $status, array $answer, string $contains): void
    {
        $calls = [];
        try {
            $this->fake($calls, $status, $answer)->poll(self::RUN_ID, 0);
            $this->fail('Erwartet: RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame($status, $e->getCode());
            $this->assertStringContainsString($contains, $e->getMessage());
            $this->assertStringNotContainsString('internes Detail', $e->getMessage());
        }
    }

    public function testUnvollstaendigeKonfiguration(): void
    {
        foreach ([['url' => '', 'token' => 'x'], ['url' => 'http://runner:8080', 'token' => ''], []] as $config) {
            $client = new RunnerClient($config, static fn (): array => [200, '{}']);
            $this->assertFalse($client->health());
            try {
                $client->start([]);
                $this->fail('Erwartet: RuntimeException');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('nicht eingerichtet', $e->getMessage());
            }
        }
    }

    public function testHealthMitTransport(): void
    {
        $ok = new RunnerClient(['url' => 'http://r', 'token' => 't'], static fn (): array => [200, "ok\n"]);
        $bad = new RunnerClient(['url' => 'http://r', 'token' => 't'], static fn (): array => [200, 'nope']);
        $down = new RunnerClient(['url' => 'http://r', 'token' => 't'], static function (): array {
            throw new RuntimeException('weg', 0);
        });
        $this->assertTrue($ok->health());
        $this->assertFalse($bad->health());
        $this->assertFalse($down->health());
    }

    // --- Echte HTTP-Verbindung zum Fake-Server -------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('transports')]
    public function testLiveKompletterAblauf(string $transport): void
    {
        $client = $this->live($transport);
        $this->assertTrue($client->health());
        $start = $client->start(['language' => 'java', 'files' => [['name' => 'A.java', 'content' => 'ü']]]);
        $this->assertSame(self::RUN_ID, $start['run_id']);
        $this->assertSame('ü', $start['echo']['files'][0]['content']);
        $poll = $client->poll(self::RUN_ID, 4);
        $this->assertSame(5, $poll['offset']);
        $this->assertSame('hi', $poll['output'][0]['text']);
        $client->stdin(self::RUN_ID, 'x');
        $client->stop(self::RUN_ID);
        $this->assertTrue($client->tests([])['results'][0]['ok']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('transports')]
    public function testLiveFalschesTokenUndUnbekannteRoute(string $transport): void
    {
        try {
            $this->live($transport, 'falsch')->start([]);
            $this->fail('Erwartet: RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertStringContainsString('nicht freigegeben', $e->getMessage());
        }
        try {
            $this->live($transport)->robo([]);
            $this->fail('Erwartet: RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame(404, $e->getCode());
        }
        try {
            $this->live($transport)->poll('r_ffffffffffffffffffffffff', 0);
            $this->fail('Erwartet: RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('existiert nicht mehr', $e->getMessage());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('transports')]
    public function testLiveNichtErreichbar(string $transport): void
    {
        $client = new RunnerClient(['url' => 'http://127.0.0.1:1', 'token' => 't', 'timeout_seconds' => 2, 'transport' => $transport]);
        $this->assertFalse($client->health());
        try {
            $client->start([]);
            $this->fail('Erwartet: RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Die Ausführungsumgebung ist nicht erreichbar.', $e->getMessage());
            $this->assertSame(0, $e->getCode());
        }
    }
}
