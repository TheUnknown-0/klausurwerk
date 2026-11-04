<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ExamClock;
use PHPUnit\Framework\TestCase;

/** Uhr: reine Berechnung (Nachteilsausgleich, Zusatzzeit, Pausen, unbegrenzt, time_up). */
final class ExamClockTest extends TestCase
{
    private const START = 1_767_000_000_000;

    /** @return array<string, mixed> */
    private function exam(array $override = []): array
    {
        return array_merge([
            'started_at' => ExamClock::toDb(self::START),
            'duration_minutes' => 90,
            'paused_at' => null,
            'paused_seconds' => 0,
        ], $override);
    }

    /** @return array<string, mixed> */
    private function person(array $override = []): array
    {
        return array_merge([
            'extra_minutes' => 0,
            'accommodation_percent' => 0,
            'paused_at' => null,
            'paused_seconds' => 0,
        ], $override);
    }

    public function testNotStarted(): void
    {
        $clock = ExamClock::forParticipation($this->exam(['started_at' => null]), $this->person(), self::START);
        self::assertFalse($clock['started']);
        self::assertNull($clock['ends_at_ms']);
        self::assertNull($clock['remaining_ms']);
        self::assertFalse($clock['time_up']);
    }

    public function testPlainDuration(): void
    {
        $clock = ExamClock::forParticipation($this->exam(), $this->person(), self::START + 60_000);
        self::assertTrue($clock['started']);
        self::assertSame(self::START + 90 * 60_000, $clock['ends_at_ms']);
        self::assertSame(89 * 60_000, $clock['remaining_ms']);
        self::assertFalse($clock['paused']);
        self::assertFalse($clock['time_up']);
        self::assertFalse($clock['unlimited']);
    }

    public function testAccommodationPercent(): void
    {
        $clock = ExamClock::forParticipation($this->exam(), $this->person(['accommodation_percent' => 25]), self::START);
        // 90 min x 1,25 = 112,5 min
        self::assertSame(self::START + 6_750_000, $clock['ends_at_ms']);
        self::assertSame(25, $clock['accommodation_percent']);
    }

    public function testAccommodationRoundsToMilliseconds(): void
    {
        $clock = ExamClock::forParticipation($this->exam(['duration_minutes' => 45]), $this->person(['accommodation_percent' => 33]), self::START);
        self::assertSame((int) round(45 * 60000 * 1.33), $clock['total_ms']);
    }

    public function testNegativeAccommodationIsIgnored(): void
    {
        self::assertSame(90 * 60000, ExamClock::totalMs(90, -50, 0));
    }

    public function testExtraMinutesPositive(): void
    {
        $clock = ExamClock::forParticipation($this->exam(), $this->person(['extra_minutes' => 10]), self::START);
        self::assertSame(self::START + 100 * 60_000, $clock['ends_at_ms']);
        self::assertSame(10, $clock['extra_minutes']);
    }

    public function testExtraMinutesNegative(): void
    {
        $clock = ExamClock::forParticipation($this->exam(), $this->person(['extra_minutes' => -15]), self::START);
        self::assertSame(self::START + 75 * 60_000, $clock['ends_at_ms']);
    }

    public function testTotalNeverBelowZero(): void
    {
        $clock = ExamClock::forParticipation($this->exam(['duration_minutes' => 10]), $this->person(['extra_minutes' => -500]), self::START + 1000);
        self::assertSame(0, $clock['total_ms']);
        self::assertSame(self::START, $clock['ends_at_ms']);
        self::assertSame(0, $clock['remaining_ms']);
        self::assertTrue($clock['time_up']);
    }

    public function testAccommodationAndExtraCombine(): void
    {
        $clock = ExamClock::forParticipation($this->exam(['duration_minutes' => 60]), $this->person(['accommodation_percent' => 50, 'extra_minutes' => 5]), self::START);
        self::assertSame(self::START + (90 + 5) * 60_000, $clock['ends_at_ms']);
    }

    public function testClosedExamPauseExtendsEnd(): void
    {
        $clock = ExamClock::forParticipation($this->exam(['paused_seconds' => 300]), $this->person(), self::START);
        self::assertSame(self::START + 95 * 60_000, $clock['ends_at_ms']);
    }

    public function testClosedPersonPauseExtendsEnd(): void
    {
        $clock = ExamClock::forParticipation($this->exam(), $this->person(['paused_seconds' => 120]), self::START);
        self::assertSame(self::START + 92 * 60_000, $clock['ends_at_ms']);
    }

    public function testExamAndPersonPausesAdd(): void
    {
        $clock = ExamClock::forParticipation($this->exam(['paused_seconds' => 60]), $this->person(['paused_seconds' => 60]), self::START);
        self::assertSame(self::START + 92 * 60_000, $clock['ends_at_ms']);
    }

    public function testRunningExamPauseKeepsRemainingConstant(): void
    {
        $pausedAt = self::START + 10 * 60_000;
        $exam = $this->exam(['paused_at' => ExamClock::toDb($pausedAt)]);

        $atPause = ExamClock::forParticipation($exam, $this->person(), $pausedAt);
        $later = ExamClock::forParticipation($exam, $this->person(), $pausedAt + 5 * 60_000);

        self::assertTrue($later['paused']);
        self::assertSame($atPause['remaining_ms'], $later['remaining_ms']);
        self::assertSame(80 * 60_000, $later['remaining_ms']);
        self::assertSame($atPause['ends_at_ms'] + 5 * 60_000, $later['ends_at_ms']);
        self::assertFalse($later['time_up']);
    }

    public function testRunningPersonPauseKeepsRemainingConstant(): void
    {
        $pausedAt = self::START + 20 * 60_000;
        $person = $this->person(['paused_at' => ExamClock::toDb($pausedAt)]);

        $later = ExamClock::forParticipation($this->exam(), $person, $pausedAt + 3 * 60_000);

        self::assertTrue($later['paused']);
        self::assertSame(70 * 60_000, $later['remaining_ms']);
    }

    public function testClosedPlusRunningPauseAdd(): void
    {
        $pausedAt = self::START + 30 * 60_000;
        $exam = $this->exam(['paused_seconds' => 120, 'paused_at' => ExamClock::toDb($pausedAt)]);
        $clock = ExamClock::forParticipation($exam, $this->person(), $pausedAt + 60_000);

        self::assertSame(self::START + (90 + 2 + 1) * 60_000, $clock['ends_at_ms']);
    }

    public function testUnlimitedWhenDurationNull(): void
    {
        $clock = ExamClock::forParticipation($this->exam(['duration_minutes' => null]), $this->person(), self::START + 99_999_999);
        self::assertTrue($clock['started']);
        self::assertTrue($clock['unlimited']);
        self::assertNull($clock['ends_at_ms']);
        self::assertNull($clock['remaining_ms']);
        self::assertFalse($clock['time_up']);
    }

    public function testTimeUpAtExactEnd(): void
    {
        $end = self::START + 90 * 60_000;
        self::assertFalse(ExamClock::forParticipation($this->exam(), $this->person(), $end - 1)['time_up']);
        self::assertTrue(ExamClock::forParticipation($this->exam(), $this->person(), $end)['time_up']);
    }

    public function testRemainingNeverNegative(): void
    {
        $clock = ExamClock::forParticipation($this->exam(), $this->person(), self::START + 200 * 60_000);
        self::assertSame(0, $clock['remaining_ms']);
        self::assertTrue($clock['time_up']);
    }

    public function testExtraTimeRevivesClock(): void
    {
        $now = self::START + 95 * 60_000;
        self::assertTrue(ExamClock::forParticipation($this->exam(), $this->person(), $now)['time_up']);
        self::assertFalse(ExamClock::forParticipation($this->exam(), $this->person(['extra_minutes' => 10]), $now)['time_up']);
    }

    public function testBaseEndsAtIgnoresAccommodation(): void
    {
        $exam = $this->exam(['paused_seconds' => 60]);
        self::assertSame(self::START + 91 * 60_000, ExamClock::baseEndsAtMs($exam, self::START));
        self::assertNull(ExamClock::baseEndsAtMs($this->exam(['duration_minutes' => null]), self::START));
        self::assertNull(ExamClock::baseEndsAtMs($this->exam(['started_at' => null]), self::START));
    }

    public function testDbTimeRoundTrip(): void
    {
        $ms = 1_767_000_123_456;
        self::assertSame($ms, ExamClock::toMs(ExamClock::toDb($ms)));
        self::assertNull(ExamClock::toMs(null));
        self::assertNull(ExamClock::toMs(''));
        self::assertNull(ExamClock::toMs('kein Datum'));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', ExamClock::toDb($ms));
    }
}
