<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Zugangskarten für Schüler:innen: neues Passwort erzeugen, Karten-Version erhöhen, PDF bauen.
 *
 * Klartext-Passwörter existieren nur im Rückgabewert von issue() und im PDF. Sie werden weder
 * gespeichert noch protokolliert.
 */
final class AccessCards
{
    /** Ohne leicht verwechselbare Zeichen: kein 0/O/o, kein 1/l/I. */
    public const ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const PASSWORD_LENGTH = 10;

    public function __construct(private readonly Database $db)
    {
    }

    public static function generatePassword(int $length = self::PASSWORD_LENGTH): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= self::ALPHABET[random_int(0, $max)];
        }

        return $password;
    }

    /**
     * Erzeugt für jede aktive Schüler:in ein neues Passwort, erhöht card_version und setzt
     * card_issued_at. Alte Karten sind damit ungültig. Andere Konten werden übersprungen.
     *
     * @param list<int> $userIds
     * @return list<array{id: int, name: string, username: string, courses: string, password: string}>
     */
    public function issue(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }
        // Viele Karten mit bcrypt dauern; das Zeitlimit des Requests großzügig anheben.
        @set_time_limit(300);

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $students = $this->db->fetchAll(
            "SELECT id, username, firstname, lastname FROM users
             WHERE role = 'student' AND is_active = 1 AND id IN ({$placeholders})
             ORDER BY lastname, firstname, username",
            $userIds,
        );

        $courseNames = $this->courseNames(array_map(static fn (array $s): int => (int) $s['id'], $students));

        return $this->db->transaction(function () use ($students, $courseNames): array {
            $cards = [];
            foreach ($students as $student) {
                $id = (int) $student['id'];
                $password = self::generatePassword();
                $this->db->run(
                    'UPDATE users SET password = ?, must_change_password = 0,
                            card_version = card_version + 1, card_issued_at = NOW()
                     WHERE id = ?',
                    [password_hash($password, PASSWORD_DEFAULT), $id],
                );
                $cards[] = [
                    'id' => $id,
                    'name' => trim((string) $student['firstname'] . ' ' . (string) $student['lastname']),
                    'username' => (string) $student['username'],
                    'courses' => $courseNames[$id] ?? '',
                    'password' => $password,
                ];
            }

            return $cards;
        });
    }

    /**
     * Aktive Schüler:innen eines Kurses.
     *
     * @return list<int>
     */
    public function activeStudentIdsOfCourse(int $courseId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT u.id FROM course_members cm JOIN users u ON u.id = cm.user_id
             WHERE cm.course_id = ? AND u.role = 'student' AND u.is_active = 1",
            [$courseId],
        );

        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * Baut das PDF (8 Karten je A4-Seite) und gibt die Bytes zurück.
     *
     * @param list<array{name: string, username: string, courses: string, password: string}> $cards
     */
    public function pdf(array $cards, string $schoolName, string $appName, string $loginUrl): string
    {
        return (new CardPdf())->render($cards, $schoolName, $appName, $loginUrl);
    }

    /**
     * Erzeugt Passwörter und PDF in einem Schritt.
     *
     * @param list<int> $userIds
     * @return array{pdf: string, count: int}
     */
    public function issueAndRender(array $userIds, string $schoolName, string $appName, string $loginUrl): array
    {
        $cards = $this->issue($userIds);

        return ['pdf' => $this->pdf($cards, $schoolName, $appName, $loginUrl), 'count' => count($cards)];
    }

    /** Sendet das PDF als Download (nie zwischenspeichern: enthält Klartext-Passwörter) und beendet den Request. */
    public static function sendPdf(string $bytes, string $filename): never
    {
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($bytes));
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        echo $bytes;
        exit;
    }

    // ---------- intern ----------

    /**
     * Kursnamen je Schüler:in (aktive Kurse zuerst), kommagetrennt.
     *
     * @param list<int> $studentIds
     * @return array<int, string>
     */
    private function courseNames(array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT cm.user_id, c.name FROM course_members cm JOIN courses c ON c.id = cm.course_id
             WHERE cm.user_id IN ({$placeholders}) AND c.is_archived = 0
             ORDER BY c.school_year DESC, c.name",
            $studentIds,
        );
        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row['user_id']][] = (string) $row['name'];
        }

        return array_map(static fn (array $list): string => implode(', ', $list), $names);
    }
}
