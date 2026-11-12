<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions as P;
use App\Services\Operators;
use App\Services\Subjects;

/**
 * Operatorenlisten je Fach: Liste, Anlegen, Bearbeiten, Löschen und Reihenfolge (innerhalb des Fachs).
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
        $subjects = Operators::subjects($this->ctx->db);
        $subject = trim((string) ($_GET['fach'] ?? ''));
        if ($subject === '' || !in_array($subject, $subjects, true)) {
            $subject = $subjects[0] ?? 'Informatik';
        }

        return $this->render('pages/operators/index', [
            'title' => 'Operatoren',
            'operators' => $this->ctx->db->fetchAll(
                'SELECT o.*, (SELECT COUNT(*) FROM tasks t WHERE t.operator_id = o.id) AS task_count
                 FROM operators o WHERE o.subject = ? ORDER BY o.sort_order, o.id',
                [$subject],
            ),
            'subject' => $subject,
            'subjects' => $subjects,
            'knownSubjects' => array_values(array_unique(array_merge(Subjects::names(), [Operators::FALLBACK]))),
            'afbLevels' => self::AFB_LEVELS,
            'values' => $old + ['name' => '', 'afb' => 'I', 'description' => '', 'subject' => $subject],
            'pageStyles' => ['admin.css'],
        ]);
    }

    /** POST /admin/operatoren/neu */
    public function store(array $params): string
    {
        $this->requirePermission(P::OPERATOREN_BEARBEITEN);
        $this->requireCsrf();
        $data = $this->readForm();
        $back = $this->listUrl($data['subject']);
        $error = $this->validate($data, null);
        if ($error !== null) {
            $this->ctx->session->rememberInput($_POST);
            $this->flash('error', $error);
            $this->redirect($back);
        }

        $next = (int) $this->ctx->db->fetchValue('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM operators WHERE subject = ?', [$data['subject']]);
        $this->ctx->db->run(
            'INSERT INTO operators (subject, name, afb, description, sort_order) VALUES (?, ?, ?, ?, ?)',
            [$data['subject'], $data['name'], $data['afb'], $data['description'], $next],
        );
        $this->ctx->audit->log('operator.anlegen', 'info', $data['subject'] . ': ' . $data['name']);
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
                'subject' => $operator['subject'],
            ],
            'knownSubjects' => array_values(array_unique(array_merge(Operators::subjects($this->ctx->db), Subjects::names(), [Operators::FALLBACK]))),
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
            'UPDATE operators SET subject = ?, name = ?, afb = ?, description = ? WHERE id = ?',
            [$data['subject'], $data['name'], $data['afb'], $data['description'], $id],
        );
        $this->ctx->audit->log('operator.bearbeiten', 'info', $data['subject'] . ': ' . $data['name']);
        $this->flash('success', 'Der Operator wurde gespeichert.');
        $this->redirect($this->listUrl($data['subject']));
    }

    /** POST /admin/operatoren/{id}/loeschen */
    public function destroy(array $params): string
    {
        $this->requirePermission(P::OPERATOREN_BEARBEITEN);
        $this->requireCsrf();
        $operator = $this->load((int) $params['id']);

        $this->ctx->db->run('DELETE FROM operators WHERE id = ?', [(int) $operator['id']]);
        $this->ctx->audit->log('operator.loeschen', 'warning', $operator['subject'] . ': ' . $operator['name']);
        $this->flash('success', 'Der Operator „' . $operator['name'] . '“ wurde gelöscht.');
        $this->redirect($this->listUrl((string) $operator['subject']));
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
                $this->ctx->db->fetchAll('SELECT id FROM operators WHERE subject = ? ORDER BY sort_order, id', [(string) $operator['subject']]),
                'id',
            ));
            $index = array_search((int) $operator['id'], $ids, true);
            $target = $index === false ? null : $index + $direction;
            if ($index !== false && $target !== null && $target >= 0 && $target < count($ids)) {
                [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
            }
            // Reihenfolge lückenlos neu vergeben (heilt auch doppelte sort_order-Werte).
            foreach ($ids as $position => $id) {
                $this->ctx->db->run('UPDATE operators SET sort_order = ? WHERE id = ?', [($position + 1) * 10, $id]);
            }
        });

        $this->redirect($this->listUrl((string) $operator['subject']));
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

    private function listUrl(string $subject): string
    {
        return $this->ctx->url('/admin/operatoren?fach=' . rawurlencode($subject));
    }

    /** @return array{subject: string, name: string, afb: string, description: string} */
    private function readForm(): array
    {
        return [
            'subject' => trim(preg_replace('/\s+/u', ' ', (string) ($_POST['subject'] ?? '')) ?? ''),
            'name' => trim((string) ($_POST['name'] ?? '')),
            'afb' => (string) ($_POST['afb'] ?? ''),
            'description' => mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 500),
        ];
    }

    /** @param array{subject: string, name: string, afb: string, description: string} $data */
    private function validate(array $data, ?int $exceptId): ?string
    {
        if ($data['subject'] === '' || mb_strlen($data['subject']) > 60) {
            return 'Das Fach ist Pflicht (höchstens 60 Zeichen).';
        }
        if ($data['name'] === '' || mb_strlen($data['name']) > 60) {
            return 'Der Name ist Pflicht (höchstens 60 Zeichen).';
        }
        if (!in_array($data['afb'], self::AFB_LEVELS, true)) {
            return 'Bitte einen Anforderungsbereich (AFB) auswählen.';
        }
        if ($this->ctx->db->fetchValue(
            'SELECT 1 FROM operators WHERE subject = ? AND name = ? AND id <> ?',
            [$data['subject'], $data['name'], $exceptId ?? 0],
        ) !== null) {
            return 'Einen Operator „' . $data['name'] . '“ gibt es in ' . $data['subject'] . ' schon.';
        }

        return null;
    }
}
