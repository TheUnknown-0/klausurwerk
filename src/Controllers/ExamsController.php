<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions as P;
use App\Services\ExamAccess;
use App\Services\ExamKit;
use App\Services\ExamService;
use App\Services\ExamSettings;

/**
 * Klausuren: Liste, Anlegen, Übersicht, Einstellungen, Lehrkräfte, Statuswechsel draft/ready,
 * Kopieren, Löschen. Warteraum/Start/Ende gehören zum Pult (anderes Modul).
 */
final class ExamsController extends Controller
{
    private function exams(): ExamService
    {
        return ExamKit::exams($this->ctx);
    }

    private function canSeeAllCourses(): bool
    {
        return $this->ctx->auth->isAdmin() || $this->ctx->auth->can(P::ALLE_KURSE);
    }

    private function examUrl(int $id, string $suffix = ''): string
    {
        return $this->ctx->url('/klausuren/' . $id . $suffix);
    }

    /** GET /klausuren */
    public function index(array $params): string
    {
        $this->requireStaff();
        $filters = [
            'course' => (int) ($_GET['kurs'] ?? 0),
            'status' => (string) ($_GET['status'] ?? ''),
            'mode' => (string) ($_GET['modus'] ?? ''),
        ];
        $userId = (int) $this->ctx->auth->id();
        $exams = $this->exams()->listFor($this->ctx->exams->visibleExamIds(), $filters, $userId);
        foreach ($exams as &$exam) {
            $exam['role'] = $this->ctx->exams->roleIn((int) $exam['id']);
        }
        unset($exam);

        $courses = $this->ctx->db->fetchAll('SELECT DISTINCT c.id, c.name FROM courses c JOIN exams e ON e.course_id = c.id ORDER BY c.name');

        return $this->render('pages/exams/index', [
            'title' => 'Klausuren',
            'exams' => $exams,
            'filters' => $filters,
            'courses' => $courses,
            'canCreate' => $this->ctx->auth->can(P::KLAUSUREN_ERSTELLEN),
            'canImport' => $this->ctx->auth->can(P::KLAUSUREN_IMPORTIEREN),
            'pageStyles' => ['exams.css'],
        ]);
    }

    /** GET /klausuren/neu */
    public function create(array $params): string
    {
        $this->requirePermission(P::KLAUSUREN_ERSTELLEN);

        return $this->render('pages/exams/form', $this->formData(null, []));
    }

    /** POST /klausuren/neu */
    public function store(array $params): string
    {
        $this->requirePermission(P::KLAUSUREN_ERSTELLEN);
        $this->requireCsrf();
        $in = $this->jsonInput();
        $ownerId = (int) $this->ctx->auth->id();
        $template = (string) ($in['template'] ?? '');
        $copyTasks = filter_var($in['copy_tasks'] ?? false, FILTER_VALIDATE_BOOLEAN);
        try {
            $this->assertCourseAllowed($in);
            $svc = $this->exams();
            $meta = $svc->normalizeMeta($in);
            $note = '';
            if (preg_match('/^klausur:(\d+)$/', $template, $m) === 1) {
                // Wie eine frühere Klausur: Einstellungen, auf Wunsch auch Aufgaben, Lösungen und Material.
                $source = $this->templateSource((int) $m[1], $copyTasks);
                if ($copyTasks) {
                    $id = $svc->copy($source, $ownerId, $meta['title']);
                    $svc->updateMeta($this->ctx->exams->require($id, ExamAccess::CAP_EDIT), $in);
                    $note = ' Aufgaben, Lösungen und Material von "' . $source['title'] . '" sind übernommen.';
                } else {
                    $id = $svc->create($in, $ownerId, ExamAccess::settingsOf($source));
                    $note = ' Die Einstellungen stammen von "' . $source['title'] . '".';
                }
                $this->ctx->audit->log('klausur_angelegt', 'info', 'Klausur #' . $id . ' angelegt, Vorlage #' . $source['id'] . ($copyTasks ? ' mit Aufgaben.' : '.'));
            } elseif (preg_match('/^profil:([a-z]+)$/', $template, $m) === 1 && isset(ExamSettings::PROFILES[$m[1]])) {
                $id = $svc->create($in, $ownerId, ExamSettings::profile($m[1], $meta['mode']));
                $note = ' Einstellungen: Profil "' . ExamSettings::PROFILES[$m[1]]['label'] . '".';
                $this->ctx->audit->log('klausur_angelegt', 'info', 'Klausur #' . $id . ' angelegt, Profil ' . $m[1] . '.');
            } else {
                $id = $svc->create($in, $ownerId);
                $this->ctx->audit->log('klausur_angelegt', 'info', 'Klausur #' . $id . ' angelegt.');
            }
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());

            return $this->render('pages/exams/form', $this->formData(null, $in));
        }
        $this->ctx->exams->forget($id);
        // Probe (Technik-Check) hat keine Aufgaben: dann auf die Klausurseite, sonst gleich in den Editor.
        if ($meta['mode'] === 'probe') {
            $this->flash('success', 'Die Probe wurde als Entwurf angelegt.' . $note);
            $this->redirect($this->examUrl($id));
        }
        $this->flash('success', 'Die Klausur wurde als Entwurf angelegt.' . $note . ' Lege jetzt die Aufgaben an, gespeichert wird automatisch.');
        $this->redirect($this->examUrl($id, '/aufgaben'));
    }

    /**
     * Vorlage für "Wie eine frühere Klausur". Für das Mitkopieren der Aufgaben braucht es Zugriff
     * auf die Lösungen, sonst reicht Lesezugriff.
     *
     * @return array<string, mixed>
     */
    private function templateSource(int $id, bool $withTasks): array
    {
        try {
            return $this->ctx->exams->require($id, $withTasks ? ExamAccess::CAP_SOLUTIONS : ExamAccess::CAP_VIEW);
        } catch (HttpException) {
            throw new \InvalidArgumentException($withTasks
                ? 'Die Aufgaben dieser Klausur kannst du nicht übernehmen (kein Zugriff auf die Lösungen).'
                : 'Diese Klausur steht als Vorlage nicht zur Verfügung.');
        }
    }

    /**
     * Frühere Klausuren als Vorlage: alle sichtbaren, die neuesten zuerst.
     *
     * @return list<array{id: int, title: string, course: ?string, date: ?string}>
     */
    private function templateOptions(): array
    {
        $ids = $this->ctx->exams->visibleExamIds();
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->ctx->db->fetchAll(
            "SELECT e.id, e.title, c.name AS course, COALESCE(e.scheduled_at, e.created_at) AS sort_at
             FROM exams e LEFT JOIN courses c ON c.id = e.course_id
             WHERE e.id IN ($marks) ORDER BY sort_at DESC, e.id DESC LIMIT 50",
            array_values($ids),
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'course' => $r['course'] !== null ? (string) $r['course'] : null,
            'date' => $r['sort_at'] !== null ? date('d.m.Y', (int) strtotime((string) $r['sort_at'])) : null,
        ], $rows);
    }

    /** POST /klausuren/{id}/einstellungen/profil */
    public function applyProfile(array $params): never
    {
        $this->requireStaff();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);
        $profile = (string) ($this->jsonInput()['profile'] ?? '');
        if (!isset(ExamSettings::PROFILES[$profile])) {
            throw new HttpException(400);
        }
        try {
            $this->exams()->applyProfile($exam, $profile);
            $this->flash('success', 'Profil "' . ExamSettings::PROFILES[$profile]['label'] . '" übernommen. Prüfe die Schalter und passe sie bei Bedarf an.');
            $this->ctx->audit->log('klausur_einstellungen', 'info', 'Profil ' . $profile . ' auf Klausur #' . $id . ' angewendet.');
        } catch (HttpException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->ctx->exams->forget($id);
        $this->redirect($this->examUrl($id, '/einstellungen'));
    }

    /** Eigene Kurse für Lehrkräfte; Admins und ALLE_KURSE dürfen jeden Kurs wählen. */
    private function assertCourseAllowed(array $in): void
    {
        $courseId = (int) ($in['course_id'] ?? 0);
        if ($courseId === 0 || $this->canSeeAllCourses()) {
            return;
        }
        $owner = $this->ctx->db->fetchValue('SELECT owner_id FROM courses WHERE id = ?', [$courseId]);
        if ((int) $owner !== (int) $this->ctx->auth->id()) {
            throw new \InvalidArgumentException('Du kannst nur Klausuren für deine eigenen Kurse anlegen.');
        }
    }

    /** @param array<string, mixed>|null $exam @param array<string, mixed> $old */
    private function formData(?array $exam, array $old): array
    {
        $svc = $this->exams();
        $courses = $svc->courseOptions((int) $this->ctx->auth->id(), $this->canSeeAllCourses());
        // Neue Klausur mit genau einem eigenen Kurs: gleich vorauswählen.
        if ($exam === null && !array_key_exists('course_id', $old) && count($courses) === 1) {
            $old['course_id'] = (string) $courses[0]['id'];
        }

        return [
            'title' => $exam === null ? 'Neue Klausur' : 'Stammdaten',
            'exam' => $exam,
            'old' => $old,
            'courses' => $courses,
            'rooms' => $svc->roomOptions(),
            'profiles' => ExamSettings::PROFILES,
            'templates' => $exam === null ? $this->templateOptions() : [],
            'pageStyles' => ['exams.css'],
        ];
    }

    /** GET /klausuren/{id} */
    public function show(array $params): string
    {
        $this->requireStaff();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_VIEW);
        $caps = $this->ctx->exams->capabilities($id);
        $svc = $this->exams();
        $tasks = ExamKit::tasks($this->ctx);

        $course = $exam['course_id'] !== null ? $this->ctx->db->fetchOne('SELECT id, name FROM courses WHERE id = ?', [(int) $exam['course_id']]) : null;
        $owner = $exam['owner_id'] !== null ? $this->ctx->db->fetchOne('SELECT firstname, lastname, username FROM users WHERE id = ?', [(int) $exam['owner_id']]) : null;
        $room = $exam['room_id'] !== null ? $this->ctx->db->fetchValue('SELECT name FROM rooms WHERE id = ?', [(int) $exam['room_id']]) : null;

        return $this->render('pages/exams/show', [
            'title' => (string) $exam['title'],
            'exam' => $exam,
            'caps' => $caps,
            'role' => $this->ctx->exams->roleIn($id),
            'course' => $course,
            'owner' => $owner,
            'room' => $room,
            'totals' => $svc->totals($id),
            'outline' => $tasks->outline($id),
            'staff' => $svc->staffOf($id),
            'report' => $exam['status'] === 'draft' && $caps[ExamAccess::CAP_EDIT] ? $tasks->check($id) : null,
            'materialCount' => count(ExamKit::materials($this->ctx)->forExam($id)),
            'pageStyles' => ['exams.css'],
            'pageScripts' => ['exam-staff.js'],
        ]);
    }

    /** GET /klausuren/{id}/einstellungen */
    public function settings(array $params): string
    {
        $this->requireStaff();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);

        return $this->render('pages/exams/settings', $this->settingsData($exam, null));
    }

    /** @param array<string, mixed> $exam @param array<string, mixed>|null $old */
    private function settingsData(array $exam, ?array $old): array
    {
        $data = $this->formData($exam, $old ?? []);
        $data['title'] = 'Einstellungen: ' . $exam['title'];
        $data['settings'] = ExamSettings::normalize(
            $old !== null && is_array($old['settings'] ?? null) ? $old['settings'] : ExamAccess::settingsOf($exam),
            (string) ($old['mode'] ?? $exam['mode']),
        );
        $data['editable'] = ExamService::isEditable($exam);
        $mode = (string) ($old['mode'] ?? $exam['mode']);
        $data['activeProfile'] = ExamSettings::matchingProfile($data['settings'], $mode);
        $data['advancedOpen'] = ExamSettings::advancedChanged($data['settings'], $mode);

        return $data;
    }

    /** POST /klausuren/{id}/einstellungen */
    public function saveSettings(array $params): string
    {
        $this->requireStaff();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);
        $in = $this->jsonInput();
        try {
            $this->assertCourseAllowed($in);
            $this->exams()->saveSettings($exam, $in);
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());

            return $this->render('pages/exams/settings', $this->settingsData($exam, $in));
        } catch (HttpException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect($this->examUrl($id, '/einstellungen'));
        }
        $this->ctx->exams->forget($id);
        $this->ctx->audit->log('klausur_einstellungen', 'info', 'Einstellungen der Klausur #' . $id . ' gespeichert.');
        $this->flash('success', 'Die Einstellungen wurden gespeichert.');
        $this->redirect($this->examUrl($id, '/einstellungen'));
    }

    /** POST /klausuren/{id}/code */
    public function regenerateCode(array $params): never
    {
        $this->requireStaff();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);
        try {
            $this->exams()->regenerateAccessCode($exam);
            $this->flash('success', 'Ein neuer Klausurcode wurde erzeugt.');
        } catch (HttpException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->ctx->exams->forget($id);
        $this->redirect($this->examUrl($id, '/einstellungen'));
    }

    /** POST /klausuren/{id}/status */
    public function setStatus(array $params): never
    {
        $this->requireStaff();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);
        $target = (string) ($this->jsonInput()['status'] ?? '');
        $svc = $this->exams();
        try {
            if ($target === 'ready') {
                $report = $svc->markReady($exam);
                foreach ($report['errors'] as $msg) {
                    $this->flash('error', $msg);
                }
                foreach ($report['warnings'] as $msg) {
                    $this->flash('warning', $msg);
                }
                if ($report['errors'] === []) {
                    $this->flash('success', 'Die Klausur ist fertiggestellt und kann im Pult geöffnet werden.');
                } else {
                    $this->flash('error', 'Die Klausur ist noch nicht fertig: bitte die oben genannten Punkte beheben.');
                }
            } elseif ($target === 'draft') {
                $svc->backToDraft($exam);
                $this->flash('success', 'Die Klausur ist wieder ein Entwurf.');
            } else {
                throw new HttpException(400);
            }
        } catch (HttpException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->ctx->exams->forget($id);
        $this->redirect($this->examUrl($id));
    }

    /** POST /klausuren/{id}/loeschen */
    public function destroy(array $params): never
    {
        $this->requireStaff();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_MANAGE);
        try {
            $this->exams()->delete($exam);
        } catch (HttpException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect($this->examUrl($id));
        }
        $this->ctx->exams->forget($id);
        $this->ctx->audit->log('klausur_geloescht', 'warning', 'Klausur #' . $id . ' (' . $exam['title'] . ') gelöscht.');
        $this->flash('success', 'Die Klausur wurde gelöscht.');
        $this->redirect($this->ctx->url('/klausuren'));
    }

    /** POST /klausuren/{id}/kopieren */
    public function copy(array $params): never
    {
        $this->requirePermission(P::KLAUSUREN_ERSTELLEN);
        $this->requireCsrf();
        $id = (int) $params['id'];
        // Kopieren schließt die Lösungen ein.
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_SOLUTIONS);
        $newId = $this->exams()->copy($exam, (int) $this->ctx->auth->id());
        $this->ctx->audit->log('klausur_kopiert', 'info', 'Klausur #' . $id . ' als #' . $newId . ' kopiert.');
        $this->flash('success', 'Die Klausur wurde als neuer Entwurf kopiert. Passe Titel, Kurs und Termin an.');
        $this->redirect($this->examUrl($newId, '/einstellungen'));
    }

    // ---------- Lehrkräfte ----------

    /** POST /klausuren/{id}/lehrkraefte */
    public function addStaff(array $params): never
    {
        $this->requireStaff();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_MANAGE);
        $in = $this->jsonInput();
        try {
            $this->exams()->addStaff($exam, (int) ($in['user_id'] ?? 0), (int) $this->ctx->auth->id());
            $this->flash('success', 'Die Lehrkraft wurde hinzugefügt.');
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->ctx->exams->forget($id);
        $this->redirect($this->examUrl($id) . '#lehrkraefte');
    }

    /** POST /klausuren/{id}/lehrkraefte/{uid} */
    public function updateStaff(array $params): never
    {
        $this->requireStaff();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $this->ctx->exams->require($id, ExamAccess::CAP_MANAGE);
        $in = $this->jsonInput();
        $grade = !empty($in['can_grade']) && filter_var($in['can_grade'], FILTER_VALIDATE_BOOLEAN);
        $solutions = !empty($in['can_view_solutions']) && filter_var($in['can_view_solutions'], FILTER_VALIDATE_BOOLEAN);
        $this->exams()->updateStaff($id, (int) $params['uid'], $solutions, $grade);
        $this->ctx->exams->forget($id);
        $this->flash('success', 'Die Rechte wurden gespeichert.');
        $this->redirect($this->examUrl($id) . '#lehrkraefte');
    }

    /** POST /klausuren/{id}/lehrkraefte/{uid}/entfernen */
    public function removeStaff(array $params): never
    {
        $this->requireStaff();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $this->ctx->exams->require($id, ExamAccess::CAP_MANAGE);
        $this->exams()->removeStaff($id, (int) $params['uid']);
        $this->ctx->exams->forget($id);
        $this->flash('success', 'Die Lehrkraft wurde entfernt.');
        $this->redirect($this->examUrl($id) . '#lehrkraefte');
    }

    /** GET /api/klausuren/{id}/lehrkraft-suche?q= (Rückfall, falls /api/benutzer/suche fehlt) */
    public function staffSearch(array $params): array
    {
        $this->requireStaff();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_MANAGE);
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            return ['success' => true, 'users' => []];
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $rows = $this->ctx->db->fetchAll(
            "SELECT id, username, firstname, lastname FROM users
             WHERE role IN ('teacher','admin') AND is_active = 1 AND id <> ?
               AND id NOT IN (SELECT user_id FROM exam_staff WHERE exam_id = ?)
               AND (username LIKE ? OR firstname LIKE ? OR lastname LIKE ? OR CONCAT(firstname, ' ', lastname) LIKE ?)
             ORDER BY lastname, firstname LIMIT 10",
            [(int) $exam['owner_id'], $id, $like, $like, $like, $like],
        );

        return ['success' => true, 'users' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => display_name($r),
            'username' => $r['username'],
        ], $rows)];
    }
}
