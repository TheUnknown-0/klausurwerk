<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\ExamAccess;
use App\Services\ExamKit;
use App\Services\ExamService;

/**
 * Material einer Klausur (Bilder, PDF): Upload, Liste, Hilfsmittel-Schalter, Zuordnung, Löschen.
 * Die Auslieferung an Schüler:innen gehört zum Live-Modul (Materials::pathFor).
 */
final class ExamMaterialsController extends Controller
{
    private function back(int $examId): never
    {
        $this->redirect($this->ctx->url('/klausuren/' . $examId . '/material'));
    }

    /** @return array<string, mixed> */
    private function materialOf(int $examId, int $materialId): array
    {
        $row = ExamKit::materials($this->ctx)->find($materialId);
        if ($row === null || (int) $row['exam_id'] !== $examId) {
            throw new HttpException(404, 'Material nicht gefunden.');
        }

        return $row;
    }

    /** @param array<string, mixed> $exam */
    private function assertEditable(array $exam, int $examId): void
    {
        if (!ExamService::isEditable($exam)) {
            $this->flash('error', 'Die Klausur ist gestartet oder abgeschlossen: Material kann nicht mehr verändert werden.');
            $this->back($examId);
        }
    }

    /** GET /klausuren/{id}/material */
    public function index(array $params): string
    {
        $this->requireStaff();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);
        $tasks = ExamKit::tasks($this->ctx)->outline($id);

        $materials = ExamKit::materials($this->ctx)->forExam($id);
        // Verwendung im Text: Anzahl der Aufgaben, die material:ID erwähnen.
        $bodies = $this->ctx->db->fetchAll('SELECT body_md FROM tasks WHERE exam_id = ?', [$id]);
        foreach ($materials as &$m) {
            $needle = 'material:' . $m['id'];
            $m['used'] = count(array_filter($bodies, static fn (array $b): bool => preg_match('/' . preg_quote($needle, '/') . '(?!\d)/', (string) $b['body_md']) === 1));
        }
        unset($m);

        return $this->render('pages/exams/material', [
            'title' => 'Material: ' . $exam['title'],
            'exam' => $exam,
            'materials' => $materials,
            'tasks' => $tasks,
            'editable' => ExamService::isEditable($exam),
            'pageStyles' => ['exams.css'],
        ]);
    }

    /** POST /klausuren/{id}/material */
    public function upload(array $params): never
    {
        $this->requireStaff();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);
        $this->assertEditable($exam, $id);

        $file = $_FILES['datei'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->flash('error', 'Bitte wähle eine Datei aus.');
            $this->back($id);
        }
        $taskId = (int) ($_POST['task_id'] ?? 0);
        if ($taskId > 0 && $this->ctx->db->fetchValue('SELECT id FROM tasks WHERE id = ? AND exam_id = ?', [$taskId, $id]) === null) {
            $taskId = 0;
        }
        try {
            $row = ExamKit::materials($this->ctx)->store($id, $file, $taskId > 0 ? $taskId : null, !empty($_POST['is_aid']));
            $this->flash('success', 'Die Datei "' . $row['original_name'] . '" wurde hochgeladen. Einbinden im Text: ![Beschriftung](material:' . $row['id'] . ')');
        } catch (HttpException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->back($id);
    }

    /** POST /klausuren/{id}/material/{mid}/hilfsmittel */
    public function toggleAid(array $params): never
    {
        $this->requireStaff();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);
        $this->assertEditable($exam, $id);
        $row = $this->materialOf($id, (int) $params['mid']);
        $this->ctx->db->run('UPDATE exam_materials SET is_aid = ? WHERE id = ?', [(int) $row['is_aid'] === 1 ? 0 : 1, (int) $row['id']]);
        $this->back($id);
    }

    /** POST /klausuren/{id}/material/{mid}/zuordnung */
    public function assign(array $params): never
    {
        $this->requireStaff();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);
        $this->assertEditable($exam, $id);
        $row = $this->materialOf($id, (int) $params['mid']);
        $taskId = (int) ($this->jsonInput()['task_id'] ?? 0);
        if ($taskId > 0 && $this->ctx->db->fetchValue('SELECT id FROM tasks WHERE id = ? AND exam_id = ?', [$taskId, $id]) === null) {
            throw new HttpException(404, 'Aufgabe nicht gefunden.');
        }
        $this->ctx->db->run('UPDATE exam_materials SET task_id = ? WHERE id = ?', [$taskId > 0 ? $taskId : null, (int) $row['id']]);
        $this->flash('success', 'Die Zuordnung wurde gespeichert.');
        $this->back($id);
    }

    /** POST /klausuren/{id}/material/{mid}/loeschen */
    public function delete(array $params): never
    {
        $this->requireStaff();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);
        $this->assertEditable($exam, $id);
        $row = $this->materialOf($id, (int) $params['mid']);
        ExamKit::materials($this->ctx)->delete($row);
        $this->flash('success', 'Die Datei wurde gelöscht. Verweise im Aufgabentext zeigen jetzt ins Leere.');
        $this->back($id);
    }
}
