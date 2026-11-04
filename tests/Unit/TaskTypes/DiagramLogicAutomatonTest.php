<?php

declare(strict_types=1);

namespace Tests\Unit\TaskTypes;

use App\Services\Diagram\AutomatonSimulator;
use App\Services\Diagram\LogicSimulator;
use App\Services\TaskTypes\AutomatonType;
use App\Services\TaskTypes\LogicType;
use App\Services\TaskTypes\TruthTableType;
use PHPUnit\Framework\TestCase;

/** Schaltnetze, Wahrheitstabellen und endliche Automaten. */
final class DiagramLogicAutomatonTest extends TestCase
{
    private function comp(string $id, string $type, string $label = ''): array
    {
        return ['id' => $id, 'type' => $type, 'label' => $label, 'x' => 0, 'y' => 0];
    }

    private function wire(string $id, string $from, string $to, string $pin): array
    {
        return ['id' => $id, 'from' => ['component' => $from, 'pin' => 'out'], 'to' => ['component' => $to, 'pin' => $pin]];
    }

    /** X = (A AND B) OR NOT A  */
    private function circuit(): array
    {
        return [
            'components' => [
                $this->comp('iA', 'INPUT', 'A'), $this->comp('iB', 'INPUT', 'B'),
                $this->comp('g1', 'AND'), $this->comp('g2', 'NOT'), $this->comp('g3', 'OR'),
                $this->comp('oX', 'OUTPUT', 'X'),
            ],
            'wires' => [
                $this->wire('w1', 'iA', 'g1', 'in0'), $this->wire('w2', 'iB', 'g1', 'in1'),
                $this->wire('w3', 'iA', 'g2', 'in0'),
                $this->wire('w4', 'g1', 'g3', 'in0'), $this->wire('w5', 'g2', 'g3', 'in1'),
                $this->wire('w6', 'g3', 'oX', 'in0'),
            ],
        ];
    }

    private function logicConfig(): array
    {
        return (new LogicType())->normalizeConfig(['inputs' => ['A', 'B'], 'outputs' => ['X']]);
    }

    // ---------- Logik-Simulation ----------

    public function testGatterTabellen(): void
    {
        $expect = [
            'AND' => [0, 0, 0, 1], 'OR' => [0, 1, 1, 1], 'NAND' => [1, 1, 1, 0], 'NOR' => [1, 0, 0, 0],
            'XOR' => [0, 1, 1, 0], 'XNOR' => [1, 0, 0, 1],
        ];
        foreach ($expect as $type => $rows) {
            foreach ([[0, 0], [0, 1], [1, 0], [1, 1]] as $i => $in) {
                self::assertSame($rows[$i], LogicSimulator::gate($type, $in), "{$type} {$in[0]}{$in[1]}");
            }
        }
        self::assertSame(1, LogicSimulator::gate('NOT', [0]));
        self::assertSame(0, LogicSimulator::gate('NOT', [1]));
        self::assertSame(1, LogicSimulator::gate('XOR', [1, 1, 1]), 'XOR ist Parität');
    }

    public function testSchaltungWirdAusgewertet(): void
    {
        $table = LogicSimulator::truthTable($this->circuit(), ['A', 'B'], ['X']);
        self::assertSame([], $table['errors']);
        self::assertSame(['00' => ['X' => 1], '01' => ['X' => 1], '10' => ['X' => 0], '11' => ['X' => 1]], $table['table']);
        self::assertSame('((A ∧ B) ∨ ¬A)', LogicSimulator::expression($this->circuit(), 'X'));
    }

    public function testZyklusWirdErkannt(): void
    {
        $circuit = [
            'components' => [$this->comp('iA', 'INPUT', 'A'), $this->comp('g1', 'OR'), $this->comp('g2', 'NOT'), $this->comp('oX', 'OUTPUT', 'X')],
            'wires' => [
                $this->wire('w1', 'iA', 'g1', 'in0'), $this->wire('w2', 'g2', 'g1', 'in1'),
                $this->wire('w3', 'g1', 'g2', 'in0'), $this->wire('w4', 'g1', 'oX', 'in0'),
            ],
        ];
        $result = LogicSimulator::evaluate($circuit, ['A' => 1]);
        self::assertSame([], $result['outputs']);
        self::assertStringContainsString('Rückkopplung', $result['errors'][0]);
        // Auch die Textdarstellung darf bei Zyklen nicht hängen.
        self::assertIsString(LogicSimulator::expression($circuit, 'X'));
    }

    public function testOffeneEingaengeSindNullMitWarnung(): void
    {
        $circuit = ['components' => [$this->comp('iA', 'INPUT', 'A'), $this->comp('g1', 'AND'), $this->comp('oX', 'OUTPUT', 'X')],
            'wires' => [$this->wire('w1', 'iA', 'g1', 'in0'), $this->wire('w2', 'g1', 'oX', 'in0')]];
        $result = LogicSimulator::evaluate($circuit, ['A' => 1]);
        self::assertSame(['X' => 0], $result['outputs']);
        self::assertNotEmpty($result['warnings']);
    }

    public function testDreiEingangsGatterMitN(): void
    {
        $circuit = ['components' => [
            $this->comp('a', 'INPUT', 'A'), $this->comp('b', 'INPUT', 'B'), $this->comp('c', 'INPUT', 'C'),
            ['id' => 'g', 'type' => 'AND', 'label' => '', 'x' => 0, 'y' => 0, 'n' => 3], $this->comp('o', 'OUTPUT', 'X'),
        ], 'wires' => [
            $this->wire('w1', 'a', 'g', 'in0'), $this->wire('w2', 'b', 'g', 'in1'), $this->wire('w3', 'c', 'g', 'in2'), $this->wire('w4', 'g', 'o', 'in0'),
        ]];
        self::assertSame(['X' => 1], LogicSimulator::evaluate($circuit, ['A' => 1, 'B' => 1, 'C' => 1])['outputs']);
        self::assertSame(['X' => 0], LogicSimulator::evaluate($circuit, ['A' => 1, 'B' => 1, 'C' => 0])['outputs']);
    }

    // ---------- LogicType ----------

    public function testLogicKonfiguration(): void
    {
        $type = new LogicType();
        $c = $type->normalizeConfig(['inputs' => ['A', 'B'], 'outputs' => ['X'], 'symbols' => 'ansi', 'show_table' => true, 'allowed_gates' => ['AND', 'XYZ', 'AND']]);
        self::assertSame(['AND'], $c['allowed_gates']);
        self::assertSame('ansi', $c['symbols']);
        self::assertTrue($c['show_table']);
        self::assertSame('din', $type->normalizeConfig(['inputs' => ['A'], 'outputs' => ['X'], 'symbols' => 'zzz'])['symbols']);

        foreach ([
            ['inputs' => [], 'outputs' => ['X']],
            ['inputs' => ['A', 'A'], 'outputs' => ['X']],
            ['inputs' => ['1A'], 'outputs' => ['X']],
            ['inputs' => ['A'], 'outputs' => ['A']],
            ['inputs' => ['A'], 'outputs' => ['X'], 'allowed_gates' => ['XYZ']],
        ] as $bad) {
            try {
                $type->normalizeConfig($bad);
                self::fail('Ausnahme erwartet für ' . json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testLogicInhaltWirdBereinigt(): void
    {
        $type = new LogicType();
        $config = $this->logicConfig();
        $circuit = $this->circuit();
        $circuit['components'][] = $this->comp('iZ', 'INPUT', 'Z');       // nicht in config
        $circuit['components'][] = $this->comp('bad', 'MAGIC');            // unbekannter Typ
        $circuit['wires'][] = $this->wire('w7', 'iB', 'g1', 'in1');        // Pin schon belegt
        $circuit['wires'][] = $this->wire('w8', 'g1', 'g1', 'in0');        // Selbstverbindung
        $circuit['wires'][] = $this->wire('w9', 'oX', 'g1', 'in0');        // OUTPUT als Quelle
        $circuit['wires'][] = $this->wire('w10', 'iA', 'g2', 'in1');       // NOT hat nur in0
        $circuit['wires'][] = $this->wire('w11', 'iA', 'nix', 'in0');
        $content = $type->normalizeContent($circuit, $config);
        self::assertCount(6, $content['components']);
        self::assertCount(6, $content['wires']);
        self::assertTrue($type->isAnswered($content));
        self::assertFalse($type->isAnswered($type->normalizeContent([], $config)));
        self::assertStringContainsString('X = ', $type->toText($content, $config));
    }

    public function testLogicAutoCheck(): void
    {
        $type = new LogicType();
        $config = $this->logicConfig();
        $solution = $type->normalizeSolution(['truth_table' => ['00' => ['X' => 1], '01' => ['X' => 1], '10' => ['X' => 0], '11' => ['X' => 1]]], $config);
        $content = $type->normalizeContent($this->circuit(), $config);
        $result = $type->autoCheck($config, $solution, $content);
        self::assertSame(1.0, $result['ratio']);
        self::assertCount(4, $result['details']['items']);

        // NOR statt OR: nur eine Zeile (10) stimmt noch
        $wrong = $this->circuit();
        $wrong['components'][4]['type'] = 'AND';
        $result = $type->autoCheck($config, $solution, $type->normalizeContent($wrong, $config));
        self::assertSame(0.25, $result['ratio']);

        // Zyklus
        $loop = ['components' => [$this->comp('iA', 'INPUT', 'A'), $this->comp('g1', 'NOT'), $this->comp('oX', 'OUTPUT', 'X')],
            'wires' => [$this->wire('w1', 'g1', 'g1', 'in0'), $this->wire('w2', 'g1', 'oX', 'in0')]];
        // Selbstverbindung wird bereinigt, daher stattdessen echter Zyklus über zwei Gatter
        $loop = ['components' => [$this->comp('g1', 'NOT'), $this->comp('g2', 'NOT'), $this->comp('oX', 'OUTPUT', 'X')],
            'wires' => [$this->wire('w1', 'g1', 'g2', 'in0'), $this->wire('w2', 'g2', 'g1', 'in0'), $this->wire('w3', 'g2', 'oX', 'in0')]];
        $result = $type->autoCheck($config, $solution, $type->normalizeContent($loop, $config));
        self::assertSame(0.0, $result['ratio']);
        self::assertStringContainsString('Rückkopplung', $result['details']['errors'][0]);
    }

    public function testLogicLoesungAusMusterschaltung(): void
    {
        $type = new LogicType();
        $config = $this->logicConfig();
        $solution = $type->normalizeSolution(['circuit' => $this->circuit()], $config);
        self::assertSame(['00' => ['X' => 1], '01' => ['X' => 1], '10' => ['X' => 0], '11' => ['X' => 1]], $solution['truth_table']);
        self::assertArrayHasKey('circuit', $solution);

        $this->expectException(\InvalidArgumentException::class);
        $type->normalizeSolution(['truth_table' => ['00' => ['X' => 1]]], $config);
    }

    // ---------- Wahrheitstabelle ----------

    private function ttConfig(array $extra = []): array
    {
        return (new TruthTableType())->normalizeConfig(['inputs' => ['A', 'B'], 'outputs' => ['X', 'Y']] + $extra);
    }

    public function testWahrheitstabelleLoesungMussVollstaendigSein(): void
    {
        $type = new TruthTableType();
        $config = $this->ttConfig();
        $this->expectException(\InvalidArgumentException::class);
        $type->normalizeSolution(['rows' => ['00' => ['X' => '0', 'Y' => '1']]], $config);
    }

    public function testWahrheitstabelleAutoCheckZellenweise(): void
    {
        $type = new TruthTableType();
        $config = $this->ttConfig(['prefilled' => ['00' => ['X' => '1']]]);
        $solution = $type->normalizeSolution(['rows' => [
            '00' => ['X' => 1, 'Y' => 0], '01' => ['X' => 1, 'Y' => 1], '10' => ['X' => 0, 'Y' => 1], '11' => ['X' => 0, 'Y' => 0],
        ]], $config);
        self::assertSame('1', $solution['rows']['00']['X']);

        $content = $type->normalizeContent(['rows' => [
            '00' => ['X' => '0', 'Y' => '0'],   // X vorgegeben, wird auf 1 gesetzt
            '01' => ['X' => '1', 'Y' => '0'],   // Y falsch
            '10' => ['X' => '0', 'Y' => '1'],
            '11' => ['X' => '1', 'Y' => '0'],   // X falsch
            '77' => ['X' => '1'],               // unbekannte Zeile
        ]], $config);
        self::assertSame('1', $content['rows']['00']['X']);
        self::assertArrayNotHasKey('77', $content['rows']);

        $result = $type->autoCheck($config, $solution, $content);
        // 8 Zellen, 1 vorgegeben => 7 gezählt, 5 richtig (00.Y, 01.X, 10.X, 10.Y, 11.Y)
        self::assertCount(7, $result['details']['items']);
        self::assertEqualsWithDelta(5 / 7, $result['ratio'], 0.001);
        self::assertTrue($type->isAnswered($content));
        self::assertStringContainsString('A | B | X | Y', $type->toText($content, $config));
    }

    public function testWahrheitstabelleEinEingangHatIntSchluessel(): void
    {
        $type = new TruthTableType();
        $config = $type->normalizeConfig(['inputs' => ['A'], 'outputs' => ['X']]);
        $solution = $type->normalizeSolution(['rows' => ['0' => ['X' => 1], '1' => ['X' => 0]]], $config);
        $content = $type->normalizeContent(['rows' => ['0' => ['X' => '1'], '1' => ['X' => '1']]], $config);
        $result = $type->autoCheck($config, $solution, $content);
        self::assertSame(0.5, $result['ratio']);
    }

    // ---------- Automaten ----------

    /** DEA: Wörter über {a,b}, die auf "ab" enden. */
    private function dfa(): array
    {
        $s = static fn (string $id, bool $init, bool $acc): array => ['id' => $id, 'name' => $id, 'x' => 0, 'y' => 0, 'initial' => $init, 'accepting' => $acc];
        $t = static fn (string $id, string $from, string $to, array $sym): array => ['id' => $id, 'from' => $from, 'to' => $to, 'symbols' => $sym];

        return [
            'states' => [$s('q0', true, false), $s('q1', false, false), $s('q2', false, true)],
            'transitions' => [
                $t('t1', 'q0', 'q1', ['a']), $t('t2', 'q0', 'q0', ['b']),
                $t('t3', 'q1', 'q1', ['a']), $t('t4', 'q1', 'q2', ['b']),
                $t('t5', 'q2', 'q1', ['a']), $t('t6', 'q2', 'q0', ['b']),
            ],
        ];
    }

    public function testDeaSimulation(): void
    {
        $dfa = $this->dfa();
        foreach (['ab', 'aab', 'bbab'] as $w) {
            self::assertTrue(AutomatonSimulator::accepts($dfa, $w), $w);
        }
        foreach (['', 'b', 'a', 'abb', 'ba'] as $w) {
            self::assertFalse(AutomatonSimulator::accepts($dfa, $w), $w);
        }
        $trace = AutomatonSimulator::trace($dfa, 'ab');
        self::assertSame([['q0'], ['q1'], ['q2']], $trace);
        self::assertSame([], AutomatonSimulator::dfaIssues($dfa, ['a', 'b']));
    }

    public function testNeaMitEpsilon(): void
    {
        // (a|b)*a mit ε-Übergang in den Endzustand
        $nfa = [
            'states' => [
                ['id' => 's0', 'name' => '', 'initial' => true, 'accepting' => false],
                ['id' => 's1', 'name' => '', 'initial' => false, 'accepting' => false],
                ['id' => 's2', 'name' => '', 'initial' => false, 'accepting' => true],
            ],
            'transitions' => [
                ['id' => 't1', 'from' => 's0', 'to' => 's0', 'symbols' => ['a', 'b']],
                ['id' => 't2', 'from' => 's0', 'to' => 's1', 'symbols' => ['a']],
                ['id' => 't3', 'from' => 's1', 'to' => 's2', 'symbols' => ['ε']],
            ],
        ];
        self::assertTrue(AutomatonSimulator::accepts($nfa, 'a'));
        self::assertTrue(AutomatonSimulator::accepts($nfa, 'bba'));
        self::assertFalse(AutomatonSimulator::accepts($nfa, 'ab'));
        self::assertFalse(AutomatonSimulator::accepts($nfa, ''));
        self::assertSame(['s0', 's1', 's2'], AutomatonSimulator::trace($nfa, 'a')[1]);
    }

    public function testDeaHinweise(): void
    {
        $nd = $this->dfa();
        $nd['transitions'][] = ['id' => 't9', 'from' => 'q0', 'to' => 'q2', 'symbols' => ['a']];
        self::assertStringContainsString('nicht deterministisch', implode(' ', AutomatonSimulator::dfaIssues($nd, ['a', 'b'])));
        $incomplete = $this->dfa();
        array_pop($incomplete['transitions']);
        self::assertStringContainsString('nicht vollständig', implode(' ', AutomatonSimulator::dfaIssues($incomplete, ['a', 'b'])));
        $noStart = $this->dfa();
        $noStart['states'][0]['initial'] = false;
        self::assertNull(AutomatonSimulator::trace($noStart, 'a'));
        self::assertFalse(AutomatonSimulator::accepts($noStart, 'ab'));
    }

    public function testAutomatKonfigurationUndLoesung(): void
    {
        $type = new AutomatonType();
        $config = $type->normalizeConfig(['kind' => 'nfa', 'alphabet' => ['a', ' b ', 'a'], 'allow_epsilon' => true]);
        self::assertSame(['a', 'b'], $config['alphabet']);
        self::assertTrue($config['allow_epsilon']);
        self::assertTrue($config['allow_simulation']);
        self::assertFalse($type->normalizeConfig(['kind' => 'dfa', 'alphabet' => ['a'], 'allow_epsilon' => true])['allow_epsilon']);

        foreach ([['alphabet' => []], ['alphabet' => ['ab']], ['alphabet' => ['ε']], ['alphabet' => range('a', 'l')]] as $bad) {
            try {
                $type->normalizeConfig($bad);
                self::fail('Ausnahme erwartet');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $solution = $type->normalizeSolution(['accept' => ['ab', 'aab'], 'reject' => ['', 'b']], $config);
        self::assertSame(['', 'b'], $solution['reject']);
        foreach ([['accept' => ['ac'], 'reject' => []], ['accept' => ['a'], 'reject' => ['a']], ['accept' => [], 'reject' => []]] as $bad) {
            try {
                $type->normalizeSolution($bad, $config);
                self::fail('Ausnahme erwartet');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAutomatAutoCheckUndInhalt(): void
    {
        $type = new AutomatonType();
        $config = $type->normalizeConfig(['kind' => 'dfa', 'alphabet' => ['a', 'b']]);
        $solution = $type->normalizeSolution(['accept' => ['ab', 'aab'], 'reject' => ['', 'b', 'abb']], $config);

        $content = $type->normalizeContent($this->dfa() + ['junk' => 1], $config);
        $result = $type->autoCheck($config, $solution, $content);
        self::assertSame(1.0, $result['ratio']);
        self::assertSame([], $result['details']['hints']);

        // Endzustand fehlt: alle akzeptierten Wörter falsch, verworfene richtig
        $broken = $this->dfa();
        $broken['states'][2]['accepting'] = false;
        $result = $type->autoCheck($config, $solution, $type->normalizeContent($broken, $config));
        self::assertSame(0.6, $result['ratio']);

        // Ungültige Symbole und doppelte Startzustände
        $messy = $this->dfa();
        $messy['states'][1]['initial'] = true;
        $messy['transitions'][] = ['id' => 'tx', 'from' => 'q0', 'to' => 'q1', 'symbols' => ['z']];
        $messy['transitions'][] = ['id' => 'ty', 'from' => 'q0', 'to' => 'nix', 'symbols' => ['a']];
        $normalized = $type->normalizeContent($messy, $config);
        self::assertCount(6, $normalized['transitions']);
        self::assertFalse($normalized['states'][1]['initial']);

        self::assertTrue($type->isAnswered($content));
        self::assertFalse($type->isAnswered($type->normalizeContent([], $config)));
        $text = $type->toText($content, $config);
        self::assertStringContainsString('→q0 | q1 | q0', $text);
        self::assertStringContainsString('*q2', $text);
    }

    public function testNichtDeterministischerDeaGibtHinweisAberWirdBewertet(): void
    {
        $type = new AutomatonType();
        $config = $type->normalizeConfig(['kind' => 'dfa', 'alphabet' => ['a', 'b']]);
        $solution = $type->normalizeSolution(['accept' => ['ab'], 'reject' => ['b']], $config);
        $nd = $this->dfa();
        $nd['transitions'][] = ['id' => 't9', 'from' => 'q0', 'to' => 'q2', 'symbols' => ['a']];
        $result = $type->autoCheck($config, $solution, $type->normalizeContent($nd, $config));
        self::assertSame(1.0, $result['ratio']);
        self::assertNotEmpty($result['details']['hints']);
    }
}
