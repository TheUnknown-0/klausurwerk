<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions as P;
use App\Services\AccessCards;
use App\Services\CourseService;

/**
 * Zugangskarten drucken: Auswahl nach Kurs oder einzelnen Schüler:innen, neue Passwörter,
 * PDF mit 8 Karten je A4-Seite.
 *
 * Wichtig: Jeder Druck erzeugt neue Passwörter. Alte Karten der betroffenen Personen werden
 * ungültig (card_version steigt). Passwörter stehen nie im Audit-Log.
 */
final class AccessCardsController extends Controller
{
    private const MAX_CARDS = 600;
    private const SEARCH_LIMIT = 30;

    /** GET /admin/zugangskarten */
    public function index(array $params): string
    {
        $this->requirePermission(P::ZUGANGSKARTEN_DRUCKEN);
        $courses = $this->courses();

        $courseOptions = $courses->visibleCourses(false);
        $courseId = (int) ($_GET['kurs'] ?? 0);
        $course = null;
        $members = [];
        if ($courseId > 0) {
            $course = $courses->find($courseId);
            if ($course === null) {
                throw new HttpException(404, 'Kurs nicht gefunden.');
            }
            if (!$courses->canView($courseId)) {
                throw new HttpException(403);
            }
            $members = array_values(array_filter(
                $courses->members($courseId),
                static fn (array $m): bool => (int) $m['is_active'] === 1,
            ));
        }

        $search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $found = $search !== '' ? $this->searchStudents($search) : [];

        return $this->render('pages/cards/index', [
            'title' => 'Zugangskarten',
            'courseOptions' => $courseOptions,
            'course' => $course,
            'members' => $members,
            'search' => $search,
            'found' => $found,
            'searchLimit' => self::SEARCH_LIMIT,
            'importCount' => $this->importIds() === [] ? 0 : count($this->importIds()),
            'maxCards' => self::MAX_CARDS,
            'pageScripts' => ['users.js'],
            'pageStyles' => ['admin.css'],
        ]);
    }

    /** POST /admin/zugangskarten: neue Passwörter erzeugen und das PDF ausgeben. */
    public function printCards(array $params): string
    {
        $this->requirePermission(P::ZUGANGSKARTEN_DRUCKEN);
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/zugangskarten');

        if (($_POST['bestaetigt'] ?? '') !== '1') {
            $this->flash('error', 'Bitte bestätige, dass die alten Karten ungültig werden. Es wurde nichts geändert.');
            $this->redirect($back);
        }

        $label = '';
        $source = (string) ($_POST['quelle'] ?? '');
        if ($source === 'import') {
            $ids = $this->importIds();
            $label = 'Import';
        } else {
            $ids = array_map('intval', is_array($_POST['user_ids'] ?? null) ? $_POST['user_ids'] : []);
            $label = 'Auswahl' . (((int) ($_POST['kurs_id'] ?? 0)) > 0 ? ' (Kurs ' . (int) $_POST['kurs_id'] . ')' : '');
        }

        $ids = $this->allowedStudentIds($ids);
        if ($ids === []) {
            $this->flash('warning', 'Es wurden keine Schüler:innen ausgewählt, für die du Karten drucken darfst.');
            $this->redirect($back);
        }
        if (count($ids) > self::MAX_CARDS) {
            $this->flash('error', sprintf('Bitte höchstens %d Karten auf einmal drucken (ausgewählt: %d).', self::MAX_CARDS, count($ids)));
            $this->redirect($back);
        }

        $result = (new AccessCards($this->ctx->db))->issueAndRender(
            $ids,
            (string) ($this->ctx->settings->get('school_name') ?? ''),
            (string) ($this->ctx->settings->get('app_name') ?: 'Klausurwerk'),
            $this->ctx->publicUrl('/login'),
        );
        $this->ctx->audit->log('zugangskarten.drucken', 'warning', sprintf('%d Karten (%s), alte Karten ungültig', $result['count'], $label));

        AccessCards::sendPdf($result['pdf'], 'zugangskarten-' . date('Y-m-d') . '.pdf');
    }

    // ---------- Helfer ----------

    private function courses(): CourseService
    {
        return new CourseService($this->ctx->db, $this->ctx->auth);
    }

    /** IDs der zuletzt importierten Personen (Sitzung). @return list<int> */
    private function importIds(): array
    {
        $result = $this->ctx->session->get(UsersController::SESSION_IMPORT_RESULT);
        $ids = is_array($result) && is_array($result['user_ids'] ?? null) ? $result['user_ids'] : [];

        return array_values(array_map('intval', $ids));
    }

    /**
     * Beschränkt auf aktive Schüler:innen, die der Nutzer verwalten darf
     * (Admin und ALLE_KURSE: alle, sonst nur Mitglieder eigener Kurse).
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private function allowedStudentIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT u.id FROM users u WHERE u.role = 'student' AND u.is_active = 1 AND u.id IN ({$placeholders})";
        $args = $ids;

        if (!$this->courses()->seesAllCourses()) {
            $own = $this->courses()->ownCourseIds((int) $this->ctx->auth->id());
            if ($own === []) {
                return [];
            }
            $sql .= ' AND EXISTS (SELECT 1 FROM course_members m WHERE m.user_id = u.id AND m.course_id IN ('
                . implode(',', array_fill(0, count($own), '?')) . '))';
            array_push($args, ...$own);
        }

        return array_map('intval', array_column($this->ctx->db->fetchAll($sql, $args), 'id'));
    }

    /**
     * Schüler:innensuche für die Einzelauswahl.
     *
     * @return list<array<string, mixed>>
     */
    private function searchStudents(string $search): array
    {
        $where = ["u.role = 'student'", 'u.is_active = 1'];
        $args = [];
        foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $token) . '%';
            $where[] = '(u.firstname LIKE ? OR u.lastname LIKE ? OR u.username LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        if (!$this->courses()->seesAllCourses()) {
            $own = $this->courses()->ownCourseIds((int) $this->ctx->auth->id());
            if ($own === []) {
                return [];
            }
            $where[] = 'EXISTS (SELECT 1 FROM course_members m WHERE m.user_id = u.id AND m.course_id IN ('
                . implode(',', array_fill(0, count($own), '?')) . '))';
            array_push($args, ...$own);
        }

        $rows = $this->ctx->db->fetchAll(
            'SELECT u.id, u.username, u.firstname, u.lastname, u.card_issued_at FROM users u WHERE '
            . implode(' AND ', $where) . ' ORDER BY u.lastname, u.firstname LIMIT ' . self::SEARCH_LIMIT,
            $args,
        );
        if ($rows !== []) {
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            $names = [];
            foreach ($this->ctx->db->fetchAll(
                'SELECT cm.user_id, c.name FROM course_members cm JOIN courses c ON c.id = cm.course_id
                 WHERE cm.user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND c.is_archived = 0
                 ORDER BY c.name',
                $ids,
            ) as $row) {
                $names[(int) $row['user_id']][] = (string) $row['name'];
            }
            foreach ($rows as &$row) {
                $row['courses'] = implode(', ', $names[(int) $row['id']] ?? []);
            }
            unset($row);
        }

        return $rows;
    }
}
