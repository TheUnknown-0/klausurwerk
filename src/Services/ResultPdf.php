<?php

declare(strict_types=1);

namespace App\Services;

require_once dirname(__DIR__, 2) . '/lib/fpdf/fpdf.php';

/**
 * Ergebnis-PDF je Person und Sammel-PDF (je Person neue Seite).
 *
 * Eingabe sind Berichte von ResultReport::build(). FPDF-Kernschriften können nur Windows-1252;
 * jede Textausgabe läuft deshalb durch t().
 *
 * Aufbau je Person: Kopf (Schule, Klausur, Kurs, Name, Datum, Punkte, Note), dann je Aufgabe Nummer,
 * Titel, erreichte und mögliche Punkte, die Antwort (Text, Code mit Zeilennummern in Monospace oder
 * ein Bild des Diagramms) und die Kommentare der Korrektur mit ihrer Stelle, am Ende der allgemeine Kommentar.
 */
final class ResultPdf extends \FPDF
{
    private const MARGIN = 15.0;
    private const CODE_SIZE = 8.0;
    private const CODE_LINE_H = 3.9;
    private const GUTTER_W = 9.0;
    private const MAX_CODE_LINES = 600;
    private const MAX_TEXT_CHARS = 12000;

    private string $footerText = '';
    private int $personStart = 1;

    public function __construct(private readonly string $schoolName = '')
    {
        parent::__construct('P', 'mm', 'A4');
        $this->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $this->SetAutoPageBreak(true, 18);
        $this->SetCreator('Klausurwerk');
        $this->SetAuthor($this->t($schoolName !== '' ? $schoolName : 'Klausurwerk'));
        $this->SetTitle($this->t('Klausurergebnis'));
    }

    /**
     * Baut das PDF aus einem oder mehreren Berichten und gibt es als Bytes zurück.
     *
     * @param list<array<string, mixed>> $reports
     */
    public function render(array $reports): string
    {
        if ($reports === []) {
            $this->AddPage();
            $this->SetFont('Helvetica', '', 11);
            $this->Cell(0, 8, $this->t('Keine Ergebnisse vorhanden.'));
        }
        foreach ($reports as $report) {
            $this->person($report);
        }

        return $this->Output('S');
    }

    /** UTF-8 nach Windows-1252 (mit Transliteration, Rückfall ohne iconv). */
    public function t(string $utf8): string
    {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT', $utf8);
        if ($converted === false) {
            $converted = mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');
        }
        // Steuerzeichen (außer Zeilenumbruch) stören FPDF nicht, wirken aber unschön.
        return (string) preg_replace('/[\x00-\x08\x0B-\x1F]/', '', (string) $converted);
    }

    /** Wie t(), kürzt aber auf die Zellbreite (mm). */
    public function fit(string $utf8, float $width): string
    {
        $text = $this->t($utf8);
        $max = $width - 1.0;
        if ($max <= 0 || $this->GetStringWidth($text) <= $max) {
            return $text;
        }
        while ($text !== '' && $this->GetStringWidth($text . '...') > $max) {
            $text = substr($text, 0, -1);
        }

        return $text . '...';
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(110, 114, 140);
        $page = $this->PageNo() - $this->personStart + 1;
        $this->Cell(0, 5, $this->fit($this->footerText, $this->w - 2 * self::MARGIN - 25), 0, 0, 'L');
        $this->SetX(-self::MARGIN - 25);
        $this->Cell(25, 5, $this->t('Seite ' . max(1, $page)), 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }

    // ---------- Person ----------

    /** @param array<string, mixed> $report */
    private function person(array $report): void
    {
        $this->AddPage();
        $exam = $report['exam'];
        $person = $report['person'];
        $summary = $report['summary'];
        $this->personStart = $this->PageNo();
        $this->footerText = $person['name'] . ' | ' . $exam['title'];
        $inner = $this->w - 2 * self::MARGIN;

        if ($this->schoolName !== '') {
            $this->SetFont('Helvetica', '', 9);
            $this->SetTextColor(95, 100, 125);
            $this->Cell($inner, 5, $this->fit($this->schoolName, $inner), 0, 1);
        }
        $this->SetTextColor(18, 20, 42);
        $this->SetFont('Helvetica', 'B', 16);
        $this->MultiCell($inner, 7.5, $this->t((string) $exam['title']));
        $this->SetFont('Helvetica', '', 10);
        $this->SetTextColor(61, 65, 99);
        $meta = [];
        if ($exam['course'] !== '') {
            $meta[] = 'Kurs: ' . $exam['course'];
        }
        if ($exam['date'] !== '') {
            $meta[] = 'Datum: ' . format_date($exam['date']);
        }
        if ($meta !== []) {
            $this->Cell($inner, 5.5, $this->fit(implode('    ', $meta), $inner), 0, 1);
        }
        $this->Ln(2);

        // Name und Ergebnis
        $this->SetFillColor(238, 240, 246);
        $this->SetTextColor(18, 20, 42);
        $this->SetFont('Helvetica', 'B', 13);
        $this->Cell($inner, 8, $this->fit((string) $person['name'], $inner - 4), 0, 1, 'L', true);
        $this->SetFont('Helvetica', '', 11);
        $points = format_points($summary['total']) . ' von ' . format_points($summary['max']) . ' Punkten';
        if ($summary['percent'] !== null) {
            $points .= ' (' . str_replace('.', ',', (string) round((float) $summary['percent'], 1)) . ' %)';
        }
        $grade = '';
        if ($summary['grade_label'] !== null && $summary['grade_label'] !== '') {
            $grade = ($summary['scale_type'] === 'points' ? 'Notenpunkte: ' : 'Note: ') . $summary['grade_label'];
        }
        $this->Cell($inner * 0.62, 7.5, $this->fit($points, $inner * 0.62), 0, 0, 'L', true);
        $this->SetFont('Helvetica', 'B', 12);
        $this->Cell($inner * 0.38, 7.5, $this->fit($grade, $inner * 0.38), 0, 1, 'R', true);
        $this->Ln(3);

        if (trim((string) $summary['general_comment']) !== '') {
            $this->SetFont('Helvetica', 'B', 10);
            $this->Cell($inner, 5.5, $this->t('Rückmeldung'), 0, 1);
            $this->SetFont('Helvetica', '', 10);
            $this->MultiCell($inner, 5, $this->t((string) $summary['general_comment']));
            $this->Ln(2);
        }

        foreach ($report['tasks'] as $task) {
            $this->task($task);
        }
    }

    /** @param array<string, mixed> $task */
    private function task(array $task): void
    {
        $inner = $this->w - 2 * self::MARGIN;
        $limit = $this->h - $this->bMargin;
        $heading = trim($task['number'] . '  ' . $task['title']);

        // Überschrift nie allein am Seitenende
        if ($this->GetY() + 28 > $limit) {
            $this->AddPage();
        }
        $this->Ln(2);

        if ($task['answer_type'] === null) {
            $this->SetFont('Helvetica', 'B', 11.5);
            $this->SetTextColor(55, 48, 163);
            $this->Cell($inner, 6.5, $this->fit($heading, $inner), 0, 1);
            $this->SetTextColor(0, 0, 0);

            return;
        }

        $this->SetFillColor(228, 230, 244);
        $this->SetTextColor(18, 20, 42);
        $this->SetFont('Helvetica', 'B', 10.5);
        $scoreW = 38.0;
        $this->Cell($inner - $scoreW, 6.5, $this->fit($heading, $inner - $scoreW - 2), 0, 0, 'L', true);
        $earned = $task['earned'] !== null ? format_points($task['earned']) : 'offen';
        $this->Cell($scoreW, 6.5, $this->t($earned . ' / ' . format_points($task['points']) . ' P.'), 0, 1, 'R', true);
        $this->SetTextColor(0, 0, 0);
        if (!empty($task['overtime'])) {
            $this->SetFont('Helvetica', 'I', 8.5);
            $this->SetTextColor(180, 83, 9);
            $this->Cell($inner, 4.5, $this->t('Hinweis: Änderungen nach Ablauf der Bearbeitungszeit.'), 0, 1);
            $this->SetTextColor(0, 0, 0);
        }
        $this->Ln(1);

        $this->answer($task);
        $this->comments($task);
        $this->Ln(2);
    }

    /** @param array<string, mixed> $task */
    private function answer(array $task): void
    {
        $inner = $this->w - 2 * self::MARGIN;
        $content = is_array($task['answer']) ? $task['answer'] : [];

        if ($task['answer'] === null || $content === []) {
            $this->SetFont('Helvetica', 'I', 9.5);
            $this->SetTextColor(110, 114, 140);
            $this->Cell($inner, 5, $this->t('(keine Antwort)'), 0, 1);
            $this->SetTextColor(0, 0, 0);

            return;
        }

        if ($task['answer_type'] === 'code' && is_array($content['files'] ?? null)) {
            $this->codeFiles($task, $content['files']);

            return;
        }

        if (is_string($task['preview_png']) && $task['preview_png'] !== '' && $this->image($task['preview_png'])) {
            return;
        }

        $text = (string) $task['answer_text'];
        $this->SetFont('Helvetica', '', 10);
        if (trim($text) === '') {
            $this->SetFont('Helvetica', 'I', 9.5);
            $this->SetTextColor(110, 114, 140);
            $this->Cell($inner, 5, $this->t('(Antwort kann im PDF nicht dargestellt werden)'), 0, 1);
            $this->SetTextColor(0, 0, 0);

            return;
        }
        if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_TEXT_CHARS) . "\n[... gekürzt]";
        }
        $this->MultiCell($inner, 4.8, $this->t($text));
    }

    /**
     * Code mit Zeilennummern, Monospace, Zeilen mit Kommentar hinterlegt; langer Code bricht sauber um.
     *
     * @param array<string, mixed> $task
     * @param list<mixed> $files
     */
    private function codeFiles(array $task, array $files): void
    {
        $inner = $this->w - 2 * self::MARGIN;
        $marked = [];
        foreach ($task['comments'] as $c) {
            if (($c['anchor']['kind'] ?? '') === 'code') {
                for ($l = (int) $c['anchor']['from_line']; $l <= (int) $c['anchor']['to_line']; $l++) {
                    $marked[(string) $c['anchor']['file']][$l] = true;
                }
            }
        }

        $this->SetFont('Courier', '', self::CODE_SIZE);
        $charW = $this->GetStringWidth('M');
        $perLine = max(20, (int) floor(($inner - self::GUTTER_W - 1) / max($charW, 0.5)));

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $name = (string) ($file['name'] ?? 'Datei');
            if ($this->GetY() + 18 > $this->h - $this->bMargin) {
                $this->AddPage();
            }
            $this->SetFont('Helvetica', 'B', 8.5);
            $this->SetTextColor(61, 65, 99);
            $this->Cell($inner, 4.8, $this->fit('Datei: ' . $name, $inner), 0, 1);
            $this->SetTextColor(0, 0, 0);

            $lines = preg_split('/\R/u', str_replace("\t", '    ', (string) ($file['content'] ?? ''))) ?: [''];
            $truncated = count($lines) > self::MAX_CODE_LINES;
            $lines = array_slice($lines, 0, self::MAX_CODE_LINES);
            $this->SetFont('Courier', '', self::CODE_SIZE);
            foreach ($lines as $i => $line) {
                $number = $i + 1;
                $isMarked = isset($marked[$name][$number]);
                $chunks = $line === '' ? [''] : (mb_str_split($line, $perLine) ?: ['']);
                foreach ($chunks as $k => $chunk) {
                    if ($isMarked) {
                        $this->SetFillColor(255, 243, 190);
                    } else {
                        $this->SetFillColor(246, 247, 251);
                    }
                    $this->SetTextColor(130, 134, 160);
                    $this->Cell(self::GUTTER_W, self::CODE_LINE_H, $k === 0 ? $this->t((string) $number) : '', 0, 0, 'R', true);
                    $this->SetTextColor(18, 20, 42);
                    $this->Cell($inner - self::GUTTER_W, self::CODE_LINE_H, ' ' . $this->t($chunk), 0, 1, 'L', true);
                }
            }
            if ($truncated) {
                $this->SetFont('Helvetica', 'I', 8.5);
                $this->Cell($inner, 4.5, $this->t('[... Rest der Datei gekürzt]'), 0, 1);
            }
            $this->Ln(1.5);
        }
        $this->SetTextColor(0, 0, 0);
    }

    /** @param array<string, mixed> $task */
    private function comments(array $task): void
    {
        if ($task['comments'] === []) {
            return;
        }
        $inner = $this->w - 2 * self::MARGIN;
        $this->Ln(1);
        $this->SetFont('Helvetica', 'B', 9.5);
        $this->SetTextColor(55, 48, 163);
        $this->Cell($inner, 5, $this->t('Anmerkungen zur Korrektur'), 0, 1);
        $this->SetTextColor(18, 20, 42);
        foreach ($task['comments'] as $c) {
            $label = self::anchorLabel($c['anchor']);
            $this->SetFont('Helvetica', 'B', 9);
            $this->SetX(self::MARGIN + 3);
            $this->Cell($this->GetStringWidth($this->t($label)) + 2, 4.6, $this->t($label), 0, 0);
            $this->SetFont('Helvetica', '', 9);
            $this->MultiCell($inner - 3 - $this->GetStringWidth($this->t($label)) - 2, 4.6, $this->t((string) $c['body']));
        }
        $this->SetTextColor(0, 0, 0);
    }

    /** Lesbare Beschreibung der Stelle eines Kommentars. @param array<string, mixed> $anchor */
    public static function anchorLabel(array $anchor): string
    {
        return match ($anchor['kind'] ?? 'task') {
            'code' => ((int) $anchor['from_line'] === (int) $anchor['to_line']
                ? 'Zeile ' . (int) $anchor['from_line']
                : 'Zeilen ' . (int) $anchor['from_line'] . ' bis ' . (int) $anchor['to_line'])
                . ' (' . (string) $anchor['file'] . '):',
            'text' => 'Zu "' . mb_substr((string) ($anchor['quote'] ?? ''), 0, 60) . '":',
            default => 'Allgemein:',
        };
    }

    /** Fügt ein Bild (Data-URL, PNG/JPEG) ein; false, wenn es nicht darstellbar ist. */
    private function image(string $dataUrl): bool
    {
        if (preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m) !== 1) {
            return false;
        }
        $bytes = base64_decode(preg_replace('/\s+/', '', $m[2]) ?? '', true);
        if ($bytes === false || $bytes === '') {
            return false;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'kwpdf');
        if ($tmp === false) {
            return false;
        }
        $file = $tmp . '.png';
        try {
            // Auf weißen Grund legen: FPDF kann PNG mit Alphakanal nicht darstellen.
            if (function_exists('imagecreatefromstring')) {
                $src = @imagecreatefromstring($bytes);
                if ($src === false) {
                    return false;
                }
                $w = imagesx($src);
                $h = imagesy($src);
                $canvas = imagecreatetruecolor($w, $h);
                imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
                imagecopy($canvas, $src, 0, 0, 0, 0, $w, $h);
                imagepng($canvas, $file);
                imagedestroy($src);
                imagedestroy($canvas);
            } elseif ($m[1] === 'png') {
                file_put_contents($file, $bytes);
            } else {
                return false;
            }
            $size = @getimagesize($file);
            if ($size === false || $size[0] < 1 || $size[1] < 1) {
                return false;
            }
            $maxW = $this->w - 2 * self::MARGIN;
            $maxH = 110.0;
            $scale = min($maxW / $size[0], $maxH / $size[1]);
            $wMm = $size[0] * $scale;
            $hMm = $size[1] * $scale;
            if ($this->GetY() + $hMm > $this->h - $this->bMargin) {
                $this->AddPage();
            }
            $this->Image($file, self::MARGIN, $this->GetY(), $wMm, $hMm);
            $this->SetY($this->GetY() + $hMm + 2);

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            @unlink($file);
            @unlink($tmp);
        }
    }
}
