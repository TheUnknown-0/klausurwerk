<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions as P;
use App\Services\CourseService;
use App\Services\GradingScales;

/**
 * Notenschlüssel: je Klassenstufe ein Standard (grade_level), dazu freie Vorlagen (grade_level NULL),
 * die einzelne Kurse als Abweichung wählen können.
 */
final class GradingScalesController extends Controller
{
    /** GET /admin/notenschluessel */
    public function index(array $params): string
    {
        $this->requirePermission(P::NOTENSCHLUESSEL_BEARBEITEN);
        $scales = $this->service()->all();

        $byLevel = [];
        $templates = [];
        foreach ($scales as $scale) {
            if ($scale['grade_level'] !== null) {
                $byLevel[(int) $scale['grade_level']] = $scale;
            } else {
                $templates[] = $scale;
            }
        }

        $usage = [];
        foreach ($this->ctx->db->fetchAll(
            'SELECT grading_scale_id, COUNT(*) AS n FROM courses WHERE grading_scale_id IS NOT NULL GROUP BY grading_scale_id',
        ) as $row) {
            $usage[(int) $row['grading_scale_id']] = (int) $row['n'];
        }

        return $this->render('pages/grading-scales/index', [
            'title' => 'Notenschlüssel',
            'levels' => range(CourseService::MIN_GRADE, CourseService::MAX_GRADE),
            'byLevel' => $byLevel,
            'templates' => $templates,
            'usage' => $usage,
            'types' => GradingScales::TYPES,
            'pageStyles' => ['admin.css'],
        ]);
    }

    /** GET /admin/notenschluessel/neu (optional ?kopie=ID oder ?stufe=N) */
    public function create(array $params): string
    {
        $this->requirePermission(P::NOTENSCHLUESSEL_BEARBEITEN);
        $old = $this->ctx->session->pullOldInput();

        $values = $old;
        if ($values === []) {
            $source = null;
            $copyId = (int) ($_GET['kopie'] ?? 0);
            if ($copyId > 0) {
                $source = $this->service()->find($copyId);
            }
            $level = (int) ($_GET['stufe'] ?? 0);
            $values = [
                'name' => $source !== null ? $source['name'] . ' (Kopie)' : '',
                'type' => $source['type'] ?? 'grades',
                'grade_level' => $level >= CourseService::MIN_GRADE && $level <= CourseService::MAX_GRADE ? (string) $level : '',
                'steps' => $source['thresholds'] ?? self::defaultSteps(),
            ];
        } else {
            $values['steps'] = self::stepsFromPost($old);
        }

        return $this->renderForm(null, $values);
    }

    /** POST /admin/notenschluessel/neu */
    public function store(array $params): string
    {
        $this->requirePermission(P::NOTENSCHLUESSEL_BEARBEITEN);
        $this->requireCsrf();

        $data = $this->readForm();
        $error = $this->validate($data, null);
        if ($error !== null) {
            $this->ctx->session->rememberInput($_POST);
            $this->flash('error', $error);
            $this->redirect($this->ctx->url('/admin/notenschluessel/neu'));
        }

        $this->ctx->db->run(
            'INSERT INTO grading_scales (name, type, grade_level, thresholds) VALUES (?, ?, ?, ?)',
            [$data['name'], $data['type'], $data['grade_level'], json_encode($data['steps'], JSON_UNESCAPED_UNICODE)],
        );
        $id = $this->ctx->db->lastInsertId();
        $this->ctx->audit->log('notenschluessel.anlegen', 'info', $data['name']);
        $this->flash('success', 'Der Notenschlüssel wurde angelegt.');
        $this->redirect($this->ctx->url('/admin/notenschluessel/' . $id));
    }

    /** GET /admin/notenschluessel/{id} */
    public function edit(array $params): string
    {
        $this->requirePermission(P::NOTENSCHLUESSEL_BEARBEITEN);
        $scale = $this->load((int) $params['id']);
        $old = $this->ctx->session->pullOldInput();

        $values = $old !== []
            ? $old + ['steps' => self::stepsFromPost($old)]
            : [
                'name' => $scale['name'],
                'type' => $scale['type'],
                'grade_level' => $scale['grade_level'] !== null ? (string) $scale['grade_level'] : '',
                'steps' => $scale['thresholds'],
            ];
        if ($old !== []) {
            $values['steps'] = self::stepsFromPost($old);
        }

        return $this->renderForm($scale, $values);
    }

    /** POST /admin/notenschluessel/{id} */
    public function update(array $params): string
    {
        $this->requirePermission(P::NOTENSCHLUESSEL_BEARBEITEN);
        $this->requireCsrf();
        $scale = $this->load((int) $params['id']);
        $id = (int) $scale['id'];

        $data = $this->readForm();
        $error = $this->validate($data, $id);
        if ($error !== null) {
            $this->ctx->session->rememberInput($_POST);
            $this->flash('error', $error);
            $this->redirect($this->ctx->url('/admin/notenschluessel/' . $id));
        }

        $this->ctx->db->run(
            'UPDATE grading_scales SET name = ?, type = ?, grade_level = ?, thresholds = ? WHERE id = ?',
            [$data['name'], $data['type'], $data['grade_level'], json_encode($data['steps'], JSON_UNESCAPED_UNICODE), $id],
        );
        $this->ctx->audit->log('notenschluessel.bearbeiten', 'info', $data['name']);
        $this->flash('success', 'Der Notenschlüssel wurde gespeichert.');
        $this->redirect($this->ctx->url('/admin/notenschluessel/' . $id));
    }

    /** POST /admin/notenschluessel/{id}/loeschen */
    public function destroy(array $params): string
    {
        $this->requirePermission(P::NOTENSCHLUESSEL_BEARBEITEN);
        $this->requireCsrf();
        $scale = $this->load((int) $params['id']);
        $id = (int) $scale['id'];

        $this->ctx->db->run('DELETE FROM grading_scales WHERE id = ?', [$id]);
        $this->ctx->audit->log('notenschluessel.loeschen', 'warning', (string) $scale['name']);
        $this->flash('success', 'Der Notenschlüssel „' . $scale['name'] . '“ wurde gelöscht. Kurse mit diesem Schlüssel nutzen wieder den Standard ihrer Klassenstufe.');
        $this->redirect($this->ctx->url('/admin/notenschluessel'));
    }

    // ---------- Helfer ----------

    private function service(): GradingScales
    {
        return new GradingScales($this->ctx->db);
    }

    /** @return array<string, mixed> */
    private function load(int $id): array
    {
        $scale = $this->service()->find($id);
        if ($scale === null) {
            throw new HttpException(404, 'Notenschlüssel nicht gefunden.');
        }

        return $scale;
    }

    /**
     * @param array<string, mixed>|null $scale
     * @param array<string, mixed> $values
     */
    private function renderForm(?array $scale, array $values): string
    {
        $usedBy = [];
        if ($scale !== null) {
            $usedBy = $this->ctx->db->fetchAll(
                'SELECT id, name, school_year FROM courses WHERE grading_scale_id = ? ORDER BY school_year DESC, name',
                [(int) $scale['id']],
            );
        }

        return $this->render('pages/grading-scales/form', [
            'title' => $scale === null ? 'Neuer Notenschlüssel' : (string) $scale['name'],
            'scale' => $scale,
            'values' => $values,
            'types' => GradingScales::TYPES,
            'levels' => range(CourseService::MIN_GRADE, CourseService::MAX_GRADE),
            'usedBy' => $usedBy,
            'pageScripts' => ['grading-scales.js'],
            'pageStyles' => ['admin.css'],
        ]);
    }

    /** @return array{name: string, type: string, grade_level: ?int, steps: list<array<string, mixed>>} */
    private function readForm(): array
    {
        $level = (int) ($_POST['grade_level'] ?? 0);

        return [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'type' => (string) ($_POST['type'] ?? ''),
            'grade_level' => $level > 0 ? $level : null,
            'steps' => GradingScales::normalize(self::stepsFromPost($_POST)),
        ];
    }

    /**
     * Stufen aus den Formularfeldern label[], value[], min_percent[].
     *
     * @param array<string, mixed> $post
     * @return list<array<string, mixed>>
     */
    private static function stepsFromPost(array $post): array
    {
        $labels = is_array($post['label'] ?? null) ? array_values($post['label']) : [];
        $values = is_array($post['value'] ?? null) ? array_values($post['value']) : [];
        $mins = is_array($post['min_percent'] ?? null) ? array_values($post['min_percent']) : [];

        $rows = [];
        foreach ($labels as $i => $label) {
            $rows[] = [
                'label' => (string) $label,
                'value' => (string) ($values[$i] ?? ''),
                'min_percent' => (string) ($mins[$i] ?? ''),
            ];
        }

        return $rows;
    }

    /** @param array{name: string, type: string, grade_level: ?int, steps: list<array<string, mixed>>} $data */
    private function validate(array $data, ?int $exceptId): ?string
    {
        if ($data['name'] === '' || mb_strlen($data['name']) > 100) {
            return 'Der Name ist Pflicht (höchstens 100 Zeichen).';
        }
        if ($data['grade_level'] !== null
            && ($data['grade_level'] < CourseService::MIN_GRADE || $data['grade_level'] > CourseService::MAX_GRADE)) {
            return 'Die Klassenstufe muss zwischen 5 und 13 liegen.';
        }
        if ($data['grade_level'] !== null) {
            $other = $this->ctx->db->fetchOne(
                'SELECT name FROM grading_scales WHERE grade_level = ? AND id <> ?',
                [$data['grade_level'], $exceptId ?? 0],
            );
            if ($other !== null) {
                return sprintf(
                    'Für Klassenstufe %d ist „%s“ schon der Standard. Ändere dort die Klassenstufe oder lösche den Schlüssel.',
                    $data['grade_level'],
                    $other['name'],
                );
            }
        }
        $errors = GradingScales::validate($data['steps'], $data['type']);
        if ($errors !== []) {
            return implode(' ', array_slice($errors, 0, 3));
        }

        return null;
    }

    /** Startwerte für einen neuen Schlüssel: Noten 1 bis 6 in ganzen Stufen. @return list<array<string, mixed>> */
    private static function defaultSteps(): array
    {
        return [
            ['label' => '1', 'value' => 1, 'min_percent' => 87],
            ['label' => '2', 'value' => 2, 'min_percent' => 73],
            ['label' => '3', 'value' => 3, 'min_percent' => 59],
            ['label' => '4', 'value' => 4, 'min_percent' => 45],
            ['label' => '5', 'value' => 5, 'min_percent' => 18],
            ['label' => '6', 'value' => 6, 'min_percent' => 0],
        ];
    }
}
