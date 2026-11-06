<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Settings;
use App\Services\UpdateCheck;
use Tests\Support\DatabaseTestCase;

/** Update-Prüfung: Zeitpunkte, Ergebnis in den Einstellungen, Fehler und Abschalten. */
final class UpdateCheckTest extends DatabaseTestCase
{
    private const REMOTE = "# Änderungen\n\n## 1.5.0 (2026-12-01)\n\n- Neu B\n\n## 1.4.0 (2026-11-20)\n\n- Neu A\n\n## 1.3.0 (2026-11-06)\n\n- Alt\n";

    private Settings $settings;
    private int $calls = 0;
    /** @var list<?string> ETags, mit denen gefragt wurde */
    private array $sentEtags = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new Settings($this->db);
        foreach (['update_checked_at', 'update_latest_version', 'update_news', 'update_error', 'update_etag',
                     'update_blocked_until', UpdateCheck::SETTING_ENABLED] as $key) {
            $this->settings->delete($key);
        }
    }

    /** @param string|array<string, mixed>|null $remote Text, Antwort als Array oder null = keine Verbindung */
    private function check(string $installed = '1.3.0', string|array|null $remote = self::REMOTE, string $url = 'https://example.org/CHANGELOG.md'): UpdateCheck
    {
        $fetcher = function (string $u, ?string $etag) use ($remote): string|array {
            $this->calls++;
            $this->sentEtags[] = $etag;
            if ($remote === null) {
                throw new \RuntimeException('Keine Verbindung zu example.org.');
            }

            return $remote;
        };

        return new UpdateCheck($this->settings, $url, $fetcher, $installed);
    }

    public function testFindetNeueVersionMitAenderungen(): void
    {
        $check = $this->check();
        self::assertTrue($check->isDue(true));
        $check->run();
        $state = $check->state();

        self::assertTrue($state['available']);
        self::assertSame('1.5.0', $state['latest']);
        self::assertSame(['1.5.0', '1.4.0'], array_column($state['news'], 'version'));
        self::assertNull($state['error']);
        self::assertNotNull($state['checkedAt']);
    }

    public function testNachTeilUpdateNurFehlendeAenderungen(): void
    {
        $this->check()->run();
        $state = $this->check('1.4.0')->state();
        self::assertTrue($state['available']);
        self::assertSame(['1.5.0'], array_column($state['news'], 'version'));

        $state = $this->check('1.5.0')->state();
        self::assertFalse($state['available']);
        self::assertSame([], $state['news']);
    }

    public function testZeitpunkte(): void
    {
        $check = $this->check();
        $now = 1_800_000_000;
        $check->run($now);

        self::assertFalse($check->isDue(true, $now + 30), 'Anmeldung kurz danach fragt nicht erneut.');
        self::assertTrue($check->isDue(true, $now + UpdateCheck::LOGIN_GAP));
        self::assertFalse($check->isDue(false, $now + 3600), 'Täglich: nach einer Stunde noch nicht.');
        self::assertTrue($check->isDue(false, $now + UpdateCheck::DAILY));
        self::assertFalse($check->manualAllowed($now + 5), 'Knopf: höchstens einmal je Minute.');
        self::assertSame(60, UpdateCheck::MIN_GAP);
        self::assertTrue($check->manualAllowed($now + UpdateCheck::MANUAL_GAP));
    }

    public function testFehlerWirdGemerktUndAlterStandBleibt(): void
    {
        $this->check()->run();
        $failing = $this->check('1.3.0', null);
        $failing->run();
        $state = $failing->state();

        self::assertSame('Keine Verbindung zu example.org.', $state['error']);
        self::assertTrue($state['available'], 'Ein bekanntes Update bleibt sichtbar.');

        $this->check('1.3.0', '<html>404</html>')->run();
        self::assertSame('Die Antwort enthielt keine Versionsnummer.', $this->check()->state()['error']);
    }

    public function testAbschalten(): void
    {
        $this->settings->set(UpdateCheck::SETTING_ENABLED, '0');
        $check = $this->check();
        self::assertFalse($check->enabled());
        self::assertFalse($check->isDue(true));

        $off = $this->check('1.3.0', self::REMOTE, 'off');
        self::assertFalse($off->configured());
        $off->run();
        self::assertSame(0, $this->calls, 'Bei UPDATE_CHECK_URL=off wird nie gefragt.');
        self::assertFalse($off->state()['available']);
    }

    public function testEtagUndUnveraenderteDatei(): void
    {
        $this->check('1.3.0', ['status' => 200, 'body' => self::REMOTE, 'etag' => '"abc123"', 'retryAfter' => null])->run();
        $check = $this->check('1.3.0', ['status' => 304, 'body' => '', 'etag' => null, 'retryAfter' => null]);
        $check->run();

        self::assertSame([null, '"abc123"'], $this->sentEtags, 'Zweite Anfrage ist bedingt.');
        $state = $check->state();
        self::assertNull($state['error']);
        self::assertSame('1.5.0', $state['latest'], 'Bei 304 gilt der gespeicherte Stand.');
        self::assertSame(['1.5.0', '1.4.0'], array_column($state['news'], 'version'));
    }

    public function testDrosselungMitRetryAfter(): void
    {
        $now = 1_800_000_000;
        $check = $this->check('1.3.0', ['status' => 429, 'body' => '', 'etag' => null, 'retryAfter' => 7200]);
        $check->run($now);

        self::assertSame($now + 7200, $check->blockedUntil());
        self::assertStringContainsString('HTTP 429', (string) $check->state($now)['error']);
        self::assertFalse($check->isDue(true, $now + 3600), 'Während der Pause nicht automatisch.');
        self::assertFalse($check->manualAllowed($now + 3600), 'Während der Pause auch nicht von Hand.');
        self::assertTrue($check->manualAllowed($now + 7200));

        // Ohne Retry-After mindestens eine Stunde.
        $this->check('1.3.0', ['status' => 403, 'body' => '', 'etag' => null, 'retryAfter' => null])->run($now);
        self::assertSame($now + UpdateCheck::BACKOFF, $this->check()->blockedUntil());

        // Erfolgreiche Antwort hebt die Pause auf.
        $this->check()->run($now + UpdateCheck::BACKOFF);
        self::assertSame(0, $this->check()->blockedUntil());
    }

    public function testUngueltigeVersionInDenEinstellungenZaehltNicht(): void
    {
        $this->settings->set('update_latest_version', '<b>9</b>');
        self::assertFalse($this->check()->state()['available']);
    }
}
