<?php

declare(strict_types=1);

namespace Tests\Unit\TaskTypes;

use App\Services\TaskTypes\ChoiceType;
use App\Services\TaskTypes\MatchingType;
use App\Services\TaskTypes\OrderingType;
use App\Services\TaskTypes\TaskTypeRegistry;
use PHPUnit\Framework\TestCase;

final class ChoiceMatchingOrderingTest extends TestCase
{
    // ---------- Registry ----------

    public function testStufeEinsTypenSindRegistriert(): void
    {
        $groups = TaskTypeRegistry::grouped();
        foreach (['code', 'text', 'choice', 'matching', 'ordering', 'gaps'] as $name) {
            self::assertNotNull(TaskTypeRegistry::get($name), $name);
        }
        self::assertArrayHasKey('Programmieren', $groups);
        self::assertArrayHasKey('Text & Auswahl', $groups);
    }

    // ---------- Choice ----------

    private function choiceConfig(bool $multiple = false): array
    {
        return (new ChoiceType())->normalizeConfig([
            'multiple' => $multiple,
            'options' => [
                ['id' => 'a', 'text_md' => 'Eins'],
                ['id' => 'b', 'text_md' => 'Zwei'],
                ['id' => 'c', 'text_md' => 'Drei'],
            ],
        ]);
    }

    public function testChoiceConfigVerlangtZweiOptionenUndEindeutigeIds(): void
    {
        $type = new ChoiceType();
        $this->expectException(\InvalidArgumentException::class);
        $type->normalizeConfig(['options' => [['id' => 'a', 'text_md' => 'x']]]);
    }

    public function testChoiceDoppelteIdWirdAbgelehnt(): void
    {
        $this->expectExceptionMessage('doppelt');
        (new ChoiceType())->normalizeConfig(['options' => [['id' => 'a', 'text_md' => 'x'], ['id' => 'a', 'text_md' => 'y']]]);
    }

    public function testChoiceUngueltigeIdWirdAbgelehnt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ChoiceType())->normalizeConfig(['options' => [['id' => 'a b', 'text_md' => 'x'], ['id' => 'b', 'text_md' => 'y']]]);
    }

    public function testChoiceEinfachauswahlErlaubtNurEineRichtige(): void
    {
        $type = new ChoiceType();
        $config = $this->choiceConfig(false);
        $this->expectExceptionMessage('genau eine');
        $type->normalizeSolution(['correct' => ['a', 'b']], $config);
    }

    public function testChoiceLoesungOhneRichtigeWirdAbgelehnt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ChoiceType())->normalizeSolution(['correct' => ['zzz']], $this->choiceConfig());
    }

    public function testChoiceContentVerwirftUnbekannteUndBeschneidetEinfachauswahl(): void
    {
        $type = new ChoiceType();
        self::assertSame(['selected' => ['a']], $type->normalizeContent(['selected' => ['a', 'b', 'x']], $this->choiceConfig(false)));
        self::assertSame(['selected' => ['a', 'b']], $type->normalizeContent(['selected' => ['a', 'b', 'x', 'a']], $this->choiceConfig(true)));
        self::assertSame(['selected' => []], $type->normalizeContent(['selected' => 'kaputt', 'evil' => 1], $this->choiceConfig()));
    }

    public function testChoiceAutoCheckAllesOderNichts(): void
    {
        $type = new ChoiceType();
        $config = $this->choiceConfig(true);
        $solution = $type->normalizeSolution(['correct' => ['a', 'b'], 'scoring' => 'all_or_nothing'], $config);
        self::assertSame(1.0, $type->autoCheck($config, $solution, ['selected' => ['b', 'a']])['ratio']);
        self::assertSame(0.0, $type->autoCheck($config, $solution, ['selected' => ['a']])['ratio']);
        self::assertSame(0.0, $type->autoCheck($config, $solution, ['selected' => ['a', 'b', 'c']])['ratio']);
    }

    public function testChoiceAutoCheckAnteiligZiehtFalschesAb(): void
    {
        $type = new ChoiceType();
        $config = $this->choiceConfig(true);
        $solution = $type->normalizeSolution(['correct' => ['a', 'b'], 'scoring' => 'partial'], $config);
        self::assertSame(0.5, $type->autoCheck($config, $solution, ['selected' => ['a']])['ratio']);
        self::assertSame(0.5, $type->autoCheck($config, $solution, ['selected' => ['a', 'b', 'c']])['ratio']);
        self::assertSame(0.0, $type->autoCheck($config, $solution, ['selected' => ['c']])['ratio']);
        $details = $type->autoCheck($config, $solution, ['selected' => ['a']])['details'];
        self::assertSame('choice', $details['source']);
    }

    public function testChoiceStudentConfigMischtDeterministischUndVerraetNichts(): void
    {
        $type = new ChoiceType();
        $config = $type->normalizeConfig([
            'shuffle' => true,
            'options' => array_map(static fn (int $i): array => ['id' => 'o' . $i, 'text_md' => 'Option **' . $i . '**'], range(1, 8)),
        ]);
        $one = $type->studentConfig($config, 42);
        self::assertSame($one, $type->studentConfig($config, 42));
        self::assertNotSame($one, $type->studentConfig($config, 43));
        self::assertCount(8, $one['options']);
        self::assertStringContainsString('<strong>', $one['options'][0]['text_html']);
        self::assertArrayNotHasKey('correct', $one);
        self::assertArrayNotHasKey('text_md', $one['options'][0]);
    }

    public function testChoiceOhneMischenBehaeltReihenfolge(): void
    {
        $type = new ChoiceType();
        $config = $type->normalizeConfig(['shuffle' => false, 'options' => [['id' => 'a', 'text_md' => 'A'], ['id' => 'b', 'text_md' => 'B']]]);
        self::assertSame(['a', 'b'], array_column($type->studentConfig($config, 99)['options'], 'id'));
    }

    public function testChoiceIsAnsweredUndToText(): void
    {
        $type = new ChoiceType();
        $config = $this->choiceConfig();
        self::assertFalse($type->isAnswered(['selected' => []]));
        self::assertTrue($type->isAnswered(['selected' => ['a']]));
        self::assertSame('Zwei', $type->toText(['selected' => ['b']], $config));
    }

    // ---------- Matching ----------

    private function matchingConfig(): array
    {
        return (new MatchingType())->normalizeConfig([
            'left' => [['id' => 'l1', 'text_md' => 'Hund'], ['id' => 'l2', 'text_md' => 'Katze']],
            'right' => [['id' => 'r1', 'text_md' => 'bellt'], ['id' => 'r2', 'text_md' => 'miaut']],
        ]);
    }

    public function testMatchingGleicheIdsLinksUndRechtsWerdenAbgelehnt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MatchingType())->normalizeConfig([
            'left' => [['id' => 'a', 'text_md' => 'x']],
            'right' => [['id' => 'a', 'text_md' => 'y']],
        ]);
    }

    public function testMatchingLoesungUndContentVerwerfenUnbekanntes(): void
    {
        $type = new MatchingType();
        $config = $this->matchingConfig();
        self::assertSame(['pairs' => ['l1' => 'r1', 'l2' => 'r2']], $type->normalizeSolution(['pairs' => ['l1' => 'r1', 'l2' => 'r2', 'zz' => 'r1', 'l1x' => 'q']], $config));
        self::assertSame(['pairs' => ['l1' => 'r2']], $type->normalizeContent(['pairs' => ['l1' => 'r2', 'l2' => 'nix', 'bad' => 'r1']], $config));
    }

    public function testMatchingLeereLoesungWirdAbgelehnt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MatchingType())->normalizeSolution(['pairs' => []], $this->matchingConfig());
    }

    public function testMatchingAutoCheckAnteilig(): void
    {
        $type = new MatchingType();
        $config = $this->matchingConfig();
        $solution = ['pairs' => ['l1' => 'r1', 'l2' => 'r2']];
        self::assertSame(1.0, $type->autoCheck($config, $solution, ['pairs' => ['l1' => 'r1', 'l2' => 'r2']])['ratio']);
        self::assertSame(0.5, $type->autoCheck($config, $solution, ['pairs' => ['l1' => 'r1', 'l2' => 'r1']])['ratio']);
        self::assertSame(0.0, $type->autoCheck($config, $solution, ['pairs' => []])['ratio']);
    }

    public function testMatchingStudentConfigMischtBeideSeiten(): void
    {
        $type = new MatchingType();
        $items = static fn (string $p): array => array_map(static fn (int $i): array => ['id' => $p . $i, 'text_md' => 'T' . $i], range(1, 10));
        $config = $type->normalizeConfig(['left' => $items('l'), 'right' => $items('r'), 'shuffle' => true]);
        $s = $type->studentConfig($config, 7);
        self::assertEqualsCanonicalizing(array_column($items('l'), 'id'), array_column($s['left'], 'id'));
        self::assertNotSame(array_column($items('r'), 'id'), array_column($s['right'], 'id'));
    }

    public function testMatchingToText(): void
    {
        self::assertSame('Hund -> bellt', (new MatchingType())->toText(['pairs' => ['l1' => 'r1']], $this->matchingConfig()));
    }

    // ---------- Ordering ----------

    private function orderingConfig(): array
    {
        return (new OrderingType())->normalizeConfig(['items' => [
            ['id' => 's1', 'text_md' => 'Erst'], ['id' => 's2', 'text_md' => 'Dann'], ['id' => 's3', 'text_md' => 'Zuletzt'],
        ]]);
    }

    public function testOrderingLoesungMussPermutationSein(): void
    {
        $type = new OrderingType();
        $config = $this->orderingConfig();
        self::assertSame(['order' => ['s1', 's2', 's3'], 'scoring' => 'all_or_nothing'], $type->normalizeSolution(['order' => ['s1', 's2', 's3']], $config));
        $this->expectException(\InvalidArgumentException::class);
        $type->normalizeSolution(['order' => ['s1', 's2']], $config);
    }

    public function testOrderingContentFiltertUnbekannteUndDoppelte(): void
    {
        $type = new OrderingType();
        self::assertSame(['order' => ['s3', 's1']], $type->normalizeContent(['order' => ['s3', 'x', 's1', 's3']], $this->orderingConfig()));
    }

    public function testOrderingAutoCheck(): void
    {
        $type = new OrderingType();
        $config = $this->orderingConfig();
        $all = ['order' => ['s1', 's2', 's3'], 'scoring' => 'all_or_nothing'];
        $partial = ['order' => ['s1', 's2', 's3'], 'scoring' => 'partial'];
        self::assertSame(1.0, $type->autoCheck($config, $all, ['order' => ['s1', 's2', 's3']])['ratio']);
        self::assertSame(0.0, $type->autoCheck($config, $all, ['order' => ['s1', 's3', 's2']])['ratio']);
        self::assertEqualsWithDelta(1 / 3, $type->autoCheck($config, $partial, ['order' => ['s1', 's3', 's2']])['ratio'], 0.0001);
        self::assertSame(0.0, $type->autoCheck($config, $partial, ['order' => []])['ratio']);
    }

    public function testOrderingStudentConfigIstGemischtAberVollstaendig(): void
    {
        $type = new OrderingType();
        $items = array_map(static fn (int $i): array => ['id' => 's' . $i, 'text_md' => 'P' . $i], range(1, 12));
        $config = $type->normalizeConfig(['items' => $items]);
        $s = $type->studentConfig($config, 5);
        self::assertCount(12, $s['items']);
        self::assertNotSame(array_column($items, 'id'), array_column($s['items'], 'id'));
        self::assertSame($s, $type->studentConfig($config, 5));
    }

    public function testOrderingToTextUndIsAnswered(): void
    {
        $type = new OrderingType();
        self::assertFalse($type->isAnswered(['order' => []]));
        self::assertSame("1. Zuletzt\n2. Erst", $type->toText(['order' => ['s3', 's1']], $this->orderingConfig()));
    }
}
