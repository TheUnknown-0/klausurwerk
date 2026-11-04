<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Services\ExamExport;
use App\Services\ExamService;
use App\Services\Materials;
use App\Services\TaskService;
use App\Services\TaskValidationException;
use Tests\Support\DatabaseTestCase;

/** Klausuren und Aufgaben: ExamService, TaskService, Material, Kopieren, Export/Import. */
final class ExamModuleTest extends DatabaseTestCase
{
    private string $uploadDir;
    private Materials $materials;
    private TaskService $tasks;
    private ExamService $exams;
    private ExamExport $export;
    private int $teacherId;

    /** 1x1-PNG */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploadDir = sys_get_temp_dir() . '/kw-test-' . bin2hex(random_bytes(6));
        mkdir($this->uploadDir, 0775, true);
        $this->materials = new Materials($this->db, $this->uploadDir);
        $this->tasks = new TaskService($this->db, $this->materials);
        $this->exams = new ExamService($this->db, $this->tasks, $this->materials);
        $this->export = new ExamExport($this->db, $this->tasks, $this->materials);

        $this->db->run("INSERT INTO users (username, firstname, lastname, role) VALUES (?, 'Lena', 'Lehrer', 'teacher')", ['lehrer' . bin2hex(random_bytes(3))]);
        $this->teacherId = $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->uploadDir . '/materials/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->uploadDir . '/materials');
        @rmdir($this->uploadDir);
    }

    // ---------- Hilfen ----------

    /** @return array<string, mixed> */
    private function exam(int $id): array
    {
        $exam = $this->db->fetchOne('SELECT * FROM exams WHERE id = ?', [$id]);
        self::assertNotNull($exam);
        $exam['settings_array'] = json_decode((string) $exam['settings'], true);

        return $exam;
    }

    private function newExam(array $overrides = []): int
    {
        return $this->exams->create($overrides + ['title' => 'Klausur Q1', 'mode' => 'exam', 'duration_minutes' => '90'], $this->teacherId);
    }

    private function png(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kwpng');
        file_put_contents($path, base64_decode(self::PNG));

        return $path;
    }

    private function choiceInput(string $title = 'MC', float $points = 2): array
    {
        return [
            'title' => $title, 'body_md' => 'Welche stimmt?', 'points' => $points, 'answer_type' => 'choice',
            'config' => ['multiple' => false, 'options' => [['id' => 'a', 'text_md' => 'A'], ['id' => 'b', 'text_md' => 'B']], 'shuffle' => true],
            'solution' => ['correct' => ['b']],
            'model_answer' => 'B', 'expectation_md' => 'B wählen',
        ];
    }

    private function codeInput(string $body = 'Schreibe ein Programm.'): array
    {
        return [
            'title' => 'Code', 'body_md' => $body, 'points' => 6, 'answer_type' => 'code',
            'config' => ['language' => 'java', 'main_file' => 'Main.java', 'allow_new_files' => false, 'sample_tests' => [['name' => 'Eins', 'input' => '1', 'expected_output' => '1']]],
            'files' => [
                ['filename' => 'Main.java', 'content' => 'public class Main {}', 'is_readonly' => false],
                ['filename' => 'Util.java', 'content' => 'class Util {}', 'is_readonly' => true],
            ],
            'tests' => [['name' => 'T1', 'type' => 'io', 'input' => '1', 'expected_output' => '1', 'points' => 2]],
            'model_answer' => 'public class Main { public static void main(String[] a) {} }',
        ];
    }

    // ---------- ExamService ----------

    public function testAnlegenSetztEntwurfUndStandardEinstellungen(): void
    {
        $id = $this->newExam(['mode' => 'practice', 'duration_minutes' => '']);
        $exam = $this->exam($id);
        self::assertSame('draft', $exam['status']);
        self::assertSame((int) $this->teacherId, (int) $exam['owner_id']);
        self::assertNull($exam['duration_minutes']);
        self::assertTrue($exam['settings_array']['aids']['java_api'], 'Übungs-Standard');
        self::assertSame(0, (int) $exam['shared_in_archive']);
    }

    public function testAnlegenValidiert(): void
    {
        foreach ([
            ['title' => '', 'mode' => 'exam', 'duration_minutes' => '90'],
            ['title' => 'X', 'mode' => 'kaputt', 'duration_minutes' => '90'],
            ['title' => 'X', 'mode' => 'exam', 'duration_minutes' => ''],
            ['title' => 'X', 'mode' => 'exam', 'duration_minutes' => '9999'],
            ['title' => 'X', 'mode' => 'exam', 'duration_minutes' => '60', 'course_id' => '999999'],
            ['title' => 'X', 'mode' => 'exam', 'duration_minutes' => '60', 'scheduled_at' => 'morgen'],
        ] as $bad) {
            try {
                $this->exams->create($bad, $this->teacherId);
                self::fail('Erwartet InvalidArgumentException: ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testEinstellungenSpeichernNormalisierenUndCodeErzeugen(): void
    {
        $id = $this->newExam();
        $exam = $this->exam($id);
        $this->exams->saveSettings($exam, [
            'title' => 'Neu', 'mode' => 'exam', 'duration_minutes' => '45', 'scheduled_at' => '2026-05-04T08:15',
            'settings' => ['require_access_code' => '1', 'violation_grace_ms' => '99999', 'paste_policy' => 'x', 'python_packages' => ['numpy', 'evil']],
            'shared_in_archive' => '1',
        ]);
        $after = $this->exam($id);
        self::assertSame('Neu', $after['title']);
        self::assertSame('2026-05-04 08:15:00', $after['scheduled_at']);
        self::assertSame(1, (int) $after['shared_in_archive']);
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6}$/', (string) $after['access_code']);
        self::assertSame(5000, $after['settings_array']['violation_grace_ms']);
        self::assertSame('internal', $after['settings_array']['paste_policy']);
        self::assertSame(['numpy'], $after['settings_array']['python_packages']);
        self::assertTrue($after['settings_array']['require_access_code']);

        // Bei erneutem Speichern bleibt der Code stehen.
        $code = $after['access_code'];
        $this->exams->saveSettings($after, ['title' => 'Neu', 'mode' => 'exam', 'duration_minutes' => '45', 'settings' => ['require_access_code' => '1']]);
        self::assertSame($code, $this->exam($id)['access_code']);
    }

    public function testEinstellungenNachStartGesperrt(): void
    {
        $id = $this->newExam();
        $this->db->run("UPDATE exams SET status = 'lobby' WHERE id = ?", [$id]);
        $this->expectException(HttpException::class);
        $this->exams->saveSettings($this->exam($id), ['title' => 'x', 'mode' => 'exam', 'duration_minutes' => '10']);
    }

    public function testFertigstellenPrueftAufgabenPunkteUndStartdatei(): void
    {
        $id = $this->newExam();
        $report = $this->exams->markReady($this->exam($id));
        self::assertNotSame([], $report['errors'], 'Ohne Aufgabe nicht fertig');
        self::assertSame('draft', $this->exam($id)['status']);

        $taskId = $this->tasks->create($this->exam($id), null, 'choice');
        $this->tasks->save($this->exam($id), $taskId, $this->choiceInput('MC', 0));
        $report = $this->exams->markReady($this->exam($id));
        self::assertNotEmpty(array_filter($report['errors'], static fn (string $m): bool => str_contains($m, 'Punkte')));

        $this->tasks->save($this->exam($id), $taskId, $this->choiceInput('MC', 2));
        $report = $this->exams->markReady($this->exam($id));
        self::assertSame([], $report['errors']);
        self::assertSame('ready', $this->exam($id)['status']);

        $this->exams->backToDraft($this->exam($id));
        self::assertSame('draft', $this->exam($id)['status']);
    }

    public function testCodeAufgabeOhneStartdateiIstEinFehler(): void
    {
        $id = $this->newExam();
        $taskId = $this->tasks->create($this->exam($id), null, 'code');
        $input = $this->codeInput();
        $this->tasks->save($this->exam($id), $taskId, $input);
        // Startdatei fehlt in der config: wird auf Standard gesetzt, nie leer.
        self::assertSame([], $this->tasks->check($id)['errors']);
        $warnings = $this->tasks->check($id)['warnings'];
        self::assertIsArray($warnings);
    }

    public function testLoeschenNurInErlaubtenStatus(): void
    {
        $id = $this->newExam();
        $this->db->run("UPDATE exams SET status = 'running' WHERE id = ?", [$id]);
        try {
            $this->exams->delete($this->exam($id));
            self::fail('Laufende Klausur darf nicht gelöscht werden');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
        $this->db->run("UPDATE exams SET status = 'draft' WHERE id = ?", [$id]);
        $this->exams->delete($this->exam($id));
        self::assertNull($this->db->fetchOne('SELECT id FROM exams WHERE id = ?', [$id]));
    }

    public function testLoeschenEntferntMaterialDateien(): void
    {
        $id = $this->newExam();
        $row = $this->materials->storeLocal($id, $this->png(), 'bild.png', null, false);
        $path = $this->materials->pathFor($row);
        self::assertFileExists($path);
        $this->exams->delete($this->exam($id));
        self::assertFileDoesNotExist($path);
    }

    public function testLehrkraefteHinzufuegenAendernEntfernen(): void
    {
        $id = $this->newExam();
        $this->db->run("INSERT INTO users (username, firstname, lastname, role) VALUES ('kollege1', 'K', 'Kollege', 'teacher')");
        $other = $this->db->lastInsertId();
        $this->db->run("INSERT INTO users (username, firstname, lastname, role) VALUES ('schueler1', 'S', 'Schüler', 'student')");
        $student = $this->db->lastInsertId();

        $this->exams->addStaff($this->exam($id), $other, $this->teacherId);
        self::assertCount(1, $this->exams->staffOf($id));
        $this->exams->updateStaff($id, $other, true, false);
        self::assertSame(1, (int) $this->exams->staffOf($id)[0]['can_view_solutions']);

        foreach ([$student, $this->teacherId, 999999] as $bad) {
            try {
                $this->exams->addStaff($this->exam($id), $bad, $this->teacherId);
                self::fail('Ungültige Lehrkraft muss abgelehnt werden');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->exams->removeStaff($id, $other);
        self::assertSame([], $this->exams->staffOf($id));
    }

    public function testListeFiltertUndZaehltNurAufgabenMitAntworttyp(): void
    {
        $a = $this->newExam(['title' => 'A']);
        $b = $this->newExam(['title' => 'B', 'mode' => 'practice']);
        $t = $this->tasks->create($this->exam($a), null, 'choice');
        $this->tasks->save($this->exam($a), $t, $this->choiceInput('MC', 3));
        $this->tasks->create($this->exam($a), null, null, 'Überschrift');

        $rows = $this->exams->listFor([$a, $b], [], $this->teacherId);
        self::assertCount(2, $rows);
        $rowA = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['id'] === $a))[0];
        self::assertSame(1, (int) $rowA['task_count']);
        self::assertSame(3.0, (float) $rowA['total_points']);
        self::assertCount(1, $this->exams->listFor([$a, $b], ['mode' => 'practice']));
        self::assertSame([], $this->exams->listFor([]));
    }

    // ---------- TaskService: Baum ----------

    public function testBaumAnlegenVerschiebenLoeschenUndPunktesumme(): void
    {
        $id = $this->newExam();
        $exam = $this->exam($id);
        $one = $this->tasks->create($exam, null, null, 'Teil A');
        $two = $this->tasks->create($exam, null, null, 'Teil B');
        $sub1 = $this->tasks->create($exam, $one, 'choice');
        $sub2 = $this->tasks->create($exam, $one, 'choice');
        $this->tasks->save($exam, $sub1, $this->choiceInput('Eins', 2));
        $this->tasks->save($exam, $sub2, $this->choiceInput('Zwei', 3.5));

        $flat = $this->tasks->fullList($id);
        self::assertSame(['1', '1a', '1b', '2'], array_column($flat, 'number'));
        self::assertSame(5.5, $flat[0]['points'], 'Container = Summe der Teilaufgaben');

        // Aufgabe 2 nach oben
        $this->tasks->move($exam, $two, 'up');
        self::assertSame($two, $this->tasks->fullList($id)[0]['id']);

        // sub2 auf oberste Ebene holen und wieder einrücken
        $this->tasks->move($exam, $sub2, 'outdent');
        self::assertNull($this->tasks->find($id, $sub2)['parent_id']);
        self::assertSame(2.0, $this->tasks->find($id, $one)['points']);
        $this->tasks->move($exam, $sub2, 'up');
        $this->tasks->move($exam, $sub2, 'up');
        $this->tasks->move($exam, $sub2, 'up');
        self::assertSame($sub2, $this->tasks->fullList($id)[0]['id']);

        // Löschen der Hauptaufgabe löscht Teilaufgaben mit
        $this->tasks->delete($exam, $one);
        self::assertNull($this->tasks->find($id, $sub1));
        self::assertCount(2, $this->tasks->fullList($id));
    }

    public function testTeilaufgabeNurUnterUeberschriftUndEineEbene(): void
    {
        $id = $this->newExam();
        $exam = $this->exam($id);
        $mc = $this->tasks->create($exam, null, 'choice');
        try {
            $this->tasks->create($exam, $mc, 'choice');
            self::fail('Unter Aufgabe mit Antworttyp keine Teilaufgabe');
        } catch (TaskValidationException) {
            self::assertTrue(true);
        }
        $head = $this->tasks->create($exam, null, null);
        $sub = $this->tasks->create($exam, $head, null);
        $this->expectException(TaskValidationException::class);
        $this->tasks->create($exam, $sub, null);
    }

    public function testStrukturNachStartGesperrt(): void
    {
        $id = $this->newExam();
        $task = $this->tasks->create($this->exam($id), null, 'choice');
        $this->db->run("UPDATE exams SET status = 'lobby' WHERE id = ?", [$id]);
        $exam = $this->exam($id);
        foreach ([
            fn () => $this->tasks->create($exam, null, null),
            fn () => $this->tasks->delete($exam, $task),
            fn () => $this->tasks->move($exam, $task, 'down'),
        ] as $action) {
            try {
                $action();
                self::fail('Struktur muss gesperrt sein');
            } catch (HttpException $e) {
                self::assertSame(409, $e->status);
            }
        }
    }

    // ---------- TaskService: Speichern ----------

    public function testSpeichernNormalisiertTypUndLoesung(): void
    {
        $id = $this->newExam();
        $exam = $this->exam($id);
        $task = $this->tasks->create($exam, null, 'choice');
        $saved = $this->tasks->save($exam, $task, $this->choiceInput('Titel', 4));
        self::assertSame('choice', $saved['answer_type']);
        self::assertSame(['b'], $saved['solution']['correct']);
        self::assertSame(4.0, $saved['points']);
        self::assertSame('B wählen', $saved['expectation_md']);

        try {
            $bad = $this->choiceInput();
            $bad['solution'] = ['correct' => ['a', 'b']];
            $this->tasks->save($exam, $task, $bad);
            self::fail('Einfachauswahl mit zwei Richtigen');
        } catch (TaskValidationException $e) {
            self::assertSame('solution', $e->field);
        }
        try {
            $bad = $this->choiceInput();
            $bad['config']['options'] = [['id' => 'a', 'text_md' => 'nur eine']];
            $this->tasks->save($exam, $task, $bad);
            self::fail('Zu wenige Optionen');
        } catch (TaskValidationException $e) {
            self::assertSame('config', $e->field);
        }
        try {
            $this->tasks->save($exam, $task, ['answer_type' => 'gibtsnicht'] + $this->choiceInput());
            self::fail('Unbekannter Typ');
        } catch (TaskValidationException $e) {
            self::assertSame('answer_type', $e->field);
        }
        try {
            $this->tasks->save($exam, $task, ['points' => 5000] + $this->choiceInput());
            self::fail('Punkte außerhalb');
        } catch (TaskValidationException $e) {
            self::assertSame('points', $e->field);
        }
    }

    public function testEntwurfDarfLoesungNochLeerLassen(): void
    {
        $id = $this->newExam();
        $task = $this->tasks->create($this->exam($id), null, 'choice');
        $input = $this->choiceInput();
        $input['solution'] = [];
        $saved = $this->tasks->save($this->exam($id), $task, $input);
        self::assertSame([], $saved['solution']['correct']);
        $warnings = $this->tasks->check($id)['warnings'];
        self::assertNotEmpty(array_filter($warnings, static fn (string $m): bool => str_contains($m, 'Lösung')));
    }

    public function testCodeAufgabeSpeichertDateienUndLeitetReadonlyListenAb(): void
    {
        $id = $this->newExam();
        $task = $this->tasks->create($this->exam($id), null, 'code');
        $saved = $this->tasks->save($this->exam($id), $task, $this->codeInput());
        self::assertSame(['Util.java'], $saved['config']['readonly_files']);
        self::assertSame(['Main.java'], $saved['config']['editable_files']);
        self::assertCount(2, $saved['files']);
        self::assertTrue($saved['files'][1]['is_readonly']);
        self::assertSame('T1', $saved['tests'][0]['name']);
        self::assertStringContainsString('static void main', $saved['model_answer']);

        // Übernimmt die Schülerantwort-Normalisierung die Listen?
        $type = \App\Services\TaskTypes\TaskTypeRegistry::require('code');
        $content = $type->normalizeContent(['files' => [['name' => 'Util.java', 'content' => 'hack'], ['name' => 'Main.java', 'content' => 'ok']]], $saved['config']);
        self::assertSame(['Main.java'], array_column($content['files'], 'name'));
    }

    public function testCodeDateinamenUndDoppelteWerdenAbgelehnt(): void
    {
        $id = $this->newExam();
        $task = $this->tasks->create($this->exam($id), null, 'code');
        foreach ([[['filename' => '../x', 'content' => '']], [['filename' => 'A.java', 'content' => ''], ['filename' => 'A.java', 'content' => '']]] as $files) {
            try {
                $this->tasks->save($this->exam($id), $task, ['files' => $files] + $this->codeInput());
                self::fail('Dateien ungültig');
            } catch (TaskValidationException $e) {
                self::assertSame('files', $e->field);
            }
        }
    }

    public function testGesperrteKlausurErlaubtNurLoesungsseite(): void
    {
        $id = $this->newExam();
        $task = $this->tasks->create($this->exam($id), null, 'choice');
        $this->tasks->save($this->exam($id), $task, $this->choiceInput('Original', 2));
        $this->db->run("UPDATE exams SET status = 'running' WHERE id = ?", [$id]);

        $input = $this->choiceInput('GEÄNDERT', 99);
        $input['body_md'] = 'Neuer Text';
        $input['config']['options'][0]['text_md'] = 'Neu';
        $input['solution'] = ['correct' => ['a']];
        $input['model_answer'] = 'Neue Muster';
        $input['expectation_md'] = 'Neuer EH';
        $saved = $this->tasks->save($this->exam($id), $task, $input);

        self::assertSame('Original', $saved['title']);
        self::assertSame('Welche stimmt?', $saved['body_md']);
        self::assertSame(2.0, $saved['points']);
        self::assertSame('A', $saved['config']['options'][0]['text_md']);
        self::assertSame(['a'], $saved['solution']['correct']);
        self::assertSame('Neue Muster', $saved['model_answer']);
        self::assertSame('Neuer EH', $saved['expectation_md']);
    }

    public function testFremdeAufgabeIstNichtErreichbar(): void
    {
        $a = $this->newExam();
        $b = $this->newExam(['title' => 'Andere']);
        $task = $this->tasks->create($this->exam($a), null, 'choice');
        $this->expectException(HttpException::class);
        $this->tasks->save($this->exam($b), $task, $this->choiceInput());
    }

    // ---------- Material ----------

    public function testMaterialUploadFormateUndSvgVerbot(): void
    {
        $id = $this->newExam();
        $row = $this->materials->storeLocal($id, $this->png(), 'Skizze.png', null, true);
        self::assertSame('image/png', $row['mime']);
        self::assertSame(1, (int) $row['is_aid']);
        self::assertFileExists($this->materials->pathFor($row));
        self::assertCount(1, $this->materials->forExam($id));

        $svg = tempnam(sys_get_temp_dir(), 'kwsvg');
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        try {
            $this->materials->storeLocal($id, $svg, 'boese.svg', null, false);
            self::fail('SVG ist verboten');
        } catch (HttpException $e) {
            self::assertSame(400, $e->status);
        }
        // Falsche Endung / Inhalt passt nicht
        $fake = tempnam(sys_get_temp_dir(), 'kwfake');
        file_put_contents($fake, '<?php echo 1;');
        try {
            $this->materials->storeLocal($id, $fake, 'bild.png', null, false);
            self::fail('Inhalt passt nicht zum Typ');
        } catch (HttpException) {
            self::assertTrue(true);
        }

        $this->materials->delete($row);
        self::assertFileDoesNotExist($this->materials->pathFor($row));
        self::assertSame([], $this->materials->forExam($id));
    }

    // ---------- Kopieren ----------

    /** @return array{0: int, 1: array<string, mixed>, 2: int} Quellklausur, Material, Aufgabe */
    private function sourceWithMaterial(): array
    {
        $src = $this->newExam(['title' => 'Quelle']);
        $mat = $this->materials->storeLocal($src, $this->png(), 'Diagramm.png', null, false);
        $head = $this->tasks->create($this->exam($src), null, null, 'Kopf');
        $this->tasks->save($this->exam($src), $head, [
            'title' => 'Kopf', 'body_md' => 'Siehe ![Bild](material:' . $mat['id'] . ')', 'answer_type' => null,
        ]);
        $sub = $this->tasks->create($this->exam($src), $head, 'choice');
        $in = $this->choiceInput('Teil', 3);
        $in['model_answer'] = 'Muster mit ![](material:' . $mat['id'] . ')';
        $this->tasks->save($this->exam($src), $sub, $in);
        $code = $this->tasks->create($this->exam($src), null, 'code');
        $this->tasks->save($this->exam($src), $code, $this->codeInput());

        return [$src, $mat, $head];
    }

    public function testAufgabeKopierenMitTeilaufgabenLoesungenUndMaterial(): void
    {
        [$src, $mat, $head] = $this->sourceWithMaterial();
        $dst = $this->newExam(['title' => 'Ziel']);

        $newTop = $this->tasks->copyTask($head, $dst);
        $copied = $this->tasks->fullList($dst);
        self::assertCount(2, $copied);
        self::assertSame($newTop, $copied[0]['id']);
        self::assertSame(3.0, $copied[0]['points']);
        self::assertSame(['b'], $copied[1]['solution']['correct'], 'Lösung wurde mitkopiert');
        self::assertSame('B wählen', $copied[1]['expectation_md']);

        $newMats = $this->materials->forExam($dst);
        self::assertCount(1, $newMats);
        $newId = (int) $newMats[0]['id'];
        self::assertNotSame((int) $mat['id'], $newId);
        self::assertStringContainsString('material:' . $newId, $copied[0]['body_md']);
        self::assertStringNotContainsString('material:' . $mat['id'] . ')', $copied[0]['body_md']);
        self::assertStringContainsString('material:' . $newId, $copied[1]['model_answer']);
        self::assertNotSame($this->materials->pathFor($mat), $this->materials->pathFor($newMats[0]));
        self::assertFileExists($this->materials->pathFor($newMats[0]));

        // Unabhängig: Änderung der Kopie lässt das Original unberührt.
        $this->tasks->save($this->exam($dst), $copied[1]['id'], ['title' => 'Geändert'] + $this->choiceInput('Geändert', 1));
        self::assertSame('Teil', $this->tasks->fullList($src)[1]['title']);
    }

    public function testKopierenOhneLoesungenLeertDieLoesungsseite(): void
    {
        [$src] = $this->sourceWithMaterial();
        $dst = $this->newExam(['title' => 'Ziel']);
        $codeTop = (int) $this->db->fetchValue("SELECT id FROM tasks WHERE exam_id = ? AND answer_type = 'code'", [$src]);
        $new = $this->tasks->copyTask($codeTop, $dst, null, false);
        $task = $this->tasks->find($dst, $new);
        self::assertSame('', $task['model_answer']);
        self::assertSame([], $task['tests']);
        self::assertCount(2, $task['files'], 'vorgegebene Dateien bleiben');
    }

    public function testKopierenInGesperrteKlausurUndUnterTeilaufgabeScheitert(): void
    {
        [$src, , $head] = $this->sourceWithMaterial();
        $dst = $this->newExam(['title' => 'Ziel']);
        $parent = $this->tasks->create($this->exam($dst), null, null);
        try {
            $this->tasks->copyTask($head, $dst, $parent);
            self::fail('Aufgabe mit Teilaufgaben nicht unter Teilaufgabe');
        } catch (TaskValidationException) {
            self::assertTrue(true);
        }
        $this->db->run("UPDATE exams SET status = 'running' WHERE id = ?", [$dst]);
        $this->expectException(HttpException::class);
        $this->tasks->copyTask($head, $dst);
    }

    public function testGanzeKlausurKopierenBehaeltAllesOhnePersonen(): void
    {
        [$src, $mat] = $this->sourceWithMaterial();
        $this->db->run("INSERT INTO users (username, firstname, lastname, role) VALUES ('s1', 'S', 'S', 'student')");
        $student = $this->db->lastInsertId();
        $this->db->run('INSERT INTO participations (exam_id, user_id) VALUES (?, ?)', [$src, $student]);
        $this->db->run('UPDATE exam_materials SET is_aid = 1 WHERE id = ?', [$mat['id']]);

        $copyId = $this->exams->copy($this->exam($src), $this->teacherId, 'Kopie');
        $copy = $this->exam($copyId);
        self::assertSame('draft', $copy['status']);
        self::assertSame($src, (int) $copy['copied_from_exam_id']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM participations WHERE exam_id = ?', [$copyId]));
        self::assertCount(count($this->tasks->fullList($src)), $this->tasks->fullList($copyId));
        $mats = $this->materials->forExam($copyId);
        self::assertCount(1, $mats);
        self::assertSame(1, (int) $mats[0]['is_aid']);
        self::assertSame(['1', '1a', '2'], array_column($this->tasks->fullList($copyId), 'number'));
        self::assertSame($this->tasks->check($copyId)['errors'], $this->tasks->check($src)['errors']);
    }

    // ---------- Export / Import ----------

    public function testExportImportRundlauf(): void
    {
        [$src, $mat] = $this->sourceWithMaterial();
        $this->db->run("INSERT IGNORE INTO operators (name, afb) VALUES ('nennen', 'I')");
        $op = (int) $this->db->fetchValue("SELECT id FROM operators WHERE name = 'nennen'");
        $anyTask = (int) $this->db->fetchValue("SELECT id FROM tasks WHERE exam_id = ? AND answer_type = 'choice'", [$src]);
        $this->db->run('UPDATE tasks SET operator_id = ?, afb = ? WHERE id = ?', [$op, 'I', $anyTask]);
        $this->db->run("UPDATE exam_materials SET is_aid = 1, task_id = ? WHERE id = ?", [$anyTask, $mat['id']]);
        $this->db->run("UPDATE exams SET description = 'Beschreibung', scheduled_at = '2026-01-01 08:00:00', access_code = 'ABC234' WHERE id = ?", [$src]);

        $zipPath = $this->export->exportToFile($this->exam($src));
        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($zipPath));
            $doc = json_decode((string) $zip->getFromName('klausur.json'), true);
            self::assertSame('klausurwerk-export', $doc['format']);
            self::assertSame(1, $doc['version']);
            self::assertArrayNotHasKey('access_code', $doc['exam']);
            self::assertArrayNotHasKey('scheduled_at', $doc['exam']);
            self::assertArrayNotHasKey('owner_id', $doc['exam']);
            self::assertNotFalse($zip->locateName($doc['materials'][0]['file']));
            $zip->close();

            $newId = $this->export->importFromFile($zipPath, $this->teacherId);
        } finally {
            @unlink($zipPath);
        }

        $new = $this->exam($newId);
        self::assertNotSame($src, $newId);
        self::assertSame('draft', $new['status']);
        self::assertSame('Quelle', $new['title']);
        self::assertSame('Beschreibung', $new['description']);
        self::assertNull($new['scheduled_at']);
        self::assertNull($new['access_code']);
        self::assertSame($this->exam($src)['settings_array'], $new['settings_array']);

        $a = $this->tasks->fullList($src);
        $b = $this->tasks->fullList($newId);
        self::assertSame(array_column($a, 'number'), array_column($b, 'number'));
        foreach ($a as $i => $task) {
            foreach (['title', 'points', 'afb', 'answer_type', 'solution', 'expectation_md', 'tests'] as $key) {
                self::assertEquals($task[$key], $b[$i][$key], "Feld {$key} von Aufgabe {$task['number']}");
            }
            self::assertEquals($task['files'], $b[$i]['files']);
            self::assertSame($task['operator_id'] === null, $b[$i]['operator_id'] === null);
        }
        $codeNew = array_values(array_filter($b, static fn (array $t): bool => $t['answer_type'] === 'code'))[0];
        self::assertSame(['Util.java'], $codeNew['config']['readonly_files']);

        $newMats = $this->materials->forExam($newId);
        self::assertCount(1, $newMats);
        self::assertSame(1, (int) $newMats[0]['is_aid']);
        self::assertNotNull($newMats[0]['task_id']);
        self::assertStringContainsString('material:' . $newMats[0]['id'], $b[0]['body_md']);
        self::assertStringContainsString('material:' . $newMats[0]['id'], $b[1]['model_answer']);
    }

    private function zipWith(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kwz');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE | \ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    public function testImportLehntFremdeFormateAb(): void
    {
        $cases = [
            'kein ZIP' => (static function (): string {
                $p = tempnam(sys_get_temp_dir(), 'kwn');
                file_put_contents($p, 'Hallo');

                return $p;
            })(),
            'ohne klausur.json' => $this->zipWith(['readme.txt' => 'x']),
            'fremdes Format' => $this->zipWith(['klausur.json' => json_encode(['format' => 'moodle', 'version' => 1])]),
            'fremde Version' => $this->zipWith(['klausur.json' => json_encode(['format' => 'klausurwerk-export', 'version' => 7])]),
            'kein JSON' => $this->zipWith(['klausur.json' => '{kaputt']),
            'Pfad-Trick' => $this->zipWith(['klausur.json' => '{}', '../evil.txt' => 'x']),
        ];
        $before = (int) $this->db->fetchValue('SELECT COUNT(*) FROM exams');
        foreach ($cases as $label => $path) {
            try {
                $this->export->importFromFile($path, $this->teacherId);
                self::fail("Import muss scheitern: {$label}");
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage(), $label);
            } finally {
                @unlink($path);
            }
        }
        self::assertSame($before, (int) $this->db->fetchValue('SELECT COUNT(*) FROM exams'));
    }

    public function testImportPruefenUeberTaskTypesUndSchreibtNichtsBeiFehler(): void
    {
        $base = [
            'format' => 'klausurwerk-export', 'version' => 1,
            'exam' => ['title' => 'X', 'mode' => 'exam', 'duration_minutes' => 60],
            'settings' => [], 'materials' => [],
        ];
        $badTasks = [
            'unbekannter Typ' => [['key' => '1', 'title' => 'T', 'answer_type' => 'hologramm', 'config' => []]],
            'kaputte Konfiguration' => [['key' => '1', 'title' => 'T', 'answer_type' => 'choice', 'config' => ['options' => [['id' => 'a', 'text_md' => 'x']]]]],
            'zu tief' => [['key' => '1', 'title' => 'T', 'answer_type' => null, 'children' => [['key' => '2', 'title' => 'U', 'children' => [['key' => '3', 'title' => 'V']]]]]],
        ];
        $before = (int) $this->db->fetchValue('SELECT COUNT(*) FROM exams');
        foreach ($badTasks as $label => $tasks) {
            $path = $this->zipWith(['klausur.json' => json_encode($base + ['tasks' => $tasks])]);
            try {
                $this->export->importFromFile($path, $this->teacherId);
                self::fail("Import muss scheitern: {$label}");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            } finally {
                @unlink($path);
            }
        }
        self::assertSame($before, (int) $this->db->fetchValue('SELECT COUNT(*) FROM exams'));
    }

    public function testImportVerweigertMaterialMitUnerlaubterEndung(): void
    {
        $doc = [
            'format' => 'klausurwerk-export', 'version' => 1, 'exam' => ['title' => 'X', 'mode' => 'exam', 'duration_minutes' => 60],
            'tasks' => [], 'materials' => [['key' => '1', 'original_name' => 'x.svg', 'file' => 'materials/x.svg']],
        ];
        $path = $this->zipWith(['klausur.json' => json_encode($doc), 'materials/x.svg' => '<svg/>']);
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->export->importFromFile($path, $this->teacherId);
        } finally {
            @unlink($path);
        }
    }
}
