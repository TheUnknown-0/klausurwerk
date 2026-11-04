<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Services\DeviceBinding;
use App\Services\EventLog;
use App\Services\SebGuard;
use PHPUnit\Framework\TestCase;

/** Kleine reine Helfer des Live-Moduls: Ereignis-Schweregrade, Gerätebindung, SEB-URL. */
final class LiveHelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER[SebGuard::HEADER]);
    }

    public function testWindowBlurBelowGraceIsInfoAndDoesNotLock(): void
    {
        $settings = ['violation_grace_ms' => 300];
        self::assertSame('info', EventLog::severityFor('window_blur', ['duration_ms' => 120], $settings, 'exam'));
        self::assertFalse(EventLog::isLockingViolation('window_blur', ['duration_ms' => 120], $settings));
    }

    public function testWindowBlurAtOrAboveGraceIsAlarm(): void
    {
        $settings = ['violation_grace_ms' => 300];
        self::assertSame('alarm', EventLog::severityFor('window_blur', ['duration_ms' => 300], $settings, 'exam'));
        self::assertTrue(EventLog::isLockingViolation('window_blur', ['duration_ms' => 5000], $settings));
        // Ohne Dauerangabe zählt der Fokusverlust als Verstoß.
        self::assertTrue(EventLog::isLockingViolation('window_blur', [], $settings));
    }

    public function testPracticeNeverRaisesAlarm(): void
    {
        self::assertSame('info', EventLog::severityFor('fullscreen_exit', [], [], 'practice'));
        self::assertSame('alarm', EventLog::severityFor('fullscreen_exit', [], [], 'exam'));
        self::assertSame('warning', EventLog::severityFor('paste_blocked', [], [], 'practice'));
    }

    public function testViolationsAndClientTypes(): void
    {
        foreach (['fullscreen_exit', 'visibility_hidden', 'second_tab'] as $type) {
            self::assertTrue(EventLog::isLockingViolation($type, [], []));
        }
        foreach (['paste_blocked', 'resize', 'reload', 'devtools_suspected'] as $type) {
            self::assertFalse(EventLog::isLockingViolation($type, [], []));
        }
        self::assertArrayNotHasKey('state_changed', EventLog::CLIENT_TYPES);
        self::assertArrayNotHasKey('connection_lost', EventLog::CLIENT_TYPES);
        self::assertArrayNotHasKey('seb_missing', EventLog::CLIENT_TYPES);
        self::assertArrayNotHasKey('time_up', EventLog::CLIENT_TYPES);
    }

    public function testDeviceCheck(): void
    {
        $token = DeviceBinding::newToken();
        $other = DeviceBinding::newToken();
        self::assertSame(48, strlen($token));

        self::assertSame(DeviceBinding::OK, DeviceBinding::check(['device_hash' => null], null));
        self::assertSame(DeviceBinding::OK, DeviceBinding::check(['device_hash' => DeviceBinding::hash($token)], $token));
        self::assertSame(DeviceBinding::FOREIGN, DeviceBinding::check(['device_hash' => DeviceBinding::hash($token)], $other));
        self::assertSame(DeviceBinding::FOREIGN, DeviceBinding::check(['device_hash' => DeviceBinding::hash($token)], null));
        self::assertSame(DeviceBinding::PENDING_SELF, DeviceBinding::check([
            'device_hash' => DeviceBinding::hash($token),
            'pending_device_hash' => DeviceBinding::hash($other),
        ], $other));
    }

    public function testCookieNameAndTokenValidation(): void
    {
        self::assertSame('kw_device_12', DeviceBinding::cookieName(12));
        $_COOKIE['kw_device_9'] = 'kein-token';
        self::assertNull(DeviceBinding::tokenFromRequest(9));
        $_COOKIE['kw_device_9'] = str_repeat('a', 48);
        self::assertSame(str_repeat('a', 48), DeviceBinding::tokenFromRequest(9));
        unset($_COOKIE['kw_device_9']);
    }

    public function testSebGuardUsesAbsoluteUrlFromRequestHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'klausur.schule.example';
        $_SERVER['REQUEST_URI'] = '/api/klausur/3/puls?x=1';
        $seen = [];
        $guard = new SebGuard(null, $this->eventsStub(), static function (string $key, string $url, ?string $header) use (&$seen): bool {
            $seen = [$key, $url, $header];

            return true;
        });
        $_SERVER[SebGuard::HEADER] = 'abc';
        $exam = ['id' => 3, 'settings' => json_encode(['require_seb' => true]), 'seb_config_key' => str_repeat('a', 64)];

        self::assertTrue($guard->passes($exam));
        self::assertSame([str_repeat('a', 64), 'http://klausur.schule.example/api/klausur/3/puls?x=1', 'abc'], $seen);
    }

    public function testSebGuardPrefersConfiguredPublicBase(): void
    {
        $_SERVER['HTTP_HOST'] = 'intern:8080';
        $_SERVER['REQUEST_URI'] = '/klausur/3';
        $guard = new SebGuard('https://klausur.schule.example/app', $this->eventsStub(), static fn (): bool => true);
        self::assertSame('https://klausur.schule.example/klausur/3', $guard->absoluteUrl());
    }

    public function testSebGuardHonoursForwardedProto(): void
    {
        $_SERVER['HTTP_HOST'] = 'k.example';
        $_SERVER['REQUEST_URI'] = '/x';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $guard = new SebGuard(null, $this->eventsStub());
        self::assertSame('https://k.example/x', $guard->absoluteUrl());
    }

    public function testSebGuardPassesWithoutRequirementAndFailsWithoutKeyOrHeader(): void
    {
        $guard = new SebGuard(null, $this->eventsStub());
        self::assertTrue($guard->passes(['id' => 1, 'settings' => '{}']));

        $exam = ['id' => 1, 'settings' => json_encode(['require_seb' => true]), 'seb_config_key' => null];
        self::assertFalse($guard->passes($exam));

        $exam['seb_config_key'] = str_repeat('b', 64);
        $_SERVER['HTTP_HOST'] = 'k.example';
        $_SERVER['REQUEST_URI'] = '/x';
        self::assertFalse($guard->passes($exam), 'ohne Header');
    }

    public function testSebGuardEnforceThrows403(): void
    {
        $guard = new SebGuard(null, $this->eventsStub());
        $exam = ['id' => 1, 'settings' => json_encode(['require_seb' => true]), 'seb_config_key' => str_repeat('b', 64)];
        $_SERVER['HTTP_HOST'] = 'k.example';
        $_SERVER['REQUEST_URI'] = '/x';
        try {
            $guard->enforce($exam);
            self::fail('403 erwartet');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
    }

    private function eventsStub(): EventLog
    {
        return (new \ReflectionClass(EventLog::class))->newInstanceWithoutConstructor();
    }
}
