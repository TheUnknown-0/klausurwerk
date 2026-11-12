<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\AnswerFiles;
use App\Services\ExamAccess;
use App\Services\ExamContent;
use App\Services\ExamService;
use App\Services\Materials;
use App\Services\MediaPlays;
use App\Services\Operators;
use App\Services\TaskService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\LiveFixtures;

/** Fach je Klausur, Operatoren je Fach, Abspielregel für Audio/Video. */
final class SubjectsMediaTest extends DatabaseTestCase
{
    use LiveFixtures;

    private function exams(): ExamService
    {
        $dir = sys_get_temp_dir() . '/kw-test-' . bin2hex(random_bytes(4));
        $materials = new Materials($this->db, $dir);

        return new ExamService($this->db, new TaskService($this->db, $materials), $materials, new AnswerFiles($this->db, $dir));
    }

    public function testFachKommtAusKursUndBestimmtHilfsmittel(): void
    {
        $teacher = $this->makeUser('teacher', 'Lena');
        $this->db->run("INSERT INTO courses (name, grade_level, school_year, subject) VALUES ('Ch Q1', 11, '2026/2027', 'Chemie')");
        $courseId = $this->db->lastInsertId();
        $svc = $this->exams();

        $id = $svc->create(['title' => 'Säuren', 'mode' => 'exam', 'duration_minutes' => 90, 'course_id' => (string) $courseId], $teacher);
        $exam = $this->db->fetchOne('SELECT * FROM exams WHERE id = ?', [$id]);
        self::assertSame('Chemie', $exam['subject']);
        self::assertTrue(ExamAccess::settingsOf($exam)['aids']['periodic_table']);

        // Eingabe geht vor dem Kurs
        $id2 = $svc->create(['title' => 'Vokabeln', 'mode' => 'practice', 'subject' => 'Englisch LK', 'course_id' => (string) $courseId], $teacher);
        self::assertSame('Englisch LK', $this->db->fetchValue('SELECT subject FROM exams WHERE id = ?', [$id2]));

        // Kopie behält das Fach
        $copy = $svc->copy($exam, $teacher);
        self::assertSame('Chemie', $this->db->fetchValue('SELECT subject FROM exams WHERE id = ?', [$copy]));
    }

    public function testOperatorenJeFachMitRueckfall(): void
    {
        $en = Operators::forSubject($this->db, 'Englisch LK');
        self::assertNotEmpty($en);
        self::assertSame(['Englisch'], array_values(array_unique(array_column($en, 'subject'))));
        self::assertContains('summarize', array_column($en, 'name'));

        $other = Operators::forSubject($this->db, 'Darstellendes Spiel');
        self::assertSame(['Allgemein'], array_values(array_unique(array_column($other, 'subject'))));

        // Zugeordnete Operatoren bleiben wählbar, auch aus einem anderen Fach
        $exam = $this->makeExam(null, 'draft', ['subject' => 'Deutsch']);
        $opId = (int) $this->db->fetchValue("SELECT id FROM operators WHERE subject = 'Informatik' AND name = 'implementieren'");
        $task = $this->makeTask((int) $exam['id']);
        $this->db->run('UPDATE tasks SET operator_id = ? WHERE id = ?', [$opId, $task]);
        $list = Operators::forSubject($this->db, 'Deutsch', (int) $exam['id']);
        self::assertContains($opId, array_map('intval', array_column($list, 'id')));
        self::assertSame('Deutsch', $list[0]['subject'], 'Eigenes Fach zuerst');

        // Import: Name passt zuerst zum Fach
        $map = Operators::nameMap($this->db, 'Chemie');
        self::assertSame(
            (int) $this->db->fetchValue("SELECT id FROM operators WHERE subject = 'Chemie' AND name = 'nennen'"),
            $map['nennen'],
        );
    }

    public function testAbspielLimitMitEinmalSchluessel(): void
    {
        $student = $this->makeUser();
        $exam = $this->makeExam($this->makeCourse([$student]));
        $p = $this->makeParticipation((int) $exam['id'], $student);
        $this->db->run(
            "INSERT INTO exam_materials (exam_id, original_name, stored_name, mime, size_bytes, is_aid, play_policy, play_limit)
             VALUES (?, 'Hoertext.mp3', 'abc.mp3', 'audio/mpeg', 10, 0, 'limited', 2)",
            [$exam['id']],
        );
        $material = $this->db->fetchOne('SELECT * FROM exam_materials WHERE id = ?', [$this->db->lastInsertId()]);
        $plays = new MediaPlays($this->db);

        $first = $plays->start((int) $p['id'], $material);
        self::assertSame(1, $first['plays']);
        self::assertSame(2, $first['limit']);
        self::assertFalse($plays->redeem((int) $p['id'], (int) $material['id'], 'falsch'));
        self::assertTrue($plays->redeem((int) $p['id'], (int) $material['id'], $first['token']));
        self::assertFalse($plays->redeem((int) $p['id'], (int) $material['id'], $first['token']), 'Schlüssel gilt nur einmal');

        $second = $plays->start((int) $p['id'], $material);
        self::assertSame(2, $second['plays']);
        try {
            $plays->start((int) $p['id'], $material);
            self::fail('Dritter Start muss scheitern');
        } catch (\DomainException $e) {
            self::assertStringContainsString('2-mal', $e->getMessage());
        }
        self::assertSame([(int) $material['id'] => 2], $plays->countsFor((int) $p['id']));

        // Schüleransicht bekommt Regel und Zähler, aber keinen Schlüssel
        $content = (new ExamContent($this->db))->forStudent($exam, $p);
        self::assertSame('limited', $content['materials'][0]['media']['policy']);
        self::assertSame(2, ((array) $content['media_plays'])[(int) $material['id']] ?? null);
    }

    public function testNurLehrkraftLaesstSchuelerNichtAbspielen(): void
    {
        $student = $this->makeUser();
        $exam = $this->makeExam($this->makeCourse([$student]));
        $p = $this->makeParticipation((int) $exam['id'], $student);
        $this->db->run(
            "INSERT INTO exam_materials (exam_id, original_name, stored_name, mime, size_bytes, is_aid) VALUES (?, 'Film.mp4', 'abc.mp4', 'video/mp4', 10, 0)",
            [$exam['id']],
        );
        $material = $this->db->fetchOne('SELECT * FROM exam_materials WHERE id = ?', [$this->db->lastInsertId()]);
        self::assertSame('teacher', $material['play_policy'], 'Standard: nur Lehrkraft');
        $this->expectException(\DomainException::class);
        (new MediaPlays($this->db))->start((int) $p['id'], $material);
    }
}
