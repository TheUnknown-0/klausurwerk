<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions as P;

/**
 * Operatorenliste (Berlin): Liste, Anlegen, Bearbeiten, Löschen und Reihenfolge.
 * Aufgaben behalten bei einem gelöschten Operator ihren Text, nur die Zuordnung entfällt.
 */
final class OperatorsController extends Controller
{
    public const AFB_LEVELS = ['I', 'II', 'III', 'I-II', 'II-III'];

    /** GET /admin/operatoren */
    public function index(array $params): string
    {
        $this->requirePermission(P::OPERATOREN_BEARBEITEN);
        $old = $this->ctx->session->pullOldInput();

        return $this->render('pages/operators/index', [
            'title' => 'Operatoren',
            'operators' => $this->ctx->db->fetchAll(
                'SELECT o.*, (SELECT COUNT(*) FROM tasks t WHERE t.operator_id = o.id) AS task_count
                 FROM operators o ORDER BY o.sort_order, o.id',
            ),
            'afbLevels' => self::AFB_LEVELS,
            'values' => $old + ['name' => '', 'afb' => 'I', 'description' => ''],
            'pageStyles' => ['admin.css'],
        ]);
    }

    /** POST /admin/operatoren/neu */
    public function store(array $params): string
    {
        $this->requirePermission(P::OPERATOREN_BEARBEITEN);
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/operatoren');

        $data = $this->readForm();
        $error = $this->validate($data, null);
        if ($error !== null) {
            $this->ctx->session->rememberInput($_POST);
            $this->flash('error', $error);
            $this->redirect($back);
        }

        $next = (int) $this->ctx->db->fetchValue('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM operators');
        $this->ctx->db->run(
            'INSERT INTO operators (name, afb, description, sort_order) VALUES (?, ?, ?, ?)',
            [$data['name'], $data['afb'], $data['description'], $next],
        );
        $this->ctx->audit->log('operator.anlegen', 'info', $data['name']);
        $this->flash('success', 'Der Operator „' . $data['name'] . '“ wurde angelegt.');
        $this->redirect($back);
    }

    /** GET /admin/operatoren/{id} */
    public function edit(array $params): string
    {
        $this->requirePermission(P::OPERATOREN_BEARBEITEN);
        $operator = $this->load((int) $params['id']);
        $old = $this->ctx->session->pullOldInput();

        return $this->render('pages/operators/edit', [
            'title' => 'Operator ' . $operator['name'],
            'operator' => $operator,
            'values' => $old + [
                'name' => $operator['name'],
                'afb' => $operator['afb'],
                'description' => $operator['description'],
            ],
            'afbLevels' => self::AFB_LEVELS,
            'taskCount' => (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM tasks WHERE operator_id = ?', [(int) $operator['id']]),
            'pageStyles' => ['admin.css'],
        ]);
    }

    /** POST /admin/operatoren/{id} */
    public function update(array $params): string
    {
        $this->requirePermission(P::OPERATOREN_BEARBEITEN);
        $this->requireCsrf();
        $operator = $this->load((int) $params['id']);
        $id = (int) $operator['id'];

        $data = $this->readForm();
        $error = $this->validate($data, $id);
        if ($error !== null) {
            $this->ctx->session->rememberInput($_POST);
            $this->flash('error', $error);
            $this->redirect($this->ctx->url('/admin/operatoren/' . $id));
        }

        $this->ctx->db->run(
            'UPDATE operators SET name = ?, afb = ?, description = ? WHERE id = ?',
            [$data['name'], $data['afb'], $data['description'], $id],
        );
        $this->ctx->audit->log('operator.bearbeiten', 'info', $data['name']);
        $this->flash('success', 'Der Operator wurde gespeichert.');
        $this->redirect($this->ctx->url('/admin/operatoren'));
    }

    /** POST /admin/operatoren/{id}/loeschen */
    public function destroy(array $params): string
    {
        $this->requirePermission(P::OPERATOREN_BEARBEITEN);
        $this->requireCsrf();
        $operator = $this->load((int) $params['id']);

        $this->ctx->db->run('DELETE FROM operators WHERE id = ?', [(int) $operator['id']]);
        $this->ctx->audit->log('operator.loeschen', 'warning', (string) $operator['name']);
        $this->flash('success', 'Der Operator „' . $operator['name'] . '“ wurde gelöscht.');
        $this->redirect($this->ctx->url('/admin/operatoren'));
    }

    /** POST /admin/operatoren/{id}/verschieben mit richtung=hoch|runter */
    public function move(array $params): string
    {
        $this->requirePermission(P::OPERATOREN_BEARBEITEN);
        $this->requireCsrf();
        $operator = $this->load((int) $params['id']);
        $direction = ($_POST['richtung'] ?? '') === 'hoch' ? -1 : 1;

        $this->ctx->db->transaction(function () use ($operator, $direction): void {
            $ids = array_map('intval', array_column(
                $this->ctx->db->fetchAll('SELECT id FROM operators ORDER BY sort_order, id'),
                'id',
            ));
            $index = array_search((int) $operator['id'], $ids, true);
            $target = $index === false ? null : $index + $direction;
            if ($index !== false && $target !== null && $target >= 0 && $target < count($ids)) {
                [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
            }
            // Reihenfolge lückenlos neu vergeben (heilt auch doppelte sort_order-Werte).
            foreach ($ids as $position => $id) {
                $this->ctx->db->run('UPDATE operators SET sort_order = ? WHERE id = ?', [$position + 1, $id]);
            }
        });

        $this->redirect($this->ctx->url('/admin/operatoren'));
    }

    // ---------- Helfer ----------

    /** @return array<string, mixed> */
    private function load(int $id): array
    {
        $operator = $this->ctx->db->fetchOne('SELECT * FROM operators WHERE id = ?', [$id]);
        if ($operator === null) {
            throw new HttpException(404, 'Operator nicht gefunden.');
        }

        return $operator;
    }

    /** @return array{name: string, afb: string, description: string} */
    private function readForm(): array
    {
        return [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'afb' => (string) ($_POST['afb'] ?? ''),
            'description' => mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 500),
        ];
    }

    /** @param array{name: string, afb: string, description: string} $data */
    private function validate(array $data, ?int $exceptId): ?string
    {
        if ($data['name'] === '' || mb_strlen($data['name']) > 60) {
            return 'Der Name ist Pflicht (höchstens 60 Zeichen).';
        }
        if (!in_array($data['afb'], self::AFB_LEVELS, true)) {
            return 'Bitte einen Anforderungsbereich (AFB) auswählen.';
        }
        if ($this->ctx->db->fetchValue(
            'SELECT 1 FROM operators WHERE name = ? AND id <> ?',
            [$data['name'], $exceptId ?? 0],
        ) !== null) {
            return 'Einen Operator „' . $data['name'] . '“ gibt es schon.';
        }

        return null;
    }
}
