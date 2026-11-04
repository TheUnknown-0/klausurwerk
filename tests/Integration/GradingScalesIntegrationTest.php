<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\GradingScales;
use Tests\Support\AdminFixtures;
use Tests\Support\DatabaseTestCase;

/** Auflösung des Notenschlüssels für Kurse (Override, Standard der Klassenstufe, nichts). */
final class GradingScalesIntegrationTest extends DatabaseTestCase
{
    use AdminFixtures;

    public function testStandardDerKlassenstufeAusDemStartbestand(): void
    {
        $service = new GradingScales($this->db);

        $ten = $service->scaleForGradeLevel(10);
        self::assertNotNull($ten);
        self::assertSame('grades', $ten['type']);
        self::assertIsArray($ten['thresholds']);
        self::assertSame('1+', $ten['thresholds'][0]['label']);

        $eleven = $service->scaleForGradeLevel(11);
        self::assertSame('points', $eleven['type']);

        self::assertNull($service->scaleForGradeLevel(7), 'für Klasse 7 gibt es keinen Standard');
    }

    public function testKursNutztStandardDerKlassenstufe(): void
    {
        $course = $this->createCourse('10a', 10);
        $scale = (new GradingScales($this->db))->scaleForCourse((int) $course['id']);

        self::assertNotNull($scale);
        self::assertSame(10, (int) $scale['grade_level']);
    }

    public function testKursOverrideGehtVor(): void
    {
        $this->db->run(
            "INSERT INTO grading_scales (name, type, grade_level, thresholds) VALUES ('Sonderschluessel', 'points', NULL, ?)",
            [json_encode([['label' => '15', 'value' => 15, 'min_percent' => 50], ['label' => '0', 'value' => 0, 'min_percent' => 0]])],
        );
        $scaleId = $this->db->lastInsertId();
        $course = $this->createCourse('10a', 10);
        $this->db->run('UPDATE courses SET grading_scale_id = ? WHERE id = ?', [$scaleId, $course['id']]);

        $service = new GradingScales($this->db);
        $scale = $service->scaleForCourse((int) $course['id']);

        self::assertSame('Sonderschluessel', $scale['name']);
        self::assertSame('15', GradingScales::gradeFor($scale['thresholds'], 60.0)['label']);
        self::assertSame('0', GradingScales::gradeFor($scale['thresholds'], 49.9)['label']);
    }

    public function testKeinStandardOderKeinKursLiefertNull(): void
    {
        $service = new GradingScales($this->db);
        $course = $this->createCourse('7a', 7);

        self::assertNull($service->scaleForCourse((int) $course['id']));
        self::assertNull($service->scaleForCourse(null));
        self::assertNull($service->scaleForCourse(987654));
    }

    public function testGeloeschterOverrideFaelltAufStandardZurueck(): void
    {
        $this->db->run("INSERT INTO grading_scales (name, type, grade_level, thresholds) VALUES ('Weg', 'grades', NULL, '[]')");
        $scaleId = $this->db->lastInsertId();
        $course = $this->createCourse('12x', 12);
        $this->db->run('UPDATE courses SET grading_scale_id = ? WHERE id = ?', [$scaleId, $course['id']]);
        $this->db->run('DELETE FROM grading_scales WHERE id = ?', [$scaleId]);

        $scale = (new GradingScales($this->db))->scaleForCourse((int) $course['id']);
        self::assertSame(12, (int) $scale['grade_level']);
    }

    public function testStartbestandIstGueltigUndLuekenlos(): void
    {
        foreach ((new GradingScales($this->db))->all() as $scale) {
            self::assertSame([], GradingScales::validate($scale['thresholds'], $scale['type']), 'Schlüssel ' . $scale['name']);
        }
    }

    public function testAllListetStandardsVorVorlagen(): void
    {
        $this->db->run("INSERT INTO grading_scales (name, type, grade_level, thresholds) VALUES ('Aaa Vorlage', 'grades', NULL, '[]')");
        $all = (new GradingScales($this->db))->all();

        $levels = array_map(static fn (array $s): ?int => $s['grade_level'] === null ? null : (int) $s['grade_level'], $all);
        $firstNull = array_search(null, $levels, true);
        self::assertIsInt($firstNull);
        foreach (array_slice($levels, 0, $firstNull) as $level) {
            self::assertNotNull($level);
        }
    }
}
