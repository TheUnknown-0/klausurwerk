<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions as P;
use App\Services\Retention;

/**
 * Aufbewahrung: Fristen einstellen und überfällige Klausuren löschen (Protokolle oder komplett).
 *
 * Gelöscht wird nur nach doppelter Bestätigung (Häkchen und das Wort LÖSCHEN) und nur, was
 * serverseitig tatsächlich fällig ist. Jede Löschung steht als Warnung im Audit-Log.
 */
final class RetentionController extends Controller
{
    /** GET /admin/aufbewahrung */
    public function index(array $params): string
    {
        $this->requirePermission(P::AUFBEWAHRUNG_VERWALTEN);
        $retention = $this->retention();

        return $this->render('pages/retention/index', [
            'title' => 'Aufbewahrung',
            'answersMonths' => $retention->answersMonths(),
            'logsMonths' => $retention->logsMonths(),
            'due' => $retention->dueExams(),
            'min' => Retention::MIN_MONTHS,
            'max' => Retention::MAX_MONTHS,
            'canEdit' => $this->ctx->auth->can(P::EINSTELLUNGEN_BEARBEITEN) || $this->ctx->auth->isAdmin(),
            'pageStyles' => ['admin.css'],
        ]);
    }

    /** POST /admin/aufbewahrung/fristen */
    public function saveSettings(array $params): string
    {
        $this->requirePermission(P::AUFBEWAHRUNG_VERWALTEN);
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/aufbewahrung');

        $answers = $this->months($_POST['retention_answers_months'] ?? '');
        $logs = $this->months($_POST['retention_logs_months'] ?? '');
        if ($answers === null || $logs === null) {
            $this->flash('error', sprintf(
                'Die Fristen brauchen ganze Monate zwischen %d und %d.',
                Retention::MIN_MONTHS,
                Retention::MAX_MONTHS,
            ));
            $this->redirect($back);
        }

        $this->ctx->settings->set('retention_answers_months', (string) $answers);
        $this->ctx->settings->set('retention_logs_months', (string) $logs);
        $this->ctx->audit->log('aufbewahrung.fristen', 'info', sprintf('Antworten %d Monate, Protokolle %d Monate', $answers, $logs));
        $this->flash('success', 'Die Fristen wurden gespeichert.');
        $this->redirect($back);
    }

    /** POST /admin/aufbewahrung/protokolle: Ereignisse, Replay-Schritte und Anfragen einer Klausur löschen. */
    public function deleteLogs(array $params): string
    {
        $this->requirePermission(P::AUFBEWAHRUNG_VERWALTEN);
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/aufbewahrung');
        $retention = $this->retention();

        $due = $this->confirmedDue($retention, $back);
        if (!$due['logs_due']) {
            $this->flash('error', 'Für diese Klausur ist die Frist für Protokolle nicht überschritten oder es gibt keine Protokolle mehr.');
            $this->redirect($back);
        }

        $result = $retention->deleteLogs((int) $due['id']);
        $this->ctx->audit->log(
            'aufbewahrung.protokolle_loeschen',
            'warning',
            sprintf(
                'Klausur %d "%s": %d Ereignisse, %d Antwortschritte, %d Anfragen gelöscht',
                $due['id'],
                $due['title'],
                $result['events'],
                $result['ops'],
                $result['requests'],
            ),
        );
        $this->flash('success', sprintf(
            'Protokolle der Klausur „%s“ gelöscht (%d Ereignisse, %d Antwortschritte, %d Anfragen).',
            $due['title'],
            $result['events'],
            $result['ops'],
            $result['requests'],
        ));
        $this->redirect($back);
    }

    /** POST /admin/aufbewahrung/klausur-loeschen: Klausur samt Antworten, Noten und Dateien löschen. */
    public function deleteExam(array $params): string
    {
        $this->requirePermission(P::AUFBEWAHRUNG_VERWALTEN);
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/aufbewahrung');
        $retention = $this->retention();

        $due = $this->confirmedDue($retention, $back);
        if (!$due['answers_due']) {
            $this->flash('error', 'Für diese Klausur ist die Frist für Antworten und Noten noch nicht überschritten.');
            $this->redirect($back);
        }

        $result = $retention->deleteExam((int) $due['id'], (string) $this->ctx->config['uploads']['dir']);
        $this->ctx->audit->log(
            'aufbewahrung.klausur_loeschen',
            'warning',
            sprintf(
                'Klausur %d "%s" vollständig gelöscht (%d Dateien entfernt, %d nicht gefunden)',
                $due['id'],
                $due['title'],
                $result['files'],
                $result['missing'],
            ),
        );
        $this->flash('success', sprintf('Die Klausur „%s“ wurde vollständig gelöscht (%d Dateien entfernt).', $due['title'], $result['files']));
        $this->redirect($back);
    }

    // ---------- Helfer ----------

    private function retention(): Retention
    {
        return new Retention($this->ctx->db, $this->ctx->settings);
    }

    /**
     * Prüft die doppelte Bestätigung und lädt die fällige Klausur; bricht sonst mit Hinweis ab.
     *
     * @return array<string, mixed>
     */
    private function confirmedDue(Retention $retention, string $back): array
    {
        $word = mb_strtoupper(trim((string) ($_POST['bestaetigung'] ?? '')));
        if (($_POST['bestaetigt'] ?? '') !== '1' || !in_array($word, ['LÖSCHEN', 'LOESCHEN'], true)) {
            $this->flash('error', 'Bitte beides bestätigen: Häkchen setzen und LÖSCHEN eintippen. Es wurde nichts gelöscht.');
            $this->redirect($back);
        }
        $due = $retention->findDue((int) ($_POST['exam_id'] ?? 0));
        if ($due === null) {
            $this->flash('error', 'Diese Klausur ist nicht (mehr) fällig. Es wurde nichts gelöscht.');
            $this->redirect($back);
        }

        return $due;
    }

    private function months(mixed $raw): ?int
    {
        $raw = trim((string) $raw);
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }
        $value = (int) $raw;

        return $value >= Retention::MIN_MONTHS && $value <= Retention::MAX_MONTHS ? $value : null;
    }
}
