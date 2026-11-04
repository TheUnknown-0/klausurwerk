<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions as P;
use App\Services\CourseService;
use App\Services\GradingScales;

/**
 * Kurse: Liste, Anlegen, Bearbeiten, Mitglieder, Archivieren, Löschen.
 *
 * Rechte: KURSE_SEHEN zum Ansehen, KURSE_BEARBEITEN zum Ändern. Eigene Kurse (Hauptlehrkraft oder
 * weitere Lehrkraft) sieht und ändert jede Lehrkraft mit dem Recht; fremde nur Admins und
 * Lehrkräfte mit ALLE_KURSE.
 */
final class CoursesController extends Controller
{
    /** GET /admin/kurse */
    public function index(array $params): string
    {
        $this->requirePermission(P::KURSE_SEHEN);
        $service = $this->service();
        $showArchived = ($_GET['archiv'] ?? '') === '1';

        return $this->render('pages/courses/index', [
            'title' => 'Kurse',
            'courses' => $service->visibleCourses($showArchived),
            'showArchived' => $showArchived,
            'canCreate' => $this->ctx->auth->can(P::KURSE_BEARBEITEN),
            'sees' => $service->seesAllCourses(),
            'pageStyles' => ['admin.css'],
        ]);
    }

    /** GET /admin/kurse/neu */
    public function create(array $params): string
    {
        $this->requirePermission(P::KURSE_BEARBEITEN);
        $old = $this->ctx->session->pullOldInput();

        return $this->renderForm(null, $old + [
            'name' => '',
            'grade_level' => '',
            'school_year' => CourseService::currentSchoolYear(),
            'subject' => 'Informatik',
            'owner_id' => (string) $this->ctx->auth->id(),
            'grading_scale_id' => '',
            'teacher_ids' => [],
            'is_archived' => '0',
        ], 'Neuer Kurs');
    }

    /** POST /admin/kurse/neu */
    public function store(array $params): string
    {
        $this->requirePermission(P::KURSE_BEARBEITEN);
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/kurse/neu');

        $data = $this->readForm(null);
        $error = $this->validate($data, null);
        if ($error !== null) {
            $this->ctx->session->rememberInput($_POST);
            $this->flash('error', $error);
            $this->redirect($back);
        }

        $service = $this->service();
        $courseId = $this->ctx->db->transaction(function () use ($service, $data): int {
            $id = $service->create($data['name'], $data['grade_level'], $data['school_year'], $data['subject'], $data['owner_id'], $data['grading_scale_id']);
            $service->setTeachers($id, $data['teacher_ids'], $data['owner_id']);

            return $id;
        });

        $this->ctx->audit->log('kurs.anlegen', 'info', sprintf('%s (%s)', $data['name'], $data['school_year']));
        $this->flash('success', 'Der Kurs wurde angelegt. Jetzt kannst du Schüler:innen hinzufügen.');
        $this->redirect($this->ctx->url('/admin/kurse/' . $courseId));
    }

    /** GET /admin/kurse/{id} */
    public function show(array $params): string
    {
        $this->requirePermission(P::KURSE_SEHEN);
        $course = $this->loadViewable((int) $params['id']);
        $courseId = (int) $course['id'];
        $service = $this->service();

        $owner = $course['owner_id'] !== null
            ? $this->ctx->db->fetchOne('SELECT id, firstname, lastname, username FROM users WHERE id = ?', [(int) $course['owner_id']])
            : null;
        $scale = $course['grading_scale_id'] !== null
            ? (new GradingScales($this->ctx->db))->find((int) $course['grading_scale_id'])
            : (new GradingScales($this->ctx->db))->scaleForGradeLevel((int) $course['grade_level']);

        return $this->render('pages/courses/show', [
            'title' => 'Kurs ' . $course['name'],
            'course' => $course,
            'owner' => $owner,
            'teachers' => $service->teachers($courseId),
            'members' => $service->members($courseId),
            'exams' => $this->ctx->db->fetchAll(
                'SELECT id, title, status, mode, scheduled_at FROM exams WHERE course_id = ?
                 ORDER BY COALESCE(scheduled_at, created_at) DESC, id DESC',
                [$courseId],
            ),
            'scale' => $scale,
            'scaleIsOverride' => $course['grading_scale_id'] !== null,
            'canEdit' => $service->canEdit($courseId),
            'canCards' => $this->ctx->auth->can(P::ZUGANGSKARTEN_DRUCKEN),
            'canStudents' => $this->ctx->auth->can(P::BENUTZER_SEHEN),
            'pageScripts' => ['courses.js'],
            'pageStyles' => ['admin.css'],
        ]);
    }

    /** GET /admin/kurse/{id}/bearbeiten */
    public function edit(array $params): string
    {
        $this->requirePermission(P::KURSE_BEARBEITEN);
        $course = $this->loadEditable((int) $params['id']);
        $old = $this->ctx->session->pullOldInput();

        $values = $old !== [] ? $old : [
            'name' => $course['name'],
            'grade_level' => (string) $course['grade_level'],
            'school_year' => $course['school_year'],
            'subject' => $course['subject'],
            'owner_id' => (string) ($course['owner_id'] ?? ''),
            'grading_scale_id' => (string) ($course['grading_scale_id'] ?? ''),
            'teacher_ids' => array_map(static fn (array $t): int => (int) $t['id'], $this->service()->teachers((int) $course['id'])),
            'is_archived' => (string) $course['is_archived'],
        ];

        return $this->renderForm($course, $values, 'Kurs bearbeiten');
    }

    /** POST /admin/kurse/{id}/bearbeiten */
    public function update(array $params): string
    {
        $this->requirePermission(P::KURSE_BEARBEITEN);
        $this->requireCsrf();
        $course = $this->loadEditable((int) $params['id']);
        $courseId = (int) $course['id'];
        $back = $this->ctx->url('/admin/kurse/' . $courseId . '/bearbeiten');

        $data = $this->readForm($course);
        $error = $this->validate($data, $courseId);
        if ($error !== null) {
            $this->ctx->session->rememberInput($_POST);
            $this->flash('error', $error);
            $this->redirect($back);
        }

        $service = $this->service();
        $this->ctx->db->transaction(function () use ($service, $data, $courseId): void {
            $this->ctx->db->run(
                'UPDATE courses SET name = ?, grade_level = ?, school_year = ?, subject = ?, owner_id = ?,
                        grading_scale_id = ?, is_archived = ? WHERE id = ?',
                [$data['name'], $data['grade_level'], $data['school_year'], $data['subject'], $data['owner_id'],
                    $data['grading_scale_id'], $data['is_archived'], $courseId],
            );
            $service->setTeachers($courseId, $data['teacher_ids'], $data['owner_id']);
        });

        $this->ctx->audit->log('kurs.bearbeiten', 'info', sprintf('%s (%s)', $data['name'], $data['school_year']));
        $this->flash('success', 'Die Änderungen wurden gespeichert.');
        $this->redirect($this->ctx->url('/admin/kurse/' . $courseId));
    }

    /** POST /admin/kurse/{id}/archivieren: Archivierung umschalten. */
    public function toggleArchive(array $params): string
    {
        $this->requirePermission(P::KURSE_BEARBEITEN);
        $this->requireCsrf();
        $course = $this->loadEditable((int) $params['id']);
        $new = (int) $course['is_archived'] === 1 ? 0 : 1;

        $this->ctx->db->run('UPDATE courses SET is_archived = ? WHERE id = ?', [$new, (int) $course['id']]);
        $this->ctx->audit->log($new === 1 ? 'kurs.archivieren' : 'kurs.reaktivieren', 'info', (string) $course['name']);
        $this->flash('success', $new === 1 ? 'Der Kurs ist archiviert.' : 'Der Kurs ist wieder aktiv.');
        $this->redirect($this->ctx->url('/admin/kurse/' . (int) $course['id']));
    }

    /** POST /admin/kurse/{id}/loeschen: nur ohne Klausuren (sonst archivieren). */
    public function destroy(array $params): string
    {
        $this->requirePermission(P::KURSE_BEARBEITEN);
        $this->requireCsrf();
        $course = $this->loadEditable((int) $params['id']);
        $courseId = (int) $course['id'];

        $exams = (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM exams WHERE course_id = ?', [$courseId]);
        if ($exams > 0) {
            $this->flash('error', sprintf(
                'Der Kurs hat noch %d Klausur(en) und kann nicht gelöscht werden. Archiviere ihn stattdessen.',
                $exams,
            ));
            $this->redirect($this->ctx->url('/admin/kurse/' . $courseId));
        }

        $this->ctx->db->run('DELETE FROM courses WHERE id = ?', [$courseId]);
        $this->ctx->audit->log('kurs.loeschen', 'warning', sprintf('%s (%s)', $course['name'], $course['school_year']));
        $this->flash('success', 'Der Kurs wurde gelöscht. Die Schüler:innen-Konten bleiben bestehen.');
        $this->redirect($this->ctx->url('/admin/kurse'));
    }

    /** POST /admin/kurse/{id}/mitglieder: Schüler:in hinzufügen (Formular oder JSON). */
    public function addMember(array $params): array|string
    {
        $this->requirePermission(P::KURSE_BEARBEITEN);
        $this->requireCsrf();
        $course = $this->loadEditable((int) $params['id']);
        $courseId = (int) $course['id'];
        $input = $this->jsonInput();
        $studentId = (int) ($input['user_id'] ?? 0);
        $json = $this->isApiRequest();

        $student = $this->ctx->db->fetchOne(
            "SELECT id, username, firstname, lastname FROM users WHERE id = ? AND role = 'student' AND is_active = 1",
            [$studentId],
        );
        if ($student === null) {
            if ($json) {
                return $this->jsonError('Diese Schüler:in gibt es nicht oder das Konto ist deaktiviert.', 404);
            }
            $this->flash('error', 'Diese Schüler:in gibt es nicht oder das Konto ist deaktiviert.');
            $this->redirect($this->ctx->url('/admin/kurse/' . $courseId));
        }

        $added = $this->service()->addMember($courseId, $studentId);
        if ($added) {
            $this->ctx->audit->log('kurs.mitglied_hinzufuegen', 'info', sprintf('%s zu %s', $student['username'], $course['name']));
        }
        if ($json) {
            return ['success' => true, 'added' => $added, 'name' => display_name($student)];
        }
        $this->flash($added ? 'success' : 'info', $added
            ? display_name($student) . ' wurde hinzugefügt.'
            : display_name($student) . ' ist schon im Kurs.');
        $this->redirect($this->ctx->url('/admin/kurse/' . $courseId));
    }

    /** POST /admin/kurse/{id}/mitglieder/{userId}/entfernen */
    public function removeMember(array $params): string
    {
        $this->requirePermission(P::KURSE_BEARBEITEN);
        $this->requireCsrf();
        $course = $this->loadEditable((int) $params['id']);
        $courseId = (int) $course['id'];
        $studentId = (int) $params['userId'];

        $student = $this->ctx->db->fetchOne('SELECT username, firstname, lastname FROM users WHERE id = ?', [$studentId]);
        $this->service()->removeMember($courseId, $studentId);
        if ($student !== null) {
            $this->ctx->audit->log('kurs.mitglied_entfernen', 'info', sprintf('%s aus %s', $student['username'], $course['name']));
            $this->flash('success', display_name($student) . ' wurde aus dem Kurs entfernt.');
        }
        $this->redirect($this->ctx->url('/admin/kurse/' . $courseId));
    }

    // ---------- Helfer ----------

    private function service(): CourseService
    {
        return new CourseService($this->ctx->db, $this->ctx->auth);
    }

    /** @return array<string, mixed> */
    private function loadViewable(int $id): array
    {
        $course = $this->service()->find($id);
        if ($course === null) {
            throw new HttpException(404, 'Kurs nicht gefunden.');
        }
        if (!$this->service()->canView($id)) {
            throw new HttpException(403);
        }

        return $course;
    }

    /** @return array<string, mixed> */
    private function loadEditable(int $id): array
    {
        $course = $this->service()->find($id);
        if ($course === null) {
            throw new HttpException(404, 'Kurs nicht gefunden.');
        }
        if (!$this->service()->canEdit($id)) {
            throw new HttpException(403);
        }

        return $course;
    }

    /**
     * @param array<string, mixed>|null $course
     * @param array<string, mixed> $values
     */
    private function renderForm(?array $course, array $values, string $title): string
    {
        $staff = $this->ctx->db->fetchAll(
            "SELECT id, username, firstname, lastname, role FROM users
             WHERE role IN ('teacher','admin') AND is_active = 1 ORDER BY lastname, firstname, username",
        );

        return $this->render('pages/courses/form', [
            'title' => $title,
            'course' => $course,
            'values' => $values,
            'isNew' => $course === null,
            'staff' => $staff,
            'scales' => (new GradingScales($this->ctx->db))->all(),
            'canChooseOwner' => $this->service()->seesAllCourses(),
            'levels' => range(CourseService::MIN_GRADE, CourseService::MAX_GRADE),
            'pageStyles' => ['admin.css'],
        ]);
    }

    /**
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function readForm(?array $existing): array
    {
        $service = $this->service();
        $myId = (int) $this->ctx->auth->id();

        // Hauptlehrkraft: frei wählbar nur mit Recht auf alle Kurse, sonst bleibt sie wie sie ist (neu: man selbst).
        if ($service->seesAllCourses()) {
            $ownerId = (int) ($_POST['owner_id'] ?? 0);
            $ownerId = $ownerId > 0 ? $ownerId : null;
        } else {
            $ownerId = $existing !== null ? ($existing['owner_id'] !== null ? (int) $existing['owner_id'] : null) : $myId;
        }

        $teacherIds = [];
        foreach (is_array($_POST['teacher_ids'] ?? null) ? $_POST['teacher_ids'] : [] as $id) {
            if ((int) $id > 0) {
                $teacherIds[] = (int) $id;
            }
        }
        // Wer nicht alle Kurse bearbeiten darf, darf sich nicht selbst aus dem Kurs herausnehmen.
        if (!$service->seesAllCourses() && $existing !== null && $ownerId !== $myId) {
            $teacherIds[] = $myId;
        }

        $scaleId = (int) ($_POST['grading_scale_id'] ?? 0);

        return [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'grade_level' => (int) ($_POST['grade_level'] ?? 0),
            'school_year' => trim((string) ($_POST['school_year'] ?? '')),
            'subject' => trim((string) ($_POST['subject'] ?? '')) ?: 'Informatik',
            'owner_id' => $ownerId,
            'grading_scale_id' => $scaleId > 0 ? $scaleId : null,
            'teacher_ids' => array_values(array_unique($teacherIds)),
            'is_archived' => ($_POST['is_archived'] ?? '') === '1' ? 1 : 0,
        ];
    }

    /** @param array<string, mixed> $data */
    private function validate(array $data, ?int $exceptId): ?string
    {
        $error = CourseService::validateFields($data['name'], $data['grade_level'], $data['school_year'], $data['subject']);
        if ($error !== null) {
            return $error;
        }
        if ($this->service()->nameTaken($data['name'], $data['school_year'], $exceptId)) {
            return 'Im Schuljahr ' . $data['school_year'] . ' gibt es schon einen Kurs „' . $data['name'] . '“.';
        }
        if ($data['owner_id'] !== null) {
            $owner = $this->ctx->db->fetchValue(
                "SELECT 1 FROM users WHERE id = ? AND role IN ('teacher','admin') AND is_active = 1",
                [$data['owner_id']],
            );
            if ($owner === null) {
                return 'Die Hauptlehrkraft muss eine aktive Lehrkraft oder ein Admin sein.';
            }
        }
        if ($data['grading_scale_id'] !== null
            && $this->ctx->db->fetchValue('SELECT 1 FROM grading_scales WHERE id = ?', [$data['grading_scale_id']]) === null) {
            return 'Der gewählte Notenschlüssel existiert nicht.';
        }

        return null;
    }
}
