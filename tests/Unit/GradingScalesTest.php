<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GradingScales;
use PHPUnit\Framework\TestCase;

/** Umrechnung Prozent zu Note/Punkte und Prüfung der Schlüssel, ohne Datenbank. */
final class GradingScalesTest extends TestCase
{
    /** @return list<array{label: string, value: float, min_percent: int}> */
    private function grades(): array
    {
        return [
            ['label' => '1', 'value' => 1.0, 'min_percent' => 87],
            ['label' => '2', 'value' => 2.0, 'min_percent' => 73],
            ['label' => '3', 'value' => 3.0, 'min_percent' => 59],
            ['label' => '4', 'value' => 4.0, 'min_percent' => 45],
            ['label' => '5', 'value' => 5.0, 'min_percent' => 18],
            ['label' => '6', 'value' => 6.0, 'min_percent' => 0],
        ];
    }

    public function testGrenzenGeltenAbPunkt(): void
    {
        $t = $this->grades();
        self::assertSame('1', GradingScales::gradeFor($t, 87.0)['label']);
        self::assertSame('2', GradingScales::gradeFor($t, 86.99)['label']);
        self::assertSame('2', GradingScales::gradeFor($t, 73.0)['label']);
        self::assertSame('3', GradingScales::gradeFor($t, 72.9)['label']);
        self::assertSame('4', GradingScales::gradeFor($t, 45.0)['label']);
        self::assertSame('5', GradingScales::gradeFor($t, 44.999)['label']);
        self::assertSame('5', GradingScales::gradeFor($t, 18.0)['label']);
        self::assertSame('6', GradingScales::gradeFor($t, 17.99)['label']);
    }

    public function testRandwerteNullHundertUndDarueber(): void
    {
        $t = $this->grades();
        self::assertSame('6', GradingScales::gradeFor($t, 0.0)['label']);
        self::assertSame('1', GradingScales::gradeFor($t, 100.0)['label']);
        self::assertSame('1', GradingScales::gradeFor($t, 112.5)['label']); // Bonuspunkte
        self::assertSame('6', GradingScales::gradeFor($t, -3.0)['label']); // unter allen Grenzen: niedrigste Stufe
    }

    public function testWertWirdAlsFloatGeliefert(): void
    {
        $result = GradingScales::gradeFor([['label' => '1-', 'value' => '1.3', 'min_percent' => '85'], ['label' => '6', 'value' => 6, 'min_percent' => 0]], 90.0);
        self::assertSame('1-', $result['label']);
        self::assertSame(1.3, $result['value']);
    }

    public function testUnsortierteStufenWerdenAbsteigendBehandelt(): void
    {
        $t = array_reverse($this->grades());
        self::assertSame('2', GradingScales::gradeFor($t, 80.0)['label']);
        self::assertSame('6', GradingScales::gradeFor($t, 5.0)['label']);
    }

    public function testGleitkommaRauschenAnDerGrenze(): void
    {
        // 29,0 von 50 Punkten gerechnet als 0.58 * 100 ergibt 57.99999999999999: gehört zu 58 %, nicht darunter.
        $t = [['label' => 'a', 'value' => 1, 'min_percent' => 58], ['label' => 'b', 'value' => 2, 'min_percent' => 0]];
        self::assertSame('a', GradingScales::gradeFor($t, 0.58 * 100 - 1e-12)['label']);
    }

    public function testPunkteSchluessel(): void
    {
        $t = [
            ['label' => '15', 'value' => 15, 'min_percent' => 95],
            ['label' => '14', 'value' => 14, 'min_percent' => 90],
            ['label' => '00', 'value' => 0, 'min_percent' => 0],
        ];
        $r = GradingScales::gradeFor($t, 92.0);
        self::assertSame('14', $r['label']);
        self::assertSame(14.0, $r['value']);
        self::assertSame('00', GradingScales::gradeFor($t, 3.0)['label']);
    }

    public function testLeereStufenLiefernLeereNote(): void
    {
        self::assertSame(['label' => '', 'value' => 0.0], GradingScales::gradeFor([], 50.0));
    }

    public function testGueltigerSchluesselHatKeineFehler(): void
    {
        self::assertSame([], GradingScales::validate($this->grades(), 'grades'));
    }

    public function testPruefungVerlangtAbsteigendUndEndeBeiNull(): void
    {
        $bad = $this->grades();
        $bad[2]['min_percent'] = 80; // nicht absteigend
        self::assertNotSame([], GradingScales::validate($bad, 'grades'));

        $noZero = $this->grades();
        $noZero[5]['min_percent'] = 5;
        $errors = GradingScales::validate($noZero, 'grades');
        self::assertStringContainsString('0 Prozent', implode(' ', $errors));

        $equal = $this->grades();
        $equal[1]['min_percent'] = 87; // gleiche Grenze wie die Stufe davor
        self::assertNotSame([], GradingScales::validate($equal, 'grades'));
    }

    public function testPruefungMeldetLeereUndFalscheEingaben(): void
    {
        self::assertNotSame([], GradingScales::validate([], 'grades'));
        self::assertNotSame([], GradingScales::validate($this->grades(), 'noten'));
        self::assertNotSame([], GradingScales::validate([['label' => '', 'value' => 1, 'min_percent' => 0]], 'grades'));
        self::assertNotSame([], GradingScales::validate([['label' => '1', 'value' => 'x', 'min_percent' => 0]], 'grades'));
        self::assertNotSame([], GradingScales::validate([['label' => '1', 'value' => 1, 'min_percent' => 101], ['label' => '2', 'value' => 2, 'min_percent' => 0]], 'grades'));
    }

    public function testNormalisierungAkzeptiertKommaUndLeereZeilen(): void
    {
        $rows = GradingScales::normalize([
            ['label' => ' 1- ', 'value' => '1,3', 'min_percent' => '85,5'],
            ['label' => '', 'value' => '', 'min_percent' => ''],
            ['label' => '6', 'value' => '6', 'min_percent' => '0'],
        ]);
        self::assertCount(2, $rows);
        self::assertSame('1-', $rows[0]['label']);
        self::assertSame(1.3, $rows[0]['value']);
        self::assertSame(85.5, $rows[0]['min_percent']);
        self::assertSame(6, $rows[1]['value']);
        self::assertSame([], GradingScales::validate($rows, 'grades'));
    }
}
