<?php

declare(strict_types=1);

namespace Tests\Unit\TaskTypes;

use App\Services\Robo\RoboWorld;
use App\Services\TaskTypes\RoboType;
use App\Services\TaskTypes\TaskTypeRegistry;
use PHPUnit\Framework\TestCase;

final class RoboTypeTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function world(array $extra = []): array
    {
        return array_replace([
            'cols' => 10, 'rows' => 8, 'robo' => ['x' => 0, 'y' => 0, 'dir' => 'E'],
            'walls' => [[3, 2]], 'boxes' => [[5, 5]], 'screws' => [['x' => 4, 'y' => 0, 'count' => 1]], 'targets' => [[9, 7]],
        ], $extra);
    }

    public function testTypIstRegistriert(): void
    {
        TaskTypeRegistry::reset();
        $type = TaskTypeRegistry::get('robo');
        self::assertInstanceOf(RoboType::class, $type);
        self::assertSame('Mikrowelt', $type->group());
        self::assertSame('Mikrowelt', array_key_first(array_intersect_key(TaskTypeRegistry::grouped(), ['Mikrowelt' => 1])));
    }

    public function testStandardkonfigurationIstGueltig(): void
    {
        $type = new RoboType();
        $config = $type->normalizeConfig($type->defaultConfig());
        self::assertSame('python', $config['language']);
        self::assertSame('main.py', $config['main_file']);
        self::assertSame(10, $config['world']['cols']);
        self::assertTrue($config['allow_new_files']);
    }

    public function testNormalisierungDerKonfiguration(): void
    {
        $type = new RoboType();
        $config = $type->normalizeConfig([
            'language' => 'java', 'java_version' => '25', 'main_file' => '', 'start_code' => 'x', 'goal_md' => 'Sammle alles.',
            'world' => self::world(['robo' => ['x' => 1, 'y' => 1, 'dir' => 'o'], 'extra' => 1]), 'unbekannt' => 1,
        ]);
        self::assertSame('Main.java', $config['main_file']);
        self::assertSame('25', $config['java_version']);
        self::assertSame('E', $config['world']['robo']['dir'], 'O wird zu E');
        self::assertArrayNotHasKey('unbekannt', $config);
        self::assertArrayNotHasKey('extra', $config['world']);
        self::assertSame([['x' => 4, 'y' => 0, 'count' => 1]], $config['world']['screws']);
        $py = $type->normalizeConfig(['language' => 'python', 'java_version' => '21', 'world' => self::world()]);
        self::assertNull($py['java_version'], 'Java-Version gibt es nur bei Java');
    }

    public function testStartdateiDarfBibliothekNichtVerdecken(): void
    {
        $type = new RoboType();
        self::assertSame('main.py', $type->normalizeConfig(['language' => 'python', 'main_file' => 'robo.py', 'world' => self::world()])['main_file']);
        self::assertSame('Main.java', $type->normalizeConfig(['language' => 'java', 'main_file' => 'Robo.java', 'world' => self::world()])['main_file']);
        foreach ([['python', 'main.java'], ['java', 'main.py'], ['python', '../x.py'], ['python', 'a b.py']] as [$lang, $file]) {
            try {
                $type->normalizeConfig(['language' => $lang, 'main_file' => $file, 'world' => self::world()]);
                self::fail("Erwartet InvalidArgumentException für $lang / $file");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testUngueltigeSpracheVersionModus(): void
    {
        $type = new RoboType();
        foreach ([['language' => 'sql'], ['language' => 'java', 'java_version' => '17'], ['run_mode' => 'fly']] as $bad) {
            try {
                $type->normalizeConfig($bad + ['world' => self::world()]);
                self::fail('Erwartet InvalidArgumentException für ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function ungueltigeWelten(): iterable
    {
        yield 'keine Welt' => [null];
        yield 'leer' => [[]];
        yield 'zu klein' => [self::world(['cols' => 1])];
        yield 'zu groß' => [self::world(['rows' => 31])];
        yield 'Spalten als Text' => [self::world(['cols' => '10'])];
        yield 'Robo außerhalb' => [self::world(['robo' => ['x' => 10, 'y' => 0, 'dir' => 'E']])];
        yield 'Robo negativ' => [self::world(['robo' => ['x' => -1, 'y' => 0, 'dir' => 'E']])];
        yield 'Richtung' => [self::world(['robo' => ['x' => 0, 'y' => 0, 'dir' => 'X']])];
        yield 'Robo auf Wand' => [self::world(['walls' => [[0, 0]]])];
        yield 'Robo auf Kiste' => [self::world(['boxes' => [[0, 0]]])];
        yield 'Kiste auf Wand' => [self::world(['walls' => [[2, 2]], 'boxes' => [[2, 2]]])];
        yield 'Wand außerhalb' => [self::world(['walls' => [[10, 0]]])];
        yield 'Wand kaputt' => [self::world(['walls' => [[1]]])];
        yield 'Wand Kommazahl' => [self::world(['walls' => [[1.5, 0]]])];
        yield 'Wände keine Liste' => [self::world(['walls' => 'viele'])];
        yield 'Schraube Anzahl 0' => [self::world(['screws' => [['x' => 1, 'y' => 1, 'count' => 0]]])];
        yield 'Schraube Anzahl 100' => [self::world(['screws' => [['x' => 1, 'y' => 1, 'count' => 100]]])];
        yield 'Schraube doppelt' => [self::world(['screws' => [['x' => 1, 'y' => 1, 'count' => 1], ['x' => 1, 'y' => 1, 'count' => 2]]])];
        yield 'Schraube auf Wand' => [self::world(['screws' => [['x' => 3, 'y' => 2, 'count' => 1]]])];
        yield 'Schraube unter Kiste' => [self::world(['screws' => [['x' => 5, 'y' => 5, 'count' => 1]]])];
        yield 'Schraube ohne Anzahl' => [self::world(['screws' => [['x' => 1, 'y' => 1]]])];
        yield 'Ziel auf Wand' => [self::world(['targets' => [[3, 2]]])];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ungueltigeWelten')]
    public function testUngueltigeWeltWirdAbgelehnt(mixed $world): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RoboType())->normalizeConfig(['language' => 'python', 'world' => $world]);
    }

    public function testGrenzgroessenSindErlaubt(): void
    {
        foreach ([[2, 2], [30, 30], [2, 30]] as [$c, $r]) {
            $w = RoboWorld::normalize(['cols' => $c, 'rows' => $r, 'robo' => ['x' => 0, 'y' => 0, 'dir' => 'N']]);
            self::assertSame($c, $w['cols']);
            self::assertSame([], $w['walls']);
        }
    }

    public function testDoppelteFelderWerdenZusammengefasst(): void
    {
        $w = RoboWorld::normalize(self::world(['walls' => [[1, 1], [1, 1], [2, 1]]]));
        self::assertSame([[1, 1], [2, 1]], $w['walls']);
    }

    public function testStudentConfigEnthaeltWeltAberKeineTests(): void
    {
        $type = new RoboType();
        $config = $type->normalizeConfig(['language' => 'python', 'world' => self::world()]);
        $student = $type->studentConfig($config, 42);
        self::assertSame($config['world'], $student['world']);
        self::assertStringNotContainsString('robo_world', json_encode($student));
        self::assertArrayNotHasKey('tests', $student);
    }

    public function testTestwelten(): void
    {
        $tests = RoboType::normalizeTests([
            ['name' => '', 'world' => self::world(), 'goal' => ['screws_collected' => 'all', 'robo_at' => [9, 7], 'robo_dir' => 'o', 'boxes_at' => [[6, 5]], 'x' => 1], 'points' => 2],
            ['name' => 'Zwei', 'type' => 'robo_world', 'world' => self::world(), 'goal' => [], 'points' => '1.5'],
            'kein array',
        ]);
        self::assertCount(2, $tests);
        self::assertSame('Welt 1', $tests[0]['name']);
        self::assertSame('robo_world', $tests[0]['type']);
        self::assertSame(['screws_collected' => 'all', 'robo_at' => [9, 7], 'robo_dir' => 'E', 'boxes_at' => [[6, 5]]], $tests[0]['goal']);
        self::assertSame(2.0, $tests[0]['points']);
        self::assertSame([], $tests[1]['goal']);
        self::assertSame(1.5, $tests[1]['points']);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function ungueltigeTests(): iterable
    {
        yield 'Typ' => [['type' => 'io', 'world' => null]];
        yield 'Welt fehlt' => [['name' => 'a']];
        yield 'Welt kaputt' => [['world' => ['cols' => 1]]];
        yield 'Zielfeld außerhalb' => [['world' => ['cols' => 5, 'rows' => 5, 'robo' => ['x' => 0, 'y' => 0, 'dir' => 'N']], 'goal' => ['robo_at' => [5, 5]]]];
        yield 'Schrauben Text' => [['world' => ['cols' => 5, 'rows' => 5, 'robo' => ['x' => 0, 'y' => 0, 'dir' => 'N']], 'goal' => ['screws_collected' => 'viele']]];
        yield 'Richtung' => [['world' => ['cols' => 5, 'rows' => 5, 'robo' => ['x' => 0, 'y' => 0, 'dir' => 'N']], 'goal' => ['robo_dir' => 'Q']]];
        yield 'Kistenfeld' => [['world' => ['cols' => 5, 'rows' => 5, 'robo' => ['x' => 0, 'y' => 0, 'dir' => 'N']], 'goal' => ['boxes_at' => [[9, 9]]]]];
        yield 'Punkte' => [['world' => ['cols' => 5, 'rows' => 5, 'robo' => ['x' => 0, 'y' => 0, 'dir' => 'N']], 'points' => 5000]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ungueltigeTests')]
    public function testUngueltigeTestweltWirdAbgelehnt(array $test): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RoboType::normalizeTests([$test]);
    }

    public function testZuVieleTestwelten(): void
    {
        $test = ['world' => self::world(), 'goal' => []];
        self::assertCount(RoboType::MAX_TESTS, RoboType::normalizeTests(array_fill(0, RoboType::MAX_TESTS, $test)));
        $this->expectException(\InvalidArgumentException::class);
        RoboType::normalizeTests(array_fill(0, RoboType::MAX_TESTS + 1, $test));
    }

    public function testLoesungsseiteSpeichertDieTestweltenAlsRueckfallebene(): void
    {
        $type = new RoboType();
        $config = $type->normalizeConfig(['language' => 'python', 'world' => self::world()]);
        $solution = $type->normalizeSolution(['tests' => [['name' => 'A', 'world' => self::world(), 'goal' => ['robo_on_target' => true]]]], $config);
        self::assertSame('A', $solution['tests'][0]['name']);
        self::assertTrue($solution['tests'][0]['goal']['robo_on_target']);
        self::assertSame(['tests' => []], $type->normalizeSolution([], $config));
        self::assertSame(['tests' => []], $type->defaultSolution());
    }

    public function testContentNurStartdateiUndGroessenbegrenzung(): void
    {
        $type = new RoboType();
        $config = $type->normalizeConfig(['language' => 'python', 'world' => self::world()]);
        $out = $type->normalizeContent(['files' => [
            ['name' => 'robo.py', 'content' => 'manipuliert'],
            ['name' => 'main.py', 'content' => 'from robo import *'],
            ['name' => 'main.py', 'content' => 'doppelt'],
            ['name' => 'extra.py', 'content' => 'unerlaubt'],
            'kein array',
        ]], $config);
        self::assertSame([['name' => 'main.py', 'content' => 'from robo import *']], $out['files']);
        self::assertSame(['files' => []], $type->normalizeContent([], $config));
        $big = $type->normalizeContent(['files' => [['name' => 'main.py', 'content' => str_repeat('x', 300000)]]], $config);
        self::assertSame(RoboType::MAX_FILE_BYTES, strlen($big['files'][0]['content']));
        try {
            $type->normalizeContent(['files' => [['name' => '../x', 'content' => '']]], $config);
            self::fail('Erwartet InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function testBeantwortetUndText(): void
    {
        $type = new RoboType();
        self::assertFalse($type->isAnswered([]));
        self::assertFalse($type->isAnswered(['files' => [['name' => 'main.py', 'content' => "  \n"]]]));
        self::assertTrue($type->isAnswered(['files' => [['name' => 'main.py', 'content' => 'vor()']]]));
        self::assertNull($type->autoCheck([], [], ['files' => []]), 'Tests laufen im Runner');
        self::assertStringContainsString('vor()', $type->toText(['files' => [['name' => 'main.py', 'content' => 'vor()']]], []));
    }
}
