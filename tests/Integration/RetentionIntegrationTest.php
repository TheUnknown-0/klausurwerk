<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Retention;
use App\Services\Settings;
use Tests\Support\AdminFixtures;
use Tests\Support\DatabaseTestCase;

/** Aufbewahrungsfristen: fällige Klausuren, Protokolle und vollständiges Löschen samt Dateien. */
final class RetentionIntegrationTest extends DatabaseTestCase
{
    use AdminFixtures;

    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploads = sys_get_temp_dir() . '/kw-retention-' . bin2hex(random_bytes(4));
        mkdir($this->uploads . '/materials', 0775, true);
        mkdir($this->uploads . '/answers', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploads . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->uploads . '/materials');
        @rmdir($this->uploads . '/answers');
        @rmdir($this->uploads);
        parent::tearDown();
    }

    private function retention(): Retention
    {
        $settings = new Settings($this->db);
        $settings->set('retention_answers_months', '24');
        $settings->set('retention_logs_months', '6');

        return new Retention($this->db, $settings);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-04 12:00:00');
    }

    /** Teilnahme mit einem Ereignis, einem Replay-Schritt und einer Anfrage. */
    private function addLogs(int $examId): int
    {
        $student = $this->createUser('student');
        $this->db->run('INSERT INTO participations (exam_id, user_id) VALUES (?, ?)', [$examId, $student['id']]);
        $participationId = $this->db->lastInsertId();
        $this->db->run("INSERT INTO tasks (exam_id, title, body_md, config) VALUES (?, 'A1', '', '{}')", [$examId]);
        $taskId = $this->db->lastInsertId();
        $this->db->run("INSERT INTO participation_events (exam_id, participation_id, type) VALUES (?, ?, 'focus_lost')", [$examId, $participationId]);
        $this->db->run("INSERT INTO answer_ops (participation_id, task_id, seq, client_ts, kind, payload) VALUES (?, ?, 1, 1, 'value', '{}')", [$participationId, $taskId]);
        $this->db->run("INSERT INTO exam_requests (exam_id, participation_id, type) VALUES (?, ?, 'hand')", [$examId, $participationId]);
        $this->db->run("INSERT INTO answers (participation_id, task_id, content) VALUES (?, ?, 'x')", [$participationId, $taskId]);

        return $participationId;
    }

    public function testFaelligkeitNachBeiderFrist(): void
    {
        $fresh = $this->createExam('Frisch', null, 'finished', '2026-10-01 10:00:00');
        $logsDue = $this->createExam('Protokolle fällig', null, 'finished', '2026-03-01 10:00:00');
        $allDue = $this->createExam('Alles fällig', null, 'returned', '2024-06-01 10:00:00', '2024-07-01 10:00:00');
        foreach ([$fresh, $logsDue, $allDue] as $exam) {
            $this->addLogs((int) $exam['id']);
        }

        $due = $this->retention()->dueExams($this->now());
        $byTitle = [];
        foreach ($due as $row) {
            $byTitle[$row['title']] = $row;
        }

        self::assertArrayNotHasKey('Frisch', $byTitle);
        self::assertTrue($byTitle['Protokolle fällig']['logs_due']);
        self::assertFalse($byTitle['Protokolle fällig']['answers_due']);
        self::assertTrue($byTitle['Alles fällig']['logs_due']);
        self::assertTrue($byTitle['Alles fällig']['answers_due']);
        self::assertSame(2, $this->retention()->countDue($this->now()));
    }

    public function testBezugsdatumReturnedBeiAntwortenUndFinishedBeiProtokollen(): void
    {
        // Beendet vor 30 Monaten, aber erst vor 5 Monaten zurückgegeben: Antworten noch in Frist, Protokolle fällig.
        $exam = $this->createExam('Spät zurück', null, 'returned', '2024-05-01 10:00:00', '2026-06-01 10:00:00');
        $this->addLogs((int) $exam['id']);

        $row = $this->retention()->findDue((int) $exam['id'], $this->now());
        self::assertNotNull($row);
        self::assertFalse($row['answers_due']);
        self::assertTrue($row['logs_due']);
    }

    public function testFallbackAufUpdatedAtUndLaufendeKlausurenNie(): void
    {
        $draft = $this->createExam('Alter Entwurf', null, 'draft');
        $this->db->run("UPDATE exams SET updated_at = '2020-01-01 00:00:00' WHERE id = ?", [$draft['id']]);
        $running = $this->createExam('Läuft', null, 'running');
        $this->db->run("UPDATE exams SET updated_at = '2020-01-01 00:00:00' WHERE id = ?", [$running['id']]);
        $this->addLogs((int) $running['id']);

        $due = $this->retention()->dueExams($this->now());
        $titles = array_column($due, 'title');

        self::assertContains('Alter Entwurf', $titles);
        self::assertNotContains('Läuft', $titles);
    }

    public function testNachDemLoeschenDerProtokolleNichtMehrAlsProtokollFaellig(): void
    {
        $exam = $this->createExam('Nur Protokolle', null, 'finished', '2026-03-01 10:00:00');
        $participationId = $this->addLogs((int) $exam['id']);
        $retention = $this->retention();

        $result = $retention->deleteLogs((int) $exam['id']);

        self::assertSame(['events' => 1, 'ops' => 1, 'requests' => 1], $result);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM answers WHERE participation_id = ?', [$participationId]), 'Antworten bleiben');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM participation_events WHERE exam_id = ?', [$exam['id']]));
        self::assertNull($retention->findDue((int) $exam['id'], $this->now()), 'ohne Protokolle und in Frist für Antworten ist nichts mehr fällig');
    }

    public function testKlausurVollstaendigLoeschenEntferntDatenUndDateien(): void
    {
        $exam = $this->createExam('Weg damit', null, 'returned', '2024-01-01 10:00:00', '2024-02-01 10:00:00');
        $other = $this->createExam('Bleibt', null, 'returned', '2024-01-01 10:00:00', '2024-02-01 10:00:00');
        $participationId = $this->addLogs((int) $exam['id']);
        $this->addLogs((int) $other['id']);
        $taskId = (int) $this->db->fetchValue('SELECT id FROM tasks WHERE exam_id = ?', [$exam['id']]);

        $materialFile = bin2hex(random_bytes(8)) . '.pdf';
        $answerFile = bin2hex(random_bytes(8)) . '.png';
        $otherFile = bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($this->uploads . '/materials/' . $materialFile, 'm');
        file_put_contents($this->uploads . '/answers/' . $answerFile, 'a');
        file_put_contents($this->uploads . '/materials/' . $otherFile, 'o');
        $this->db->run(
            "INSERT INTO exam_materials (exam_id, original_name, stored_name, mime, size_bytes) VALUES (?, 'a.pdf', ?, 'application/pdf', 1)",
            [$exam['id'], $materialFile],
        );
        $this->db->run(
            "INSERT INTO exam_materials (exam_id, original_name, stored_name, mime, size_bytes) VALUES (?, 'o.pdf', ?, 'application/pdf', 1)",
            [$other['id'], $otherFile],
        );
        $this->db->run(
            "INSERT INTO answer_files (participation_id, task_id, original_name, stored_name, mime, size_bytes) VALUES (?, ?, 'z.png', ?, 'image/png', 1)",
            [$participationId, $taskId, $answerFile],
        );

        $result = $this->retention()->deleteExam((int) $exam['id'], $this->uploads);

        self::assertSame(2, $result['files']);
        self::assertSame(0, $result['missing']);
        self::assertFileDoesNotExist($this->uploads . '/materials/' . $materialFile);
        self::assertFileDoesNotExist($this->uploads . '/answers/' . $answerFile);
        self::assertFileExists($this->uploads . '/materials/' . $otherFile, 'Dateien anderer Klausuren bleiben');
        self::assertNull($this->db->fetchOne('SELECT 1 FROM exams WHERE id = ?', [$exam['id']]));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM participations WHERE exam_id = ?', [$exam['id']]));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM answers WHERE participation_id = ?', [$participationId]));
        self::assertNotNull($this->db->fetchOne('SELECT 1 FROM exams WHERE id = ?', [$other['id']]));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM participation_events WHERE exam_id = ?', [$other['id']]));
    }

    public function testManipulierteDateinamenWerdenNichtGeloescht(): void
    {
        $exam = $this->createExam('Pfad', null, 'returned', '2024-01-01 10:00:00', '2024-02-01 10:00:00');
        $outside = $this->uploads . '/geheim.txt';
        file_put_contents($outside, 'x');
        $this->db->run(
            "INSERT INTO exam_materials (exam_id, original_name, stored_name, mime, size_bytes) VALUES (?, 'x', '../geheim.txt', 'text/plain', 1)",
            [$exam['id']],
        );

        $result = $this->retention()->deleteExam((int) $exam['id'], $this->uploads);

        self::assertSame(0, $result['files']);
        self::assertSame(1, $result['missing']);
        self::assertFileExists($outside);
        @unlink($outside);
    }

    public function testFristenMitStandardwertenUndUngueltigenEinstellungen(): void
    {
        $settings = new Settings($this->db);
        $retention = new Retention($this->db, $settings);
        self::assertSame(24, $retention->answersMonths());
        self::assertSame(6, $retention->logsMonths());

        $settings->set('retention_answers_months', '0');
        $settings->set('retention_logs_months', 'abc');
        self::assertSame(24, $retention->answersMonths(), '0 ist ungültig: Standard');
        self::assertSame(6, $retention->logsMonths());

        $settings->set('retention_answers_months', '36');
        self::assertSame(36, $retention->answersMonths());
    }
}
