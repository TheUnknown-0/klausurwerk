<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Korrekturzeichen am Rand von Freitext-Antworten (Deutsch, Fremdsprachen, Latein) mit Zählung
 * und Fehlerquotient. Reine Rechenlogik ohne Datenbank; das Gegenstück im Browser steht in
 * frontend/src/grading/logic.js (summarizeMarks). Format: docs/DATENFORMATE.md, Abschnitt 5.
 *
 * Der Fehlerquotient ist nur ein Hinweis, er setzt keine Punkte.
 */
final class CorrectionMarks
{
    public const SET_AUTO = 'auto';
    public const SET_OFF = 'aus';
    /** Werte der Klausur-Einstellung `correction_marks`. */
    public const CHOICES = ['auto', 'deutsch', 'fremdsprache', 'latein', 'aus'];

    public const LABELS = [
        'auto' => 'Automatisch aus dem Fach',
        'deutsch' => 'Deutsch',
        'fremdsprache' => 'Fremdsprache (Englisch, Französisch ...)',
        'latein' => 'Latein und Altgriechisch',
        'aus' => 'Keine Korrekturzeichen',
    ];

    /** Fächer (kleingeschrieben) je Zeichensatz. */
    private const FOREIGN = ['englisch', 'französisch', 'franzoesisch', 'spanisch', 'italienisch', 'russisch', 'türkisch', 'tuerkisch', 'portugiesisch', 'niederländisch', 'niederlaendisch', 'polnisch', 'chinesisch', 'japanisch', 'arabisch', 'neugriechisch'];
    private const CLASSICAL = ['latein', 'altgriechisch', 'griechisch', 'lateinisch'];

    /**
     * Zeichen je Satz: Kürzel, Name, zählt als Fehler, Gewicht.
     *
     * @return array<string, list<array{code: string, name: string, counts: bool, weight: float}>>
     */
    public static function sets(): array
    {
        $m = static fn (string $code, string $name, bool $counts = true, float $weight = 1.0): array => [
            'code' => $code, 'name' => $name, 'counts' => $counts, 'weight' => $weight,
        ];

        return [
            'deutsch' => [
                $m('R', 'Rechtschreibung'), $m('Gr', 'Grammatik'), $m('Z', 'Zeichensetzung'), $m('A', 'Ausdruck'),
                $m('W', 'Wortwahl'), $m('Sb', 'Satzbau'), $m('T', 'Tempus'), $m('Bz', 'Bezug'),
                $m('St', 'Stil'), $m('Wdh', 'Wiederholung'),
            ],
            'fremdsprache' => [
                $m('sp', 'Rechtschreibung (spelling)'), $m('gr', 'Grammatik (grammar)'), $m('voc', 'Vokabular (vocabulary)'),
                $m('wo', 'Wortstellung (word order)'), $m('t', 'Zeitform (tense)'), $m('prep', 'Präposition (preposition)'),
                $m('st', 'Stil (style)'), $m('ex', 'Ausdruck (expression)'), $m('!', 'Auslassung (omission)'),
            ],
            'latein' => [
                $m('Vok', 'Vokabel'), $m('Gr', 'Grammatik, Form'), $m('K', 'Konstruktion'),
                $m('S', 'Sinnfehler', true, 2.0), $m('Ausl', 'Auslassung'),
            ],
            'aus' => [],
        ];
    }

    /** Zeichensatz zu einem Fach: Deutsch, Fremdsprachen, alte Sprachen, sonst keiner. */
    public static function setForSubject(string $subject): string
    {
        // Fachgruppe aus Subjects ("Englisch LK", Kürzel "En"), sonst die Namenslisten unten.
        $group = Subjects::group($subject);
        if (in_array($group, ['deutsch', 'fremdsprache', 'latein'], true)) {
            return $group;
        }
        $s = mb_strtolower(trim($subject));
        if ($s === 'deutsch') {
            return 'deutsch';
        }
        if (in_array($s, self::CLASSICAL, true)) {
            return 'latein';
        }
        if (in_array($s, self::FOREIGN, true)) {
            return 'fremdsprache';
        }

        return 'aus';
    }

    /**
     * Aktiver Satz einer Klausur: Einstellung `correction_marks`, bei 'auto' das Fach.
     *
     * @param array<string, mixed> $exam
     */
    public static function activeSet(array $exam): string
    {
        $choice = (string) (ExamAccess::settingsOf($exam)['correction_marks'] ?? 'auto');
        if ($choice === self::SET_AUTO || !in_array($choice, self::CHOICES, true)) {
            return self::setForSubject((string) ($exam['subject'] ?? 'Informatik'));
        }

        return $choice;
    }

    /** @return list<array{code: string, name: string, counts: bool, weight: float}> */
    public static function definitions(string $set): array
    {
        return self::sets()[$set] ?? [];
    }

    /** @return array{code: string, name: string, counts: bool, weight: float}|null */
    public static function find(string $set, string $code): ?array
    {
        foreach (self::definitions($set) as $def) {
            if ($def['code'] === $code) {
                return $def;
            }
        }

        return null;
    }

    /**
     * Angaben für die Oberfläche (Korrektur und Rückgabe).
     *
     * @param array<string, mixed> $exam
     * @return array{set: string, choice: string, marks: list<array<string, mixed>>, show_quotient: bool}
     */
    public static function forExam(array $exam): array
    {
        $settings = ExamAccess::settingsOf($exam);
        $set = self::activeSet($exam);
        $choice = (string) ($settings['correction_marks'] ?? 'auto');

        return [
            'set' => $set,
            'choice' => in_array($choice, self::CHOICES, true) ? $choice : 'auto',
            'marks' => self::definitions($set),
            'show_quotient' => !empty($settings['correction_marks_show_quotient']),
        ];
    }

    /** Wörter wie im Browser: durch Leerraum getrennte Stücke. */
    public static function countWords(string $text): int
    {
        $t = trim($text);

        return $t === '' ? 0 : count(preg_split('/\s+/u', $t) ?: []);
    }

    /** Gewichtete Fehler je 100 Wörter auf eine Nachkommastelle, null ohne Wörter. */
    public static function quotient(float $weighted, int $words): ?float
    {
        return $words <= 0 ? null : round($weighted / $words * 100, 1);
    }

    /**
     * Zählung für eine Aufgabe. Unbekannte Kürzel (z. B. nach dem Wechsel des Satzes) zählen nicht.
     *
     * @param list<string> $codes gesetzte Kürzel, eines je Zeichen
     * @param list<array{code: string, name: string, counts: bool, weight: float}> $defs
     * @return array{counts: array<string, int>, errors: int, weighted: float, words: int, quotient: ?float}
     */
    public static function summarize(array $codes, int $words, array $defs): array
    {
        $byCode = [];
        foreach ($defs as $d) {
            $byCode[$d['code']] = $d;
        }
        $counts = [];
        $errors = 0;
        $weighted = 0.0;
        foreach ($codes as $code) {
            if (!isset($byCode[$code])) {
                continue;
            }
            $counts[$code] = ($counts[$code] ?? 0) + 1;
            if ($byCode[$code]['counts']) {
                $errors++;
                $weighted += $byCode[$code]['weight'];
            }
        }

        return ['counts' => $counts, 'errors' => $errors, 'weighted' => $weighted, 'words' => $words, 'quotient' => self::quotient($weighted, $words)];
    }

    /**
     * Fasst mehrere Aufgaben zusammen (Quotient aus den Summen, nicht Mittel der Quotienten).
     *
     * @param list<array{counts: array<string, int>, errors: int, weighted: float, words: int}> $parts
     * @return array{counts: array<string, int>, errors: int, weighted: float, words: int, quotient: ?float}
     */
    public static function combine(array $parts): array
    {
        $counts = [];
        $errors = 0;
        $weighted = 0.0;
        $words = 0;
        foreach ($parts as $p) {
            foreach ($p['counts'] as $code => $n) {
                $counts[$code] = ($counts[$code] ?? 0) + $n;
            }
            $errors += $p['errors'];
            $weighted += $p['weighted'];
            $words += $p['words'];
        }

        return ['counts' => $counts, 'errors' => $errors, 'weighted' => $weighted, 'words' => $words, 'quotient' => self::quotient($weighted, $words)];
    }

    /**
     * Kürzel der Kommentare mit Zeichen (nur Textanker).
     *
     * @param list<array<string, mixed>> $comments
     * @return list<string>
     */
    public static function codesOf(array $comments): array
    {
        $out = [];
        foreach ($comments as $c) {
            $a = $c['anchor'] ?? [];
            if (($a['kind'] ?? '') === 'text' && isset($a['mark']) && is_string($a['mark']) && $a['mark'] !== '') {
                $out[] = $a['mark'];
            }
        }

        return $out;
    }

    /**
     * Setzt hinter jede markierte Stelle das Kürzel, z. B. "ging [Gr]". Positionen sind UTF-16-Einheiten
     * wie im Browser (JavaScript), nicht Codepunkte.
     *
     * @param list<array{to: int, code: string}> $marks
     */
    public static function annotate(string $text, array $marks): string
    {
        if ($marks === []) {
            return $text;
        }
        $u = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $len = intdiv(strlen($u), 2);
        $byPos = [];
        foreach ($marks as $m) {
            $byPos[max(0, min($len, $m['to']))][] = $m['code'];
        }
        // Von hinten einfügen, damit frühere Positionen gültig bleiben.
        krsort($byPos);
        foreach ($byPos as $pos => $codes) {
            $ins = mb_convert_encoding(' [' . implode('] [', $codes) . ']', 'UTF-16LE', 'UTF-8');
            $u = substr($u, 0, $pos * 2) . $ins . substr($u, $pos * 2);
        }

        return mb_convert_encoding($u, 'UTF-8', 'UTF-16LE');
    }
}
