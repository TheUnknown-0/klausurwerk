<?php

declare(strict_types=1);

/**
 * Demo-Daten für Vorführung, Handbuch-Screenshots und Ende-zu-Ende-Tests.
 *
 *   php bin/demo.php            legt die Demo-Schule an (nur in einer leeren Installation)
 *   php bin/demo.php --force    legt sie auch an, wenn schon Konten existieren
 *
 * Im Container: docker compose exec app php bin/demo.php
 *
 * Alle Personen sind erfunden. Passwörter stehen unten in DEMO_PASSWORD bzw.
 * werden für Schüler:innen als "<benutzername>-2026" gesetzt.
 */

use App\Core\Context;
use App\Services\ExamKit;

const DEMO_PASSWORD = 'Demo2026!';

if (PHP_SAPI !== 'cli') {
    exit("Nur auf der Kommandozeile.\n");
}

/** @var Context $ctx */
$ctx = require dirname(__DIR__) . '/src/bootstrap.php';
$db = $ctx->db;
$force = in_array('--force', $argv, true);

if (!$force && $db->fetchValue('SELECT COUNT(*) FROM users') > 0) {
    fwrite(STDERR, "Es gibt bereits Konten. Mit --force trotzdem Demo-Daten ergänzen.\n");
    exit(1);
}
if ($db->fetchValue("SELECT id FROM users WHERE username = 'frau.lehmann'") !== null) {
    fwrite(STDERR, "Demo-Daten sind schon vorhanden (frau.lehmann).\n");
    exit(1);
}

$hash = static fn (string $pw): string => password_hash($pw, PASSWORD_DEFAULT);

$userId = static function (string $username, string $first, string $last, string $role, ?string $email = null, ?string $password = null, int $accommodation = 0) use ($db, $hash): int {
    $db->run(
        'INSERT INTO users (username, email, password, firstname, lastname, role, accommodation_percent, card_version, card_issued_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$username, $email, $hash($password ?? DEMO_PASSWORD), $first, $last, $role, $accommodation,
         $role === 'student' ? 1 : 0, $role === 'student' ? date('Y-m-d H:i:s') : null],
    );

    return $db->lastInsertId();
};

$ctx->settings->set('school_name', $ctx->settings->get('school_name') ?: 'Gymnasium am Musterpark');
$ctx->settings->set('app_name', $ctx->settings->get('app_name') ?: 'Klausurwerk');

if ($db->fetchValue("SELECT id FROM users WHERE role = 'admin' LIMIT 1") === null) {
    $userId('admin', 'Alex', 'Admin', 'admin', 'admin@example.org');
}
$teacher = $userId('frau.lehmann', 'Johanna', 'Lehmann', 'teacher', 'lehmann@example.org');
$teacher2 = $userId('herr.yilmaz', 'Deniz', 'Yilmaz', 'teacher', 'yilmaz@example.org');

$students = [
    ['mia.becker', 'Mia', 'Becker', 0],
    ['noah.schulz', 'Noah', 'Schulz', 0],
    ['emma.wagner', 'Emma', 'Wagner', 25],
    ['ben.hoffmann', 'Ben', 'Hoffmann', 0],
    ['lea.koch', 'Lea', 'Koch', 0],
    ['paul.richter', 'Paul', 'Richter', 0],
];
$studentIds = [];
foreach ($students as [$u, $f, $l, $acc]) {
    $studentIds[] = $userId($u, $f, $l, 'student', null, $u . '-2026', $acc);
}
$db->run("UPDATE users SET accommodation_note = 'Nachteilsausgleich laut Konferenzbeschluss' WHERE username = 'emma.wagner'");

$year = \App\Services\CourseService::currentSchoolYear();
$db->run('INSERT INTO courses (name, grade_level, school_year, subject, owner_id) VALUES (?, 11, ?, ?, ?)', ['Inf-Q1', $year, 'Informatik', $teacher]);
$course = $db->lastInsertId();
$db->run('INSERT INTO courses (name, grade_level, school_year, subject, owner_id) VALUES (?, 10, ?, ?, ?)', ['Inf-10b', $year, 'Informatik', $teacher2]);
$db->run('INSERT INTO course_teachers (course_id, user_id) VALUES (?, ?)', [$course, $teacher2]);
foreach ($studentIds as $sid) {
    $db->run('INSERT INTO course_members (course_id, user_id) VALUES (?, ?)', [$course, $sid]);
}

$db->run('INSERT INTO rooms (name, grid_cols, grid_rows, created_by) VALUES (?, 6, 4, ?)', ['R 204 (Informatik)', $teacher]);
$room = $db->lastInsertId();
foreach ($studentIds as $i => $sid) {
    $db->run('INSERT INTO room_positions (room_id, user_id, col, `row`) VALUES (?, ?, ?, ?)', [$room, $sid, ($i % 3) * 2, intdiv($i, 3)]);
}

$exams = ExamKit::exams($ctx);
$tasks = ExamKit::tasks($ctx);
$operator = static fn (string $name): ?int => ($id = $db->fetchValue('SELECT id FROM operators WHERE name = ?', [$name])) === null ? null : (int) $id;

/**
 * Legt eine Aufgabe an und speichert sie vollständig.
 *
 * @param array<string, mixed> $data
 */
$task = static function (array $exam, ?int $parent, ?string $type, array $data) use ($tasks): int {
    $id = $tasks->create($exam, $parent, $type, (string) ($data['title'] ?? ''));
    $tasks->save($exam, $id, $data + ['answer_type' => $type]);

    return $id;
};

// ---------- Klausur ----------
$examId = $exams->create([
    'title' => 'Klausur Q1: Algorithmen und Datenbanken',
    'mode' => 'exam',
    'course_id' => $course,
    'room_id' => $room,
    'duration_minutes' => 90,
    'description' => 'Erste Klausur im Kurs Inf-Q1. Hilfsmittel: Operatorenliste.',
], $teacher);
$exam = $db->fetchOne('SELECT * FROM exams WHERE id = ?', [$examId]);
$exam = $db->fetchOne('SELECT * FROM exams WHERE id = ?', [$examId]);

$g = $task($exam, null, null, [
    'title' => 'Grundlagen',
    'body_md' => "Beantworte die folgenden Fragen zu Schleifen und Datentypen.",
]);
$task($exam, $g, 'choice', [
    'title' => 'Datentypen',
    'body_md' => 'Welche der folgenden Datentypen sind in Java **primitiv**?',
    'points' => 2, 'operator_id' => $operator('nennen'), 'afb' => 'I',
    'config' => ['multiple' => true, 'shuffle' => true, 'options' => [
        ['id' => 'a', 'text_md' => '`int`'], ['id' => 'b', 'text_md' => '`String`'],
        ['id' => 'c', 'text_md' => '`boolean`'], ['id' => 'd', 'text_md' => '`ArrayList<Integer>`'],
    ]],
    'solution' => ['correct' => ['a', 'c'], 'scoring' => 'partial'],
    'expectation_md' => '`int` und `boolean` sind primitiv, `String` und `ArrayList` sind Referenztypen.',
]);
$task($exam, $g, 'gaps', [
    'title' => 'Zählschleife',
    'body_md' => 'Ergänze die Schleife so, dass sie die Summe der Zahlen von 1 bis 10 berechnet.',
    'points' => 2, 'operator_id' => $operator('implementieren'), 'afb' => 'I-II',
    'config' => ['mode' => 'code', 'language' => 'java',
        'template' => "int summe = 0;\nfor (int i = 1; i {{g1}} 10; i++) {\n    summe {{g2}} i;\n}",
        'gaps' => [['id' => 'g1', 'width' => 3, 'options' => null], ['id' => 'g2', 'width' => 3, 'options' => ['+=', '-=', '=']]]],
    'solution' => ['gaps' => ['g1' => ['accepted' => ['<='], 'case_sensitive' => true, 'trim' => true], 'g2' => ['accepted' => ['+='], 'case_sensitive' => true, 'trim' => true]]],
]);
$task($exam, $g, 'text', [
    'title' => 'Laufzeit',
    'body_md' => 'Erläutere, warum die lineare Suche im schlechtesten Fall $n$ Vergleiche braucht.',
    'points' => 3, 'operator_id' => $operator('erläutern'), 'afb' => 'II',
    'config' => ['min_words' => 30, 'max_chars' => 4000],
    'expectation_md' => "- Element steht am Ende oder fehlt\n- jedes der \$n\$ Elemente wird einmal verglichen",
]);
$task($exam, null, 'code', [
    'title' => 'Fibonacci in Python',
    'body_md' => "Implementiere die Funktion `fib(n)`, die die n-te Fibonacci-Zahl zurückgibt (`fib(0) = 0`, `fib(1) = 1`).\n\nDas Hauptprogramm liest eine Zahl ein und gibt `fib(n)` aus.",
    'points' => 6, 'operator_id' => $operator('implementieren'), 'afb' => 'II',
    'config' => ['language' => 'python', 'main_file' => 'main.py', 'allow_new_files' => false, 'stdin' => '',
        'sample_tests' => [['name' => 'n = 10', 'input' => "10\n", 'expected_output' => '55', 'compare' => 'trim']]],
    'files' => [['filename' => 'main.py', 'content' => "def fib(n):\n    # TODO\n    return 0\n\n\nn = int(input(\"n = \"))\nprint(fib(n))\n", 'is_readonly' => false]],
    'model_answer' => "def fib(n):\n    a, b = 0, 1\n    for _ in range(n):\n        a, b = b, a + b\n    return a\n",
    'tests' => [
        ['name' => 'fib(0)', 'type' => 'python_assert', 'code' => 'assert fib(0) == 0', 'points' => 1],
        ['name' => 'fib(1)', 'type' => 'python_assert', 'code' => 'assert fib(1) == 1', 'points' => 1],
        ['name' => 'fib(20)', 'type' => 'python_assert', 'code' => 'assert fib(20) == 6765', 'points' => 2],
        ['name' => 'Eingabe 10', 'type' => 'io', 'input' => "10\n", 'expected_output' => '55', 'compare' => 'contains', 'points' => 2],
    ],
    'expectation_md' => 'Iterative oder rekursive Lösung; Basisfälle korrekt.',
]);
$task($exam, null, 'code', [
    'title' => 'Klasse Konto',
    'body_md' => "Vervollständige die Klasse `Konto` in `Konto.java`: `einzahlen` erhöht den Kontostand, `abheben` nur bei ausreichender Deckung (sonst `false`).\n\n`Main.java` ist vorgegeben und schreibgeschützt.",
    'points' => 8, 'operator_id' => $operator('implementieren'), 'afb' => 'II-III',
    'config' => ['language' => 'java', 'main_file' => 'Main.java', 'allow_new_files' => false, 'stdin' => ''],
    'files' => [
        ['filename' => 'Main.java', 'is_readonly' => true, 'content' => "public class Main {\n    public static void main(String[] args) {\n        Konto k = new Konto();\n        k.einzahlen(100);\n        System.out.println(k.abheben(30));\n        System.out.println(k.abheben(200));\n        System.out.println(k.getKontostand());\n    }\n}\n"],
        ['filename' => 'Konto.java', 'is_readonly' => false, 'content' => "public class Konto {\n    private int kontostand;\n\n    public void einzahlen(int betrag) {\n        // TODO\n    }\n\n    public boolean abheben(int betrag) {\n        // TODO\n        return false;\n    }\n\n    public int getKontostand() {\n        return kontostand;\n    }\n}\n"],
    ],
    'model_answer' => "public class Konto {\n    private int kontostand;\n    public void einzahlen(int betrag) { if (betrag > 0) kontostand += betrag; }\n    public boolean abheben(int betrag) {\n        if (betrag <= 0 || betrag > kontostand) return false;\n        kontostand -= betrag;\n        return true;\n    }\n    public int getKontostand() { return kontostand; }\n}\n",
    'tests' => [
        ['name' => 'Ablauf Main', 'type' => 'io', 'input' => '', 'expected_output' => "true\nfalse\n70", 'compare' => 'trim', 'points' => 4],
        ['name' => 'Keine Überziehung', 'type' => 'java_main', 'points' => 4, 'code' => "public class KwTest {\n    public static void main(String[] a) {\n        Konto k = new Konto();\n        k.einzahlen(10);\n        if (k.abheben(11)) System.exit(1);\n        if (k.getKontostand() != 10) System.exit(2);\n    }\n}\n"],
    ],
]);
$task($exam, null, 'code', [
    'title' => 'SQL: Bibliothek',
    'body_md' => "Gegeben ist die Tabelle `buch(id, titel, jahr, ausgeliehen)`.\n\nGib Titel und Jahr aller **nicht** ausgeliehenen Bücher aus, neueste zuerst.",
    'points' => 4, 'operator_id' => $operator('implementieren'), 'afb' => 'II',
    'config' => ['language' => 'sql', 'main_file' => 'abfrage.sql',
        'sql_setup' => "CREATE TABLE buch (id INTEGER PRIMARY KEY, titel TEXT, jahr INTEGER, ausgeliehen INTEGER);\nINSERT INTO buch VALUES (1,'Der Hobbit',1937,0),(2,'Momo',1973,1),(3,'Tschick',2010,0),(4,'Krabat',1971,0);"],
    'files' => [['filename' => 'abfrage.sql', 'content' => "-- deine Abfrage\n", 'is_readonly' => false]],
    'model_answer' => 'SELECT titel, jahr FROM buch WHERE ausgeliehen = 0 ORDER BY jahr DESC;',
    'tests' => [['name' => 'Ergebnis', 'type' => 'sql_result', 'expected_query' => 'SELECT titel, jahr FROM buch WHERE ausgeliehen = 0 ORDER BY jahr DESC', 'order_sensitive' => true, 'points' => 4]],
]);
$task($exam, null, 'matching', [
    'title' => 'Begriffe',
    'body_md' => 'Ordne die Begriffe den Beschreibungen zu.',
    'points' => 3, 'operator_id' => $operator('angeben'), 'afb' => 'I',
    'config' => ['shuffle' => true,
        'left' => [['id' => 'l1', 'text_md' => 'Primärschlüssel'], ['id' => 'l2', 'text_md' => 'Fremdschlüssel'], ['id' => 'l3', 'text_md' => 'Projektion']],
        'right' => [['id' => 'r1', 'text_md' => 'identifiziert einen Datensatz eindeutig'], ['id' => 'r2', 'text_md' => 'verweist auf einen Datensatz einer anderen Tabelle'], ['id' => 'r3', 'text_md' => 'Auswahl von Spalten']]],
    'solution' => ['pairs' => ['l1' => 'r1', 'l2' => 'r2', 'l3' => 'r3']],
]);
$task($exam, null, 'ordering', [
    'title' => 'Bubblesort',
    'body_md' => 'Bringe die Schritte eines Durchlaufs von Bubblesort in die richtige Reihenfolge.',
    'points' => 2, 'operator_id' => $operator('beschreiben'), 'afb' => 'I-II',
    'config' => ['items' => [
        ['id' => 's1', 'text_md' => 'Beginne beim ersten Element'],
        ['id' => 's2', 'text_md' => 'Vergleiche zwei benachbarte Elemente'],
        ['id' => 's3', 'text_md' => 'Vertausche sie, falls sie falsch herum stehen'],
        ['id' => 's4', 'text_md' => 'Gehe ein Element weiter'],
    ]],
    'solution' => ['order' => ['s1', 's2', 's3', 's4'], 'scoring' => 'partial'],
]);
$exams->addStaff($db->fetchOne('SELECT * FROM exams WHERE id = ?', [$examId]), $teacher2, $teacher);
$db->run("UPDATE exams SET status = 'ready' WHERE id = ?", [$examId]);

// ---------- Übung (läuft, ohne Überwachung) ----------
$practiceId = $exams->create(['title' => 'Übung: Python-Training', 'mode' => 'practice', 'course_id' => $course, 'duration_minutes' => null], $teacher);
$practice = $db->fetchOne('SELECT * FROM exams WHERE id = ?', [$practiceId]);
$task($practice, null, 'code', [
    'title' => 'Quersumme',
    'body_md' => 'Schreibe ein Programm, das eine Zahl einliest und ihre Quersumme ausgibt.',
    'points' => 4,
    'config' => ['language' => 'python', 'main_file' => 'quersumme.py',
        'sample_tests' => [['name' => '123', 'input' => "123\n", 'expected_output' => '6', 'compare' => 'trim'], ['name' => '9999', 'input' => "9999\n", 'expected_output' => '36', 'compare' => 'trim']]],
    'files' => [['filename' => 'quersumme.py', 'content' => "zahl = input(\"Zahl: \")\n", 'is_readonly' => false]],
    'model_answer' => "zahl = input('Zahl: ')\nprint(sum(int(z) for z in zahl))\n",
]);
$task($practice, null, 'code', [
    'title' => 'Turtle: Quadrat',
    'body_md' => 'Zeichne mit der Turtle ein Quadrat mit Seitenlänge 100.',
    'points' => 2,
    'config' => ['language' => 'python', 'main_file' => 'quadrat.py'],
    'files' => [['filename' => 'quadrat.py', 'content' => "import turtle\n\nt = turtle.Turtle()\n", 'is_readonly' => false]],
]);
$db->run("UPDATE exams SET status = 'running', started_at = NOW(3) WHERE id = ?", [$practiceId]);

// Robo-Aufgabe in der Übung (Gitterwelt)
$task($practice, null, 'robo', [
    'title' => 'Robo sammelt Schrauben',
    'body_md' => 'Steuere Robo so, dass er alle Schrauben einsammelt und auf dem Ziel stehen bleibt.',
    'points' => 4,
    'config' => [
        'language' => 'python', 'main_file' => 'main.py', 'allow_new_files' => true,
        'start_code' => "from robo import *\n\nvor()\n",
        'goal_md' => 'Sammle beide Schrauben ein und bleib auf der Flagge stehen.',
        'world' => ['cols' => 8, 'rows' => 4, 'robo' => ['x' => 0, 'y' => 1, 'dir' => 'E'],
            'walls' => [[3, 0], [3, 2], [3, 3]], 'boxes' => [], 'screws' => [['x' => 2, 'y' => 1, 'count' => 1], ['x' => 5, 'y' => 1, 'count' => 1]],
            'targets' => [[7, 1]]],
    ],
    'tests' => [[
        'name' => 'Startwelt', 'type' => 'robo_world', 'points' => 4,
        'world' => ['cols' => 8, 'rows' => 4, 'robo' => ['x' => 0, 'y' => 1, 'dir' => 'E'],
            'walls' => [[3, 0], [3, 2], [3, 3]], 'boxes' => [], 'screws' => [['x' => 2, 'y' => 1, 'count' => 1], ['x' => 5, 'y' => 1, 'count' => 1]],
            'targets' => [[7, 1]]],
        'goal' => ['screws_collected' => 'all', 'robo_at' => [7, 1]],
    ]],
]);

// ---------- Klausur 2: Modellierung (Diagramm-Aufgaben) ----------
$modelId = $exams->create([
    'title' => 'Test Q1: Modellierung und Logik',
    'mode' => 'exam',
    'course_id' => $course,
    'room_id' => $room,
    'duration_minutes' => 45,
], $teacher);
$model = $db->fetchOne('SELECT * FROM exams WHERE id = ?', [$modelId]);
$registry = \App\Services\TaskTypes\TaskTypeRegistry::class;
$diagram = static function (string $type, string $title, string $body, float $points, array $config = [], array $solution = []) use ($task, $model, $registry, $operator): int {
    $t = $registry::require($type);

    return $task($model, null, $type, [
        'title' => $title, 'body_md' => $body, 'points' => $points,
        'operator_id' => $operator('modellieren'), 'afb' => 'II',
        'config' => $config + $t->defaultConfig(),
        'solution' => $solution !== [] ? $solution : $t->defaultSolution(),
    ]);
};
$diagram('uml', 'Klassendiagramm Bibliothek', 'Modelliere die Klassen `Buch`, `Ausleihe` und `Mitglied` mit sinnvollen Attributen, Methoden und Beziehungen.', 6);
$diagram('structogram', 'Struktogramm', 'Zeichne ein Struktogramm für die lineare Suche in einem Feld.', 4);
$diagram('flowchart', 'Programmablaufplan', 'Zeichne einen PAP, der prüft, ob eine eingelesene Zahl eine Primzahl ist.', 4);
$diagram('logic', 'Schaltung', 'Baue eine Schaltung mit den Eingängen A und B, deren Ausgang X genau dann 1 ist, wenn **genau einer** der Eingänge 1 ist.', 3, [],
    ['truth_table' => ['00' => ['X' => 0], '01' => ['X' => 1], '10' => ['X' => 1], '11' => ['X' => 0]]]);
$diagram('truth_table', 'Wahrheitstabelle', 'Fülle die Wahrheitstabelle für $X = A \\land \\lnot B$ aus.', 2, [],
    ['rows' => ['00' => ['X' => '0'], '01' => ['X' => '0'], '10' => ['X' => '1'], '11' => ['X' => '0']]]);
$diagram('automaton', 'Endlicher Automat', 'Entwirf einen DEA über {a, b}, der genau die Wörter akzeptiert, die auf **ab** enden.', 4, [],
    ['accept' => ['ab', 'aab', 'bab', 'abab'], 'reject' => ['', 'a', 'b', 'ba', 'abb']]);
$diagram('er', 'ER-Modell', 'Erstelle ein ER-Modell (Chen-Notation) für Schüler:innen, Kurse und ihre Belegung.', 5);
$diagram('relational', 'Relationenschema', 'Überführe dein ER-Modell in ein Relationenschema mit Primär- und Fremdschlüsseln.', 4);
$diagram('drawing', 'Skizze', 'Skizziere den Aufbau eines Client-Server-Systems.', 2);
$db->run("UPDATE exams SET status = 'ready' WHERE id = ?", [$modelId]);

// ---------- Probe ----------
$exams->create(['title' => 'Technik-Check vor der Klausur', 'mode' => 'probe', 'course_id' => $course, 'room_id' => $room, 'duration_minutes' => 10], $teacher);

echo "Demo-Daten angelegt.\n";
echo "  Lehrkräfte: frau.lehmann, herr.yilmaz (Passwort " . DEMO_PASSWORD . ")\n";
echo "  Admin (falls neu angelegt): admin (Passwort " . DEMO_PASSWORD . ")\n";
echo "  Schüler:innen im Kurs Inf-Q1: " . implode(', ', array_column($students, 0)) . " (Passwort <benutzername>-2026)\n";
echo "  Klausur #{$examId} (bereit), Übung #{$practiceId} (läuft), Test Modellierung #{$modelId} (bereit), Technik-Check (Entwurf)\n";
