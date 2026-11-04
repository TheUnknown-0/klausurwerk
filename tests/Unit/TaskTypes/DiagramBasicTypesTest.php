<?php

declare(strict_types=1);

namespace Tests\Unit\TaskTypes;

use App\Services\TaskTypes\DrawingType;
use App\Services\TaskTypes\FlowchartType;
use App\Services\TaskTypes\StructogramType;
use App\Services\TaskTypes\TaskTypeRegistry;
use PHPUnit\Framework\TestCase;

/** Zeichnung, Struktogramm, Programmablaufplan und Registrierung der Diagramm-Typen. */
final class DiagramBasicTypesTest extends TestCase
{
    public function testAlleDiagrammTypenSindRegistriert(): void
    {
        foreach (['drawing', 'uml', 'structogram', 'flowchart', 'logic', 'truth_table', 'automaton', 'er', 'relational'] as $name) {
            $type = TaskTypeRegistry::get($name);
            self::assertNotNull($type, $name);
            self::assertSame('Diagramme', $type->group());
            self::assertNotSame('', $type->label());
            // Standardwerte müssen sich selbst normalisieren lassen.
            $config = $type->normalizeConfig($type->defaultConfig());
            self::assertIsArray($config);
            self::assertIsArray($type->normalizeContent([], $config));
            self::assertNull($type->autoCheck($config, [], $type->normalizeContent([], $config)) ?? null, $name . ' ohne Lösung');
        }
        self::assertArrayHasKey('Diagramme', TaskTypeRegistry::grouped());
    }

    // ---------- Zeichnung ----------

    public function testZeichnungKonfigurationWirdBegrenzt(): void
    {
        $c = (new DrawingType())->normalizeConfig(['width' => 99999, 'height' => 10, 'background_material_id' => '7', 'allow_upload' => 'true']);
        self::assertSame(3000, $c['width']);
        self::assertSame(200, $c['height']);
        self::assertSame(7, $c['background_material_id']);
        self::assertTrue($c['allow_upload']);
        self::assertNull((new DrawingType())->normalizeConfig(['background_material_id' => 0])['background_material_id']);
    }

    public function testZeichnungNormalisiertStricheTexteUndUploads(): void
    {
        $type = new DrawingType();
        $config = $type->normalizeConfig(['width' => 400, 'height' => 300]);
        $content = $type->normalizeContent([
            'strokes' => [
                ['color' => '#FF0000', 'width' => 3, 'points' => [[1.234, 2.66], [999, -5], ['x', 1], [10]]],
                ['color' => 'javascript:alert(1)', 'width' => 500, 'points' => [[5, 5]]],
                ['color' => '#000', 'points' => []],
            ],
            'texts' => [
                ['id' => 't1', 'x' => 50, 'y' => 60, 'text' => "  Hallo\x01 "],
                ['id' => 't1', 'x' => 1, 'y' => 1, 'text' => 'doppelt'],
                ['id' => 'bad id', 'x' => 1, 'y' => 1, 'text' => 'x'],
                ['id' => 't2', 'x' => 1, 'y' => 1, 'text' => ''],
            ],
            'upload_ids' => [5, '6', 5, 'x', -1],
            'unbekannt' => 1,
        ], $config);

        self::assertCount(2, $content['strokes']);
        self::assertSame('#ff0000', $content['strokes'][0]['color']);
        self::assertSame([[1.2, 2.7], [400.0, 0.0]], $content['strokes'][0]['points']);
        self::assertSame('#000000', $content['strokes'][1]['color']);
        self::assertSame(40, $content['strokes'][1]['width']);
        self::assertCount(1, $content['texts']);
        self::assertSame('Hallo', $content['texts'][0]['text']);
        self::assertSame([5, 6], $content['upload_ids']);
        self::assertArrayNotHasKey('unbekannt', $content);
    }

    public function testZeichnungBeantwortetUndText(): void
    {
        $type = new DrawingType();
        $config = $type->normalizeConfig([]);
        self::assertFalse($type->isAnswered($type->normalizeContent([], $config)));
        $content = $type->normalizeContent(['strokes' => [['points' => [[1, 1], [2, 2]]]], 'texts' => [['id' => 'a', 'x' => 1, 'y' => 1, 'text' => 'Knoten']]], $config);
        self::assertTrue($type->isAnswered($content));
        $text = $type->toText($content, $config);
        self::assertStringContainsString('1 Strich', $text);
        self::assertStringContainsString('Knoten', $text);
        self::assertNull($type->autoCheck($config, [], $content));
        self::assertSame([], $type->normalizeSolution(['x' => 1], $config));
    }

    // ---------- Struktogramm ----------

    private function structogram(): array
    {
        return ['root' => ['type' => 'sequence', 'children' => [
            ['id' => 'b1', 'type' => 'statement', 'text' => 'summe := 0'],
            ['id' => 'b2', 'type' => 'for', 'head' => 'i von 1 bis n', 'body' => ['type' => 'sequence', 'children' => [
                ['id' => 'b3', 'type' => 'if', 'cond' => 'i gerade', 'then' => ['type' => 'sequence', 'children' => [
                    ['id' => 'b4', 'type' => 'statement', 'text' => 'summe := summe + i'],
                ]], 'else' => ['type' => 'sequence', 'children' => []]],
            ]]],
            ['id' => 'b5', 'type' => 'switch', 'expr' => 'x', 'cases' => [
                ['label' => '1', 'body' => ['type' => 'sequence', 'children' => [['id' => 'b6', 'type' => 'call', 'text' => 'foo()']]]],
                ['label' => 'sonst', 'body' => ['type' => 'sequence', 'children' => [['id' => 'b7', 'type' => 'break', 'text' => '']]]],
            ]],
            ['id' => 'b8', 'type' => 'while', 'cond' => 'a > 0', 'body' => ['type' => 'sequence', 'children' => []]],
            ['id' => 'b9', 'type' => 'do_while', 'cond' => 'ok', 'body' => ['type' => 'sequence', 'children' => [['id' => 'b10', 'type' => 'statement', 'text' => 'weiter']]]],
        ]]];
    }

    public function testStruktogrammBleibtErhaltenUndTextIstEingerueckt(): void
    {
        $type = new StructogramType();
        $content = $type->normalizeContent($this->structogram(), []);
        self::assertSame($this->structogram(), $content);
        self::assertTrue($type->isAnswered($content));
        $text = $type->toText($content, []);
        self::assertStringContainsString("FÜR i von 1 bis n\n    WENN i gerade DANN\n        summe := summe + i\n    ENDE WENN\nENDE FÜR", $text);
        self::assertStringContainsString("FALL sonst:\n        ABBRUCH", $text);
        self::assertStringContainsString("WIEDERHOLE\n    weiter\nBIS ok", $text);
        self::assertNull($type->autoCheck([], [], $content));
    }

    public function testStruktogrammVerwirftUngueltigeBloeckeUndDoppelteIds(): void
    {
        $type = new StructogramType();
        $content = $type->normalizeContent(['root' => ['type' => 'sequence', 'children' => [
            ['id' => 'a', 'type' => 'statement', 'text' => 'ok'],
            ['id' => 'a', 'type' => 'statement', 'text' => 'doppelt'],
            ['id' => 'b', 'type' => 'unbekannt'],
            ['type' => 'statement', 'text' => 'ohne id'],
            'kaputt',
        ]]], []);
        self::assertCount(1, $content['root']['children']);
        self::assertFalse($type->isAnswered($type->normalizeContent(['root' => 5], [])));
        self::assertSame(['root' => ['type' => 'sequence', 'children' => []]], $type->normalizeSolution([], []));
    }

    public function testStruktogrammBegrenztTiefeUndAnzahl(): void
    {
        $type = new StructogramType();
        $inner = ['type' => 'sequence', 'children' => [['id' => 'leaf', 'type' => 'statement', 'text' => 'x']]];
        for ($i = 0; $i < 30; $i++) {
            $inner = ['type' => 'sequence', 'children' => [['id' => 'w' . $i, 'type' => 'while', 'cond' => 'c', 'body' => $inner]]];
        }
        $content = $type->normalizeContent(['root' => $inner], []);
        $depth = 0;
        $node = $content['root'];
        while ($node['children'] !== []) {
            $node = $node['children'][0]['body'] ?? ['children' => []];
            $depth++;
        }
        self::assertLessThanOrEqual(StructogramType::MAX_DEPTH + 2, $depth);

        $many = [];
        for ($i = 0; $i < 400; $i++) {
            $many[] = ['id' => 's' . $i, 'type' => 'statement', 'text' => 't'];
        }
        $content = $type->normalizeContent(['root' => ['type' => 'sequence', 'children' => $many]], []);
        self::assertCount(StructogramType::MAX_NODES, $content['root']['children']);
    }

    // ---------- Programmablaufplan ----------

    public function testProgrammablaufplan(): void
    {
        $type = new FlowchartType();
        $content = $type->normalizeContent([
            'nodes' => [
                ['id' => 'n1', 'type' => 'start', 'text' => 'Start', 'x' => 100.4, 'y' => 20],
                ['id' => 'n2', 'type' => 'decision', 'text' => 'x > 0?', 'x' => 100, 'y' => 100],
                ['id' => 'n3', 'type' => 'io', 'text' => 'Ausgabe', 'x' => 200, 'y' => 200],
                ['id' => 'n4', 'type' => 'seltsam', 'text' => 'Fallback', 'x' => 0, 'y' => 0],
                ['id' => 'n3', 'type' => 'end', 'text' => 'doppelt'],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'n1', 'to' => 'n2', 'label' => ''],
                ['id' => 'e2', 'from' => 'n2', 'to' => 'n3', 'label' => 'Ja'],
                ['id' => 'e3', 'from' => 'n2', 'to' => 'fehlt', 'label' => 'Nein'],
            ],
        ], []);
        self::assertCount(4, $content['nodes']);
        self::assertSame('process', $content['nodes'][3]['type']);
        self::assertSame(100, $content['nodes'][0]['x']);
        self::assertCount(2, $content['edges']);
        self::assertTrue($type->isAnswered($content));
        $text = $type->toText($content, []);
        self::assertStringContainsString('[1] Start: Start', $text);
        self::assertStringContainsString('[2] -> [3] (Ja)', $text);
        self::assertNull($type->autoCheck([], [], $content));
        self::assertSame(['nodes' => [], 'edges' => []], $type->normalizeSolution([], []));
    }
}
