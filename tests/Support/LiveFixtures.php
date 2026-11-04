<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Auth;
use App\Core\Context;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Services\Audit;
use App\Services\ExamClock;
use App\Services\Settings;

/**
 * Testdaten und Kontext für die Integrationstests des Live-Moduls.
 * Erwartet `$this->db` (Database) aus DatabaseTestCase.
 */
trait LiveFixtures
{
    /** Fester Startzeitpunkt der Klausuren in Tests (Millisekunden). */
    protected const T0 = 1_767_000_000_000;

    private int $seq = 0;

    protected function makeUser(string $role = 'student', string $name = 'Mia', int $accommodation = 0): int
    {
        $this->seq++;
        $this->db->run(
            'INSERT INTO users (username, firstname, lastname, role, accommodation_percent) VALUES (?, ?, ?, ?, ?)',
            ['u' . $this->seq . '_' . strtolower($name), $name, 'Test' . $this->seq, $role, $accommodation],
        );

        return $this->db->lastInsertId();
    }

    /** @param list<int> $studentIds */
    protected function makeCourse(array $studentIds): int
    {
        $this->seq++;
        $this->db->run(
            "INSERT INTO courses (name, grade_level, school_year) VALUES (?, 11, '2026/2027')",
            ['Kurs' . $this->seq],
        );
        $courseId = $this->db->lastInsertId();
        foreach ($studentIds as $id) {
            $this->db->run('INSERT INTO course_members (course_id, user_id) VALUES (?, ?)', [$courseId, $id]);
        }

        return $courseId;
    }

    /**
     * @param array<string, mixed> $overrides Spalten von exams
     * @param array<string, mixed> $settings Überschreibungen der Klausur-Einstellungen
     * @return array<string, mixed>
     */
    protected function makeExam(?int $courseId, string $status = 'running', array $overrides = [], array $settings = []): array
    {
        $mode = (string) ($overrides['mode'] ?? 'exam');
        $normalized = \App\Services\ExamSettings::normalize($settings + \App\Services\ExamSettings::defaults($mode), $mode);
        $row = array_merge([
            'title' => 'Testklausur',
            'course_id' => $courseId,
            'owner_id' => null,
            'mode' => 'exam',
            'status' => $status,
            'duration_minutes' => 90,
            'started_at' => in_array($status, ['running', 'paused', 'finished'], true) ? ExamClock::toDb(self::T0) : null,
            'settings' => json_encode($normalized),
        ], $overrides);
        $this->db->run(
            'INSERT INTO exams (title, course_id, owner_id, mode, status, duration_minutes, started_at, settings, access_code, seb_config_key, room_id, paused_at, paused_seconds)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $row['title'], $row['course_id'], $row['owner_id'], $row['mode'], $row['status'], $row['duration_minutes'],
                $row['started_at'], $row['settings'], $row['access_code'] ?? null, $row['seb_config_key'] ?? null, $row['room_id'] ?? null,
                $row['paused_at'] ?? null, $row['paused_seconds'] ?? 0,
            ],
        );

        return $this->reloadExam($this->db->lastInsertId());
    }

    /** @return array<string, mixed> */
    protected function reloadExam(int $examId): array
    {
        return $this->db->fetchOne('SELECT * FROM exams WHERE id = ?', [$examId]);
    }

    /** @param array<string, mixed> $config */
    protected function makeTask(int $examId, ?string $type = 'text', ?int $parentId = null, array $config = [], int $position = 0): int
    {
        $this->db->run(
            "INSERT INTO tasks (exam_id, parent_id, position, title, body_md, points, answer_type, config) VALUES (?, ?, ?, ?, 'Text **fett**', 4, ?, ?)",
            [$examId, $parentId, $position, 'Aufgabe', $type, json_encode($config === [] ? new \stdClass() : $config)],
        );

        return $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function makeParticipation(int $examId, int $userId, string $state = 'active', array $overrides = []): array
    {
        $row = array_merge([
            'state_changed_at' => ExamClock::toDb(self::T0),
            'joined_at' => ExamClock::toDb(self::T0 - 60000),
            'last_seen_at' => ExamClock::toDb(self::T0),
            'shuffle_seed' => 12345,
            'accommodation_percent' => 0,
            'extra_minutes' => 0,
            'device_hash' => null,
            'last_seq' => 0,
        ], $overrides);
        $this->db->run(
            'INSERT INTO participations (exam_id, user_id, state, state_changed_at, joined_at, last_seen_at, shuffle_seed, accommodation_percent, extra_minutes, device_hash, last_seq)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $examId, $userId, $state, $row['state_changed_at'], $row['joined_at'], $row['last_seen_at'], $row['shuffle_seed'],
                $row['accommodation_percent'], $row['extra_minutes'], $row['device_hash'], $row['last_seq'],
            ],
        );

        return $this->reloadParticipation($this->db->lastInsertId());
    }

    /** @return array<string, mixed> */
    protected function reloadParticipation(int $id): array
    {
        return $this->db->fetchOne('SELECT * FROM participations WHERE id = ?', [$id]);
    }

    /** @return list<string> Ereignistypen einer Teilnahme in Reihenfolge */
    protected function eventTypes(int $participationId): array
    {
        return array_column(
            $this->db->fetchAll('SELECT type FROM participation_events WHERE participation_id = ? ORDER BY id', [$participationId]),
            'type',
        );
    }

    /** Kontext für Controller-Tests (ohne HTTP, ohne echte Sitzung). */
    protected function makeContext(?int $userId): Context
    {
        $_SESSION = [];
        if ($userId !== null) {
            $_SESSION['user_id'] = $userId;
        }
        $session = new Session();
        $csrf = new Csrf($session);
        $auth = new Auth($session, $this->db);
        $view = new View(dirname(__DIR__, 2) . '/templates');
        $settings = new Settings($this->db);
        $audit = new Audit($this->db, $auth);
        $config = [
            'app' => ['env' => 'testing', 'base_url' => '', 'secure_cookies' => false],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kw-test-uploads'],
            'runner' => ['url' => 'http://runner:8080', 'token' => '', 'timeout_seconds' => 5],
        ];
        $ctx = new Context($config, $this->db, $session, $csrf, $auth, $view, $settings, $audit);
        $view->share('ctx', $ctx);
        $view->share('auth', $auth);
        $view->share('csrf', $csrf);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf->token();
        $_SERVER['REQUEST_URI'] = '/test';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['HTTP_HOST'] = 'localhost';
        unset($_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH']);

        return $ctx;
    }
}
