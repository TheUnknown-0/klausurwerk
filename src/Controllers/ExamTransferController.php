<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions as P;
use App\Services\ExamAccess;
use App\Services\ExamKit;

/** Export einer Klausur als ZIP und Import als neuer Entwurf. */
final class ExamTransferController extends Controller
{
    /** GET /klausuren/{id}/export */
    public function export(array $params): never
    {
        $this->requirePermission(P::KLAUSUREN_IMPORTIEREN);
        $id = (int) $params['id'];
        // Der Export enthält Musterlösungen und Tests.
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_SOLUTIONS);

        $path = ExamKit::export($this->ctx)->exportToFile($exam);
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', (string) $exam['title']) ?: 'klausur'), '-');
        $name = 'klausur-' . ($slug !== '' ? $slug : $id) . '-' . date('Y-m-d') . '.zip';

        $this->ctx->audit->log('klausur_exportiert', 'info', 'Klausur #' . $id . ' exportiert.');
        header('Content-Type: application/zip');
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        @unlink($path);
        exit;
    }

    /** GET /klausuren/import */
    public function form(array $params): string
    {
        $this->requirePermission(P::KLAUSUREN_IMPORTIEREN);

        return $this->render('pages/exams/import', [
            'title' => 'Klausur importieren',
            'pageStyles' => ['exams.css'],
        ]);
    }

    /** POST /klausuren/import */
    public function import(array $params): string
    {
        $this->requirePermission(P::KLAUSUREN_IMPORTIEREN);
        $this->requireCsrf();

        $file = $_FILES['datei'] ?? null;
        $error = null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'Bitte wähle eine ZIP-Datei aus (Upload fehlgeschlagen oder zu groß).';
        } elseif ((int) $file['size'] > 200 * 1024 * 1024) {
            $error = 'Die Datei ist zu groß (höchstens 200 MB).';
        } else {
            try {
                $id = ExamKit::export($this->ctx)->importFromFile((string) $file['tmp_name'], (int) $this->ctx->auth->id());
                $this->ctx->audit->log('klausur_importiert', 'info', 'Klausur #' . $id . ' importiert.');
                $this->flash('success', 'Die Klausur wurde als Entwurf importiert. Bitte Kurs, Raum und Termin prüfen.');
                $this->redirect($this->ctx->url('/klausuren/' . $id . '/einstellungen'));
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            } catch (\Throwable $e) {
                error_log('[Klausurwerk] Import fehlgeschlagen: ' . $e->getMessage());
                $error = 'Der Import ist fehlgeschlagen. Die Datei ist unvollständig oder nicht lesbar.';
            }
        }
        $this->flash('error', $error ?? 'Unbekannter Fehler.');

        return $this->render('pages/exams/import', [
            'title' => 'Klausur importieren',
            'pageStyles' => ['exams.css'],
        ]);
    }
}
