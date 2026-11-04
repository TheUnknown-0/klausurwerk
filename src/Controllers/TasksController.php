<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\ExamAccess;
use App\Services\ExamKit;
use App\Services\ExamSettings;
use App\Services\TaskService;
use App\Services\TaskTypes\TaskTypeRegistry;
use App\Services\TaskValidationException;

/**
 * Aufgaben-Editor (Seite + JSON-API). Aufgaben und Lösungen werden hier nur mit CAP_EDIT
 * gelesen und geschrieben (Hauptlehrkraft, Admin, ALLE_KLAUSUREN), die Lösungen gehören
 * dazu.
 */
final class TasksController extends Controller
{
    private function service(): TaskService
    {
        return ExamKit::tasks($this->ctx);
    }

    /** GET /klausuren/{id}/aufgaben */
    public function editor(array $params): string
    {
        $this->requireStaff();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);

        $types = [];
        foreach (TaskTypeRegistry::all() as $name => $type) {
            $types[] = [
                'name' => $name,
                'label' => $type->label(),
                'group' => $type->group(),
                'defaultConfig' => $type->defaultConfig() ?: new \stdClass(),
                'defaultSolution' => $type->defaultSolution() ?: new \stdClass(),
            ];
        }
        $operators = $this->ctx->db->fetchAll('SELECT id, name, afb FROM operators ORDER BY sort_order, name');
        $materials = array_map(static fn (array $m): array => [
            'id' => (int) $m['id'],
            'name' => $m['original_name'],
            'mime' => $m['mime'],
            'task_id' => $m['task_id'] !== null ? (int) $m['task_id'] : null,
        ], ExamKit::materials($this->ctx)->forExam($id));

        $boot = [
            'exam_id' => $id,
            'exam' => [
                'title' => $exam['title'],
                'status' => $exam['status'],
                'locked' => TaskService::isLocked($exam),
                'mode' => $exam['mode'],
            ],
            'settings' => ExamSettings::normalize(ExamAccess::settingsOf($exam), (string) $exam['mode']),
            'assets' => [
                'pyodide' => $this->ctx->url('/assets/vendor/pyodide/'),
                'sqljs' => $this->ctx->url('/assets/vendor/sqljs/'),
                'aids' => $this->ctx->url('/assets/aids/'),
            ],
            'tasks' => $this->service()->fullList($id),
            'types' => $types,
            'operators' => $operators,
            'afb' => TaskService::AFB_VALUES,
            'materials' => $materials,
            'urls' => [
                // Ohne BASE_URL: frontend/src/lib/api.js setzt sie selbst davor.
                'api' => '/api/klausuren/' . $id . '/aufgaben',
                'preview' => '/api/markdown/vorschau',
                'materials' => $this->ctx->url('/klausuren/' . $id . '/material'),
                'exam' => $this->ctx->url('/klausuren/' . $id),
            ],
        ];

        return $this->render('pages/tasks/editor', [
            'title' => 'Aufgaben: ' . $exam['title'],
            'exam' => $exam,
            'boot' => $boot,
            'wideContent' => true,
            'pageStyles' => ['exams.css', 'diagrams.css'],
            'bundles' => ['task-editor.js'],
        ]);
    }

    /**
     * Fehler der Fachlogik in JSON-Fehlerantworten übersetzen.
     *
     * @param callable():array<string, mixed> $fn
     * @return array<string, mixed>
     */
    private function guarded(callable $fn): array
    {
        try {
            return $fn();
        } catch (TaskValidationException $e) {
            $out = $this->jsonError($e->getMessage(), 422);
            $out['field'] = $e->field;

            return $out;
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage(), 422);
        } catch (HttpException $e) {
            return $this->jsonError($e->getMessage(), $e->status);
        }
    }

    /** @return array<string, mixed> */
    private function refresh(int $examId, ?int $taskId = null): array
    {
        $tasks = $this->service()->fullList($examId);
        $out = ['success' => true, 'tasks' => $tasks];
        if ($taskId !== null) {
            foreach ($tasks as $t) {
                if ((int) $t['id'] === $taskId) {
                    $out['task'] = $t;
                }
            }
        }

        return $out;
    }

    /** GET /api/klausuren/{id}/aufgaben */
    public function list(array $params): array
    {
        $this->requireStaff();
        $id = (int) $params['id'];
        $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);

        return $this->refresh($id);
    }

    /** POST /api/klausuren/{id}/aufgaben */
    public function create(array $params): array
    {
        $this->requireStaff();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);
        $in = $this->jsonInput();

        return $this->guarded(function () use ($exam, $id, $in): array {
            $parent = ($in['parent_id'] ?? null) === null || $in['parent_id'] === '' ? null : (int) $in['parent_id'];
            $type = isset($in['answer_type']) && is_string($in['answer_type']) && $in['answer_type'] !== '' ? $in['answer_type'] : null;
            $taskId = $this->service()->create($exam, $parent, $type, mb_substr((string) ($in['title'] ?? ''), 0, 200));

            return $this->refresh($id, $taskId) + ['task_id' => $taskId];
        });
    }

    /** GET /api/klausuren/{id}/aufgaben/{tid} */
    public function show(array $params): array
    {
        $this->requireStaff();
        $id = (int) $params['id'];
        $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);
        $task = $this->service()->find($id, (int) $params['tid']);
        if ($task === null) {
            return $this->jsonError('Aufgabe nicht gefunden.', 404);
        }

        return ['success' => true, 'task' => $task];
    }

    /** POST /api/klausuren/{id}/aufgaben/{tid} */
    public function save(array $params): array
    {
        $this->requireStaff();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);
        $in = $this->jsonInput();
        $taskId = (int) $params['tid'];

        return $this->guarded(function () use ($exam, $id, $taskId, $in): array {
            $this->service()->save($exam, $taskId, $in);

            return $this->refresh($id, $taskId);
        });
    }

    /** POST /api/klausuren/{id}/aufgaben/{tid}/loeschen */
    public function delete(array $params): array
    {
        $this->requireStaff();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);
        $taskId = (int) $params['tid'];

        return $this->guarded(function () use ($exam, $id, $taskId): array {
            $this->service()->delete($exam, $taskId);
            $this->ctx->audit->log('aufgabe_geloescht', 'info', 'Aufgabe #' . $taskId . ' der Klausur #' . $id . ' gelöscht.');

            return $this->refresh($id);
        });
    }

    /** POST /api/klausuren/{id}/aufgaben/{tid}/verschieben */
    public function move(array $params): array
    {
        $this->requireStaff();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);
        $taskId = (int) $params['tid'];
        $direction = (string) ($this->jsonInput()['direction'] ?? '');

        return $this->guarded(function () use ($exam, $id, $taskId, $direction): array {
            $this->service()->move($exam, $taskId, $direction);

            return $this->refresh($id, $taskId);
        });
    }
}
