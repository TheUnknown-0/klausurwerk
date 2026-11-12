<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Operatoren je Fach. Eine Klausur sieht die Liste ihres Fachs ("Englisch LK" nutzt "Englisch");
 * gibt es dafür keine, die Liste "Allgemein". Bereits zugeordnete Operatoren einer Klausur bleiben
 * immer wählbar, auch wenn das Fach später geändert wurde.
 */
final class Operators
{
    public const FALLBACK = 'Allgemein';

    /** Fach, unter dem die Operatoren der Klausur stehen (mit Rückfall auf "Allgemein"). */
    public static function listSubject(Database $db, string $subject): string
    {
        $s = Subjects::operatorSubject($subject);
        if ($s !== '' && $db->fetchValue('SELECT 1 FROM operators WHERE subject = ? LIMIT 1', [$s]) !== null) {
            return $s;
        }

        return self::FALLBACK;
    }

    /**
     * @return list<array<string, mixed>> id, subject, name, afb, description
     */
    public static function forSubject(Database $db, string $subject, ?int $examId = null): array
    {
        $s = self::listSubject($db, $subject);
        if ($examId === null) {
            return $db->fetchAll(
                'SELECT id, subject, name, afb, description FROM operators WHERE subject = ? ORDER BY sort_order, name',
                [$s],
            );
        }

        return $db->fetchAll(
            'SELECT id, subject, name, afb, description FROM operators
             WHERE subject = ? OR id IN (SELECT operator_id FROM tasks WHERE exam_id = ? AND operator_id IS NOT NULL)
             ORDER BY subject = ? DESC, sort_order, name',
            [$s, $examId, $s],
        );
    }

    /** @return list<string> Fächer, für die es Operatoren gibt */
    public static function subjects(Database $db): array
    {
        return array_map('strval', array_column(
            $db->fetchAll('SELECT subject, MIN(id) AS first FROM operators GROUP BY subject ORDER BY subject = \'Informatik\' DESC, subject'),
            'subject',
        ));
    }

    /**
     * Name => id für den Import: Operatoren des Fachs zuerst, dann alle übrigen (erster Treffer).
     *
     * @return array<string, int>
     */
    public static function nameMap(Database $db, string $subject): array
    {
        $s = self::listSubject($db, $subject);
        $map = [];
        foreach ($db->fetchAll('SELECT id, name FROM operators ORDER BY subject = ? DESC, sort_order, id', [$s]) as $op) {
            $map[mb_strtolower((string) $op['name'])] ??= (int) $op['id'];
        }

        return $map;
    }
}
