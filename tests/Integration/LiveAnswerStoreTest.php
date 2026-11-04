<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Services\AnswerStore;
use App\Services\EventLog;
use App\Services\ExamClock;
use App\Services\ParticipationState;
use App\Services\TaskTypes\TextType;
use App\Services\TaskTypes\TaskType;
use Tests\Support\DatabaseTestCase;
use Tests\Support\LiveFixtures;

/** Autosave-Regeln aus docs/API.md 1.5. */
final class LiveAnswerStoreTest extends DatabaseTestCase
{
    use LiveFixtures;

    private AnswerStore $store;
    /** @var array<string, mixed> */
    private array $exam;
    /** @var array<string, mixed> */
    private array $p;
    private int $task1;
    private int $task2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new AnswerStore($this->db, static fn (string $name): ?TaskType => $name === 'text' ? new TextType() : null);
        $student = $this->makeUser('student');
        $this->exam = $this->makeExam($this->makeCourse([$student]));
        $this->p = $this->makeParticipation((int) $this->exam['id'], $student);
        $this->task1 = $this->makeTask((int) $this->exam['id'], 'text');
        $this->task2 = $this->makeTask((int) $this->exam['id'], 'text', null, [], 1);
    }

    /** @return array<string, mixed> */
    private function item(int $seq, int $taskId, string $text, int $clientTs = 0): array
    {
        return ['seq' => $seq, 'task_id' => $taskId, 'client_ts' => $clientTs ?: self::T0 + 1000 + $seq, 'kind' => 'value', 'payload' => ['text' => $text]];
    }

    /** @return array<string, mixed> */
    private function save(array $items, array $contents, int $nowMs = self::T0 + 60_000, ?array $participation = null): array
    {
        $p = $participation ?? $this->reloadParticipation((int) $this->p['id']);

        return $this->store->save($this->reloadExam((int) $this->exam['id']), $p, $items, $contents, $nowMs);
    }

    private function answer(int $taskId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM answers WHERE participation_id = ? AND task_id = ?', [$this->p['id'], $taskId]);
    }

    public function testSavesContentOpsAndAdvancesLastSeq(): void
    {
        $r = $this->save(
            [$this->item(1, $this->task1, 'a'), $this->item(2, $this->task1, 'ab')],
            [$this->task1 => ['content' => ['text' => 'ab'], 'flagged' => true]],
        );

        self::assertSame(2, $r['ack_seq']);
        self::assertSame([$this->task1 => 1], $r['versions']);
        self::assertSame(2, $r['saved_items']);
        $answer = $this->answer($this->task1);
        self::assertSame('{"text":"ab"}', $answer['content']);
        self::assertSame(1, (int) $answer['flagged']);
        self::assertSame(0, (int) $answer['has_overtime']);
        self::assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM answer_ops WHERE participation_id = ?', [$this->p['id']]));
        self::assertSame(2, (int) $this->reloadParticipation((int) $this->p['id'])['last_seq']);
    }

    public function testDuplicateSeqIsIgnoredAndStaleContentNotApplied(): void
    {
        $this->save([$this->item(1, $this->task1, 'neu')], [$this->task1 => ['content' => ['text' => 'neu']]]);
        $r = $this->save([$this->item(1, $this->task1, 'alt')], [$this->task1 => ['content' => ['text' => 'alt']]]);

        self::assertSame(1, $r['ack_seq']);
        self::assertSame(0, $r['saved_items']);
        self::assertSame('{"text":"neu"}', $this->answer($this->task1)['content']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM answer_ops WHERE participation_id = ?', [$this->p['id']]));
        self::assertSame([$this->task1 => 1], $r['versions']);
    }

    public function testGapsInSeqAreAllowed(): void
    {
        $this->save([$this->item(1, $this->task1, 'a')], [$this->task1 => ['content' => ['text' => 'a']]]);
        $r = $this->save([$this->item(5, $this->task1, 'abcde')], [$this->task1 => ['content' => ['text' => 'abcde']]]);

        self::assertSame(5, $r['ack_seq']);
        self::assertSame([$this->task1 => 2], $r['versions']);
    }

    public function testVersionOnlyGrowsWhenContentChanges(): void
    {
        $this->save([], [$this->task1 => ['content' => ['text' => 'x']]]);
        $r = $this->save([], [$this->task1 => ['content' => ['text' => 'x']]]);
        self::assertSame([$this->task1 => 1], $r['versions']);

        $r = $this->save([], [$this->task1 => ['content' => ['text' => 'y']]]);
        self::assertSame([$this->task1 => 2], $r['versions']);

        $r = $this->save([], [$this->task1 => ['content' => ['text' => 'y'], 'flagged' => true]]);
        self::assertSame([$this->task1 => 3], $r['versions']);
    }

    public function testProgressCountersFollowSaves(): void
    {
        $this->save([], [
            $this->task1 => ['content' => ['text' => 'etwas'], 'flagged' => true],
            $this->task2 => ['content' => ['text' => '']],
        ]);
        $row = $this->reloadParticipation((int) $this->p['id']);
        self::assertSame(1, (int) $row['answered_count']);
        self::assertSame(1, (int) $row['flagged_count']);
        self::assertNotNull($row['last_save_at']);

        $this->save([], [$this->task1 => ['content' => ['text' => '']], $this->task2 => ['content' => ['text' => 'jetzt']]]);
        $row = $this->reloadParticipation((int) $this->p['id']);
        self::assertSame(1, (int) $row['answered_count']);
        self::assertSame(0, (int) $row['flagged_count']);

        $this->store->recount((int) $this->p['id']);
        $row = $this->reloadParticipation((int) $this->p['id']);
        self::assertSame(1, (int) $row['answered_count']);
    }

    public function testLockedAcceptsOnlyStepsFromBeforeTheLockWindow(): void
    {
        $lockedAt = self::T0 + 600_000;
        (new ParticipationState($this->db, new EventLog($this->db)))
            ->transition((int) $this->p['id'], 'locked', 'Vollbild', null, $lockedAt, ['lock_reason' => 'Vollbild']);

        // Offline getippt, vor der Sperre (und bis 2 s danach) => wird übernommen.
        $r = $this->save(
            [
                $this->item(1, $this->task1, 'vor', $lockedAt - 5000),
                $this->item(2, $this->task1, 'vor+', $lockedAt + 1500),
                $this->item(3, $this->task1, 'nach', $lockedAt + 5000),
            ],
            [$this->task1 => ['content' => ['text' => 'vor+']]],
            $lockedAt + 20_000,
        );

        self::assertSame(2, $r['ack_seq']);
        self::assertSame([3], $r['rejected']);
        self::assertSame(2, $r['saved_items']);
        self::assertSame('{"text":"vor+"}', $this->answer($this->task1)['content']);
    }

    public function testLockedWithOnlyLateStepsIs409(): void
    {
        $lockedAt = self::T0 + 600_000;
        (new ParticipationState($this->db, new EventLog($this->db)))
            ->transition((int) $this->p['id'], 'locked', 'Vollbild', null, $lockedAt, ['lock_reason' => 'Vollbild']);

        try {
            $this->save([$this->item(1, $this->task1, 'nach', $lockedAt + 10_000)], [$this->task1 => ['content' => ['text' => 'nach']]], $lockedAt + 20_000);
            self::fail('409 erwartet');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
        self::assertNull($this->answer($this->task1));
        self::assertSame(0, (int) $this->reloadParticipation((int) $this->p['id'])['last_seq']);
    }

    public function testLockedContentWithoutStepsIsNotSaved(): void
    {
        $lockedAt = self::T0 + 600_000;
        (new ParticipationState($this->db, new EventLog($this->db)))
            ->transition((int) $this->p['id'], 'paused', 'Pause', null, $lockedAt);

        $this->expectException(HttpException::class);
        $this->save([], [$this->task1 => ['content' => ['text' => 'heimlich']]], $lockedAt + 5000);
    }

    public function testAfterSubmittedAlways409(): void
    {
        (new ParticipationState($this->db, new EventLog($this->db)))
            ->transition((int) $this->p['id'], 'submitted', 'Abgabe', null, self::T0 + 600_000);

        try {
            $this->save([$this->item(1, $this->task1, 'x', self::T0 + 1)], [$this->task1 => ['content' => ['text' => 'x']]], self::T0 + 700_000);
            self::fail('409 erwartet');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testOvertimeIsSavedButFlagged(): void
    {
        $afterEnd = self::T0 + 91 * 60_000;
        $r = $this->save(
            [$this->item(1, $this->task1, 'zu spät', $afterEnd)],
            [$this->task1 => ['content' => ['text' => 'zu spät']]],
            $afterEnd,
        );

        self::assertSame(1, $r['saved_items']);
        self::assertSame(1, (int) $this->answer($this->task1)['has_overtime']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT is_overtime FROM answer_ops WHERE participation_id = ?', [$this->p['id']]));
    }

    public function testBeforeEndNothingIsFlaggedAndExtraTimeMovesTheLimit(): void
    {
        $this->db->run('UPDATE participations SET extra_minutes = 10 WHERE id = ?', [$this->p['id']]);
        $t = self::T0 + 95 * 60_000; // nach Grundzeit, aber innerhalb der Zusatzzeit
        $this->save([$this->item(1, $this->task1, 'ok', $t)], [$this->task1 => ['content' => ['text' => 'ok']]], $t);

        self::assertSame(0, (int) $this->answer($this->task1)['has_overtime']);
    }

    public function testMoreThan200ItemsRejected(): void
    {
        $items = [];
        for ($i = 1; $i <= 201; $i++) {
            $items[] = $this->item($i, $this->task1, 'x');
        }
        try {
            $this->save($items, []);
            self::fail('400 erwartet');
        } catch (HttpException $e) {
            self::assertSame(400, $e->status);
        }
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM answer_ops'));
    }

    public function test200ItemsAreAccepted(): void
    {
        $items = [];
        for ($i = 1; $i <= 200; $i++) {
            $items[] = $this->item($i, $this->task1, 'x');
        }
        $r = $this->save($items, [$this->task1 => ['content' => ['text' => 'x']]]);
        self::assertSame(200, $r['ack_seq']);
        self::assertSame(200, $r['saved_items']);
    }

    public function testInvalidContentRejectsOnlyThatTask(): void
    {
        $store = new AnswerStore($this->db, static fn (string $name): ?TaskType => new class () extends \App\Services\TaskTypes\BaseTaskType {
            public function name(): string { return 'text'; }
            public function label(): string { return 'Fake'; }
            public function group(): string { return 'Test'; }
            public function normalizeContent(array $content, array $config): array
            {
                if (($content['text'] ?? '') === 'boese') {
                    throw new \InvalidArgumentException('Nicht erlaubt.');
                }

                return $content;
            }
        });
        $p = $this->reloadParticipation((int) $this->p['id']);

        $r = $store->save($this->exam, $p, [$this->item(1, $this->task1, 'gut'), $this->item(2, $this->task2, 'boese')], [
            $this->task1 => ['content' => ['text' => 'gut']],
            $this->task2 => ['content' => ['text' => 'boese']],
        ], self::T0 + 60_000);

        // Die gültige Antwort ist gespeichert, nur die ungültige wird gemeldet.
        $invalid = (array) $r['invalid'];
        self::assertArrayHasKey($this->task2, $invalid);
        self::assertArrayNotHasKey($this->task1, $invalid);
        self::assertSame(2, $r['ack_seq']);
        $saved = $this->db->fetchValue('SELECT content FROM answers WHERE participation_id = ? AND task_id = ?', [(int) $p['id'], $this->task1]);
        self::assertSame(['text' => 'gut'], json_decode((string) $saved, true));
        self::assertNull($this->db->fetchValue('SELECT id FROM answers WHERE participation_id = ? AND task_id = ?', [(int) $p['id'], $this->task2]));
    }

    public function testUnknownTaskIs422(): void
    {
        $this->expectException(HttpException::class);
        $this->save([$this->item(1, 999999, 'x')], []);
    }

    public function testTaskOfAnotherExamIsRejected(): void
    {
        $other = $this->makeExam(null, 'running');
        $foreign = $this->makeTask((int) $other['id'], 'text');
        try {
            $this->save([], [$foreign => ['content' => ['text' => 'x']]]);
            self::fail('422 erwartet');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
    }

    public function testContainerTaskIsNoTarget(): void
    {
        $container = $this->makeTask((int) $this->exam['id'], null);
        $this->expectException(HttpException::class);
        $this->save([], [$container => ['content' => ['text' => 'x']]]);
    }

    public function testInvalidKindAndSeqRejected(): void
    {
        foreach ([
            ['seq' => 0, 'task_id' => $this->task1, 'kind' => 'value'],
            ['seq' => 1, 'task_id' => $this->task1, 'kind' => 'komisch'],
            ['seq' => 'x', 'task_id' => $this->task1, 'kind' => 'ops'],
        ] as $bad) {
            try {
                $this->save([$bad], []);
                self::fail('422 erwartet');
            } catch (HttpException $e) {
                self::assertSame(422, $e->status);
            }
        }
    }

    public function testExamNotRunningIs409(): void
    {
        $this->db->run("UPDATE exams SET status = 'finished' WHERE id = ?", [$this->exam['id']]);
        $this->expectException(HttpException::class);
        $this->save([], [$this->task1 => ['content' => ['text' => 'x']]]);
    }

    public function testWaitingParticipationCannotSave(): void
    {
        $other = $this->makeUser('student', 'Neu');
        $waiting = $this->makeParticipation((int) $this->exam['id'], $other, 'waiting');
        $this->expectException(HttpException::class);
        $this->store->save($this->exam, $waiting, [], [$this->task1 => ['content' => ['text' => 'x']]], self::T0 + 1000);
    }

    public function testClientOffsetShiftsOfflineWindow(): void
    {
        $lockedAt = self::T0 + 600_000;
        (new ParticipationState($this->db, new EventLog($this->db)))
            ->transition((int) $this->p['id'], 'locked', 'Vollbild', null, $lockedAt, ['lock_reason' => 'Vollbild']);

        // Die Clientuhr geht 10 s vor: client_ts liegt nach der Sperre, korrigiert aber davor.
        $p = $this->reloadParticipation((int) $this->p['id']);
        $r = $this->store->save(
            $this->exam,
            $p,
            [$this->item(1, $this->task1, 'x', $lockedAt + 8000)],
            [$this->task1 => ['content' => ['text' => 'x']]],
            $lockedAt + 30_000,
            10_000 * -1,
        );
        self::assertSame(1, $r['saved_items']);
    }
}
