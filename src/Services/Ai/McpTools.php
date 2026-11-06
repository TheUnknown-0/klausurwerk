<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Permissions as P;
use App\Services\ExamAccess;
use App\Services\ExamKit;
use App\Services\ExamService;
use App\Services\ExamSettings;
use App\Services\TaskService;
use App\Services\TaskTypes\TaskTypeRegistry;
use App\Services\TaskValidationException;

/**
 * Werkzeuge des KI-Zugangs (MCP). Jede Aktion läuft im Namen der angemeldeten Lehrkraft
 * (Auth::actAs) über dieselben Dienste und Rechteprüfungen wie die Weboberfläche:
 * ExamAccess (Fähigkeiten je Klausur), TaskService (Sperre ab Klausurstart, Validierung je Typ),
 * Materials (Dateitypen, Größe). Schülerdaten (Teilnahmen, Antworten, Ergebnisse) erreicht kein
 * Werkzeug. Schreibende Aktionen stehen im Audit-Log mit dem Namen des KI-Programms.
 */
final class McpTools
{
    /** Höchstzahl Treffer der Archivsuche je Aufruf. */
    private const ARCHIVE_LIMIT = 25;

    public function __construct(
        private readonly Context $ctx,
        private readonly string $clientName,
    ) {
    }

    // ---------- Definitionen ----------

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        $int = static fn (string $d): array => ['type' => 'integer', 'description' => $d];
        $str = static fn (string $d): array => ['type' => 'string', 'description' => $d];
        $obj = static fn (array $props, array $required = []): array => array_filter([
            'type' => 'object', 'properties' => $props === [] ? new \stdClass() : $props, 'required' => $required, 'additionalProperties' => false,
        ], static fn ($v): bool => $v !== []);
        $types = array_keys(TaskTypeRegistry::all());
        $taskFields = [
            'title' => $str('Titel der Aufgabe (kurz, z. B. "Klassendiagramm Bibliothek")'),
            'body_md' => $str('Aufgabentext in Markdown. Formeln mit $…$, Code in ```java …```, Bilder mit ![Text](material:ID).'),
            'points' => ['type' => 'number', 'description' => 'Punkte, Vielfaches von 0,5 (0 bis 999). Bei Überschriften mit Teilaufgaben ignoriert.'],
            'answer_type' => ['type' => ['string', 'null'], 'enum' => [...$types, null], 'description' => 'Antworttyp. null = Überschrift mit Teilaufgaben. Formate: get_task_types.'],
            'config' => ['type' => 'object', 'description' => 'Konfiguration des Antworttyps (siehe get_task_types), z. B. Optionen bei choice.'],
            'solution' => ['type' => 'object', 'description' => 'Lösung des Antworttyps (siehe get_task_types), z. B. {"correct": ["b"]} bei choice.'],
            'model_answer' => $str('Musterlösung als Text bzw. Code (bei code: vollständiges Programm). Nie für Schüler:innen sichtbar.'),
            'expectation_md' => $str('Erwartungshorizont für die Korrektur (Markdown). Nie für Schüler:innen sichtbar.'),
            'tests' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Versteckte Tests (code, robo), Format siehe get_task_types.'],
            'files' => ['type' => 'array', 'description' => 'Vorgegebene Dateien bei code.', 'items' => $obj([
                'filename' => $str('Dateiname, z. B. Main.java'),
                'content' => $str('Inhalt'),
                'is_readonly' => ['type' => 'boolean', 'description' => 'true = Schüler:innen können sie nicht ändern'],
            ], ['filename', 'content'])],
            'operator_id' => ['type' => ['integer', 'null'], 'description' => 'Operator (siehe get_reference_data), z. B. "Erläutern"'],
            'afb' => ['type' => ['string', 'null'], 'enum' => [...TaskService::AFB_VALUES, null], 'description' => 'Anforderungsbereich'],
        ];
        $examMeta = [
            'title' => $str('Titel der Klausur'),
            'mode' => ['type' => 'string', 'enum' => array_keys(ExamService::MODES), 'description' => 'exam = Klausur, practice = Übung, probe = Technik-Check ohne Aufgaben'],
            'duration_minutes' => ['type' => ['integer', 'null'], 'description' => 'Dauer in Minuten (1 bis 600); nur bei Übungen darf sie fehlen'],
            'course_id' => ['type' => ['integer', 'null'], 'description' => 'Kurs (siehe get_reference_data)'],
            'room_id' => ['type' => ['integer', 'null'], 'description' => 'Raum (siehe get_reference_data)'],
            'description' => $str('Beschreibung für die Lehrkraft'),
            'scheduled_at' => $str('Termin, Format JJJJ-MM-TT HH:MM'),
            'profile' => ['type' => 'string', 'enum' => array_keys(ExamSettings::PROFILES), 'description' => 'Einstellungsprofil (siehe get_reference_data)'],
        ];
        $examTask = ['exam_id' => $int('Klausur'), 'task_id' => $int('Aufgabe')];
        $ro = ['readOnlyHint' => true, 'openWorldHint' => false];
        $rw = ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false];
        $del = ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false];

        $d = static fn (string $name, string $title, string $desc, array $schema, array $ann): array => [
            'name' => $name, 'title' => $title, 'description' => $desc, 'inputSchema' => $schema, 'annotations' => $ann + ['title' => $title],
        ];

        return [
            $d('get_reference_data', 'Stammdaten', 'Wer bin ich, welche Rechte habe ich, und welche Kurse, Räume, Operatoren, Anforderungsbereiche, Modi und Einstellungsprofile gibt es? Zuerst aufrufen.', $obj([]), $ro),
            $d('get_task_types', 'Antworttypen', 'Alle Antworttypen mit Standard-config und -solution. Mit "type" zusätzlich die genaue Formatbeschreibung dieses Typs. Vor dem Anlegen einer Aufgabe eines Typs aufrufen.', $obj(['type' => ['type' => 'string', 'enum' => $types, 'description' => 'Antworttyp für die Formatbeschreibung']]), $ro),
            $d('list_exams', 'Klausuren auflisten', 'Klausuren, die die Lehrkraft sehen darf, neueste zuerst. editable = Aufgaben und Einstellungen änderbar (Entwurf oder bereit, mit Bearbeitungsrecht).', $obj([
                'status' => ['type' => 'string', 'enum' => array_keys(ExamService::STATUS_LABELS)],
                'query' => $str('Teil des Titels'),
            ]), $ro),
            $d('get_exam', 'Klausur lesen', 'Stammdaten, Einstellungen, alle Aufgaben in Reihenfolge (mit Lösungen, wenn berechtigt), Material und Links zu Editor und Vorschau.', $obj(['exam_id' => $int('Klausur')], ['exam_id']), $ro),
            $d('check_exam', 'Klausur prüfen', 'Prüfung wie beim Fertigstellen: Fehler (verhindern "bereit") und Warnungen, etwa fehlende Lösungen oder Punkte.', $obj(['exam_id' => $int('Klausur')], ['exam_id']), $ro),
            $d('create_exam', 'Klausur anlegen', 'Neue Klausur als Entwurf. Danach Aufgaben mit create_task anlegen.', $obj($examMeta, ['title', 'mode']), $rw),
            $d('update_exam', 'Klausur ändern', 'Stammdaten, Profil oder einzelne Einstellungen ändern (nur Entwurf oder bereit). Nicht genannte Felder bleiben. settings: Teilobjekt der Klausur-Einstellungen, z. B. {"aids": {"calculator": true}}.', $obj(['exam_id' => $int('Klausur')] + $examMeta + [
                'settings' => ['type' => 'object', 'description' => 'Teil der Einstellungen (Format: get_exam liefert die aktuellen)'],
            ], ['exam_id']), $rw),
            $d('create_task', 'Aufgabe anlegen', 'Neue Aufgabe am Ende der Ebene oder direkt hinter after_id. Teilaufgaben: parent_id einer Überschrift (answer_type null). Liefert die gespeicherte Aufgabe mit Nummer und Vorschau-Link.', $obj([
                'exam_id' => $int('Klausur'),
                'parent_id' => ['type' => ['integer', 'null'], 'description' => 'Überschrift, unter der die Teilaufgabe entsteht'],
                'after_id' => $int('Direkt hinter diese Aufgabe derselben Ebene einfügen'),
            ] + $taskFields, ['exam_id', 'title']), $rw),
            $d('update_task', 'Aufgabe ändern', 'Felder einer Aufgabe ändern. Nicht genannte Felder bleiben. Nach dem Start der Klausur nur noch Musterlösung, Erwartungshorizont, Lösung und Tests.', $obj($examTask + $taskFields, ['exam_id', 'task_id']), $rw),
            $d('delete_task', 'Aufgabe löschen', 'Löscht eine Aufgabe samt Teilaufgaben. Nicht rückgängig zu machen: vorher bei der Lehrkraft nachfragen.', $obj($examTask, ['exam_id', 'task_id']), $del),
            $d('move_task', 'Aufgabe verschieben', 'up/down innerhalb der Ebene, indent = Teilaufgabe der vorigen Aufgabe, outdent = auf die oberste Ebene.', $obj($examTask + [
                'direction' => ['type' => 'string', 'enum' => ['up', 'down', 'indent', 'outdent']],
            ], ['exam_id', 'task_id', 'direction']), $rw),
            $d('duplicate_task', 'Aufgabe duplizieren', 'Kopie samt Teilaufgaben, Lösung und Tests direkt hinter dem Original.', $obj($examTask, ['exam_id', 'task_id']), $rw),
            $d('list_materials', 'Material auflisten', 'Dateien der Klausur (Bilder, PDF) mit dem Markdown zum Einbinden.', $obj(['exam_id' => $int('Klausur')], ['exam_id']), $ro),
            $d('upload_material', 'Material hochladen', 'Bild (png, jpg, gif, webp) oder PDF bis 20 MB als Base64. Einbinden im Aufgabentext mit dem gelieferten Markdown.', $obj([
                'exam_id' => $int('Klausur'),
                'filename' => $str('Dateiname mit Endung, z. B. netzplan.png'),
                'content_base64' => $str('Dateiinhalt, Base64'),
                'task_id' => ['type' => ['integer', 'null'], 'description' => 'Aufgabe, zu der die Datei gehört (optional)'],
                'is_aid' => ['type' => 'boolean', 'description' => 'true = als Hilfsmittel in der Seitenleiste anbieten'],
            ], ['exam_id', 'filename', 'content_base64']), $rw),
            $d('delete_material', 'Material löschen', 'Löscht eine Datei. Verweise im Aufgabentext zeigen danach ins Leere.', $obj(['exam_id' => $int('Klausur'), 'material_id' => $int('Material')], ['exam_id', 'material_id']), $del),
            $d('search_archive', 'Archiv durchsuchen', 'Aufgaben aus früheren Klausuren (eigene und im Archiv freigegebene) suchen.', $obj([
                'query' => $str('Suchbegriff in Titel oder Text'),
                'answer_type' => ['type' => 'string', 'enum' => $types],
                'language' => ['type' => 'string', 'enum' => ['java', 'python', 'sql']],
            ]), $ro),
            $d('copy_archive_task', 'Aus dem Archiv übernehmen', 'Kopiert eine Aufgabe aus dem Archiv (samt Teilaufgaben, mit Lösungen, wenn berechtigt) ans Ende einer eigenen Klausur.', $obj([
                'task_id' => $int('Aufgabe aus search_archive'),
                'target_exam_id' => $int('Zielklausur (Entwurf)'),
            ], ['task_id', 'target_exam_id']), $rw),
        ];
    }

    /** Hinweise für das Sprachmodell (MCP initialize "instructions"). */
    public static function instructions(): string
    {
        return <<<'TXT'
            Klausurwerk ist eine Plattform für Informatik-Klausuren (Java, Python, SQL, Diagramme) an einer Schule.
            Du arbeitest im Namen einer Lehrkraft und hilfst beim Erstellen von Klausuren, Übungen und Aufgaben.

            Vorgehen:
            1. get_reference_data aufrufen (Rechte, Kurse, Operatoren, Profile).
            2. Vor dem Anlegen einer Aufgabe get_task_types mit dem Typ aufrufen und config/solution genau in diesem Format liefern.
            3. Aufgaben vollständig anlegen: Aufgabentext, Punkte, Lösung bzw. Musterlösung und Erwartungshorizont, bei code passende Tests.
            4. Nach größeren Änderungen check_exam aufrufen und der Lehrkraft den Vorschau-Link nennen, damit sie die Aufgabe prüft.

            Regeln:
            - Schreibe auf Deutsch, passend für die Sekundarstufe (Klassen 10 bis 12) und mit den Operatoren der Liste.
            - Punkte sind Vielfache von 0,5. Teilaufgaben entstehen unter einer Überschrift (answer_type null).
            - Laufende oder abgeschlossene Klausuren sind gesperrt; du kannst dort keine Aufgaben hinzufügen oder löschen.
            - Löschen nur nach ausdrücklicher Bestätigung der Lehrkraft.
            - Du hast keinen Zugriff auf Schülerdaten und sollst keine erfinden.
            TXT;
    }

    // ---------- Aufruf ----------

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed> strukturiertes Ergebnis
     */
    public function call(string $name, array $args): array
    {
        try {
            return match ($name) {
                'get_reference_data' => $this->referenceData(),
                'get_task_types' => $this->taskTypes($args),
                'list_exams' => $this->listExams($args),
                'get_exam' => $this->getExam($args),
                'check_exam' => $this->checkExam($args),
                'create_exam' => $this->createExam($args),
                'update_exam' => $this->updateExam($args),
                'create_task' => $this->createTask($args),
                'update_task' => $this->updateTask($args),
                'delete_task' => $this->deleteTask($args),
                'move_task' => $this->moveTask($args),
                'duplicate_task' => $this->duplicateTask($args),
                'list_materials' => $this->listMaterials($args),
                'upload_material' => $this->uploadMaterial($args),
                'delete_material' => $this->deleteMaterial($args),
                'search_archive' => $this->searchArchive($args),
                'copy_archive_task' => $this->copyArchiveTask($args),
                default => throw new McpToolNotFound($name),
            };
        } catch (TaskValidationException $e) {
            throw new ToolError($e->getMessage() . ($e->field !== null && $e->field !== '' ? ' (Feld: ' . $e->field . ')' : ''));
        } catch (\InvalidArgumentException $e) {
            throw new ToolError($e->getMessage());
        } catch (HttpException $e) {
            throw new ToolError(match ($e->status) {
                403 => 'Keine Berechtigung dafür. ' . $e->getMessage(),
                default => $e->getMessage() !== '' ? $e->getMessage() : 'Fehler ' . $e->status,
            });
        }
    }

    // ---------- Hilfen ----------

    private function userId(): int
    {
        return (int) $this->ctx->auth->id();
    }

    private static function intArg(array $args, string $key): int
    {
        $v = $args[$key] ?? null;
        if (!is_int($v) && !(is_string($v) && ctype_digit($v)) && !(is_float($v) && floor($v) === $v)) {
            throw new ToolError('Parameter ' . $key . ' fehlt oder ist keine Zahl.');
        }

        return (int) $v;
    }

    private static function optInt(array $args, string $key): ?int
    {
        return array_key_exists($key, $args) && $args[$key] !== null && $args[$key] !== '' ? self::intArg($args, $key) : null;
    }

    /** @return array<string, mixed> */
    private function exam(int $id, string $cap): array
    {
        return $this->ctx->exams->require($id, $cap);
    }

    /** Klausur zum Bearbeiten: Recht und Status prüfen. @return array<string, mixed> */
    private function editableExam(int $id): array
    {
        $exam = $this->exam($id, ExamAccess::CAP_EDIT);
        if (!ExamService::isEditable($exam)) {
            throw new ToolError('Die Klausur "' . $exam['title'] . '" ist gestartet oder abgeschlossen und kann nicht mehr verändert werden.');
        }

        return $exam;
    }

    private function log(string $action, string $details): void
    {
        $this->ctx->audit->log('ki.' . $action, 'info', $details . ' (KI-Programm: ' . $this->clientName . ')');
    }

    /** @return array{editor: string, preview: string} */
    private function links(int $examId, ?int $taskId = null): array
    {
        return [
            'editor' => $this->ctx->publicUrl('/klausuren/' . $examId . '/aufgaben'),
            'preview' => $this->ctx->publicUrl('/klausuren/' . $examId . '/vorschau' . ($taskId !== null ? '?aufgabe=' . $taskId : '')),
        ];
    }

    /** @param array<string, mixed> $t @return array<string, mixed> */
    private static function taskOut(array $t, bool $withSolutions): array
    {
        unset($t['children'], $t['exam_id'], $t['position']);
        if (!$withSolutions) {
            unset($t['solution'], $t['model_answer'], $t['expectation_md'], $t['tests']);
        }

        return $t;
    }

    /** @return array<string, mixed>|null */
    private function taskOf(int $examId, int $taskId): ?array
    {
        return ExamKit::tasks($this->ctx)->find($examId, $taskId);
    }

    // ---------- Lesen ----------

    private function referenceData(): array
    {
        $user = $this->ctx->auth->user() ?? [];
        $auth = $this->ctx->auth;
        $allCourses = $auth->isAdmin() || $auth->can(P::ALLE_KURSE);
        $svc = ExamKit::exams($this->ctx);
        $profiles = [];
        foreach (ExamSettings::PROFILES as $key => $p) {
            $profiles[] = ['key' => $key, 'label' => $p['label'], 'description' => $p['description'] ?? ''];
        }

        return [
            'user' => ['name' => display_name($user), 'role' => $user['role'] ?? null],
            'school' => $this->ctx->settings->get('school_name'),
            'permissions' => [
                'create_exams' => $auth->can(P::KLAUSUREN_ERSTELLEN),
                'search_archive' => $auth->can(P::ARCHIV_DURCHSUCHEN),
            ],
            'modes' => ExamService::MODES,
            'profiles' => $profiles,
            'courses' => array_map(static fn (array $c): array => [
                'id' => (int) $c['id'], 'name' => $c['name'], 'school_year' => $c['school_year'], 'grade_level' => (int) $c['grade_level'],
            ], $svc->courseOptions($this->userId(), $allCourses)),
            'rooms' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => $r['name']], $svc->roomOptions()),
            'operators' => array_map(static fn (array $o): array => ['id' => (int) $o['id'], 'name' => $o['name'], 'afb' => $o['afb']],
                $this->ctx->db->fetchAll('SELECT id, name, afb FROM operators ORDER BY sort_order, name')),
            'afb_values' => TaskService::AFB_VALUES,
            'markdown' => 'Aufgabentexte in Markdown: **fett**, Listen, Tabellen, Code in ```java```, Formeln $a^2$ und $$…$$, Bilder ![Text](material:ID).',
        ];
    }

    private function taskTypes(array $args): array
    {
        $types = [];
        foreach (TaskTypeRegistry::all() as $name => $type) {
            $types[] = [
                'name' => $name,
                'label' => $type->label(),
                'group' => $type->group(),
                'default_config' => $type->defaultConfig() ?: new \stdClass(),
                'default_solution' => $type->defaultSolution() ?: new \stdClass(),
            ];
        }
        $out = ['types' => $types, 'container' => 'answer_type null = Überschrift mit Teilaufgaben (eigener Text, Punkte aus den Teilaufgaben).'];
        $want = (string) ($args['type'] ?? '');
        if ($want !== '') {
            if (TaskTypeRegistry::get($want) === null) {
                throw new ToolError('Unbekannter Antworttyp: ' . mb_substr($want, 0, 40));
            }
            $out['format'] = self::formatDoc($want);
        }

        return $out;
    }

    /** Abschnitt des Typs aus docs/DATENFORMATE.md (die Referenz der Formate). */
    private static function formatDoc(string $type): string
    {
        $doc = @file_get_contents(dirname(__DIR__, 3) . '/docs/DATENFORMATE.md');
        if (!is_string($doc)) {
            return '';
        }
        // Eigene Überschrift ("### 2.3 `choice`") oder Aufzählungspunkt im Diagramm-Abschnitt ("- **`uml`**").
        if (preg_match('/^### [0-9.]+ `' . preg_quote($type, '/') . '`.*?(?=^### |^## |\z)/ms', $doc, $m) === 1) {
            return trim($m[0]);
        }
        if (preg_match('/^- \*\*`' . preg_quote($type, '/') . '`\*\*.*?(?=^- \*\*`|^### |^## |\z)/ms', $doc, $m) === 1) {
            return "Diagramm-Typ (Koordinaten in Pixeln, jedes Element mit id):\n" . trim($m[0]);
        }

        return '';
    }

    private function listExams(array $args): array
    {
        $filters = [];
        if (!empty($args['status'])) {
            $filters['status'] = (string) $args['status'];
        }
        $rows = ExamKit::exams($this->ctx)->listFor($this->ctx->exams->visibleExamIds(), $filters, $this->userId());
        $q = mb_strtolower(trim((string) ($args['query'] ?? '')));
        $out = [];
        foreach ($rows as $r) {
            if ($q !== '' && !str_contains(mb_strtolower((string) $r['title']), $q)) {
                continue;
            }
            $id = (int) $r['id'];
            $out[] = [
                'id' => $id,
                'title' => $r['title'],
                'mode' => $r['mode'],
                'status' => $r['status'],
                'course' => $r['course_name'],
                'scheduled_at' => $r['scheduled_at'],
                'tasks' => (int) $r['task_count'],
                'points' => (float) $r['total_points'],
                'editable' => ExamService::isEditable($r) && $this->ctx->exams->can($id, ExamAccess::CAP_EDIT),
            ];
            if (count($out) >= 100) {
                break;
            }
        }

        return ['exams' => $out];
    }

    private function getExam(array $args): array
    {
        $id = self::intArg($args, 'exam_id');
        $exam = $this->exam($id, ExamAccess::CAP_VIEW);
        $caps = $this->ctx->exams->capabilities($id);
        $withSolutions = $caps[ExamAccess::CAP_SOLUTIONS] || $caps[ExamAccess::CAP_EDIT];
        $tasks = $withSolutions ? ExamKit::tasks($this->ctx)->fullList($id) : ExamKit::tasks($this->ctx)->outline($id);
        $settings = ExamSettings::normalize(ExamAccess::settingsOf($exam), (string) $exam['mode']);
        $course = $exam['course_id'] !== null ? $this->ctx->db->fetchValue('SELECT name FROM courses WHERE id = ?', [(int) $exam['course_id']]) : null;

        return [
            'exam' => [
                'id' => $id,
                'title' => $exam['title'],
                'description' => $exam['description'],
                'mode' => $exam['mode'],
                'status' => $exam['status'],
                'editable' => ExamService::isEditable($exam) && $caps[ExamAccess::CAP_EDIT],
                'duration_minutes' => $exam['duration_minutes'] !== null ? (int) $exam['duration_minutes'] : null,
                'course_id' => $exam['course_id'] !== null ? (int) $exam['course_id'] : null,
                'course' => $course,
                'room_id' => $exam['room_id'] !== null ? (int) $exam['room_id'] : null,
                'scheduled_at' => $exam['scheduled_at'],
                'profile' => ExamSettings::matchingProfile($settings, (string) $exam['mode']),
                'settings' => $settings,
            ],
            'tasks' => array_map(static fn (array $t): array => self::taskOut($t, $withSolutions), $tasks),
            'materials' => $this->materialsOf($id),
            'links' => $this->links($id),
        ];
    }

    private function checkExam(array $args): array
    {
        $id = self::intArg($args, 'exam_id');
        $this->exam($id, ExamAccess::CAP_EDIT);

        return ExamKit::tasks($this->ctx)->check($id) + ['links' => $this->links($id)];
    }

    // ---------- Klausuren ----------

    private function assertCourseAllowed(?int $courseId): void
    {
        $auth = $this->ctx->auth;
        if ($courseId === null || $auth->isAdmin() || $auth->can(P::ALLE_KURSE)) {
            return;
        }
        $owner = $this->ctx->db->fetchValue('SELECT owner_id FROM courses WHERE id = ?', [$courseId]);
        if ((int) $owner !== $this->userId()) {
            throw new ToolError('Klausuren gibt es nur für eigene Kurse (siehe get_reference_data).');
        }
    }

    /** @param array<string, mixed> $args @return array<string, mixed> Felder für ExamService::normalizeMeta */
    private static function metaInput(array $args, array $current = []): array
    {
        $in = $current;
        foreach (['title', 'mode', 'description', 'scheduled_at'] as $k) {
            if (array_key_exists($k, $args)) {
                $in[$k] = $args[$k] === null ? '' : (string) $args[$k];
            }
        }
        foreach (['duration_minutes', 'course_id', 'room_id'] as $k) {
            if (array_key_exists($k, $args)) {
                $in[$k] = $args[$k] === null ? '' : (string) $args[$k];
            }
        }

        return $in;
    }

    private function createExam(array $args): array
    {
        if (!$this->ctx->auth->can(P::KLAUSUREN_ERSTELLEN)) {
            throw new ToolError('Die Lehrkraft darf keine Klausuren anlegen (Recht "Klausuren erstellen" fehlt).');
        }
        $in = self::metaInput($args);
        $this->assertCourseAllowed(($in['course_id'] ?? '') === '' ? null : (int) $in['course_id']);
        $svc = ExamKit::exams($this->ctx);
        $meta = $svc->normalizeMeta($in);
        $profile = (string) ($args['profile'] ?? '');
        if ($profile !== '' && !isset(ExamSettings::PROFILES[$profile])) {
            throw new ToolError('Unbekanntes Profil: ' . mb_substr($profile, 0, 40));
        }
        $id = $svc->create($in, $this->userId(), $profile !== '' ? ExamSettings::profile($profile, $meta['mode']) : null);
        $this->ctx->exams->forget($id);
        $this->log('klausur_angelegt', 'Klausur #' . $id . ' "' . $meta['title'] . '" angelegt' . ($profile !== '' ? ', Profil ' . $profile : '') . '.');

        return ['exam_id' => $id, 'title' => $meta['title'], 'status' => 'draft', 'links' => $this->links($id)];
    }

    private function updateExam(array $args): array
    {
        $id = self::intArg($args, 'exam_id');
        $exam = $this->editableExam($id);
        $svc = ExamKit::exams($this->ctx);
        $current = [
            'title' => (string) $exam['title'],
            'mode' => (string) $exam['mode'],
            'description' => (string) ($exam['description'] ?? ''),
            'duration_minutes' => $exam['duration_minutes'] !== null ? (string) $exam['duration_minutes'] : '',
            'course_id' => $exam['course_id'] !== null ? (string) $exam['course_id'] : '',
            'room_id' => $exam['room_id'] !== null ? (string) $exam['room_id'] : '',
            'scheduled_at' => $exam['scheduled_at'] !== null ? (string) $exam['scheduled_at'] : '',
        ];
        $in = self::metaInput($args, $current);
        if (($in['course_id'] ?? '') !== $current['course_id']) {
            $this->assertCourseAllowed($in['course_id'] === '' ? null : (int) $in['course_id']);
        }
        $settings = ExamAccess::settingsOf($exam);
        if (isset($args['settings'])) {
            if (!is_array($args['settings'])) {
                throw new ToolError('settings muss ein Objekt sein.');
            }
            $settings = array_replace_recursive($settings, $args['settings']);
        }
        $in['settings'] = $settings;
        $in['shared_in_archive'] = (int) ($exam['shared_in_archive'] ?? 0) === 1 ? '1' : '';
        $svc->saveSettings($exam, $in);
        $this->ctx->exams->forget($id);
        if (!empty($args['profile'])) {
            $profile = (string) $args['profile'];
            if (!isset(ExamSettings::PROFILES[$profile])) {
                throw new ToolError('Unbekanntes Profil: ' . mb_substr($profile, 0, 40));
            }
            $svc->applyProfile($this->exam($id, ExamAccess::CAP_EDIT), $profile);
            $this->ctx->exams->forget($id);
        }
        $this->log('klausur_geaendert', 'Klausur #' . $id . ' geändert (' . implode(', ', array_keys(array_diff_key($args, ['exam_id' => 1]))) . ').');

        return $this->getExam(['exam_id' => $id])['exam'] + ['links' => $this->links($id)];
    }

    // ---------- Aufgaben ----------

    /** Eingabe für TaskService::save aus aktuellem Stand und den genannten Feldern. */
    private static function taskInput(array $current, array $args): array
    {
        $in = [];
        foreach (['title', 'body_md', 'points', 'operator_id', 'afb', 'answer_type', 'config', 'files'] as $k) {
            $in[$k] = array_key_exists($k, $args) ? $args[$k] : ($current[$k] ?? null);
        }
        // Lösungsseite nur mitschicken, wenn genannt (sonst bleibt sie, wie sie ist).
        foreach (['solution', 'model_answer', 'expectation_md', 'tests'] as $k) {
            if (array_key_exists($k, $args)) {
                $in[$k] = $args[$k];
            }
        }
        if ($in['answer_type'] !== ($current['answer_type'] ?? null) && !array_key_exists('config', $args)) {
            // Typwechsel ohne eigene config: Standard des neuen Typs.
            $type = is_string($in['answer_type']) ? TaskTypeRegistry::get($in['answer_type']) : null;
            $in['config'] = $type?->defaultConfig() ?? [];
        }

        return $in;
    }

    /** @return array<string, mixed> */
    private function taskResult(int $examId, int $taskId): array
    {
        $task = $this->taskOf($examId, $taskId) ?? throw new ToolError('Aufgabe nicht gefunden.');

        return ['task' => self::taskOut($task, true), 'links' => $this->links($examId, $taskId)];
    }

    private function createTask(array $args): array
    {
        $examId = self::intArg($args, 'exam_id');
        $exam = $this->editableExam($examId);
        $tasks = ExamKit::tasks($this->ctx);
        $type = isset($args['answer_type']) && is_string($args['answer_type']) && $args['answer_type'] !== '' ? $args['answer_type'] : null;
        if ($type !== null && TaskTypeRegistry::get($type) === null) {
            throw new ToolError('Unbekannter Antworttyp: ' . mb_substr($type, 0, 40) . '. Siehe get_task_types.');
        }
        $parent = self::optInt($args, 'parent_id');
        $taskId = $tasks->create($exam, $parent, $type, mb_substr((string) ($args['title'] ?? ''), 0, 200));
        try {
            $after = self::optInt($args, 'after_id');
            if ($after !== null) {
                $tasks->placeAfter($exam, $taskId, $after);
            }
            $current = $this->taskOf($examId, $taskId) ?? throw new ToolError('Aufgabe nicht gefunden.');
            $tasks->save($exam, $taskId, self::taskInput($current, $args + ['answer_type' => $type]));
        } catch (\Throwable $e) {
            // Keine halben Aufgaben zurücklassen: Mit den Angaben stimmte etwas nicht.
            $tasks->delete($exam, $taskId);
            throw $e;
        }
        $saved = $this->taskOf($examId, $taskId);
        $this->log('aufgabe_angelegt', 'Aufgabe ' . ($saved['number'] ?? '?') . ' (#' . $taskId . ') in Klausur #' . $examId . ' angelegt.');

        return $this->taskResult($examId, $taskId);
    }

    private function updateTask(array $args): array
    {
        $examId = self::intArg($args, 'exam_id');
        $taskId = self::intArg($args, 'task_id');
        $exam = $this->exam($examId, ExamAccess::CAP_EDIT);
        $current = $this->taskOf($examId, $taskId) ?? throw new ToolError('Aufgabe ' . $taskId . ' gibt es in dieser Klausur nicht.');
        if (TaskService::isLocked($exam)) {
            $structural = array_intersect(array_keys($args), ['title', 'body_md', 'points', 'operator_id', 'afb', 'answer_type', 'config', 'files']);
            if ($structural !== []) {
                throw new ToolError('Die Klausur ist gestartet: nur noch solution, model_answer, expectation_md und tests sind änderbar (nicht: ' . implode(', ', $structural) . ').');
            }
        }
        ExamKit::tasks($this->ctx)->save($exam, $taskId, self::taskInput($current, $args));
        $this->log('aufgabe_geaendert', 'Aufgabe ' . $current['number'] . ' (#' . $taskId . ') in Klausur #' . $examId . ' geändert.');

        return $this->taskResult($examId, $taskId);
    }

    private function deleteTask(array $args): array
    {
        $examId = self::intArg($args, 'exam_id');
        $taskId = self::intArg($args, 'task_id');
        $exam = $this->editableExam($examId);
        $current = $this->taskOf($examId, $taskId) ?? throw new ToolError('Aufgabe ' . $taskId . ' gibt es in dieser Klausur nicht.');
        ExamKit::tasks($this->ctx)->delete($exam, $taskId);
        $this->log('aufgabe_geloescht', 'Aufgabe ' . $current['number'] . ' "' . $current['title'] . '" (#' . $taskId . ') aus Klausur #' . $examId . ' gelöscht.');

        return ['deleted' => $taskId, 'remaining' => array_map(
            static fn (array $t): array => ['id' => (int) $t['id'], 'number' => $t['number'], 'title' => $t['title']],
            ExamKit::tasks($this->ctx)->outline($examId),
        )];
    }

    private function moveTask(array $args): array
    {
        $examId = self::intArg($args, 'exam_id');
        $taskId = self::intArg($args, 'task_id');
        $exam = $this->editableExam($examId);
        if ($this->taskOf($examId, $taskId) === null) {
            throw new ToolError('Aufgabe ' . $taskId . ' gibt es in dieser Klausur nicht.');
        }
        ExamKit::tasks($this->ctx)->move($exam, $taskId, (string) ($args['direction'] ?? ''));
        $this->log('aufgabe_verschoben', 'Aufgabe #' . $taskId . ' in Klausur #' . $examId . ' verschoben (' . (string) ($args['direction'] ?? '') . ').');

        return ['order' => array_map(
            static fn (array $t): array => ['id' => (int) $t['id'], 'number' => $t['number'], 'title' => $t['title']],
            ExamKit::tasks($this->ctx)->outline($examId),
        )];
    }

    private function duplicateTask(array $args): array
    {
        $examId = self::intArg($args, 'exam_id');
        $taskId = self::intArg($args, 'task_id');
        $exam = $this->editableExam($examId);
        if ($this->taskOf($examId, $taskId) === null) {
            throw new ToolError('Aufgabe ' . $taskId . ' gibt es in dieser Klausur nicht.');
        }
        $newId = ExamKit::tasks($this->ctx)->duplicate($exam, $taskId);
        $this->log('aufgabe_dupliziert', 'Aufgabe #' . $taskId . ' in Klausur #' . $examId . ' dupliziert (neu #' . $newId . ').');

        return $this->taskResult($examId, $newId);
    }

    // ---------- Material ----------

    /** @return list<array<string, mixed>> */
    private function materialsOf(int $examId): array
    {
        return array_map(static fn (array $m): array => [
            'id' => (int) $m['id'],
            'name' => $m['original_name'],
            'mime' => $m['mime'],
            'size_bytes' => (int) $m['size_bytes'],
            'task_id' => $m['task_id'] !== null ? (int) $m['task_id'] : null,
            'is_aid' => (int) $m['is_aid'] === 1,
            'markdown' => '![' . $m['original_name'] . '](material:' . $m['id'] . ')',
        ], ExamKit::materials($this->ctx)->forExam($examId));
    }

    private function listMaterials(array $args): array
    {
        $id = self::intArg($args, 'exam_id');
        $this->exam($id, ExamAccess::CAP_EDIT);

        return ['materials' => $this->materialsOf($id)];
    }

    private function uploadMaterial(array $args): array
    {
        $examId = self::intArg($args, 'exam_id');
        $this->editableExam($examId);
        $name = trim((string) ($args['filename'] ?? ''));
        if ($name === '' || mb_strlen($name) > 200) {
            throw new ToolError('Bitte einen Dateinamen mit Endung angeben.');
        }
        $raw = preg_replace('/\s+/', '', (string) ($args['content_base64'] ?? '')) ?? '';
        if (str_starts_with($raw, 'data:')) {
            $raw = substr($raw, (int) strpos($raw, ',') + 1);
        }
        $bytes = base64_decode($raw, true);
        if ($bytes === false || $bytes === '') {
            throw new ToolError('content_base64 ist kein gültiges Base64.');
        }
        $taskId = self::optInt($args, 'task_id');
        if ($taskId !== null && $this->taskOf($examId, $taskId) === null) {
            throw new ToolError('Aufgabe ' . $taskId . ' gibt es in dieser Klausur nicht.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'kw-ki-');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            throw new ToolError('Die Datei ließ sich nicht zwischenspeichern.');
        }
        try {
            $row = ExamKit::materials($this->ctx)->storeLocal($examId, $tmp, $name, $taskId, !empty($args['is_aid']));
        } finally {
            @unlink($tmp);
        }
        $this->log('material_hochgeladen', 'Material #' . $row['id'] . ' "' . $row['original_name'] . '" zu Klausur #' . $examId . ' hochgeladen.');

        return [
            'material_id' => (int) $row['id'],
            'name' => $row['original_name'],
            'markdown' => '![' . $row['original_name'] . '](material:' . $row['id'] . ')',
        ];
    }

    private function deleteMaterial(array $args): array
    {
        $examId = self::intArg($args, 'exam_id');
        $this->editableExam($examId);
        $materials = ExamKit::materials($this->ctx);
        $row = $materials->find(self::intArg($args, 'material_id'));
        if ($row === null || (int) $row['exam_id'] !== $examId) {
            throw new ToolError('Material nicht gefunden.');
        }
        $materials->delete($row);
        $this->log('material_geloescht', 'Material #' . $row['id'] . ' "' . $row['original_name'] . '" aus Klausur #' . $examId . ' gelöscht.');

        return ['deleted' => (int) $row['id']];
    }

    // ---------- Archiv ----------

    private function assertArchive(): void
    {
        if (!$this->ctx->auth->can(P::ARCHIV_DURCHSUCHEN)) {
            throw new ToolError('Die Lehrkraft darf das Archiv nicht durchsuchen (Recht "Archiv durchsuchen" fehlt).');
        }
    }

    private function searchArchive(array $args): array
    {
        $this->assertArchive();
        $visible = $this->ctx->exams->visibleExamIds();
        $where = ['(e.shared_in_archive = 1' . ($visible !== [] ? ' OR e.id IN (' . implode(',', array_fill(0, count($visible), '?')) . ')' : '') . ')', "(t.body_md <> '' OR t.title <> '')"];
        $params = $visible;
        $q = trim((string) ($args['query'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where[] = '(t.title LIKE ? OR t.body_md LIKE ?)';
            array_push($params, $like, $like);
        }
        $type = (string) ($args['answer_type'] ?? '');
        if ($type !== '' && TaskTypeRegistry::get($type) !== null) {
            $where[] = 't.answer_type = ?';
            $params[] = $type;
        }
        $lang = (string) ($args['language'] ?? '');
        if (in_array($lang, ['java', 'python', 'sql'], true)) {
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(t.config, '$.language')) = ?";
            $params[] = $lang;
        }
        $rows = $this->ctx->db->fetchAll(
            'SELECT t.id, t.title, t.body_md, t.points, t.answer_type, t.parent_id, e.id AS exam_id, e.title AS exam_title, c.name AS course_name, c.grade_level
             FROM tasks t JOIN exams e ON e.id = t.exam_id LEFT JOIN courses c ON c.id = e.course_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY e.created_at DESC, t.exam_id, t.parent_id, t.position LIMIT ' . self::ARCHIVE_LIMIT,
            $params,
        );

        return ['results' => array_map(static fn (array $r): array => [
            'task_id' => (int) $r['id'],
            'title' => $r['title'],
            'answer_type' => $r['answer_type'],
            'points' => (float) $r['points'],
            'is_subtask' => $r['parent_id'] !== null,
            'exam' => $r['exam_title'],
            'course' => $r['course_name'],
            'grade_level' => $r['grade_level'] !== null ? (int) $r['grade_level'] : null,
            'excerpt' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $r['body_md'])), 0, 300),
        ], $rows)];
    }

    private function copyArchiveTask(array $args): array
    {
        $this->assertArchive();
        $taskId = self::intArg($args, 'task_id');
        $targetId = self::intArg($args, 'target_exam_id');
        $this->editableExam($targetId);
        $source = $this->ctx->db->fetchOne('SELECT t.id, t.exam_id, e.shared_in_archive FROM tasks t JOIN exams e ON e.id = t.exam_id WHERE t.id = ?', [$taskId]);
        if ($source === null) {
            throw new ToolError('Aufgabe nicht gefunden.');
        }
        $sourceExam = (int) $source['exam_id'];
        $shared = (int) $source['shared_in_archive'] === 1;
        if (!$shared && !$this->ctx->exams->can($sourceExam, ExamAccess::CAP_VIEW)) {
            throw new ToolError('Keine Berechtigung für diese Aufgabe.');
        }
        $withSolutions = $shared || $this->ctx->exams->can($sourceExam, ExamAccess::CAP_SOLUTIONS);
        $newId = ExamKit::tasks($this->ctx)->copyTask($taskId, $targetId, null, $withSolutions);
        $this->log('aufgabe_kopiert', 'Aufgabe #' . $taskId . ' aus Klausur #' . $sourceExam . ' nach Klausur #' . $targetId . ' kopiert (neu #' . $newId . ').');

        return $this->taskResult($targetId, $newId) + ['with_solutions' => $withSolutions];
    }
}
