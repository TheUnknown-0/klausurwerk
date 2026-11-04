<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Schüler:innen-Import per CSV und Benutzernamen-Erzeugung.
 *
 * Ablauf: parseCsv() zerlegt die Datei (ohne Datenbank), analyze() prüft jede Zeile gegen
 * Datenbank und Rechte und liefert die Vorschau, execute() legt Konten, fehlende Kurse und
 * Mitgliedschaften an. Neue Konten haben kein Passwort (NULL): Anmelden geht erst nach dem
 * Druck der Zugangskarte.
 */
final class UserImport
{
    public const MAX_ROWS = 2000;

    /** Erkannte Kopfzeilen-Namen (klein) zu interner Spalte. */
    private const ALIASES = [
        'vorname' => 'firstname', 'firstname' => 'firstname',
        'nachname' => 'lastname', 'lastname' => 'lastname', 'name' => 'lastname',
        'kurs' => 'course', 'course' => 'course', 'klasse' => 'course',
        'benutzername' => 'username', 'username' => 'username', 'login' => 'username',
        'nachteilsausgleich' => 'accommodation', 'na' => 'accommodation', 'nta' => 'accommodation',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    // ---------- Benutzernamen ----------

    /** Wandelt Umlaute und gängige Akzente in ASCII um und entfernt alles außer a-z und 0-9. */
    public static function slug(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'ą' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ę' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ı' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'oe', 'ő' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ű' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ñ' => 'n', 'ń' => 'n',
            'š' => 's', 'ś' => 's', 'ş' => 's', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
            'ł' => 'l', 'đ' => 'd', 'ð' => 'd', 'þ' => 'th', 'æ' => 'ae', 'œ' => 'oe',
        ]);

        return preg_replace('/[^a-z0-9]+/', '', $text) ?? '';
    }

    /** Vorschlag ohne Eindeutigkeitsprüfung: vorname.nachname (erster Vorname, ganzer Nachname). */
    public static function baseUsername(string $firstname, string $lastname): string
    {
        $parts = preg_split('/\s+/', trim($firstname)) ?: [];
        $first = self::slug((string) ($parts[0] ?? ''));
        $last = self::slug($lastname);

        $base = $first !== '' && $last !== '' ? $first . '.' . $last : $first . $last;
        if (strlen($base) < 3) {
            $base = 'schueler' . $base;
        }

        return substr($base, 0, 90);
    }

    /**
     * Macht einen Benutzernamen eindeutig (hängt 2, 3, ... an).
     *
     * @param array<string, true> $reserved bereits vergebene Namen (klein), z. B. aus derselben Datei
     */
    public function uniqueUsername(string $base, array $reserved = []): string
    {
        $candidate = $base;
        $n = 1;
        while (isset($reserved[strtolower($candidate)]) || $this->usernameExists($candidate)) {
            $n++;
            $candidate = $base . $n;
        }

        return $candidate;
    }

    public function usernameExists(string $username): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM users WHERE username = ? LIMIT 1', [$username]) !== null;
    }

    public static function isValidUsername(string $username): bool
    {
        return preg_match('/^[a-zA-Z0-9._-]{3,100}$/', $username) === 1;
    }

    // ---------- CSV ----------

    /**
     * Zerlegt den CSV-Inhalt (UTF-8, Kopfzeile, Trenner ; oder , automatisch). Ohne Datenbank.
     *
     * @return array{error: string}|array{delimiter: string, rows: list<array{line: int, firstname: string, lastname: string, course: string, username: string, accommodation: string}>}
     */
    public static function parseCsv(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $numbered = [];
        foreach ($lines as $i => $line) {
            if (trim($line) !== '') {
                $numbered[] = [$i + 1, $line];
            }
        }
        if (count($numbered) < 2) {
            return ['error' => 'Die Datei enthält außer der Kopfzeile keine Daten.'];
        }
        if (count($numbered) - 1 > self::MAX_ROWS) {
            return ['error' => sprintf('Zu viele Zeilen (höchstens %d je Import).', self::MAX_ROWS)];
        }

        $header = $numbered[0][1];
        $semicolons = substr_count($header, ';');
        $commas = substr_count($header, ',');
        $tabs = substr_count($header, "\t");
        $delimiter = ';';
        if ($tabs > $semicolons && $tabs > $commas) {
            $delimiter = "\t";
        } elseif ($commas > $semicolons) {
            $delimiter = ',';
        }

        $columns = [];
        foreach (str_getcsv($header, $delimiter, '"', '') as $position => $label) {
            $key = mb_strtolower(trim((string) $label));
            if (isset(self::ALIASES[$key]) && !isset($columns[self::ALIASES[$key]])) {
                $columns[self::ALIASES[$key]] = $position;
            }
        }
        foreach (['firstname' => 'Vorname', 'lastname' => 'Nachname', 'course' => 'Kurs'] as $required => $label) {
            if (!isset($columns[$required])) {
                return ['error' => 'Die Kopfzeile braucht die Spalten Vorname, Nachname und Kurs (gefunden: '
                    . trim($header) . ').'];
            }
        }

        $rows = [];
        foreach (array_slice($numbered, 1) as [$lineNumber, $line]) {
            $cells = str_getcsv($line, $delimiter, '"', '');
            $get = static fn (string $key): string => isset($columns[$key]) ? trim((string) ($cells[$columns[$key]] ?? '')) : '';
            $rows[] = [
                'line' => $lineNumber,
                'firstname' => $get('firstname'),
                'lastname' => $get('lastname'),
                'course' => $get('course'),
                'username' => $get('username'),
                'accommodation' => $get('accommodation'),
            ];
        }

        return ['delimiter' => $delimiter, 'rows' => $rows];
    }

    // ---------- Vorschau ----------

    /**
     * Prüft jede Zeile; schreibt nichts.
     *
     * $scope: actor_id, all_courses (alle Kurse nutzbar), own_course_ids, may_create_courses,
     *         may_set_accommodation.
     *
     * @param list<array<string, mixed>> $rawRows Ergebnis von parseCsv()['rows']
     * @param array{actor_id: int, all_courses: bool, own_course_ids: list<int>, may_create_courses: bool, may_set_accommodation: bool} $scope
     * @return array{rows: list<array<string, mixed>>, courses: array<string, array<string, mixed>>, counts: array<string, int>, school_year: string}
     */
    public function analyze(array $rawRows, array $scope, ?string $schoolYear = null): array
    {
        $schoolYear ??= CourseService::currentSchoolYear();

        $existingCourses = [];
        foreach ($this->db->fetchAll('SELECT id, name, grade_level FROM courses WHERE school_year = ?', [$schoolYear]) as $c) {
            $existingCourses[mb_strtolower((string) $c['name'])] = $c;
        }

        $reservedNames = [];
        $courses = [];
        $rows = [];
        $counts = ['new' => 0, 'skip' => 0, 'error' => 0];

        foreach ($rawRows as $raw) {
            $problems = [];
            $status = 'new';
            $firstname = mb_substr(trim((string) $raw['firstname']), 0, 100);
            $lastname = mb_substr(trim((string) $raw['lastname']), 0, 100);
            $courseName = mb_substr(trim((string) $raw['course']), 0, 100);
            $username = trim((string) $raw['username']);
            $usernameGiven = $username !== '';
            $accommodationRaw = trim((string) $raw['accommodation']);

            if ($firstname === '' || $lastname === '') {
                $problems[] = 'Vorname und Nachname sind Pflicht';
            }

            // Kurs
            $courseKey = mb_strtolower($courseName);
            $courseId = null;
            if ($courseName === '') {
                $problems[] = 'Kurs fehlt';
            } else {
                if (!isset($courses[$courseKey])) {
                    $existing = $existingCourses[$courseKey] ?? null;
                    $allowed = true;
                    $reason = '';
                    if ($existing !== null) {
                        if (!$scope['all_courses'] && !in_array((int) $existing['id'], $scope['own_course_ids'], true)) {
                            $allowed = false;
                            $reason = 'Der Kurs gehört einer anderen Lehrkraft';
                        }
                    } elseif (!$scope['may_create_courses']) {
                        $allowed = false;
                        $reason = 'Den Kurs gibt es nicht und du darfst keine Kurse anlegen';
                    }
                    $courses[$courseKey] = [
                        'name' => $existing !== null ? (string) $existing['name'] : $courseName,
                        'exists' => $existing !== null,
                        'id' => $existing !== null ? (int) $existing['id'] : null,
                        'grade_level' => $existing !== null ? (int) $existing['grade_level'] : CourseService::guessGradeLevel($courseName),
                        'allowed' => $allowed,
                        'reason' => $reason,
                        'count' => 0,
                    ];
                }
                $course = &$courses[$courseKey];
                if (!$course['allowed']) {
                    $problems[] = (string) $course['reason'];
                }
                $courseId = $course['id'];
                unset($course);
            }

            // Nachteilsausgleich
            $accommodation = 0;
            if ($accommodationRaw !== '') {
                $normalized = rtrim(str_replace(',', '.', $accommodationRaw), '% ');
                if (!ctype_digit(str_replace('.', '', $normalized)) || (int) round((float) $normalized) > 100) {
                    $problems[] = 'Nachteilsausgleich muss eine Zahl von 0 bis 100 sein';
                } else {
                    $accommodation = (int) round((float) $normalized);
                    if ($accommodation > 0 && !$scope['may_set_accommodation']) {
                        $problems[] = 'Du darfst keinen Nachteilsausgleich festlegen';
                    }
                }
            }

            // Benutzername
            if ($usernameGiven) {
                if (!self::isValidUsername($username)) {
                    $problems[] = 'Benutzername ungültig (3 bis 100 Zeichen: Buchstaben, Zahlen, Punkt, Minus, Unterstrich)';
                } elseif (isset($reservedNames[strtolower($username)])) {
                    $problems[] = 'Benutzername kommt in der Datei mehrfach vor';
                } elseif ($this->usernameExists($username)) {
                    $problems[] = 'Benutzername ist schon vergeben';
                }
            }

            $reason = '';
            if ($problems === []) {
                // Schon im Kurs vorhanden (gleicher Name)? Dann überspringen statt doppelt anlegen.
                if (!$usernameGiven && $courseId !== null && $this->studentInCourseByName($courseId, $firstname, $lastname)) {
                    $status = 'skip';
                    $reason = 'Ist schon im Kurs vorhanden';
                } else {
                    if (!$usernameGiven) {
                        $username = $this->uniqueUsername(self::baseUsername($firstname, $lastname), $reservedNames);
                    }
                    $reservedNames[strtolower($username)] = true;
                    $courses[$courseKey]['count']++;
                }
            } else {
                $status = 'error';
                $reason = implode('; ', $problems);
                if ($usernameGiven && self::isValidUsername($username)) {
                    $reservedNames[strtolower($username)] = true;
                }
            }

            $counts[$status]++;
            $rows[] = [
                'line' => (int) $raw['line'],
                'firstname' => $firstname,
                'lastname' => $lastname,
                'course' => $courseName,
                'course_key' => $courseKey,
                'username' => $status === 'new' ? $username : ($usernameGiven ? $username : ''),
                'username_given' => $usernameGiven,
                'accommodation' => $accommodation,
                'status' => $status,
                'reason' => $reason,
            ];
        }

        return ['rows' => $rows, 'courses' => $courses, 'counts' => $counts, 'school_year' => $schoolYear];
    }

    // ---------- Ausführen ----------

    /**
     * Legt fehlende Kurse, Konten (created_via=import, ohne Passwort) und Mitgliedschaften an.
     * Prüft Benutzernamen erneut (zwischen Vorschau und Ausführen kann sich etwas geändert haben).
     *
     * @param array{rows: list<array<string, mixed>>, courses: array<string, array<string, mixed>>, school_year: string} $analysis
     * @param array<string, int|string> $gradeLevels Kursschlüssel (klein) zu Klassenstufe für neue Kurse
     * @return array{created: int, failed: int, skipped: int, courses_created: int, user_ids: list<int>, messages: list<string>}
     * @throws \InvalidArgumentException wenn für einen neuen Kurs keine gültige Klassenstufe vorliegt
     */
    public function execute(array $analysis, array $gradeLevels, int $actorId): array
    {
        $courses = $analysis['courses'];
        $schoolYear = (string) $analysis['school_year'];

        // Klassenstufen für neue Kurse zusammenstellen und prüfen, bevor etwas geschrieben wird.
        $levels = [];
        foreach ($courses as $key => $course) {
            if ($course['exists'] || !$course['allowed'] || (int) $course['count'] === 0) {
                continue;
            }
            $level = isset($gradeLevels[$key]) && $gradeLevels[$key] !== '' ? (int) $gradeLevels[$key] : (int) ($course['grade_level'] ?? 0);
            if ($level < CourseService::MIN_GRADE || $level > CourseService::MAX_GRADE) {
                throw new \InvalidArgumentException('Bitte für den neuen Kurs „' . $course['name'] . '“ eine Klassenstufe von 5 bis 13 wählen.');
            }
            $levels[$key] = $level;
        }

        return $this->db->transaction(function () use ($analysis, $courses, $levels, $schoolYear, $actorId): array {
            $courseIds = [];
            $created = 0;
            $failed = 0;
            $skipped = 0;
            $coursesCreated = 0;
            $userIds = [];
            $messages = [];

            foreach ($levels as $key => $level) {
                $name = (string) $courses[$key]['name'];
                // Zwischenzeitlich angelegt? Dann diesen Kurs verwenden.
                $found = $this->db->fetchOne('SELECT id FROM courses WHERE name = ? AND school_year = ?', [$name, $schoolYear]);
                if ($found !== null) {
                    $courseIds[$key] = (int) $found['id'];
                    continue;
                }
                $this->db->run(
                    "INSERT INTO courses (name, grade_level, school_year, subject, owner_id) VALUES (?, ?, ?, 'Informatik', ?)",
                    [$name, $level, $schoolYear, $actorId],
                );
                $courseIds[$key] = $this->db->lastInsertId();
                $coursesCreated++;
            }
            foreach ($courses as $key => $course) {
                if ($course['exists'] && $course['id'] !== null) {
                    $courseIds[$key] = (int) $course['id'];
                }
            }

            $reserved = [];
            foreach ($analysis['rows'] as $row) {
                if ($row['status'] === 'skip') {
                    $skipped++;
                    continue;
                }
                if ($row['status'] !== 'new') {
                    continue;
                }
                $courseId = $courseIds[$row['course_key']] ?? null;
                if ($courseId === null) {
                    $failed++;
                    $messages[] = 'Zeile ' . $row['line'] . ': Kurs nicht gefunden.';
                    continue;
                }

                $username = (string) $row['username'];
                if ($this->usernameExists($username) || isset($reserved[strtolower($username)])) {
                    if (!empty($row['username_given'])) {
                        $failed++;
                        $messages[] = 'Zeile ' . $row['line'] . ': Benutzername „' . $username . '“ ist inzwischen vergeben.';
                        continue;
                    }
                    $username = $this->uniqueUsername(self::baseUsername((string) $row['firstname'], (string) $row['lastname']), $reserved);
                }
                $reserved[strtolower($username)] = true;

                $this->db->run(
                    "INSERT INTO users (username, firstname, lastname, role, created_via, accommodation_percent)
                     VALUES (?, ?, ?, 'student', 'import', ?)",
                    [$username, $row['firstname'], $row['lastname'], (int) $row['accommodation']],
                );
                $userId = $this->db->lastInsertId();
                $this->db->run('INSERT INTO course_members (course_id, user_id) VALUES (?, ?)', [$courseId, $userId]);
                $userIds[] = $userId;
                $created++;
            }

            return [
                'created' => $created,
                'failed' => $failed,
                'skipped' => $skipped,
                'courses_created' => $coursesCreated,
                'user_ids' => $userIds,
                'messages' => $messages,
            ];
        });
    }

    // ---------- intern ----------

    private function studentInCourseByName(int $courseId, string $firstname, string $lastname): bool
    {
        return $this->db->fetchValue(
            "SELECT 1 FROM course_members cm JOIN users u ON u.id = cm.user_id
             WHERE cm.course_id = ? AND u.role = 'student' AND u.firstname = ? AND u.lastname = ? LIMIT 1",
            [$courseId, $firstname, $lastname],
        ) !== null;
    }
}
