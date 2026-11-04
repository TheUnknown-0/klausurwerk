<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\ExamAccess;
use App\Services\ExamService;
use App\Services\ExamStatistics;
use App\Services\Exports;
use App\Services\GradingScales;
use App\Services\GradingService;

/**
 * Ergebnisse: Summen und Noten je Person, Kommentar, Abschluss, Klausurspiegel, Export und der
 * Statusfluss Freigeben, Zurücknehmen, Archivieren.
 *
 * Ansehen, kommentieren und abschließen braucht "grade"; Freigeben, Zurücknehmen, Archivieren,
 * Notenschlüssel und die Einstellung zum Erwartungshorizont brauchen "manage" (Hauptlehrkraft, Admin).
 */
final class ResultsController extends Controller
{
    private ?GradingService $grading = null;

    private function svc(): GradingService
    {
        return $this->grading ??= new GradingService($this->ctx->db, new GradingScales($this->ctx->db));
    }

    private function resultsUrl(int $examId): string
    {
        return $this->ctx->url('/klausuren/' . $examId . '/ergebnisse');
    }

    /** GET /klausuren/{id}/ergebnisse */
    public function index(array $params): string
    {
        $this->requireStaff();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_GRADE);
        $examId = (int) $exam['id'];
        $status = (string) $exam['status'];
        if (!in_array($status, ['grading', 'returned', 'archived'], true)) {
            $this->flash('info', $status === 'finished'
                ? 'Beginne zuerst die Korrektur, dann entstehen Ergebnisse.'
                : 'Ergebnisse gibt es erst nach dem Ende der Klausur.');
            $this->redirect($this->ctx->url($status === 'finished' ? '/klausuren/' . $examId . '/korrektur' : '/klausuren/' . $examId));
        }

        $rows = $this->svc()->results($exam);
        $stats = ExamStatistics::forExam($this->svc(), $exam);
        $scale = $this->svc()->scaleFor($exam);
        $finalized = count(array_filter($rows, static fn (array $r): bool => $r['finalized_at'] !== null));

        return $this->render('pages/results/index', [
            'title' => 'Ergebnisse: ' . (string) $exam['title'],
            'exam' => $exam,
            'caps' => $this->ctx->exams->capabilities($examId),
            'statusLabel' => ExamService::STATUS_LABELS[$status] ?? $status,
            'rows' => $rows,
            'stats' => $stats,
            'scale' => $scale,
            'scales' => $this->svc()->allScales(),
            'finalized' => $finalized,
            'boot' => [
                'exam_id' => $examId,
                'status' => $status,
                'person_api' => '/api/ergebnisse/' . $examId . '/person/',
            ],
            'bundles' => ['grading.js'],
            'pageStyles' => ['grading.css'],
            'wideContent' => true,
        ]);
    }

    /** POST /api/ergebnisse/{id}/person/{pid}  {general_comment?: string, finalized?: bool} */
    public function person(array $params): array
    {
        $user = $this->requireStaff();
        $this->requireCsrf();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_GRADE);
        $this->ctx->session->release();
        $pid = (int) $params['pid'];
        $in = $this->jsonInput();

        if (array_key_exists('general_comment', $in)) {
            $this->svc()->saveGeneralComment($exam, $pid, (string) $in['general_comment']);
        }
        if (array_key_exists('finalized', $in)) {
            $this->svc()->setFinalized($exam, $pid, filter_var($in['finalized'], FILTER_VALIDATE_BOOLEAN), (int) $user['id']);
        }
        foreach ($this->svc()->results($exam) as $row) {
            if ((int) $row['id'] === $pid) {
                return [
                    'success' => true,
                    'finalized' => $row['finalized_at'] !== null,
                    'total' => $row['total'],
                    'max' => $row['max'],
                    'percent' => $row['percent'],
                    'grade_label' => $row['grade_label'],
                ];
            }
        }
        throw new HttpException(404, 'Teilnahme nicht gefunden.');
    }

    /** POST /klausuren/{id}/ergebnisse/alle-abschliessen */
    public function finalizeAll(array $params): never
    {
        $user = $this->requireStaff();
        $this->requireCsrf();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_GRADE);
        try {
            $r = $this->svc()->finalizeComplete($exam, (int) $user['id']);
            $this->flash('success', $r['finalized'] . ' Personen abgeschlossen.'
                . ($r['skipped'] > 0 ? ' ' . $r['skipped'] . ' haben noch unbewertete Aufgaben und bleiben offen.' : ''));
        } catch (HttpException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect($this->resultsUrl((int) $exam['id']));
    }

    /** POST /klausuren/{id}/ergebnisse/einstellungen  {grading_scale_id, show_expectation} */
    public function saveSettings(array $params): never
    {
        $this->requireStaff();
        $this->requireCsrf();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_MANAGE);
        $examId = (int) $exam['id'];
        $in = $this->jsonInput();
        try {
            $scaleRaw = (string) ($in['grading_scale_id'] ?? '');
            $newScale = $scaleRaw === '' ? null : (int) $scaleRaw;
            $oldScale = $exam['grading_scale_id'] !== null ? (int) $exam['grading_scale_id'] : null;
            if ($newScale !== $oldScale) {
                $this->svc()->assertGrading($exam);
                $exam = $this->svc()->changeScale($exam, $newScale);
            }
            $show = !empty($in['show_expectation']) ? 1 : 0;
            $this->ctx->db->run('UPDATE exams SET show_expectation_on_return = ? WHERE id = ?', [$show, $examId]);
            $this->ctx->exams->forget($examId);
            $this->ctx->audit->log('ergebnis_einstellungen', 'info', 'Ergebnis-Einstellungen der Klausur #' . $examId . ' geändert.');
            $this->flash('success', 'Die Einstellungen wurden gespeichert.');
        } catch (HttpException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect($this->resultsUrl($examId));
    }

    /** POST /klausuren/{id}/freigeben */
    public function release(array $params): never
    {
        $this->requireStaff();
        $this->requireCsrf();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_MANAGE);
        $examId = (int) $exam['id'];
        try {
            $r = $this->svc()->release($exam);
            $this->ctx->exams->forget($examId);
            $this->ctx->audit->log('ergebnisse_freigegeben', 'warning', 'Ergebnisse der Klausur #' . $examId . ' (' . $exam['title'] . ') freigegeben, ' . $r['finalized'] . ' abgeschlossen, ' . $r['open'] . ' offen.');
            $this->flash('success', 'Die Ergebnisse sind freigegeben. Schüler:innen sehen ihr Ergebnis, sobald ihre Korrektur abgeschlossen ist.'
                . ($r['open'] > 0 ? ' Noch offen: ' . $r['open'] . ' Personen (sie sehen noch nichts).' : ''));
        } catch (HttpException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect($this->resultsUrl($examId));
    }

    /** POST /klausuren/{id}/zuruecknehmen */
    public function withdraw(array $params): never
    {
        $this->requireStaff();
        $this->requireCsrf();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_MANAGE);
        $examId = (int) $exam['id'];
        try {
            $this->svc()->withdraw($exam);
            $this->ctx->exams->forget($examId);
            $this->ctx->audit->log('freigabe_zurueckgenommen', 'warning', 'Freigabe der Klausur #' . $examId . ' (' . $exam['title'] . ') zurückgenommen.');
            $this->flash('success', 'Die Freigabe ist zurückgenommen. Die Korrektur ist wieder offen.');
        } catch (HttpException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect($this->resultsUrl($examId));
    }

    /** POST /klausuren/{id}/archivieren */
    public function archive(array $params): never
    {
        $this->requireStaff();
        $this->requireCsrf();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_MANAGE);
        $examId = (int) $exam['id'];
        try {
            $this->svc()->archive($exam);
            $this->ctx->exams->forget($examId);
            $this->ctx->audit->log('klausur_archiviert', 'info', 'Klausur #' . $examId . ' (' . $exam['title'] . ') archiviert.');
            $this->flash('success', 'Die Klausur ist archiviert.');
        } catch (HttpException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect($this->resultsUrl($examId));
    }

    /** GET /klausuren/{id}/ergebnisse/export?format=csv|xlsx */
    public function export(array $params): never
    {
        $this->requireStaff();
        $exam = $this->ctx->exams->require((int) $params['id'], ExamAccess::CAP_GRADE);
        $examId = (int) $exam['id'];
        if (!in_array((string) $exam['status'], ['grading', 'returned', 'archived'], true)) {
            throw new HttpException(409, 'Ergebnisse gibt es erst, wenn die Korrektur begonnen hat.');
        }
        [$header, $data] = $this->exportTable($exam);
        $this->ctx->audit->log('ergebnisse_exportiert', 'info', 'Ergebnisse der Klausur #' . $examId . ' exportiert.');
        Exports::deliver((string) ($_GET['format'] ?? 'csv'), $header, $data, 'ergebnisse-' . (string) $exam['title']);
    }

    /**
     * Kopfzeile und Zeilen der Export-Tabelle.
     *
     * @param array<string, mixed> $exam
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function exportTable(array $exam): array
    {
        $examId = (int) $exam['id'];
        $ownerId = $exam['owner_id'] !== null ? (int) $exam['owner_id'] : null;
        $tasks = $this->svc()->leafTasks($examId);
        $entries = $this->svc()->entriesByCell($examId);
        $header = ['Nachname', 'Vorname', 'Benutzername', 'Abgabe'];
        foreach ($tasks as $t) {
            $header[] = $t['number'] . ' (' . format_points($t['points']) . ' P.)';
        }
        array_push($header, 'Summe', 'Maximum', 'Prozent', 'Note', 'Abgeschlossen');

        $rows = [];
        foreach ($this->svc()->results($exam) as $r) {
            $line = [
                (string) $r['lastname'],
                (string) $r['firstname'],
                (string) $r['username'],
                (string) $r['state'] === 'submitted' ? 'ja' : 'nein',
            ];
            foreach ($tasks as $t) {
                $final = GradingService::finalEntry($entries[(int) $r['id']][$t['id']] ?? [], $ownerId);
                $line[] = $final['points'] === null ? '' : format_points($final['points']);
            }
            $line[] = format_points($r['total']);
            $line[] = format_points($r['max']);
            $line[] = $r['percent'] === null ? '' : str_replace('.', ',', (string) round((float) $r['percent'], 1));
            $line[] = (string) ($r['grade_label'] ?? '');
            $line[] = $r['finalized_at'] !== null ? 'ja' : 'nein';
            $rows[] = $line;
        }

        return [$header, $rows];
    }
}
