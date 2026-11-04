<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

/**
 * Begrenzung der Java-Läufe je Person: höchstens 1 laufender Lauf, höchstens 30 Starts pro Minute.
 * Die run_id gehört der Person (run_sessions); fremde run_ids sind für andere nicht auffindbar.
 *
 * Ein Lauf gilt als laufend, solange er nicht beendet ist und zuletzt vor höchstens ABANDON_MS
 * abgefragt wurde (der Client fragt alle 250 ms; der Runner beendet unbeachtete Läufe selbst).
 */
final class RunLimiter
{
    public const MAX_STARTS_PER_MINUTE = 30;
    public const ABANDON_MS = 8000;
    /** Absolute Obergrenze für die Dauer eines Laufs, unabhängig vom Abruf. */
    public const MAX_AGE_MS = 300000;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Prüft die Grenzen und reserviert einen Start (Treffer für die Minutenzählung).
     *
     * @throws HttpException 429
     */
    public function check(int $userId, ?int $nowMs = null): void
    {
        $nowMs ??= ExamClock::nowMs();
        if ($this->running($userId, $nowMs) !== null) {
            throw new HttpException(429, 'Es läuft noch ein Programm von dir. Beende es zuerst, bevor du ein neues startest.');
        }
        $starts = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM run_sessions WHERE user_id = ? AND started_at > ?',
            [$userId, ExamClock::toDb($nowMs - 60000)],
        );
        if ($starts >= self::MAX_STARTS_PER_MINUTE) {
            throw new HttpException(429, 'Du startest zu viele Programme hintereinander. Warte einen Moment.');
        }
    }

    /** Laufenden Lauf der Person (run_id) oder null. */
    public function running(int $userId, ?int $nowMs = null): ?string
    {
        $nowMs ??= ExamClock::nowMs();
        $row = $this->db->fetchOne(
            'SELECT run_id FROM run_sessions
             WHERE user_id = ? AND finished_at IS NULL AND started_at > ? AND COALESCE(last_poll_at, started_at) > ?
             ORDER BY started_at DESC LIMIT 1',
            [$userId, ExamClock::toDb($nowMs - self::MAX_AGE_MS), ExamClock::toDb($nowMs - self::ABANDON_MS)],
        );

        return $row !== null ? (string) $row['run_id'] : null;
    }

    public function register(string $runId, int $userId, ?int $examId, ?int $taskId, ?int $nowMs = null): void
    {
        $this->db->run(
            'INSERT INTO run_sessions (run_id, user_id, exam_id, task_id, started_at) VALUES (?, ?, ?, ?, ?)',
            [$runId, $userId, $examId, $taskId, ExamClock::toDb($nowMs ?? ExamClock::nowMs())],
        );
    }

    /** Lauf der Person, sonst null (fremde run_id ist damit für den Aufrufer nicht vorhanden). */
    public function find(string $runId, int $userId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM run_sessions WHERE run_id = ? AND user_id = ?', [$runId, $userId]);
    }

    public function touch(string $runId, ?int $nowMs = null): void
    {
        $this->db->run('UPDATE run_sessions SET last_poll_at = ? WHERE run_id = ?', [ExamClock::toDb($nowMs ?? ExamClock::nowMs()), $runId]);
    }

    public function finish(string $runId, ?int $nowMs = null): void
    {
        $this->db->run(
            'UPDATE run_sessions SET finished_at = COALESCE(finished_at, ?) WHERE run_id = ?',
            [ExamClock::toDb($nowMs ?? ExamClock::nowMs()), $runId],
        );
    }

    /** Alte Zeilen entfernen (selten aufrufen, z. B. bei jedem 50. Start). */
    public function cleanup(?int $nowMs = null): void
    {
        $nowMs ??= ExamClock::nowMs();
        $this->db->run('DELETE FROM run_sessions WHERE started_at < ?', [ExamClock::toDb($nowMs - 6 * 3600000)]);
        $this->db->run('DELETE FROM live_rate_hits WHERE hit_at < ?', [ExamClock::toDb($nowMs - 3600000)]);
    }

    /**
     * Allgemeine Ratenbegrenzung (z. B. falsche Klausurcodes): zählt Treffer im Fenster.
     *
     * @throws HttpException 429 wenn $max erreicht ist
     */
    public function hit(string $bucket, int $userId, int $max, int $windowMs, ?int $nowMs = null): void
    {
        $nowMs ??= ExamClock::nowMs();
        $count = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM live_rate_hits WHERE bucket = ? AND user_id = ? AND hit_at > ?',
            [$bucket, $userId, ExamClock::toDb($nowMs - $windowMs)],
        );
        if ($count >= $max) {
            throw new HttpException(429, 'Zu viele Versuche. Bitte warte einen Moment.');
        }
        $this->db->run(
            'INSERT INTO live_rate_hits (bucket, user_id, hit_at) VALUES (?, ?, ?)',
            [$bucket, $userId, ExamClock::toDb($nowMs)],
        );
    }
}
