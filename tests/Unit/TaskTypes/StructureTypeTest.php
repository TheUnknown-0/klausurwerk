<?php

declare(strict_types=1);

namespace Tests\Unit\TaskTypes;

use App\Services\Diagram\StructureComparer;
use App\Services\TaskTypes\StructureType;
use App\Services\TaskTypes\TaskTypeRegistry;
use PHPUnit\Framework\TestCase;

/** Antworttyp "structure": Normalisierung, Summenformel, Graph-Isomorphie und Vorbewertung. */
final class StructureTypeTest extends TestCase
{
    /**
     * Baut ein Diagramm aus Atomen ("id:El") und Bindungen ("a-b", "a=b", "a#b").
     *
     * @param list<string> $atoms
     * @param list<string> $bonds
     * @return array<string, mixed>
     */
    private function molecule(array $atoms, array $bonds, int $dx = 0): array
    {
        $out = ['atoms' => [], 'bonds' => [], 'texts' => [], 'arrows' => []];
        foreach ($atoms as $i => $spec) {
            [$id, $el] = explode(':', $spec);
            $out['atoms'][] = ['id' => $id, 'el' => $el, 'x' => $dx + $i * 50, 'y' => 0, 'charge' => 0, 'pairs' => [], 'radicals' => 0];
        }
        foreach ($bonds as $i => $spec) {
            preg_match('/^(\w+)([-=#])(\w+)$/', $spec, $m);
            $out['bonds'][] = ['id' => 'b' . ($i + 1), 'a' => $m[1], 'b' => $m[3], 'order' => ['-' => 1, '=' => 2, '#' => 3][$m[2]], 'stereo' => 'none'];
        }

        return $out;
    }

    private function ethanol(int $dx = 0): array
    {
        return $this->molecule(
            ['c1:C', 'c2:C', 'o:O', 'h1:H', 'h2:H', 'h3:H', 'h4:H', 'h5:H', 'h6:H'],
            ['c1-c2', 'c2-o', 'c1-h1', 'c1-h2', 'c1-h3', 'c2-h4', 'c2-h5', 'o-h6'],
            $dx
        );
    }

    private function ether(int $dx = 0): array
    {
        return $this->molecule(
            ['c1:C', 'o:O', 'c2:C', 'h1:H', 'h2:H', 'h3:H', 'h4:H', 'h5:H', 'h6:H'],
            ['c1-o', 'o-c2', 'c1-h1', 'c1-h2', 'c1-h3', 'c2-h4', 'c2-h5', 'c2-h6'],
            $dx
        );
    }

    public function testTypIstRegistriert(): void
    {
        $type = TaskTypeRegistry::get('structure');
        self::assertNotNull($type);
        self::assertSame('Diagramme', $type->group());
        self::assertSame('Strukturformel (Chemie)', $type->label());
        $config = $type->normalizeConfig($type->defaultConfig());
        self::assertFalse($config['score_pairs']);
        self::assertNull($config['start']);
        self::assertFalse($type->isAnswered($type->normalizeContent([], $config)));
        self::assertNull($type->autoCheck($config, [], $type->normalizeContent([], $config)));
    }

    public function testNormalisierungVerwirftFremdwerteUndBegrenzt(): void
    {
        $type = new StructureType();
        $content = $type->normalizeContent([
            'atoms' => [
                ['id' => 'a1', 'el' => 'cl', 'x' => 99999, 'y' => -99999, 'charge' => 9, 'pairs' => ['top', 'top', 'unten', 'left'], 'radicals' => 7],
                ['id' => 'a2', 'el' => 'Xx', 'x' => 0, 'y' => 0],
                ['id' => 'a3', 'el' => '<script>', 'x' => 0, 'y' => 0],
                ['id' => 'a1', 'el' => 'O', 'x' => 0, 'y' => 0],
                ['id' => 'a4', 'el' => 'O', 'x' => 10, 'y' => 10, 'charge' => -5],
                ['id' => 'böse id', 'el' => 'H'],
                'kein Array',
            ],
            'bonds' => [
                ['id' => 'b1', 'a' => 'a1', 'b' => 'a4', 'order' => 5, 'stereo' => 'wedge'],
                ['id' => 'b2', 'a' => 'a4', 'b' => 'a1', 'order' => 1],
                ['id' => 'b3', 'a' => 'a1', 'b' => 'a1'],
                ['id' => 'b4', 'a' => 'a1', 'b' => 'fehlt'],
                ['id' => 'a1', 'a' => 'a1', 'b' => 'a4'],
            ],
            'texts' => [['id' => 't1', 'x' => 5, 'y' => 5, 'text' => "  Reaktion\n 1 "], ['id' => 't2', 'text' => '']],
            'arrows' => [['id' => 'r1', 'x' => 1, 'y' => 2, 'len' => 5000, 'kind' => 'doppel'], ['id' => 'r2', 'kind' => 'equilibrium']],
        ], []);
        self::assertCount(2, $content['atoms']);
        self::assertSame('Cl', $content['atoms'][0]['el']);
        self::assertSame(8000, $content['atoms'][0]['x']);
        self::assertSame(-3000, $content['atoms'][0]['y']);
        self::assertSame(3, $content['atoms'][0]['charge']);
        self::assertSame(['top', 'left'], $content['atoms'][0]['pairs']);
        self::assertSame(4, $content['atoms'][0]['radicals']);
        self::assertSame(-2, $content['atoms'][1]['charge']);
        self::assertCount(1, $content['bonds']);
        self::assertSame(3, $content['bonds'][0]['order']);
        self::assertSame('none', $content['bonds'][0]['stereo'], 'Keil nur bei Einfachbindung');
        self::assertSame('Reaktion 1', $content['texts'][0]['text']);
        self::assertCount(1, $content['texts']);
        self::assertSame(400, $content['arrows'][0]['len']);
        self::assertSame('forward', $content['arrows'][0]['kind']);
        self::assertSame('equilibrium', $content['arrows'][1]['kind']);
        self::assertTrue($type->isAnswered($content));
    }

    public function testHoechstzahlenGelten(): void
    {
        $type = new StructureType();
        $atoms = [];
        for ($i = 0; $i < 400; $i++) {
            $atoms[] = ['id' => 'a' . $i, 'el' => 'C', 'x' => $i, 'y' => 0];
        }
        $content = $type->normalizeContent(['atoms' => $atoms], []);
        self::assertCount(StructureType::MAX_ATOMS, $content['atoms']);
    }

    public function testKonfigurationMitStartzeichnung(): void
    {
        $type = new StructureType();
        $config = $type->normalizeConfig(['score_pairs' => 'true', 'start' => $this->ethanol()]);
        self::assertTrue($config['score_pairs']);
        self::assertCount(9, $config['start']['atoms']);
        self::assertNull($type->normalizeConfig(['start' => ['atoms' => []]])['start']);
        self::assertNull($type->normalizeConfig(['start' => 'quatsch'])['start']);
    }

    public function testSummenformelInHillReihenfolge(): void
    {
        $mol = StructureComparer::molecules($this->ethanol())[0];
        self::assertSame('C₂H₆O', StructureComparer::formulaText($mol['counts']));
        self::assertSame(0, $mol['charge']);
        // ohne Kohlenstoff rein alphabetisch (H nicht vorn)
        self::assertSame('ClHNa', StructureComparer::formulaText(['Na' => 1, 'Cl' => 1, 'H' => 1]));
        self::assertSame('CH₄', StructureComparer::formulaText(['H' => 4, 'C' => 1]));
        self::assertSame('2−', StructureComparer::chargeText(-2));
        self::assertSame('+', StructureComparer::chargeText(1));
    }

    public function testMehrereMoleculeUndGesamtladung(): void
    {
        $diagram = $this->molecule(['n:N', 'h1:H', 'h2:H', 'h3:H', 'h4:H', 'o:O', 'h5:H'], ['n-h1', 'n-h2', 'n-h3', 'n-h4', 'o-h5']);
        $diagram['atoms'][0]['charge'] = 1;
        $mols = StructureComparer::molecules($diagram);
        self::assertCount(2, $mols);
        self::assertSame(1, $mols[0]['charge']);
        self::assertSame('H₄N', StructureComparer::formulaText($mols[0]['counts']));
    }

    public function testEthanolGegenDimethyletherGleicheSummenformelAnderesGeruest(): void
    {
        $ethanol = StructureComparer::molecules($this->ethanol())[0];
        $ether = StructureComparer::molecules($this->ether())[0];
        self::assertSame(StructureComparer::formulaText($ethanol['counts']), StructureComparer::formulaText($ether['counts']));
        self::assertFalse(StructureComparer::isomorphic($ethanol, $ether, false));
        // Ethanol mit anderer Reihenfolge und anderen Kennungen bleibt isomorph
        $again = StructureComparer::molecules($this->molecule(
            ['x9:H', 'x8:O', 'x7:C', 'x6:H', 'x5:H', 'x4:C', 'x3:H', 'x2:H', 'x1:H'],
            ['x8-x9', 'x7-x8', 'x7-x4', 'x7-x6', 'x7-x5', 'x4-x3', 'x4-x2', 'x4-x1']
        ))[0];
        self::assertTrue(StructureComparer::isomorphic($ethanol, $again, false));
    }

    public function testBenzolIsomorphieMitSymmetrie(): void
    {
        $ring = static function (int $kekule): array {
            $atoms = [];
            $bonds = [];
            for ($i = 0; $i < 6; $i++) {
                $atoms[] = ['id' => "c$i", 'el' => 'C', 'x' => $i, 'y' => 0, 'charge' => 0, 'pairs' => [], 'radicals' => 0];
                $atoms[] = ['id' => "h$i", 'el' => 'H', 'x' => $i, 'y' => 9, 'charge' => 0, 'pairs' => [], 'radicals' => 0];
                $bonds[] = ['id' => "bh$i", 'a' => "c$i", 'b' => "h$i", 'order' => 1, 'stereo' => 'none'];
                $bonds[] = ['id' => "bc$i", 'a' => "c$i", 'b' => 'c' . (($i + 1) % 6), 'order' => ($i + $kekule) % 2 === 0 ? 2 : 1, 'stereo' => 'none'];
            }

            return ['atoms' => $atoms, 'bonds' => $bonds, 'texts' => [], 'arrows' => []];
        };
        $a = StructureComparer::molecules($ring(0))[0];
        $b = StructureComparer::molecules($ring(1))[0];
        self::assertTrue(StructureComparer::isomorphic($a, $b, false), 'beide Kekulé-Strukturen sind gleich');
        // eine Doppelbindung zu wenig
        $broken = $ring(0);
        $broken['bonds'][1]['order'] = 1;
        self::assertFalse(StructureComparer::isomorphic($a, StructureComparer::molecules($broken)[0], false));
    }

    public function testGrosseMoleculeBleibenSchnell(): void
    {
        // Alkankette mit 60 Atomen, Schülerversion mit umgekehrter Reihenfolge
        $atoms = [];
        $bonds = [];
        for ($i = 0; $i < 30; $i++) {
            $atoms[] = "c$i:C";
            $atoms[] = "h$i:H";
            $bonds[] = "c$i-h$i";
            if ($i > 0) {
                $bonds[] = 'c' . ($i - 1) . "-c$i";
            }
        }
        $a = $this->molecule($atoms, $bonds);
        $b = $this->molecule(array_reverse($atoms), array_reverse($bonds));
        $start = microtime(true);
        $result = StructureComparer::compare($a, $b);
        self::assertSame(1.0, $result['ratio']);
        self::assertLessThan(5.0, microtime(true) - $start);
    }

    public function testVorbewertungVollstaendigUndHinweise(): void
    {
        $type = new StructureType();
        $config = $type->normalizeConfig([]);
        $solution = $type->normalizeSolution($this->ethanol(), $config);
        self::assertSame(1.0, $type->autoCheck($config, $solution, $type->normalizeContent($this->ethanol(300), $config))['ratio']);

        $result = $type->autoCheck($config, $solution, $type->normalizeContent($this->ether(), $config));
        self::assertSame('structure', $result['details']['source']);
        $missing = implode(' | ', $result['details']['missing']);
        self::assertStringContainsString('Bindungsgerüst von C₂H₆O: Summenformel stimmt, aber', $missing);
        self::assertStringContainsString('Bindung C-C fehlt', $missing);
        self::assertStringNotContainsString('Summenformel C₂H₆O:', $missing);
        self::assertGreaterThan(0.0, $result['ratio']);
        self::assertLessThan(1.0, $result['ratio']);
    }

    public function testVorbewertungFehlendeDoppelbindungUndSummenformel(): void
    {
        $type = new StructureType();
        $config = $type->normalizeConfig([]);
        // Formaldehyd H2C=O
        $sol = $this->molecule(['c:C', 'o:O', 'h1:H', 'h2:H'], ['c=o', 'c-h1', 'c-h2']);
        $solution = $type->normalizeSolution($sol, $config);
        $one = $this->molecule(['c:C', 'o:O', 'h1:H', 'h2:H'], ['c-o', 'c-h1', 'c-h2']);
        $missing = implode(' | ', $type->autoCheck($config, $solution, $type->normalizeContent($one, $config))['details']['missing']);
        self::assertStringContainsString('Summenformel stimmt, aber Bindung C=O fehlt und Bindung C-O ist zu viel', $missing);

        $noH = $this->molecule(['c:C', 'o:O', 'h1:H'], ['c=o', 'c-h1']);
        $missing = implode(' | ', $type->autoCheck($config, $solution, $type->normalizeContent($noH, $config))['details']['missing']);
        self::assertStringContainsString('ein H-Atom fehlt', $missing);
    }

    public function testElektronenpaareZaehlenNurWennEingeschaltet(): void
    {
        $type = new StructureType();
        $water = $this->molecule(['o:O', 'h1:H', 'h2:H'], ['o-h1', 'o-h2']);
        $withPairs = $water;
        $withPairs['atoms'][0]['pairs'] = ['top', 'bottom'];
        $off = $type->normalizeConfig(['score_pairs' => false]);
        $on = $type->normalizeConfig(['score_pairs' => true]);

        self::assertSame(1.0, $type->autoCheck($off, $type->normalizeSolution($withPairs, $off), $type->normalizeContent($water, $off))['ratio']);

        $solution = $type->normalizeSolution($withPairs, $on);
        $result = $type->autoCheck($on, $solution, $type->normalizeContent($water, $on));
        self::assertLessThan(1.0, $result['ratio']);
        self::assertStringContainsString('Elektronenpaare', implode(' | ', $result['details']['missing']));
        // Seite des Elektronenpaars ist egal, nur die Anzahl zählt
        $other = $withPairs;
        $other['atoms'][0]['pairs'] = ['left', 'right'];
        self::assertSame(1.0, $type->autoCheck($on, $solution, $type->normalizeContent($other, $on))['ratio']);
    }

    public function testLadungMehrereMoleculeUndPfeile(): void
    {
        $type = new StructureType();
        $config = $type->normalizeConfig([]);
        $sol = [
            'atoms' => [
                ['id' => 'n', 'el' => 'Na', 'x' => 0, 'y' => 0, 'charge' => 1, 'pairs' => [], 'radicals' => 0],
                ['id' => 'c', 'el' => 'Cl', 'x' => 300, 'y' => 0, 'charge' => -1, 'pairs' => ['top', 'right', 'bottom', 'left'], 'radicals' => 0],
            ],
            'bonds' => [],
            'texts' => [],
            'arrows' => [['id' => 'r', 'x' => 150, 'y' => 0, 'len' => 100, 'kind' => 'forward']],
        ];
        $solution = $type->normalizeSolution($sol, $config);
        self::assertSame(1.0, $type->autoCheck($config, $solution, $type->normalizeContent($sol, $config))['ratio']);

        $wrong = $sol;
        $wrong['atoms'][1]['charge'] = 0;
        $wrong['arrows'][0]['kind'] = 'equilibrium';
        $result = $type->autoCheck($config, $solution, $type->normalizeContent($wrong, $config));
        $missing = implode(' | ', $result['details']['missing']);
        self::assertStringContainsString('Gesamtladung − von Cl', $missing);
        self::assertStringContainsString('Reaktionspfeil →', $missing);
        self::assertContains('Reaktionspfeil ⇌', $result['details']['extra']);
    }

    public function testLeereAntwortUndLoesung(): void
    {
        $type = new StructureType();
        $config = $type->normalizeConfig([]);
        $solution = $type->normalizeSolution($this->ethanol(), $config);
        $result = $type->autoCheck($config, $solution, $type->normalizeContent([], $config));
        self::assertSame(0.0, $result['ratio']);
        self::assertNull($type->autoCheck($config, $type->normalizeSolution([], $config), $type->normalizeContent($this->ethanol(), $config)));
    }

    public function testToText(): void
    {
        $type = new StructureType();
        $text = $type->toText($type->normalizeContent($this->ethanol(), []), []);
        self::assertStringContainsString('Molekül 1: C₂H₆O, Ladung ungeladen', $text);
        self::assertStringContainsString('C-O', $text);
    }
}
