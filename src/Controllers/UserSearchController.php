<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\UserImport;

/**
 * Personensuche für Auswahlfelder anderer Module (Aufsichten, Kursmitglieder, Teilnehmer).
 * Nur für Lehrkräfte und Admins; höchstens 20 Treffer, nur aktive Konten.
 */
final class UserSearchController extends Controller
{
    private const LIMIT = 20;

    /**
     * GET /api/benutzer/suche?q=&role=teacher|student&course_id=
     *
     * role=teacher liefert Lehrkräfte und Admins (beide können Klausuren betreuen).
     * Antwort: {"success":true,"users":[{id,name,username,role,kurse:[{id,name}]}]}
     */
    public function search(array $params): array
    {
        $this->requireStaff();
        $this->ctx->session->release();

        $query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $role = (string) ($_GET['role'] ?? '');
        $courseId = (int) ($_GET['course_id'] ?? 0);

        $where = ['u.is_active = 1'];
        $args = [];
        if ($role === 'teacher') {
            $where[] = "u.role IN ('teacher','admin')";
        } elseif ($role === 'student') {
            $where[] = "u.role = 'student'";
        } elseif ($role !== '') {
            return $this->jsonError('Die Rolle muss teacher oder student sein.', 400);
        }
        if ($courseId > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM course_members m WHERE m.user_id = u.id AND m.course_id = ?)';
            $args[] = $courseId;
        }
        foreach (preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $token) . '%';
            $where[] = '(u.firstname LIKE ? OR u.lastname LIKE ? OR u.username LIKE ?)';
            array_push($args, $like, $like, $like);
        }

        $rows = $this->ctx->db->fetchAll(
            'SELECT u.id, u.username, u.firstname, u.lastname, u.role FROM users u WHERE '
            . implode(' AND ', $where) . ' ORDER BY u.lastname, u.firstname, u.username LIMIT ' . self::LIMIT,
            $args,
        );

        $courses = [];
        if ($rows !== []) {
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            foreach ($this->ctx->db->fetchAll(
                'SELECT cm.user_id, c.id, c.name FROM course_members cm JOIN courses c ON c.id = cm.course_id
                 WHERE cm.user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND c.is_archived = 0
                 ORDER BY c.school_year DESC, c.name',
                $ids,
            ) as $row) {
                $courses[(int) $row['user_id']][] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
            }
        }

        return [
            'success' => true,
            'users' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'name' => trim($r['firstname'] . ' ' . $r['lastname']) ?: $r['username'],
                'username' => $r['username'],
                'role' => $r['role'],
                'kurse' => $courses[(int) $r['id']] ?? [],
            ], $rows),
        ];
    }

    /**
     * GET /api/benutzer/benutzername?vorname=&nachname=
     * Eindeutiger Benutzername-Vorschlag für das Formular (vorname.nachname, Zahl bei Bedarf).
     */
    public function suggestUsername(array $params): array
    {
        $this->requirePermission(\App\Core\Permissions::BENUTZER_ERSTELLEN);
        $this->ctx->session->release();

        $first = mb_substr(trim((string) ($_GET['vorname'] ?? '')), 0, 100);
        $last = mb_substr(trim((string) ($_GET['nachname'] ?? '')), 0, 100);
        if ($first === '' && $last === '') {
            return ['success' => true, 'username' => ''];
        }

        $importer = new UserImport($this->ctx->db);

        return ['success' => true, 'username' => $importer->uniqueUsername(UserImport::baseUsername($first, $last))];
    }
}
