<?php

declare(strict_types=1);

namespace Tests\Unit\TaskTypes;

use App\Services\TaskTypes\ErType;
use App\Services\TaskTypes\RelationalType;
use App\Services\TaskTypes\UmlType;
use PHPUnit\Framework\TestCase;

/** Klassendiagramm, ER-Diagramm und Relationenschema: Normalisierung und Vergleich. */
final class DiagramComparisonTest extends TestCase
{
    // ---------- UML ----------

    private function umlModel(): array
    {
        return [
            'classes' => [
                ['id' => 'c1', 'name' => 'Person', 'kind' => 'abstract', 'x' => 10, 'y' => 20,
                    'attributes' => [['visibility' => '-', 'name' => 'name', 'type' => 'String', 'static' => false]],
                    'methods' => [['visibility' => '+', 'name' => 'getName', 'params' => '', 'return_type' => 'String', 'static' => false, 'abstract' => false]]],
                ['id' => 'c2', 'name' => 'Schueler', 'kind' => 'class', 'x' => 10, 'y' => 200, 'attributes' => [], 'methods' => []],
                ['id' => 'c3', 'name' => 'Kurs', 'kind' => 'class', 'x' => 300, 'y' => 200,
                    'attributes' => [['visibility' => '-', 'name' => 'titel', 'type' => 'String', 'static' => false]], 'methods' => []],
            ],
            'relations' => [
                ['id' => 'r1', 'from' => 'c2', 'to' => 'c1', 'kind' => 'inheritance', 'label' => '', 'mult_from' => '', 'mult_to' => ''],
                ['id' => 'r2', 'from' => 'c2', 'to' => 'c3', 'kind' => 'association', 'label' => 'besucht', 'mult_from' => '*', 'mult_to' => '1..*'],
            ],
        ];
    }

    public function testUmlNormalisierung(): void
    {
        $type = new UmlType();
        $config = $type->normalizeConfig(['allow_interfaces' => false]);
        self::assertFalse($config['allow_interfaces']);
        $model = $this->umlModel();
        $model['classes'][0]['kind'] = 'quatsch';
        $model['classes'][0]['attributes'][0]['visibility'] = 'x';
        $model['classes'][] = ['id' => 'c1', 'name' => 'Doppelt'];
        $model['relations'][] = ['id' => 'r3', 'from' => 'c1', 'to' => 'fehlt', 'kind' => 'association'];
        $model['relations'][] = ['id' => 'r4', 'from' => 'c1', 'to' => 'c3', 'kind' => 'zauber'];
        $content = $type->normalizeContent($model, $config);
        self::assertCount(3, $content['classes']);
        self::assertSame('class', $content['classes'][0]['kind']);
        self::assertSame('-', $content['classes'][0]['attributes'][0]['visibility']);
        self::assertCount(3, $content['relations']);
        self::assertSame('association', $content['relations'][2]['kind']);
        self::assertSame('orthogonal', $content['relations'][0]['route']);
        self::assertTrue($type->isAnswered($content));
    }

    public function testUmlVergleichVollstaendig(): void
    {
        $type = new UmlType();
        $config = $type->normalizeConfig([]);
        $solution = $type->normalizeSolution($this->umlModel(), $config);

        // Schüler:in nutzt andere IDs, andere Schreibweise und die Beziehung andersherum (Assoziation)
        $student = $this->umlModel();
        foreach ($student['classes'] as &$c) {
            $c['id'] = 's' . $c['id'];
        }
        unset($c);
        $student['classes'][1]['name'] = 'Schueler';
        $student['classes'][0]['name'] = ' person ';
        $student['relations'] = [
            ['id' => 'a', 'from' => 'sc3', 'to' => 'sc2', 'kind' => 'association', 'label' => '', 'mult_from' => '', 'mult_to' => ''],
            ['id' => 'b', 'from' => 'sc2', 'to' => 'sc1', 'kind' => 'inheritance', 'label' => '', 'mult_from' => '', 'mult_to' => ''],
        ];
        $result = $type->autoCheck($config, $solution, $type->normalizeContent($student, $config));
        self::assertSame(1.0, $result['ratio']);
        self::assertSame([], $result['details']['missing']);
        self::assertSame([], $result['details']['extra']);
    }

    public function testUmlVergleichMitFehlernUndZusaetzlichem(): void
    {
        $type = new UmlType();
        $config = $type->normalizeConfig([]);
        $solution = $type->normalizeSolution($this->umlModel(), $config);

        $student = $this->umlModel();
        $student['classes'][0]['kind'] = 'class';                          // nicht abstrakt
        $student['classes'][0]['attributes'][0]['type'] = 'int';           // falscher Typ
        $student['classes'][0]['methods'] = [];                            // Methode fehlt
        $student['classes'][2]['name'] = 'Lehrer';                         // Kurs fehlt, Lehrer ist zusätzlich
        $student['relations'][0]['kind'] = 'association';                  // Vererbung falsch
        $result = $type->autoCheck($config, $solution, $type->normalizeContent($student, $config));

        $missing = implode(' | ', $result['details']['missing']);
        self::assertStringContainsString('Klasse Kurs', $missing);
        self::assertStringContainsString('Typ weicht ab', $missing);
        self::assertStringContainsString('Methode Person.getName()', $missing);
        self::assertStringContainsString('Vererbung Schueler → Person', $missing);
        self::assertStringContainsString('abstrakte Klasse', $missing);
        $extra = implode(' | ', $result['details']['extra']);
        self::assertStringContainsString('Klasse Lehrer', $extra);
        self::assertGreaterThan(0.0, $result['ratio']);
        self::assertLessThan(0.6, $result['ratio']);
        self::assertSame($result['ratio'], $result['details']['ratio']);
    }

    public function testUmlLoesungBrauchtKlasseUndText(): void
    {
        $type = new UmlType();
        $config = $type->normalizeConfig([]);
        try {
            $type->normalizeSolution(['classes' => []], $config);
            self::fail('Ausnahme erwartet');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Klasse', $e->getMessage());
        }
        $content = $type->normalizeContent($this->umlModel(), $config);
        $text = $type->toText($content, $config);
        self::assertStringContainsString('abstract class Person', $text);
        self::assertStringContainsString('  - name: String', $text);
        self::assertStringContainsString('  + getName(): String', $text);
        self::assertStringContainsString('Schueler --|> Person', $text);
        self::assertStringContainsString('Schueler "*" --- "1..*" Kurs : besucht', $text);
        self::assertNull($type->autoCheck($config, [], $content));
    }

    // ---------- ER ----------

    private function erModel(): array
    {
        return [
            'entities' => [
                ['id' => 'e1', 'name' => 'Schüler', 'x' => 0, 'y' => 0, 'weak' => false],
                ['id' => 'e2', 'name' => 'Kurs', 'x' => 300, 'y' => 0, 'weak' => false],
            ],
            'attributes' => [
                ['id' => 'a1', 'owner' => 'e1', 'name' => 'Nr', 'key' => true, 'x' => 0, 'y' => -80],
                ['id' => 'a2', 'owner' => 'e1', 'name' => 'Name', 'key' => false, 'x' => 80, 'y' => -80],
                ['id' => 'a3', 'owner' => 'e2', 'name' => 'Titel', 'key' => true, 'x' => 300, 'y' => -80],
                ['id' => 'a4', 'owner' => 'r1', 'name' => 'Note', 'key' => false, 'x' => 150, 'y' => 80],
            ],
            'relationships' => [
                ['id' => 'r1', 'name' => 'besucht', 'x' => 150, 'y' => 0, 'ends' => [
                    ['entity' => 'e1', 'cardinality' => 'n'], ['entity' => 'e2', 'cardinality' => 'm'],
                ]],
            ],
        ];
    }

    public function testErNormalisierung(): void
    {
        $type = new ErType();
        $model = $this->erModel();
        $model['attributes'][] = ['id' => 'a9', 'owner' => 'nix', 'name' => 'Waise'];
        $model['relationships'][0]['ends'][] = ['entity' => 'fehlt', 'cardinality' => '1'];
        $model['relationships'][0]['ends'][0]['cardinality'] = 'viele';
        $content = $type->normalizeContent($model, []);
        self::assertCount(4, $content['attributes']);
        self::assertCount(2, $content['relationships'][0]['ends']);
        self::assertSame('1', $content['relationships'][0]['ends'][0]['cardinality']);
        self::assertTrue($type->isAnswered($content));
        $text = $type->toText($content, []);
        self::assertStringContainsString('Entität Schüler (_Nr_, Name)', $text);
        self::assertStringContainsString('Beziehung besucht: Schüler [1] – Kurs [m] (Note)', $text);
    }

    public function testErVergleich(): void
    {
        $type = new ErType();
        $solution = $type->normalizeSolution($this->erModel(), []);
        self::assertSame(1.0, $type->autoCheck([], $solution, $type->normalizeContent($this->erModel(), []))['ratio']);

        $student = $this->erModel();
        $student['entities'][0]['name'] = 'schueler';                     // Umlaut weicht ab: Entität fehlt
        $student['attributes'][0]['key'] = false;                         // Schlüssel fehlt
        $student['relationships'][0]['ends'][1]['cardinality'] = '1';     // falsche Kardinalität
        $student['entities'][] = ['id' => 'e3', 'name' => 'Raum', 'x' => 0, 'y' => 0, 'weak' => false];
        $result = $type->autoCheck([], $solution, $type->normalizeContent($student, []));
        $missing = implode(' | ', $result['details']['missing']);
        self::assertStringContainsString('Entität Schüler', $missing);
        self::assertStringContainsString('Kardinalität m bei Kurs', $missing);
        self::assertContains('Entität Raum', $result['details']['extra']);
        self::assertGreaterThan(0.0, $result['ratio']);
        self::assertLessThan(1.0, $result['ratio']);
    }

    // ---------- Relationenschema ----------

    private function schema(): array
    {
        return ['tables' => [
            ['id' => 't1', 'name' => 'Kurs', 'columns' => [['name' => 'id', 'pk' => true, 'fk' => null], ['name' => 'titel', 'pk' => false, 'fk' => null]]],
            ['id' => 't2', 'name' => 'Schueler', 'columns' => [
                ['name' => 'id', 'pk' => true, 'fk' => null], ['name' => 'name', 'pk' => false, 'fk' => null], ['name' => 'kurs_id', 'pk' => false, 'fk' => 'Kurs.id'],
            ]],
        ]];
    }

    public function testRelationalNormalisierungUndText(): void
    {
        $type = new RelationalType();
        $schema = $this->schema();
        $schema['tables'][1]['columns'][2]['fk'] = ' Kurs . id ';
        $schema['tables'][0]['columns'][1]['fk'] = 'ohnepunkt';
        $content = $type->normalizeContent($schema, []);
        self::assertSame('Kurs.id', $content['tables'][1]['columns'][2]['fk']);
        self::assertNull($content['tables'][0]['columns'][1]['fk']);
        self::assertSame("Kurs(_id_, titel)\nSchueler(_id_, name, ↑kurs_id)\n  ↑kurs_id verweist auf Kurs.id", $type->toText($content, []));
        self::assertTrue($type->isAnswered($content));
        self::assertFalse($type->isAnswered($type->normalizeContent([], [])));
    }

    public function testRelationalVergleich(): void
    {
        $type = new RelationalType();
        $solution = $type->normalizeSolution($this->schema(), []);
        self::assertSame(1.0, $type->autoCheck([], $solution, $type->normalizeContent($this->schema(), []))['ratio']);

        $student = $this->schema();
        $student['tables'][1]['columns'][2]['fk'] = null;                 // Fremdschlüssel fehlt
        $student['tables'][1]['columns'][1]['pk'] = true;                 // falscher Primärschlüssel
        $student['tables'][0]['columns'][] = ['name' => 'raum', 'pk' => false, 'fk' => null]; // zusätzliche Spalte
        $result = $type->autoCheck([], $solution, $type->normalizeContent($student, []));
        self::assertStringContainsString('Fremdschlüssel Schueler.kurs_id → Kurs.id', implode('|', $result['details']['missing']));
        self::assertContains('Spalte Kurs.raum', $result['details']['extra']);
        self::assertContains('Schueler.name ist kein Primärschlüssel', $result['details']['extra']);
        self::assertGreaterThan(0.5, $result['ratio']);
        self::assertLessThan(1.0, $result['ratio']);

        $this->expectException(\InvalidArgumentException::class);
        $type->normalizeSolution(['tables' => []], []);
    }
}
