<?php

declare(strict_types=1);

namespace Tests\Unit\TaskTypes;

use App\Services\TaskTypes\CodeType;
use App\Services\TaskTypes\GapsType;
use App\Services\TaskTypes\TextType;
use PHPUnit\Framework\TestCase;

final class CodeTextGapsTest extends TestCase
{
    // ---------- Code ----------

    public function testCodeStandardwerteUndNormalisierung(): void
    {
        $type = new CodeType();
        $config = $type->normalizeConfig(['language' => 'python', 'main_file' => '', 'gui' => true, 'unbekannt' => 1]);
        self::assertSame('python', $config['language']);
        self::assertSame('main.py', $config['main_file']);
        self::assertFalse($config['gui'], 'GUI gibt es nur für Java');
        self::assertArrayNotHasKey('unbekannt', $config);
        self::assertNull($config['java_version']);
        self::assertNull($config['python_packages'] ?? null);
    }

    public function testCodeUngueltigeSpracheStartdateiVersionWerdenAbgelehnt(): void
    {
        $type = new CodeType();
        foreach ([['language' => 'cobol'], ['main_file' => '../x.java'], ['java_version' => '17'], ['run_mode' => 'fly']] as $bad) {
            try {
                $type->normalizeConfig($bad);
                self::fail('Erwartet InvalidArgumentException für ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCodeSampleTestsWerdenNormalisiert(): void
    {
        $config = (new CodeType())->normalizeConfig(['sample_tests' => [
            ['name' => '', 'input' => "3\n", 'expected_output' => '3', 'compare' => 'kaputt'],
            'nicht-array',
        ]]);
        self::assertCount(1, $config['sample_tests']);
        self::assertSame('Beispiel 1', $config['sample_tests'][0]['name']);
        self::assertSame('trim', $config['sample_tests'][0]['compare']);
    }

    public function testCodeContentVerwirftSchreibgeschuetzteUndUnbekannteDateien(): void
    {
        $type = new CodeType();
        $config = $type->normalizeConfig([
            'main_file' => 'Main.java', 'allow_new_files' => false,
            'readonly_files' => ['Util.java'], 'editable_files' => ['Main.java', 'Helper.java'],
        ]);
        $out = $type->normalizeContent(['files' => [
            ['name' => 'Main.java', 'content' => 'class Main {}'],
            ['name' => 'Util.java', 'content' => 'manipuliert'],
            ['name' => 'Helper.java', 'content' => 'x'],
            ['name' => 'Neu.java', 'content' => 'unerlaubt'],
            ['name' => 'Main.java', 'content' => 'doppelt'],
        ]], $config);
        self::assertSame(['Main.java', 'Helper.java'], array_column($out['files'], 'name'));
        self::assertSame('class Main {}', $out['files'][0]['content']);
    }

    public function testCodeNeueDateienNurMitAllowNewFiles(): void
    {
        $type = new CodeType();
        $config = $type->normalizeConfig(['allow_new_files' => true, 'editable_files' => ['Main.java']]);
        $out = $type->normalizeContent(['files' => [['name' => 'Neu.java', 'content' => 'x']]], $config);
        self::assertSame(['Neu.java'], array_column($out['files'], 'name'));
    }

    public function testCodeContentUngueltigerDateinameUndLimit(): void
    {
        $type = new CodeType();
        $config = $type->normalizeConfig(['allow_new_files' => true]);
        try {
            $type->normalizeContent(['files' => [['name' => 'a/b.java', 'content' => '']]], $config);
            self::fail('Pfad im Dateinamen muss abgelehnt werden');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        $many = array_map(static fn (int $i): array => ['name' => "F{$i}.java", 'content' => ''], range(1, 21));
        $this->expectExceptionMessage('Höchstens 20');
        $type->normalizeContent(['files' => $many], $config);
    }

    public function testCodeContentGroessenlimit(): void
    {
        $type = new CodeType();
        $config = $type->normalizeConfig(['allow_new_files' => true]);
        $files = [];
        for ($i = 1; $i <= 4; $i++) {
            $files[] = ['name' => "F{$i}.txt", 'content' => str_repeat('x', 200000)];
        }
        $this->expectExceptionMessage('zu groß');
        $type->normalizeContent(['files' => $files], $config);
    }

    public function testCodeIsAnsweredToTextAutoCheck(): void
    {
        $type = new CodeType();
        self::assertFalse($type->isAnswered(['files' => [['name' => 'a', 'content' => " \n"]]]));
        self::assertTrue($type->isAnswered(['files' => [['name' => 'a', 'content' => 'x']]]));
        self::assertNull($type->autoCheck([], [], ['files' => []]));
        self::assertStringContainsString('// Main.java', $type->toText(['files' => [['name' => 'Main.java', 'content' => 'x']]], []));
        self::assertSame([], $type->normalizeSolution(['x' => 1], []));
    }

    public function testCodeTestsJePassendeSprache(): void
    {
        $tests = CodeType::normalizeTests([
            ['name' => 'A', 'type' => 'io', 'input' => '2', 'expected_output' => '4', 'points' => 1],
            ['name' => 'B', 'type' => 'java_main', 'code' => 'class KwTest {}', 'points' => '2.5'],
        ], 'java');
        self::assertCount(2, $tests);
        self::assertSame(2.5, $tests[1]['points']);
        self::assertSame('trim', $tests[0]['compare']);

        $this->expectExceptionMessage('passt nicht');
        CodeType::normalizeTests([['type' => 'python_assert', 'code' => 'assert 1']], 'java');
    }

    public function testCodeTestsPflichtfelderUndPunkte(): void
    {
        foreach ([
            [['type' => 'sql_result', 'expected_query' => ' ']],
            [['type' => 'java_main', 'code' => '']],
            [['type' => 'io', 'points' => -1]],
            [['type' => 'io', 'points' => 1000]],
            [['type' => 'unbekannt']],
        ] as $case) {
            try {
                CodeType::normalizeTests($case, 'java');
                CodeType::normalizeTests($case, 'sql');
                self::fail('Erwartet InvalidArgumentException: ' . json_encode($case));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $sql = CodeType::normalizeTests([['type' => 'sql_result', 'expected_query' => 'SELECT 1', 'order_sensitive' => 'true']], 'sql');
        self::assertTrue($sql[0]['order_sensitive']);
    }

    // ---------- Text ----------

    public function testTextConfigGrenzen(): void
    {
        $type = new TextType();
        self::assertSame(['min_words' => null, 'max_chars' => 20000, 'placeholder' => ''], $type->normalizeConfig([]));
        self::assertSame(50, $type->normalizeConfig(['min_words' => '50'])['min_words']);
        foreach ([['max_chars' => 5], ['max_chars' => 100001], ['min_words' => -3], ['min_words' => 20000]] as $bad) {
            try {
                $type->normalizeConfig($bad);
                self::fail('Erwartet InvalidArgumentException für ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTextContentWirdAufMaxCharsGekuerzt(): void
    {
        $type = new TextType();
        $config = $type->normalizeConfig(['max_chars' => 100]);
        $out = $type->normalizeContent(['text' => str_repeat('ä', 500), 'evil' => 'x'], $config);
        self::assertSame(100, mb_strlen($out['text']));
        self::assertSame(['text'], array_keys($out));
        self::assertSame(['text' => ''], $type->normalizeContent(['text' => ['array']], $config));
    }

    public function testTextIsAnsweredToTextAutoCheck(): void
    {
        $type = new TextType();
        self::assertFalse($type->isAnswered(['text' => "  \n"]));
        self::assertTrue($type->isAnswered(['text' => 'Antwort']));
        self::assertSame('Antwort', $type->toText(['text' => 'Antwort'], []));
        self::assertNull($type->autoCheck([], [], ['text' => 'x']));
    }

    // ---------- Gaps ----------

    private function gapsConfig(): array
    {
        return (new GapsType())->normalizeConfig([
            'mode' => 'code', 'language' => 'java',
            'template' => 'for (int i = 0; i {{g1}} 10; i++) { summe {{g2}} i; }',
            'gaps' => [['id' => 'g1', 'width' => 4], ['id' => 'g2', 'width' => 3, 'options' => ['+=', '-=', '=']]],
        ]);
    }

    public function testGapsConfigLeitetLueckenAusTemplateAb(): void
    {
        $config = $this->gapsConfig();
        self::assertSame(['g1', 'g2'], array_column($config['gaps'], 'id'));
        self::assertSame(['+=', '-=', '='], $config['gaps'][1]['options']);
        self::assertNull($config['gaps'][0]['options']);
        // Nicht deklarierte Lücke im Text bekommt Standardwerte, überzählige Deklaration entfällt.
        $c = (new GapsType())->normalizeConfig(['template' => 'a {{x}} b', 'gaps' => [['id' => 'weg']]]);
        self::assertSame(['x'], array_column($c['gaps'], 'id'));
        self::assertSame(6, $c['gaps'][0]['width']);
    }

    public function testGapsConfigOhneLueckeOderZuVieleWirdAbgelehnt(): void
    {
        $type = new GapsType();
        try {
            $type->normalizeConfig(['template' => 'Keine Lücke']);
            self::fail('Template ohne Lücke muss scheitern');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        $this->expectExceptionMessage('Höchstens');
        $type->normalizeConfig(['template' => implode(' ', array_map(static fn (int $i): string => '{{g' . $i . '}}', range(1, 41)))]);
    }

    public function testGapsLoesungVerlangtAntwortProLuecke(): void
    {
        $type = new GapsType();
        $config = $this->gapsConfig();
        $sol = $type->normalizeSolution(['gaps' => [
            'g1' => ['accepted' => ['<', '<', '  '], 'case_sensitive' => false],
            'g2' => ['accepted' => ['+=']],
        ]], $config);
        self::assertSame(['<'], $sol['gaps']['g1']['accepted']);
        self::assertFalse($sol['gaps']['g1']['case_sensitive']);
        self::assertTrue($sol['gaps']['g2']['trim']);

        $this->expectExceptionMessage('g2');
        $type->normalizeSolution(['gaps' => ['g1' => ['accepted' => ['<']]]], $config);
    }

    public function testGapsUngueltigerRegexWirdAbgelehnt(): void
    {
        $type = new GapsType();
        $this->expectExceptionMessage('regulär');
        $type->normalizeSolution(['gaps' => [
            'g1' => ['accepted' => ['<'], 'regex' => '(['],
            'g2' => ['accepted' => ['+=']],
        ]], $this->gapsConfig());
    }

    public function testGapsAutoCheck(): void
    {
        $type = new GapsType();
        $config = $this->gapsConfig();
        $solution = $type->normalizeSolution(['gaps' => [
            'g1' => ['accepted' => ['<', '<= 9']],
            'g2' => ['accepted' => ['+='], 'case_sensitive' => true],
        ]], $config);
        self::assertSame(1.0, $type->autoCheck($config, $solution, ['values' => ['g1' => ' <= 9 ', 'g2' => '+=']])['ratio']);
        self::assertSame(0.5, $type->autoCheck($config, $solution, ['values' => ['g1' => '<', 'g2' => '-=']])['ratio']);
        self::assertSame(0.0, $type->autoCheck($config, $solution, ['values' => []])['ratio']);
    }

    public function testGapsAutoCheckGrossKleinUndRegex(): void
    {
        $type = new GapsType();
        $config = $type->normalizeConfig(['template' => '{{a}} {{b}}']);
        $solution = $type->normalizeSolution(['gaps' => [
            'a' => ['accepted' => ['Hallo'], 'case_sensitive' => false],
            'b' => ['accepted' => [], 'regex' => '^\d{2}$'],
        ]], $config);
        self::assertSame(1.0, $type->autoCheck($config, $solution, ['values' => ['a' => 'hALLO', 'b' => '42']])['ratio']);
        self::assertSame(0.0, $type->autoCheck($config, $solution, ['values' => ['a' => 'x', 'b' => '4']])['ratio']);
    }

    public function testGapsContentUndToText(): void
    {
        $type = new GapsType();
        $config = $this->gapsConfig();
        $content = $type->normalizeContent(['values' => ['g1' => '<', 'zz' => 'x', 'g2' => str_repeat('a', 900)]], $config);
        self::assertSame(['g1', 'g2'], array_keys($content['values']));
        self::assertSame(500, mb_strlen($content['values']['g2']));
        self::assertFalse($type->isAnswered(['values' => ['g1' => ' ']]));
        self::assertTrue($type->isAnswered($content));
        self::assertStringContainsString('i [<] 10', $type->toText($content, $config));
    }
}
