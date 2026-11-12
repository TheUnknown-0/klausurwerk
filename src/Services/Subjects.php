<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Fächer: bekannte Fächer, ihre Fachgruppe und was daraus folgt (Antworttypen vorne,
 * Standard-Hilfsmittel, Sprache und Zeichenleiste der Antworten).
 *
 * Das Fach ist Freitext (Kurs und Klausur), damit Schulen eigene Bezeichnungen nutzen können
 * ("Englisch LK", "Chemie Q2"). group() ordnet es über den Anfang des Namens oder ein Kürzel zu;
 * Unbekanntes landet in "allgemein".
 */
final class Subjects
{
    /**
     * Bekannte Fächer: Name => [Fachgruppe, Sprachcode der Antworten, Zeichenleiste].
     * Sprachcode und Zeichenleiste sind Vorschläge für neue Aufgaben (je Aufgabe änderbar).
     */
    public const KNOWN = [
        'Informatik' => ['informatik', 'de', 'none'],
        'Deutsch' => ['deutsch', 'de', 'de'],
        'Englisch' => ['fremdsprache', 'en', 'en'],
        'Französisch' => ['fremdsprache', 'fr', 'fr'],
        'Spanisch' => ['fremdsprache', 'es', 'es'],
        'Italienisch' => ['fremdsprache', 'it', 'it'],
        'Russisch' => ['fremdsprache', 'ru', 'none'],
        'Polnisch' => ['fremdsprache', 'pl', 'pl'],
        'Latein' => ['latein', 'la', 'la'],
        'Altgriechisch' => ['latein', 'grc', 'none'],
        'Chemie' => ['chemie', 'de', 'chem'],
        'Physik' => ['naturwissenschaft', 'de', 'math'],
        'Biologie' => ['naturwissenschaft', 'de', 'de'],
        'Mathematik' => ['mathematik', 'de', 'math'],
        'Geschichte' => ['gesellschaft', 'de', 'de'],
        'Geografie' => ['gesellschaft', 'de', 'de'],
        'Politikwissenschaft' => ['gesellschaft', 'de', 'de'],
        'Ethik' => ['gesellschaft', 'de', 'de'],
        'Philosophie' => ['gesellschaft', 'de', 'de'],
        'Wirtschaft' => ['gesellschaft', 'de', 'de'],
    ];

    /** Kürzel und andere Schreibweisen (klein geschrieben). */
    private const ALIASES = [
        'inf' => 'Informatik', 'info' => 'Informatik',
        'de' => 'Deutsch', 'deu' => 'Deutsch',
        'en' => 'Englisch', 'eng' => 'Englisch', 'english' => 'Englisch',
        'fr' => 'Französisch', 'frz' => 'Französisch', 'franzoesisch' => 'Französisch',
        'sp' => 'Spanisch', 'spa' => 'Spanisch', 'es' => 'Spanisch',
        'it' => 'Italienisch', 'ita' => 'Italienisch',
        'la' => 'Latein', 'lat' => 'Latein',
        'ch' => 'Chemie', 'che' => 'Chemie',
        'ph' => 'Physik', 'phy' => 'Physik',
        'bio' => 'Biologie', 'bi' => 'Biologie',
        'ma' => 'Mathematik', 'mathe' => 'Mathematik', 'mat' => 'Mathematik',
        'ge' => 'Geschichte', 'geo' => 'Geografie', 'erdkunde' => 'Geografie', 'geographie' => 'Geografie',
        'pw' => 'Politikwissenschaft', 'politik' => 'Politikwissenschaft',
        'eth' => 'Ethik', 'phil' => 'Philosophie',
        'griechisch' => 'Altgriechisch',
    ];

    /** Sprachen für Antworten (lang-Attribut). '' = wie das Fach. */
    public const LANGUAGES = [
        'de' => 'Deutsch', 'en' => 'Englisch', 'fr' => 'Französisch', 'es' => 'Spanisch', 'it' => 'Italienisch',
        'pl' => 'Polnisch', 'ru' => 'Russisch', 'la' => 'Latein', 'grc' => 'Altgriechisch',
    ];

    /** Zeichenleisten (frontend/src/lib/char-bar.js). '' = wie das Fach, 'none' = keine. */
    public const CHARSETS = [
        'none' => 'keine', 'de' => 'Deutsch', 'en' => 'Englisch', 'fr' => 'Französisch', 'es' => 'Spanisch',
        'it' => 'Italienisch', 'pl' => 'Polnisch', 'la' => 'Latein', 'chem' => 'Chemie', 'math' => 'Mathematik',
    ];

    /** Prüft lang/chars einer Aufgabenkonfiguration; Unbekanntes wird zu '' (wie das Fach). */
    public static function cleanLanguage(mixed $value): string
    {
        return is_string($value) && isset(self::LANGUAGES[$value]) ? $value : '';
    }

    public static function cleanCharset(mixed $value): string
    {
        return is_string($value) && isset(self::CHARSETS[$value]) ? $value : '';
    }

    /** Fachgruppen mit Bezeichnung (für Auswahllisten). */
    public const GROUPS = [
        'informatik' => 'Informatik',
        'deutsch' => 'Deutsch',
        'fremdsprache' => 'Moderne Fremdsprache',
        'latein' => 'Alte Sprache',
        'chemie' => 'Chemie',
        'naturwissenschaft' => 'Naturwissenschaft',
        'mathematik' => 'Mathematik',
        'gesellschaft' => 'Gesellschaftswissenschaft',
        'allgemein' => 'Sonstiges Fach',
    ];

    /** Bekannter Fachname zu einer freien Bezeichnung, sonst null. "Englisch LK" => "Englisch". */
    public static function canonical(string $subject): ?string
    {
        $s = mb_strtolower(trim($subject));
        if ($s === '') {
            return null;
        }
        foreach (array_keys(self::KNOWN) as $name) {
            $n = mb_strtolower($name);
            if ($s === $n || str_starts_with($s, $n . ' ') || str_starts_with($s, $n . '-') || str_starts_with($s, $n . '(')) {
                return $name;
            }
        }
        $first = preg_split('/[\s\-(]+/u', $s)[0] ?? '';

        return self::ALIASES[$first] ?? null;
    }

    public static function group(string $subject): string
    {
        $name = self::canonical($subject);

        return $name === null ? 'allgemein' : self::KNOWN[$name][0];
    }

    /** Sprachcode für Antworten (lang-Attribut), Standard Deutsch. */
    public static function language(string $subject): string
    {
        $name = self::canonical($subject);

        return $name === null ? 'de' : self::KNOWN[$name][1];
    }

    /** Zeichenleiste für neue Aufgaben. */
    public static function charset(string $subject): string
    {
        $name = self::canonical($subject);

        return $name === null ? 'de' : self::KNOWN[$name][2];
    }

    /** Ist das Fach Informatik? Dann bleibt alles wie bisher (Programmieren vorne usw.). */
    public static function isComputing(string $subject): bool
    {
        return self::group($subject) === 'informatik';
    }

    /** Bereinigter Fachname aus einem Formular; leer = null. */
    public static function clean(mixed $subject): ?string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $subject) ?? '');
        if ($s === '') {
            return null;
        }
        if (mb_strlen($s) > 60) {
            throw new \InvalidArgumentException('Das Fach darf höchstens 60 Zeichen lang sein.');
        }

        return $s;
    }

    /**
     * Hilfsmittel, die zum Fach passen. Profile und neue Klausuren schalten nur diese ein;
     * die Lehrkraft kann jedes andere trotzdem von Hand zuschalten.
     *
     * @return list<string>
     */
    public static function relevantAids(string $subject): array
    {
        return match (self::group($subject)) {
            'informatik' => ['java_api', 'python_doc', 'sql_cheatsheet', 'operators', 'calculator'],
            'chemie' => ['periodic_table', 'operators', 'calculator'],
            'naturwissenschaft' => ['periodic_table', 'operators', 'calculator'],
            'mathematik' => ['operators', 'calculator'],
            default => ['operators'],
        };
    }

    /**
     * Startwerte der Hilfsmittel für eine neue Klausur im Fach (Modus beachtet: Übung großzügiger).
     *
     * @return array<string, bool>
     */
    public static function defaultAids(string $subject, string $mode): array
    {
        $practice = $mode === 'practice';

        return match (self::group($subject)) {
            'informatik' => [
                'java_api' => $practice, 'python_doc' => $practice, 'sql_cheatsheet' => $practice,
                'operators' => true, 'calculator' => false, 'periodic_table' => false,
            ],
            'chemie' => [
                'java_api' => false, 'python_doc' => false, 'sql_cheatsheet' => false,
                'operators' => true, 'calculator' => true, 'periodic_table' => true,
            ],
            'naturwissenschaft' => [
                'java_api' => false, 'python_doc' => false, 'sql_cheatsheet' => false,
                'operators' => true, 'calculator' => true, 'periodic_table' => false,
            ],
            'mathematik' => [
                'java_api' => false, 'python_doc' => false, 'sql_cheatsheet' => false,
                'operators' => true, 'calculator' => $practice, 'periodic_table' => false,
            ],
            default => [
                'java_api' => false, 'python_doc' => false, 'sql_cheatsheet' => false,
                'operators' => true, 'calculator' => false, 'periodic_table' => false,
            ],
        };
    }

    /** Fachname, unter dem die Operatoren einer Klausur stehen ("Englisch LK" => "Englisch"). */
    public static function operatorSubject(string $subject): string
    {
        return self::canonical($subject) ?? trim($subject);
    }

    /** @return list<string> bekannte Fachnamen für Vorschlagslisten */
    public static function names(): array
    {
        return array_keys(self::KNOWN);
    }
}
