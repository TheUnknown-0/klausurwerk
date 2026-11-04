<?php

declare(strict_types=1);

namespace App\Services;

require_once dirname(__DIR__, 2) . '/lib/fpdf/fpdf.php';
require_once dirname(__DIR__, 2) . '/lib/qr.php';

/**
 * Zugangskarten als PDF: 8 Karten je A4-Seite (2 Spalten, 4 Zeilen), mit Schnittlinien.
 *
 * Kernschriften von FPDF können nur Latin-1; jede Textausgabe läuft deshalb durch t() bzw. fit().
 */
final class CardPdf extends \FPDF
{
    public const COLS = 2;
    public const ROWS = 4;
    public const PER_PAGE = 8;

    private const CARD_W = 105.0;
    private const CARD_H = 74.25;

    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4');
        $this->SetMargins(0, 0, 0);
        $this->SetAutoPageBreak(false);
        $this->SetCreator('Klausurwerk');
        $this->SetTitle($this->t('Zugangskarten'));
    }

    /**
     * Baut alle Karten und gibt das PDF als Bytes zurück.
     *
     * @param list<array{name: string, username: string, courses: string, password: string}> $cards
     */
    public function render(array $cards, string $schoolName, string $appName, string $loginUrl): string
    {
        if ($cards === []) {
            $this->AddPage();
        }
        foreach ($cards as $i => $card) {
            $slot = $i % self::PER_PAGE;
            if ($slot === 0) {
                $this->AddPage();
                $this->cutLines();
            }
            $x = ($slot % self::COLS) * self::CARD_W;
            $y = intdiv($slot, self::COLS) * self::CARD_H;
            $this->card($x, $y, $card, $schoolName, $appName, $loginUrl);
        }

        return $this->Output('S');
    }

    /** UTF-8 nach ISO-8859-1 (mit Transliteration). */
    public function t(string $utf8): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $utf8);

        return $converted === false ? (preg_replace('/[^\x20-\x7E]/', '', $utf8) ?? '') : $converted;
    }

    /** Wie t(), kürzt aber auf die Zellenbreite (mm). */
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

    /** Öffentliche Adresse für den QR-Code: Zugangsdaten stehen im Fragment (#), das nie zum Server geht. */
    public static function qrPayload(string $loginUrl, string $username, string $password): string
    {
        return $loginUrl . '#u=' . rawurlencode($username) . '&p=' . rawurlencode($password);
    }

    // ---------- intern ----------

    private function cutLines(): void
    {
        $this->SetDrawColor(190, 190, 200);
        $this->SetLineWidth(0.15);
        $this->SetDash(1.2, 1.2);
        $this->Line(self::CARD_W, 0, self::CARD_W, 297);
        for ($r = 1; $r < self::ROWS; $r++) {
            $this->Line(0, $r * self::CARD_H, 210, $r * self::CARD_H);
        }
        $this->SetDash();
    }

    /** Strichelung (FPDF kennt sie nicht): direkter PDF-Operator. */
    private function SetDash(float $black = 0, float $white = 0): void
    {
        $this->_out($black > 0
            ? sprintf('[%.3F %.3F] 0 d', $black * $this->k, $white * $this->k)
            : '[] 0 d');
    }

    /** @param array{name: string, username: string, courses: string, password: string} $card */
    private function card(float $x, float $y, array $card, string $schoolName, string $appName, string $loginUrl): void
    {
        $pad = 7.0;
        $inner = self::CARD_W - 2 * $pad;
        $left = $x + $pad;

        // Kopf: Schule und Anwendung
        $this->SetTextColor(95, 100, 125);
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetXY($left, $y + 6);
        $head = $schoolName !== '' ? $schoolName . ' | ' . $appName : $appName;
        $this->Cell($inner, 4, $this->fit($head, $inner), 0, 0, 'L');

        // Name und Kurs
        $this->SetTextColor(18, 20, 42);
        $this->SetFont('Helvetica', 'B', 14);
        $this->SetXY($left, $y + 12);
        $this->Cell($inner, 7, $this->fit($card['name'], $inner), 0, 0, 'L');
        $this->SetTextColor(61, 65, 99);
        $this->SetFont('Helvetica', '', 9);
        $this->SetXY($left, $y + 19.5);
        $this->Cell($inner, 5, $this->fit($card['courses'], $inner), 0, 0, 'L');

        // Zugangsdaten links
        $textW = 56.0;
        $this->SetTextColor(95, 100, 125);
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetXY($left, $y + 28);
        $this->Cell($textW, 4, $this->t('Benutzername'), 0, 0, 'L');
        $this->SetTextColor(18, 20, 42);
        $this->SetFont('Courier', 'B', 12);
        $this->SetXY($left, $y + 32);
        $this->Cell($textW, 6, $this->fit($card['username'], $textW), 0, 0, 'L');

        $this->SetTextColor(95, 100, 125);
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetXY($left, $y + 42);
        $this->Cell($textW, 4, $this->t('Passwort'), 0, 0, 'L');
        $this->SetTextColor(55, 48, 163);
        $this->SetFont('Courier', 'B', 15);
        $this->SetXY($left, $y + 46);
        $this->Cell($textW, 7, $this->fit($card['password'], $textW), 0, 0, 'L');

        // QR-Code rechts
        $qrSize = 32.0;
        qrDrawFpdf($this, self::qrPayload($loginUrl, $card['username'], $card['password']), $x + self::CARD_W - $pad - $qrSize + 2, $y + 27, $qrSize);

        // Hinweis unten
        $this->SetTextColor(95, 100, 125);
        $this->SetFont('Helvetica', '', 6.5);
        $this->SetXY($left, $y + 60);
        $this->MultiCell($inner, 3, $this->t(
            'QR-Code scannen oder unter ' . $loginUrl . ' anmelden. Die Karte ist persönlich, gib sie nicht weiter. '
            . 'Eine neu gedruckte Karte macht diese ungültig.',
        ), 0, 'L');
        $this->SetTextColor(0, 0, 0);
    }
}
