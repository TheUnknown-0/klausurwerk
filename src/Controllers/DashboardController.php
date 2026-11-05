<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions as P;
use App\Services\CourseService;
use App\Services\Retention;
use App\Services\RunnerClient;

/**
 * Startseite für Lehrkräfte und Admins: eigene Klausuren nach Zustand, eigene Kurse,
 * für Admins Hinweise auf fehlende Einrichtung und fällige Aufbewahrung.
 */
final class DashboardController extends Controller
{
    private const LIVE = ['lobby', 'running', 'paused'];
    private const PLANNED = ['draft', 'ready'];
    private const GRADING = ['finished', 'grading'];

    /** GET /dashboard */
    public function index(array $params): string
    {
        $user = $this->requireStaff();
        $userId = (int) $user['id'];

        $exams = $this->ctx->db->fetchAll(
            "SELECT e.id, e.title, e.status, e.mode, e.scheduled_at, e.started_at, e.owner_id,
                    c.name AS course_name,
                    (SELECT COUNT(*) FROM participations p WHERE p.exam_id = e.id) AS participants,
                    (SELECT COUNT(*) FROM participations p WHERE p.exam_id = e.id AND p.state = 'submitted') AS submitted
             FROM exams e
             LEFT JOIN courses c ON c.id = e.course_id
             WHERE (e.owner_id = ? OR e.id IN (SELECT exam_id FROM exam_staff WHERE user_id = ?))
               AND e.status IN ('lobby','running','paused','draft','ready','finished','grading')
             ORDER BY COALESCE(e.scheduled_at, e.created_at) DESC, e.id DESC",
            [$userId, $userId],
        );

        $live = $planned = $grading = [];
        foreach ($exams as $exam) {
            $exam['is_owner'] = (int) ($exam['owner_id'] ?? 0) === $userId;
            if (in_array($exam['status'], self::LIVE, true)) {
                $live[] = $exam;
            } elseif (in_array($exam['status'], self::PLANNED, true)) {
                $planned[] = $exam;
            } elseif (in_array($exam['status'], self::GRADING, true)) {
                $grading[] = $exam;
            }
        }

        $courses = new CourseService($this->ctx->db, $this->ctx->auth);
        $ownIds = $courses->ownCourseIds($userId);
        $myCourses = [];
        if ($ownIds !== []) {
            $myCourses = $this->ctx->db->fetchAll(
                'SELECT c.id, c.name, c.school_year, c.grade_level,
                        (SELECT COUNT(*) FROM course_members cm WHERE cm.course_id = c.id) AS member_count
                 FROM courses c WHERE c.is_archived = 0 AND c.id IN (' . implode(',', array_fill(0, count($ownIds), '?')) . ')
                 ORDER BY c.school_year DESC, c.grade_level, c.name',
                $ownIds,
            );
        }

        return $this->render('pages/dashboard/index', [
            'title' => 'Übersicht',
            'user' => $user,
            'live' => $live,
            'planned' => $planned,
            'grading' => $grading,
            'myCourses' => $myCourses,
            'canCreateExam' => $this->ctx->auth->can(P::KLAUSUREN_ERSTELLEN),
            'canSeeCourses' => $this->ctx->auth->can(P::KURSE_SEHEN),
            'hints' => $this->ctx->auth->isAdmin() ? $this->adminHints() : [],
            'pageStyles' => ['admin.css'],
        ]);
    }

    /**
     * Hinweise für Admins: [Stufe, Text, Link, Linktext].
     *
     * @return list<array{level: string, text: string, url: ?string, link: ?string}>
     */
    private function adminHints(): array
    {
        $hints = [];
        $config = $this->ctx->config;

        if ((string) ($config['mail']['host'] ?? '') === '' || (string) ($config['mail']['from_address'] ?? '') === '') {
            $hints[] = [
                'level' => 'warning',
                'text' => 'Der Mailserver ist nicht eingerichtet (MAIL_HOST und MAIL_FROM_ADDRESS). Lehrkräfte melden sich ohne Anmeldecode an.',
                'url' => $this->ctx->url('/admin/einstellungen'),
                'link' => 'Einstellungen',
            ];
        }
        if ((string) ($config['runner']['token'] ?? '') === '') {
            $hints[] = [
                'level' => 'error',
                'text' => 'RUNNER_TOKEN ist leer. Ohne dieses Geheimnis kann die App keinen Code im Runner ausführen.',
                'url' => null,
                'link' => null,
            ];
        } elseif (!(new RunnerClient((array) ($config['runner'] ?? [])))->health()) {
            // Kurzer Verbindungstest (3 s), damit das vor der Klausur auffällt und nicht erst beim ersten "Ausführen".
            $hints[] = [
                'level' => 'error',
                'text' => 'Der Runner (Java-Ausführung und automatische Tests) ist nicht erreichbar. Container "runner" und RUNNER_URL prüfen.',
                'url' => null,
                'link' => null,
            ];
        }
        if ($this->ctx->baseIsGuessed()) {
            $hints[] = [
                'level' => 'info',
                'text' => 'Die öffentliche Adresse ist nicht hinterlegt. Der QR-Code auf den Zugangskarten nutzt die Adresse dieses Aufrufs.',
                'url' => $this->ctx->url('/admin/einstellungen'),
                'link' => 'Adresse eintragen',
            ];
        }

        $due = (new Retention($this->ctx->db, $this->ctx->settings))->countDue();
        if ($due > 0) {
            $hints[] = [
                'level' => 'warning',
                'text' => sprintf('%d Klausur(en) haben die Aufbewahrungsfrist überschritten.', $due),
                'url' => $this->ctx->url('/admin/aufbewahrung'),
                'link' => 'Aufbewahrung öffnen',
            ];
        }

        return $hints;
    }
}
