<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ExamAccess;
use App\Services\SebConfig;
use App\Services\SebEncryption;

/** SEB-Konfigurationsdatei (.seb) für eine Klausur erzeugen. */
final class SebController extends Controller
{
    /** GET /klausuren/{id}/seb */
    public function form(array $params): string
    {
        $this->requireStaff();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);

        return $this->render('pages/exams/seb', [
            'title' => 'SEB-Datei: ' . $exam['title'],
            'exam' => $exam,
            'publicBase' => $this->ctx->publicBase(),
            'baseGuessed' => $this->ctx->baseIsGuessed(),
            'pageStyles' => ['exams.css'],
        ]);
    }

    /** POST /klausuren/{id}/seb */
    public function download(array $params): string
    {
        $this->requireStaff();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $exam = $this->ctx->exams->require($id, ExamAccess::CAP_EDIT);
        $in = $this->jsonInput();
        $password = (string) ($in['quit_password'] ?? '');
        $manualKey = strtolower(trim((string) ($in['manual_key'] ?? '')));
        $bek = strtolower(trim((string) ($in['browser_exam_key'] ?? '')));
        $settingsPassword = (string) ($in['settings_password'] ?? '');

        if (mb_strlen($password) > 100 || mb_strlen($settingsPassword) > 100) {
            $this->flash('error', 'Das Passwort ist zu lang (höchstens 100 Zeichen).');
            $this->redirect($this->ctx->url('/klausuren/' . $id . '/seb'));
        }
        if ($manualKey !== '' && !SebConfig::isValidKey($manualKey)) {
            $this->flash('error', 'Der Config Key muss aus 64 Hexadezimalzeichen bestehen.');
            $this->redirect($this->ctx->url('/klausuren/' . $id . '/seb'));
        }
        if ($bek !== '' && !SebConfig::isValidKey($bek)) {
            $this->flash('error', 'Der Browser Exam Key muss aus 64 Hexadezimalzeichen bestehen.');
            $this->redirect($this->ctx->url('/klausuren/' . $id . '/seb'));
        }

        $built = SebConfig::build($exam, $this->ctx->publicBase(), $password);
        $key = $manualKey !== '' ? $manualKey : $built['config_key'];
        $this->ctx->db->run(
            'UPDATE exams SET seb_config_key = ?, seb_quit_password_hash = ?, seb_browser_exam_key = ? WHERE id = ?',
            [$key, $password !== '' ? hash('sha256', $password) : null, $bek !== '' ? $bek : null, $id],
        );
        $this->ctx->exams->forget($id);
        $this->ctx->audit->log('seb_datei', 'info', 'SEB-Datei für Klausur #' . $id . ' erzeugt'
            . ($bek !== '' ? ' (mit Browser Exam Key' : ' (ohne Browser Exam Key')
            . ($settingsPassword !== '' ? ', verschlüsselt).' : ').'));
        $body = $settingsPassword !== '' ? SebEncryption::encrypt($built['xml'], $settingsPassword) : $built['xml'];

        $name = 'klausur-' . $id . '.seb';
        header('Content-Type: application/x-seb');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo $body;
        exit;
    }
}
