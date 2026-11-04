<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ParticipationState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Übergangstabelle der Teilnahme (ARCHITECTURE.md, "Zustände einer Teilnahme"). */
final class ParticipationStateTableTest extends TestCase
{
    public function testAllStatesHaveRows(): void
    {
        foreach (ParticipationState::STATES as $state) {
            self::assertArrayHasKey($state, ParticipationState::TRANSITIONS, $state);
        }
        self::assertCount(count(ParticipationState::STATES), ParticipationState::TRANSITIONS);
    }

    public function testTargetsAreKnownStatesAndNotSelf(): void
    {
        foreach (ParticipationState::TRANSITIONS as $from => $targets) {
            foreach ($targets as $to) {
                self::assertContains($to, ParticipationState::STATES, "{$from} -> {$to}");
                self::assertNotSame($from, $to, "{$from} -> {$to}");
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function allowed(): iterable
    {
        $pairs = [
            ['waiting', 'active'], ['active', 'locked'], ['locked', 'active'], ['active', 'absent'], ['absent', 'active'],
            ['active', 'paused'], ['paused', 'active'], ['active', 'submit_requested'], ['submit_requested', 'active'],
            ['submit_requested', 'submitted'], ['active', 'submitted'], ['submitted', 'active'],
            ['active', 'device_pending'], ['locked', 'device_pending'], ['absent', 'device_pending'], ['paused', 'device_pending'],
            ['submit_requested', 'device_pending'], ['waiting', 'device_pending'],
            ['device_pending', 'active'], ['device_pending', 'locked'], ['device_pending', 'absent'], ['device_pending', 'paused'],
            ['device_pending', 'submit_requested'], ['device_pending', 'waiting'],
            ['waiting', 'submitted'], ['locked', 'submitted'], ['absent', 'submitted'], ['paused', 'submitted'], ['device_pending', 'submitted'],
        ];
        foreach ($pairs as [$from, $to]) {
            yield "{$from} -> {$to}" => [$from, $to];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function forbidden(): iterable
    {
        $pairs = [
            ['waiting', 'locked'], ['waiting', 'paused'], ['waiting', 'absent'], ['waiting', 'submit_requested'],
            ['locked', 'paused'], ['locked', 'absent'], ['locked', 'submit_requested'],
            ['absent', 'locked'], ['absent', 'paused'], ['paused', 'locked'], ['paused', 'absent'],
            ['submitted', 'locked'], ['submitted', 'absent'], ['submitted', 'paused'], ['submitted', 'waiting'],
            ['submitted', 'submit_requested'], ['submitted', 'device_pending'],
            ['submit_requested', 'locked'], ['submit_requested', 'absent'], ['submit_requested', 'paused'],
            ['active', 'waiting'], ['active', 'active'], ['submitted', 'submitted'],
        ];
        foreach ($pairs as [$from, $to]) {
            yield "{$from} -> {$to}" => [$from, $to];
        }
    }

    #[DataProvider('allowed')]
    public function testAllowedTransition(string $from, string $to): void
    {
        self::assertTrue(ParticipationState::canTransition($from, $to));
    }

    #[DataProvider('forbidden')]
    public function testForbiddenTransition(string $from, string $to): void
    {
        self::assertFalse(ParticipationState::canTransition($from, $to));
    }

    public function testUnknownStatesAreForbidden(): void
    {
        self::assertFalse(ParticipationState::canTransition('foo', 'active'));
        self::assertFalse(ParticipationState::canTransition('active', 'foo'));
        self::assertSame([], ParticipationState::allowedFrom('foo'));
    }

    public function testSubmittedCanOnlyBeReopened(): void
    {
        self::assertSame(['active'], ParticipationState::allowedFrom('submitted'));
    }

    public function testClockStoppingStates(): void
    {
        self::assertTrue(ParticipationState::stopsClock('paused', false));
        self::assertTrue(ParticipationState::stopsClock('paused', true));
        self::assertFalse(ParticipationState::stopsClock('absent', false));
        self::assertTrue(ParticipationState::stopsClock('absent', true));
        self::assertFalse(ParticipationState::stopsClock('locked', true));
        self::assertFalse(ParticipationState::stopsClock('active', true));
    }
}
