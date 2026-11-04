<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LiveStore;
use PHPUnit\Framework\TestCase;

/** Flüchtiger Speicher der Live-Wand: Schreiben, Lesen, Veralten, Fokus, Rate, Löschen. */
final class LiveStoreTest extends TestCase
{
    private string $dir;
    private LiveStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kw-livestore-test-' . bin2hex(random_bytes(4));
        $this->store = new LiveStore($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->dir . '/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->dir);
    }

    public function testUsesGivenDirectory(): void
    {
        self::assertSame($this->dir, $this->store->dir());
        self::assertDirectoryExists($this->dir);
    }

    public function testFallsBackToTempDirWhenNotWritable(): void
    {
        $store = new LiveStore('/proc/nicht/schreibbar');
        self::assertSame(sys_get_temp_dir() . '/klausurwerk-live', $store->dir());
    }

    public function testStateRoundTripKeepsOnlyLatest(): void
    {
        $this->store->putState(7, 11, ['view' => 'task', 'code_excerpt' => 'a'], 1_000_000);
        $this->store->putState(7, 11, ['view' => 'overview', 'code_excerpt' => 'b'], 1_001_000);

        $state = $this->store->getState(7, 11, 1_002_000);
        self::assertSame('overview', $state['view']);
        self::assertSame('b', $state['code_excerpt']);
        self::assertSame(1_001_000, $state['received_ms']);
        self::assertNull($this->store->getState(7, 12, 1_002_000), 'andere Teilnahme');
        self::assertNull($this->store->getState(8, 11, 1_002_000), 'andere Klausur');
    }

    public function testStateGoesStaleAfter120Seconds(): void
    {
        $this->store->putState(7, 11, ['view' => 'task'], 1_000_000);

        self::assertNotNull($this->store->getState(7, 11, 1_000_000 + 120_000));
        self::assertNull($this->store->getState(7, 11, 1_000_000 + 120_001));
    }

    public function testScreenRoundTripAndStale(): void
    {
        $jpeg = "\xFF\xD8\xFF\xE0bytes";
        $this->store->putScreen(7, 11, $jpeg, 1280, 720, 'monitor', 2_000_000);

        self::assertSame(
            ['ts_ms' => 2_000_000, 'width' => 1280, 'height' => 720, 'surface' => 'monitor'],
            $this->store->getScreenMeta(7, 11, 2_005_000),
        );
        self::assertSame($jpeg, $this->store->getScreenImage(7, 11, 2_005_000));
        self::assertNull($this->store->getScreenImage(7, 11, 2_000_000 + 120_001));
        self::assertNull($this->store->getScreenMeta(7, 11, 2_000_000 + 120_001));
        self::assertNull($this->store->getScreenImage(7, 99, 2_005_000));
    }

    public function testWritesLeaveNoTempFiles(): void
    {
        $this->store->putState(7, 11, ['view' => 'task'], 1);
        $this->store->putScreen(7, 11, "\xFF\xD8\xFFx", 10, 10, 'window', 1);

        $names = array_map('basename', glob($this->dir . '/e7/*') ?: []);
        self::assertSame([], array_filter($names, static fn (string $n): bool => str_ends_with($n, '.tmp')));
    }

    public function testFocusExpiresAndSwitches(): void
    {
        self::assertSame(5000, $this->store->screenIntervalMs(7, 11, 1000));

        $this->store->setFocus(7, 11, 1_000_000);
        self::assertTrue($this->store->isFocused(7, 11, 1_000_000 + 29_999));
        self::assertSame(1000, $this->store->screenIntervalMs(7, 11, 1_010_000));
        self::assertFalse($this->store->isFocused(7, 12, 1_010_000));
        self::assertFalse($this->store->isFocused(7, 11, 1_000_000 + 30_000), 'nach 30 s vorbei');

        $this->store->setFocus(7, 12, 1_000_000);
        self::assertFalse($this->store->isFocused(7, 11, 1_000_001), 'ein Fokus je Klausur');
        $this->store->setFocus(7, null);
        self::assertNull($this->store->focusedParticipation(7, 1_000_001));
    }

    public function testRateLimitAllowsTwoPerSecond(): void
    {
        self::assertTrue($this->store->allow(7, 11, 'live', 2, 10_000));
        self::assertTrue($this->store->allow(7, 11, 'live', 2, 10_300));
        self::assertFalse($this->store->allow(7, 11, 'live', 2, 10_600));
        self::assertTrue($this->store->allow(7, 11, 'screen', 2, 10_600), 'eigener Eimer');
        self::assertTrue($this->store->allow(7, 12, 'live', 2, 10_600), 'eigene Teilnahme');
        self::assertTrue($this->store->allow(7, 11, 'live', 2, 11_100), 'nach einer Sekunde wieder frei');
    }

    public function testClearExamRemovesEverythingOfThatExamOnly(): void
    {
        $this->store->putState(7, 11, ['view' => 'task'], 1);
        $this->store->putScreen(7, 11, "\xFF\xD8\xFFx", 10, 10, 'monitor', 1);
        $this->store->setFocus(7, 11, 1);
        $this->store->allow(7, 11, 'live', 2, 1);
        $this->store->putState(8, 21, ['view' => 'task'], 1);

        $this->store->clearExam(7);

        self::assertDirectoryDoesNotExist($this->dir . '/e7');
        self::assertNull($this->store->getState(7, 11, 2));
        self::assertNull($this->store->getScreenImage(7, 11, 2));
        self::assertNull($this->store->focusedParticipation(7, 2));
        self::assertNotNull($this->store->getState(8, 21, 2));
        $this->store->clearExam(7);
        $this->store->clearExam(12345);
    }
}
