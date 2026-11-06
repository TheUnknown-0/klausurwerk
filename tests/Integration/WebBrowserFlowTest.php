<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\StudentExamController;
use App\Controllers\WebProxyController;
use App\Services\Materials;
use App\Services\TaskService;
use App\Services\TaskValidationException;
use App\Services\Web\WebSites;
use App\Services\Web\WebToken;
use Tests\Support\DatabaseTestCase;
use Tests\Support\LiveFixtures;

/**
 * Eingebauter Browser über die Controller, ohne Netz: Freigaben speichern und erben,
 * Zugangsmarke holen, gesperrte Adressen melden, abgelaufene Teilnahme aussperren.
 */
final class WebBrowserFlowTest extends DatabaseTestCase
{
    use LiveFixtures;

    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: int, 3: array<string, mixed>, 4: int, 5: int} */
    private function scene(): array
    {
        $teacher = $this->makeUser('teacher', 'Lehrer');
        $student = $this->makeUser('student', 'Mia');
        $exam = $this->makeExam($this->makeCourse([$student]), 'running', ['owner_id' => $teacher]);
        $head = $this->makeTask((int) $exam['id'], null);
        $sub = $this->makeTask((int) $exam['id'], 'text', $head);
        $this->db->run('UPDATE tasks SET web_sites = ? WHERE id = ?', [json_encode([['url' => 'https://docs.python.org/3/library/', 'label' => 'Python', 'exact' => false]]), $head]);
        $p = $this->makeParticipation((int) $exam['id'], $student, 'active');

        return [$teacher, $exam, $student, $p, $head, $sub];
    }

    private function proxy(string $token, array $get): string
    {
        $_GET = $get;

        return (new WebProxyController($this->makeContext(null)))->show(['token' => $token]);
    }

    public function testFreigabenSpeichernErbenUndKopieren(): void
    {
        [$teacher, $exam, , , $head, $sub] = $this->scene();
        $this->db->run("UPDATE exams SET status = 'draft' WHERE id = ?", [$exam['id']]);
        $exam = $this->reloadExam((int) $exam['id']);
        $tasks = new TaskService($this->db, new Materials($this->db, sys_get_temp_dir()));

        $saved = $tasks->save($exam, $sub, ['title' => 'Teil', 'answer_type' => 'text', 'points' => 1, 'web_sites' => [
            ['url' => 'de.wikipedia.org/wiki/Liste_(Datenstruktur)', 'label' => 'Wikipedia', 'exact' => true],
        ]]);
        self::assertSame('https://de.wikipedia.org/wiki/Liste_(Datenstruktur)', $saved['web_sites'][0]['url']);

        $eff = WebSites::effective($this->db, (int) $exam['id'], $sub);
        self::assertSame(['https://de.wikipedia.org/wiki/Liste_(Datenstruktur)', 'https://docs.python.org/3/library/'], array_column($eff, 'url'));

        // Speichern ohne web_sites lässt sie stehen (Fragenliste schickt das Feld nicht mit).
        self::assertCount(1, $tasks->save($exam, $sub, ['title' => 'Teil 2', 'answer_type' => 'text', 'points' => 1])['web_sites']);

        $copy = $tasks->duplicate($exam, $head);
        self::assertSame('https://docs.python.org/3/library/', WebSites::decode($this->db->fetchValue('SELECT web_sites FROM tasks WHERE id = ?', [$copy]))[0]['url']);

        $this->expectException(TaskValidationException::class);
        $tasks->save($exam, $sub, ['title' => 'x', 'answer_type' => 'text', 'points' => 1, 'web_sites' => [['url' => 'javascript:alert(1)']]]);
        self::assertNotNull($teacher);
    }

    public function testZugangsmarkeUndGesperrteAdresse(): void
    {
        [, $exam, $student, $p, , $sub] = $this->scene();
        $examId = (int) $exam['id'];

        // Schüler:in holt die Marke für die Teilaufgabe (Freigaben der Überschrift gelten).
        $ctx = $this->makeContext($student);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $access = (new StudentExamController($ctx))->webAccess(['id' => (string) $examId]);
        self::assertFalse($access['success'] ?? true, 'ohne task_id gibt es keine Freigaben');

        $token = WebToken::fromSettings($ctx->settings)->issue($examId, $sub, 'p', (int) $p['id']);

        $html = $this->proxy($token, ['u' => 'https://evil.example/spickzettel']);
        self::assertStringContainsString('nicht freigegeben', $html);
        self::assertSame(['web_blocked'], array_values(array_intersect($this->eventTypes((int) $p['id']), ['web_blocked'])));
        // Gleiche Adresse gleich noch einmal: nur eine Meldung je Minute.
        $this->proxy($token, ['u' => 'https://evil.example/spickzettel']);
        self::assertCount(1, array_filter($this->eventTypes((int) $p['id']), static fn (string $t): bool => $t === 'web_blocked'));

        // Signatur einer anderen Adresse öffnet nichts.
        $sig = WebToken::fromSettings($ctx->settings)->asset($token, 'https://cdn.example/a.png');
        self::assertStringContainsString('nicht freigegeben', $this->proxy($token, ['u' => 'https://evil.example/b.html', 's' => $sig]));

        // Gefälschte Marke
        self::assertStringContainsString('abgelaufen', $this->proxy($token . 'x', ['u' => 'https://docs.python.org/3/library/']));

        // Abgegeben: Browser zu
        $this->db->run("UPDATE participations SET state = 'submitted' WHERE id = ?", [$p['id']]);
        self::assertStringContainsString('nur während der Bearbeitung', $this->proxy($token, ['u' => 'https://docs.python.org/3/library/']));
    }

    public function testLehrkraftVorschauUndFremde(): void
    {
        [$teacher, $exam, , , , $sub] = $this->scene();
        $examId = (int) $exam['id'];
        $ctx = $this->makeContext($teacher);
        $tokens = WebToken::fromSettings($ctx->settings);

        // Fremde Lehrkraft: Marke ist an sie gebunden, aber ohne Leserecht nutzlos.
        $stranger = $this->makeUser('teacher', 'Fremd');
        $html = $this->proxy($tokens->issue($examId, $sub, 'u', $stranger), ['u' => 'https://evil.example/']);
        self::assertStringContainsString('nur während der Bearbeitung', $html);

        // Eigene Lehrkraft: gesperrte Adresse zeigt den Hinweis, ohne Pult-Meldung (keine Teilnahme).
        $html = $this->proxy($tokens->issue($examId, $sub, 'u', $teacher), ['u' => 'https://evil.example/']);
        self::assertStringContainsString('nicht freigegeben', $html);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM participation_events WHERE type = 'web_blocked' AND exam_id = ?", [$examId]));
    }
}
