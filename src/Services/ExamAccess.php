<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Permissions as P;

/**
 * Einzige Stelle, die über Lehrkräfte-Rechte an einer Klausur entscheidet.
 *
 * Rollen an einer Klausur:
 *  - owner: Hauptlehrkraft (exams.owner_id), Admins und Lehrkräfte mit
 *           ALLE_KLAUSUREN werden wie owner behandelt.
 *  - staff: hinzugefügte Lehrkraft (exam_staff). Darf immer Pult bedienen und
 *           mitlesen; Lösungen und Korrektur nur mit Schalter.
 *
 * Fähigkeiten (CAP_*):
 *  - view:      Klausur in Listen/Übersicht sehen
 *  - monitor:   Pult, Live-Mitlesen, Replay, Ereignisprotokoll
 *  - solutions: Musterlösung, Erwartungshorizont, Tests, korrekte Antworten
 *  - grade:     korrigieren (schließt solutions ein)
 *  - edit:      Aufgaben und Einstellungen bearbeiten
 *  - manage:    Lehrkräfte hinzufügen, löschen, Ergebnisse freigeben
 *
 * Schüler:innen haben hier nie eine Fähigkeit; ihr Zugang läuft über
 * participations (siehe Services\ParticipationState).
 */
final class ExamAccess
{
    public const CAP_VIEW = 'view';
    public const CAP_MONITOR = 'monitor';
    public const CAP_SOLUTIONS = 'solutions';
    public const CAP_GRADE = 'grade';
    public const CAP_EDIT = 'edit';
    public const CAP_MANAGE = 'manage';

    /** @var array<int, array{exam: array<string, mixed>, role: ?string, staff: ?array<string, mixed>}> */
    private array $cache = [];

    public function __construct(
        private readonly Database $db,
        private readonly Auth $auth,
    ) {
    }

    /**
     * Lädt die Klausur und prüft die Fähigkeit für den angemeldeten Nutzer.
     * 404, wenn es die Klausur nicht gibt; 403 ohne Fähigkeit.
     *
     * @return array<string, mixed> Die Klausur-Zeile (settings bereits als Array in 'settings_array').
     */
    public function require(int $examId, string $capability): array
    {
        $entry = $this->load($examId);
        if ($entry === null) {
            throw new HttpException(404, 'Klausur nicht gefunden.');
        }
        if (!$this->allows($entry, $capability)) {
            throw new HttpException(403);
        }

        return $entry['exam'];
    }

    /** Prüft eine Fähigkeit ohne Ausnahme. */
    public function can(int $examId, string $capability): bool
    {
        $entry = $this->load($examId);

        return $entry !== null && $this->allows($entry, $capability);
    }

    /** Rolle des angemeldeten Nutzers an der Klausur: 'owner', 'staff' oder null. */
    public function roleIn(int $examId): ?string
    {
        return $this->load($examId)['role'] ?? null;
    }

    /**
     * Alle Fähigkeiten auf einmal (für Templates und JS-Bootstrap).
     *
     * @return array<string, bool>
     */
    public function capabilities(int $examId): array
    {
        $entry = $this->load($examId);
        $caps = [];
        foreach ([self::CAP_VIEW, self::CAP_MONITOR, self::CAP_SOLUTIONS, self::CAP_GRADE, self::CAP_EDIT, self::CAP_MANAGE] as $cap) {
            $caps[$cap] = $entry !== null && $this->allows($entry, $cap);
        }

        return $caps;
    }

    /**
     * IDs aller Klausuren, die der angemeldete Nutzer sehen darf (view).
     *
     * @return list<int>
     */
    public function visibleExamIds(): array
    {
        $user = $this->auth->user();
        if ($user === null || $user['role'] === 'student') {
            return [];
        }
        if ($this->seesAll()) {
            return array_map('intval', array_column($this->db->fetchAll('SELECT id FROM exams'), 'id'));
        }
        $rows = $this->db->fetchAll(
            'SELECT id FROM exams WHERE owner_id = ?
             UNION SELECT exam_id AS id FROM exam_staff WHERE user_id = ?',
            [(int) $user['id'], (int) $user['id']],
        );

        return array_map('intval', array_column($rows, 'id'));
    }

    /** Cache verwerfen (nach Änderung von owner/exam_staff). */
    public function forget(int $examId): void
    {
        unset($this->cache[$examId]);
    }

    /** Dekodiert exams.settings robust zu einem Array. */
    public static function settingsOf(array $exam): array
    {
        $settings = json_decode((string) ($exam['settings'] ?? '{}'), true);

        return is_array($settings) ? $settings : [];
    }

    // ---------- intern ----------

    private function seesAll(): bool
    {
        return $this->auth->isAdmin() || ($this->auth->isTeacher() && $this->auth->can(P::ALLE_KLAUSUREN));
    }

    /** @return array{exam: array<string, mixed>, role: ?string, staff: ?array<string, mixed>}|null */
    private function load(int $examId): ?array
    {
        if (array_key_exists($examId, $this->cache)) {
            return $this->cache[$examId];
        }

        $exam = $this->db->fetchOne('SELECT * FROM exams WHERE id = ?', [$examId]);
        if ($exam === null) {
            return $this->cache[$examId] = null;
        }
        $exam['settings_array'] = self::settingsOf($exam);

        $user = $this->auth->user();
        $role = null;
        $staff = null;
        if ($user !== null && $user['role'] !== 'student') {
            if ($this->seesAll() || (int) ($exam['owner_id'] ?? 0) === (int) $user['id']) {
                $role = 'owner';
            } else {
                $staff = $this->db->fetchOne(
                    'SELECT * FROM exam_staff WHERE exam_id = ? AND user_id = ?',
                    [$examId, (int) $user['id']],
                );
                $role = $staff !== null ? 'staff' : null;
            }
        }

        return $this->cache[$examId] = ['exam' => $exam, 'role' => $role, 'staff' => $staff];
    }

    /** @param array{exam: array<string, mixed>, role: ?string, staff: ?array<string, mixed>} $entry */
    private function allows(array $entry, string $capability): bool
    {
        if ($entry['role'] === 'owner') {
            return true;
        }
        if ($entry['role'] !== 'staff' || $entry['staff'] === null) {
            return false;
        }
        $grade = (int) $entry['staff']['can_grade'] === 1;

        return match ($capability) {
            self::CAP_VIEW, self::CAP_MONITOR => true,
            self::CAP_GRADE => $grade,
            self::CAP_SOLUTIONS => $grade || (int) $entry['staff']['can_view_solutions'] === 1,
            default => false,
        };
    }
}
