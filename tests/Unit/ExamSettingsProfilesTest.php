<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ExamSettings;
use PHPUnit\Framework\TestCase;

/** Profile und "Erweitert" der Klausur-Einstellungen. */
final class ExamSettingsProfilesTest extends TestCase
{
    public function testStrengSperrtUndVerlangtCode(): void
    {
        $s = ExamSettings::profile('streng', 'exam');
        self::assertTrue($s['lock_on_violation']);
        self::assertSame('internal', $s['paste_policy']);
        self::assertTrue($s['require_access_code']);
        self::assertFalse($s['require_seb']);
        self::assertFalse($s['editor']['autocomplete']);
        self::assertFalse($s['aids']['java_api']);
        self::assertTrue($s['aids']['operators']);
    }

    public function testSebStattCode(): void
    {
        $s = ExamSettings::profile('seb', 'exam');
        self::assertTrue($s['require_seb']);
        self::assertFalse($s['require_access_code']);
        self::assertTrue($s['lock_on_violation']);
    }

    public function testLockerOhneSperreMitAllenHilfen(): void
    {
        $s = ExamSettings::profile('locker', 'practice');
        self::assertFalse($s['lock_on_violation']);
        self::assertSame('allow_log', $s['paste_policy']);
        self::assertTrue($s['editor']['autocomplete']);
        self::assertTrue($s['editor']['lint']);
        self::assertTrue($s['aids']['calculator']);
    }

    public function testProfilErkennen(): void
    {
        foreach (array_keys(ExamSettings::PROFILES) as $key) {
            self::assertSame($key, ExamSettings::matchingProfile(ExamSettings::profile($key, 'exam'), 'exam'), $key);
        }
        // Der Standard einer Klausur verlangt keinen Code: kein Profil passt genau.
        self::assertNull(ExamSettings::matchingProfile(ExamSettings::defaults('exam'), 'exam'));
    }

    public function testAnwendenBehaeltFeineinstellungen(): void
    {
        $current = ExamSettings::defaults('exam');
        $current['java_version'] = '25';
        $current['editor']['font_size'] = 18;
        $current['python_packages'] = ['numpy'];
        $s = ExamSettings::applyProfile($current, 'locker', 'exam');
        self::assertSame('25', $s['java_version']);
        self::assertSame(18, $s['editor']['font_size']);
        self::assertSame(['numpy'], $s['python_packages']);
        self::assertFalse($s['lock_on_violation']);
        self::assertSame('locker', ExamSettings::matchingProfile($s, 'exam'));
    }

    public function testErweitertKlapptNurBeiAbweichungAuf(): void
    {
        self::assertFalse(ExamSettings::advancedChanged(ExamSettings::defaults('exam'), 'exam'));
        self::assertFalse(ExamSettings::advancedChanged(ExamSettings::profile('streng', 'exam'), 'exam'), 'Profile ändern nichts unter "Erweitert"');
        $s = ExamSettings::defaults('exam');
        $s['java_version'] = '25';
        self::assertTrue(ExamSettings::advancedChanged($s, 'exam'));
        $s = ExamSettings::defaults('exam');
        $s['editor']['font_size'] = 20;
        self::assertTrue(ExamSettings::advancedChanged($s, 'exam'));
    }

    public function testUnbekanntesProfil(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExamSettings::profile('gibtsnicht');
    }
}
