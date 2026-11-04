<?php

declare(strict_types=1);

namespace App\Services;

use ZipArchive;

/**
 * Tabellen-Exporte der Druckzentrale.
 *
 *  - csv():  Semikolon-getrennt, UTF-8 mit BOM (Excel öffnet das direkt korrekt)
 *  - xlsx(): minimales OOXML-Paket über ZipArchive (Inline-Strings, eine Sheet-XML)
 *
 * Beide Methoden senden die Datei und beenden den Request.
 */
final class Exports
{
    private const XLSX_MAX_COLUMNS = 702; // A .. ZZ

    /**
     * @param list<string>                              $header
     * @param list<array<string, mixed>|list<mixed>>    $rows
     */
    public static function csv(array $header, array $rows, string $filename): never
    {
        $filename = self::safeFilename($filename, 'csv');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }

        fwrite($out, "\xEF\xBB\xBF"); // BOM für Excel
        fputcsv($out, array_map(self::cell(...), $header), ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(self::cell(...), array_values($row)), ';', '"', '');
        }
        fclose($out);

        exit;
    }

    /**
     * @param list<string>                           $header
     * @param list<array<string, mixed>|list<mixed>> $rows
     */
    public static function xlsx(array $header, array $rows, string $filename): never
    {
        if (!class_exists(ZipArchive::class)) {
            // Ohne ZipArchive kein XLSX — dann liefern wir CSV statt eines Fehlers.
            self::csv($header, $rows, self::withoutXlsxSuffix($filename) . '.csv');
        }

        $sheet = self::sheetXml($header, $rows);

        $tmp = tempnam(sys_get_temp_dir(), 'bm_xlsx_');
        if ($tmp === false) {
            self::csv($header, $rows, self::withoutXlsxSuffix($filename) . '.csv');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            self::csv($header, $rows, self::withoutXlsxSuffix($filename) . '.csv');
        }

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets></workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/styles.xml', self::stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        $filename = self::safeFilename($filename, 'xlsx');

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Content-Length: ' . (string) (filesize($tmp) ?: 0));

        readfile($tmp);
        @unlink($tmp);

        exit;
    }

    /**
     * Liefert je nach Format CSV oder XLSX (Dateiendung wird ergänzt).
     *
     * @param list<string>                           $header
     * @param list<array<string, mixed>|list<mixed>> $rows
     */
    public static function deliver(string $format, array $header, array $rows, string $baseName): never
    {
        $baseName .= '_' . date('Y-m-d_His');

        if (strtolower($format) === 'xlsx') {
            self::xlsx($header, $rows, $baseName . '.xlsx');
        }

        self::csv($header, $rows, $baseName . '.csv');
    }

    // ---------- Interna ----------

    /**
     * @param list<string>                           $header
     * @param list<array<string, mixed>|list<mixed>> $rows
     */
    private static function sheetXml(array $header, array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        $xml .= self::rowXml($header, 1, 1);

        $rowNumber = 2;
        foreach ($rows as $row) {
            $xml .= self::rowXml(array_values($row), $rowNumber, 0);
            $rowNumber++;
        }

        return $xml . '</sheetData></worksheet>';
    }

    /** @param list<mixed> $values */
    private static function rowXml(array $values, int $rowNumber, int $styleId): string
    {
        $xml = '<row r="' . $rowNumber . '">';
        foreach ($values as $index => $value) {
            if ($index >= self::XLSX_MAX_COLUMNS) {
                break;
            }
            $xml .= '<c r="' . self::columnLetter($index) . $rowNumber . '" t="inlineStr" s="' . $styleId . '">'
                . '<is><t xml:space="preserve">' . self::xmlEscape(self::text($value)) . '</t></is></c>';
        }

        return $xml . '</row>';
    }

    private static function columnLetter(int $index): string
    {
        $letters = '';
        $index++;
        while ($index > 0) {
            $rest = ($index - 1) % 26;
            $letters = chr(65 + $rest) . $letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }

    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /** Wert als Text (null/bool werden lesbar abgebildet). */
    private static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'ja' : 'nein';
        }

        return (string) $value;
    }

    /**
     * Wie text(), entschärft aber Formel-Injektion: Tabellenprogramme werten
     * CSV-Felder mit führendem = + - @ als Formel aus. In XLSX ist das nicht
     * nötig, weil Inline-Strings dort immer Text bleiben.
     */
    private static function cell(mixed $value): string
    {
        $text = self::text($value);
        if ($text !== '' && str_contains("=+-@\t\r", $text[0])) {
            $text = "'" . $text;
        }

        return $text;
    }

    /** XML-sicher machen (inkl. Entfernen ungültiger Steuerzeichen). */
    private static function xmlEscape(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function withoutXlsxSuffix(string $name): string
    {
        return (string) preg_replace('/\.xlsx$/i', '', $name);
    }

    private static function safeFilename(string $name, string $extension): string
    {
        $name = strtr($name, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
        ]);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        if ($ascii === false) {
            $ascii = $name;
        }
        $ascii = trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', $ascii) ?? '', '_');
        if ($ascii === '') {
            $ascii = 'export';
        }
        if (!str_ends_with(strtolower($ascii), '.' . $extension)) {
            $ascii .= '.' . $extension;
        }

        return $ascii;
    }
}
